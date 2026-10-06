import 'dart:convert';
import 'dart:io';

import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart' as plain;
import 'package:sqflite_sqlcipher/sqflite.dart' as cipher;

/// The device database. Everything a technician does is written here first and
/// synced later — a visit must be completable end to end with the radio off.
class LocalDb {
  LocalDb(this._db);

  final plain.Database _db;

  static const _schema = <String>[
    // Cached work, refreshed on every successful bootstrap.
    '''
    CREATE TABLE IF NOT EXISTS visits (
      id INTEGER PRIMARY KEY,
      state TEXT NOT NULL,
      scheduled_start TEXT,
      client_name TEXT,
      site_name TEXT,
      site_address TEXT,
      site_lat REAL,
      site_lng REAL,
      geofence_radius_m INTEGER,
      access_notes TEXT,
      wo_number TEXT,
      wo_title TEXT,
      wo_type TEXT,
      sla_due_at TEXT,
      sla_status TEXT,
      service_window_start TEXT,
      service_window_end TEXT,
      is_rework INTEGER DEFAULT 0,
      on_site_seconds INTEGER DEFAULT 0,
      local_started_at TEXT,
      payload TEXT,
      last_synced_at TEXT
    )
    ''',
    '''
    -- Composite key on purpose. With `id` alone, two visits to the same site
    -- shared one row: inserting the second moved the asset onto it and the first
    -- visit lost its asset list mid-shift.
    CREATE TABLE IF NOT EXISTS assets (
      id INTEGER NOT NULL,
      visit_id INTEGER NOT NULL,
      name TEXT,
      type TEXT,
      location TEXT,
      qr_code TEXT,
      under_warranty INTEGER DEFAULT 0,
      PRIMARY KEY (visit_id, id)
    )
    ''',
    // The outbound queue. client_event_id is the idempotency key: the server may
    // receive the same row any number of times and will apply it exactly once.
    '''
    CREATE TABLE IF NOT EXISTS pending_events (
      client_event_id TEXT PRIMARY KEY,
      visit_id INTEGER NOT NULL,
      event_type TEXT NOT NULL,
      payload TEXT,
      device_timestamp TEXT NOT NULL,
      monotonic_offset_ms INTEGER,
      last_trusted_server_time TEXT,
      sequence INTEGER NOT NULL,
      lat REAL,
      lng REAL,
      source TEXT,
      status TEXT NOT NULL DEFAULT 'pending',
      attempts INTEGER NOT NULL DEFAULT 0,
      last_error TEXT,
      created_at TEXT NOT NULL,
      next_attempt_at TEXT,
      actor_user_id INTEGER,
      accuracy_m REAL,
      is_mocked INTEGER,
      transient_failures INTEGER NOT NULL DEFAULT 0
    )
    ''',
    '''
    CREATE TABLE IF NOT EXISTS pending_media (
      client_media_id TEXT PRIMARY KEY,
      visit_id INTEGER NOT NULL,
      checklist_instance_id INTEGER,
      asset_id INTEGER,
      kind TEXT NOT NULL,
      mime TEXT,
      local_path TEXT NOT NULL,
      total_bytes INTEGER NOT NULL,
      uploaded_bytes INTEGER NOT NULL DEFAULT 0,
      sha256 TEXT,
      captured_at TEXT,
      lat REAL,
      lng REAL,
      state TEXT NOT NULL DEFAULT 'pending',
      attempts INTEGER NOT NULL DEFAULT 0,
      last_error TEXT,
      next_attempt_at TEXT,
      transient_failures INTEGER NOT NULL DEFAULT 0,
      encrypted INTEGER NOT NULL DEFAULT 0,
      actor_user_id INTEGER
    )
    ''',
    '''
    CREATE TABLE IF NOT EXISTS local_checklists (
      visit_id INTEGER NOT NULL,
      asset_id INTEGER NOT NULL,
      status TEXT,
      note TEXT,
      no_parts_used INTEGER DEFAULT 0,
      updated_at TEXT,
      PRIMARY KEY (visit_id, asset_id)
    )
    ''',
    // Online-only API actions (custody, stocktake, transfers, diagnosis,
    // additional work, vehicle inspection) queued with the Idempotency-Key that
    // travels with every retry of the SAME tap, including after a restart.
    '''
    CREATE TABLE IF NOT EXISTS pending_actions (
      idempotency_key TEXT PRIMARY KEY,
      kind TEXT NOT NULL,
      method TEXT NOT NULL DEFAULT 'POST',
      path TEXT NOT NULL,
      body TEXT,
      label TEXT,
      attachment_path TEXT,
      attachment_field TEXT,
      attachment_name TEXT,
      visit_id INTEGER,
      target_key TEXT,
      status TEXT NOT NULL DEFAULT 'pending',
      attempts INTEGER NOT NULL DEFAULT 0,
      transient_failures INTEGER NOT NULL DEFAULT 0,
      next_attempt_at TEXT,
      last_error TEXT,
      actor_user_id INTEGER,
      created_at TEXT NOT NULL,
      completed_at TEXT
    )
    ''',
    '''
    CREATE TABLE IF NOT EXISTS kv (
      k TEXT PRIMARY KEY,
      v TEXT
    )
    ''',
    'CREATE INDEX IF NOT EXISTS idx_events_status ON pending_events (status, sequence)',
    'CREATE INDEX IF NOT EXISTS idx_media_state ON pending_media (state)',
    'CREATE INDEX IF NOT EXISTS idx_actions_status ON pending_actions (status, target_key)',
  ];

