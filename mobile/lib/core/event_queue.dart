import 'dart:convert';

import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import 'backoff.dart';
import 'local_db.dart';
import 'location.dart';
import 'trusted_clock.dart';

/// Status of a queued action as the technician sees it: بانتظار المزامنة / فشل / تمت.
/// `cancelled` is for work the technician withdrew before it was ever sent —
/// a registration for a photo they discarded. It is terminal like `synced`, but
/// it must not be counted as delivered.
enum QueuedStatus { pending, failed, synced, cancelled }

class QueuedEvent {
  QueuedEvent({
    required this.clientEventId,
    required this.visitId,
    required this.eventType,
    required this.payload,
    required this.deviceTimestamp,
    required this.sequence,
    this.monotonicOffsetMs,
    this.lastTrustedServerTime,
    this.lat,
    this.lng,
    this.accuracyM,
    this.isMocked,
    this.actorUserId,
    this.source = 'offline',
    this.status = QueuedStatus.pending,
    this.attempts = 0,
    this.lastError,
    this.nextAttemptAt,
    this.transientFailures = 0,
  });

  final String clientEventId;
  final int visitId;
  final String eventType;
  final Map<String, dynamic> payload;
  final DateTime deviceTimestamp;
  final int sequence;
  final int? monotonicOffsetMs;
  final DateTime? lastTrustedServerTime;
  final double? lat;
  final double? lng;
  final double? accuracyM;
  final bool? isMocked;

  /// The technician signed in when this was recorded (contract C6). The server
  /// refuses an event whose author is not the token's user.
  final int? actorUserId;
  final String source;
  final QueuedStatus status;
  final int attempts;
  final String? lastError;
  final DateTime? nextAttemptAt;

  /// Consecutive outages/server failures; drives the backoff, never a failure.
  final int transientFailures;

  factory QueuedEvent.fromRow(Map<String, dynamic> row) => QueuedEvent(
    clientEventId: row['client_event_id'] as String,
    visitId: row['visit_id'] as int,
    eventType: row['event_type'] as String,
    payload: row['payload'] == null
        ? <String, dynamic>{}
        : jsonDecode(row['payload'] as String) as Map<String, dynamic>,
    deviceTimestamp: DateTime.parse(row['device_timestamp'] as String),
    sequence: row['sequence'] as int,
    monotonicOffsetMs: row['monotonic_offset_ms'] as int?,
    lastTrustedServerTime: row['last_trusted_server_time'] == null
        ? null
        : DateTime.tryParse(row['last_trusted_server_time'] as String),
    lat: (row['lat'] as num?)?.toDouble(),
    lng: (row['lng'] as num?)?.toDouble(),
    accuracyM: (row['accuracy_m'] as num?)?.toDouble(),
    isMocked: row['is_mocked'] == null ? null : row['is_mocked'] == 1,
    actorUserId: row['actor_user_id'] as int?,
    source: (row['source'] as String?) ?? 'offline',
    status: QueuedStatus.values.byName(row['status'] as String),
    attempts: row['attempts'] as int? ?? 0,
    lastError: row['last_error'] as String?,
    transientFailures: row['transient_failures'] as int? ?? 0,
    nextAttemptAt: row['next_attempt_at'] == null
        ? null
        : DateTime.tryParse(row['next_attempt_at'] as String),
  );

  Map<String, dynamic> toWire() => {
    'client_event_id': clientEventId,
    'visit_id': visitId,
    'event_type': eventType,
    'payload': payload,
    // UTC, always. A bare local timestamp with no offset is reinterpreted in
    // the server's timezone, so a device in a different zone looked hours out
    // of step and was falsely flagged as having a tampered clock.
    'device_timestamp': deviceTimestamp.toUtc().toIso8601String(),
    if (monotonicOffsetMs != null) 'monotonic_offset_ms': monotonicOffsetMs,
    if (lastTrustedServerTime != null)
      'last_trusted_server_time': lastTrustedServerTime!
          .toUtc()
          .toIso8601String(),
    'sequence': sequence,
    // Location evidence, top-level (contract C3).
    if (lat != null) 'lat': lat,
    if (lng != null) 'lng': lng,
    if (accuracyM != null) 'accuracy_m': accuracyM,
    if (isMocked != null) 'is_mocked': isMocked,
    if (actorUserId != null) 'actor_user_id': actorUserId,
    'source': source,
  };
}

