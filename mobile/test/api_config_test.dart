import 'package:darak_field/core/api_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('release requires an explicitly configured API', () {
    expect(
      () => resolveApiBase(configured: '', releaseMode: true),
      throwsStateError,
    );
  });

  test('release accepts only a durable HTTPS endpoint', () {
    expect(
      () => resolveApiBase(
        configured: 'http://api.example.com',
        releaseMode: true,
      ),
      throwsStateError,
    );
    expect(
      () => resolveApiBase(
        configured: 'https://mihwar-api.vercel.app',
        releaseMode: true,
      ),
      throwsStateError,
    );
    expect(
      resolveApiBase(configured: 'https://api.example.com/', releaseMode: true),
      'https://api.example.com',
    );
  });

  test('debug uses the Android emulator loopback fallback', () {
    expect(
      resolveApiBase(configured: '', releaseMode: false),
      'http://10.0.2.2:8000',
    );
  });
}
