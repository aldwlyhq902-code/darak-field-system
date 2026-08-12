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
  static const _keyDatabase = 'darak_database_key_v1';

  final FlutterSecureStorage _storage;

  Future<String?> readToken() => _storage.read(key: _keyToken);

  Future<void> writeToken(String token) =>
      _storage.write(key: _keyToken, value: token);

  Future<String?> readTechnicianName() => _storage.read(key: _keyTechnician);

  Future<void> writeTechnicianName(String name) =>
      _storage.write(key: _keyTechnician, value: name);

  /// Returns one stable, device-bound 256-bit database key. It deliberately
  /// survives sign-out: deleting it while keeping the database would make all
  /// offline work permanently unreadable.
  Future<String> readOrCreateDatabaseKey() async {
    final existing = await _storage.read(key: _keyDatabase);
    if (existing != null && existing.isNotEmpty) return existing;

    final random = Random.secure();
    final bytes = List<int>.generate(32, (_) => random.nextInt(256));
    final generated = base64UrlEncode(bytes);
    await _storage.write(key: _keyDatabase, value: generated);
    return generated;
  }

  /// Called on sign-out and on a 401 — the token is dead either way.
  Future<void> clear() async {
    await _storage.delete(key: _keyToken);
    await _storage.delete(key: _keyTechnician);
  }
}