  static const version = 3;
  static const fileName = 'darak.db';

  /// [path] is the full database path. Tests pass `inMemoryDatabasePath`;
  /// the app passes a file inside its documents directory.
  static Future<LocalDb> open({
    String? path,
    String? directory,
    plain.DatabaseFactory? factory,
    String? password,
  }) async {
    final resolved =
        path ?? (directory == null ? fileName : p.join(directory, fileName));

    late final plain.Database db;
    if (password == null) {
      // Desktop tests intentionally use sqflite_common_ffi. Production always
      // supplies a keystore-backed password through AppState.
      final dbFactory = factory ?? plain.databaseFactory;
      db = await dbFactory.openDatabase(resolved, options: _options());
    } else {
      if (factory != null) {
        throw ArgumentError(
          'A custom database factory cannot encrypt a database.',
        );
      }
      await _migratePlaintextDatabase(resolved, password);
      db = await cipher.openDatabase(
        resolved,
        password: password,
        version: version,
        onCreate: _onCreate,
        onUpgrade: _onUpgrade,
        onOpen: _onOpen,
      );
    }

    return LocalDb(db);
  }

  static plain.OpenDatabaseOptions _options() => plain.OpenDatabaseOptions(
    version: version,
    onCreate: _onCreate,
    onUpgrade: _onUpgrade,
    onOpen: _onOpen,
  );

  static Future<void> _onCreate(plain.Database db, int _) async {
    for (final statement in _schema) {
      await db.execute(statement);
    }
  }

  // CREATE TABLE IF NOT EXISTS never alters a table that already exists, so a
  // phone carrying the v1 database needs an explicit, lossless asset migration.
  static Future<void> _onUpgrade(plain.Database db, int from, int to) async {
    if (from < 2) {
      await db.execute('ALTER TABLE assets RENAME TO assets_v1');
      await _onCreate(db, to);
      await db.execute('''
        INSERT OR IGNORE INTO assets (id, visit_id, name, type, location, qr_code, under_warranty)
        SELECT id, visit_id, name, type, location, qr_code, under_warranty FROM assets_v1
      ''');
      await db.execute('DROP TABLE assets_v1');
    }

    // v3: retry scheduling, the user who recorded each item, the location
    // evidence on events, evidence encryption, and the action outbox. Added
    // column by column — never by re-creating tables — so a phone carrying a
    // day of unsynced work loses nothing in the upgrade.
    if (from < 3) {
      for (final (table, column, type) in _v3Columns) {
        await _addColumnIfMissing(db, table, column, type);
      }
      await _onCreate(db, to);
    }
  }

