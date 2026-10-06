import 'dart:math';

import 'location.dart';

enum GeofenceStatus {
  /// Within the site's radius (plus the fix's own uncertainty).
  inside,

  /// Clearly away from the site: the technician must say why.
  outside,

  /// No site coordinates, no radius, or no fix. Work continues — a cold GPS
  /// in a basement is a field condition, not misconduct.
  unknown,
}

/// The lite geofence check made at "start" and at close (no background
/// tracking). Contract C3: a reason is required only when the distance exceeds
/// `radius + min(accuracy, 100)` — a 60 m fix must not turn an honest
/// technician standing at the door into an "outside the site" case, and an
/// absurd 2 km "accuracy" must not excuse being across town.
class GeofenceCheck {
  const GeofenceCheck({
    required this.status,
    this.fix,
    this.distanceM,
    this.allowedM,
  });

  final GeofenceStatus status;
  final LocationFix? fix;
  final double? distanceM;
  final double? allowedM;

  bool get needsReason => status == GeofenceStatus.outside;

  /// A fake-GPS app is active. Allowed, flagged on the event, and the
  /// technician is told gently that the office will see it.
  bool get isMocked => fix?.isMocked ?? false;

  /// Accuracy assumed when the platform reports none.
  static const double maxAccuracyAllowanceM = 100;

  static GeofenceCheck evaluate({
    required double? siteLat,
    required double? siteLng,
    required num? radiusM,
    required LocationFix? fix,
  }) {
    if (siteLat == null ||
        siteLng == null ||
        radiusM == null ||
        radiusM <= 0 ||
        fix == null) {
      return GeofenceCheck(status: GeofenceStatus.unknown, fix: fix);
    }

    final distance = distanceMeters(siteLat, siteLng, fix.lat, fix.lng);
    final allowance = min(
      fix.accuracyM ?? maxAccuracyAllowanceM,
      maxAccuracyAllowanceM,
    );
    final allowed = radiusM.toDouble() + allowance;

    return GeofenceCheck(
      status: distance > allowed
          ? GeofenceStatus.outside
          : GeofenceStatus.inside,
      fix: fix,
      distanceM: distance,
      allowedM: allowed,
    );
  }

  /// Great-circle distance (haversine). Plenty precise at site scale.
  static double distanceMeters(
    double lat1,
    double lng1,
    double lat2,
    double lng2,
  ) {
    const earthRadius = 6371000.0;
    double rad(double degrees) => degrees * pi / 180;

    final dLat = rad(lat2 - lat1);
    final dLng = rad(lng2 - lng1);
    final a =
        sin(dLat / 2) * sin(dLat / 2) +
        cos(rad(lat1)) * cos(rad(lat2)) * sin(dLng / 2) * sin(dLng / 2);
    return 2 * earthRadius * asin(min(1.0, sqrt(a)));
  }

  /// Transitions at which the geofence is checked.
  static bool appliesTo(String target) =>
      target == 'started' ||
      target == 'awaiting_close' ||
      target == 'completed';
}
