import 'package:shared_preferences/shared_preferences.dart';

/// Time the server can reason about.
///
/// The wall clock on the handset is editable by the person holding it, so it is
/// reported but never offered as proof. Alongside it we send:
///   - the last server time this device actually observed, and
///   - a monotonic offset measured by a [Stopwatch], which does not move when
///     the user changes the clock.
///
/// The backend recomputes `lastTrustedServerTime + monotonicOffset` and compares
/// it to the wall clock; a large gap means the clock was moved.
///
/// Honesty note: a [Stopwatch] does not survive an app restart. After a cold
/// start we report a null offset rather than inventing one — the server treats
/// "unknown" as unknown instead of trusting a fabricated number.
class TrustedClock {
  TrustedClock._(this._prefs);

  static const _keyServerTime = 'trusted_server_time';

  final SharedPreferences _prefs;
  final Stopwatch _sinceSync = Stopwatch();
  DateTime? _lastTrustedServerTime;

  static Future<TrustedClock> load() async {
    final prefs = await SharedPreferences.getInstance();
    final clock = TrustedClock._(prefs);

    final stored = prefs.getString(_keyServerTime);
    if (stored != null) {
      // Restored from disk, but the stopwatch cannot be restored with it, so the
      // offset stays unavailable until the next successful sync.
      clock._lastTrustedServerTime = DateTime.tryParse(stored);
    }

    return clock;
  }

  /// Called whenever the server tells us its time (login or sync response).
  Future<void> adopt(DateTime serverTime) async {
    _lastTrustedServerTime = serverTime;
    _sinceSync
      ..reset()
      ..start();
    await _prefs.setString(_keyServerTime, serverTime.toIso8601String());
  }

  DateTime? get lastTrustedServerTime => _lastTrustedServerTime;

  /// Null when unknown — never a guess.
  int? get monotonicOffsetMs =>
      _sinceSync.isRunning ? _sinceSync.elapsedMilliseconds : null;

  /// The device wall clock, sent as-is and labelled as such.
  DateTime get deviceNow => DateTime.now();

  /// Best estimate of true time: trusted server time advanced by the monotonic
  /// offset when we have one, otherwise the wall clock.
  DateTime get bestEstimate {
    final base = _lastTrustedServerTime;
    final offset = monotonicOffsetMs;

    if (base != null && offset != null) {
      return base.add(Duration(milliseconds: offset));
    }

    return DateTime.now();
  }

  /// How far the wall clock has drifted from the trusted estimate, if knowable.
  Duration? get suspectedDrift {
    final base = _lastTrustedServerTime;
    final offset = monotonicOffsetMs;

    if (base == null || offset == null) return null;

    return DateTime.now().difference(base.add(Duration(milliseconds: offset)));
  }
}
