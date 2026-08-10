import 'dart:async';
import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';

import 'api_client.dart';
import 'event_queue.dart';
import 'evidence_store.dart';
import 'local_db.dart';
import 'trusted_clock.dart';
import 'visit_progress.dart';

class SyncOutcome {
  const SyncOutcome({
    required this.accepted,
    required this.duplicate,
    required this.rejected,
    required this.deferred,
    this.error,
    this.sessionExpired = false,
    this.heldBack = 0,
  });

  final int accepted;
  final int duplicate;
  final int rejected;
  final int deferred; // left pending because the network failed, not the event
  final String? error;

  /// The token is dead (401/403). The caller must sign the technician out —
  /// retrying forever with a dead token just burns battery and buries the queue.
  final bool sessionExpired;

  /// Close events deliberately withheld until their evidence finishes uploading.
  final int heldBack;

  bool get isClean => rejected == 0 && error == null;

  int get total => accepted + duplicate + rejected + deferred;
}

/// Drives the outbound queue and the resumable uploads.
///
/// Design rules, each of which exists because of a specific failure:
///  - The device generates the idempotency key, so a timeout (where we never
///    learn whether the write landed) is safe to retry.
///  - A rejected event and a failed request are different things. Rejected means
///    the server understood and refused — surface it. Failed means the network
///    died — keep it pending and try again.
///  - Uploads resume from the server's byte offset; a dropped link never costs
///    the whole file.
///  - Nothing is ever deleted to make the queue look clean.
class SyncEngine {
  SyncEngine({
    required this.api,
    required this.queue,
    required this.db,
    required this.clock,
    required this.deviceUuid,
    VisitProgress? progress,
    this.evidenceRoot,
    this.chunkSize = 256 * 1024,
    this.maxAttempts = 8,
  }) : progress = progress ?? VisitProgress(db);

  /// Where captured evidence lives, so completed and fully-uploaded visits can
  /// have their files reclaimed.
  final String? evidenceRoot;

  final ApiClient api;
  final EventQueue queue;
  final LocalDb db;
  final TrustedClock clock;
  final String deviceUuid;
  final VisitProgress progress;
  final int chunkSize;
  final int maxAttempts;

  bool _running = false;

  /// Pull the day's work. Safe to call repeatedly; it refreshes the local cache
  /// without discarding anything the technician is still working on.
  Future<void> bootstrap() async {
    final response = await api.bootstrap();

    final serverTime = DateTime.tryParse(response['server_time'] as String? ?? '');
    if (serverTime != null) {
      await clock.adopt(serverTime);
    }

    final visits = (response['visits'] as List<dynamic>? ?? const [])
        .cast<Map<String, dynamic>>();

    // A visit with unsynced work survives a refresh even if the server's window
    // no longer returns it — past midnight it would otherwise vanish from under
    // a technician still standing on site.
    final protectedIds = <int>{};

    for (final row in await db.raw.rawQuery(
      "SELECT DISTINCT visit_id FROM pending_events WHERE status != 'synced' "
      "UNION SELECT DISTINCT visit_id FROM pending_media WHERE state != 'complete'",
    )) {
      final id = row['visit_id'];
      if (id is int) protectedIds.add(id);
    }

    await db.replaceVisits(visits, protectedVisitIds: protectedIds);
  }

  /// Returns null when a sync is already in flight.
  ///
  /// It used to return a zero outcome, which callers could not tell apart from a
  /// genuinely empty queue — so a concurrent tap reported "nothing to sync" and
  /// stamped a fresh success time while the real sync was still running.
  Future<SyncOutcome?> sync() async {
    if (_running) {
      return null;
    }

    _running = true;

    try {
      final outcome = await _pushEvents();
      await _pushMedia();
      await _pruneUploadedEvidence();
      return outcome;
    } finally {
      _running = false;
    }
  }

  /// Frees disk for visits the server has confirmed closed and whose evidence is
  /// fully uploaded. Anything still pending is never touched — that is unsent
  /// evidence, and losing it loses the proof the visit happened.
  Future<void> _pruneUploadedEvidence() async {
    final rows = await db.raw.rawQuery(
      "SELECT v.id AS visit_id FROM visits v "
      "WHERE v.state = 'completed' "
      "AND NOT EXISTS (SELECT 1 FROM pending_media m WHERE m.visit_id = v.id AND m.state != 'complete') "
      "AND NOT EXISTS (SELECT 1 FROM pending_events e WHERE e.visit_id = v.id AND e.status != 'synced')",
    );

    final store = EvidenceStore(db: db, queue: queue, clock: clock, rootDirectory: evidenceRoot ?? '');

    for (final row in rows) {
      final visitId = row['visit_id'];
      if (visitId is int) {
        await store.pruneUploaded(visitId);
      }
    }
  }

