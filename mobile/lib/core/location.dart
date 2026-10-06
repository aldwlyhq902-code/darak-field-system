import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

/// One position, with what the server needs to judge it (contract C3).
class LocationFix {
  const LocationFix({
    required this.lat,
    required this.lng,
    this.accuracyM,
    this.isMocked = false,
    this.timestamp,
  });

  final double lat;
  final double lng;
  final double? accuracyM;

  /// `Position.isMocked`: a fake-GPS app is feeding the position. Recorded and
  /// shown to the office — never used to block the technician.
  final bool isMocked;
  final DateTime? timestamp;

  factory LocationFix.fromPosition(Position position) => LocationFix(
    lat: position.latitude,
    lng: position.longitude,
    accuracyM: position.accuracy.isFinite && position.accuracy > 0
        ? position.accuracy
        : null,
    isMocked: position.isMocked,
    timestamp: position.timestamp,
  );
}

/// Where the device is, without ever making the technician wait for it.
///
/// A basement kitchen often has no fix, and refusing to record work because the
/// GPS is cold would block real work to satisfy a metadata field. So:
///  - [quick] never waits on the radio: a recent fix or nothing;
///  - [fresh] waits at most a few seconds and is used only at the moments that
///    matter (start and close), where a geofence is checked.
abstract class LocationSource {
  Future<LocationFix?> quick();

  Future<LocationFix?> fresh({Duration timeout = const Duration(seconds: 5)});
}

class NoLocationSource implements LocationSource {
  const NoLocationSource();

  @override
  Future<LocationFix?> quick() async => null;

  @override
  Future<LocationFix?> fresh({
    Duration timeout = const Duration(seconds: 5),
  }) async => null;
}

class GeolocatorLocationSource implements LocationSource {
  GeolocatorLocationSource({this.maxAge = const Duration(minutes: 2)});

  /// A position older than this says little about where the work happened.
  final Duration maxAge;

  LocationFix? _last;
  bool _refreshing = false;

  bool _isRecent(LocationFix? fix) {
    final at = fix?.timestamp;
    return at != null && DateTime.now().difference(at).abs() <= maxAge;
  }

  @override
  Future<LocationFix?> quick() async {
    if (_isRecent(_last)) return _last;

    try {
      if (!await _permitted(request: false)) return null;
      final known = await Geolocator.getLastKnownPosition().timeout(
        const Duration(milliseconds: 800),
      );
      if (known != null) {
        final fix = LocationFix.fromPosition(known);
        if (_isRecent(fix)) return _last = fix;
      }
    } catch (error) {
      debugPrint('Last known position unavailable: $error');
    }

    // Warm up for the next action without holding this one.
    unawaited(_refreshInBackground());
    return null;
  }

  @override
  Future<LocationFix?> fresh({
    Duration timeout = const Duration(seconds: 5),
  }) async {
    try {
      if (!await _permitted(request: true)) return null;
      final position = await Geolocator.getCurrentPosition(
        locationSettings: LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: timeout,
        ),
      ).timeout(timeout + const Duration(milliseconds: 500));
      return _last = LocationFix.fromPosition(position);
    } catch (error) {
      // No fix is a normal field condition, not an error worth interrupting
      // work. Fall back to whatever recent position we already have.
      debugPrint('Fresh position unavailable: $error');
      return _isRecent(_last) ? _last : null;
    }
  }

  Future<void> _refreshInBackground() async {
    if (_refreshing) return;
    _refreshing = true;
    try {
      await fresh(timeout: const Duration(seconds: 10));
    } finally {
      _refreshing = false;
    }
  }

  Future<bool> _permitted({required bool request}) async {
    if (!await Geolocator.isLocationServiceEnabled()) return false;

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied && request) {
      permission = await Geolocator.requestPermission();
    }

    return permission == LocationPermission.always ||
        permission == LocationPermission.whileInUse;
  }
}
