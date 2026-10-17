import 'package:darak_field/core/error_messages.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/sync_engine.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/fake_api.dart';

/// The phone shows a visit closed the moment the button is tapped. When the
/// server refuses, the technician has to learn it — with the server's own list
/// of what is missing — and must be able to get past a refusal that will never
/// change. Before: the screen kept "مقفلة", the list was thrown away, and the
/// failed event blocked sign-out until a supervisor revoked the phone.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late VisitProgress progress;
  late FakeApi server;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    progress = VisitProgress(db);
    server = FakeApi();

    await db.replaceVisits([
      {
        'id': 7,
        'state': 'awaiting_close',
        'scheduled_start': DateTime.now().toIso8601String(),
        'site': {'name': 'فرع السلامة', 'client_name': 'مطعم السلامة'},
        'work_order': {'title': 'صيانة وقائية'},
        'assets': [
          {'id': 11, 'name': 'سبليت الصالة', 'type': 'split_ac'},
        ],
      },
    ]);
  });

  tearDown(() async => db.raw.close());

  Future<QueuedEvent> closeLocally() async {
    expect(await progress.applyTransition(7, 'completed'), isTrue);
    return queue.enqueue(
      visitId: 7,
      eventType: 'visit.transition',
      payload: {'to': 'completed'},
    );
  }

  void refuseCloses() {
    server.onEvents = (events) => FakeApi.json({
      'server_time': DateTime.now().toUtc().toIso8601String(),
      'results': [
        for (final event in events)
          {
            'client_event_id': event['client_event_id'],
            'status': 'rejected',
            'code': 'VISIT_CLOSE_BLOCKED',
            'message': 'This visit cannot be closed yet.',
            'retryable': false,
            'blockers': [
              {
                'code': 'ADDITIONAL_WORK_PENDING',
                'message_ar': 'بانتظار موافقة العميل على الأعمال الإضافية.',
              },
              {
                'code': 'SIGNATURE_MISSING',
                'message_ar': 'توقيع العميل مطلوب.',
              },
            ],
          },
      ],
      'visits': [
        {'id': 7, 'state': 'awaiting_close'},
      ],
    });
  }

  test(
    'a refused close rolls the visit back and keeps the server list',
    () async {
      final close = await closeLocally();
      refuseCloses();

      await engineFor(server, db, queue, clock).sync();

      expect(
        (await db.visit(7))!['state'],
        'awaiting_close',
        reason: 'the phone must stop calling the visit closed',
      );

      final refused = await queue.failedFor(7);
      expect(refused.single.clientEventId, close.clientEventId);
      expect(SyncEngine.closeBlockersFrom(refused.single.lastError), [
        'بانتظار موافقة العميل على الأعمال الإضافية.',
        'توقيع العميل مطلوب.',
      ]);
    },
  );

  test('the sync screen shows the server list, not a generic line', () {
    final reason = SyncEngine.rejectionReason({
      'code': 'VISIT_CLOSE_BLOCKED',
      'message': 'This visit cannot be closed yet.',
      'blockers': [
        {'message_ar': 'صورة للأصل مطلوبة.'},
        {'message_ar': 'توقيع العميل مطلوب.'},
      ],
    });
    final separator = reason.indexOf(':');

    expect(
      friendlyRejection(
        reason.substring(0, separator),
        reason.substring(separator + 1).trim(),
      ),
      '$closeRefusedPrefixصورة للأصل مطلوبة.، توقيع العميل مطلوب.',
    );
  });

  test('other refusals keep their "CODE: message" form', () {
    expect(
      SyncEngine.rejectionReason({
        'code': 'VISIT_REASSIGNED',
        'message': 'No longer yours.',
      }),
      'VISIT_REASSIGNED: No longer yours.',
    );
    expect(SyncEngine.closeBlockersFrom('VISIT_REASSIGNED: x'), isEmpty);
    expect(SyncEngine.closeBlockersFrom(null), isEmpty);
  });

  test(
    'an abandoned refusal no longer counts as unsynced work (sign-out)',
    () async {
      final close = await closeLocally();
      refuseCloses();
      await engineFor(server, db, queue, clock).sync();
      expect((await db.unsyncedCounts()).events, 1);

      expect(await queue.abandon(close.clientEventId), isTrue);

      expect((await db.unsyncedCounts()).events, 0);
      expect(await queue.failedFor(7), isEmpty);
      // Only a refused event can be abandoned; one still on its way cannot.
      final pending = await queue.enqueue(
        visitId: 7,
        eventType: 'note.added',
        payload: {'text': 'x'},
      );
      expect(await queue.abandon(pending.clientEventId), isFalse);
    },
  );
}
