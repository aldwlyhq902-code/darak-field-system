import 'package:darak_field/l10n/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('English translates fixed and interpolated field messages', () {
    const localizations = AppLocalizations(Locale('en'));

    expect(localizations.translate('زيارات اليوم'), 'Today’s visits');
    expect(localizations.translate('آخر مزامنة 10:45'), 'Last sync 10:45');
    expect(
      localizations.translate('حالة الأصل: وحدة التكييف'),
      startsWith('Asset status:'),
    );
    expect(
      localizations.translate('الكمية الفعلية · SKU-زيارات اليوم'),
      'Actual quantity · SKU-زيارات اليوم',
      reason: 'template values must never be translated as UI copy',
    );
    expect(
      localizations.translate('نص جديد فيه زيارات اليوم'),
      'نص جديد فيه زيارات اليوم',
      reason: 'unknown copy must not become a broken mixed-language sentence',
    );
  });

  test('Arabic keeps Arabic copy and localizes server state codes', () {
    const localizations = AppLocalizations(Locale('ar'));

    expect(localizations.translate('زيارات اليوم'), 'زيارات اليوم');
    expect(localizations.translate('en_route'), 'في الطريق');
  });

  testWidgets('localized text follows locale direction automatically', (
    tester,
  ) async {
    Future<void> pump(Locale locale) => tester.pumpWidget(
      MaterialApp(
        locale: locale,
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: const Scaffold(body: LText('زيارات اليوم')),
      ),
    );

    await pump(const Locale('en'));
    expect(find.text('Today’s visits'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('Today’s visits'))),
      TextDirection.ltr,
    );

    await pump(const Locale('ar'));
    expect(find.text('زيارات اليوم'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('زيارات اليوم'))),
      TextDirection.rtl,
    );
  });
}
