import 'dart:math';

/// When a queued item may be tried again after a failure the SERVER caused
/// (5xx, 429, a temporary refusal).
///
/// Exponential with "equal jitter": half the delay is fixed, the other half is
/// random. A fleet of phones that lost the same server at the same moment would
/// otherwise come back in lock-step and knock it over again.
///
/// Connectivity failures do NOT use this. A technician walking out of a
/// basement must sync the moment the radio returns, not ten minutes later
/// because the basement made the backoff grow.
class Backoff {
  const Backoff({
    this.base = const Duration(seconds: 15),
    this.cap = const Duration(minutes: 15),
  });

  final Duration base;
  final Duration cap;

  /// [failures] is the number of consecutive failures so far, starting at 1.
  Duration delay(int failures, {Random? random}) {
    final exponent = max(0, min(failures - 1, 16));
    final raw = base.inMilliseconds * (1 << exponent);
    final capped = min(raw, cap.inMilliseconds);
    final half = capped ~/ 2;
    final jitter = (random ?? _random).nextInt(half + 1);
    return Duration(milliseconds: half + jitter);
  }

  DateTime nextAttempt(int failures, {DateTime? now, Random? random}) =>
      (now ?? DateTime.now()).toUtc().add(delay(failures, random: random));

  static final Random _random = Random();
}

/// Stored as UTC ISO-8601, which sorts lexically, so SQLite can compare it.
String? encodeAttemptTime(DateTime? value) => value?.toUtc().toIso8601String();

String nowForEligibility([DateTime? now]) =>
    (now ?? DateTime.now()).toUtc().toIso8601String();
