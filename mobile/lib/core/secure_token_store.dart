import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Credentials and the SQLCipher key live in the platform keystore, never in
/// SharedPreferences or beside the database they protect.
class SecureTokenStore {
  SecureTokenStore({FlutterSecureStorage? storage})
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(encryptedSharedPreferences: true),
          );

  static const _keyToken = 'darak_api_token';
  static const _keyTechnician = 'darak_technician_name';
  static const _keyUserId = 'darak_user_id';
  static const _keyDatabase = 'darak_database_key_v1';
  static const _keyEvidence = 'darak_evidence_key_v1';

  final FlutterSecureStorage _storage;

  Future<String?> readToken() => _storage.read(key: _keyToken);

  Future<void> writeToken(String token) =>
      _storage.write(key: _keyToken, value: token);

  Future<String?> readTechnicianName() => _storage.read(key: _keyTechnician);

  Future<void> writeTechnicianName(String name) =>
      _storage.write(key: _keyTechnician, value: name);

  /// The signed-in technician's id, stamped on every queued item (C6).
  Future<int?> readUserId() async =>
      int.tryParse(await _storage.read(key: _keyUserId) ?? '');

  Future<void> writeUserId(int id) =>
      _storage.write(key: _keyUserId, value: '$id');

  /// Returns one stable, device-bound 256-bit database key. It deliberately
  /// survives a session expiry: deleting it while keeping the database would
  /// make all offline work permanently unreadable.
  Future<String> readOrCreateDatabaseKey() => _readOrCreateKey(_keyDatabase);

  /// The AES-256 key for evidence files at rest, kept apart from the database
  /// key so neither alone opens the other.
  Future<List<int>> readOrCreateEvidenceKey() async =>
      base64Url.decode(await _readOrCreateKey(_keyEvidence));

  Future<String> _readOrCreateKey(String name) async {
    final existing = await _storage.read(key: name);
    if (existing != null && existing.isNotEmpty) return existing;

    final random = Random.secure();
    final bytes = List<int>.generate(32, (_) => random.nextInt(256));
    final generated = base64UrlEncode(bytes);
    await _storage.write(key: name, value: generated);
    return generated;
  }

  /// Called on a 401 — the token is dead, the work is not. The keys and the
  /// user id stay so the same technician can sign in again and sync it.
  Future<void> clear() async {
    await _storage.delete(key: _keyToken);
    await _storage.delete(key: _keyTechnician);
  }

  /// Sign-out and remote wipe: nothing of the previous user survives, keys
  /// included — without the keys, any byte left behind is unreadable.
  Future<void> wipeAll() async {
    for (final key in [
      _keyToken,
      _keyTechnician,
      _keyUserId,
      _keyDatabase,
      _keyEvidence,
    ]) {
      await _storage.delete(key: key);
    }
  }
}
