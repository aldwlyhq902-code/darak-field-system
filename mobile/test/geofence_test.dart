import 'package:darak_field/core/geofence.dart';
import 'package:darak_field/core/location.dart';
import 'package:flutter_test/flutter_test.dart';

/// Review finding 9: the lite geofence at start and close.
void main() {
  // A site in Jeddah and points due north of it (1e-3 deg lat ≈ 111 m).
  const siteLat = 21.5;
  const siteLng = 39.2;

  LocationFix at(double metersNorth, {double? accuracy, bool mocked = false}) =>
      LocationFix(
        lat: siteLat + metersNorth / 111195,
        lng: siteLng,
        accuracyM: accuracy,
        isMocked: mocked,
      );

  GeofenceCheck check(LocationFix? fix, {num? radius = 100}) =>
      GeofenceCheck.evaluate(
        siteLat: siteLat,
        siteLng: siteLng,
        radiusM: radius,
        fix: fix,
      );

  test('inside the radius needs no reason', () {
    final result = check(at(80, accuracy: 10));
    expect(result.status, GeofenceStatus.inside);
    expect(result.distanceM, closeTo(80, 1));
  });

  test('the fix accuracy widens the circle, up to 100 m', () {
    expect(check(at(150, accuracy: 60)).status, GeofenceStatus.inside);
    expect(check(at(150, accuracy: 30)).status, GeofenceStatus.outside);
    // A 2 km "accuracy" cannot excuse being 500 m away.
    final far = check(at(500, accuracy: 2000));
    expect(far.status, GeofenceStatus.outside);
    expect(far.allowedM, 200);
  });

  test('outside the circle asks for a reason', () {
    final result = check(at(900, accuracy: 15));
    expect(result.needsReason, isTrue);
    expect(result.distanceM, closeTo(900, 5));
  });

  test('no coordinates, no radius or no fix never blocks the work', () {
    expect(check(null).status, GeofenceStatus.unknown);
    expect(check(at(900), radius: null).status, GeofenceStatus.unknown);
    expect(
      GeofenceCheck.evaluate(
        siteLat: null,
        siteLng: null,
        radiusM: 100,
        fix: at(900),
      ).needsReason,
      isFalse,
    );
  });

  test('a mocked position is allowed and flagged', () {
    final result = check(at(10, accuracy: 5, mocked: true));
    expect(result.status, GeofenceStatus.inside);
    expect(result.isMocked, isTrue);
  });

  test('checked at start and at close only', () {
    expect(GeofenceCheck.appliesTo('started'), isTrue);
    expect(GeofenceCheck.appliesTo('awaiting_close'), isTrue);
    expect(GeofenceCheck.appliesTo('completed'), isTrue);
    expect(GeofenceCheck.appliesTo('en_route'), isFalse);
    expect(GeofenceCheck.appliesTo('paused'), isFalse);
  });
}