  Future<SyncOutcome> _pushEvents() async {
    final queued = await queue.pending();

    if (queued.isEmpty) {
      return const SyncOutcome(accepted: 0, duplicate: 0, rejected: 0, deferred: 0);
    }

    // A close is judged against evidence the server can see. Sending it before
    // the photos have uploaded guarantees a refusal, so hold it back — per visit,
    // so one slow upload never blocks a different visit's close.
    final blockedVisits = await progress.visitsWithPendingUploads();

    final batch = <QueuedEvent>[];
    var heldBack = 0;

    for (final event in queued) {
      final isClose = event.eventType == 'visit.transition' && event.payload['to'] == 'completed';

      if (isClose && blockedVisits.contains(event.visitId)) {
        heldBack++;
        continue;
      }

      batch.add(event);
    }

    if (batch.isEmpty) {
      return SyncOutcome(
        accepted: 0, duplicate: 0, rejected: 0, deferred: 0, heldBack: heldBack,
      );
    }

    final ids = batch.map((e) => e.clientEventId).toList();

    try {
      final response = await api.pushEvents(
        deviceUuid: deviceUuid,
        events: batch.map((e) => e.toWire()).toList(),
        lastTrustedServerTime: clock.lastTrustedServerTime,
      );

      final serverTime = DateTime.tryParse(response['server_time'] as String? ?? '');
      if (serverTime != null) {
        await clock.adopt(serverTime);
      }

      // The server's word on every visit it touched. Applied before the per-event
      // bookkeeping so the UI reflects the truth even if a later step throws.
      await progress.adoptCanonical(
        (response['visits'] as List<dynamic>? ?? const []).cast<Map<String, dynamic>>(),
      );

      final results = (response['results'] as List<dynamic>? ?? const [])
          .cast<Map<String, dynamic>>();

      final settled = <String>[];
      var accepted = 0;
      var duplicate = 0;
      var rejected = 0;
      var deferredRetryable = 0;

      for (final result in results) {
        final id = result['client_event_id'] as String?;
        if (id == null) continue;

        switch (result['status']) {
          case 'accepted':
            accepted++;
            settled.add(id);
          case 'duplicate':
            // The first attempt did land. Exactly the case the UUID exists for.
            duplicate++;
            settled.add(id);
          case 'rejected':
            // A close refused only because its uploads have not landed yet is a
            // TEMPORARY refusal: it clears itself. Parking it as "failed" is what
            // forced the technician to hunt for a red row and requeue by hand.
            if (result['retryable'] == true) {
              await queue.noteAttempt([id], '${result['code']}: waiting for evidence');
              deferredRetryable++;
            } else {
              rejected++;
              await queue.markFailed(
                id,
                '${result['code'] ?? 'REJECTED'}: ${result['message'] ?? ''}',
              );
            }
        }
      }

      await queue.markSynced(settled);

      return SyncOutcome(
        accepted: accepted,
        duplicate: duplicate,
        rejected: rejected,
        deferred: deferredRetryable,
        heldBack: heldBack,
      );
    } on ApiException catch (e) {
      // A dead token is not the events' fault. Keep the day's work queued and
      // tell the app to sign out, rather than flipping dozens of valid events to
      // "failed" and then failing them again on every retry.
      if (e.statusCode == 401 || e.statusCode == 403) {
        await queue.noteAttempt(ids, 'session expired');

        return SyncOutcome(
          accepted: 0, duplicate: 0, rejected: 0, deferred: batch.length,
          error: e.message, sessionExpired: true,
        );
      }

      if (e.isRetryable) {
        // Transport problem — the events are innocent and stay pending.
        await queue.noteAttempt(ids, e.toString());
        return SyncOutcome(
          accepted: 0,
          duplicate: 0,
          rejected: 0,
          deferred: batch.length,
          error: e.message,
        );
      }

      for (final id in ids) {
        await queue.markFailed(id, e.toString());
      }

      return SyncOutcome(
        accepted: 0,
        duplicate: 0,
        rejected: batch.length,
        deferred: 0,
        error: e.message,
      );
    }
  }