/// The outbound queue.
///
/// Two rules make replay safe:
///  1. The UUID is generated HERE, on the device, before anything is sent. The
///     server keys idempotency on it, so a retry after a timeout — where we never
///     learned whether the first attempt landed — cannot double-apply.
///  2. Nothing is deleted on send. A row moves pending -> synced, and a rejected
///     row keeps its reason so the technician can be told what went wrong.
class EventQueue {
  EventQueue(this._db, this._clock, {Uuid? uuid})
    : _uuid = uuid ?? const Uuid();

  final LocalDb _db;
  final TrustedClock _clock;
  final Uuid _uuid;

  Future<QueuedEvent> enqueue({
    required int visitId,
    required String eventType,
    Map<String, dynamic> payload = const {},
    LocationFix? location,
    double? lat,
    double? lng,
    int? actorUserId,
    String source = 'offline',
    String? clientEventId,
  }) => _db.raw.transaction(
    (transaction) => enqueueInTransaction(
      transaction,
      visitId: visitId,
      eventType: eventType,
      payload: payload,
      location: location,
      lat: lat,
      lng: lng,
      actorUserId: actorUserId,
      source: source,
      clientEventId: clientEventId,
    ),
  );

  /// Inserts an event and advances its causal sequence in the caller's SQLite
  /// transaction. Evidence capture and every local write (state, checklist,
  /// parts declaration) use this so the change and the event that reports it
  /// commit as one indivisible unit.
  Future<QueuedEvent> enqueueInTransaction(
    DatabaseExecutor transaction, {
    required int visitId,
    required String eventType,
    Map<String, dynamic> payload = const {},
    LocationFix? location,
    double? lat,
    double? lng,
    int? actorUserId,
    String source = 'offline',
    String? clientEventId,
  }) async {
    final time = await _clock.sample();

    final event = QueuedEvent(
      clientEventId: clientEventId ?? _uuid.v4(),
      visitId: visitId,
      eventType: eventType,
      payload: payload,
      deviceTimestamp: time.deviceTime,
      sequence: await _db.nextSequence(transaction),
      monotonicOffsetMs: time.monotonicOffsetMs,
      lastTrustedServerTime: time.lastTrustedServerTime,
      lat: location?.lat ?? lat,
      lng: location?.lng ?? lng,
      accuracyM: location?.accuracyM,
      isMocked: location?.isMocked,
      actorUserId: actorUserId,
      source: source,
    );

    await transaction.insert('pending_events', {
      'client_event_id': event.clientEventId,
      'visit_id': event.visitId,
      'event_type': event.eventType,
      'payload': jsonEncode(event.payload),
      'device_timestamp': event.deviceTimestamp.toIso8601String(),
      'monotonic_offset_ms': event.monotonicOffsetMs,
      'last_trusted_server_time': event.lastTrustedServerTime
          ?.toIso8601String(),
      'sequence': event.sequence,
      'lat': event.lat,
      'lng': event.lng,
      'accuracy_m': event.accuracyM,
      'is_mocked': event.isMocked == null ? null : (event.isMocked! ? 1 : 0),
      'actor_user_id': event.actorUserId,
      'source': event.source,
      'status': QueuedStatus.pending.name,
      'attempts': 0,
      'created_at': DateTime.now().toIso8601String(),
    }, conflictAlgorithm: ConflictAlgorithm.abort);

    return event;
  }

