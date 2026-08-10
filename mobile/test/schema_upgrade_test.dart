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
    final path = '${await databaseFactory.getDatabasesPath()}/upgrade_${DateTime.now().microsecondsSinceEpoch}.db';

    await createV1(path);

    // Opening with the current code must migrate, not wipe: the phone may be
    // offline, and discarding the cache strips a technician mid-visit.
    final db = await LocalDb.open(path: path);

    final rows = await db.raw.query('assets');
    expect(rows, hasLength(1), reason: 'the cached asset must survive the upgrade');
    expect(rows.first['name'], 'سبليت الصالة');

    // The whole point: the same asset can now belong to a second visit.
    await db.raw.insert('assets', {
      'id': 11,
      'visit_id': 8,
      'name': 'سبليت الصالة',
      'type': 'split_ac',
      'qr_code': 'A-11',
    });

    final forVisit7 = await db.raw.query('assets', where: 'visit_id = ?', whereArgs: [7]);
    final forVisit8 = await db.raw.query('assets', where: 'visit_id = ?', whereArgs: [8]);

    expect(forVisit7, hasLength(1), reason: 'the first visit must not lose its asset');
    expect(forVisit8, hasLength(1));

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
