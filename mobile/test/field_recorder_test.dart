import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/field_recorder.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/location.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Review finding 4: the local write and its queued event were two separate
/// writes. These tests make the event insert fail and check that the local
/// change rolled back with it — the screen can never show work the server
/// will not hear about.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late EventQueue queue;
  late FieldRecorder recorder;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    final clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    recorder = FieldRecorder(db: db, queue: queue, progress: VisitProgress(db));
    await db.replaceVisits([
      {
        'id': 7,
        'state': 'scheduled',
        'site': {
          'name': 'فرع',
          'lat': 21.5,
          'lng': 39.2,
          'geofence_radius_m': 150,
        },
        'work_order': {'title': 'صيانة'},
      },
    ]);
  });

  tearDown(() async => db.raw.close());

  Future<void> breakEventInserts() => db.raw.execute('''
    CREATE TRIGGER reject_events BEFORE INSERT ON pending_events
    BEGIN SELECT RAISE(ABORT, 'injected failure'); END
  ''');

  test('a checklist row and its event commit together or not at all', () async {
    await breakEventInserts();

    await expectLater(
      recorder.setChecklist(7, 11, status: 'fault'),
      throwsA(anything),
    );

    expect(await db.raw.query('local_checklists'), isEmpty);
    expect(await db.getValue('event_sequence'), isNull);
  });

  test('a transition and its event commit together or not at all', () async {
    await breakEventInserts();

    await expectLater(recorder.transition(7, 'en_route'), throwsA(anything));

    expect((await db.visit(7))!['state'], 'scheduled');
  });

  test('the parts declaration and its event commit together', () async {
    await recorder.setChecklist(7, 11, status: 'ok');
    await breakEventInserts();

    await expectLater(recorder.declareNoParts(7, true), throwsA(anything));

    final rows = await db.raw.query('local_checklists');
    expect(rows.single['no_parts_used'], 0);
  });

  test('an illegal transition queues nothing', () async {
    expect(await recorder.transition(7, 'completed'), isFalse);
    expect(await queue.pending(), isEmpty);
  });

  test(
    'events carry location, mock flag, author and override reason',
    () async {
      const fix = LocationFix(
        lat: 21.6,
        lng: 39.3,
        accuracyM: 12.5,
        isMocked: true,
      );

      await recorder.transition(7, 'en_route');
      await recorder.transition(
        7,
        'started',
        location: fix,
        geofenceOverrideReason: '  مدخل الموظفين في الشارع الخلفي  ',
        actorUserId: 42,
      );

      final wire = (await queue.pending()).last.toWire();
      expect(wire['lat'], 21.6);
      expect(wire['lng'], 39.3);
      expect(wire['accuracy_m'], 12.5);
      expect(wire['is_mocked'], isTrue);
      expect(wire['actor_user_id'], 42);
      expect(wire['payload'], {
        'to': 'started',
        'geofence_override_reason': 'مدخل الموظفين في الشارع الخلفي',
      });
    },
  );
}
