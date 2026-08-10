import 'dart:typed_data';

import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';

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
  CameraCapture({ImagePicker? picker}) : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  Future<Capture?> takePhoto({int maxWidth = 1600, int quality = 82}) async {
    final file = await _picker.pickImage(
      source: ImageSource.camera,
      maxWidth: maxWidth.toDouble(),
      imageQuality: quality,
      preferredCameraDevice: CameraDevice.rear,
    );

    if (file == null) return null;

    final position = await _bestEffortPosition();

    return Capture(
      bytes: await file.readAsBytes(),
      mime: file.mimeType ?? 'image/jpeg',
      lat: position?.latitude,
      lng: position?.longitude,
    );
  }

  Future<Position?> _bestEffortPosition() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return null;
      }

      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 6),
        ),
      );
    } catch (_) {
      // No fix is a normal field condition, not an error worth interrupting work.
      return null;
    }
  }
}