  Future<void> _pushMedia() async {
    final rows = await db.raw.query(
      'pending_media',
      where: 'state IN (?, ?)',
      whereArgs: ['pending', 'uploading'],
      orderBy: 'rowid ASC',
    );

    for (final row in rows) {
      try {
        await _uploadOne(row);
      } catch (e) {
        final attempts = ((row['attempts'] as int?) ?? 0) + 1;

        // After enough tries this stops being a transient blip. Marking it
        // 'failed' puts it in front of the technician instead of retrying
        // silently forever while the close it gates never goes through.
        final exhausted = attempts >= maxAttempts;

        await db.raw.rawUpdate(
          'UPDATE pending_media SET attempts = ?, last_error = ?, state = ? WHERE client_media_id = ?',
          [attempts, e.toString(), exhausted ? 'failed' : 'uploading', row['client_media_id']],
        );
      }
    }
  }

  /// Uploads that have given up, so the sync screen can show them rather than
  /// leaving a visit un-closable for reasons nobody can see.
  Future<List<Map<String, dynamic>>> failedUploads() => db.raw.query(
        'pending_media',
        where: 'state = ?',
        whereArgs: ['failed'],
      );

  /// Puts a given-up upload back in the queue, resetting its attempt count.
  /// Without this a failed photo is a dead end the technician cannot clear.
  Future<void> retryUpload(String clientMediaId) async {
    await db.raw.update(
      'pending_media',
      {'state': 'pending', 'attempts': 0, 'last_error': null},
      where: 'client_media_id = ?',
      whereArgs: [clientMediaId],
    );
  }

  Future<void> _uploadOne(Map<String, dynamic> row) async {
    final id = row['client_media_id'] as String;
    final file = File(row['local_path'] as String);

    if (!await file.exists()) {
      await db.raw.rawUpdate(
        'UPDATE pending_media SET state = ?, last_error = ? WHERE client_media_id = ?',
        ['failed', 'local file missing', id],
      );
      return;
    }

    // Always ask the server where it is rather than trusting our own counter —
    // the last chunk may have arrived after we lost the reply.
    var offset = 0;
    try {
      final status = await api.mediaStatus(id);
      if (status['upload_state'] == 'complete') {
        await _markComplete(id);
        return;
      }
      offset = (status['uploaded_bytes'] as num?)?.toInt() ?? 0;
    } on ApiException catch (e) {
      if (e.statusCode != 404) rethrow;
    }

    final total = await file.length();
    final handle = await file.open();

    try {
      while (offset < total) {
        await handle.setPosition(offset);
        final length = min(chunkSize, total - offset);
        final bytes = await handle.read(length);

        try {
          final response = await api.uploadChunk(
            clientMediaId: id,
            offset: offset,
            bytes: Uint8List.fromList(bytes),
          );
          offset = (response['uploaded_bytes'] as num?)?.toInt() ?? (offset + length);
        } on ApiException catch (e) {
          if (e.code == 'OFFSET_MISMATCH') {
            // Resync to the server's truth and carry on from there.
            offset = (e.body['expected_offset'] as num?)?.toInt() ?? 0;
            continue;
          }
          rethrow;
        }

        await db.raw.update(
          'pending_media',
          {'uploaded_bytes': offset, 'state': 'uploading'},
          where: 'client_media_id = ?',
          whereArgs: [id],
        );
      }
    } finally {
      await handle.close();
    }

    // The hash recorded at CAPTURE time, not one recomputed from whatever is on
    // disk now. Re-hashing here would happily certify a file that changed after
    // it was taken, defeating the reason for hashing at all.
    final captured = row['sha256'] as String?;
    final digest = captured ?? sha256.convert(await file.readAsBytes()).toString();

    await api.completeUpload(clientMediaId: id, sha256: digest);
    await _markComplete(id, sha256Hex: digest);
  }

  Future<void> _markComplete(String id, {String? sha256Hex}) async {
    await db.raw.update(
      'pending_media',
      {
        'state': 'complete',
        'last_error': null,
        if (sha256Hex != null) 'sha256': sha256Hex,
      },
      where: 'client_media_id = ?',
      whereArgs: [id],
    );
  }
}
