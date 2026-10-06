import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/foundation.dart' show debugPrint;

import 'action_outbox.dart';
import 'api_client.dart';
import 'backoff.dart';
import 'error_messages.dart';
import 'event_queue.dart';
import 'evidence_cipher.dart';
import 'evidence_store.dart';
import 'local_db.dart';
import 'trusted_clock.dart';
import 'visit_progress.dart';

class SyncOutcome {
  const SyncOutcome({
    this.accepted = 0,
    this.duplicate = 0,
    this.rejected = 0,
    this.deferred = 0,
    this.heldBack = 0,
    this.actionsSent = 0,
    this.actionsRejected = 0,
    this.actionsWaiting = 0,
    this.uploaded = 0,
    this.uploadsWaiting = 0,
    this.error,
    this.sessionExpired = false,
    this.remoteWipeCode,
    this.connectivityLost = false,
    this.pulled = false,
    this.halted = false,
    this.pendingApproval = false,
  });

  final int accepted;
  final int duplicate;
  final int rejected;
  final int deferred; // left pending because the network failed, not the event

  /// Close events deliberately withheld until their evidence finishes uploading.
  final int heldBack;

  final int actionsSent;
  final int actionsRejected;
  final int actionsWaiting;
  final int uploaded;
  final int uploadsWaiting;

  /// One Arabic sentence for the technician; details went to the debug log.
  final String? error;

  /// The token is dead (401). The caller must send the technician to the login
  /// screen — and KEEP the queue (contract C1).
  final bool sessionExpired;

  /// 403 DEVICE_REVOKED / USER_INACTIVE: the remote-wipe path (contract C1).
  final String? remoteWipeCode;

  /// No answer from the server at all. Everything after the failing step was
  /// skipped: uploading photos into a dead link only burned their attempts.
  final bool connectivityLost;

  /// The day's work was pulled after the push.
  final bool pulled;

  /// Stop sending further batches in this run.
  final bool halted;

  /// 403 DEVICE_PENDING_APPROVAL on a protected route: back to the login
  /// screen with everything kept — never a wipe.
  final bool pendingApproval;

  bool get remoteWipe => remoteWipeCode != null;

  bool get mustStop => sessionExpired || remoteWipe || pendingApproval;

  bool get isClean => rejected == 0 && actionsRejected == 0 && error == null;

  int get total => accepted + duplicate + rejected + deferred;

  int get sent => accepted + duplicate;

  SyncOutcome operator +(SyncOutcome other) => SyncOutcome(
    accepted: accepted + other.accepted,
    duplicate: duplicate + other.duplicate,
    rejected: rejected + other.rejected,
    deferred: deferred + other.deferred,
    heldBack: max(heldBack, other.heldBack),
    actionsSent: actionsSent + other.actionsSent,
    actionsRejected: actionsRejected + other.actionsRejected,
    actionsWaiting: actionsWaiting + other.actionsWaiting,
    uploaded: uploaded + other.uploaded,
    uploadsWaiting: uploadsWaiting + other.uploadsWaiting,
    error: other.error ?? error,
    sessionExpired: sessionExpired || other.sessionExpired,
    remoteWipeCode: other.remoteWipeCode ?? remoteWipeCode,
    connectivityLost: connectivityLost || other.connectivityLost,
    pulled: pulled || other.pulled,
    halted: halted || other.halted,
    pendingApproval: pendingApproval || other.pendingApproval,
  );

  SyncOutcome copyWith({int? heldBack, bool? pulled, String? error}) =>
      SyncOutcome(
        accepted: accepted,
        duplicate: duplicate,
        rejected: rejected,
        deferred: deferred,
        heldBack: heldBack ?? this.heldBack,
        actionsSent: actionsSent,
        actionsRejected: actionsRejected,
        actionsWaiting: actionsWaiting,
        uploaded: uploaded,
        uploadsWaiting: uploadsWaiting,
        error: error ?? this.error,
        sessionExpired: sessionExpired,
        remoteWipeCode: remoteWipeCode,
        connectivityLost: connectivityLost,
        pulled: pulled ?? this.pulled,
        halted: halted,
        pendingApproval: pendingApproval,
      );

  static SyncOutcome fromAuthFailure(ApiException e, {int deferred = 0}) =>
      SyncOutcome(
        deferred: deferred,
        sessionExpired: e.isSessionExpired,
        remoteWipeCode: e.isRemoteWipe ? e.code : null,
        pendingApproval: e.isPendingApproval,
        error: friendlyError(e),
        halted: true,
      );
}

enum ActionAttemptKind { done, queued, rejected, sessionExpired, remoteWipe }

/// What happened to one outbox action sent while the technician watched.
class ActionAttempt {
  const ActionAttempt(
    this.kind, {
    this.message,
    this.body = const {},
    this.replayed = false,
    this.remoteWipeCode,
    this.connectivityLost = false,
  });

