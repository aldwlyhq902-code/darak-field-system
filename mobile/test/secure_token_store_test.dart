import 'dart:convert';

import 'package:darak_field/core/secure_token_store.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
  });

  test('database key is 256 bit and remains stable', () async {
    final store = SecureTokenStore();

    final first = await store.readOrCreateDatabaseKey();
    final second = await store.readOrCreateDatabaseKey();

    expect(second, first);
    expect(base64Url.decode(first), hasLength(32));
  });

  test('sign-out clears credentials but preserves database key', () async {
    final store = SecureTokenStore();
    final key = await store.readOrCreateDatabaseKey();
    await store.writeToken('token');
    await store.writeTechnicianName('Technician');

    await store.clear();

    expect(await store.readToken(), isNull);
    expect(await store.readTechnicianName(), isNull);
    expect(await store.readOrCreateDatabaseKey(), key);
  });
}