  /// Oldest first, by the device sequence — the causal order the server replays in.
  ///
  /// Rows waiting out a server-side backoff are skipped unless [ignoreBackoff]
  /// (a sync the technician asked for by hand).
  Future<List<QueuedEvent>> pending({
    int limit = 200,
    bool ignoreBackoff = true,
    DateTime? now,
  }) async {
    final rows = await _db.raw.query(
      'pending_events',
      where: ignoreBackoff
          ? 'status = ?'
          : 'status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?)',
      whereArgs: [
        QueuedStatus.pending.name,
        if (!ignoreBackoff) nowForEligibility(now),
      ],
      orderBy: 'sequence ASC',
      limit: limit,
    );

    return rows.map(QueuedEvent.fromRow).toList();
  }

  Future<void> markSynced(Iterable<String> ids) async {
    if (ids.isEmpty) return;

    final batch = _db.raw.batch();
    for (final id in ids) {
      batch.update(
        'pending_events',
        {
          'status': QueuedStatus.synced.name,
          'last_error': null,
          'next_attempt_at': null,
        },
        // A response can arrive after the technician discarded the evidence.
        // Never revive a registration that was cancelled while the request was
        // in flight.
        where: 'client_event_id = ? AND status = ?',
        whereArgs: [id, QueuedStatus.pending.name],
      );
    }
    await batch.commit(noResult: true);
  }

  Future<void> markFailed(String id, String reason) async {
    await _db.raw.rawUpdate(
      'UPDATE pending_events SET status = ?, attempts = attempts + 1, last_error = ?, next_attempt_at = NULL '
      'WHERE client_event_id = ? AND status = ?',
      [QueuedStatus.failed.name, reason, id, QueuedStatus.pending.name],
    );
  }

  /// No answer, a 5xx/429, or a server-side failure of the event
  /// (EVENT_FAILED / DB_ERROR). Not the event's fault and NOT an attempt: only
  /// the transient counter that drives the backoff moves. With [retryAt] the
  /// rows wait it out before automatic syncs retry them.
  Future<void> noteTransient(
    Iterable<String> ids,
    String reason, {
    DateTime? retryAt,
  }) async {
    if (ids.isEmpty) return;

    final batch = _db.raw.batch();
    for (final id in ids) {
      batch.rawUpdate(
        'UPDATE pending_events SET transient_failures = transient_failures + ?, last_error = ?, next_attempt_at = ? '
        'WHERE client_event_id = ? AND status = ?',
        [
          retryAt == null ? 0 : 1,
          reason,
          encodeAttemptTime(retryAt),
          id,
          QueuedStatus.pending.name,
        ],
      );
    }
    await batch.commit(noResult: true);
  }

  /// The server looked at the event and refused it for now (a close waiting
  /// for its evidence). It stays pending; the attempt counter moves.
  Future<void> noteAttempt(
    Iterable<String> ids,
    String reason, {
    DateTime? retryAt,
  }) async {
    if (ids.isEmpty) return;

    final batch = _db.raw.batch();
    for (final id in ids) {
      batch.rawUpdate(
        'UPDATE pending_events SET attempts = attempts + 1, last_error = ?, next_attempt_at = ? '
        'WHERE client_event_id = ? AND status = ?',
        [reason, encodeAttemptTime(retryAt), id, QueuedStatus.pending.name],
      );
    }
    await batch.commit(noResult: true);
  }

  Future<Map<QueuedStatus, int>> counts() async {
    final rows = await _db.raw.rawQuery(
      'SELECT status, COUNT(*) AS c FROM pending_events GROUP BY status',
    );

    return {
      for (final status in QueuedStatus.values)
        status:
            rows
                .cast<Map<String, dynamic>>()
                .where((r) => r['status'] == status.name)
                .map((r) => r['c'] as int)
                .firstOrNull ??
            0,
    };
  }

