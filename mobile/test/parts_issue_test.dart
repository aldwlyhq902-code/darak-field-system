import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/field_recorder.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/parts.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/fake_api.dart';

/// The phone could not record a part at all: the close gate asked "parts or
/// none?" and "none" was the only answer it had. And "none", once declared,
/// was silently withdrawn by the next asset status tap.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late FieldRecorder recorder;

  const capacitor = VanPart(
    partId: 5,
    name: 'مكثف 35 ميكروفاراد',
    qty: 3,
    sku: 'CAP-35',
    qrCode: 'PART-CAP-35',
    unit: 'pcs',
  );

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    db = await LocalDb.open(path: inMemoryDatabasePath);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    recorder = FieldRecorder(db: db, queue: queue, progress: VisitProgress(db));
    await db.replaceVisits([
      {
        'id': 7,
        'state': 'started',
        'site': {'name': 'فرع'},
        'work_order': {'title': 'صيانة'},
        'assets': [
          {'id': 11, 'name': 'سبليت', 'type': 'split_ac'},
        ],
      },
    ]);
  });

  tearDown(() async => db.raw.close());

  Future<QueuedEvent> issue(double qty) => recorder.issuePart(
    7,
    partId: capacitor.partId,
    qty: qty,
    fromLocationId: 40,
    idempotencyKey: '6f1d2c3b-4a59-4e6f-8a7b-9c0d1e2f3a4b',
  );

  test('an issue queues the payload the server validates', () async {
    final event = await issue(2);

    expect(event.eventType, 'part.issue');
    expect(event.payload, {
      'part_id': 5,
      'qty': 2.0,
      'from_location_id': 40,
      'idempotency_key': '6f1d2c3b-4a59-4e6f-8a7b-9c0d1e2f3a4b',
    });
    final issued = await recorder.issuedParts(7);
    expect(issued.single.qty, 2);
    expect(issued.single.unsent, isTrue);
  });

  test(
    'issuing withdraws an earlier "no parts used" in the same step',
    () async {
      await recorder.declareNoParts(7, true);
      expect(await recorder.noPartsDeclared(7), isTrue);

      await issue(1);

      expect(await recorder.noPartsDeclared(7), isFalse);
      final declarations = (await queue.pending())
          .where((e) => e.eventType == 'parts.declaration')
          .map((e) => e.payload['no_parts_used'])
          .toList();
      expect(declarations, [true, false], reason: 'the server hears it too');
    },
  );

  test('available stock subtracts issues not yet on the server', () async {
    final first = await issue(2);
    expect(availableInVan(capacitor, await recorder.unsentIssues()), 1);

    await queue.markSynced([first.clientEventId]);
    // Once synced, the next refresh's van balance already counts it.
    expect(availableInVan(capacitor, await recorder.unsentIssues()), 3);
  });

  test('an unsent issue can be withdrawn; a sent one cannot', () async {
    final unsent = await issue(1);
    expect(await recorder.withdrawIssue(unsent.clientEventId), isTrue);
    expect(await recorder.issuedParts(7), isEmpty);
    expect((await db.unsyncedCounts()).events, 0);

    final sent = await issue(1);
    await queue.markSynced([sent.clientEventId]);
    expect(await recorder.withdrawIssue(sent.clientEventId), isFalse);
    expect((await recorder.issuedParts(7)).single.unsent, isFalse);
  });

  test('a declaration made before any asset status survives', () async {
    await recorder.declareNoParts(7, true);
    // The status tap used to write no_parts_used = false over it.
    await recorder.setChecklist(7, 11, status: 'ok');

    expect(await recorder.noPartsDeclared(7), isTrue);
    final upsert = (await queue.pending()).lastWhere(
      (e) => e.eventType == 'checklist.upsert',
    );
    expect(upsert.payload['no_parts_used'], isTrue);
  });

  test(
    'bootstrap stores the van and a server without it changes nothing',
    () async {
      final server = FakeApi();
      final engine = engineFor(server, db, queue, clock);

      server.bootstrapVisits = const [];
      await engine.bootstrap();
      expect(await VehicleStock.load(db), isNull);

      await VehicleStock.store(db, {
        'location_id': 40,
        'name': 'سيارة 1',
        'parts': [
          {
            'part_id': 5,
            'sku': 'CAP-35',
            'qr_code': 'PART-CAP-35',
            'name': 'مكثف',
            'unit': 'pcs',
            'qty': 3,
          },
        ],
      });
      final stock = await VehicleStock.load(db);
      expect(stock!.locationId, 40);
      expect(stock.parts.single.matchesCode('part-cap-35'), isTrue);
      expect(stock.parts.single.matchesCode('CAP-35'), isTrue);
      expect(stock.parts.single.matchesCode('OTHER'), isFalse);

      // FakeApi's bootstrap carries no vehicle_stock key: an older server.
      await engine.bootstrap();
      expect((await VehicleStock.load(db))!.locationId, 40);
    },
  );
}
