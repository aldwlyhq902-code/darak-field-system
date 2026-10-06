import 'dart:io';
import 'dart:typed_data';

import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/image_limits.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Contract addition 7: the server refuses images over 40 MP or 12000 px on
/// a side. The app must never stage one.
Uint8List png(int width, int height) {
  final bytes = Uint8List(33);
  bytes.setAll(0, [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]);
  ByteData.sublistView(bytes)
    ..setUint32(8, 13)
    ..setUint32(16, width)
    ..setUint32(20, height);
  bytes.setAll(12, 'IHDR'.codeUnits);
  return bytes;
}

Uint8List jpeg(int width, int height) => Uint8List.fromList([
  0xFF, 0xD8, // SOI
  0xFF, 0xE0, 0x00, 0x04, 0x00, 0x00, // APP0 with 2 bytes of payload
  0xFF, 0xC0, 0x00, 0x11, 0x08, // SOF0, length 17, precision 8
  height >> 8, height & 0xFF, width >> 8, width & 0xFF,
  0x03, 0, 0, 0, 0, 0, 0, 0, 0, 0,
  0xFF, 0xD9,
]);

void main() {
  test('dimensions are read from PNG and JPEG headers', () {
    expect(ImageLimits.dimensions(png(1600, 1200)), (
      width: 1600,
      height: 1200,
    ));
    expect(ImageLimits.dimensions(jpeg(4032, 3024)), (
      width: 4032,
      height: 3024,
    ));
    expect(ImageLimits.dimensions(Uint8List.fromList([1, 2, 3])), isNull);
  });

  test('the server limits: 12000 px per side and 40 MP', () {
    expect(ImageLimits.withinServerLimits(12000, 3000), isTrue);
    expect(ImageLimits.withinServerLimits(12001, 100), isFalse);
    expect(ImageLimits.withinServerLimits(8000, 5001), isFalse);
    expect(
      ImageLimits.withinServerLimits(
        ImageLimits.captureMaxSide,
        ImageLimits.captureMaxSide,
      ),
      isTrue,
      reason: 'the camera cap is far inside the limit',
    );
  });

  test(
    'an oversized image is refused at staging, before anything is queued',
    () async {
      sqfliteFfiInit();
      databaseFactory = databaseFactoryFfi;
      SharedPreferences.setMockInitialValues({});
      final db = await LocalDb.open(path: inMemoryDatabasePath);
      final clock = await TrustedClock.load();
      final queue = EventQueue(db, clock);
      final root = await Directory.systemTemp.createTemp('darak_limits');
      addTearDown(() async {
        await db.raw.close();
        await root.delete(recursive: true);
      });
      final store = EvidenceStore(
        db: db,
        queue: queue,
        clock: clock,
        rootDirectory: root.path,
      );

      await expectLater(
        store.store(
          visitId: 1,
          kind: EvidenceStore.kindPhotoAfter,
          bytes: jpeg(13000, 900),
        ),
        throwsArgumentError,
      );
      expect(await queue.pending(), isEmpty);

      await store.store(
        visitId: 1,
        kind: EvidenceStore.kindPhotoAfter,
        bytes: jpeg(1600, 1200),
      );
      expect(await queue.pending(), hasLength(1));
    },
  );
}
