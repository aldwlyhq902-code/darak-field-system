import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The bearer token lives in the platform keystore, not in the sqlite file.
///
/// This is the decided position replacing the earlier hedge of "encrypted as far
/// as possible". The reasoning is proportionate rather than absolute:
///
///   - The token is the credential. On a lost handset it is what lets someone
///     read client data, so it goes in Android's EncryptedSharedPreferences.
///   - The local database holds a day or two of visits and photos. Full database
///     encryption would mean sqflite_sqlcipher, a native dependency that changes
///     the build and cannot be exercised in the desktop test harness. For a
///     two-vehicle pilot that is a poor trade: it buys little beyond what device
///     encryption and remote revocation already give, and costs test coverage on
///     the riskiest component in the system.
///   - The real control against a lost phone is server-side device revocation,
///     which is built and tested.
///
/// If the pilot ever carries more than a rolling couple of days of client data,
/// revisit this — the entry point is LocalDb.open, which already takes a path and
/// a factory.
class SecureTokenStore {
  SecureTokenStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  static const _keyToken = 'darak_api_token';
  static const _keyTechnician = 'darak_technician_name';

  final FlutterSecureStorage _storage;

  Future<String?> readToken() => _storage.read(key: _keyToken);

  Future<void> writeToken(String token) => _storage.write(key: _keyToken, value: token);

  Future<String?> readTechnicianName() => _storage.read(key: _keyTechnician);

  Future<void> writeTechnicianName(String name) =>
      _storage.write(key: _keyTechnician, value: name);

  /// Called on sign-out and on a 401 — the token is dead either way.
  Future<void> clear() async {
    await _storage.delete(key: _keyToken);
    await _storage.delete(key: _keyTechnician);
  }
}
