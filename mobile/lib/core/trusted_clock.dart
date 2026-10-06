import 'dart:math';

import 'package:shared_preferences/shared_preferences.dart';

import 'device_platform.dart';

/// Time the server can reason about.
///
/// The wall clock on the handset is editable by the person holding it, so it is
/// reported but never offered as proof. Alongside it we send:
///   - the last server time this device actually observed, and
///   - the time elapsed since then on a clock the user cannot set.
///
/// The backend recomputes `lastTrustedServerTime + monotonicOffset` and compares
/// it to the wall clock; a large gap means the clock was moved.
///
/// Review found the "monotonic" part was a Dart [Stopwatch], which on Android
/// reads CLOCK_MONOTONIC — it STOPS in deep sleep, so a phone that dozed in a
/// pocket for an hour under-reported the offset by an hour. It also died with
/// the process, so after Android killed the app the offset was null until the
/// next sync. The source is now `SystemClock.elapsedRealtime()` (counts through
/// sleep) with the boot counter, and the anchor (serverTime, elapsedRealtime,
/// boot) is persisted: within the same boot it survives process death; after a
/// reboot elapsedRealtime restarts, the boot counter changes, and the offset is
/// honestly reported as unknown again.
class TrustedClock {
  TrustedClock._(this._prefs, this._source);

  static const _keyServerTime = 'trusted_server_time';
  static const _keyAnchorElapsed = 'trusted_anchor_elapsed_ms';
  static const _keyAnchorEpoch = 'trusted_anchor_epoch';

  /// Preference keys a device wipe must remove.
  static const storageKeys = [
    _keyServerTime,
    _keyAnchorElapsed,
    _keyAnchorEpoch,
  ];

  final SharedPreferences _prefs;
  final MonotonicSource _source;

  DateTime? _lastTrustedServerTime;
  int? _anchorElapsedMs;

  // The newest reading, plus a stopwatch to extrapolate between readings for
  // the synchronous getters. Refreshed on every event and on app resume, so a
  // deep sleep is corrected by the next reading rather than accumulated.
  MonotonicReading? _lastReading;
  final Stopwatch _sinceReading = Stopwatch();

  static Future<TrustedClock> load({
    SharedPreferences? prefs,
    MonotonicSource? source,
  }) async {
    final clock = TrustedClock._(
      prefs ?? await SharedPreferences.getInstance(),
      source ?? PlatformMonotonicSource(),
    );

    final stored = clock._prefs.getString(_keyServerTime);
    if (stored != null) {
      clock._lastTrustedServerTime = DateTime.tryParse(stored);
    }

    final reading = await clock.refresh();
    final anchorElapsed = clock._prefs.getInt(_keyAnchorElapsed);
    final anchorEpoch = clock._prefs.getString(_keyAnchorEpoch);

    // Only an anchor taken on the same monotonic timeline is meaningful. A
    // different boot (or, off-device, a different process) means the elapsed
    // counter restarted and the old anchor would yield a fabricated number.
    if (clock._lastTrustedServerTime != null &&
        anchorElapsed != null &&
        anchorEpoch != null &&
        anchorEpoch == reading.epoch &&
        reading.elapsedMs >= anchorElapsed) {
      clock._anchorElapsedMs = anchorElapsed;
    }

    return clock;
  }

  /// Takes a fresh reading from the platform clock.
  Future<MonotonicReading> refresh() async {
    final reading = await _source.read();
    _lastReading = reading;
    _sinceReading
      ..reset()
      ..start();
    return reading;
  }

  /// Called whenever the server tells us its time (login or sync response).
  Future<void> adopt(DateTime serverTime) async {
    final reading = await refresh();
    _lastTrustedServerTime = serverTime;
    _anchorElapsedMs = reading.elapsedMs;

    await _prefs.setString(_keyServerTime, serverTime.toIso8601String());
    final epoch = reading.epoch;
    if (epoch == null) {
      await _prefs.remove(_keyAnchorElapsed);
      await _prefs.remove(_keyAnchorEpoch);
    } else {
      await _prefs.setInt(_keyAnchorElapsed, reading.elapsedMs);
      await _prefs.setString(_keyAnchorEpoch, epoch);
    }
  }

