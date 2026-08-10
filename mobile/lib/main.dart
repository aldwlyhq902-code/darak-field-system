import 'package:flutter/material.dart';

import 'app_state.dart';
import 'screens/login_screen.dart';
import 'screens/today_screen.dart';

/// Point this at the Laravel host. Overridden at build time:
///   flutter run --dart-define=DARAK_API=https://api.darak.sa
const _apiBase = String.fromEnvironment(
  'DARAK_API',
  defaultValue: 'http://10.0.2.2:8000', // Android emulator -> host machine
);

void main() {
  runApp(const DarakApp());
}

class DarakApp extends StatefulWidget {
  const DarakApp({super.key});

  @override
  State<DarakApp> createState() => _DarakAppState();
}

class _DarakAppState extends State<DarakApp> {
  final AppState state = AppState(baseUrl: _apiBase);

  @override
  void initState() {
    super.initState();
    state.init();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = ColorScheme.fromSeed(
      seedColor: const Color(0xFF0F766E),
      brightness: Brightness.light,
    );

    return MaterialApp(
      title: 'دارك — تطبيق الفني',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: scheme,
        useMaterial3: true,
        fontFamily: 'Roboto',
        // Field conditions: gloves, sunlight, one hand. Bigger targets, higher
        // contrast, no delicate hit areas.
        filledButtonTheme: FilledButtonThemeData(
          style: FilledButton.styleFrom(
            minimumSize: const Size.fromHeight(56),
            textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
          ),
        ),
        cardTheme: const CardThemeData(margin: EdgeInsets.symmetric(vertical: 6)),
      ),
      // The whole product is Arabic-first.
      locale: const Locale('ar'),
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
      home: AnimatedBuilder(
        animation: state,
        builder: (context, _) {
          if (!state.ready) {
            return const Scaffold(body: Center(child: CircularProgressIndicator()));
          }

          // A revoked or expired token drops the technician back to the login
          // screen instead of leaving the app retrying forever with a dead token.
          // Nothing queued is lost — it syncs after signing in again.
          return state.isAuthenticated
              ? TodayScreen(state: state)
              : LoginScreen(state: state, notice: state.sessionExpired ? state.lastSyncMessage : null);
        },
      ),
    );
  }
}
