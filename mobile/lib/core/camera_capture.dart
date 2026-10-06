import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart' show debugPrint;
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;

import 'image_limits.dart';
import 'location.dart';

class Capture {
  const Capture({required this.bytes, required this.mime, this.lat, this.lng});

  final Uint8List bytes;
  final String mime;
  final double? lat;
  final double? lng;
}

/// Camera capture with a location fix attached.
///
/// `ImageSource.camera` is the only source offered. There is no gallery path in
/// the app at all, which is a policy not a proof — a determined person can still
/// photograph a screen. It raises the effort enough to matter and pairs with the
/// server-side hash and the burned-in timestamp.
///
/// The location is best-effort on purpose. A basement kitchen often has no fix,
/// and refusing to let the technician photograph the unit because the GPS is cold
/// would block real work to satisfy a metadata field.
class CameraCapture {
  CameraCapture({ImagePicker? picker, LocationSource? location})
    : _picker = picker ?? ImagePicker(),
      _location = location ?? GeolocatorLocationSource();

  final ImagePicker _picker;
  final LocationSource _location;

  Future<Capture?> takePhoto({
    int maxSide = ImageLimits.captureMaxSide,
    int quality = 82,
  }) async {
    final file = await _picker.pickImage(
      source: ImageSource.camera,
      // Both axes: a width cap alone let a tall panorama through, and the
      // server refuses anything over 40 MP / 12000 px for good.
      maxWidth: maxSide.toDouble(),
      maxHeight: maxSide.toDouble(),
      imageQuality: quality,
      preferredCameraDevice: CameraDevice.rear,
    );

    if (file == null) return null;

    try {
      final position = await _location.fresh(
        timeout: const Duration(seconds: 4),
      );

      return Capture(
        bytes: await file.readAsBytes(),
        mime: file.mimeType ?? _mimeFromName(file.path),
        lat: position?.lat,
        lng: position?.lng,
      );
    } finally {
      // image_picker leaves its copy (and a scaled copy) in the app cache,
      // unencrypted and never cleaned up. The bytes are now in memory and are
      // about to be stored encrypted; the plaintext copy must not outlive this.
      await deleteQuietly(file.path);
    }
  }

  static String _mimeFromName(String path) =>
      switch (p.extension(path).toLowerCase()) {
        '.png' => 'image/png',
        '.webp' => 'image/webp',
        _ => 'image/jpeg',
      };

  static Future<void> deleteQuietly(String path) async {
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } catch (error) {
      debugPrint('Could not delete capture copy $path: $error');
    }
  }

  /// Startup sweep for copies left behind by a capture the process did not
  /// survive (Android often kills the app while the camera is in front).
  static Future<int> purgeLeftovers(String cacheDirectory) async {
    final cache = Directory(cacheDirectory);
    if (!await cache.exists()) return 0;

    var removed = 0;
    await for (final entity in cache.list()) {
      if (entity is! File) continue;
      final name = p.basename(entity.path).toLowerCase();
      final isPickerCopy =
          name.startsWith('image_picker') || name.startsWith('scaled_');
      if (isPickerCopy) {
        await deleteQuietly(entity.path);
        removed++;
      }
    }
    return removed;
  }
}