  static const _v3Columns = <(String, String, String)>[
    ('pending_events', 'next_attempt_at', 'TEXT'),
    ('pending_events', 'actor_user_id', 'INTEGER'),
    ('pending_events', 'accuracy_m', 'REAL'),
    ('pending_events', 'is_mocked', 'INTEGER'),
    ('pending_events', 'transient_failures', 'INTEGER NOT NULL DEFAULT 0'),
    ('pending_media', 'next_attempt_at', 'TEXT'),
    ('pending_media', 'transient_failures', 'INTEGER NOT NULL DEFAULT 0'),
    ('pending_media', 'encrypted', 'INTEGER NOT NULL DEFAULT 0'),
    ('pending_media', 'actor_user_id', 'INTEGER'),
  ];

  static Future<void> _addColumnIfMissing(
    plain.Database db,
    String table,
    String column,
    String type,
  ) async {
    final columns = await db.rawQuery('PRAGMA table_info($table)');
    if (columns.any((row) => row['name'] == column)) return;
    await db.execute('ALTER TABLE $table ADD COLUMN $column $type');
  }

  static Future<void> _onOpen(plain.Database db) => _onCreate(db, version);

  /// Converts an existing plaintext database before opening it normally. The
  /// original remains as a backup until the encrypted copy has opened and its
  /// schema has been verified with the device key.
  static Future<void> _migratePlaintextDatabase(
    String path,
    String password,
  ) async {
    if (path == plain.inMemoryDatabasePath) return;

    final source = File(path);
    final temporary = File('$path.sqlcipher.tmp');
    final backup = File('$path.plaintext.backup');

    if (!await source.exists() && await backup.exists()) {
      await backup.rename(path);
    }
    if (!await source.exists()) return;

    if (await backup.exists()) {
      if (await _canOpenEncrypted(path, password)) {
        await backup.delete();
        if (await temporary.exists()) await temporary.delete();
        return;
      }

      // Do not guess here. A wrong/lost keystore key is indistinguishable from
      // an interrupted replacement; deleting either copy could destroy the only
      // recoverable data. Leave both artifacts for explicit recovery.
      throw StateError(
        'An interrupted SQLCipher migration needs manual recovery; both database copies were preserved.',
      );
    }

    if (await _canOpenEncrypted(path, password)) {
      if (await temporary.exists()) await temporary.delete();
      return;
    }

    if (!await _canOpenPlaintext(path)) {
      throw StateError(
        'The local database cannot be opened with the device key and is not plaintext.',
      );
    }

    if (await temporary.exists()) await temporary.delete();
    final escapedPath = temporary.path.replaceAll("'", "''");
    final escapedKey = password.replaceAll("'", "''");
    final db = await cipher.openDatabase(path, singleInstance: false);

    try {
      await db.rawQuery('PRAGMA wal_checkpoint(FULL)');
      final versionRows = await db.rawQuery('PRAGMA user_version');
      final version = versionRows.first.values.first as int? ?? 0;
      await db.execute(
        "ATTACH DATABASE '$escapedPath' AS encrypted KEY '$escapedKey'",
      );
      await db.rawQuery("SELECT sqlcipher_export('encrypted')");
      await db.execute('PRAGMA encrypted.user_version = $version');
      await db.execute('DETACH DATABASE encrypted');
    } finally {
      await db.close();
    }

    if (!await _canOpenEncrypted(temporary.path, password)) {
      if (await temporary.exists()) await temporary.delete();
      throw StateError('SQLCipher migration verification failed.');
    }

    await source.rename(backup.path);
    try {
      await temporary.rename(path);
      if (!await _canOpenEncrypted(path, password)) {
        throw StateError(
          'The migrated SQLCipher database could not be reopened.',
        );
      }
      await backup.delete();
    } catch (_) {
      if (await source.exists()) await source.delete();
      if (await backup.exists()) await backup.rename(path);
      rethrow;
    }
  }

  static Future<bool> _canOpenEncrypted(String path, String password) async {
    plain.Database? db;
    try {
      db = await cipher.openDatabase(
        path,
        password: password,
        readOnly: true,
        singleInstance: false,
      );
      await db.rawQuery('SELECT count(*) FROM sqlite_master');
      return true;
    } catch (_) {
      return false;
    } finally {
      await db?.close();
    }
  }

