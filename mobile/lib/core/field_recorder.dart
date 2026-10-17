import 'package:sqflite/sqflite.dart' show ConflictAlgorithm, DatabaseExecutor;

import 'event_queue.dart';
import 'local_db.dart';
import 'location.dart';
import 'parts.dart';
import 'visit_progress.dart';

/// Every field action that changes local data AND reports it to the server.
///
/// Each one commits the local change and its queued event in ONE SQLite
/// transaction. They used to be two writes: the checklist row was saved, then
/// the event enqueued. A crash, a full disk or a thrown exception between the
/// two left the phone showing work the server would never hear about — and
/// nothing would ever notice, because the screen looked right.
class FieldRecorder {
  FieldRecorder({
    required LocalDb db,
    required EventQueue queue,
    required VisitProgress progress,
    DateTime Function()? now,
  }) : _db = db,
       _queue = queue,
       _progress = progress,
       _now = now ?? DateTime.now;

  final LocalDb _db;
  final EventQueue _queue;
  final VisitProgress _progress;
  final DateTime Function() _now;

  /// Moves the visit forward locally and queues the event, atomically. Returns
  /// false (and queues nothing) when the transition is not legal from here.
  Future<bool> transition(
    int visitId,
    String target, {
    LocationFix? location,
    String? geofenceOverrideReason,
    int? actorUserId,
  }) => _db.raw.transaction((transaction) async {
    final applied = await _progress.applyTransition(
      visitId,
      target,
      executor: transaction,
    );
    if (!applied) return false;

    final reason = geofenceOverrideReason?.trim();
    await _queue.enqueueInTransaction(
      transaction,
      visitId: visitId,
      eventType: 'visit.transition',
      payload: {
        'to': target,
        if (reason != null && reason.isNotEmpty)
          'geofence_override_reason': reason,
      },
      location: location,
      actorUserId: actorUserId,
    );
    return true;
  });

  /// [noPartsUsed] defaults to the visit's standing declaration. It used to
  /// default to false, so recording any asset's status silently withdrew a
  /// "no parts used" the technician had already declared — the switch turned
  /// itself off, and the server's copy was overwritten with false too.
  Future<void> setChecklist(
    int visitId,
    int assetId, {
    required String status,
    String? note,
    bool? noPartsUsed,
    LocationFix? location,
    int? actorUserId,
  }) => _db.raw.transaction((transaction) async {
    final declared =
        noPartsUsed ?? await _declaredNoParts(transaction, visitId);
    await transaction.insert('local_checklists', {
      'visit_id': visitId,
      'asset_id': assetId,
      'status': status,
      'note': note,
      'no_parts_used': declared ? 1 : 0,
      'updated_at': _now().toIso8601String(),
    }, conflictAlgorithm: ConflictAlgorithm.replace);

    await _queue.enqueueInTransaction(
      transaction,
      visitId: visitId,
      eventType: 'checklist.upsert',
      payload: {
        'asset_id': assetId,
        'status': status,
        'note': note,
        'no_parts_used': declared,
      },
      location: location,
      actorUserId: actorUserId,
    );
  });

  /// Explicit per-visit declaration, decoupled from asset status.
  ///
  /// It used to be inferred from status == 'ok', which broke both ways: a faulty
  /// asset could never satisfy the gate, and a healthy asset whose part had just
  /// been replaced silently asserted "no parts used" — the exact stock leak the
  /// gate exists to catch.
  Future<void> declareNoParts(
    int visitId,
    bool value, {
    LocationFix? location,
    int? actorUserId,
  }) => _db.raw.transaction(
    (transaction) => _declare(
      transaction,
      visitId,
      value,
      location: location,
      actorUserId: actorUserId,
    ),
  );

  /// Visit-level, kept apart from the checklist rows: declared before any
  /// asset had a status, the update below touched no row and the declaration
  /// was lost the moment the screen reloaded.
  Future<void> _declare(
    DatabaseExecutor transaction,
    int visitId,
    bool value, {
    LocationFix? location,
    int? actorUserId,
  }) async {
    await transaction.insert('kv', {
      'k': _noPartsKey(visitId),
      'v': value ? '1' : '0',
    }, conflictAlgorithm: ConflictAlgorithm.replace);
    await transaction.update(
      'local_checklists',
      {'no_parts_used': value ? 1 : 0},
      where: 'visit_id = ?',
      whereArgs: [visitId],
    );

    await _queue.enqueueInTransaction(
      transaction,
      visitId: visitId,
      eventType: 'parts.declaration',
      payload: {'no_parts_used': value},
      location: location,
      actorUserId: actorUserId,
    );
  }

