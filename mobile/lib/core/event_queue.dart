import 'dart:convert';

import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import 'local_db.dart';
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
    this.source = 'offline',
    this.status = QueuedStatus.pending,
    this.attempts = 0,
    this.lastError,
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
  final String source;
  final QueuedStatus status;
  final int attempts;
  final String? lastError;

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
    source: (row['source'] as String?) ?? 'offline',
    status: QueuedStatus.values.byName(row['status'] as String),
    attempts: row['attempts'] as int? ?? 0,
    lastError: row['last_error'] as String?,
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
    if (lat != null) 'lat': lat,
    if (lng != null) 'lng': lng,
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
    double? lat,
    double? lng,
    String source = 'offline',
    String? clientEventId,
  }) async {
    final event = QueuedEvent(
      clientEventId: clientEventId ?? _uuid.v4(),
      visitId: visitId,
      eventType: eventType,
      payload: payload,
      deviceTimestamp: _clock.deviceNow,
      sequence: await _db.nextSequence(),
      monotonicOffsetMs: _clock.monotonicOffsetMs,
      lastTrustedServerTime: _clock.lastTrustedServerTime,
      lat: lat,
      lng: lng,
      source: source,
    );

    await _db.raw.insert('pending_events', {
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
      'source': event.source,
      'status': QueuedStatus.pending.name,
      'attempts': 0,
      'created_at': DateTime.now().toIso8601String(),
    }, conflictAlgorithm: ConflictAlgorithm.ignore);

    return event;
  }

  /// Oldest first, by the device sequence — the causal order the server replays in.
  Future<List<QueuedEvent>> pending({int limit = 200}) async {
    final rows = await _db.raw.query(
      'pending_events',
      where: 'status = ?',
      whereArgs: [QueuedStatus.pending.name],
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
        {'status': QueuedStatus.synced.name, 'last_error': null},
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
      'UPDATE pending_events SET status = ?, attempts = attempts + 1, last_error = ? '
      'WHERE client_event_id = ? AND status = ?',
      [QueuedStatus.failed.name, reason, id, QueuedStatus.pending.name],
    );
  }

  /// Transport failure (no signal, 500). Not the event's fault — it stays pending
  /// and only the attempt counter moves.
  Future<void> noteAttempt(Iterable<String> ids, String reason) async {
    if (ids.isEmpty) return;

    final batch = _db.raw.batch();
    for (final id in ids) {
      batch.rawUpdate(
        'UPDATE pending_events SET attempts = attempts + 1, last_error = ? '
        'WHERE client_event_id = ? AND status = ?',
        [reason, id, QueuedStatus.pending.name],
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

  /// Supervisor-approved retry of a rejected action.
  Future<void> requeue(String id) async {
    await _db.raw.update(
      'pending_events',
      {'status': QueuedStatus.pending.name, 'last_error': null},
      where: 'client_event_id = ? AND status = ?',
      whereArgs: [id, QueuedStatus.failed.name],
    );
  }
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