  static Future<bool> _canOpenPlaintext(String path) async {
    plain.Database? db;
    try {
      db = await cipher.openDatabase(
        path,
        readOnly: true,
        singleInstance: false,
      );
      await db.rawQuery('SELECT count(*) FROM sqlite_master');
      return true;
    } catch (_) {
      return false;
    } finally {
      await db?.close();
    }
  }

  plain.Database get raw => _db;

  Future<void> setValue(String key, String value) async {
    await _db.insert('kv', {
      'k': key,
      'v': value,
    }, conflictAlgorithm: plain.ConflictAlgorithm.replace);
  }

  Future<String?> getValue(String key) async {
    final rows = await _db.query(
      'kv',
      where: 'k = ?',
      whereArgs: [key],
      limit: 1,
    );
    return rows.isEmpty ? null : rows.first['v'] as String?;
  }

  /// Monotonically increasing per-device counter. Survives clock changes, which
  /// is exactly why the server orders replayed events by it.
  Future<int> nextSequence([plain.DatabaseExecutor? executor]) async {
    final target = executor ?? _db;
    final rows = await target.query(
      'kv',
      columns: ['v'],
      where: 'k = ?',
      whereArgs: ['event_sequence'],
      limit: 1,
    );
    final current =
        int.tryParse(
          rows.isEmpty ? '0' : (rows.first['v'] as String? ?? '0'),
        ) ??
        0;
    final next = current + 1;
    await target.insert('kv', {
      'k': 'event_sequence',
      'v': '$next',
    }, conflictAlgorithm: plain.ConflictAlgorithm.replace);
    return next;
  }

  /// Refreshes the cached day.
  ///
  /// [protectedVisitIds] are never deleted even when the server did not return
  /// them. Without this, a visit still in progress at midnight — when the server
  /// window rolls to the next day — vanished mid-work and the technician lost the
  /// asset list, the checklist and the close button while standing on site.
  ///
  /// [localStateVisitIds] keep their LOCAL state, start time and on-site
  /// counter. These are visits whose transitions are still queued: the server
  /// has not seen them yet, so its state is simply older than the phone's.
  /// Overwriting it rolled a technician's offline "started" back to
  /// "scheduled" on every refresh and erased the running timer with it.
  ///
  /// Every other visit takes the server's state, but its local start time
  /// survives as long as the server agrees on the state — a blanket REPLACE
  /// used to null it on every refresh, so the next pause counted zero minutes.
  Future<void> replaceVisits(
    List<Map<String, dynamic>> visits, {
    Set<int> protectedVisitIds = const {},
    Set<int> localStateVisitIds = const {},
  }) async {
    final returnedIds = visits.map((v) => v['id'] as int).toSet();
    final keep = {...returnedIds, ...protectedVisitIds, ...localStateVisitIds};

    await _db.transaction((transaction) async {
      final local = {
        for (final row in await transaction.query(
          'visits',
          columns: ['id', 'state', 'local_started_at', 'on_site_seconds'],
        ))
          row['id'] as int: row,
      };

      final batch = transaction.batch();

      if (keep.isEmpty) {
        batch.delete('visits');
        batch.delete('assets');
      } else {
        final placeholders = List.filled(keep.length, '?').join(',');
        batch.delete(
          'visits',
          where: 'id NOT IN ($placeholders)',
          whereArgs: keep.toList(),
        );
        batch.delete(
          'assets',
          where: 'visit_id NOT IN ($placeholders)',
          whereArgs: keep.toList(),
        );
      }

      for (final visit in visits) {
        _writeVisit(
          batch,
          visit,
          local: local[visit['id']],
          keepLocalState: localStateVisitIds.contains(visit['id']),
        );
      }

      await batch.commit(noResult: true);
    });
  }

