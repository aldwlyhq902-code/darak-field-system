import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Evidence capture is the step where a lost byte means a visit cannot be proven.
/// These tests pin the ordering: file on disk and queue row first, upload later.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late EventQueue queue;
  late EvidenceStore evidence;
  late Directory root;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    final clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    root = await Directory.systemTemp.createTemp('darak_evidence_test');
    evidence = EvidenceStore(db: db, queue: queue, clock: clock, rootDirectory: root.path);
  });

  tearDown(() async {
    await db.raw.close();
    if (await root.exists()) await root.delete(recursive: true);
  });

  Uint8List bytes(int length) =>
      Uint8List.fromList(List<int>.generate(length, (i) => (i * 7) % 256));

  test('a capture is on disk and queued before any network call', () async {
    final payload = bytes(2048);

    final id = await evidence.store(
      visitId: 5,
      kind: EvidenceStore.kindPhotoAfter,
      bytes: payload,
      assetId: 11,
      lat: 21.58,
      lng: 39.16,
    );

    final rows = await evidence.forVisit(5);
    expect(rows, hasLength(1));
    expect(rows.first['client_media_id'], id);
    expect(rows.first['state'], 'pending');
    expect(rows.first['total_bytes'], 2048);

    expect(await File(rows.first['local_path'] as String).exists(), isTrue);

    // The same UUID is in the outbound queue, so the server can tie the uploaded
    // bytes to the visit even if the two arrive out of order.
    final pending = await queue.pending();
    expect(pending, hasLength(1));
    expect(pending.first.eventType, 'media.register');
    expect(pending.first.payload['client_media_id'], id);
    expect(pending.first.payload['asset_id'], 11);
  });

  test('the hash is computed at capture time, not at upload time', () async {
    final payload = bytes(512);
    final expected = sha256.convert(payload).toString();

    await evidence.store(visitId: 5, kind: EvidenceStore.kindPhotoBefore, bytes: payload);

    final rows = await evidence.forVisit(5);
    expect(rows.first['sha256'], expected);
  });

  test('a signature is recorded as its own kind', () async {
    expect(await evidence.hasSignature(9), isFalse);

    await evidence.store(
      visitId: 9,
      kind: EvidenceStore.kindSignature,
      bytes: bytes(300),
      mime: 'image/png',
      declaredSource: 'on_screen',
    );

    expect(await evidence.hasSignature(9), isTrue);
    expect(await evidence.photoCount(9), 0, reason: 'a signature is not a photo');
  });

  test('captures are counted per visit', () async {
    await evidence.store(visitId: 1, kind: EvidenceStore.kindPhotoBefore, bytes: bytes(100));
    await evidence.store(visitId: 1, kind: EvidenceStore.kindPhotoAfter, bytes: bytes(100));
    await evidence.store(visitId: 2, kind: EvidenceStore.kindPhotoAfter, bytes: bytes(100));

    expect(await evidence.photoCount(1), 2);
    expect(await evidence.photoCount(2), 1);
  });

  test('pruning removes uploaded files and never touches pending ones', () async {
    await evidence.store(visitId: 3, kind: EvidenceStore.kindPhotoAfter, bytes: bytes(400));
    await evidence.store(visitId: 3, kind: EvidenceStore.kindPhotoAfter, bytes: bytes(400));

    final rows = await evidence.forVisit(3);
    await db.raw.update(
      'pending_media',
      {'state': 'complete'},
      where: 'client_media_id = ?',
      whereArgs: [rows.first['client_media_id']],
    );

    final removed = await evidence.pruneUploaded(3);

    expect(removed, 1);
    expect(await File(rows.first['local_path'] as String).exists(), isFalse);
    expect(await File(rows.last['local_path'] as String).exists(), isTrue,
        reason: 'unsent evidence is never deleted');
  });

  test('each capture gets its own id even for identical bytes', () async {
    final payload = bytes(64);

    final first = await evidence.store(visitId: 4, kind: EvidenceStore.kindPhotoAfter, bytes: payload);
    final second = await evidence.store(visitId: 4, kind: EvidenceStore.kindPhotoAfter, bytes: payload);

    expect(first, isNot(second));
    expect(await evidence.forVisit(4), hasLength(2));
  });
}
