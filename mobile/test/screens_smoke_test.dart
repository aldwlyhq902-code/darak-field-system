import 'dart:convert';

import 'package:darak_field/screens/additional_work_screen.dart';
import 'package:darak_field/screens/diagnosis_screen.dart';
import 'package:darak_field/screens/field_tools_screen.dart';
import 'package:darak_field/screens/login_screen.dart';
import 'package:darak_field/screens/signature_screen.dart';
import 'package:darak_field/screens/sync_screen.dart';
import 'package:darak_field/screens/today_screen.dart';
import 'package:darak_field/screens/vehicle_inspection_screen.dart';
import 'package:darak_field/screens/visit_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:intl/date_symbol_data_local.dart';

import 'support/fake_api.dart';
import 'support/phone_app.dart';

/// Every screen of the technician app, rendered at phone size with realistic
/// (long, Arabic) data, in both languages, scrolled to the end. A layout
/// error anywhere — an overflow, a button that swallows its row — fails here
/// instead of on a technician's phone. The QR scanner is left out: it is a
/// camera platform view with nothing to lay out in a test.
void main() {
  setUpAll(() async {
    await initializeDateFormatting('ar');
    await initializeDateFormatting('en');
  });

  http.Response data(Object value) => http.Response(
    jsonEncode({'data': value}),
    200,
    headers: {'content-type': 'application/json; charset=utf-8'},
  );

  FakeApi fieldServer() =>
      FakeApi()
        ..onGet = (request) => switch (request.path) {
          '/api/v1/custodies' => data([
            {
              'id': 1,
              'item_name': 'جهاز قياس ضغط الفريون الرقمي مع خراطيم',
              'custody_no': 'CUS-2026-0007',
              'status': 'issued',
            },
            {
              'id': 2,
              'item_name': 'سلم ألمنيوم',
              'custody_no': 'CUS-2026-0003',
              'status': 'accepted',
            },
          ]),
          '/api/v1/inventory/stocktakes' => data([
            {
              'id': 3,
              'reference': 'ST-2026-0002',
              'location': {'name': 'مخزون السيارة 3'},
            },
          ]),
          '/api/v1/inventory/vehicle-transfer-releases' => data([
            {
              'id': 4,
              'transfer_no': 'VT-2026-0011',
              'qty': 2,
              'part': {'name': 'مكثف تشغيل 35 ميكروفاراد'},
            },
          ]),
          '/api/v1/inventory/vehicle-transfers' => data([
            {
              'id': 5,
              'transfer_no': 'VT-2026-0012',
              'qty': 1,
              'part': {'name': 'فلتر مجفف خط السائل'},
            },
          ]),
          '/api/v1/fleet/vehicle' => data({
            'id': 9,
            'plate': 'ر ص د 4821',
            'make': 'تويوتا',
            'model': 'هايلكس',
            'current_odometer_km': 48210,
          }),
          _ => null,
        };

  final visits = [
    serverVisit(id: 7, state: 'started'),
    serverVisit(
      id: 8,
      state: 'scheduled',
      scheduledStart: '2026-10-07T13:00:00+03:00',
      slaStatus: 'amber',
    ),
    serverVisit(
      id: 9,
      state: 'awaiting_close',
      scheduledStart: '2026-10-07T16:30:00+03:00',
      slaStatus: 'red',
    ),
    serverVisit(
      id: 10,
      state: 'completed',
      scheduledStart: '2026-10-08T08:00:00+03:00',
    ),
  ];

  final screens = <String, Widget Function(FakeApp app)>{
    'login': (app) => LoginScreen(
      state: app.state,
      notice: 'انتهت الجلسة. سجّل الدخول مرة أخرى — العمل غير المرسل محفوظ.',
    ),
    'today': (app) => TodayScreen(state: app.state),
    'visit (scheduled)': (app) => VisitScreen(state: app.state, visitId: 8),
    'visit (started)': (app) => VisitScreen(state: app.state, visitId: 7),
    'visit (awaiting close)': (app) =>
        VisitScreen(state: app.state, visitId: 9),
    'visit (completed)': (app) => VisitScreen(state: app.state, visitId: 10),
    'sync': (app) => SyncScreen(state: app.state),
    'field tools': (app) => FieldToolsScreen(state: app.state),
    'vehicle inspection': (app) => VehicleInspectionScreen(state: app.state),
    'diagnosis': (app) => DiagnosisScreen(state: app.state, visitId: 7),
    'additional work': (app) =>
        AdditionalWorkScreen(state: app.state, visitId: 7),
    'signature': (app) => const SignatureScreen(),
  };

  for (final locale in const [Locale('ar'), Locale('en')]) {
    for (final entry in screens.entries) {
      testWidgets(
        '${entry.key} renders at phone size (${locale.languageCode})',
        (tester) async {
          usePhoneViewport(tester);
          final app = (await tester.runAsync(
            () => FakeApp.create(visits: visits, server: fieldServer()),
          ))!;
          addTearDown(() => tester.runAsync(app.dispose));
          app.state
            ..locale = locale
            ..lastSyncedAt = DateTime(2026, 10, 7, 13, 40);

          await tester.pumpWidget(phoneApp(entry.value(app), locale: locale));
          await settleIo(tester);
          expect(tester.takeException(), isNull);
          expect(find.byType(Scaffold), findsWidgets);

          // Lists build lazily: scroll to the end so every tile is laid out.
          final scrollable = find.byType(Scrollable);
          if (scrollable.evaluate().isNotEmpty) {
            for (var i = 0; i < 12; i++) {
              await tester.drag(
                scrollable.first,
                const Offset(0, -400),
                warnIfMissed: false,
              );
              await tester.pump();
              await settleIo(tester, rounds: 2);
              expect(tester.takeException(), isNull);
            }
          }
        },
      );
    }
  }
}