  void _writeVisit(
    plain.Batch batch,
    Map<String, dynamic> visit, {
    required Map<String, Object?>? local,
    required bool keepLocalState,
  }) {
    final site = visit['site'] as Map<String, dynamic>? ?? const {};
    final wo = visit['work_order'] as Map<String, dynamic>? ?? const {};
    final sla = visit['sla'] as Map<String, dynamic>?;
    final window = sla?['service_window'] as Map<String, dynamic>?;

    final serverState = visit['state'];
    final String? state;
    final Object? startedAt;
    final Object? onSite;

    if (keepLocalState && local != null) {
      state = local['state'] as String?;
      startedAt = local['local_started_at'];
      onSite = local['on_site_seconds'];
    } else {
      state = serverState as String?;
      startedAt = local != null && local['state'] == serverState
          ? local['local_started_at']
          : null;
      onSite = visit['on_site_seconds'] ?? 0;
    }

    batch.insert('visits', {
      'id': visit['id'],
      'state': state,
      'local_started_at': startedAt,
      'on_site_seconds': onSite,
      'scheduled_start': visit['scheduled_start'],
      'client_name': site['client_name'],
      'site_name': site['name'],
      'site_address': site['address'],
      'site_lat': _toDouble(site['lat']),
      'site_lng': _toDouble(site['lng']),
      'geofence_radius_m': site['geofence_radius_m'],
      'access_notes': site['access_notes'],
      'wo_number': wo['number'],
      'wo_title': wo['title'],
      'wo_type': wo['type'],
      'sla_due_at': sla?['due_at'],
      'sla_status': sla?['status'],
      'service_window_start': window?['start'],
      'service_window_end': window?['end'],
      'is_rework': (visit['is_rework'] == true) ? 1 : 0,
      'payload': jsonEncode(visit),
      'last_synced_at': DateTime.now().toIso8601String(),
    }, conflictAlgorithm: plain.ConflictAlgorithm.replace);

    for (final asset in (visit['assets'] as List<dynamic>? ?? const [])) {
      final a = asset as Map<String, dynamic>;
      batch.insert('assets', {
        'id': a['id'],
        'visit_id': visit['id'],
        'name': a['name'],
        'type': a['type'],
        'location': a['location'],
        'qr_code': a['qr_code'],
        'under_warranty': (a['under_warranty'] == true) ? 1 : 0,
      }, conflictAlgorithm: plain.ConflictAlgorithm.replace);
    }
  }

  /// Unsynced work on the device, for the sign-out gate and the login guard.
  ///
  /// [foreignTo] counts only items recorded by a DIFFERENT user than the one
  /// given — the work that would be sent under the wrong technician's name.
  Future<UnsyncedCounts> unsyncedCounts({int? foreignTo}) async {
    final actor = foreignTo == null
        ? ''
        : ' AND actor_user_id IS NOT NULL AND actor_user_id != $foreignTo';

    Future<int> count(String sql) async =>
        ((await _db.rawQuery(sql)).first['c'] as int?) ?? 0;

    return UnsyncedCounts(
      events: await count(
        "SELECT COUNT(*) AS c FROM pending_events WHERE status IN ('pending', 'failed')$actor",
      ),
      media: await count(
        "SELECT COUNT(*) AS c FROM pending_media WHERE state NOT IN ('complete', 'discarded')$actor",
      ),
      actions: await count(
        "SELECT COUNT(*) AS c FROM pending_actions WHERE status IN ('pending', 'failed')$actor",
      ),
    );
  }

  /// Rows queued before items carried their author (schema v3) belong to the
  /// only user this device has had: the one signed in now (contract C6).
  Future<void> adoptOrphans(int userId) async {
    await _db.transaction((transaction) async {
      for (final table in [
        'pending_events',
        'pending_media',
        'pending_actions',
      ]) {
        await transaction.rawUpdate(
          'UPDATE $table SET actor_user_id = ? WHERE actor_user_id IS NULL',
          [userId],
        );
      }
    });
  }

  Future<void> close() => _db.close();

  Future<List<Map<String, dynamic>>> todayVisits() =>
      _db.query('visits', orderBy: 'scheduled_start');

  Future<Map<String, dynamic>?> visit(int id) async {
    final rows = await _db.query(
      'visits',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    return rows.isEmpty ? null : rows.first;
  }

  Future<List<Map<String, dynamic>>> assetsFor(int visitId) =>
      _db.query('assets', where: 'visit_id = ?', whereArgs: [visitId]);

  static double? _toDouble(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    return double.tryParse(value.toString());
  }
}

class UnsyncedCounts {
  const UnsyncedCounts({
    required this.events,
    required this.media,
    required this.actions,
  });

  final int events;
  final int media;
  final int actions;

  int get total => events + media + actions;
  bool get isEmpty => total == 0;
}