  Future<bool> noPartsDeclared(int visitId) =>
      _declaredNoParts(_db.raw, visitId);

  static String _noPartsKey(int visitId) => 'no_parts:$visitId';

  Future<bool> _declaredNoParts(DatabaseExecutor executor, int visitId) async {
    final stored = await executor.query(
      'kv',
      columns: ['v'],
      where: 'k = ?',
      whereArgs: [_noPartsKey(visitId)],
      limit: 1,
    );
    if (stored.isNotEmpty) return stored.first['v'] == '1';

    // Declarations made before the visit-level key existed live on the rows.
    final rows = await executor.rawQuery(
      'SELECT COUNT(*) AS c FROM local_checklists WHERE visit_id = ? AND no_parts_used = 1',
      [visitId],
    );
    return ((rows.first['c'] as int?) ?? 0) > 0;
  }

  /// Issues a part from the technician's own van to this visit, recorded and
  /// queued together. A visit that had declared "no parts" stops saying so in
  /// the same transaction: both cannot be true.
  ///
  /// The phone could not record a part at all before. The close gate demanded
  /// an answer, so the only answer available was "no parts used" — exactly the
  /// van-stock leak the gate exists to stop.
  Future<QueuedEvent> issuePart(
    int visitId, {
    required int partId,
    required double qty,
    required int fromLocationId,
    required String idempotencyKey,
    LocationFix? location,
    int? actorUserId,
  }) => _db.raw.transaction((transaction) async {
    if (await _declaredNoParts(transaction, visitId)) {
      await _declare(
        transaction,
        visitId,
        false,
        location: location,
        actorUserId: actorUserId,
      );
    }

    return _queue.enqueueInTransaction(
      transaction,
      visitId: visitId,
      eventType: 'part.issue',
      payload: {
        'part_id': partId,
        'qty': qty,
        'from_location_id': fromLocationId,
        'idempotency_key': idempotencyKey,
      },
      location: location,
      actorUserId: actorUserId,
    );
  });

  /// Parts this phone issued to the visit, newest first. Withdrawn ones are
  /// left out; refused ones stay, marked, so the technician sees them.
  Future<List<IssuedPart>> issuedParts(int visitId) async {
    final rows = await _db.raw.query(
      'pending_events',
      where: 'visit_id = ? AND event_type = ? AND status != ?',
      whereArgs: [visitId, 'part.issue', QueuedStatus.cancelled.name],
      orderBy: 'sequence DESC',
    );
    return rows.map(QueuedEvent.fromRow).map(IssuedPart.fromEvent).toList();
  }

  /// Issues not yet on the server, across every visit: what the van still
  /// owes on this phone's side.
  Future<List<IssuedPart>> unsentIssues() async {
    final rows = await _db.raw.query(
      'pending_events',
      where: 'event_type = ? AND status = ?',
      whereArgs: ['part.issue', QueuedStatus.pending.name],
    );
    return rows.map(QueuedEvent.fromRow).map(IssuedPart.fromEvent).toList();
  }

  /// Withdraws an issue the server has not received yet. Compare-and-set on
  /// `pending`: once it has gone out (or been refused) it is the server's
  /// record, corrected by a return in the panel. The caller holds the sync
  /// lock so an in-flight batch cannot carry it at the same moment.
  Future<bool> withdrawIssue(String clientEventId) async {
    final changed = await _db.raw.update(
      'pending_events',
      {
        'status': QueuedStatus.cancelled.name,
        'last_error': 'withdrawn by the technician before it synced',
      },
      where: 'client_event_id = ? AND event_type = ? AND status = ?',
      whereArgs: [clientEventId, 'part.issue', QueuedStatus.pending.name],
    );
    return changed > 0;
  }

  /// A plain event with no local side effect (scans, signer details).
  Future<QueuedEvent> record(
    int visitId,
    String eventType, {
    Map<String, dynamic> payload = const {},
    LocationFix? location,
    int? actorUserId,
  }) => _queue.enqueue(
    visitId: visitId,
    eventType: eventType,
    payload: payload,
    location: location,
    actorUserId: actorUserId,
  );
}
