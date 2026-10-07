import 'package:flutter/material.dart';

/// The app theme. One function so the widget tests render with exactly the
/// theme the phone uses — the full-width button rule below is what broke the
/// visit screen, and a test with the default theme could not have seen it.
ThemeData buildDarakTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: const Color(0xFF0F766E),
    brightness: Brightness.light,
  );

  return ThemeData(
    colorScheme: scheme,
    useMaterial3: true,
    fontFamily: 'Roboto',
    // Field conditions: gloves, sunlight, one hand. Bigger targets, higher
    // contrast, no delicate hit areas.
    //
    // `Size.fromHeight` means an INFINITE minimum width: right for the primary
    // actions stacked down a screen, wrong anywhere the button shares a row
    // (a ListTile's trailing slot, a Row). Use [TileButton] there.
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(56),
        textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
      ),
    ),
    cardTheme: const CardThemeData(margin: EdgeInsets.symmetric(vertical: 6)),
  );
}
