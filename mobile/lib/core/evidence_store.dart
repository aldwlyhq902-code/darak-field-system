import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart' show debugPrint;
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import 'event_queue.dart';
import 'evidence_cipher.dart';
import 'image_limits.dart';
import 'local_db.dart';
import 'trusted_clock.dart';

/// Where captured evidence lands before it is uploaded.
///
/// Bytes first land in a flushed `.pending` file. The media row, registration
/// event, and event sequence then commit in one SQLite transaction, after which
/// the file is atomically promoted to its final name. [reconcile] completes that
/// last rename if the process stops between the commit and promotion.
///
/// That ordering matters. If the app dies between capture and sync, the file and
/// its queue row are already on disk and the uploader picks it up on next launch.
///
/// With a [cipher] (always, in the app) the bytes on disk are AES-GCM
/// encrypted (see [EvidenceCipher]); the SHA-256 recorded at capture is still
/// the hash of the PLAINTEXT, which is what the server receives and verifies.
class EvidenceStore {
  EvidenceStore({
    required LocalDb db,
    required EventQueue queue,
    required TrustedClock clock,
    required String rootDirectory,
    EvidenceCipher? cipher,
    int maxBytes = 25 * 1024 * 1024,
    Uuid? uuid,
  }) : _db = db,
       _queue = queue,
       _clock = clock,
       _root = rootDirectory,
       _cipher = cipher,
       _maxBytes = maxBytes,
       _uuid = uuid ?? const Uuid();

