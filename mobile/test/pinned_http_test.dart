import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:darak_field/core/api_client.dart';
import 'package:darak_field/core/pinned_http.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

/// Review finding 21: optional SPKI pinning.
///
/// Throwaway self-signed certificates for "localhost", generated for this test
/// only. The expected pins were computed independently with:
///   openssl x509 -pubkey -noout | openssl pkey -pubin -outform der
///     | openssl dgst -sha256 -binary | openssl enc -base64
const _serverCert = '''
-----BEGIN CERTIFICATE-----
MIIBnDCCAUGgAwIBAgIUPgp+c/5dOplvbG9LD9J1IL2+l44wCgYIKoZIzj0EAwIw
FDESMBAGA1UEAwwJbG9jYWxob3N0MCAXDTI2MTAwNjExMDg0MloYDzIxMjYwOTEy
MTEwODQyWjAUMRIwEAYDVQQDDAlsb2NhbGhvc3QwWTATBgcqhkjOPQIBBggqhkjO
PQMBBwNCAATBvWNRgSZr5Hc5/PUkRz0e/T+myakhlCQ59rXtIQGzZy6jfpRcwrNH
/Kffbf6B/nnfPZ5+8rImjD373v7dgBmmo28wbTAdBgNVHQ4EFgQUVkMPpqnexsWN
zEzutrfj41sIdJ8wHwYDVR0jBBgwFoAUVkMPpqnexsWNzEzutrfj41sIdJ8wDwYD
VR0TAQH/BAUwAwEB/zAaBgNVHREEEzARgglsb2NhbGhvc3SHBH8AAAEwCgYIKoZI
zj0EAwIDSQAwRgIhAIM6CTJDUYCJ5cj/p3qgZUv+iLcbp887yQ46vIzRIy97AiEA
x/FvyKnvr2i6fdHnJnFJFeihoPtkjf9G+oqU3EcYBbM=
-----END CERTIFICATE-----
''';

const _serverKey = '''
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgT86Wj1fW936Lczpk
vSmsw82yA/Yf/svOnIIV4baruF+hRANCAATBvWNRgSZr5Hc5/PUkRz0e/T+myakh
lCQ59rXtIQGzZy6jfpRcwrNH/Kffbf6B/nnfPZ5+8rImjD373v7dgBmm
-----END PRIVATE KEY-----
''';

const _serverPin = 'PwfubdA0eXwo0JR+BznDazqCcciuQnoFHrF8M0V6t1Q=';

/// The SPKI pin of a different key — what a backup pin, or an attacker's
/// certificate, looks like.
const _otherPin = 'MZatizvofN4veTcwDWOf7PkXCjw+ubMMlkKuceNHWuM=';

Uint8List _der(String pem) => base64.decode(
  pem
      .split('\n')
      .where((line) => line.isNotEmpty && !line.startsWith('-----'))
      .join(),
);

void main() {
  test('the SPKI pin matches what openssl computes', () {
    expect(spkiSha256Base64(_der(_serverCert)), _serverPin);
  });

  test('pins parse from the dart-define list', () {
    expect(parsePins(' a= , ,b= '), {'a=', 'b='});
    expect(parsePins(''), isEmpty);
  });

  group('over a real TLS socket', () {
    late HttpServer server;
    late int requests;
    late SecurityContext trustTestCa;

    setUp(() async {
      requests = 0;
      final context = SecurityContext()
        ..useCertificateChainBytes(utf8.encode(_serverCert))
        ..usePrivateKeyBytes(utf8.encode(_serverKey));
      server = await HttpServer.bindSecure('localhost', 0, context);
      server.listen((request) {
        requests++;
        request.response
          ..headers.contentType = ContentType.json
          ..write(jsonEncode({'server_time': 'x', 'visits': []}))
          ..close();
      });
      // Normal chain validation still applies; the test CA is trusted here
      // the way a public CA is trusted in production.
      trustTestCa = SecurityContext(withTrustedRoots: false)
        ..setTrustedCertificatesBytes(utf8.encode(_serverCert));
    });

    tearDown(() async => server.close(force: true));

    ApiClient client(Set<String> pins) => ApiClient(
      baseUrl: 'https://localhost:${server.port}',
      client: createApiHttpClient(pins: pins.join(','), context: trustTestCa),
    )..token = 'secret-token';

    test('a matching pin (with a backup) connects', () async {
      final response = await client({_otherPin, _serverPin}).bootstrap();
      expect(response['visits'], isEmpty);
      expect(requests, 1);
    });

    test('a mismatched pin refuses before any byte is sent', () async {
      try {
        await client({_otherPin}).bootstrap();
        fail('a pin mismatch must not connect');
      } on ApiException catch (e) {
        expect(e.code, 'TLS');
        expect(e.isConnectivity, isTrue);
      }
      expect(requests, 0, reason: 'the bearer token never left the device');
    });

    test('without pins the client behaves exactly as before', () {
      expect(createApiHttpClient(pins: ''), isA<http.Client>());
    });
  });
}
