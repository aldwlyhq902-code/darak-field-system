
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
///  - The server's canonical state always wins on sync. This is an optimistic
///    guess for the UI, never a second source of truth.
class VisitProgress {
  VisitProgress(this._db);

  final LocalDb _db;

  /// Mirrors Visit::TRANSITIONS on the server. If one changes, both change.
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

  Future<String?> currentState(int visitId) async {
    final visit = await _db.visit(visitId);
    return visit?['state'] as String?;
  }

  /// @return the states reachable right now, for building the action buttons.
  Future<List<String>> nextStates(int visitId) async {
    final state = await currentState(visitId);
    return state == null ? const [] : (transitions[state] ?? const []);
  }

  /// Applies a transition locally. Returns false if it is not legal from the
  /// current state — the caller should not queue an event in that case.
  Future<bool> applyTransition(int visitId, String target) async {
    final state = await currentState(visitId);

    if (state == null || !allows(state, target)) {
      return false;
    }

    final now = DateTime.now();
    final values = <String, Object?>{'state': target};

    // The on-site counter is advisory on the device; the server recomputes it
    // from the event chain. It exists so the technician sees a live number.
    if (state == 'started') {
      final visit = await _db.visit(visitId);
      final startedAt = DateTime.tryParse((visit?['local_started_at'] as String?) ?? '');

      if (startedAt != null) {
        final elapsed = now.difference(startedAt).inSeconds;
        values['on_site_seconds'] = ((visit?['on_site_seconds'] as int?) ?? 0) + (elapsed > 0 ? elapsed : 0);
      }
    }

    if (target == 'started') {
      values['local_started_at'] = now.toIso8601String();
    }

    await _db.raw.update('visits', values, where: 'id = ?', whereArgs: [visitId]);

    return true;
  }

  /// The server's word, applied after a sync. Overrides any local guess.
  ///
  /// @param canonical entries of {id, state} as returned by /sync/events
  Future<void> adoptCanonical(List<Map<String, dynamic>> canonical) async {
    if (canonical.isEmpty) return;

    final batch = _db.raw.batch();

    for (final entry in canonical) {
      final id = entry['id'];
      final state = entry['state'];

      if (id == null || state == null) continue;

      final values = <String, Object?>{'state': state};

      if (entry['on_site_seconds'] != null) {
        values['on_site_seconds'] = entry['on_site_seconds'];
      }

      batch.update('visits', values, where: 'id = ?', whereArgs: [id]);
    }

    await batch.commit(noResult: true);
  }

  /// True when the visit has local work the server has not acknowledged yet —
  /// used to protect it from being wiped by a bootstrap refresh.
  Future<bool> hasUnsyncedWork(int visitId) async {
    final events = await _db.raw.rawQuery(
      "SELECT COUNT(*) AS c FROM pending_events WHERE visit_id = ? AND status != 'synced'",
      [visitId],
    );

    if (((events.first['c'] as int?) ?? 0) > 0) return true;

    final media = await _db.raw.rawQuery(
      "SELECT COUNT(*) AS c FROM pending_media WHERE visit_id = ? AND state != 'complete'",
      [visitId],
    );

    return ((media.first['c'] as int?) ?? 0) > 0;
  }

  /// Visits whose close must wait: media still making progress.
  ///
  /// Deliberately EXCLUDES uploads that have given up. Holding the close for a
  /// permanently failed upload deadlocks the visit: the engine no longer retries
  /// a 'failed' row, so the close would be withheld forever with no way out.
  /// Sending it instead gets a refusal from the server naming the missing
  /// evidence — a reason the technician can act on beats silence.
  Future<Set<int>> visitsWithPendingUploads() async {
    final rows = await _db.raw.rawQuery(
      "SELECT DISTINCT visit_id FROM pending_media WHERE state IN ('pending', 'uploading')",
    );

    return rows.map((r) => r['visit_id'] as int).toSet();
  }
}
