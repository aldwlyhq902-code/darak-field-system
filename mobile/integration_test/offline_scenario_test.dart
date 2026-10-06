import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:darak_field/core/action_outbox.dart';
import 'package:darak_field/core/device_platform.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_cipher.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/field_recorder.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/location.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../test/support/fake_api.dart';

/// The scenario the product exists for, on a real Android device: real
/// SQLCipher, the real elapsedRealtime/boot-count channel, real files —
/// against a fake server that is unreachable for "the whole day", then back.
///
///     flutter test integration_test/offline_scenario_test.dart -d <device>
void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('a day with no signal loses nothing, fails nothing, then syncs once', (
    tester,
  ) async {
    final documents = await getApplicationDocumentsDirectory();
    final root = Directory(
      '${documents.path}/offline_it_${DateTime.now().microsecondsSinceEpoch}',
    )..createSync(recursive: true);
    addTearDown(() => root.deleteSync(recursive: true));

    // --- the platform channel (finding 8, C4) -------------------------------
    const platform = DevicePlatform();
    final native = await platform.clock();
    expect(native, isNotNull, reason: 'MainActivity clock channel');
    expect(native!.elapsedRealtimeMs, greaterThan(0));
    expect(native.bootCount, isNotNull);
    final integrity = await platform.integrity();
    expect(integrity, isNotNull);
    debugPrint('device integrity: $integrity');
    await platform.setSecureScreen(true);

    // --- the stack, as AppState assembles it --------------------------------
    final prefs = await SharedPreferences.getInstance();
    final db = await LocalDb.open(
      directory: root.path,
      password: 'integration-test-key-${Random.secure().nextInt(1 << 30)}',
    );
    addTearDown(db.close);
    final clock = await TrustedClock.load(prefs: prefs);
    await clock.adopt(DateTime.now().toUtc());
    final queue = EventQueue(db, clock);
    final cipher = EvidenceCipher(
      List<int>.generate(32, (_) => Random.secure().nextInt(256)),
    );
    final evidence = EvidenceStore(
      db: db,
      queue: queue,
      clock: clock,
      rootDirectory: root.path,
      cipher: cipher,
    );
    final progress = VisitProgress(db, now: () => clock.bestEstimate);
    final recorder = FieldRecorder(db: db, queue: queue, progress: progress);
    final outbox = ActionOutbox(db, attachmentRoot: root.path, cipher: cipher);

    final server = FakeApi()..offline = true;
    final engine = engineFor(
      server,
      db,
      queue,
      clock,
      evidence: evidence,
      outbox: outbox,
      root: root.path,
      chunkSize: 64 * 1024,
    );

    Map<String, dynamic> serverVisit(String state) => {
      'id': 7,
      'state': state,
      'site': {
        'name': 'فرع السلامة',
        'lat': 21.5,
        'lng': 39.2,
        'geofence_radius_m': 150,
      },
      'work_order': {'title': 'صيانة وقائية'},
      'assets': [
        {'id': 11, 'name': 'سبليت الصالة'},
        {'id': 12, 'name': 'سبليت المطبخ'},
      ],
    };
    await db.replaceVisits([serverVisit('scheduled')]);

    // --- the day, with no signal --------------------------------------------
    const here = LocationFix(lat: 21.5001, lng: 39.2001, accuracyM: 8);
    final random = Random(7);
    final photos = <String, Uint8List>{};

    await recorder.transition(7, 'en_route', location: here, actorUserId: 5);
    await recorder.transition(7, 'started', location: here, actorUserId: 5);
    for (final asset in [11, 12]) {
      await recorder.setChecklist(7, asset, status: 'ok', actorUserId: 5);
      for (var i = 0; i < 3; i++) {
        final bytes = Uint8List.fromList(
          List<int>.generate(150 * 1024 + i, (_) => random.nextInt(256)),
        );
        final id = await evidence.store(
          visitId: 7,
          kind: EvidenceStore.kindPhotoAfter,
          bytes: bytes,
          assetId: asset,
          actorUserId: 5,
        );
        photos[id] = bytes;
      }
    }
    await recorder.declareNoParts(7, true, actorUserId: 5);
    await recorder.transition(
      7,
      'awaiting_close',
      location: here,
      actorUserId: 5,
    );
    await recorder.transition(7, 'completed', location: here, actorUserId: 5);
    await outbox.add(ActionRequest.acceptCustody(3, 'فني'), actorUserId: 5);

    final recorded = (await queue.pending(limit: 1000)).length;

    // Every tap fired a sync into the void, and the technician pulled to
    // refresh a few times as well.
    for (var i = 0; i < 12; i++) {
      await engine.sync(force: i.isEven, pull: true);
    }

    final media = await db.raw.query('pending_media');
    expect(media, hasLength(6));
    expect(media.map((m) => m['state']), everyElement(isNot('failed')));
    expect(media.map((m) => m['attempts']), everyElement(0));
    expect(await queue.failed(), isEmpty);
    expect(await queue.pending(limit: 1000), hasLength(recorded));
    expect(
      (await db.visit(7))!['state'],
      'completed',
      reason: 'not rolled back',
    );
    for (final row in media) {
      final onDisk = await File(row['local_path'] as String).readAsBytes();
      expect(EvidenceCipher.looksEncrypted(onDisk), isTrue);
    }

    // --- the app is killed and relaunched in the same boot ------------------
    final relaunched = await TrustedClock.load(prefs: prefs);
    expect(
      (await relaunched.sample()).monotonicOffsetMs,
      isNotNull,
      reason: 'the anchor survives process death on a real device',
    );

    // --- signal returns -------------------------------------------------------
    server
      ..offline = false
      ..bootstrapVisits = [serverVisit('completed')];
    final outcome = (await engine.sync(pull: true))!;

    expect(outcome.error, isNull);
    expect(outcome.pulled, isTrue);
    expect(await queue.pending(limit: 1000), isEmpty);
    expect(await queue.failed(), isEmpty);
    expect(
      server.acceptedEventIds,
      hasLength(recorded),
      reason: 'exactly once',
    );
    for (final MapEntry(key: id, value: bytes) in photos.entries) {
      expect(
        server.uploads[id]!.toBytes(),
        bytes,
        reason: 'plaintext uploaded',
      );
      expect(server.completedHashes[id], sha256.convert(bytes).toString());
    }
    expect(await outbox.pending(), isEmpty);

    // The close went out only after the last photo had landed.
    final order = server.requests.map((r) => r.path).toList();
    final lastComplete = order.lastIndexWhere((p) => p.endsWith('/complete'));
    final closeRequest = server.eventRequests.lastWhere(
      (r) => (r.json['events'] as List).any(
        (e) => (e as Map)['payload']?['to'] == 'completed',
      ),
    );
    expect(server.requests.indexOf(closeRequest), greaterThan(lastComplete));
    expect((await db.visit(7))!['state'], 'completed');
  });
}
