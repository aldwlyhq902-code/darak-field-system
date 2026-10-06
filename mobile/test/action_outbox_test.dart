import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:darak_field/core/action_outbox.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_cipher.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/sync_engine.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/fake_api.dart';

/// Review finding 7 and contract C2: online-only actions now live in a
/// persisted outbox, each with ONE idempotency key reused on every retry.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late Directory root;
  late ActionOutbox outbox;
  final cipher = EvidenceCipher(List<int>.generate(32, (i) => i * 3));

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    root = await Directory.systemTemp.createTemp('darak_outbox_test');
    outbox = ActionOutbox(db, attachmentRoot: root.path, cipher: cipher);
  });

  tearDown(() async {
    await db.raw.close();
    if (await root.exists()) await root.delete(recursive: true);
  });

  Iterable<SeenRequest> actionRequests(FakeApi server, String suffix) =>
      server.requests.where((r) => r.path.endsWith(suffix));

  test('an offline tap is kept and later sent under the SAME key', () async {
    final server = FakeApi()..offline = true;
    final engine = engineFor(server, db, queue, clock, outbox: outbox);

    final action = await outbox.add(
      ActionRequest.acceptCustody(5, 'فني الاختبار'),
      actorUserId: 9,
    );
    final first = await engine.trySendAction(action.idempotencyKey);

    expect(first.kind, ActionAttemptKind.queued);
    expect(first.connectivityLost, isTrue);
    expect(
      (await outbox.pending()).single.idempotencyKey,
      action.idempotencyKey,
    );

    // The app is killed and restarted: a fresh outbox over the same database.
    final restarted = ActionOutbox(
      db,
      attachmentRoot: root.path,
      cipher: cipher,
    );
    server.offline = false;
    final outcome = (await engineFor(
      server,
      db,
      queue,
      clock,
      outbox: restarted,
    ).sync())!;

    expect(outcome.actionsSent, 1);
    final keys = actionRequests(
      server,
      '/custodies/5/accept',
    ).map((r) => r.headers['idempotency-key']).toList();
    expect(keys, hasLength(2));
    expect(keys.toSet(), {action.idempotencyKey});
    expect(actionRequests(server, '/custodies/5/accept').last.json, {
      'accepted_name': 'فني الاختبار',
    });
    expect(await outbox.pending(), isEmpty);
  });

  test('a replayed 2xx (Idempotent-Replayed: true) is a success', () async {
    final server = FakeApi()
      ..onAction = (_) => FakeApi.json(
        {
          'data': {'id': 3},
        },
        201,
        {'idempotent-replayed': 'true'},
      );
    final engine = engineFor(server, db, queue, clock, outbox: outbox);

    final action = await outbox.add(ActionRequest.acceptTransfer(3));
    final attempt = await engine.trySendAction(action.idempotencyKey);

    expect(attempt.kind, ActionAttemptKind.done);
    expect(attempt.replayed, isTrue);
    expect(
      (await outbox.find(action.idempotencyKey))!.status,
      ActionStatus.done,
    );
  });

  test(
    'IDEMPOTENCY_REQUEST_IN_PROGRESS waits for Retry-After, same key',
    () async {
      var busy = true;
      final server = FakeApi()
        ..onAction = (_) => busy
            ? FakeApi.json(
                {'code': 'IDEMPOTENCY_REQUEST_IN_PROGRESS'},
                409,
                {'retry-after': '30'},
              )
            : null;
      final engine = engineFor(server, db, queue, clock, outbox: outbox);

      final action = await outbox.add(ActionRequest.completeStocktake(9));
      final attempt = await engine.trySendAction(action.idempotencyKey);

      expect(attempt.kind, ActionAttemptKind.queued);
      final waiting = (await outbox.find(action.idempotencyKey))!;
      expect(waiting.status, ActionStatus.pending);
      expect(waiting.attempts, 0);
      final wait = waiting.nextAttemptAt!.difference(DateTime.now().toUtc());
      expect(wait.inSeconds, inInclusiveRange(25, 30));

      // An automatic sync respects the server's Retry-After…
      await engine.sync();
      expect(actionRequests(server, '/stocktakes/9/complete'), hasLength(1));

      // …and the retry carries the same key.
      busy = false;
      await engine.sync(force: true);
      expect(
        actionRequests(
          server,
          '/stocktakes/9/complete',
        ).map((r) => r.headers['idempotency-key']).toSet(),
        {action.idempotencyKey},
      );
      expect(await outbox.pending(), isEmpty);
    },
  );

  test('IDEMPOTENCY_KEY_INVALID is a programming error, not a retry', () async {
    final server = FakeApi()
      ..onAction = (_) =>
          FakeApi.json({'code': 'IDEMPOTENCY_KEY_INVALID'}, 422);
    final engine = engineFor(server, db, queue, clock, outbox: outbox);

    final action = await outbox.add(ActionRequest.releaseTransfer(2));
    final attempt = await engine.trySendAction(action.idempotencyKey);

    expect(attempt.kind, ActionAttemptKind.rejected);
    expect(attempt.message, 'تعذّر تنفيذ الطلب. أعد المحاولة لاحقاً.');
  });

  test(
    'IDEMPOTENCY_KEY_REUSED is refused once, never retried in a loop',
    () async {
      final server = FakeApi()
        ..onAction = (_) => FakeApi.json({
          'code': 'IDEMPOTENCY_KEY_REUSED',
          'message': 'x',
        }, 422);
      final engine = engineFor(server, db, queue, clock, outbox: outbox);

      final action = await outbox.add(ActionRequest.completeStocktake(4));
      final attempt = await engine.trySendAction(action.idempotencyKey);

      expect(attempt.kind, ActionAttemptKind.rejected);
      expect(attempt.message, 'تعذّر تنفيذ الطلب. أعد المحاولة لاحقاً.');
      await engine.sync(force: true);
      expect(actionRequests(server, '/stocktakes/4/complete'), hasLength(1));
    },
  );

  test(
    'a waiting scan holds its stocktake’s close, not other targets',
    () async {
      var scanFails = true;
      final server = FakeApi()
        ..onAction = (request) {
          if (request.path.endsWith('/scan') && scanFails) {
            return FakeApi.json({'code': 'SERVER'}, 503);
          }
          return null;
        };
      final engine = engineFor(server, db, queue, clock, outbox: outbox);

      await outbox.add(ActionRequest.scanStocktake(4, 'SKU-1', 3));
      await outbox.add(ActionRequest.completeStocktake(4));
      await outbox.add(ActionRequest.releaseTransfer(8));

      await engine.sync();

      expect(actionRequests(server, '/stocktakes/4/scan'), hasLength(1));
      expect(
        actionRequests(server, '/stocktakes/4/complete'),
        isEmpty,
        reason: 'closing the count before its scan lands would lose the scan',
      );
      expect(
        actionRequests(server, '/vehicle-transfers/8/release'),
        hasLength(1),
      );

      scanFails = false;
      await engine.sync(force: true);
      final order = server.requests
          .where((r) => r.path.contains('/stocktakes/4/'))
          .map((r) => r.path.split('/').last)
          .toList();
      expect(order, ['scan', 'scan', 'complete']);
    },
  );

  test(
    'a refusal seen live is dismissed; one found in the background waits',
    () async {
      final server = FakeApi()
        ..onAction = (_) =>
            FakeApi.json({'message': 'العهدة ليست بانتظار الاستلام.'}, 422);
      final engine = engineFor(server, db, queue, clock, outbox: outbox);

      final live = await outbox.add(ActionRequest.acceptCustody(1, 'أ'));
      final attempt = await engine.trySendAction(live.idempotencyKey);
      expect(attempt.kind, ActionAttemptKind.rejected);
      expect(attempt.message, 'العهدة ليست بانتظار الاستلام.');
      expect(
        (await outbox.find(live.idempotencyKey))!.status,
        ActionStatus.dismissed,
      );

      final background = await outbox.add(ActionRequest.acceptCustody(2, 'ب'));
      final outcome = (await engine.sync())!;
      expect(outcome.actionsRejected, 1);
      final row = (await outbox.find(background.idempotencyKey))!;
      expect(row.status, ActionStatus.failed);
      expect(row.lastError, 'العهدة ليست بانتظار الاستلام.');

      // Retry keeps the key.
      await outbox.retry(background.idempotencyKey);
      expect(
        (await outbox.pending()).single.idempotencyKey,
        background.idempotencyKey,
      );
    },
  );

  test(
    'the inspection photo waits encrypted and is sent as plaintext',
    () async {
      final server = FakeApi()..offline = true;
      final engine = engineFor(server, db, queue, clock, outbox: outbox);
      final photo = Uint8List.fromList(utf8.encode('JPEG-BYTES-OF-THE-VAN'));

      final action = await outbox.add(
        ActionRequest.vehicleInspection(
          odometerKm: 1234.5,
          checklist: {'tires': true, 'brakes': false},
          defects: ' ضوء خلفي ',
          photo: photo,
        ),
      );
      await engine.trySendAction(action.idempotencyKey);

      final stored = await File(action.attachmentPath!).readAsBytes();
      expect(
        utf8.decode(stored, allowMalformed: true),
        isNot(contains('JPEG-BYTES')),
      );

      server.offline = false;
      await engine.sync();

      final sent = actionRequests(server, '/fleet/vehicle/inspection').last;
      expect(sent.headers['idempotency-key'], action.idempotencyKey);
      final body = utf8.decode(sent.bodyBytes, allowMalformed: true);
      expect(body, contains('JPEG-BYTES-OF-THE-VAN'));
      expect(body, contains('name="odometer_km"'));
      expect(body, contains('name="brakes"'));
      expect(await File(action.attachmentPath!).exists(), isFalse);
    },
  );

  test('the X-App-Version header travels on every request (C7)', () async {
    final server = FakeApi();
    final engine = engineFor(server, db, queue, clock, outbox: outbox);
    engine.api.appVersion = '0.2.0+2';

    await queue.enqueue(visitId: 1, eventType: 'note.added');
    await outbox.add(ActionRequest.acceptTransfer(1));
    await engine.sync(pull: true);

    expect(server.requests, isNotEmpty);
    expect(server.requests.map((r) => r.headers['x-app-version']).toSet(), {
      '0.2.0+2',
    });
  });
}