  Future<List<QueuedEvent>> failed() async {
    final rows = await _db.raw.query(
      'pending_events',
      where: 'status = ?',
      whereArgs: [QueuedStatus.failed.name],
      orderBy: 'sequence DESC',
    );

    return rows.map(QueuedEvent.fromRow).toList();
  }

  /// True while a `media.register` for this file has not reached the server.
  /// Uploading before it lands only earns a 404 for an unknown file.
  Future<bool> registrationPending(String clientMediaId) async {
    final rows = await _db.raw.query(
      'pending_events',
      columns: ['payload'],
      where: 'event_type = ? AND status = ? AND payload LIKE ?',
      whereArgs: [
        'media.register',
        QueuedStatus.pending.name,
        '%$clientMediaId%',
      ],
    );
    return rows.any((row) {
      final payload =
          jsonDecode(row['payload'] as String? ?? '{}') as Map<String, dynamic>;
      return payload['client_media_id'] == clientMediaId;
    });
  }

  /// Cancels a `media.register` that can no longer produce useful work.
  ///
  /// Both pending and rejected registrations are terminally cancelled. We still
  /// ask the server to discard the media afterwards: a pending request may have
  /// reached the server even when its response never reached this device.
  Future<bool> cancelUnsettledMediaRegistration(String clientMediaId) async {
    final rows = await _db.raw.query(
      'pending_events',
      where: 'event_type = ? AND status IN (?, ?) AND payload LIKE ?',
      whereArgs: [
        'media.register',
        QueuedStatus.pending.name,
        QueuedStatus.failed.name,
        '%$clientMediaId%',
      ],
    );

    // LIKE is a coarse filter; confirm against the decoded payload so a partial
    // string match cannot cancel the wrong registration.
    final matching = rows.where((row) {
      final payload =
          jsonDecode(row['payload'] as String? ?? '{}') as Map<String, dynamic>;
      return payload['client_media_id'] == clientMediaId;
    }).toList();

    if (matching.isEmpty) {
      return false;
    }

    var cancelled = 0;

    for (final row in matching) {
      cancelled += await _db.raw.update(
        'pending_events',
        {
          'status': QueuedStatus.cancelled.name,
          'last_error': 'discarded before it synced',
        },
        // Compare-and-set keeps a late sync result and a technician action from
        // silently overwriting each other.
        where: 'client_event_id = ? AND status IN (?, ?)',
        whereArgs: [
          row['client_event_id'],
          QueuedStatus.pending.name,
          QueuedStatus.failed.name,
        ],
      );
    }

    return cancelled > 0;
  }

  /// Refused events of one visit, newest first.
  Future<List<QueuedEvent>> failedFor(int visitId) async {
    final rows = await _db.raw.query(
      'pending_events',
      where: 'status = ? AND visit_id = ?',
      whereArgs: [QueuedStatus.failed.name, visitId],
      orderBy: 'sequence DESC',
    );

    return rows.map(QueuedEvent.fromRow).toList();
  }

  /// The technician gives up on an event the server refused for good — a
  /// visit reassigned to a colleague, a payload the server will never accept.
  /// Retry was the only option before, so one such event blocked sign-out
  /// until a supervisor revoked the phone. Only a refused event can be
  /// abandoned: anything not yet answered still goes out.
  Future<bool> abandon(String id) async {
    final changed = await _db.raw.rawUpdate(
      "UPDATE pending_events SET status = ?, "
      "last_error = 'abandoned by the technician after: ' || COALESCE(last_error, '') "
      'WHERE client_event_id = ? AND status = ?',
      [QueuedStatus.cancelled.name, id, QueuedStatus.failed.name],
    );
    return changed > 0;
  }

  /// Supervisor-approved retry of a rejected action.
  Future<void> requeue(String id) async {
    await _db.raw.update(
      'pending_events',
      {
        'status': QueuedStatus.pending.name,
        'last_error': null,
        'next_attempt_at': null,
      },
      where: 'client_event_id = ? AND status = ?',
      whereArgs: [id, QueuedStatus.failed.name],
    );
  }
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
