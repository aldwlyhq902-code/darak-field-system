import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// The few things only the Android side can answer. Implemented in
/// `MainActivity.kt` on the `com.darak.darak_field/device` channel.
///
/// Every call degrades to null/no-op off-device (unit tests, desktop) or when
/// the channel is missing, so nothing in the app depends on it to function.
class DevicePlatform {
  const DevicePlatform();

  static const MethodChannel _channel = MethodChannel(
    'com.darak.darak_field/device',
  );

  /// `SystemClock.elapsedRealtime()` (keeps counting in deep sleep, unlike a
  /// Dart [Stopwatch]) and `Settings.Global.BOOT_COUNT`.
  Future<({int elapsedRealtimeMs, int? bootCount})?> clock() async {
    try {
      final result = await _channel.invokeMapMethod<String, Object?>('clock');
      final elapsed = (result?['elapsedRealtimeMs'] as num?)?.toInt();
      if (elapsed == null) return null;
      final boot = (result?['bootCount'] as num?)?.toInt();
      return (
        elapsedRealtimeMs: elapsed,
        bootCount: boot == null || boot < 0 ? null : boot,
      );
    } catch (_) {
      return null;
    }
  }

  /// Root and emulator heuristics (contract C4). Advisory only — never used to
  /// block anyone; a determined attacker defeats them, an honest one is told.
  Future<Map<String, bool>?> integrity() async {
    try {
      final result = await _channel.invokeMapMethod<String, Object?>(
        'integrity',
      );
      if (result == null) return null;
      return {
        'rooted': result['rooted'] == true,
        'emulator': result['emulator'] == true,
      };
    } catch (_) {
      return null;
    }
  }

  /// FLAG_SECURE: no screenshots, no recents thumbnail of customer data.
  Future<void> setSecureScreen(bool enabled) async {
    try {
      await _channel.invokeMethod<void>('setSecure', {'enabled': enabled});
    } catch (error) {
      debugPrint('FLAG_SECURE unavailable: $error');
    }
  }
}

/// `--dart-define=DARAK_SECURE_SCREEN=false` turns FLAG_SECURE off (for store
/// screenshots); anything else forces it on. Unset: on in release, off in
/// debug so developers can still capture the screen.
bool resolveSecureScreen({
  String configured = const String.fromEnvironment('DARAK_SECURE_SCREEN'),
  bool releaseMode = kReleaseMode,
}) {
  final value = configured.trim().toLowerCase();
  if (value.isEmpty) return releaseMode;
  return !(value == 'false' || value == '0' || value == 'off');
}
