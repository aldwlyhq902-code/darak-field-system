import 'package:flutter/foundation.dart';

const configuredApiBase = String.fromEnvironment('DARAK_API');

String resolveApiBase({
  String configured = configuredApiBase,
  bool releaseMode = kReleaseMode,
}) {
  final value = configured.trim();
  if (value.isEmpty) {
    if (releaseMode) {
      throw StateError(
        'DARAK_API is required for release builds. '
        'Build with --dart-define=DARAK_API=https://api.example.com.',
      );
    }
    return 'http://10.0.2.2:8000';
  }

  final uri = Uri.tryParse(value);
  if (uri == null || !uri.hasScheme || uri.host.isEmpty) {
    throw ArgumentError.value(
      configured,
      'DARAK_API',
      'Must be an absolute URL',
    );
  }
  if (releaseMode && uri.scheme != 'https') {
    throw StateError('DARAK_API must use HTTPS in release builds.');
  }
  if (releaseMode && uri.host == 'mihwar-api.vercel.app') {
    throw StateError(
      'The temporary Vercel API is forbidden in release builds.',
    );
  }
  return value.replaceFirst(RegExp(r'/+$'), '');
}