  /// Everything an event needs, from a reading taken now.
  Future<ClockSample> sample() async {
    final reading = await refresh();
    return ClockSample(
      deviceTime: DateTime.now(),
      monotonicOffsetMs: _offsetAt(reading.elapsedMs),
      lastTrustedServerTime: _lastTrustedServerTime,
    );
  }

  DateTime? get lastTrustedServerTime => _lastTrustedServerTime;

  /// Null when unknown — never a guess.
  int? get monotonicOffsetMs {
    final reading = _lastReading;
    if (reading == null) return null;
    return _offsetAt(reading.elapsedMs + _sinceReading.elapsedMilliseconds);
  }

  int? _offsetAt(int elapsedMs) {
    final anchor = _anchorElapsedMs;
    if (anchor == null || _lastTrustedServerTime == null) return null;
    final offset = elapsedMs - anchor;
    return offset >= 0 ? offset : null;
  }

  /// The device wall clock, sent as-is and labelled as such.
  DateTime get deviceNow => DateTime.now();

  /// Best estimate of true time: trusted server time advanced by the monotonic
  /// offset when we have one, otherwise the wall clock. Used for the on-site
  /// timer so moving the phone's clock cannot inflate billable minutes.
  DateTime get bestEstimate {
    final base = _lastTrustedServerTime;
    final offset = monotonicOffsetMs;

    if (base != null && offset != null) {
      return base.add(Duration(milliseconds: offset)).toLocal();
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

class ClockSample {
  const ClockSample({
    required this.deviceTime,
    required this.monotonicOffsetMs,
    required this.lastTrustedServerTime,
  });

  final DateTime deviceTime;
  final int? monotonicOffsetMs;
  final DateTime? lastTrustedServerTime;
}

class MonotonicReading {
  const MonotonicReading({required this.elapsedMs, this.epoch});

  /// Milliseconds on a clock that never goes backwards and is not user-settable.
  final int elapsedMs;

  /// Identifies the timeline [elapsedMs] belongs to (one boot, or one process
  /// off-device). Null when it cannot be identified, in which case nothing is
  /// persisted.
  final String? epoch;
}

abstract class MonotonicSource {
  Future<MonotonicReading> read();
}

/// Android's elapsedRealtime + boot count, falling back to a per-process
/// stopwatch where the platform channel does not exist (tests, desktop).
class PlatformMonotonicSource implements MonotonicSource {
  PlatformMonotonicSource([this._platform = const DevicePlatform()]);

  final DevicePlatform _platform;
  bool _unavailable = false;

  @override
  Future<MonotonicReading> read() async {
    if (!_unavailable) {
      final clock = await _platform.clock();
      if (clock != null) {
        return MonotonicReading(
          elapsedMs: clock.elapsedRealtimeMs,
          epoch: clock.bootCount == null ? null : 'boot:${clock.bootCount}',
        );
      }
      _unavailable = true;
    }
    return ProcessMonotonicSource.instance.read();
  }
}

/// Valid only for the life of this process — which is all a Dart [Stopwatch]
/// can promise.
class ProcessMonotonicSource implements MonotonicSource {
  ProcessMonotonicSource._();

  static final instance = ProcessMonotonicSource._();
  static final Stopwatch _sinceStart = Stopwatch()..start();
  static final String _epoch =
      'process:${Random().nextInt(1 << 32)}-${DateTime.now().microsecondsSinceEpoch}';

  @override
  Future<MonotonicReading> read() async => MonotonicReading(
    elapsedMs: _sinceStart.elapsedMilliseconds,
    epoch: _epoch,
  );
}
