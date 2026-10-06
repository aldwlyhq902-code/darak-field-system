import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/field_recorder.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/fake_api.dart';

/// Review finding 2: a refresh used to undo offline progress.
///
/// TodayScreen pulled on every open before anything was pushed, and the
/// bootstrap REPLACEd each visit row: the server's (older) state overwrote the
/// phone's, and `local_started_at` was nulled even for visits whose
/// transitions were still queued.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late VisitProgress progress;
  late FieldRecorder recorder;

  Map<String, dynamic> serverVisit(String state, {int onSite = 0}) => {
    'id': 7,
    'state': state,
    'on_site_seconds': onSite,
    'scheduled_start': DateTime.now().toIso8601String(),
    'site': {'name': 'فرع السلامة', 'client_name': 'مطعم السلامة'},
    'work_order': {'title': 'صيانة وقائية'},
    'assets': [
      {'id': 11, 'name': 'سبليت الصالة'},
    ],
  };

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    progress = VisitProgress(db);
    recorder = FieldRecorder(db: db, queue: queue, progress: progress);
    await db.replaceVisits([serverVisit('scheduled')]);
  });

  tearDown(() async => db.raw.close());

  Future<Map<String, Object?>> visit() async => (await db.visit(7))!;

  test('a refresh keeps a visit whose transitions are still queued', () async {
    await recorder.transition(7, 'en_route');
    await recorder.transition(7, 'started');
    final startedAt = (await visit())['local_started_at'];
    expect(startedAt, isNotNull);

    // Offline the push fails; the server still says "scheduled".
    final server = FakeApi()..bootstrapVisits = [serverVisit('scheduled')];
    final engine = engineFor(server, db, queue, clock);
    await engine.bootstrap();

    expect((await visit())['state'], 'started');
    expect((await visit())['local_started_at'], startedAt);
    expect(
      (await visit())['wo_title'],
      'صيانة وقائية',
      reason: 'rest refreshed',
    );
  });

  test('sync(pull) pushes the queue before it pulls', () async {
    await recorder.transition(7, 'en_route');

    final server = FakeApi()
      ..canonicalVisits = [
        {'id': 7, 'state': 'en_route'},
      ]
      ..bootstrapVisits = [serverVisit('en_route')];
    final engine = engineFor(server, db, queue, clock);

    final outcome = (await engine.sync(pull: true))!;

    expect(outcome.pulled, isTrue);
    final paths = server.requests.map((r) => r.path.split('/').last).toList();
    expect(paths.indexOf('events'), lessThan(paths.indexOf('bootstrap')));
    expect((await visit())['state'], 'en_route');
  });

  test('no pull at all when the push found no network', () async {
    await recorder.transition(7, 'en_route');
    final server = FakeApi()..offline = true;
    final engine = engineFor(server, db, queue, clock);

    final outcome = (await engine.sync(pull: true))!;

    expect(outcome.pulled, isFalse);
    expect(
      server.requests.where((r) => r.path.endsWith('/bootstrap')),
      isEmpty,
    );
    expect((await visit())['state'], 'en_route');
  });

  test('the start time survives a refresh that agrees on the state', () async {
    await recorder.transition(7, 'en_route');
    await recorder.transition(7, 'started');
    final startedAt = (await visit())['local_started_at'];
    await queue.markSynced((await queue.pending()).map((e) => e.clientEventId));

    final server = FakeApi()..bootstrapVisits = [serverVisit('started')];
    await engineFor(server, db, queue, clock).bootstrap();

    expect((await visit())['state'], 'started');
    expect(
      (await visit())['local_started_at'],
      startedAt,
      reason: 'the next pause must still count the minutes on site',
    );
  });

  test('a held-back close is not undone by the canonical state', () async {
    for (final state in ['en_route', 'started', 'awaiting_close']) {
      await recorder.transition(7, state);
    }
    // The close waits for a photo that is still uploading.
    await db.raw.insert('pending_media', {
      'client_media_id': 'm-1',
      'visit_id': 7,
      'kind': 'photo_after',
      'local_path': '/never/read.jpg',
      'total_bytes': 1,
      'state': 'uploading',
      'next_attempt_at': DateTime.now()
          .toUtc()
          .add(const Duration(hours: 1))
          .toIso8601String(),
    });
    await recorder.transition(7, 'completed');

    // The other transitions go out; the server reports what IT knows.
    final server = FakeApi()
      ..canonicalVisits = [
        {'id': 7, 'state': 'awaiting_close'},
      ];
    final outcome = (await engineFor(server, db, queue, clock).sync())!;

    expect(outcome.heldBack, 1);
    expect(
      (await visit())['state'],
      'completed',
      reason: 'the technician closed it; the close is merely waiting',
    );
  });

  test('once the server has every transition, its state wins', () async {
    await recorder.transition(7, 'en_route');

    // The office cancelled the trip while the phone was dark.
    final server = FakeApi()
      ..canonicalVisits = [
        {'id': 7, 'state': 'scheduled'},
      ];
    await engineFor(server, db, queue, clock).sync();

    expect((await visit())['state'], 'scheduled');
  });
}
