import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import 'event_queue.dart';
import 'local_db.dart';
import 'trusted_clock.dart';

/// Where captured evidence lands before it is uploaded.
///
/// Two things happen for every capture, in this order:
///   1. The bytes are written to the app's own directory. Not the gallery — the
///      photo is business evidence, not the technician's picture.
///   2. A row goes into `pending_media` AND a `media.register` event goes into the
///      outbound queue, both keyed by the same device-generated UUID.
///
/// That ordering matters. If the app dies between capture and sync, the file and
/// its queue row are already on disk and the uploader picks it up on next launch.
class EvidenceStore {
  EvidenceStore({
    required LocalDb db,
    required EventQueue queue,
    required TrustedClock clock,
    required String rootDirectory,
    Uuid? uuid,
  })  : _db = db,
        _queue = queue,
        _clock = clock,
        _root = rootDirectory,
        _uuid = uuid ?? const Uuid();

  final LocalDb _db;
  final EventQueue _queue;
  final TrustedClock _clock;
  final String _root;
  final Uuid _uuid;

  static const kindPhotoBefore = 'photo_before';
  static const kindPhotoAfter = 'photo_after';
  static const kindSignature = 'signature';

  /// Registers a captured file. Returns the client media id, which is also the
  /// idempotency key the server dedupes on.
  Future<String> store({
    required int visitId,
    required String kind,
    required Uint8List bytes,
    String mime = 'image/jpeg',
    int? checklistInstanceId,
    int? assetId,
    double? lat,
    double? lng,
    String declaredSource = 'camera',
  }) async {
    final clientMediaId = _uuid.v4();
    final extension = switch (mime) {
      'image/png' => 'png',
      'image/webp' => 'webp',
      _ => 'jpg',
    };

    final directory = Directory(p.join(_root, 'evidence', '$visitId'));
    if (!await directory.exists()) {
      await directory.create(recursive: true);
    }

    final file = File(p.join(directory.path, '$clientMediaId.$extension'));
    await file.writeAsBytes(bytes, flush: true);

    // The hash is computed at capture time, not at upload time. If the file is
    // altered on disk afterwards the mismatch surfaces instead of being masked by
    // re-hashing whatever bytes happen to be there when the network returns.
    final digest = sha256.convert(bytes).toString();
    final capturedAt = _clock.deviceNow;

    await _db.raw.insert(
      'pending_media',
      {
        'client_media_id': clientMediaId,
        'visit_id': visitId,
        'checklist_instance_id': checklistInstanceId,
        // Kept locally too, so the on-device close gate can ask the same
        // per-asset question the server asks.
        'asset_id': assetId,
        'kind': kind,
        'mime': mime,
        'local_path': file.path,
        'total_bytes': bytes.length,
        'uploaded_bytes': 0,
        'sha256': digest,
        // UTC for the same reason as event timestamps: an offset-less local time
        // is reinterpreted in the server's zone.
        'captured_at': capturedAt.toUtc().toIso8601String(),
        'lat': lat,
        'lng': lng,
        'state': 'pending',
        'attempts': 0,
      },
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );

    await _queue.enqueue(
      visitId: visitId,
      eventType: 'media.register',
      payload: {
        'client_media_id': clientMediaId,
        'kind': kind,
        'mime': mime,
        'total_bytes': bytes.length,
        'captured_at': capturedAt.toUtc().toIso8601String(),
        'declared_source': declaredSource,
        if (assetId != null) 'asset_id': assetId,
        if (checklistInstanceId != null) 'checklist_instance_id': checklistInstanceId,
        if (lat != null) 'lat': lat,
        if (lng != null) 'lng': lng,
      },
      lat: lat,
      lng: lng,
    );

    return clientMediaId;
  }

  Future<List<Map<String, dynamic>>> forVisit(int visitId) => _db.raw.query(
        'pending_media',
        where: 'visit_id = ?',
        whereArgs: [visitId],
        orderBy: 'rowid ASC',
      );

  /// Photos on this visit, optionally narrowed to one asset.
  ///
  /// The assetId argument used to be declared and silently ignored, so the local
  /// close gate counted every photo on the visit while the server demanded one
  /// per asset — "ready" on the phone, refused by the server.
  Future<int> photoCount(int visitId, {int? assetId}) async {
    final rows = assetId == null
        ? await _db.raw.rawQuery(
            'SELECT COUNT(*) AS c FROM pending_media WHERE visit_id = ? AND kind LIKE ?',
            [visitId, 'photo%'],
          )
        : await _db.raw.rawQuery(
            'SELECT COUNT(*) AS c FROM pending_media WHERE visit_id = ? AND asset_id = ? AND kind LIKE ?',
            [visitId, assetId, 'photo%'],
          );

    return (rows.first['c'] as int?) ?? 0;
  }

  Future<bool> hasSignature(int visitId) async {
    final rows = await _db.raw.query(
      'pending_media',
      where: 'visit_id = ? AND kind = ?',
      whereArgs: [visitId, kindSignature],
      limit: 1,
    );

    return rows.isNotEmpty;
  }

  /// Evidence for a closed and fully uploaded visit can be cleared to reclaim
  /// space. Anything still pending is never touched — that is unsent evidence.
  Future<int> pruneUploaded(int visitId) async {
    final rows = await _db.raw.query(
      'pending_media',
      where: 'visit_id = ? AND state = ?',
      whereArgs: [visitId, 'complete'],
    );

    var removed = 0;

    for (final row in rows) {
      final file = File(row['local_path'] as String);
      if (await file.exists()) {
        await file.delete();
        removed++;
      }
    }

    return removed;
  }
}
