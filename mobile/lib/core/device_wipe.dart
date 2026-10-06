import 'dart:io';

import 'package:flutter/foundation.dart' show debugPrint;
import 'package:path/path.dart' as p;
import 'package:shared_preferences/shared_preferences.dart';

import 'local_db.dart';
import 'secure_token_store.dart';
import 'trusted_clock.dart';

/// Forgets everything the app holds about the previous user.
///
/// Two callers: the remote-wipe path (403 DEVICE_REVOKED / USER_INACTIVE,
/// contract C1) — a lost or stolen phone must not keep customer premises,
/// signatures and the day's work readable — and sign-out, which is only
/// allowed once nothing is left unsynced.
///
/// Kept: the device UUID (the server's identity for this handset) and the UI
/// language (the device's, not the user's). Everything else goes: database
/// rows, the queue, evidence, queued attachments, cached files, the token, and
/// the database and evidence keys — so even a byte that escaped deletion stays
/// unreadable.
class DeviceWipe {
  const DeviceWipe._();

  static const keptPreferences = {'device_uuid', 'ui_locale'};
  static const sequenceFloorKey = 'event_sequence_floor';

  static Future<void> run({
    required LocalDb? db,
    required String documentsDirectory,
    String? cacheDirectory,
    required SecureTokenStore tokens,
    required SharedPreferences prefs,
  }) async {
    // The server orders a device's events by sequence. A fresh database must
    // not restart below what this device has already sent.
    final previousFloor = prefs.getInt(sequenceFloorKey);
    int? sequence;
    try {
      sequence = int.tryParse(await db?.getValue('event_sequence') ?? '');
    } catch (_) {}

    try {
      await db?.close();
    } catch (error) {
      debugPrint('Closing the database before wipe failed: $error');
    }

    final database = p.join(documentsDirectory, LocalDb.fileName);
    for (final suffix in [
      '',
      '-wal',
      '-shm',
      '-journal',
      '.sqlcipher.tmp',
      '.plaintext.backup',
    ]) {
      await _delete(File('$database$suffix'));
    }

    for (final folder in ['evidence', 'outbox']) {
      await _delete(Directory(p.join(documentsDirectory, folder)));
    }

    if (cacheDirectory != null) {
      final cache = Directory(cacheDirectory);
      if (await cache.exists()) {
        await for (final entity in cache.list()) {
          await _delete(entity);
        }
      }
    }

    await tokens.wipeAll();

    for (final key in prefs.getKeys().toList()) {
      if (!keptPreferences.contains(key)) await prefs.remove(key);
    }
    for (final key in TrustedClock.storageKeys) {
      await prefs.remove(key);
    }
    final floor = [?sequence, ?previousFloor].fold<int?>(
      null,
      (highest, value) => highest == null || value > highest ? value : highest,
    );
    if (floor != null) await prefs.setInt(sequenceFloorKey, floor);
  }

  /// Raises a fresh database's sequence counter to the floor saved by a wipe.
  static Future<void> restoreSequenceFloor(
    LocalDb db,
    SharedPreferences prefs,
  ) async {
    final floor = prefs.getInt(sequenceFloorKey);
    if (floor == null) return;
    final current =
        int.tryParse(await db.getValue('event_sequence') ?? '') ?? 0;
    if (current < floor) await db.setValue('event_sequence', '$floor');
  }

  static Future<void> _delete(FileSystemEntity entity) async {
    try {
      if (await entity.exists()) await entity.delete(recursive: true);
    } catch (error) {
      debugPrint('Wipe could not delete ${entity.path}: $error');
    }
  }
}
