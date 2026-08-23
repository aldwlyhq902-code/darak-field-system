import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'app_state.dart';
import 'core/api_config.dart';
import 'l10n/app_localizations.dart';
import 'screens/login_screen.dart';
import 'screens/today_screen.dart';

/// Point this at the Laravel host. Overridden at build time:
///   flutter run --dart-define=DARAK_API=https://api.darak.sa
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final apiBase = resolveApiBase();
  await initializeDateFormatting('ar');
  await initializeDateFormatting('en');
  runApp(DarakApp(apiBase: apiBase));
}

class DarakApp extends StatefulWidget {
  const DarakApp({required this.apiBase, super.key});

  final String apiBase;

  @override
  State<DarakApp> createState() => _DarakAppState();
}

class _DarakAppState extends State<DarakApp> {
  late final AppState state = AppState(baseUrl: widget.apiBase);
  Object? _initializationError;
  bool _initializing = true;

  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    if (mounted) {
      setState(() {
        _initializing = true;
        _initializationError = null;
      });
    }

    try {
      await state.init();
    } catch (error, stackTrace) {
      debugPrint('App initialization failed: $error');
      debugPrintStack(stackTrace: stackTrace);
      if (mounted) setState(() => _initializationError = error);
    } finally {
      if (mounted) setState(() => _initializing = false);
    }
  }

  @override
  void dispose() {
    state.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = ColorScheme.fromSeed(
      seedColor: const Color(0xFF0F766E),
      brightness: Brightness.light,
    );

    return MaterialApp(
      onGenerateTitle: (context) => context.tr('دارك — تطبيق الفني'),
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
            textStyle: const TextStyle(
              fontSize: 17,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        cardTheme: const CardThemeData(
          margin: EdgeInsets.symmetric(vertical: 6),
        ),
      ),
      locale: state.locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) => Directionality(
        textDirection: state.isArabic ? TextDirection.rtl : TextDirection.ltr,
        child: child ?? const SizedBox.shrink(),
      ),
      home: AnimatedBuilder(
        animation: state,
        builder: (context, _) {
          if (_initializationError != null) {
            return Scaffold(
              body: SafeArea(
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 420),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.error_outline,
                            size: 56,
                            color: scheme.error,
                          ),
                          const SizedBox(height: 16),
                          LText(
                            'تعذّر تجهيز التطبيق',
                            style: Theme.of(context).textTheme.headlineSmall,
                          ),
                          const SizedBox(height: 8),
                          const LText(
                            'لم نتمكن من فتح التخزين الآمن أو قاعدة البيانات المحلية. أعد المحاولة، وإن استمرت المشكلة تواصل مع الدعم قبل حذف التطبيق حتى لا تفقد الأعمال غير المرسلة.',
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 20),
                          FilledButton.icon(
                            onPressed: _initializing ? null : _initialize,
                            icon: const Icon(Icons.refresh),
                            label: const LText('إعادة المحاولة'),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            );
          }

          if (!state.ready) {
            return const Scaffold(
              body: Center(child: CircularProgressIndicator()),
            );
          }

          // A revoked or expired token drops the technician back to the login
          // screen instead of leaving the app retrying forever with a dead token.
          // Nothing queued is lost — it syncs after signing in again.
          return state.isAuthenticated
              ? TodayScreen(state: state)
              : LoginScreen(
                  state: state,
                  notice: state.sessionExpired ? state.lastSyncMessage : null,
                );
        },
      ),
    );
  }
}
