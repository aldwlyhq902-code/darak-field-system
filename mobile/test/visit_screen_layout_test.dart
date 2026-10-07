import 'package:darak_field/screens/signature_screen.dart';
import 'package:darak_field/screens/tile_button.dart';
import 'package:darak_field/screens/visit_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/phone_app.dart';

/// 0.3.0 regression: the signature tile's trailing button inherited the
/// theme's `Size.fromHeight(56)` (an infinite minimum width), consumed the
/// whole ListTile, and the visit page failed layout — an error screen in
/// debug, a broken tile in release.
void main() {
  for (final signed in [false, true]) {
    testWidgets(
      'visit screen lays out at phone size (signature ${signed ? 'captured' : 'missing'})',
      (tester) async {
        usePhoneViewport(tester);
        final app = (await tester.runAsync(
          () => FakeApp.create(visits: [serverVisit()]),
        ))!;
        addTearDown(() => tester.runAsync(app.dispose));
        if (signed) await tester.runAsync(() => app.addSignature(7));

        await tester.pumpWidget(
          phoneApp(VisitScreen(state: app.state, visitId: 7)),
        );
        await settleIo(tester);

        expect(tester.takeException(), isNull);
        expect(find.text('صيانة وقائية شهرية للمكيفات'), findsOneWidget);

        await _revealSignatureTile(tester);
        expect(tester.takeException(), isNull);
        expect(find.text('توقيع مسؤول الموقع'), findsOneWidget);

        final button = find.byType(TileButton);
        expect(button, findsOneWidget);
        expect(find.text(signed ? 'إعادة' : 'توقيع'), findsOneWidget);

        // The button sits beside the title, inside the screen, and leaves the
        // tile most of its width.
        final rect = tester.getRect(button);
        expect(rect.width, lessThan(phoneSize.width / 2));
        expect(rect.left, greaterThanOrEqualTo(0));
        expect(rect.right, lessThanOrEqualTo(phoneSize.width));
        expect(rect.height, greaterThanOrEqualTo(48));
        expect(button.hitTestable(), findsOneWidget);
      },
    );
  }

  testWidgets('the signature button opens the signature screen', (
    tester,
  ) async {
    usePhoneViewport(tester);
    final app = (await tester.runAsync(
      () => FakeApp.create(visits: [serverVisit()]),
    ))!;
    addTearDown(() => tester.runAsync(app.dispose));

    await tester.pumpWidget(
      phoneApp(VisitScreen(state: app.state, visitId: 7)),
    );
    await settleIo(tester);
    await _revealSignatureTile(tester);
    await tester.tap(find.byType(TileButton));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(SignatureScreen), findsOneWidget);
  });
}

Future<void> _revealSignatureTile(WidgetTester tester) async {
  await tester.scrollUntilVisible(
    find.byType(TileButton),
    200,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.ensureVisible(find.byType(TileButton));
  await tester.pumpAndSettle();
}
