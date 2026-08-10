import 'dart:convert';

import 'package:darak_field/core/api_client.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/sync_engine.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// The scenario the product exists for, tested from the device's side.
///
/// Review found the app could not advance a visit at all without a network: the
/// only writer of `visits.state` was the bootstrap response. The technician
/// tapped "انطلاق" and nothing changed, so every later button was unreachable.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late VisitProgress progress;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    progress = VisitProgress(db);

    await db.replaceVisits([
      {
        'id': 7,
        'state': 'scheduled',
        'scheduled_start': DateTime.now().toIso8601String(),
        'site': {'name': 'فرع السلامة', 'client_name': 'مطعم السلامة'},
        'work_order': {'title': 'صيانة وقائية'},
        'assets': [
          {'id': 11, 'name': 'سبليت الصالة', 'type': 'split_ac'},
        ],
      }
    ]);
  });

  tearDown(() async => db.raw.close());

  group('offline state progression', () {
    test('a transition advances the local state with no network', () async {
      expect((await db.visit(7))!['state'], 'scheduled');

      await progress.applyTransition(7, 'en_route');

      expect((await db.visit(7))!['state'], 'en_route',
          reason: 'the technician must see the next button without a server');
    });

    test('the whole visit can be walked to completion offline', () async {
      for (final state in ['en_route', 'started', 'awaiting_close', 'completed']) {
        final ok = await progress.applyTransition(7, state);
        expect(ok, isTrue, reason: 'transition to $state must be accepted locally');
      }

      expect((await db.visit(7))!['state'], 'completed');
    });

    test('an illegal local transition is refused instead of queueing a doomed event', () async {
      // scheduled -> completed is not in the map; queueing it would only produce a
      // 409 later and park a permanently failed row in the technician's face.
      final ok = await progress.applyTransition(7, 'completed');

      expect(ok, isFalse);
      expect((await db.visit(7))!['state'], 'scheduled');
    });

    test('the canonical server state overrides the local guess after sync', () async {
      await progress.applyTransition(7, 'en_route');

      // The office reopened the visit while the device was dark.
      await progress.adoptCanonical([
        {'id': 7, 'state': 'scheduled'}
      ]);

      expect((await db.visit(7))!['state'], 'scheduled');
    });
  });

  group('close is held until evidence has landed', () {
    test('a close event is not pushed while media is still pending', () async {
      final server = _RecordingServer();
      final engine = _engine(server, db, queue, clock);

      await db.raw.insert('pending_media', {
        'client_media_id': 'media-1',
        'visit_id': 7,
        'kind': 'photo_after',
        'local_path': '/nonexistent/photo.jpg',
        'total_bytes': 10,
        'uploaded_bytes': 0,
        'state': 'pending',
      });

      await queue.enqueue(visitId: 7, eventType: 'checklist.upsert', payload: {'asset_id': 11});
      await queue.enqueue(visitId: 7, eventType: 'visit.transition', payload: {'to': 'completed'});

      await engine.sync();

      expect(server.sentTypes, contains('checklist.upsert'));
      expect(server.sentTypes, isNot(contains('visit.transition')),
          reason: 'the close must wait for the evidence it will be judged on');

      // And it is still queued, not lost.
      final pending = await queue.pending();
      expect(pending.map((e) => e.eventType), contains('visit.transition'));
    });

    test('the close is pushed once every upload for that visit is complete', () async {
      final server = _RecordingServer();
      final engine = _engine(server, db, queue, clock);

      await db.raw.insert('pending_media', {
        'client_media_id': 'media-1',
        'visit_id': 7,
        'kind': 'photo_after',
        'local_path': '/nonexistent/photo.jpg',
        'total_bytes': 10,
        'uploaded_bytes': 10,
        'state': 'complete',
      });

      await queue.enqueue(visitId: 7, eventType: 'visit.transition', payload: {'to': 'completed'});

      await engine.sync();

      expect(server.sentTypes, contains('visit.transition'));
    });

    test('a close for another visit is not held back by this visit’s uploads', () async {
      final server = _RecordingServer();
      final engine = _engine(server, db, queue, clock);

      await db.raw.insert('pending_media', {
        'client_media_id': 'media-1',
        'visit_id': 7,
        'kind': 'photo_after',
        'local_path': '/x.jpg',
        'total_bytes': 10,
        'uploaded_bytes': 0,
        'state': 'pending',
      });

      await queue.enqueue(visitId: 99, eventType: 'visit.transition', payload: {'to': 'completed'});

      await engine.sync();

      expect(server.sentTypes, contains('visit.transition'));
    });
  });

  group('rejection handling', () {
    test('a retryable rejection stays queued instead of being parked as failed', () async {
      final server = _RecordingServer()..rejectWith = {'code': 'VISIT_CLOSE_BLOCKED', 'retryable': true};
      final engine = _engine(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'visit.transition', payload: {'to': 'completed'});

      await engine.sync();

      expect(await queue.failed(), isEmpty,
          reason: 'a refusal that clears itself once uploads finish is not a failure');
      expect(await queue.pending(), hasLength(1));
    });

    test('a permanent rejection is parked as failed with its reason', () async {
      final server = _RecordingServer()
        ..rejectWith = {'code': 'INVALID_VISIT_TRANSITION', 'retryable': false};
      final engine = _engine(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'visit.transition', payload: {'to': 'completed'});

      await engine.sync();

      final failed = await queue.failed();
      expect(failed, hasLength(1));
      expect(failed.first.lastError, contains('INVALID_VISIT_TRANSITION'));
    });

    test('a 401 leaves the queue intact and reports the session as dead', () async {
      final server = _RecordingServer()..failWith = 401;
      final engine = _engine(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'note.added');
      await queue.enqueue(visitId: 7, eventType: 'note.added');

      final outcome = (await engine.sync())!;

      expect(outcome.sessionExpired, isTrue);
      expect(await queue.failed(), isEmpty,
          reason: 'the session failed, not the events — a day of field work must survive');
      expect(await queue.pending(), hasLength(2));
    });
  });
}

SyncEngine _engine(_RecordingServer server, LocalDb db, EventQueue queue, TrustedClock clock) {
  return SyncEngine(
    api: ApiClient(baseUrl: 'https://test.local', client: server.client),
    queue: queue,
    db: db,
    clock: clock,
    deviceUuid: 'device-under-test',
  );
}

class _RecordingServer {
  final List<String> sentTypes = [];
  Map<String, dynamic>? rejectWith;
  int? failWith;

  http.Client get client => MockClient((request) async {
        if (failWith != null) {
          return http.Response(jsonEncode({'code': 'X', 'message': 'nope'}), failWith!);
        }

        if (request.url.path.endsWith('/sync/events')) {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          final events = (body['events'] as List<dynamic>).cast<Map<String, dynamic>>();
          final results = <Map<String, dynamic>>[];

          for (final e in events) {
            sentTypes.add(e['event_type'] as String);

            if (rejectWith != null) {
              results.add({
                'client_event_id': e['client_event_id'],
                'status': 'rejected',
                'code': rejectWith!['code'],
                'message': 'refused',
                if (rejectWith!.containsKey('retryable')) 'retryable': rejectWith!['retryable'],
              });
            } else {
              results.add({'client_event_id': e['client_event_id'], 'status': 'accepted'});
            }
          }

          return http.Response(
            jsonEncode({
              'server_time': DateTime.now().toIso8601String(),
              'results': results,
              'visits': [],
            }),
            200,
          );
        }

        return http.Response(jsonEncode({'code': 'NOT_FOUND'}), 404);
      });
}
