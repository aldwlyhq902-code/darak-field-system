import 'dart:convert';
import 'dart:io';

import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/fake_api.dart';

/// Review findings 1, 5, 10 and 11: what the engine does when the network, the
/// server, or one bad event misbehaves.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late EvidenceStore evidence;
  late Directory root;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    root = await Directory.systemTemp.createTemp('darak_sync_test');
    evidence = EvidenceStore(
      db: db,
      queue: queue,
      clock: clock,
      rootDirectory: root.path,
    );
  });

  tearDown(() async {
    await db.raw.close();
    if (await root.exists()) await root.delete(recursive: true);
  });

  Future<Map<String, Object?>> media(String id) async => (await db.raw.query(
    'pending_media',
    where: 'client_media_id = ?',
    whereArgs: [id],
  )).single;

  group('offline never marks evidence failed (finding 1)', () {
    test(
      'a basement full of retries spends none of the photo’s attempts',
      () async {
        final server = FakeApi()..offline = true;
        final engine = engineFor(server, db, queue, clock, evidence: evidence);

        final id = await evidence.store(
          visitId: 7,
          kind: EvidenceStore.kindPhotoAfter,
          bytes: utf8.encode('jpeg bytes'),
          assetId: 1,
        );

        // Every record() triggers a sync. Twelve of them in a dead zone used to
        // push the photo past maxAttempts (8) and park it as "failed".
        for (var i = 0; i < 12; i++) {
          await engine.sync(force: true);
        }

        final row = await media(id);
        expect(row['state'], isNot('failed'));
        expect(row['attempts'], 0);
        expect(
          await queue.pending(),
          hasLength(1),
          reason: 'registration kept',
        );
      },
    );

    test('uploads are skipped when the events push found no network', () async {
      final server = FakeApi()..offline = true;
      final engine = engineFor(server, db, queue, clock, evidence: evidence);

      // A file whose registration has already landed, so only the
      // connectivity rule can be what keeps the uploader away.
      final id = await evidence.store(
        visitId: 7,
        kind: EvidenceStore.kindPhotoAfter,
        bytes: utf8.encode('jpeg bytes'),
      );
      await queue.markSynced([(await queue.pending()).single.clientEventId]);
      await queue.enqueue(visitId: 7, eventType: 'note.added');

      final outcome = (await engine.sync())!;

      expect(outcome.connectivityLost, isTrue);
      expect(
        server.mediaRequests,
        isEmpty,
        reason: 'uploading into a link that just died only burns attempts',
      );
      expect((await media(id))['attempts'], 0);
    });

    test('a 5xx on upload backs off without counting an attempt', () async {
      final server = FakeApi()
        ..onMedia = (path, _) => path.endsWith('/chunk')
            ? FakeApi.json({'code': 'SERVER'}, 503)
            : null;
      final engine = engineFor(server, db, queue, clock, evidence: evidence);

      final id = await evidence.store(
        visitId: 7,
        kind: EvidenceStore.kindPhotoAfter,
        bytes: utf8.encode('jpeg bytes'),
      );

      await engine.sync();

      var row = await media(id);
      expect(row['attempts'], 0);
      expect(row['state'], isNot('failed'));
      final retryAt = DateTime.parse(row['next_attempt_at'] as String);
      expect(retryAt.isAfter(DateTime.now().toUtc()), isTrue);

      // An automatic sync waits the backoff out…
      final before = server.mediaRequests.length;
      await engine.sync();
      expect(server.mediaRequests.length, before);

      // …the technician's own "sync" does not.
      server.onMedia = null;
      await engine.sync(force: true);
      row = await media(id);
      expect(row['state'], 'complete');
    });

    test('only definitive refusals count, and they still give up', () async {
      final server = FakeApi()
        ..onMedia = (path, _) => path.endsWith('/chunk')
            ? FakeApi.json({'code': 'MEDIA_REJECTED'}, 422)
            : null;
      final engine = engineFor(server, db, queue, clock, evidence: evidence);

      final id = await evidence.store(
        visitId: 7,
        kind: EvidenceStore.kindPhotoAfter,
        bytes: utf8.encode('jpeg bytes'),
      );

      await engine.sync(force: true);
      expect((await media(id))['attempts'], 1);

      for (var i = 0; i < 10; i++) {
        await engine.sync(force: true);
      }
      expect((await media(id))['state'], 'failed');
    });
  });

  group('one bad event does not fail the batch (finding 5)', () {
    test('a request-level 422 is bisected down to the culprit', () async {
      final server = FakeApi()
        ..onEvents = (events) => events.any((e) => e['event_type'] == 'bad')
            ? FakeApi.json({'message': 'The given data was invalid.'}, 422)
            : null;
      final engine = engineFor(server, db, queue, clock);

      for (final type in ['a', 'b', 'bad', 'c', 'd']) {
        await queue.enqueue(visitId: 7, eventType: type);
      }

      final outcome = (await engine.sync())!;

      expect(outcome.accepted, 4);
      expect(outcome.rejected, 1);
      final failed = await queue.failed();
      expect(failed.single.eventType, 'bad');
      expect(await queue.pending(), isEmpty);
    });

    test('validation keys pinpoint the culprit without bisecting', () async {
      var calls = 0;
      final server = FakeApi()
        ..onEvents = (events) {
          calls++;
          final index = events.indexWhere((e) => e['event_type'] == 'bad');
          if (index < 0) return null;
          return FakeApi.json({
            'message': 'invalid',
            'errors': {
              'events.$index.payload': ['The payload field is too large.'],
            },
          }, 422);
        };
      final engine = engineFor(server, db, queue, clock);

      for (final type in ['a', 'bad', 'c']) {
        await queue.enqueue(visitId: 7, eventType: type);
      }

      await engine.sync();

      expect((await queue.failed()).single.eventType, 'bad');
      expect((await queue.failed()).single.lastError, contains('too large'));
      expect(calls, 2, reason: 'one refusal, one resend of the innocent rest');
    });

    test('a refusal of the request itself fails no event', () async {
      final server = FakeApi()
        ..failWith = 422
        ..failBody = {
          'message': 'invalid',
          'errors': {
            'device_uuid': ['The device uuid field must be a valid UUID.'],
          },
        };
      final engine = engineFor(server, db, queue, clock);

      for (var i = 0; i < 4; i++) {
        await queue.enqueue(visitId: 7, eventType: 'note.added');
      }

      final outcome = (await engine.sync())!;

      expect(await queue.failed(), isEmpty);
      expect(await queue.pending(), hasLength(4));
      expect(outcome.error, isNotNull);
    });

    test('batches are capped by size as well as by count', () async {
      final server = FakeApi();
      final engine = engineFor(
        server,
        db,
        queue,
        clock,
        batchByteLimit: 64 * 1024,
      );

      final note = 'x' * (20 * 1024);
      for (var i = 0; i < 12; i++) {
        await queue.enqueue(
          visitId: 7,
          eventType: 'note.added',
          payload: {'text': note},
        );
      }

      await engine.sync();

      expect(await queue.pending(), isEmpty);
      expect(server.eventRequests.length, greaterThan(1));
      for (final request in server.eventRequests) {
        expect(request.bodyBytes.length, lessThan(64 * 1024 + 4096));
      }
    });
  });

  group('the whole queue goes out (finding 10)', () {
    test('more than one batch of 200 is sent in a single sync', () async {
      final server = FakeApi();
      final engine = engineFor(server, db, queue, clock);

      for (var i = 0; i < 450; i++) {
        await queue.enqueue(visitId: 7, eventType: 'checklist.upsert');
      }

      final outcome = (await engine.sync())!;

      expect(outcome.accepted, 450);
      expect(await queue.pending(), isEmpty);
      expect(
        server.eventRequests.every(
          (r) => (r.json['events'] as List).length <= 200,
        ),
        isTrue,
      );
    });

    test(
      'an event recorded while a sync is running goes out with it',
      () async {
        final server = FakeApi();
        var tapped = false;
        server.duringEvents = () async {
          if (tapped) return;
          tapped = true;
          // The technician taps "سليم" while the first request is in flight.
          await queue.enqueue(visitId: 7, eventType: 'checklist.upsert');
        };
        final engine = engineFor(server, db, queue, clock);

        await queue.enqueue(visitId: 7, eventType: 'visit.transition');
        await engine.sync();

        expect(await queue.pending(), isEmpty);
        expect(server.sentEventTypes, ['visit.transition', 'checklist.upsert']);
      },
    );
  });

  group('uploads cannot spin forever (finding 11)', () {
    test('OFFSET_MISMATCH resyncs are capped per run', () async {
      var chunks = 0;
      final server = FakeApi()
        ..onMedia = (path, _) {
          if (!path.endsWith('/chunk')) return null;
          chunks++;
          return FakeApi.json({
            'code': 'OFFSET_MISMATCH',
            'expected_offset': 0,
          }, 409);
        };
      final engine = engineFor(server, db, queue, clock, evidence: evidence);

      final id = await evidence.store(
        visitId: 7,
        kind: EvidenceStore.kindPhotoAfter,
        bytes: utf8.encode('jpeg bytes'),
      );

      await engine.sync().timeout(const Duration(seconds: 10));

      expect(chunks, lessThanOrEqualTo(engine.maxOffsetResyncs + 1));
      expect((await media(id))['attempts'], 1);
      expect((await media(id))['last_error'], contains('UPLOAD_STALLED'));
    });
  });

  test(
    'automatic syncs respect a server backoff; manual ones do not',
    () async {
      final server = FakeApi()..failWith = 503;
      final engine = engineFor(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'note.added');
      await engine.sync();
      expect(server.eventRequests, hasLength(1));

      server.failWith = null;
      await engine.sync();
      expect(server.eventRequests, hasLength(1), reason: 'still backing off');

      await engine.sync(force: true);
      expect(server.eventRequests, hasLength(2));
      expect(await queue.pending(), isEmpty);
    },
  );

  group('contract additions', () {
    test(
      'a 429 on sync/events waits out Retry-After and counts nothing',
      () async {
        final server = FakeApi()
          ..onEvents = (_) => FakeApi.json(
            {'code': 'TOO_MANY_REQUESTS'},
            429,
            {'retry-after': '120'},
          );
        final engine = engineFor(server, db, queue, clock);

        await queue.enqueue(visitId: 7, eventType: 'note.added');
        final outcome = (await engine.sync())!;

        expect(outcome.deferred, 1);
        final row = (await db.raw.query('pending_events')).single;
        expect(row['status'], 'pending');
        expect(row['attempts'], 0, reason: 'a rate limit is not a failure');
        final wait = DateTime.parse(
          row['next_attempt_at'] as String,
        ).difference(DateTime.now().toUtc());
        expect(wait.inSeconds, inInclusiveRange(115, 120));
      },
    );

    test(
      'server-side event failures retry; definitive codes are parked',
      () async {
        final server = FakeApi()
          ..onEvents = (events) => FakeApi.json({
            'server_time': DateTime.now().toUtc().toIso8601String(),
            'results': [
              for (final event in events)
                switch (event['event_type']) {
                  'flaky' => {
                    'client_event_id': event['client_event_id'],
                    'status': 'rejected',
                    'code': 'DB_ERROR',
                    'request_id': 'req-123',
                  },
                  'stolen' => {
                    'client_event_id': event['client_event_id'],
                    'status': 'rejected',
                    'code': 'VISIT_REASSIGNED',
                    'message': 'Visit was reassigned.',
                  },
                  _ => {
                    'client_event_id': event['client_event_id'],
                    'status': 'accepted',
                  },
                },
            ],
            'visits': [],
          });
        final engine = engineFor(server, db, queue, clock);

        await queue.enqueue(visitId: 7, eventType: 'flaky');
        await queue.enqueue(visitId: 7, eventType: 'stolen');
        await queue.enqueue(visitId: 7, eventType: 'fine');

        final outcome = (await engine.sync())!;

        expect(outcome.accepted, 1);
        expect(outcome.rejected, 1);
        final failed = await queue.failed();
        expect(failed.single.eventType, 'stolen');
        expect(failed.single.lastError, startsWith('VISIT_REASSIGNED'));

        final retrying = (await queue.pending()).single;
        expect(retrying.eventType, 'flaky');
        expect(retrying.attempts, 0);
        expect(retrying.lastError, contains('req-123'));
        expect(retrying.nextAttemptAt!.isAfter(DateTime.now().toUtc()), isTrue);
      },
    );

    test('IMAGE_TOO_LARGE fails the photo at once, with its reason', () async {
      final server = FakeApi()
        ..onMedia = (path, _) => path.endsWith('/complete')
            ? FakeApi.json({'code': 'IMAGE_TOO_LARGE'}, 422)
            : null;
      final engine = engineFor(server, db, queue, clock, evidence: evidence);

      final id = await evidence.store(
        visitId: 7,
        kind: EvidenceStore.kindPhotoAfter,
        bytes: utf8.encode('jpeg bytes'),
      );

      await engine.sync();

      final row = await media(id);
      expect(row['state'], 'failed', reason: 'no retry can change the answer');
      expect(row['last_error'], startsWith('IMAGE_TOO_LARGE'));
    });

    test(
      '403 DEVICE_PENDING_APPROVAL stops the sync and keeps everything',
      () async {
        final server = FakeApi()
          ..failWith = 403
          ..failBody = {'code': 'DEVICE_PENDING_APPROVAL'};
        final engine = engineFor(server, db, queue, clock);

        await queue.enqueue(visitId: 7, eventType: 'note.added');
        final outcome = (await engine.sync())!;

        expect(outcome.pendingApproval, isTrue);
        expect(outcome.mustStop, isTrue);
        expect(outcome.remoteWipe, isFalse, reason: 'never a wipe');
        expect(await queue.pending(), hasLength(1));
        expect(await queue.failed(), isEmpty);
      },
    );
  });
}
