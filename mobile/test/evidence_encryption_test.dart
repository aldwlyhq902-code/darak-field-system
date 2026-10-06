import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_cipher.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/fake_api.dart';

/// Review finding 17: evidence encrypted at rest, uploaded as plaintext.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  final key = List<int>.generate(32, (i) => (i * 7 + 1) % 256);
  final random = Random(42);
  Uint8List bytes(int length) => Uint8List.fromList(
    List<int>.generate(length, (_) => random.nextInt(256)),
  );

  late Directory root;

  setUp(() async {
    root = await Directory.systemTemp.createTemp('darak_cipher_test');
  });

  tearDown(() async {
    if (await root.exists()) await root.delete(recursive: true);
  });

  group('cipher', () {
    test(
      'any plaintext range can be read back from the encrypted file',
      () async {
        final cipher = EvidenceCipher(key, segmentSize: 1000);
        final plain = bytes(4321);
        final file = File('${root.path}/x.enc');
        await cipher.encryptToFile(plain, file);

        final reader = await cipher.open(file);
        expect(reader.length, plain.length);
        for (final (offset, length) in [
          (0, 4321),
          (0, 1),
          (999, 2),
          (1000, 1000),
          (2500, 1700),
          (4320, 50),
        ]) {
          final end = min(offset + length, plain.length);
          expect(
            await reader.read(offset, length),
            plain.sublist(offset, end),
            reason: 'range $offset+$length',
          );
        }
        expect(await reader.sha256Hex(), sha256.convert(plain).toString());
        await reader.close();
      },
    );

    test('the bytes on disk reveal nothing of the plaintext', () async {
      final cipher = EvidenceCipher(key);
      final plain = Uint8List.fromList(
        List<int>.generate(5000, (i) => 'SIGNATURE-'.codeUnitAt(i % 10)),
      );
      final sealed = cipher.encrypt(plain);

      expect(String.fromCharCodes(sealed), isNot(contains('SIGNATURE-')));
      expect(EvidenceCipher.looksEncrypted(sealed), isTrue);
      expect(EvidenceCipher.looksEncrypted(plain), isFalse);
    });

    test('tampering, truncation and a wrong key are all detected', () async {
      final cipher = EvidenceCipher(key, segmentSize: 64);
      final plain = bytes(300);
      final file = File('${root.path}/x.enc');

      final sealed = cipher.encrypt(plain);
      sealed[EvidenceCipher.headerLength + 70] ^= 1;
      await file.writeAsBytes(sealed);
      await expectLater(cipher.decryptFile(file), throwsA(anything));

      final truncated = cipher.encrypt(plain);
      await file.writeAsBytes(
        truncated.sublist(0, truncated.length - (64 + 16)),
      );
      await expectLater(cipher.decryptFile(file), throwsA(anything));

      await file.writeAsBytes(cipher.encrypt(plain));
      final stranger = EvidenceCipher(List<int>.filled(32, 9));
      await expectLater(stranger.decryptFile(file), throwsA(anything));
      expect(await cipher.decryptFile(file), plain);
    });
  });

  group('evidence store and uploader', () {
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

    test(
      'a capture is stored encrypted and uploaded as its plaintext',
      () async {
        final store = EvidenceStore(
          db: db,
          queue: queue,
          clock: clock,
          rootDirectory: root.path,
          cipher: EvidenceCipher(key),
        );
        final photo = bytes(700 * 1024 + 13);

        final id = await store.store(
          visitId: 7,
          kind: EvidenceStore.kindPhotoAfter,
          bytes: photo,
        );
        final row = (await store.forVisit(7)).single;

        expect(row['encrypted'], 1);
        expect(row['sha256'], sha256.convert(photo).toString());
        final onDisk = await File(row['local_path'] as String).readAsBytes();
        expect(EvidenceCipher.looksEncrypted(onDisk), isTrue);
        expect(onDisk.length, isNot(photo.length));

        final server = FakeApi();
        await engineFor(server, db, queue, clock, evidence: store).sync();

        expect(server.uploads[id]!.toBytes(), photo);
        expect(server.completedHashes[id], sha256.convert(photo).toString());
        expect((await store.forVisit(7)).single['state'], 'complete');
      },
    );

    test(
      'an upload resumed mid-file sends the right plaintext bytes',
      () async {
        final store = EvidenceStore(
          db: db,
          queue: queue,
          clock: clock,
          rootDirectory: root.path,
          cipher: EvidenceCipher(key),
        );
        final photo = bytes(300 * 1024);
        final id = await store.store(
          visitId: 7,
          kind: EvidenceStore.kindPhotoAfter,
          bytes: photo,
        );

        // The first 100 000 bytes already landed before the link dropped.
        final server = FakeApi();
        server.uploads[id] = BytesBuilder()..add(photo.sublist(0, 100000));
        await engineFor(server, db, queue, clock, evidence: store).sync();

        expect(server.uploads[id]!.toBytes(), photo);
        expect(server.completedHashes[id], sha256.convert(photo).toString());
      },
    );

    test(
      'plaintext captures from older versions are encrypted in place',
      () async {
        final legacy = EvidenceStore(
          db: db,
          queue: queue,
          clock: clock,
          rootDirectory: root.path,
        );
        final photo = bytes(5000);
        await legacy.store(
          visitId: 3,
          kind: EvidenceStore.kindPhotoBefore,
          bytes: photo,
        );
        final before = (await legacy.forVisit(3)).single;
        final plainPath = before['local_path'] as String;
        expect(before['encrypted'], 0);

        final upgraded = EvidenceStore(
          db: db,
          queue: queue,
          clock: clock,
          rootDirectory: root.path,
          cipher: EvidenceCipher(key),
        );
        final result = await upgraded.reconcile();

        final after = (await upgraded.forVisit(3)).single;
        expect(result.encrypted, 1);
        expect(after['encrypted'], 1);
        expect(after['local_path'], '$plainPath.enc');
        expect(after['sha256'], sha256.convert(photo).toString());
        expect(await File(plainPath).exists(), isFalse);

        final reader = await upgraded.openReader(after);
        expect(await reader.read(0, reader.length), photo);
        await reader.close();

        // Idempotent: a second launch has nothing left to do.
        expect((await upgraded.reconcile()).encrypted, 0);
      },
    );
  });
}
