import 'dart:convert';

import 'package:darak_field/screens/field_tools_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'support/fake_api.dart';
import 'support/phone_app.dart';

/// 0.3.0 regression: the custody list printed the raw status code
/// ("CUS-2026-0007 · issued") inside the Arabic UI.
void main() {
  FakeApi server() =>
      FakeApi()
        ..onGet = (request) => request.path == '/api/v1/custodies'
            ? http.Response(
                jsonEncode({
                  'data': [
                    {
                      'id': 1,
                      'item_name': 'جهاز قياس ضغط',
                      'custody_no': 'CUS-2026-0007',
                      'status': 'issued',
                    },
                    {
                      'id': 2,
                      'item_name': 'سلم ألمنيوم',
                      'custody_no': 'CUS-2026-0003',
                      'status': 'accepted',
                    },
                  ],
                }),
                200,
                headers: {'content-type': 'application/json; charset=utf-8'},
              )
            : http.Response(jsonEncode({'data': []}), 200);

  for (final (locale, issued, accepted) in [
    (const Locale('ar'), 'بانتظار الاستلام', 'مستلمة'),
    (const Locale('en'), 'Awaiting receipt', 'Received'),
  ]) {
    testWidgets(
      'custody status is a label, not a code (${locale.languageCode})',
      (tester) async {
        usePhoneViewport(tester);
        final app = (await tester.runAsync(
          () => FakeApp.create(server: server()),
        ))!;
        addTearDown(() => tester.runAsync(app.dispose));

        await tester.pumpWidget(
          phoneApp(FieldToolsScreen(state: app.state), locale: locale),
        );
        await settleIo(tester);

        // Built tiles, on screen or just below it.
        Finder text(String value) => find.text(value, skipOffstage: false);
        expect(text('CUS-2026-0007 · $issued'), findsOneWidget);
        expect(text('CUS-2026-0003 · $accepted'), findsOneWidget);
        expect(
          find.textContaining(RegExp('issued|accepted'), skipOffstage: false),
          findsNothing,
        );
      },
    );
  }

  test('unknown custody states never surface as codes', () {
    expect(custodyStatusLabel('returned'), 'مرجعة');
    expect(custodyStatusLabel('something_new'), '—');
    expect(custodyStatusLabel(null), '—');
  });
}
