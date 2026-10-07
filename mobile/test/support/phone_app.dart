import 'dart:io';
import 'dart:typed_data';

import 'package:darak_field/app_state.dart';
import 'package:darak_field/core/action_outbox.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_cipher.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/field_recorder.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/location.dart';
import 'package:darak_field/core/sync_engine.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:darak_field/theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'fake_api.dart';

/// A mid-size Android phone (Pixel 8 class), in logical pixels.
const phoneSize = Size(393, 852);

/// Renders at [size] logical pixels for the rest of the test.
void usePhoneViewport(WidgetTester tester, {Size size = phoneSize}) {
  tester.view.devicePixelRatio = 2.625;
  tester.view.physicalSize = size * 2.625;
  addTearDown(tester.view.reset);
}

/// The app's MaterialApp shell: real theme, real localization, RTL for Arabic.
Widget phoneApp(Widget home, {Locale locale = const Locale('ar')}) =>
    MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: buildDarakTheme(),
      locale: locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) => Directionality(
        textDirection: locale.languageCode == 'ar'
            ? TextDirection.rtl
            : TextDirection.ltr,
        child: child ?? const SizedBox.shrink(),
      ),
      home: home,
    );

/// An [AppState] wired to an in-memory database and a [FakeApi], without the
/// platform plugins `init()` needs (keystore, path provider, connectivity).
class FakeApp {
  FakeApp._(this.state, this.server, this.root);

  final AppState state;
  final FakeApi server;
  final Directory root;

  static bool _ffiReady = false;

  /// Call inside `tester.runAsync` — sqflite works on a real isolate.
  static Future<FakeApp> create({
    List<Map<String, dynamic>> visits = const [],
    FakeApi? server,
  }) async {
    if (!_ffiReady) {
      sqfliteFfiInit();
      databaseFactory = databaseFactoryFfi;
      _ffiReady = true;
    }
    SharedPreferences.setMockInitialValues({});
    final api = server ?? FakeApi();
    final state = AppState(
      baseUrl: 'https://test.local',
      httpClient: api.client,
      location: NoLocationSource(),
    );
    final root = await Directory.systemTemp.createTemp('darak_ui_');
    final db = await LocalDb.open(path: inMemoryDatabasePath);
    final clock = await TrustedClock.load();
    final queue = EventQueue(db, clock);
    final cipher = EvidenceCipher(List<int>.generate(32, (i) => i * 7));
    final evidence = EvidenceStore(
      db: db,
      queue: queue,
      clock: clock,
      rootDirectory: root.path,
      cipher: cipher,
    );
    final progress = VisitProgress(db, now: () => clock.bestEstimate);
    final outbox = ActionOutbox(db, attachmentRoot: root.path, cipher: cipher);

    state
      ..db = db
      ..clock = clock
      ..queue = queue
      ..evidence = evidence
      ..progress = progress
      ..outbox = outbox
      ..recorder = FieldRecorder(
        db: db,
        queue: queue,
        progress: progress,
        now: () => clock.bestEstimate,
      )
      ..sync = SyncEngine(
        api: state.api,
        queue: queue,
        db: db,
        clock: clock,
        deviceUuid: '0b9a3f4e-1111-4222-8333-444455556666',
        progress: progress,
        evidenceRoot: root.path,
        evidence: evidence,
        outbox: outbox,
      )
      ..technicianName = 'فني تجريبي';

    await db.replaceVisits(visits);
    await state.refreshLocal();
    state.ready = true;
    return FakeApp._(state, api, root);
  }

  Future<void> addSignature(int visitId) async {
    await state.evidence.store(
      visitId: visitId,
      kind: EvidenceStore.kindSignature,
      bytes: Uint8List.fromList(List<int>.generate(64, (i) => i)),
      mime: 'image/png',
      declaredSource: 'on_screen',
    );
  }

  Future<void> dispose() async {
    await state.db.raw.close();
    if (root.existsSync()) root.deleteSync(recursive: true);
  }
}

/// One visit as the server's bootstrap sends it.
Map<String, dynamic> serverVisit({
  int id = 7,
  String state = 'started',
  String scheduledStart = '2026-10-07T07:30:00+03:00',
  String slaStatus = 'green',
}) => {
  'id': id,
  'state': state,
  'on_site_seconds': 0,
  'scheduled_start': scheduledStart,
  'is_rework': false,
  'site': {
    'name': 'فرع العليا',
    'client_name': 'مطاعم المذاق',
    'address': 'طريق الملك فهد، الرياض',
    'access_notes': 'الدخول من باب الخدمة الخلفي',
  },
  'work_order': {
    'number': 'WO-2026-0042',
    'title': 'صيانة وقائية شهرية للمكيفات',
    'type': 'preventive',
  },
  'sla': {
    'due_at': '2026-10-07T11:30:00+03:00',
    'status': slaStatus,
    'service_window': {'start': '07:00:00', 'end': '23:00:00'},
  },
  'assets': [
    {
      'id': 11,
      'name': 'مكيف سبليت — الصالة الرئيسية',
      'type': 'split_ac',
      'location': 'الصالة',
      'under_warranty': true,
    },
    {'id': 12, 'name': 'ثلاجة التبريد', 'type': 'freezer'},
  ],
};

/// Lets real (isolate-backed) database work finish, frame by frame.
Future<void> settleIo(WidgetTester tester, {int rounds = 12}) async {
  for (var i = 0; i < rounds; i++) {
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 15)),
    );
    await tester.pump();
  }
}
