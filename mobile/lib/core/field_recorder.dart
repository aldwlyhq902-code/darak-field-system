import 'package:sqflite/sqflite.dart' show ConflictAlgorithm;

import 'event_queue.dart';
import 'local_db.dart';
import 'location.dart';
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

  Future<void> setChecklist(
    int visitId,
    int assetId, {
    required String status,
    String? note,
    bool noPartsUsed = false,
    LocationFix? location,
    int? actorUserId,
  }) => _db.raw.transaction((transaction) async {
    await transaction.insert('local_checklists', {
      'visit_id': visitId,
      'asset_id': assetId,
      'status': status,
      'note': note,
      'no_parts_used': noPartsUsed ? 1 : 0,
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
        'no_parts_used': noPartsUsed,
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
  }) => _db.raw.transaction((transaction) async {
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
  });

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
