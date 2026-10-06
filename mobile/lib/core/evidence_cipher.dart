import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart' as crypto;
import 'package:pointycastle/export.dart'
    show AEADParameters, AESEngine, GCMBlockCipher, KeyParameter;

/// Encryption at rest for evidence photos, signatures and queued attachments.
///
/// The SQLCipher database was encrypted, but the photos and signatures it
/// pointed at sat in plain JPEG/PNG beside it — customer premises and
/// signatures readable by anything that could read the app's files.
///
/// Format (one file per capture):
///
///     header  = "DRKE" | version u8 | segmentSize u32 | plaintextLength u64
///               | noncePrefix 8 bytes                              (25 bytes)
///     segment = AES-256-GCM(plaintext[i*S .. (i+1)*S]) | tag (16 bytes)
///
/// Segmented on purpose: the resumable uploader sends PLAINTEXT from an
/// arbitrary byte offset (the server hashes what it receives and compares it to
/// the capture-time hash). With fixed-size segments any offset is reachable by
/// decrypting one or two segments in memory — no decrypted copy of the file
/// ever touches the disk. Each segment's nonce is the file's random prefix plus
/// the segment index, and the AAD binds the header, the index and a
/// "last segment" flag, so segments cannot be reordered, swapped between
/// files, or the file silently truncated.
class EvidenceCipher {
  EvidenceCipher(List<int> key, {this.segmentSize = defaultSegmentSize})
    : _key = Uint8List.fromList(key) {
    if (_key.length != 32) {
      throw ArgumentError.value(key.length, 'key', 'Must be 32 bytes');
    }
  }

  static const defaultSegmentSize = 64 * 1024;
  static const headerLength = 25;
  static const tagLength = 16;
  static const _version = 1;
  static const _magic = [0x44, 0x52, 0x4B, 0x45]; // DRKE

  final Uint8List _key;
  final int segmentSize;

  static final Random _random = Random.secure();

  /// True when [bytes] start with this format's header.
  static bool looksEncrypted(List<int> bytes) =>
      bytes.length >= headerLength &&
      bytes[0] == _magic[0] &&
      bytes[1] == _magic[1] &&
      bytes[2] == _magic[2] &&
      bytes[3] == _magic[3];

  Uint8List encrypt(Uint8List plaintext) {
    final header = ByteData(headerLength);
    for (var i = 0; i < 4; i++) {
      header.setUint8(i, _magic[i]);
    }
    header.setUint8(4, _version);
    header.setUint32(5, segmentSize);
    header.setUint64(9, plaintext.length);
    for (var i = 0; i < 8; i++) {
      header.setUint8(17 + i, _random.nextInt(256));
    }
    final headerBytes = header.buffer.asUint8List();

    final segments = _segmentCount(plaintext.length, segmentSize);
    final out = BytesBuilder(copy: false)..add(headerBytes);

    for (var index = 0; index < segments; index++) {
      final start = index * segmentSize;
      final end = min(start + segmentSize, plaintext.length);
      out.add(
        _crypt(
          encrypting: true,
          header: headerBytes,
          index: index,
          last: index == segments - 1,
          input: Uint8List.sublistView(plaintext, start, end),
        ),
      );
    }

    return out.toBytes();
  }

  /// Writes [plaintext] encrypted to [file], flushed to disk.
  Future<void> encryptToFile(Uint8List plaintext, File file) =>
      file.writeAsBytes(encrypt(plaintext), flush: true);

  /// Whole-file decrypt, for small attachments.
  Future<Uint8List> decryptFile(File file) async {
    final reader = await open(file);
    try {
      return await reader.read(0, reader.length);
    } finally {
      await reader.close();
    }
  }

  /// Random-access plaintext reader over an encrypted file.
  Future<EvidenceReader> open(File file) async {
    final handle = await file.open();
    try {
      final header = await handle.read(headerLength);
      if (!looksEncrypted(header) || header[4] != _version) {
        throw const FormatException('Not an encrypted evidence file');
      }
      final view = ByteData.sublistView(Uint8List.fromList(header));
      final segment = view.getUint32(5);
      final length = view.getUint64(9);
      if (segment <= 0 || length < 0) {
        throw const FormatException('Corrupt evidence header');
      }
      return _EncryptedReader(
        this,
        handle,
        Uint8List.fromList(header),
        segment,
        length,
      );
    } catch (_) {
      await handle.close();
      rethrow;
    }
  }

