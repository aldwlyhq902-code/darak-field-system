import 'dart:math';
import 'dart:typed_data';

/// The server refuses evidence images over 40 megapixels or 12000 px on a
/// side (422 IMAGE_TOO_LARGE), and no retry can change that answer.
///
/// The camera path already resizes to [captureMaxSide] on both axes, far
/// inside the limit; this check is the backstop at staging, so an oversized
/// image is refused while the technician is still in front of the equipment
/// rather than discovered as a failed upload after they have driven away.
class ImageLimits {
  const ImageLimits._();

  static const maxPixels = 40 * 1000 * 1000;
  static const maxSide = 12000;

  /// What `image_picker` is asked for, on BOTH axes. A width cap alone lets a
  /// tall panorama through.
  static const captureMaxSide = 1600;

  static bool withinServerLimits(int width, int height) =>
      max(width, height) <= maxSide && width * height <= maxPixels;

  /// Width and height from a PNG or JPEG header; null when the format is not
  /// recognised (the server remains the judge for those).
  static ({int width, int height})? dimensions(Uint8List bytes) {
    // PNG: signature, then the IHDR chunk with width/height at 16..23.
    if (bytes.length >= 24 &&
        bytes[0] == 0x89 &&
        bytes[1] == 0x50 &&
        bytes[2] == 0x4E &&
        bytes[3] == 0x47) {
      final view = ByteData.sublistView(bytes);
      return (width: view.getUint32(16), height: view.getUint32(20));
    }

    // JPEG: walk the segments to the first start-of-frame marker.
    if (bytes.length >= 4 && bytes[0] == 0xFF && bytes[1] == 0xD8) {
      var index = 2;
      while (index + 9 < bytes.length) {
        if (bytes[index] != 0xFF) {
          index++;
          continue;
        }
        final marker = bytes[index + 1];
        if (marker == 0xFF) {
          index++;
          continue;
        }
        if (marker == 0x01 || (marker >= 0xD0 && marker <= 0xD8)) {
          index += 2;
          continue;
        }
        if (marker == 0xDA || marker == 0xD9) break; // scan data / end
        final length = (bytes[index + 2] << 8) | bytes[index + 3];
        final isStartOfFrame =
            marker >= 0xC0 &&
            marker <= 0xCF &&
            marker != 0xC4 &&
            marker != 0xC8 &&
            marker != 0xCC;
        if (isStartOfFrame) {
          final height = (bytes[index + 5] << 8) | bytes[index + 6];
          final width = (bytes[index + 7] << 8) | bytes[index + 8];
          return (width: width, height: height);
        }
        index += 2 + length;
      }
    }

    return null;
  }
}
