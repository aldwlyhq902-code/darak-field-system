import 'dart:io';
import 'dart:typed_data';

import 'package:darak_field/core/device_wipe.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_cipher.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/secure_token_store.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Contract C1's remote wipe, and sign-out (finding 22): nothing of the
/// previous user survives except the device UUID.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  test('everything goes except the device identity and language', () async {
    SharedPreferences.setMockInitialValues({
      'device_uuid': 'device-1',
      'ui_locale': 'en',
      'cache_whatever': 'x',
    });
    FlutterSecureStorage.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final tokens = SecureTokenStore();
    await tokens.writeToken('token');
    await tokens.writeUserId(7);
    await tokens.writeTechnicianName('فني');
    final databaseKey = await tokens.readOrCreateDatabaseKey();
    final evidenceKey = await tokens.readOrCreateEvidenceKey();

    final documents = await Directory.systemTemp.createTemp('darak_docs');
    final cache = await Directory.systemTemp.createTemp('darak_cache');
    addTearDown(() async {
      for (final dir in [documents, cache]) {
        if (await dir.exists()) await dir.delete(recursive: true);
      }
    });

    final db = await LocalDb.open(
      path: p.join(documents.path, LocalDb.fileName),
    );
    final clock = await TrustedClock.load(prefs: prefs);
    await clock.adopt(DateTime.utc(2026, 10, 6, 9));
    final queue = EventQueue(db, clock);
    final evidence = EvidenceStore(
      db: db,
      queue: queue,
      clock: clock,
      rootDirectory: documents.path,
      cipher: EvidenceCipher(evidenceKey),
    );
    await evidence.store(
      visitId: 1,
      kind: EvidenceStore.kindSignature,
      bytes: Uint8List.fromList([1, 2, 3]),
      mime: 'image/png',
      declaredSource: 'on_screen',
    );
    for (var i = 0; i < 4; i++) {
      await queue.enqueue(visitId: 1, eventType: 'note.added');
    }
    await Directory(p.join(documents.path, 'outbox')).create();
    await File(
      p.join(documents.path, 'outbox', 'x.bin.enc'),
    ).writeAsString('x');
    await File(p.join(cache.path, 'image_picker_123.jpg')).writeAsString('jpg');

    await DeviceWipe.run(
      db: db,
      documentsDirectory: documents.path,
      cacheDirectory: cache.path,
      tokens: tokens,
      prefs: prefs,
    );

    expect(
      await File(p.join(documents.path, LocalDb.fileName)).exists(),
      isFalse,
    );
    expect(
      await Directory(p.join(documents.path, 'evidence')).exists(),
      isFalse,
    );
    expect(await Directory(p.join(documents.path, 'outbox')).exists(), isFalse);
    expect(await cache.list().toList(), isEmpty);

    expect(await tokens.readToken(), isNull);
    expect(await tokens.readUserId(), isNull);
    expect(await tokens.readTechnicianName(), isNull);
    expect(await tokens.readOrCreateDatabaseKey(), isNot(databaseKey));
    expect(await tokens.readOrCreateEvidenceKey(), isNot(evidenceKey));

    expect(prefs.getString('device_uuid'), 'device-1');
    expect(prefs.getString('ui_locale'), 'en');
    expect(prefs.getString('cache_whatever'), isNull);
    expect(prefs.getString('trusted_server_time'), isNull);

    // A fresh database continues the device's event sequence.
    final fresh = await LocalDb.open(path: inMemoryDatabasePath);
    await DeviceWipe.restoreSequenceFloor(fresh, prefs);
    expect(await fresh.nextSequence(), 6, reason: '1 registration + 4 notes');
    await fresh.raw.close();
  });
}
