import 'package:sqflite/sqflite.dart' show DatabaseExecutor;

import 'local_db.dart';

/// The visit state machine, mirrored on the device.
///
/// Review found the app could not advance a visit without a network at all: the
/// only writer of `visits.state` was the bootstrap response, so a technician
/// offline tapped "انطلاق" and nothing moved. Every later button was derived from
/// that state, so the whole visit was unreachable — in the exact conditions the
/// product was built for.
///
/// Two rules keep the mirror honest:
///  - It refuses illegal transitions locally rather than queueing an event that
///    can only come back as a 409 and sit in the technician's face as "failed".
///  - The server's canonical state wins once it has seen the device's
///    transitions. While a transition is still queued the server's state is
///    simply OLDER than the phone's, and adopting it would undo offline work.
class VisitProgress {
  VisitProgress(this._db, {DateTime Function()? now})
    : _now = now ?? DateTime.now;

  final LocalDb _db;

  /// The on-site counter's clock. The app passes the trusted estimate so that
  /// moving the phone's clock forward cannot inflate billable minutes.
  final DateTime Function() _now;

  /// Mirrors `contracts/visit_transitions.json`. PHP and Flutter tests both fail
  /// if either implementation drifts from that shared contract.
  static const Map<String, List<String>> transitions = {
    'scheduled': ['en_route'],
    'en_route': ['started', 'scheduled'],
    'started': ['paused', 'awaiting_close'],
    'paused': ['started', 'awaiting_close'],
    'awaiting_close': ['completed', 'started'],
    'completed': ['reopened'],
    'reopened': ['started'],
  };

  static bool allows(String from, String to) =>
      (transitions[from] ?? const []).contains(to);

  Future<String?> currentState(
    int visitId, {
    DatabaseExecutor? executor,
  }) async {
    final rows = await (executor ?? _db.raw).query(
      'visits',
      columns: ['state'],
      where: 'id = ?',
      whereArgs: [visitId],
      limit: 1,
    );
    return rows.isEmpty ? null : rows.first['state'] as String?;
  }

  /// @return the states reachable right now, for building the action buttons.
  Future<List<String>> nextStates(int visitId) async {
    final state = await currentState(visitId);
    return state == null ? const [] : (transitions[state] ?? const []);
  }

  /// Applies a transition locally. Returns false if it is not legal from the
  /// current state — the caller should not queue an event in that case.
  ///
  /// Pass the caller's [executor] to commit the state change and its queued
  /// event in ONE transaction: a crash between the two used to leave the phone
  /// showing a state the server would never hear about.
  Future<bool> applyTransition(
    int visitId,
    String target, {
    DatabaseExecutor? executor,
  }) async {
    final db = executor ?? _db.raw;
    final rows = await db.query(
      'visits',
      columns: ['state', 'local_started_at', 'on_site_seconds'],
      where: 'id = ?',
      whereArgs: [visitId],
      limit: 1,
    );
    final visit = rows.isEmpty ? null : rows.first;
    final state = visit?['state'] as String?;

    if (state == null || !allows(state, target)) {
      return false;
    }

    final now = _now();
    final values = <String, Object?>{'state': target};

    // The on-site counter is advisory on the device; the server recomputes it
    // from the event chain. It exists so the technician sees a live number.
    if (state == 'started') {
      final startedAt = DateTime.tryParse(
        (visit?['local_started_at'] as String?) ?? '',
      );

      if (startedAt != null) {
        final elapsed = now.difference(startedAt).inSeconds;
        values['on_site_seconds'] =
            ((visit?['on_site_seconds'] as int?) ?? 0) +
            (elapsed > 0 ? elapsed : 0);
      }
      values['local_started_at'] = null;
    }

    if (target == 'started') {
      values['local_started_at'] = now.toUtc().toIso8601String();
    }

    await db.update('visits', values, where: 'id = ?', whereArgs: [visitId]);

    return true;
  }

  /// The server's word, applied after a sync.
  ///
  /// Skipped for any visit that still has a transition queued on this device:
  /// a close held back for its photos, or a transition recorded while this
  /// batch was in flight. Adopting the server's (older) state there rolled the
  /// visit back on screen; the queued transition then arrived and the button
  /// the technician needed had vanished in between.
  ///
  /// @param canonical entries of {id, state} as returned by /sync/events
  Future<void> adoptCanonical(List<Map<String, dynamic>> canonical) async {
    if (canonical.isEmpty) return;

    final pending = await visitsWithPendingTransitions();

    await _db.raw.transaction((transaction) async {
      for (final entry in canonical) {
        final id = entry['id'];
        final state = entry['state'];

        if (id is! int || state is! String || pending.contains(id)) continue;

        final current = await currentState(id, executor: transaction);
        final values = <String, Object?>{'state': state};

        if (entry['on_site_seconds'] != null) {
          values['on_site_seconds'] = entry['on_site_seconds'];
        }
        // A start time is only meaningful for the state it was taken in.
        if (current != state) {
          values['local_started_at'] = null;
        }

        await transaction.update(
          'visits',
          values,
          where: 'id = ?',
          whereArgs: [id],
        );
      }
    });
  }

  /// Visits whose local state is ahead of the server: a transition is still
  /// queued (not yet sent, or held back). Failed transitions do NOT count —
  /// the server refused them, so its state is the truth.
  Future<Set<int>> visitsWithPendingTransitions() async {
    final rows = await _db.raw.rawQuery(
      "SELECT DISTINCT visit_id FROM pending_events "
      "WHERE status = 'pending' AND event_type = 'visit.transition'",
    );
    return rows.map((r) => r['visit_id'] as int).toSet();
  }

  /// True when the visit has local work the server has not acknowledged yet —
  /// used to protect it from being wiped by a bootstrap refresh.
  Future<bool> hasUnsyncedWork(int visitId) async {
    final events = await _db.raw.rawQuery(
      "SELECT COUNT(*) AS c FROM pending_events "
      "WHERE visit_id = ? AND status NOT IN ('synced', 'cancelled')",
      [visitId],
    );

    if (((events.first['c'] as int?) ?? 0) > 0) return true;

    // `discarded` is terminal, like `complete`. Counting every non-complete
    // state as unfinished kept a visit protected and its files on disk forever
    // once anything had been dropped.
    final media = await _db.raw.rawQuery(
      "SELECT COUNT(*) AS c FROM pending_media "
      "WHERE visit_id = ? AND state NOT IN ('complete', 'discarded')",
      [visitId],
    );

    return ((media.first['c'] as int?) ?? 0) > 0;
  }

  /// Visits whose close must wait for something that will resolve itself.
  ///
  /// Deliberately EXCLUDES uploads that have given up: the engine no longer
  /// retries a `failed` row, so holding the close for one deadlocks the visit.
  /// Sending it instead gets a refusal naming the missing evidence, which the
  /// technician can act on. Only a definitive server refusal can make an
  /// upload `failed` now — never a lost signal — so a photo taken in a
  /// basement keeps holding its close until it actually lands.
  ///
  /// `discard_pending` DOES hold it. Sending the close before the server has
  /// acknowledged the discard earns a permanent rejection — the file is still
  /// blocking on that side — and the close is then parked as failed with nothing
  /// to re-send it once the discard finally lands.
  Future<Set<int>> visitsWithPendingUploads() async {
    final rows = await _db.raw.rawQuery(
      "SELECT DISTINCT visit_id FROM pending_media "
      "WHERE state IN ('pending', 'uploading', 'discard_pending')",
    );

    return rows.map((r) => r['visit_id'] as int).toSet();
  }
}