  final ActionAttemptKind kind;

  /// Arabic, for the screen.
  final String? message;
  final Map<String, dynamic> body;
  final bool replayed;
  final String? remoteWipeCode;

  /// Queued because nothing answered (as opposed to a busy server).
  final bool connectivityLost;
}

/// Bookkeeping for one sync run, so an item that could not be settled is not
/// re-sent over and over within the same run.
class _Run {
  final skippedEvents = <String>{};
  final skippedActions = <String>{};
  final skippedMedia = <String>{};
}

class _UploadStalled implements Exception {
  _UploadStalled(this.reason);
  final String reason;

  @override
  String toString() => 'UPLOAD_STALLED: $reason';
}

/// Drives the outbound queue, the action outbox and the resumable uploads.
///
/// Design rules, each of which exists because of a specific failure:
///  - The device generates the idempotency key, so a timeout (where we never
///    learn whether the write landed) is safe to retry.
///  - A rejected item and a failed request are different things. Rejected means
///    the server understood and refused — surface it. Failed means the network
///    died — keep it pending and try again, WITHOUT spending its attempts.
///  - Push before pull. A refresh that ran ahead of the queued work used to
///    roll offline progress back on screen.
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
    EvidenceStore? evidence,
    ActionOutbox? outbox,
    this.chunkSize = 256 * 1024,
    this.maxAttempts = 8,
    this.batchLimit = 200,
    this.batchByteLimit = 512 * 1024,
    this.maxRounds = 4,
    this.maxBatchesPerRun = 50,
    this.maxOffsetResyncs = 5,
    this.backoff = const Backoff(),
  }) : progress = progress ?? VisitProgress(db),
       evidence =
           evidence ??
           EvidenceStore(
             db: db,
             queue: queue,
             clock: clock,
             rootDirectory: evidenceRoot ?? '',
           ),
       outbox = outbox ?? ActionOutbox(db, attachmentRoot: evidenceRoot ?? '');

  /// Where captured evidence lives, so completed and fully-uploaded visits can
  /// have their files reclaimed.
  final String? evidenceRoot;

  final ApiClient api;
  final EventQueue queue;
  final LocalDb db;
  final TrustedClock clock;
  final String deviceUuid;
  final VisitProgress progress;
  final EvidenceStore evidence;
  final ActionOutbox outbox;
  final int chunkSize;

  /// Definitive refusals an upload may collect before it is shown as failed.
  /// Network and server outages never count toward it.
  final int maxAttempts;

  /// The server accepts at most 200 events per request.
  final int batchLimit;

  /// A second ceiling, by size: 200 events at the server's 32 KB payload
  /// limit is a 6 MB request that cannot finish on a weak link before the
  /// timeout — and then never finishes at all.
  final int batchByteLimit;

  /// Push → actions → uploads, repeated while new work keeps arriving.
  final int maxRounds;
  final int maxBatchesPerRun;

  /// OFFSET_MISMATCH resyncs allowed for one file in one run. Unbounded, a
  /// confused server and this loop could spin forever holding the sync lock.
  final int maxOffsetResyncs;
  final Backoff backoff;

  bool _running = false;
  Completer<void>? _idle;

  bool get isRunning => _running;

  /// Serializes sync, retry, and discard operations on the same local queue.
  ///
  /// A discard racing an in-flight media registration is ambiguous: the server
  /// may have accepted the registration even when the device has not received
  /// the response yet. One operation at a time makes the state transition
  /// deterministic and lets the discard reconcile with the server afterwards.
  Future<T> exclusive<T>(Future<T> Function() operation) async {
    while (_running) {
      final idle = _idle;
      if (idle != null) {
        await idle.future;
      }
    }

    _running = true;
    final idle = Completer<void>();
    _idle = idle;

    try {
      return await operation();
    } finally {
      _running = false;
      if (!idle.isCompleted) {
        idle.complete();
      }
      if (identical(_idle, idle)) {
        _idle = null;
      }
    }
  }

  /// Pull the day's work. Safe to call repeatedly; it refreshes the local cache
  /// without discarding — or rolling back — anything the technician is still
  /// working on. Prefer `sync(pull: true)`, which pushes first.
  Future<void> bootstrap() => exclusive(_bootstrap);

  Future<void> _bootstrap() async {
    final response = await api.bootstrap();

    final serverTime = DateTime.tryParse(
      response['server_time'] as String? ?? '',
    );
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
      "SELECT DISTINCT visit_id FROM pending_events WHERE status NOT IN ('synced', 'cancelled') "
      "UNION SELECT DISTINCT visit_id FROM pending_media "
      "WHERE state NOT IN ('complete', 'discarded')",
    )) {
      final id = row['visit_id'];
      if (id is int) protectedIds.add(id);
    }

    await db.replaceVisits(
      visits,
      protectedVisitIds: protectedIds,
      // The server has not seen these visits' queued transitions yet; its
      // state for them is older than the phone's.
      localStateVisitIds: await progress.visitsWithPendingTransitions(),
    );
  }

  /// Returns null when a sync is already in flight.
  ///
  /// It used to return a zero outcome, which callers could not tell apart from a
  /// genuinely empty queue — so a concurrent tap reported "nothing to sync" and
  /// stamped a fresh success time while the real sync was still running.
  ///
  /// [force] ignores retry backoff (the technician pressed "sync"); automatic
  /// triggers respect it. [pull] refreshes the day's work AFTER the push.
  Future<SyncOutcome?> sync({bool force = false, bool pull = false}) {
    if (_running) {
      return Future.value();
    }

    return exclusive(() => _run(force: force, pull: pull));
  }

  Future<SyncOutcome> _run({required bool force, required bool pull}) async {
    final run = _Run();

    // Discards first: a file the technician dropped must stop being uploaded,
    // and must stop blocking the close, before anything else is attempted.
    var outcome = await _flushDiscards();
    if (outcome.mustStop) return outcome;

    for (var round = 0; round < maxRounds; round++) {
      final events = await _pushEvents(run, force: force);
      outcome = outcome + events;
      if (events.mustStop) return outcome;

      // An events push that died on the network means the network is gone.
      // Uploading after it only failed every photo too — and those failures
      // used to count, until a basement had marked the evidence "failed".
      if (events.connectivityLost) break;

      final actions = await _flushActions(run, force: force);
      outcome = outcome + actions;
      if (actions.mustStop) return outcome;
      if (actions.connectivityLost) break;

      final media = await _pushMedia(run, force: force);
      outcome = outcome + media;
      if (media.mustStop) return outcome;
      if (media.connectivityLost) break;

      // Work recorded while this round ran (a tap during a long upload), or a
      // close released by uploads that just finished, goes out now instead of
      // waiting for the next trigger.
      if (!await _hasMoreWork(run, force: force)) break;
    }

    if (pull && !outcome.connectivityLost) {
      try {
        await _bootstrap();
        outcome = outcome.copyWith(pulled: true);
      } on ApiException catch (e) {
        if (e.stopsSession) {
          return outcome + SyncOutcome.fromAuthFailure(e);
        }
        outcome =
            outcome +
            SyncOutcome(
              error: friendlyError(e, context: 'bootstrap'),
              connectivityLost: e.isConnectivity,
            );
      }
    }

    await _pruneUploadedEvidence();
    return outcome.copyWith(heldBack: await _heldBackCount());
  }

  Future<bool> _hasMoreWork(_Run run, {required bool force}) async {
    final events = await queue.pending(
      limit: run.skippedEvents.length + 64,
      ignoreBackoff: force,
    );
    final blocked = await progress.visitsWithPendingUploads();
    if (events.any(
      (e) =>
          !run.skippedEvents.contains(e.clientEventId) &&
          !(_isClose(e) && blocked.contains(e.visitId)),
    )) {
      return true;
    }

    final actions = await outbox.pending();
    if (actions.any(
      (a) =>
          !run.skippedActions.contains(a.idempotencyKey) &&
          (force || !_waiting(a.nextAttemptAt)),
    )) {
      return true;
    }

    final media = await _mediaCandidates(force: force);
    return media.any(
      (row) => !run.skippedMedia.contains(row['client_media_id']),
    );
  }

  static bool _isClose(QueuedEvent event) =>
      event.eventType == 'visit.transition' &&
      event.payload['to'] == 'completed';

  static bool _waiting(DateTime? nextAttemptAt) =>
      nextAttemptAt != null && nextAttemptAt.isAfter(DateTime.now().toUtc());

  Future<int> _heldBackCount() async {
    final blocked = await progress.visitsWithPendingUploads();
    if (blocked.isEmpty) return 0;

    final rows = await db.raw.query(
      'pending_events',
      columns: ['visit_id', 'payload'],
      where: "status = 'pending' AND event_type = 'visit.transition'",
    );
    return rows.where((row) {
      final payload =
          jsonDecode(row['payload'] as String? ?? '{}') as Map<String, dynamic>;
      return blocked.contains(row['visit_id']) && payload['to'] == 'completed';
    }).length;
  }

  /// Frees disk for visits the server has confirmed closed and whose evidence is
  /// fully uploaded. Anything still pending is never touched — that is unsent
  /// evidence, and losing it loses the proof the visit happened.
  Future<void> _pruneUploadedEvidence() async {
    final rows = await db.raw.rawQuery(
      "SELECT v.id AS visit_id FROM visits v "
      "WHERE v.state = 'completed' "
      "AND NOT EXISTS (SELECT 1 FROM pending_media m WHERE m.visit_id = v.id AND m.state NOT IN ('complete', 'discarded')) "
      "AND NOT EXISTS (SELECT 1 FROM pending_events e WHERE e.visit_id = v.id AND e.status NOT IN ('synced', 'cancelled'))",
    );

    for (final row in rows) {
      final visitId = row['visit_id'];
      if (visitId is int) {
        await evidence.pruneUploaded(visitId);
      }
    }
  }

  // ---------------------------------------------------------------- events

  /// Sends every eligible event, in batches of at most [batchLimit] events and
  /// [batchByteLimit] bytes. It used to send ONE batch of 200 per sync; a day
  /// offline left the rest waiting for taps that might never come.
  Future<SyncOutcome> _pushEvents(_Run run, {required bool force}) async {
    var outcome = const SyncOutcome();

    for (var round = 0; round < maxBatchesPerRun; round++) {
      // A close is judged against evidence the server can see. Sending it before
      // the photos have uploaded guarantees a refusal, so hold it back — per
      // visit, so one slow upload never blocks a different visit's close.
      final blockedVisits = await progress.visitsWithPendingUploads();
      final candidates = await queue.pending(
        limit: batchLimit + run.skippedEvents.length + 64,
        ignoreBackoff: force,
      );

      final batch = <QueuedEvent>[];
      var bytes = 0;

      for (final event in candidates) {
        if (run.skippedEvents.contains(event.clientEventId)) continue;
        if (_isClose(event) && blockedVisits.contains(event.visitId)) continue;

        final size = utf8.encode(jsonEncode(event.toWire())).length;
        if (batch.isNotEmpty &&
            (batch.length >= batchLimit || bytes + size > batchByteLimit)) {
          break;
        }
        batch.add(event);
        bytes += size;
      }

      if (batch.isEmpty) break;

      final result = await _sendBatch(batch, run);
      outcome = outcome + result;
      if (result.mustStop || result.connectivityLost || result.halted) break;
    }

    return outcome;
  }

  /// One request. When the server refuses the REQUEST because of something in
  /// it (a malformed event, a body too large), the culprit is isolated — by the
  /// validation keys when the server names them, otherwise by bisection — and
  /// only the culprit is parked as failed. It used to mark all 200 failed for
  /// the sake of one.
  Future<SyncOutcome> _sendBatch(List<QueuedEvent> batch, _Run run) async {
    final ids = batch.map((e) => e.clientEventId).toList();

    final Map<String, dynamic> response;
    try {
      response = await api.pushEvents(
        deviceUuid: deviceUuid,
        events: batch.map((e) => e.toWire()).toList(),
        lastTrustedServerTime: clock.lastTrustedServerTime,
      );
    } on ApiException catch (e) {
      return _batchFailed(batch, e, run);
    }

    final serverTime = DateTime.tryParse(
      response['server_time'] as String? ?? '',
    );
    if (serverTime != null) {
      await clock.adopt(serverTime);
    }

    final results = (response['results'] as List<dynamic>? ?? const [])
        .whereType<Map>()
        .map((row) => Map<String, dynamic>.from(row));

    final settled = <String>[];
    final answered = <String>{};
    var accepted = 0;
    var duplicate = 0;
    var rejected = 0;
    var deferredRetryable = 0;

    for (final result in results) {
      final id = result['client_event_id'] as String?;
      if (id == null || !ids.contains(id)) continue;
      answered.add(id);

      switch (result['status']) {
        case 'accepted':
          accepted++;
          settled.add(id);
        case 'duplicate':
          // The first attempt did land. Exactly the case the UUID exists for.
          duplicate++;
          settled.add(id);
        case 'rejected':
          final event = batch.firstWhere((e) => e.clientEventId == id);
          final code = result['code'];
          if (serverSideEventCodes.contains(code)) {
            // The server failed on its side (and logged it under request_id).
            // Nothing is wrong with the event: retry it later, never park it.
            await queue.noteTransient(
              [id],
              '$code (request ${result['request_id'] ?? '?'})',
              retryAt: backoff.nextAttempt(event.transientFailures + 1),
            );
            run.skippedEvents.add(id);
            deferredRetryable++;
          } else if (result['retryable'] == true) {
            // A close refused only because its uploads have not landed yet is
            // a TEMPORARY refusal: it clears itself. Parking it as "failed" is
            // what forced the technician to hunt for a red row and requeue by
            // hand.
            await queue.noteAttempt(
              [id],
              '$code: waiting for evidence',
              retryAt: backoff.nextAttempt(event.attempts + 1),
            );
            run.skippedEvents.add(id);
            deferredRetryable++;
          } else {
            rejected++;
            await queue.markFailed(
              id,
              '${result['code'] ?? 'REJECTED'}: ${result['message'] ?? ''}',
            );
          }
        default:
          run.skippedEvents.add(id);
      }
    }

    // An event the server did not mention stays pending for the next run.
    for (final id in ids) {
      if (!answered.contains(id)) run.skippedEvents.add(id);
    }

    await queue.markSynced(settled);

    // The server's word on every visit it touched — applied AFTER the
    // bookkeeping, so transitions it has just acknowledged no longer shield
    // their visit, while ones still queued (a held-back close) do.
    await progress.adoptCanonical(
      (response['visits'] as List<dynamic>? ?? const [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
    );

    return SyncOutcome(
      accepted: accepted,
      duplicate: duplicate,
      rejected: rejected,
      deferred: deferredRetryable + (ids.length - answered.length),
    );
  }

  Future<SyncOutcome> _batchFailed(
    List<QueuedEvent> batch,
    ApiException e,
    _Run run,
  ) async {
    final ids = batch.map((e) => e.clientEventId).toList();

    // A dead token, a device awaiting approval or a revoked one is not the
    // events' fault. Keep the day's work queued, rather than flipping valid
    // events to "failed".
    if (e.stopsSession) {
      await queue.noteTransient(ids, e.toString());
      return SyncOutcome.fromAuthFailure(e, deferred: batch.length);
    }

    if (e.isConnectivity) {
      // No answer at all. The events are innocent and the next trigger —
      // connectivity returning, the app resuming — retries immediately.
      await queue.noteTransient(ids, e.toString());
      return SyncOutcome(
        deferred: batch.length,
        error: friendlyError(e, context: 'sync/events'),
        connectivityLost: true,
        halted: true,
      );
    }

    if (e.isServerTransient) {
      // 5xx, or 429 (rate limited): back off — by the server's Retry-After
      // when it gives one — and count nothing against the events.
      final failures = batch.map((e) => e.transientFailures).reduce(max);
      await queue.noteTransient(
        ids,
        e.toString(),
        retryAt: _retryAt(e, failures + 1),
      );
      return SyncOutcome(
        deferred: batch.length,
        error: friendlyError(e, context: 'sync/events'),
        halted: true,
      );
    }

    final culprits = _culpritIndexes(e, batch.length);
    if (culprits.isNotEmpty) {
      for (final index in culprits) {
        await queue.markFailed(batch[index].clientEventId, _rejectionText(e));
      }
      final rest = [
        for (var i = 0; i < batch.length; i++)
          if (!culprits.contains(i)) batch[i],
      ];
      final remainder = rest.isEmpty
          ? const SyncOutcome()
          : await _sendBatch(rest, run);
      return remainder + SyncOutcome(rejected: culprits.length);
    }

    if (_isBisectable(e)) {
      if (batch.length == 1) {
        await queue.markFailed(batch.single.clientEventId, _rejectionText(e));
        return const SyncOutcome(rejected: 1);
      }

      final middle = batch.length ~/ 2;
      final left = await _sendBatch(batch.sublist(0, middle), run);
      if (left.mustStop || left.connectivityLost || left.halted) {
        return left + SyncOutcome(deferred: batch.length - middle);
      }
      return left + await _sendBatch(batch.sublist(middle), run);
    }

    // The REQUEST was refused for a reason no event caused (another 403, a 404,
    // a 409): keep them all, back off, and say why. Never a sign-out (C1).
    final failures = batch.map((e) => e.transientFailures).reduce(max);
    await queue.noteTransient(
      ids,
      e.toString(),
      retryAt: _retryAt(e, failures + 1),
    );
    run.skippedEvents.addAll(ids);
    return SyncOutcome(
      deferred: batch.length,
      error: friendlyError(e, context: 'sync/events'),
      halted: true,
    );
  }

  /// Upload refusals that no retry can change (422 IMAGE_TOO_LARGE: over
  /// 40 MP or 12000 px on a side).
  static const terminalMediaCodes = {'IMAGE_TOO_LARGE'};

  /// Per-event refusals that are the SERVER's failure, not the event's
  /// (they carry a request_id for the logs): retried with backoff.
  static const serverSideEventCodes = {'EVENT_FAILED', 'DB_ERROR'};

  /// The server's Retry-After when it sent one, otherwise our own backoff.
  DateTime _retryAt(ApiException e, int failures) {
    final after = e.retryAfter;
    if (after != null) return DateTime.now().toUtc().add(after);
    return backoff.nextAttempt(failures);
  }

  static String _rejectionText(ApiException e) {
    final errors = e.body['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return '${e.code}: ${first.first}';
    }
    return '${e.code}: ${e.message}';
  }

  /// Laravel names the offending entries: {"errors": {"events.17.payload": …}}.
  static Set<int> _culpritIndexes(ApiException e, int length) {
    final errors = e.body['errors'];
    if (e.statusCode != 422 || errors is! Map) return const {};
    final pattern = RegExp(r'^events\.(\d+)(\.|$)');
    return {
      for (final key in errors.keys)
        if (pattern.firstMatch('$key') case final match?)
          if (int.parse(match.group(1)!) case final index when index < length)
            index,
    };
  }

  /// Refusals that may be caused by one event in the batch. A 422 whose
  /// errors name only request-level fields (device_uuid…) is not: bisecting
  /// it would mark every innocent event failed, one at a time.
  static bool _isBisectable(ApiException e) {
    if (e.statusCode == 413 || e.statusCode == 400) return true;
    if (e.statusCode != 422) return false;
    final errors = e.body['errors'];
    if (errors is Map && errors.isNotEmpty) {
      return errors.keys.any((key) => '$key'.startsWith('events'));
    }
    return true;
  }

  // ---------------------------------------------------------------- actions

  /// Sends one outbox action now, while the technician watches. When a sync is
  /// already running the action simply waits its turn in the outbox.
  Future<ActionAttempt> trySendAction(String idempotencyKey) async {
    if (_running) return const ActionAttempt(ActionAttemptKind.queued);

    return exclusive(() async {
      final action = await outbox.find(idempotencyKey);
      if (action == null || action.status != ActionStatus.pending) {
        return const ActionAttempt(ActionAttemptKind.queued);
      }
      return _sendAction(action, seen: true);
    });
  }

  Future<SyncOutcome> _flushActions(_Run run, {required bool force}) async {
    var sent = 0;
    var rejected = 0;
    var waiting = 0;
    // Items for the same target go strictly in order (the scans before the
    // stocktake's close). Once one waits, the rest of its target waits too.
    final blockedTargets = <String>{};

    for (final action in await outbox.pending()) {
      final key = action.idempotencyKey;
      final target = action.targetKey;

      final blocked = target != null && blockedTargets.contains(target);
      final skipped = run.skippedActions.contains(key);
      if (blocked || skipped || (!force && _waiting(action.nextAttemptAt))) {
        if (target != null) blockedTargets.add(target);
        waiting++;
        continue;
      }

      final attempt = await _sendAction(action);
      switch (attempt.kind) {
        case ActionAttemptKind.done:
          sent++;
        case ActionAttemptKind.rejected:
          rejected++;
        case ActionAttemptKind.queued:
          run.skippedActions.add(key);
          if (target != null) blockedTargets.add(target);
          waiting++;
          if (attempt.connectivityLost) {
            return SyncOutcome(
              actionsSent: sent,
              actionsRejected: rejected,
              actionsWaiting: waiting,
              connectivityLost: true,
              error: attempt.message,
            );
          }
        case ActionAttemptKind.sessionExpired:
        case ActionAttemptKind.remoteWipe:
          return SyncOutcome(
            actionsSent: sent,
            actionsRejected: rejected,
            sessionExpired: attempt.kind == ActionAttemptKind.sessionExpired,
            remoteWipeCode: attempt.remoteWipeCode,
            error: attempt.message,
            halted: true,
          );
      }
    }

    return SyncOutcome(
      actionsSent: sent,
      actionsRejected: rejected,
      actionsWaiting: waiting,
    );
  }

  Future<ActionAttempt> _sendAction(
    PendingAction action, {
    bool seen = false,
  }) async {
    final key = action.idempotencyKey;

    try {
      final ApiResult result;
      if (action.isMultipart) {
        result = await api.postMultipartAction(
          path: action.path,
          fields: action.body.map((k, v) => MapEntry(k, '$v')),
          idempotencyKey: key,
          fileField: action.attachmentField,
          fileBytes: await outbox.attachment(action),
          fileName: action.attachmentName,
        );
      } else {
        result = await api.postAction(
          path: action.path,
          body: action.body,
          idempotencyKey: key,
        );
      }

      await outbox.markDone(key);
      return ActionAttempt(
        ActionAttemptKind.done,
        body: result.body,
        replayed: result.replayed,
      );
    } on ApiException catch (e) {
      if (e.stopsSession) {
        // 401, awaiting approval, or revoked: the action waits under its key.
        await outbox.noteTransient(key, e.toString());
        return ActionAttempt(
          e.isRemoteWipe
              ? ActionAttemptKind.remoteWipe
              : ActionAttemptKind.sessionExpired,
          message: friendlyError(e),
          remoteWipeCode: e.isRemoteWipe ? e.code : null,
        );
      }

      if (e.isConnectivity) {
        await outbox.noteTransient(key, e.toString());
        return ActionAttempt(
          ActionAttemptKind.queued,
          message: friendlyError(e, context: action.kind),
          connectivityLost: true,
        );
      }

      if (e.isServerTransient) {
        // 5xx, 429, or IDEMPOTENCY_REQUEST_IN_PROGRESS (the first attempt
        // under this key is still running): ask again later, SAME key.
        await outbox.noteTransient(
          key,
          e.toString(),
          retryAt: _retryAt(e, action.transientFailures + 1),
        );
        return ActionAttempt(
          ActionAttemptKind.queued,
          message: friendlyError(e, context: action.kind),
        );
      }

      // Definitive — including IDEMPOTENCY_KEY_REUSED / _INVALID, which the
      // client has already logged as the programming errors they are.
      final message = friendlyError(e, context: action.kind);
      await outbox.markRejected(key, message, seen: seen);
      return ActionAttempt(ActionAttemptKind.rejected, message: message);
    } catch (e) {
      // A local failure (the attachment could not be read or decrypted).
      final message = friendlyError(e, context: action.kind);
      await outbox.markRejected(key, message, seen: seen);
      return ActionAttempt(ActionAttemptKind.rejected, message: message);
    }
  }

  // ---------------------------------------------------------------- media

  Future<List<Map<String, dynamic>>> _mediaCandidates({
    required bool force,
  }) => db.raw.query(
    'pending_media',
    where: force
        ? 'state IN (?, ?)'
        : 'state IN (?, ?) AND (next_attempt_at IS NULL OR next_attempt_at <= ?)',
    whereArgs: ['pending', 'uploading', if (!force) nowForEligibility()],
    orderBy: 'rowid ASC',
  );

  Future<SyncOutcome> _pushMedia(_Run run, {required bool force}) async {
    var uploaded = 0;
    var waiting = 0;

    for (final row in await _mediaCandidates(force: force)) {
      final id = row['client_media_id'] as String;
      if (run.skippedMedia.contains(id)) continue;

      // The server has not been told about this file yet (its registration is
      // still queued); an upload now would only earn a 404 and an attempt.
      if (await queue.registrationPending(id)) {
        waiting++;
        continue;
      }

      try {
        await _uploadOne(row);
        uploaded++;
      } on ApiException catch (e) {
        run.skippedMedia.add(id);

        if (e.stopsSession) {
          return SyncOutcome(uploaded: uploaded) +
              SyncOutcome.fromAuthFailure(e);
        }

        if (e.isConnectivity) {
          // Not the photo's fault and not counted. The rest of the files would
          // only fail the same way, so stop here.
          await _noteMediaTransient(id, e, retryAt: null);
          return SyncOutcome(
            uploaded: uploaded,
            uploadsWaiting: waiting + 1,
            connectivityLost: true,
            error: friendlyError(e, context: 'media'),
          );
        }

        if (e.isServerTransient) {
          await _noteMediaTransient(
            id,
            e,
            retryAt: _retryAt(
              e,
              ((row['transient_failures'] as int?) ?? 0) + 1,
            ),
          );
          waiting++;
          continue;
        }

        if (terminalMediaCodes.contains(e.code)) {
          // No retry can change the answer (the image itself is refused):
          // show it at once so the technician can drop it and retake.
          await _noteMediaRejection(row, e, terminal: true);
          continue;
        }

        await _noteMediaRejection(row, e);
      } catch (e) {
        // A definitive local or protocol failure: the file is unreadable, its
        // encryption does not verify, or the server keeps moving the offset.
        run.skippedMedia.add(id);
        await _noteMediaRejection(row, e);
      }
    }

    return SyncOutcome(uploaded: uploaded, uploadsWaiting: waiting);
  }

  Future<void> _noteMediaTransient(
    String id,
    Object error, {
    required DateTime? retryAt,
  }) => db.raw.rawUpdate(
    'UPDATE pending_media SET transient_failures = transient_failures + ?, last_error = ?, next_attempt_at = ? '
    'WHERE client_media_id = ?',
    [retryAt == null ? 0 : 1, error.toString(), encodeAttemptTime(retryAt), id],
  );

  /// Only a refusal the server MEANT (or a file that cannot be read) counts
  /// against the attempt budget. After [maxAttempts] it is shown as failed.
  Future<void> _noteMediaRejection(
    Map<String, dynamic> row,
    Object error, {
    bool terminal = false,
  }) async {
    final attempts = ((row['attempts'] as int?) ?? 0) + 1;
    final exhausted = terminal || attempts >= maxAttempts;

    debugPrint('Upload of ${row['client_media_id']} refused: $error');

    await db.raw.rawUpdate(
      'UPDATE pending_media SET attempts = ?, last_error = ?, state = ?, next_attempt_at = ? '
      'WHERE client_media_id = ? AND state IN (?, ?)',
      [
        attempts,
        error.toString(),
        exhausted ? 'failed' : 'uploading',
        exhausted ? null : encodeAttemptTime(backoff.nextAttempt(attempts)),
        row['client_media_id'],
        'pending',
        'uploading',
      ],
    );
  }

  /// Uploads that have given up, so the sync screen can show them rather than
  /// leaving a visit un-closable for reasons nobody can see.
  Future<List<Map<String, dynamic>>> failedUploads() =>
      db.raw.query('pending_media', where: 'state = ?', whereArgs: ['failed']);

  /// Puts a given-up upload back in the queue, resetting its attempt count.
  /// Without this a failed photo is a dead end the technician cannot clear.
  Future<void> retryUpload(String clientMediaId) => exclusive(() async {
    await db.raw.update(
      'pending_media',
      {
        'state': 'pending',
        'attempts': 0,
        'transient_failures': 0,
        'next_attempt_at': null,
        'last_error': null,
      },
      where: 'client_media_id = ? AND state = ?',
      whereArgs: [clientMediaId, 'failed'],
    );
  });

  /// Drops a file the technician has given up on.
  ///
  /// The local registration is cancelled first, but the server is always asked
  /// to discard too. A pending event may already have landed upstream with its
  /// response lost, so local queue state alone cannot prove the server has never
  /// heard of the file.
  Future<void> discardUpload(String clientMediaId, {required String reason}) =>
      exclusive(() async {
        await queue.cancelUnsettledMediaRegistration(clientMediaId);

        await db.raw.update(
          'pending_media',
          {'state': 'discard_pending', 'last_error': reason},
          where: 'client_media_id = ? AND state != ?',
          whereArgs: [clientMediaId, 'complete'],
        );

        await _flushDiscards();
      });

  Future<SyncOutcome> _flushDiscards() async {
    final rows = await db.raw.query(
      'pending_media',
      where: 'state = ?',
      whereArgs: ['discard_pending'],
    );

    for (final row in rows) {
      final id = row['client_media_id'] as String;

      try {
        await api.discardMedia(
          clientMediaId: id,
          reason: (row['last_error'] as String?) ?? 'تعذّر الرفع',
        );

        await db.raw.update(
          'pending_media',
          {'state': 'discarded'},
          where: 'client_media_id = ?',
          whereArgs: [id],
        );
      } on ApiException catch (e) {
        if (e.statusCode == 404) {
          // All pending/failed registrations were cancelled before this request,
          // under the same engine lock. Nothing can create the row later, so a
          // server-side absence is a successful reconciliation, not a deadlock.
          await db.raw.update(
            'pending_media',
            {'state': 'discarded'},
            where: 'client_media_id = ?',
            whereArgs: [id],
          );
          continue;
        }
        if (e.stopsSession) {
          return SyncOutcome.fromAuthFailure(e);
        }
        if (e.isConnectivity) break;
        // Anything else stays discard_pending and is retried by the next sync.
      }
    }

    return const SyncOutcome();
  }

  Future<void> _uploadOne(Map<String, dynamic> row) async {
    final id = row['client_media_id'] as String;

    if (!await File(row['local_path'] as String).exists()) {
      await db.raw.rawUpdate(
        'UPDATE pending_media SET state = ?, last_error = ? WHERE client_media_id = ?',
        ['failed', 'local file missing', id],
      );
      return;
    }

    // Plaintext, decrypted segment by segment in memory: the server hashes
    // what it receives against the capture-time hash of the plaintext.
    final EvidenceReader reader = await evidence.openReader(row);

    try {
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

      final total = reader.length;
      var resyncs = 0;

      while (offset < total) {
        final length = min(chunkSize, total - offset);
        final bytes = await reader.read(max(0, offset), length);

        try {
          final response = await api.uploadChunk(
            clientMediaId: id,
            offset: offset,
            bytes: bytes,
          );
          offset =
              (response['uploaded_bytes'] as num?)?.toInt() ??
              (offset + length);
        } on ApiException catch (e) {
          if (e.code == 'OFFSET_MISMATCH') {
            if (++resyncs > maxOffsetResyncs) {
              throw _UploadStalled('the server kept moving the offset');
            }
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

      // The hash recorded at CAPTURE time, not one recomputed from whatever is
      // on disk now. Re-hashing here would happily certify a file that changed
      // after it was taken, defeating the reason for hashing at all.
      final captured = row['sha256'] as String?;
      final digest = captured ?? await reader.sha256Hex();

      await api.completeUpload(clientMediaId: id, sha256: digest);
      await _markComplete(id, sha256Hex: digest);
    } finally {
      await reader.close();
    }
  }

  Future<void> _markComplete(String id, {String? sha256Hex}) async {
    await db.raw.update(
      'pending_media',
      {
        'state': 'complete',
        'last_error': null,
        'next_attempt_at': null,
        'transient_failures': 0,
        if (sha256Hex != null) 'sha256': sha256Hex,
      },
      where: 'client_media_id = ?',
      whereArgs: [id],
    );
  }
}
