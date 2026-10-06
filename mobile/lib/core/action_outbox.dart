import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:path/path.dart' as p;
import 'package:uuid/uuid.dart';

import 'backoff.dart';
import 'evidence_cipher.dart';
import 'local_db.dart';

enum ActionStatus {
  /// Waiting to be sent (or re-sent after no answer).
  pending,

  /// Refused by the server while the technician was not looking. Shown on the
  /// sync screen with its reason until they retry or dismiss it.
  failed,

  /// Accepted (or replayed as already accepted).
  done,

  /// Refused while the technician was watching (they saw the message), or
  /// dismissed by them from the sync screen. Kept for the record, not counted.
  dismissed,
}

/// What the technician asked for, before it has a key or a row.
///
/// These calls (custody, stocktake, transfers, diagnosis, additional work,
/// vehicle inspection) used to be fired straight at the API from button
/// handlers: no idempotency key, no error handling — an offline tap was an
/// unhandled exception, and a timeout followed by a second tap could apply the
/// action twice. Each is now a persisted outbox row with an Idempotency-Key
/// generated ONCE per tap and reused on every retry (contract C2).
class ActionRequest {
  const ActionRequest({
    required this.kind,
    required this.path,
    required this.label,
    this.body = const {},
    this.targetKey,
    this.visitId,
    this.multipart = false,
    this.attachment,
    this.attachmentField,
    this.attachmentName,
  });

  final String kind;
  final String path;

  /// Arabic, shown wherever the pending item is listed.
  final String label;
  final Map<String, dynamic> body;

  /// Items sharing a key are delivered strictly in order (scans before the
  /// stocktake's close); different keys never wait on each other.
  final String? targetKey;
  final int? visitId;
  final bool multipart;
  final Uint8List? attachment;
  final String? attachmentField;
  final String? attachmentName;

  factory ActionRequest.acceptCustody(int custodyId, String acceptedName) =>
      ActionRequest(
        kind: 'custody.accept',
        path: '/api/v1/custodies/$custodyId/accept',
        body: {'accepted_name': acceptedName},
        targetKey: 'custody:$custodyId',
        label: 'إقرار استلام العهدة',
      );

  factory ActionRequest.scanStocktake(int sessionId, String code, double qty) =>
      ActionRequest(
        kind: 'stocktake.scan',
        path: '/api/v1/inventory/stocktakes/$sessionId/scan',
        body: {'code': code, 'qty': qty},
        targetKey: 'stocktake:$sessionId',
        label: 'مسح صنف الجرد',
      );

  factory ActionRequest.completeStocktake(int sessionId) => ActionRequest(
    kind: 'stocktake.complete',
    path: '/api/v1/inventory/stocktakes/$sessionId/complete',
    targetKey: 'stocktake:$sessionId',
    label: 'إقفال الجرد',
  );

  factory ActionRequest.releaseTransfer(int transferId) => ActionRequest(
    kind: 'transfer.release',
    path: '/api/v1/inventory/vehicle-transfers/$transferId/release',
    targetKey: 'transfer:$transferId',
    label: 'تأكيد التسليم',
  );

  factory ActionRequest.acceptTransfer(int transferId) => ActionRequest(
    kind: 'transfer.accept',
    path: '/api/v1/inventory/vehicle-transfers/$transferId/accept',
    targetKey: 'transfer:$transferId',
    label: 'تأكيد الاستلام',
  );

  factory ActionRequest.recordDiagnosis({
    required int visitId,
    required String faultCode,
    required String diagnosisCode,
    required String summary,
  }) => ActionRequest(
    kind: 'visit.diagnosis',
    path: '/api/v1/visits/$visitId/diagnosis',
    body: {
      'fault_code': faultCode,
      'diagnosis_code': diagnosisCode,
      'resolution_summary': summary,
    },
    targetKey: 'visit:$visitId:diagnosis',
    visitId: visitId,
    label: 'اعتماد التشخيص',
  );

  factory ActionRequest.additionalWork({
    required int visitId,
    required String title,
    required String description,
    required List<Map<String, dynamic>> items,
  }) => ActionRequest(
    kind: 'visit.additional_work',
    path: '/api/v1/visits/$visitId/additional-work',
    body: {'title': title, 'description': description, 'items': items},
    targetKey: 'visit:$visitId:additional-work',
    visitId: visitId,
    label: 'طلب اعتماد عمل أو قطع إضافية',
  );

