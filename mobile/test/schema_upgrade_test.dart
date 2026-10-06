import 'package:darak_field/core/local_db.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// The upgrade path, not just the fresh install.
///
/// The composite key on `assets` was added to the schema, but the database
/// version stayed at 1 with no onUpgrade — and `CREATE TABLE IF NOT EXISTS`
/// never alters a table that already exists. Every phone already carrying the
/// app would have kept the old single-column key and gone on losing one visit's
/// asset list whenever two visits shared a site. A fix that only reaches new
/// installs is not a fix, and only an upgrade test can tell the difference.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  /// The v1 schema, exactly as it shipped: `id` alone as the primary key.
  Future<void> createV1(String path) async {
    final db = await databaseFactory.openDatabase(
      path,
      options: OpenDatabaseOptions(version: 1),
    );

    await db.execute('''
      CREATE TABLE assets (
        id INTEGER PRIMARY KEY,
        visit_id INTEGER NOT NULL,
        name TEXT,
        type TEXT,
        location TEXT,
        qr_code TEXT,
        under_warranty INTEGER DEFAULT 0
      )
    ''');

    await db.insert('assets', {
      'id': 11,
      'visit_id': 7,
      'name': 'سبليت الصالة',
      'type': 'split_ac',
      'qr_code': 'A-11',
    });

    await db.close();
  }

  test('a v1 database keeps its rows and gains the composite key', () async {
    final path =
        '${await databaseFactory.getDatabasesPath()}/upgrade_${DateTime.now().microsecondsSinceEpoch}.db';

    await createV1(path);

    // Opening with the current code must migrate, not wipe: the phone may be
    // offline, and discarding the cache strips a technician mid-visit.
    final db = await LocalDb.open(path: path);

    final rows = await db.raw.query('assets');
    expect(
      rows,
      hasLength(1),
      reason: 'the cached asset must survive the upgrade',
    );
    expect(rows.first['name'], 'سبليت الصالة');

    // The whole point: the same asset can now belong to a second visit.
    await db.raw.insert('assets', {
      'id': 11,
      'visit_id': 8,
      'name': 'سبليت الصالة',
      'type': 'split_ac',
      'qr_code': 'A-11',
    });

    final forVisit7 = await db.raw.query(
      'assets',
      where: 'visit_id = ?',
      whereArgs: [7],
    );
    final forVisit8 = await db.raw.query(
      'assets',
      where: 'visit_id = ?',
      whereArgs: [8],
    );

    expect(
      forVisit7,
      hasLength(1),
      reason: 'the first visit must not lose its asset',
    );
    expect(forVisit8, hasLength(1));

    await db.raw.close();
  });

  test('a v2 database keeps its queue and gains the v3 columns', () async {
    final path =
        '${await databaseFactory.getDatabasesPath()}/upgrade_v2_${DateTime.now().microsecondsSinceEpoch}.db';

    // The v2 outbound tables exactly as they shipped in 0.2.0.
    final v2 = await databaseFactory.openDatabase(
      path,
      options: OpenDatabaseOptions(version: 2),
    );
    await v2.execute('''
      CREATE TABLE pending_events (
        client_event_id TEXT PRIMARY KEY, visit_id INTEGER NOT NULL,
        event_type TEXT NOT NULL, payload TEXT, device_timestamp TEXT NOT NULL,
        monotonic_offset_ms INTEGER, last_trusted_server_time TEXT,
        sequence INTEGER NOT NULL, lat REAL, lng REAL, source TEXT,
        status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT, created_at TEXT NOT NULL)
    ''');
    await v2.execute('''
      CREATE TABLE pending_media (
        client_media_id TEXT PRIMARY KEY, visit_id INTEGER NOT NULL,
        checklist_instance_id INTEGER, asset_id INTEGER, kind TEXT NOT NULL,
        mime TEXT, local_path TEXT NOT NULL, total_bytes INTEGER NOT NULL,
        uploaded_bytes INTEGER NOT NULL DEFAULT 0, sha256 TEXT, captured_at TEXT,
        lat REAL, lng REAL, state TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT)
    ''');
    await v2.insert('pending_events', {
      'client_event_id': 'e-1',
      'visit_id': 7,
      'event_type': 'visit.transition',
      'payload': '{"to":"started"}',
      'device_timestamp': '2026-10-01T08:00:00Z',
      'sequence': 1,
      'created_at': '2026-10-01T08:00:00Z',
    });
    await v2.insert('pending_media', {
      'client_media_id': 'm-1',
      'visit_id': 7,
      'kind': 'photo_after',
      'local_path': '/x.jpg',
      'total_bytes': 10,
      'attempts': 3,
    });
    await v2.close();

    final db = await LocalDb.open(path: path);

    final event = (await db.raw.query('pending_events')).single;
    expect(event['client_event_id'], 'e-1');
    expect(event.containsKey('next_attempt_at'), isTrue);
    expect(event['actor_user_id'], isNull);

    final media = (await db.raw.query('pending_media')).single;
    expect(media['attempts'], 3, reason: 'nothing reset by the upgrade');
    expect(media['encrypted'], 0, reason: 'legacy files are plaintext');
    expect(media['transient_failures'], 0);

    expect(await db.raw.query('pending_actions'), isEmpty);
    await db.raw.close();
  });

  test('a fresh install already has the composite key', () async {
    final db = await LocalDb.open(path: inMemoryDatabasePath);

    await db.raw.insert('assets', {'id': 5, 'visit_id': 1, 'name': 'وحدة'});
    await db.raw.insert('assets', {'id': 5, 'visit_id': 2, 'name': 'وحدة'});

    expect(await db.raw.query('assets'), hasLength(2));

    await db.raw.close();
  });
}