  final LocalDb _db;
  final EventQueue _queue;
  final TrustedClock _clock;
  final String _root;
  final EvidenceCipher? _cipher;
  final int _maxBytes;
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
    int? actorUserId,
  }) async {
    if (bytes.isEmpty || bytes.length > _maxBytes) {
      throw ArgumentError.value(
        bytes.length,
        'bytes',
        'Evidence must be between 1 and $_maxBytes bytes',
      );
    }

    if (![kindPhotoBefore, kindPhotoAfter, kindSignature].contains(kind)) {
      throw ArgumentError.value(kind, 'kind', 'Unsupported evidence kind');
    }

    if (!['image/jpeg', 'image/png', 'image/webp'].contains(mime)) {
      throw ArgumentError.value(mime, 'mime', 'Unsupported evidence MIME type');
    }

    if (kind == kindSignature && mime != 'image/png') {
      throw ArgumentError.value(mime, 'mime', 'Signatures must be PNG images');
    }

    final size = ImageLimits.dimensions(bytes);
    if (size != null &&
        !ImageLimits.withinServerLimits(size.width, size.height)) {
      throw ArgumentError.value(
        '${size.width}x${size.height}',
        'bytes',
        'Image exceeds the server limit (40 MP / 12000 px)',
      );
    }

    if (!['camera', 'on_screen'].contains(declaredSource)) {
      throw ArgumentError.value(
        declaredSource,
        'declaredSource',
        'Unsupported evidence source',
      );
    }

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

    final cipher = _cipher;
    final file = File(
      p.join(
        directory.path,
        cipher == null
            ? '$clientMediaId.$extension'
            : '$clientMediaId.$extension.enc',
      ),
    );
    final stagingFile = File('${file.path}.pending');
    if (cipher == null) {
      await stagingFile.writeAsBytes(bytes, flush: true);
    } else {
      await cipher.encryptToFile(bytes, stagingFile);
    }

    // The hash is computed at capture time, not at upload time. If the file is
    // altered on disk afterwards the mismatch surfaces instead of being masked by
    // re-hashing whatever bytes happen to be there when the network returns.
    final digest = sha256.convert(bytes).toString();
    final capturedAt = _clock.deviceNow;

    try {
      await _db.raw.transaction((transaction) async {
        await transaction.insert('pending_media', {
          'client_media_id': clientMediaId,
          'visit_id': visitId,
          'checklist_instance_id': checklistInstanceId,
          'asset_id': assetId,
          'kind': kind,
          'mime': mime,
          'local_path': file.path,
          'total_bytes': bytes.length,
          'uploaded_bytes': 0,
          'sha256': digest,
          'captured_at': capturedAt.toUtc().toIso8601String(),
          'lat': lat,
          'lng': lng,
          'state': 'pending',
          'attempts': 0,
          'encrypted': cipher == null ? 0 : 1,
          'actor_user_id': actorUserId,
        }, conflictAlgorithm: ConflictAlgorithm.abort);

        await _queue.enqueueInTransaction(
          transaction,
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
            if (checklistInstanceId != null)
              'checklist_instance_id': checklistInstanceId,
            if (lat != null) 'lat': lat,
            if (lng != null) 'lng': lng,
          },
          lat: lat,
          lng: lng,
          actorUserId: actorUserId,
        );
      });
    } catch (_) {
      if (await stagingFile.exists()) await stagingFile.delete();
      rethrow;
    }

    await stagingFile.rename(file.path);

    return clientMediaId;
  }

  /// Repairs captures interrupted after their database commit and removes
  /// staging files whose transaction never committed. Missing committed files
  /// are marked failed so they cannot be mistaken for uploadable evidence.
  ///
  /// With a cipher it also encrypts, in place, any capture written before
  /// encryption at rest existed.
  Future<EvidenceReconciliation> reconcile() async {
    final rows = await _db.raw.query('pending_media');
    final expectedStagingPaths = <String>{};
    var promoted = 0;
    var missing = 0;
    var encrypted = 0;

    for (final row in rows) {
      final path = row['local_path'] as String;
      final file = File(path);
      final staging = File('$path.pending');
      expectedStagingPaths.add(staging.path);
      final isEncrypted = (row['encrypted'] as int? ?? 0) == 1;

      if (isEncrypted && path.endsWith('.enc')) {
        // A migration recorded in the database but interrupted before the
        // plaintext original was deleted.
        final original = File(path.substring(0, path.length - 4));
        if (await original.exists()) await original.delete();
      }

      if (await file.exists()) {
        if (await staging.exists()) await staging.delete();
      } else if (await staging.exists()) {
        await staging.rename(path);
        promoted++;
      } else {
        // Completed evidence may have been intentionally pruned, and discarded
        // evidence is expected to have no bytes. Neither is corruption.
        if (row['state'] == 'complete' || row['state'] == 'discarded') {
          continue;
        }

        await _db.raw.update(
          'pending_media',
          {'state': 'failed', 'last_error': 'Local evidence file is missing'},
          where: 'client_media_id = ? AND state NOT IN (?, ?)',
          whereArgs: [row['client_media_id'], 'complete', 'discarded'],
        );
        missing++;
        continue;
      }

      if (_cipher != null && !isEncrypted && await _encryptLegacy(row)) {
        encrypted++;
      }
    }

    final evidenceRoot = Directory(p.join(_root, 'evidence'));
    var orphanedStagingFiles = 0;
    if (await evidenceRoot.exists()) {
      await for (final entity in evidenceRoot.list(recursive: true)) {
        if (entity is! File) continue;
        final orphanStaging =
            entity.path.endsWith('.pending') &&
            !expectedStagingPaths.contains(entity.path);
        // A half-written encryption of a legacy file; the original is intact.
        final abandonedMigration = entity.path.endsWith('.enc.tmp');
        if (orphanStaging || abandonedMigration) {
          await entity.delete();
          orphanedStagingFiles++;
        }
      }
    }

    return EvidenceReconciliation(
      promoted: promoted,
      missing: missing,
      orphanedStagingFiles: orphanedStagingFiles,
      encrypted: encrypted,
    );
  }

  /// Encrypts one pre-encryption capture: write the ciphertext beside it,
  /// verify it decrypts to the same bytes, record the new path, then delete the
  /// plaintext. Every step is restartable — the plaintext is only removed after
  /// the database points at a verified encrypted copy.
  Future<bool> _encryptLegacy(Map<String, dynamic> row) async {
    final cipher = _cipher!;
    final path = row['local_path'] as String;
    final plain = File(path);
    final target = File('$path.enc');
    final temporary = File('$path.enc.tmp');

    try {
      final bytes = await plain.readAsBytes();
      if (EvidenceCipher.looksEncrypted(bytes)) return false;

      await cipher.encryptToFile(bytes, temporary);
      final roundTrip = await cipher.decryptFile(temporary);
      if (sha256.convert(roundTrip) != sha256.convert(bytes)) {
        throw StateError('Encrypted copy did not verify');
      }

      if (await target.exists()) await target.delete();
      await temporary.rename(target.path);

      final updated = await _db.raw.update(
        'pending_media',
        {'local_path': target.path, 'encrypted': 1},
        where: 'client_media_id = ? AND encrypted = 0',
        whereArgs: [row['client_media_id']],
      );
      if (updated == 0) return false;

      await plain.delete();
      return true;
    } catch (error) {
      // Leave the plaintext where it is; the next launch tries again. A failed
      // migration must never cost the evidence itself.
      if (await temporary.exists()) await temporary.delete();
      debugPrint('Evidence encryption migration failed for $path: $error');
      return false;
    }
  }

  /// Plaintext bytes of one capture, for the uploader. Encrypted files are
  /// decrypted segment by segment in memory; nothing decrypted is written out.
  Future<EvidenceReader> openReader(Map<String, dynamic> row) async {
    final file = File(row['local_path'] as String);
    if ((row['encrypted'] as int? ?? 0) == 1) {
      final cipher = _cipher;
      if (cipher == null) {
        throw StateError('Encrypted evidence needs the evidence key');
      }
      return cipher.open(file);
    }
    return PlainEvidenceReader.open(file);
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
  /// States that still count as evidence the technician has captured.
  ///
  /// A discarded file is NOT evidence. Counting it told the phone the visit was
  /// ready while the server, which excludes it, was still asking for a
  /// replacement — the two gates disagreeing in the technician's hands.
  static const _live = "state NOT IN ('discarded', 'discard_pending')";

  Future<int> photoCount(int visitId, {int? assetId}) async {
    final rows = assetId == null
        ? await _db.raw.rawQuery(
            'SELECT COUNT(*) AS c FROM pending_media WHERE visit_id = ? AND kind LIKE ? AND $_live',
            [visitId, 'photo%'],
          )
        : await _db.raw.rawQuery(
            'SELECT COUNT(*) AS c FROM pending_media WHERE visit_id = ? AND asset_id = ? AND kind LIKE ? AND $_live',
            [visitId, assetId, 'photo%'],
          );

    return (rows.first['c'] as int?) ?? 0;
  }

  Future<bool> hasSignature(int visitId) async {
    final rows = await _db.raw.query(
      'pending_media',
      where: 'visit_id = ? AND kind = ? AND $_live',
      whereArgs: [visitId, kindSignature],
      limit: 1,
    );

    return rows.isNotEmpty;
  }

  /// Live evidence for a visit, for the on-device close gate. Discarded files
  /// are excluded so the phone asks the same question the server asks.
  Future<List<Map<String, dynamic>>> liveForVisit(int visitId) => _db.raw.query(
    'pending_media',
    where: 'visit_id = ? AND $_live',
    whereArgs: [visitId],
  );

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

class EvidenceReconciliation {
  const EvidenceReconciliation({
    required this.promoted,
    required this.missing,
    required this.orphanedStagingFiles,
    this.encrypted = 0,
  });

  final int promoted;
  final int missing;
  final int orphanedStagingFiles;

  /// Legacy plaintext captures encrypted by this pass.
  final int encrypted;
}
