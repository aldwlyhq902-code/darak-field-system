import 'package:darak_field/core/business_time.dart';
import 'package:darak_field/screens/today_screen.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'support/phone_app.dart';

/// 0.3.0 regression: the app formatted the server's `+03:00` timestamps after
/// `DateTime.parse` had turned them into UTC, so a 07:30 visit showed 04:30.
/// Everything below goes through `toUtc()`, so the results do not depend on
/// the zone of the machine running the tests — the same property that keeps
/// a phone set to the wrong zone showing the site's time.
void main() {
  setUpAll(() async {
    await initializeDateFormatting('ar');
    await initializeDateFormatting('en');
  });

  group('BusinessTime', () {
    test('a Riyadh timestamp shows its own wall-clock time', () {
      final start = BusinessTime.parse('2026-10-07T07:30:00+03:00')!;
      expect(start.isUtc, isTrue, reason: 'what Dart actually returns');
      expect(start.hour, 4, reason: 'the bug: formatting this prints 04:30');
      expect(BusinessTime.time(start), '07:30');
      expect(BusinessTime.dateTime(start), '2026-10-07 07:30');
    });

    test('UTC and other offsets are converted to Riyadh time', () {
      for (final iso in [
        '2026-10-07T04:30:00Z',
        '2026-10-07T04:30:00.000000Z', // Laravel's default JSON date format
        '2026-10-07T06:30:00+02:00',
        '2026-10-06T23:30:00-05:00',
      ]) {
        expect(
          BusinessTime.time(BusinessTime.parse(iso)!),
          '07:30',
          reason: iso,
        );
      }
    });

    test('an exact instant shows the same time whatever the device zone', () {
      final instant = DateTime.utc(2026, 10, 7, 10, 40);
      expect(BusinessTime.time(instant), '13:40');
      expect(BusinessTime.time(instant.toLocal()), '13:40');
    });

    test('a device-local timestamp (no offset) is converted from local', () {
      final local = DateTime(2026, 10, 7, 9, 15);
      final parsed = BusinessTime.parse(local.toIso8601String())!;
      expect(
        BusinessTime.time(parsed),
        BusinessTime.time(local.toUtc()),
        reason: 'last_synced_at is stored device-local',
      );
    });

    test('the date follows Riyadh, not UTC, around midnight', () {
      // 00:30 on the 8th in Riyadh is still the 7th in UTC.
      final late = BusinessTime.parse('2026-10-07T21:30:00Z')!;
      expect(BusinessTime.dateTime(late), '2026-10-08 00:30');

      final now = BusinessTime.parse('2026-10-07T13:40:00+03:00')!;
      expect(BusinessTime.dayOffset(late, now), 1);
      expect(BusinessTime.dayLabel(late, now), 'غداً');
      expect(
        BusinessTime.dayLabel(
          BusinessTime.parse('2026-10-07T23:59:00+03:00')!,
          now,
        ),
        isNull,
      );
      expect(
        BusinessTime.dayLabel(
          BusinessTime.parse('2026-10-06T08:00:00+03:00')!,
          now,
        ),
        'أمس',
      );
      expect(
        BusinessTime.dayLabel(
          BusinessTime.parse('2026-10-10T08:00:00+03:00')!,
          now,
          'en',
        ),
        'Sat 10/10',
      );
    });

    test('missing or malformed input yields null, not a crash', () {
      expect(BusinessTime.parse(null), isNull);
      expect(BusinessTime.parse(''), isNull);
      expect(BusinessTime.parse('not a date'), isNull);
    });
  });

  testWidgets('today list shows the visit at its Riyadh time and labels '
      'tomorrow', (tester) async {
    usePhoneViewport(tester);
    final today = BusinessTime.wallClock(DateTime.now());
    final tomorrow = today.add(const Duration(days: 1));
    String day(DateTime d) =>
        '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

    final app = (await tester.runAsync(
      () => FakeApp.create(
        visits: [
          serverVisit(id: 7, scheduledStart: '${day(today)}T07:30:00+03:00'),
          serverVisit(
            id: 8,
            state: 'scheduled',
            scheduledStart: '${day(tomorrow)}T08:15:00+03:00',
          ),
        ],
      ),
    ))!;
    addTearDown(() => tester.runAsync(app.dispose));

    await tester.pumpWidget(phoneApp(TodayScreen(state: app.state)));
    await settleIo(tester);

    expect(tester.takeException(), isNull);
    expect(find.text('07:30'), findsOneWidget);
    expect(find.text('04:30'), findsNothing);
    expect(find.text('08:15'), findsOneWidget);
    expect(
      find.text('غداً'),
      findsOneWidget,
      reason: 'only tomorrow is labelled',
    );
  });
}