  Uint8List _crypt({
    required bool encrypting,
    required Uint8List header,
    required int index,
    required bool last,
    required Uint8List input,
  }) {
    final nonce = Uint8List(12)..setRange(0, 8, header, 17);
    ByteData.sublistView(nonce).setUint32(8, index);

    final aad = Uint8List(headerLength + 5)..setRange(0, headerLength, header);
    ByteData.sublistView(aad).setUint32(headerLength, index);
    aad[headerLength + 4] = last ? 1 : 0;

    final gcm = GCMBlockCipher(AESEngine())
      ..init(
        encrypting,
        AEADParameters(KeyParameter(_key), tagLength * 8, nonce, aad),
      );
    // Throws InvalidCipherTextException when the tag does not verify: a
    // tampered, truncated or mis-keyed file never yields "plaintext".
    return gcm.process(input);
  }

  static int _segmentCount(int length, int segment) =>
      length == 0 ? 1 : (length + segment - 1) ~/ segment;
}

/// Plaintext bytes of a piece of evidence, however it is stored.
abstract class EvidenceReader {
  /// Plaintext length — what the server receives and hashes.
  int get length;

  Future<Uint8List> read(int offset, int length);

  Future<void> close();

  /// SHA-256 of the plaintext, streamed.
  Future<String> sha256Hex({int chunk = 256 * 1024}) async {
    final output = _DigestSink();
    final input = crypto.sha256.startChunkedConversion(output);
    for (var offset = 0; offset < length; offset += chunk) {
      input.add(await read(offset, min(chunk, length - offset)));
    }
    input.close();
    return output.value.toString();
  }
}

/// Legacy captures written before encryption, until [EvidenceStore.reconcile]
/// migrates them.
class PlainEvidenceReader extends EvidenceReader {
  PlainEvidenceReader._(this._handle, this.length);

  static Future<PlainEvidenceReader> open(File file) async {
    final handle = await file.open();
    return PlainEvidenceReader._(handle, await handle.length());
  }

  final RandomAccessFile _handle;

  @override
  final int length;

  @override
  Future<Uint8List> read(int offset, int length) async {
    await _handle.setPosition(offset);
    return _handle.read(length);
  }

  @override
  Future<void> close() => _handle.close();
}

class _EncryptedReader extends EvidenceReader {
  _EncryptedReader(
    this._cipher,
    this._handle,
    this._header,
    this._segmentSize,
    this.length,
  );

  final EvidenceCipher _cipher;
  final RandomAccessFile _handle;
  final Uint8List _header;
  final int _segmentSize;

  @override
  final int length;

  int? _cachedIndex;
  Uint8List? _cachedPlain;

  int get _segments => EvidenceCipher._segmentCount(length, _segmentSize);

  @override
  Future<Uint8List> read(int offset, int count) async {
    if (offset < 0 || count < 0 || offset > length) {
      throw RangeError.range(offset, 0, length, 'offset');
    }
    final end = min(offset + count, length);
    if (end <= offset) return Uint8List(0);

    final first = offset ~/ _segmentSize;
    final last = (end - 1) ~/ _segmentSize;
    final out = BytesBuilder(copy: false);

    for (var index = first; index <= last; index++) {
      final plain = await _segment(index);
      final segmentStart = index * _segmentSize;
      final from = max(offset, segmentStart) - segmentStart;
      final to = min(end, segmentStart + plain.length) - segmentStart;
      out.add(Uint8List.sublistView(plain, from, to));
    }

    return out.toBytes();
  }

  Future<Uint8List> _segment(int index) async {
    if (_cachedIndex == index && _cachedPlain != null) return _cachedPlain!;

    final plainLength = min(_segmentSize, length - index * _segmentSize);
    final cipherLength = plainLength + EvidenceCipher.tagLength;
    final position =
        EvidenceCipher.headerLength +
        index * (_segmentSize + EvidenceCipher.tagLength);

    await _handle.setPosition(position);
    final bytes = await _handle.read(cipherLength);
    if (bytes.length != cipherLength) {
      throw const FormatException('Encrypted evidence file is truncated');
    }

    final plain = _cipher._crypt(
      encrypting: false,
      header: _header,
      index: index,
      last: index == _segments - 1,
      input: bytes,
    );
    _cachedIndex = index;
    _cachedPlain = plain;
    return plain;
  }

  @override
  Future<void> close() => _handle.close();
}

class _DigestSink implements Sink<crypto.Digest> {
  late crypto.Digest value;

  @override
  void add(crypto.Digest data) => value = data;

  @override
  void close() {}
}