  factory ActionRequest.vehicleInspection({
    required double odometerKm,
    required Map<String, bool> checklist,
    String? defects,
    Uint8List? photo,
  }) => ActionRequest(
    kind: 'fleet.inspection',
    path: '/api/v1/fleet/vehicle/inspection',
    multipart: true,
    body: {
      'odometer_km': '$odometerKm',
      for (final entry in checklist.entries) entry.key: entry.value ? '1' : '0',
      if (defects != null && defects.trim().isNotEmpty)
        'defects': defects.trim(),
    },
    targetKey: 'fleet:inspection',
    label: 'فحص السيارة اليومي',
    attachment: photo,
    attachmentField: 'photo',
    attachmentName: 'vehicle-inspection.jpg',
  );
}

class PendingAction {
  PendingAction({
    required this.idempotencyKey,
    required this.kind,
    required this.method,
    required this.path,
    required this.body,
    required this.label,
    required this.status,
    this.attachmentPath,
    this.attachmentField,
    this.attachmentName,
    this.visitId,
    this.targetKey,
    this.attempts = 0,
    this.transientFailures = 0,
    this.nextAttemptAt,
    this.lastError,
    this.actorUserId,
    this.createdAt,
  });

  static const methodJson = 'POST';
  static const methodMultipart = 'MULTIPART';

  final String idempotencyKey;
  final String kind;
  final String method;
  final String path;
  final Map<String, dynamic> body;
  final String label;
  final ActionStatus status;
  final String? attachmentPath;
  final String? attachmentField;
  final String? attachmentName;
  final int? visitId;
  final String? targetKey;
  final int attempts;
  final int transientFailures;
  final DateTime? nextAttemptAt;
  final String? lastError;
  final int? actorUserId;
  final DateTime? createdAt;

  bool get isMultipart => method == methodMultipart;

  factory PendingAction.fromRow(Map<String, dynamic> row) => PendingAction(
    idempotencyKey: row['idempotency_key'] as String,
    kind: row['kind'] as String,
    method: row['method'] as String? ?? methodJson,
    path: row['path'] as String,
    body: row['body'] == null
        ? <String, dynamic>{}
        : jsonDecode(row['body'] as String) as Map<String, dynamic>,
    label: row['label'] as String? ?? '',
    status: ActionStatus.values.byName(row['status'] as String),
    attachmentPath: row['attachment_path'] as String?,
    attachmentField: row['attachment_field'] as String?,
    attachmentName: row['attachment_name'] as String?,
    visitId: row['visit_id'] as int?,
    targetKey: row['target_key'] as String?,
    attempts: row['attempts'] as int? ?? 0,
    transientFailures: row['transient_failures'] as int? ?? 0,
    nextAttemptAt: row['next_attempt_at'] == null
        ? null
        : DateTime.tryParse(row['next_attempt_at'] as String),
    lastError: row['last_error'] as String?,
    actorUserId: row['actor_user_id'] as int?,
    createdAt: DateTime.tryParse(row['created_at'] as String? ?? ''),
  );
}

/// The persisted outbox for online-only API actions.
///
/// Flushed by the sync engine after the event queue. Every row keeps the
/// idempotency key it was created with, so a retry — after a timeout, a
/// crash, or a week offline — is recognised by the server as the same tap.
class ActionOutbox {
  ActionOutbox(
    this._db, {
    required String attachmentRoot,
    EvidenceCipher? cipher,
    Uuid? uuid,
  }) : _attachmentRoot = attachmentRoot,
       _cipher = cipher,
       _uuid = uuid ?? const Uuid();

  final LocalDb _db;
  final String _attachmentRoot;
  final EvidenceCipher? _cipher;
  final Uuid _uuid;

  static const _table = 'pending_actions';

  Future<PendingAction> add(ActionRequest request, {int? actorUserId}) async {
    final key = _uuid.v4();

    String? attachmentPath;
    final attachment = request.attachment;
    if (attachment != null) {
      final directory = Directory(p.join(_attachmentRoot, 'outbox'));
      await directory.create(recursive: true);
      final file = File(
        p.join(directory.path, _cipher == null ? '$key.bin' : '$key.bin.enc'),
      );
      if (_cipher == null) {
        await file.writeAsBytes(attachment, flush: true);
      } else {
        await _cipher.encryptToFile(attachment, file);
      }
      attachmentPath = file.path;
    }

    try {
      await _db.raw.insert(_table, {
        'idempotency_key': key,
        'kind': request.kind,
        'method': request.multipart
            ? PendingAction.methodMultipart
            : PendingAction.methodJson,
        'path': request.path,
        'body': jsonEncode(request.body),
        'label': request.label,
        'attachment_path': attachmentPath,
        'attachment_field': request.attachmentField,
        'attachment_name': request.attachmentName,
        'visit_id': request.visitId,
        'target_key': request.targetKey,
        'status': ActionStatus.pending.name,
        'attempts': 0,
        'transient_failures': 0,
        'actor_user_id': actorUserId,
        'created_at': DateTime.now().toUtc().toIso8601String(),
      });
    } catch (_) {
      if (attachmentPath != null) await _deleteQuietly(attachmentPath);
      rethrow;
    }

    return (await find(key))!;
  }

