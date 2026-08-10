import 'dart:convert';

import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// The device database. Everything a technician does is written here first and
/// synced later — a visit must be completable end to end with the radio off.
class LocalDb {
  LocalDb(this._db);

  final Database _db;

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
    CREATE TABLE IF NOT EXISTS assets (
      id INTEGER PRIMARY KEY,
      visit_id INTEGER NOT NULL,
      name TEXT,
      type TEXT,
      location TEXT,
      qr_code TEXT,
      under_warranty INTEGER DEFAULT 0
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
      created_at TEXT NOT NULL
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
      last_error TEXT
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
    '''
    CREATE TABLE IF NOT EXISTS kv (
      k TEXT PRIMARY KEY,
      v TEXT
    )
    ''',
    'CREATE INDEX IF NOT EXISTS idx_events_status ON pending_events (status, sequence)',
    'CREATE INDEX IF NOT EXISTS idx_media_state ON pending_media (state)',
  ];

  /// [path] is the full database path. Tests pass `inMemoryDatabasePath`;
  /// the app passes a file inside its documents directory.
  static Future<LocalDb> open({String? path, String? directory, DatabaseFactory? factory}) async {
    final dbFactory = factory ?? databaseFactory;
    final resolved = path ?? (directory == null ? 'darak.db' : p.join(directory, 'darak.db'));

    final db = await dbFactory.openDatabase(
      resolved,
      options: OpenDatabaseOptions(
        version: 1,
        onCreate: (db, _) async {
          for (final statement in _schema) {
            await db.execute(statement);
          }
        },
        onOpen: (db) async {
          for (final statement in _schema) {
            await db.execute(statement);
          }
        },
      ),
    );

    return LocalDb(db);
  }

  Database get raw => _db;

  Future<void> setValue(String key, String value) async {
    await _db.insert('kv', {'k': key, 'v': value},
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<String?> getValue(String key) async {
    final rows = await _db.query('kv', where: 'k = ?', whereArgs: [key], limit: 1);
    return rows.isEmpty ? null : rows.first['v'] as String?;
  }

  /// Monotonically increasing per-device counter. Survives clock changes, which
  /// is exactly why the server orders replayed events by it.
  Future<int> nextSequence() async {
    final current = int.tryParse(await getValue('event_sequence') ?? '0') ?? 0;
    final next = current + 1;
    await setValue('event_sequence', '$next');
    return next;
  }

  /// Refreshes the cached day.
  ///
  /// [protectedVisitIds] are never deleted even when the server did not return
  /// them. Without this, a visit still in progress at midnight — when the server
  /// window rolls to the next day — vanished mid-work and the technician lost the
  /// asset list, the checklist and the close button while standing on site.
  Future<void> replaceVisits(
    List<Map<String, dynamic>> visits, {
    Set<int> protectedVisitIds = const {},
  }) async {
    final returnedIds = visits.map((v) => v['id'] as int).toSet();
    final keep = {...returnedIds, ...protectedVisitIds};

    final batch = _db.batch();

    if (keep.isEmpty) {
      batch.delete('visits');
      batch.delete('assets');
    } else {
      final placeholders = List.filled(keep.length, '?').join(',');
      batch.delete('visits', where: 'id NOT IN ($placeholders)', whereArgs: keep.toList());
      batch.delete('assets', where: 'visit_id NOT IN ($placeholders)', whereArgs: keep.toList());
    }

    for (final visit in visits) {
      final site = visit['site'] as Map<String, dynamic>? ?? const {};
      final wo = visit['work_order'] as Map<String, dynamic>? ?? const {};
      final sla = visit['sla'] as Map<String, dynamic>?;
      final window = sla?['service_window'] as Map<String, dynamic>?;

      batch.insert(
        'visits',
        {
          'id': visit['id'],
          'state': visit['state'],
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
          'on_site_seconds': visit['on_site_seconds'] ?? 0,
          'payload': jsonEncode(visit),
          'last_synced_at': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

      for (final asset in (visit['assets'] as List<dynamic>? ?? const [])) {
        final a = asset as Map<String, dynamic>;
        batch.insert(
          'assets',
          {
            'id': a['id'],
            'visit_id': visit['id'],
            'name': a['name'],
            'type': a['type'],
            'location': a['location'],
            'qr_code': a['qr_code'],
            'under_warranty': (a['under_warranty'] == true) ? 1 : 0,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
    }

    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> todayVisits() =>
      _db.query('visits', orderBy: 'scheduled_start');

  Future<Map<String, dynamic>?> visit(int id) async {
    final rows = await _db.query('visits', where: 'id = ?', whereArgs: [id], limit: 1);
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
