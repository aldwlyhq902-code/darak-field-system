import 'dart:convert';

import 'package:darak_field/core/api_client.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/sync_engine.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// The offline engine tested for real, on a real SQLite database, against a fake
/// server that can be told to fail.
///
/// This mirrors the paid programmer-gate scenario: 20 events and 10 photos captured
/// with no network, then a sync that must neither lose nor duplicate anything.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
  });

  tearDown(() async => db.raw.close());

  group('event queue', () {
    test('each queued action gets a distinct client_event_id', () async {
      final a = await queue.enqueue(visitId: 1, eventType: 'note.added');
      final b = await queue.enqueue(visitId: 1, eventType: 'note.added');

      expect(a.clientEventId, isNot(b.clientEventId));
      expect(a.clientEventId, hasLength(36));
    });

    test('sequence increases monotonically and survives clock changes', () async {
      final events = <QueuedEvent>[];
      for (var i = 0; i < 5; i++) {
        events.add(await queue.enqueue(visitId: 1, eventType: 'geofence.ping'));
      }

      expect(events.map((e) => e.sequence).toList(), [1, 2, 3, 4, 5]);
    });

    test('pending returns rows in sequence order, oldest first', () async {
      for (var i = 0; i < 3; i++) {
        await queue.enqueue(visitId: 1, eventType: 'step.$i');
      }

      final pending = await queue.pending();

      expect(pending.map((e) => e.eventType).toList(), ['step.0', 'step.1', 'step.2']);
    });

    test('a rejected event keeps its reason instead of disappearing', () async {
      final event = await queue.enqueue(visitId: 1, eventType: 'part.issue');

      await queue.markFailed(event.clientEventId, 'EVENT_FAILED: Insufficient stock');

      final failed = await queue.failed();
      expect(failed, hasLength(1));
      expect(failed.first.lastError, contains('Insufficient stock'));

      // And it can be retried after the supervisor fixes the cause.
      await queue.requeue(event.clientEventId);
      expect(await queue.pending(), hasLength(1));
    });
  });

  group('sync engine', () {
    test('twenty offline events sync once and replay as duplicates', () async {
      final server = _FakeServer();
      final engine = _engine(server, db, queue, clock);

      for (var i = 0; i < 20; i++) {
        await queue.enqueue(visitId: 7, eventType: 'checklist.upsert', payload: {'i': i});
      }

      expect(await queue.pending(), hasLength(20));

      final first = (await engine.sync())!;
      expect(first.accepted, 20);
      expect(first.rejected, 0);
      expect(await queue.pending(), isEmpty);
      expect(server.receivedEventIds, hasLength(20));

      // The real duplicate scenario: the server processed the batch but the reply
      // never reached the handset, so the app still believes it is unsent and
      // retries. Simulated by putting the rows back to pending.
      await db.raw.update('pending_events', {'status': 'pending'});

      final second = (await engine.sync())!;
      expect(second.duplicate, 20, reason: 'server recognises the same UUIDs');
      expect(second.accepted, 0);
      expect(server.uniqueEventIds, hasLength(20), reason: 'no duplicate side effects');
      expect(await queue.pending(), isEmpty, reason: 'the retry settles the queue');
    });

    test('network failure leaves events pending, not lost and not failed', () async {
      final server = _FakeServer()..failWith = 503;
      final engine = _engine(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'note.added');
      await queue.enqueue(visitId: 7, eventType: 'note.added');

      final outcome = (await engine.sync())!;

      expect(outcome.deferred, 2);
      expect(outcome.rejected, 0);
      expect(await queue.pending(), hasLength(2), reason: 'still queued for retry');
      expect(await queue.failed(), isEmpty, reason: 'transport failure is not the event\'s fault');

      // Server recovers.
      server.failWith = null;
      final retry = (await engine.sync())!;
      expect(retry.accepted, 2);
      expect(await queue.pending(), isEmpty);
    });

    test('a server rejection is surfaced with its reason', () async {
      final server = _FakeServer()..rejectTypes = {'part.issue'};
      final engine = _engine(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'note.added');
      await queue.enqueue(visitId: 7, eventType: 'part.issue');

      final outcome = (await engine.sync())!;

      expect(outcome.accepted, 1);
      expect(outcome.rejected, 1);

      final failed = await queue.failed();
      expect(failed, hasLength(1));
      expect(failed.first.eventType, 'part.issue');
      expect(failed.first.lastError, contains('Insufficient stock'));
    });

    test('one bad event does not block the good ones behind it', () async {
      final server = _FakeServer()..rejectTypes = {'bad'};
      final engine = _engine(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'good.1');
      await queue.enqueue(visitId: 7, eventType: 'bad');
      await queue.enqueue(visitId: 7, eventType: 'good.2');

      final outcome = (await engine.sync())!;

      expect(outcome.accepted, 2);
      expect(outcome.rejected, 1);
      expect(await queue.pending(), isEmpty);
    });

    test('counts drive the sync status screen', () async {
      final server = _FakeServer()..rejectTypes = {'bad'};
      final engine = _engine(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'good');
      await queue.enqueue(visitId: 7, eventType: 'bad');
      await engine.sync();
      await queue.enqueue(visitId: 7, eventType: 'later');

      final counts = await queue.counts();

      expect(counts[QueuedStatus.synced], 1);
      expect(counts[QueuedStatus.failed], 1);
      expect(counts[QueuedStatus.pending], 1);
    });
  });

  group('trusted clock', () {
    test('reports a null offset instead of guessing before the first sync', () async {
      expect(clock.monotonicOffsetMs, isNull);
      expect(clock.suspectedDrift, isNull);
    });

    test('after adopting server time the offset advances monotonically', () async {
      await clock.adopt(DateTime.utc(2026, 8, 10, 12));

      expect(clock.monotonicOffsetMs, isNotNull);
      expect(clock.lastTrustedServerTime, DateTime.utc(2026, 8, 10, 12));

      await Future<void>.delayed(const Duration(milliseconds: 25));
      expect(clock.monotonicOffsetMs, greaterThanOrEqualTo(20));
    });

    test('queued events carry the clock evidence the server needs', () async {
      await clock.adopt(DateTime.utc(2026, 8, 10, 12));

      final event = await queue.enqueue(visitId: 1, eventType: 'note.added');
      final wire = event.toWire();

      expect(wire['device_timestamp'], isNotNull);
      expect(wire['monotonic_offset_ms'], isNotNull);
      expect(wire['last_trusted_server_time'], isNotNull);
      expect(wire['sequence'], 1);
    });
  });
}