  Future<PendingAction?> find(String key) async {
    final rows = await _db.raw.query(
      _table,
      where: 'idempotency_key = ?',
      whereArgs: [key],
      limit: 1,
    );
    return rows.isEmpty ? null : PendingAction.fromRow(rows.first);
  }

  /// Pending rows in the order they were tapped.
  Future<List<PendingAction>> pending() async {
    final rows = await _db.raw.query(
      _table,
      where: 'status = ?',
      whereArgs: [ActionStatus.pending.name],
      orderBy: 'rowid ASC',
    );
    return rows.map(PendingAction.fromRow).toList();
  }

  /// Pending and refused rows, for the screens that show "waiting to send".
  Future<List<PendingAction>> outstanding() async {
    final rows = await _db.raw.query(
      _table,
      where: 'status IN (?, ?)',
      whereArgs: [ActionStatus.pending.name, ActionStatus.failed.name],
      orderBy: 'rowid ASC',
    );
    return rows.map(PendingAction.fromRow).toList();
  }

  Future<bool> hasEligible({bool ignoreBackoff = false, DateTime? now}) async {
    final rows = await _db.raw.query(
      _table,
      columns: ['idempotency_key'],
      where: ignoreBackoff
          ? 'status = ?'
          : 'status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?)',
      whereArgs: [
        ActionStatus.pending.name,
        if (!ignoreBackoff) nowForEligibility(now),
      ],
      limit: 1,
    );
    return rows.isNotEmpty;
  }

  /// The multipart attachment, decrypted in memory for sending.
  Future<Uint8List?> attachment(PendingAction action) async {
    final path = action.attachmentPath;
    if (path == null) return null;
    final file = File(path);
    if (!await file.exists()) return null;
    if (path.endsWith('.enc')) {
      final cipher = _cipher;
      if (cipher == null) throw StateError('Attachment needs the evidence key');
      return cipher.decryptFile(file);
    }
    return file.readAsBytes();
  }

  Future<void> markDone(String key) async {
    final action = await find(key);
    await _db.raw.update(
      _table,
      {
        'status': ActionStatus.done.name,
        'last_error': null,
        'next_attempt_at': null,
        'completed_at': DateTime.now().toUtc().toIso8601String(),
      },
      where: 'idempotency_key = ?',
      whereArgs: [key],
    );
    if (action?.attachmentPath != null) {
      await _deleteQuietly(action!.attachmentPath!);
    }
  }

  /// A definitive refusal. [seen]: the technician was watching and got the
  /// message, so it need not wait on the sync screen.
  Future<void> markRejected(
    String key,
    String reason, {
    bool seen = false,
  }) => _db.raw.rawUpdate(
    'UPDATE $_table SET status = ?, attempts = attempts + 1, last_error = ?, next_attempt_at = NULL '
    'WHERE idempotency_key = ?',
    [
      seen ? ActionStatus.dismissed.name : ActionStatus.failed.name,
      reason,
      key,
    ],
  );

  /// No answer, or the server could not take it now. The row stays pending
  /// under the same key; [retryAt] makes automatic syncs wait a backoff.
  Future<void> noteTransient(
    String key,
    String reason, {
    DateTime? retryAt,
  }) => _db.raw.rawUpdate(
    'UPDATE $_table SET transient_failures = transient_failures + 1, last_error = ?, next_attempt_at = ? '
    'WHERE idempotency_key = ? AND status = ?',
    [reason, encodeAttemptTime(retryAt), key, ActionStatus.pending.name],
  );

  /// Sends a refused action again — under the SAME key, so if the earlier
  /// attempt did land after all, the server replays rather than repeats it.
  Future<void> retry(String key) => _db.raw.update(
    _table,
    {
      'status': ActionStatus.pending.name,
      'last_error': null,
      'next_attempt_at': null,
    },
    where: 'idempotency_key = ? AND status = ?',
    whereArgs: [key, ActionStatus.failed.name],
  );

  Future<void> dismiss(String key) async {
    final action = await find(key);
    await _db.raw.update(
      _table,
      {'status': ActionStatus.dismissed.name},
      where: 'idempotency_key = ? AND status = ?',
      whereArgs: [key, ActionStatus.failed.name],
    );
    if (action?.attachmentPath != null) {
      await _deleteQuietly(action!.attachmentPath!);
    }
  }

  static Future<void> _deleteQuietly(String path) async {
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } catch (_) {}
  }
}
