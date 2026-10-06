import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:http/http.dart' as http;
import 'package:http/io_client.dart';

/// `--dart-define=DARAK_PINS=<pin>,<backup pin>` — base64 SHA-256 of the
/// server certificate's SubjectPublicKeyInfo. See mobile/README.md for how to
/// compute them and why a backup pin is mandatory.
const configuredPins = String.fromEnvironment('DARAK_PINS');

Set<String> parsePins(String raw) => raw
    .split(',')
    .map((pin) => pin.trim())
    .where((pin) => pin.isNotEmpty)
    .toSet();

/// The HTTP client for the API. Without pins it is the platform default —
/// exactly the behaviour before pinning existed. With pins, every TLS
/// connection must present a leaf certificate whose public key is one of them,
/// IN ADDITION to normal chain validation.
http.Client createApiHttpClient({
  String pins = configuredPins,
  SecurityContext? context,
}) {
  final parsed = parsePins(pins);
  if (parsed.isEmpty) return http.Client();
  return IOClient(pinnedHttpClient(parsed, context: context));
}

/// The pin is checked inside the connection factory, after the TLS handshake
/// and BEFORE HttpClient writes a single byte — the bearer token and the
/// request body never reach a server that fails the check.
HttpClient pinnedHttpClient(Set<String> pins, {SecurityContext? context}) {
  final client = HttpClient(context: context)
    // A proxy would terminate the connection somewhere else; pinning means
    // talking to the server directly.
    ..findProxy = (_) => 'DIRECT';

  client.connectionFactory = (uri, proxyHost, proxyPort) async {
    if (uri.scheme != 'https') {
      return Socket.startConnect(uri.host, uri.port);
    }

    final task = await SecureSocket.startConnect(
      uri.host,
      uri.port,
      context: context,
    );

    return ConnectionTask.fromSocket(
      task.socket.then((socket) {
        final certificate = socket.peerCertificate;
        final pin = certificate == null
            ? null
            : spkiSha256Base64(certificate.der);

        if (pin == null || !pins.contains(pin)) {
          socket.destroy();
          throw const HandshakeException('Server certificate pin mismatch');
        }
        return socket;
      }),
      task.cancel,
    );
  };

  return client;
}

/// base64(SHA-256(SubjectPublicKeyInfo)) — the same value
/// `openssl x509 -pubkey | openssl pkey -pubin -outform der | openssl dgst
/// -sha256 -binary | base64` prints.
String spkiSha256Base64(Uint8List certificateDer) =>
    base64.encode(sha256.convert(subjectPublicKeyInfo(certificateDer)).bytes);

/// Walks just enough DER to find tbsCertificate.subjectPublicKeyInfo.
///
///     Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm, signature }
///     TBSCertificate ::= SEQUENCE { [0] version OPTIONAL, serialNumber,
///         signature, issuer, validity, subject, subjectPublicKeyInfo, ... }
Uint8List subjectPublicKeyInfo(Uint8List der) {
  final certificate = _Tlv.at(der, 0);
  final tbs = _Tlv.at(der, certificate.contentStart);
  if (certificate.tag != 0x30 || tbs.tag != 0x30) {
    throw const FormatException('Not an X.509 certificate');
  }

  var element = _Tlv.at(der, tbs.contentStart);
  if (element.tag == 0xA0) element = _Tlv.at(der, element.end); // version
  // serialNumber, signature, issuer, validity, subject
  for (var skip = 0; skip < 5; skip++) {
    element = _Tlv.at(der, element.end);
  }
  if (element.tag != 0x30) {
    throw const FormatException('subjectPublicKeyInfo not found');
  }
  return Uint8List.sublistView(der, element.start, element.end);
}

class _Tlv {
  const _Tlv(this.tag, this.start, this.contentStart, this.end);

  final int tag;
  final int start;
  final int contentStart;
  final int end;

  static _Tlv at(Uint8List bytes, int offset) {
    if (offset + 2 > bytes.length) throw const FormatException('Truncated DER');
    final tag = bytes[offset];
    var index = offset + 1;
    var length = bytes[index++];
    if (length & 0x80 != 0) {
      final count = length & 0x7f;
      if (count == 0 || count > 4 || index + count > bytes.length) {
        throw const FormatException('Unsupported DER length');
      }
      length = 0;
      for (var i = 0; i < count; i++) {
        length = (length << 8) | bytes[index++];
      }
    }
    final end = index + length;
    if (end > bytes.length) throw const FormatException('Truncated DER');
    return _Tlv(tag, offset, index, end);
  }
}
