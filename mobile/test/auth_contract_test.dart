import 'dart:convert';

import 'package:darak_field/core/api_client.dart';
import 'package:darak_field/core/error_messages.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/login_session.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/l10n/app_localizations.dart';
import 'package:flutter/widgets.dart' show Locale;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/fake_api.dart';

/// Contract C1 (and review finding 6): which refusals end the session, which
/// wipe the device, and which refuse only the one request.
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

  group('sync', () {
    test('an ordinary 403 refuses the request, not the session', () async {
      final server = FakeApi()
        ..failWith = 403
        ..failBody = {'code': 'DEVICE_MISMATCH', 'message': 'nope'};
      final engine = engineFor(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'note.added');
      final outcome = (await engine.sync())!;

      expect(outcome.sessionExpired, isFalse, reason: 'no logout on a 403');
      expect(outcome.remoteWipe, isFalse);
      expect(outcome.error, 'هذا الجهاز مسجل لمستخدم آخر.');
      expect(await queue.failed(), isEmpty);
      expect(await queue.pending(), hasLength(1));
    });

    test('403 DEVICE_REVOKED asks for the remote wipe', () async {
      final server = FakeApi()
        ..failWith = 403
        ..failBody = {'code': 'DEVICE_REVOKED', 'message': 'revoked'};
      final engine = engineFor(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'note.added');
      final outcome = (await engine.sync())!;

      expect(outcome.remoteWipeCode, 'DEVICE_REVOKED');
      expect(outcome.sessionExpired, isFalse);
    });

    test('403 USER_INACTIVE during an upload also stops and wipes', () async {
      final server = FakeApi()
        ..onMedia = (_, _) => FakeApi.json({'code': 'USER_INACTIVE'}, 403);
      final engine = engineFor(server, db, queue, clock);
      await db.raw.insert('pending_media', {
        'client_media_id': 'm',
        'visit_id': 7,
        'kind': 'photo_after',
        'local_path': 'test/support/fake_api.dart',
        'total_bytes': 1,
        'state': 'pending',
      });

      final outcome = (await engine.sync())!;

      expect(outcome.remoteWipeCode, 'USER_INACTIVE');
    });

    test('401 keeps the queue and reports the session as dead', () async {
      final server = FakeApi()..failWith = 401;
      final engine = engineFor(server, db, queue, clock);

      await queue.enqueue(visitId: 7, eventType: 'note.added');
      final outcome = (await engine.sync())!;

      expect(outcome.sessionExpired, isTrue);
      expect(await queue.pending(), hasLength(1));
    });
  });

  group('api client', () {
    ApiClient client(http.Client inner) =>
        ApiClient(baseUrl: 'https://test.local', client: inner)
          ..token = 'token';

    test(
      'auth failures are reported from any screen, others are not',
      () async {
        final reported = <String>[];
        final responses = <(int, String)>[
          (401, 'UNAUTHENTICATED'),
          (403, 'DEVICE_REVOKED'),
          (403, 'USER_INACTIVE'),
          (403, 'FORBIDDEN'),
          (404, 'NOT_FOUND'),
        ];
        var index = 0;
        final api = client(
          MockClient((_) async {
            final (status, code) = responses[index++];
            return http.Response(jsonEncode({'code': code}), status);
          }),
        )..onAuthFailure = (e) => reported.add(e.code);

        for (var i = 0; i < responses.length; i++) {
          await expectLater(api.custodies(), throwsA(isA<ApiException>()));
        }

        expect(reported, [
          'UNAUTHENTICATED',
          'DEVICE_REVOKED',
          'USER_INACTIVE',
        ]);
      },
    );

    test(
      'a proxy HTML error page is a server error, not "no network"',
      () async {
        final api = client(
          MockClient(
            (_) async => http.Response('<html>Bad Gateway</html>', 502),
          ),
        );

        try {
          await api.bootstrap();
          fail('expected an error');
        } on ApiException catch (e) {
          expect(e.statusCode, 502);
          expect(e.isConnectivity, isFalse);
          expect(e.isServerTransient, isTrue);
        }
      },
    );

    test('a dropped socket becomes a connectivity ApiException', () async {
      final api = client(
        MockClient((request) async {
          throw http.ClientException('Connection reset', request.url);
        }),
      );

      try {
        await api.bootstrap();
        fail('expected an error');
      } on ApiException catch (e) {
        expect(e.isConnectivity, isTrue);
        expect(e.code, 'NETWORK');
      }
    });

    test(
      'login sends version and integrity, and adopts no token itself',
      () async {
        late Map<String, dynamic> sent;
        final api = ApiClient(
          baseUrl: 'https://test.local',
          appVersion: '0.2.0+2',
          client: MockClient((request) async {
            sent = jsonDecode(request.body) as Map<String, dynamic>;
            expect(request.headers['x-app-version'], '0.2.0+2');
            return http.Response(
              jsonEncode({
                'token': 't',
                'user': {'id': 1},
              }),
              200,
            );
          }),
        );

        await api.login(
          email: 'a@b.c',
          password: 'p',
          deviceUuid: 'u',
          appVersion: '0.2.0+2',
          deviceIntegrity: {'rooted': false, 'emulator': true},
        );

        expect(sent['app_version'], '0.2.0+2');
        expect(sent['device_integrity'], {'rooted': false, 'emulator': true});
        expect(api.token, isNull);
      },
    );
  });

  group('contract additions', () {
    test('a locked account says how many minutes remain, rounded up', () async {
      final api = ApiClient(
        baseUrl: 'https://test.local',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({
              'code': 'ACCOUNT_TEMPORARILY_LOCKED',
              'retry_after': 125,
            }),
            429,
          ),
        ),
      );

      try {
        await api.login(email: 'a@b.c', password: 'x', deviceUuid: 'u');
        fail('expected a lockout');
      } on ApiException catch (e) {
        expect(e.retryAfter, const Duration(seconds: 125));
        final message = friendlyError(e);
        expect(message, contains('3 دقيقة'));
        expect(
          const AppLocalizations(Locale('en')).translate(message),
          contains('Try again in 3 minute(s)'),
        );
      }
    });

    test(
      'DEVICE_PENDING_APPROVAL on a protected route ends the session only',
      () async {
        final reported = <ApiException>[];
        final api =
            ApiClient(
                baseUrl: 'https://test.local',
                client: MockClient(
                  (_) async => http.Response(
                    jsonEncode({'code': 'DEVICE_PENDING_APPROVAL'}),
                    403,
                  ),
                ),
              )
              ..token = 't'
              ..onAuthFailure = reported.add;

        await expectLater(api.bootstrap(), throwsA(isA<ApiException>()));

        expect(reported.single.isPendingApproval, isTrue);
        expect(reported.single.isRemoteWipe, isFalse);
        expect(friendlyError(reported.single), devicePendingApprovalMessage);
      },
    );
  });

  group('login response (finding 15)', () {
    test('a response without a token is rejected, not cast', () {
      expect(
        LoginSession.parse({
          'user': {'id': 1},
        }),
        isNull,
      );
      expect(
        LoginSession.parse({
          'token': '',
          'user': {'id': 1},
        }),
        isNull,
      );
      expect(LoginSession.parse({'token': 't'}), isNull);
      expect(
        LoginSession.parse({
          'token': 't',
          'user': {'name': 'x'},
        }),
        isNull,
      );
    });

    test('a well-formed response yields the session', () {
      final session = LoginSession.parse({
        'token': 'abc',
        'user': {'id': 42, 'name': 'فني'},
      })!;
      expect(session.token, 'abc');
      expect(session.userId, 42);
      expect(session.name, 'فني');
    });
  });

  group('queue bound to its author (finding 23, C6)', () {
    test(
      'another technician’s unsynced work blocks a different login',
      () async {
        await queue.enqueue(
          visitId: 7,
          eventType: 'note.added',
          actorUserId: 5,
        );
        await db.raw.insert('pending_actions', {
          'idempotency_key': 'k',
          'kind': 'custody.accept',
          'path': '/x',
          'status': 'pending',
          'actor_user_id': 5,
          'created_at': DateTime.now().toIso8601String(),
        });

        expect((await db.unsyncedCounts(foreignTo: 5)).isEmpty, isTrue);
        final foreign = await db.unsyncedCounts(foreignTo: 6);
        expect(foreign.events, 1);
        expect(foreign.actions, 1);
        expect(foreign.total, 2);
      },
    );

    test(
      'rows that predate authorship are adopted by the signed-in user',
      () async {
        await queue.enqueue(visitId: 7, eventType: 'note.added');

        await db.adoptOrphans(9);

        expect((await queue.pending()).single.toWire()['actor_user_id'], 9);
      },
    );
  });
}