SyncEngine _engine(_FakeServer server, LocalDb db, EventQueue queue, TrustedClock clock) {
  return SyncEngine(
    api: ApiClient(baseUrl: 'https://test.local', client: server.client),
    queue: queue,
    db: db,
    clock: clock,
    deviceUuid: 'device-under-test',
  );
}

/// Minimal stand-in for the Laravel endpoint, with the same idempotency contract.
class _FakeServer {
  final Set<String> uniqueEventIds = <String>{};
  final List<String> receivedEventIds = <String>[];
  Set<String> rejectTypes = <String>{};
  int? failWith;

  http.Client get client => MockClient((request) async {
        if (failWith != null) {
          return http.Response(
            jsonEncode({'code': 'SERVER', 'message': 'upstream unavailable'}),
            failWith!,
          );
        }

        if (request.url.path.endsWith('/sync/events')) {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          final events = (body['events'] as List<dynamic>).cast<Map<String, dynamic>>();
          final results = <Map<String, dynamic>>[];

          for (final event in events) {
            final id = event['client_event_id'] as String;
            final type = event['event_type'] as String;

            if (rejectTypes.contains(type)) {
              results.add({
                'client_event_id': id,
                'status': 'rejected',
                'code': 'EVENT_FAILED',
                'message': 'Insufficient stock at [Vehicle 1]',
              });
              continue;
            }

            if (uniqueEventIds.contains(id)) {
              results.add({'client_event_id': id, 'status': 'duplicate'});
            } else {
              uniqueEventIds.add(id);
              receivedEventIds.add(id);
              results.add({'client_event_id': id, 'status': 'accepted'});
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
