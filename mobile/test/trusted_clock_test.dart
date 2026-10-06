import 'package:darak_field/core/device_platform.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Review finding 8. The offset used to come from a Dart Stopwatch: it froze
/// in deep sleep (CLOCK_MONOTONIC) and died with the process.
class _FakeElapsed implements MonotonicSource {
  int elapsedMs = 1000;
  int? bootCount = 3;

  @override
  Future<MonotonicReading> read() async => MonotonicReading(
    elapsedMs: elapsedMs,
    epoch: bootCount == null ? null : 'boot:$bootCount',
  );
}

void main() {
  late SharedPreferences prefs;
  late _FakeElapsed elapsed;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    elapsed = _FakeElapsed();
  });

  final server = DateTime.utc(2026, 10, 6, 8);

  test('the offset counts time the phone spent asleep', () async {
    final clock = await TrustedClock.load(prefs: prefs, source: elapsed);
    await clock.adopt(server);

    // An hour in a pocket: elapsedRealtime keeps counting through it.
    elapsed.elapsedMs += const Duration(hours: 1).inMilliseconds;

    final sample = await clock.sample();
    expect(sample.monotonicOffsetMs, const Duration(hours: 1).inMilliseconds);
  });

  test('the anchor survives process death within the same boot', () async {
    final first = await TrustedClock.load(prefs: prefs, source: elapsed);
    await first.adopt(server);

    // Android kills the app; 10 minutes later it starts again.
    elapsed.elapsedMs += 600000;
    final restarted = await TrustedClock.load(prefs: prefs, source: elapsed);

    expect(restarted.lastTrustedServerTime, server);
    expect((await restarted.sample()).monotonicOffsetMs, 600000);
    final estimate = restarted.bestEstimate.toUtc();
    expect(
      estimate.difference(server.add(const Duration(minutes: 10))).inSeconds,
      0,
    );
  });

  test('a reboot makes the offset unknown again, never invented', () async {
    final first = await TrustedClock.load(prefs: prefs, source: elapsed);
    await first.adopt(server);

    elapsed
      ..bootCount = 4
      ..elapsedMs = 5000;
    final afterReboot = await TrustedClock.load(prefs: prefs, source: elapsed);

    expect(afterReboot.lastTrustedServerTime, server);
    expect((await afterReboot.sample()).monotonicOffsetMs, isNull);
    expect(afterReboot.suspectedDrift, isNull);
  });

  test('without a boot counter nothing is persisted as trustworthy', () async {
    elapsed.bootCount = null;
    final first = await TrustedClock.load(prefs: prefs, source: elapsed);
    await first.adopt(server);
    expect((await first.sample()).monotonicOffsetMs, 0);

    final restarted = await TrustedClock.load(prefs: prefs, source: elapsed);
    expect((await restarted.sample()).monotonicOffsetMs, isNull);
  });

  test('drift compares the wall clock with the trusted estimate', () async {
    final clock = await TrustedClock.load(prefs: prefs, source: elapsed);
    // The server says it is two hours earlier than this phone believes.
    await clock.adopt(
      DateTime.now().toUtc().subtract(const Duration(hours: 2)),
    );

    expect(clock.suspectedDrift!.inMinutes, closeTo(120, 1));
  });

  test('off-device the platform clock falls back without throwing', () async {
    final source = PlatformMonotonicSource();
    final a = await source.read();
    await Future<void>.delayed(const Duration(milliseconds: 20));
    final b = await source.read();

    expect(b.elapsedMs, greaterThanOrEqualTo(a.elapsedMs + 15));
    expect(a.epoch, b.epoch);
  });

  test('FLAG_SECURE defaults on in release and can be turned off', () {
    expect(resolveSecureScreen(configured: '', releaseMode: true), isTrue);
    expect(resolveSecureScreen(configured: '', releaseMode: false), isFalse);
    expect(
      resolveSecureScreen(configured: 'false', releaseMode: true),
      isFalse,
    );
    expect(resolveSecureScreen(configured: 'true', releaseMode: false), isTrue);
  });
}
