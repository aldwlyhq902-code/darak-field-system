import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

class ApiException implements Exception {
  ApiException(this.statusCode, this.body);

  final int statusCode;
  final Map<String, dynamic> body;

  String get code => (body['code'] as String?) ?? 'HTTP_$statusCode';
  String get message => (body['message'] as String?) ?? 'Request failed.';

  /// 5xx and network errors are worth retrying; 4xx means the request itself is
  /// wrong and retrying forever would just burn battery.
  bool get isRetryable => statusCode >= 500 || statusCode == 0 || statusCode == 429;

  @override
  String toString() => '$code: $message';
}

class ApiClient {
  ApiClient({
    required this.baseUrl,
    http.Client? client,
    this.timeout = const Duration(seconds: 30),
    this.uploadTimeout = const Duration(seconds: 90),
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final http.Client _client;

  /// Without these, a half-open connection — a captive Wi-Fi that accepts the
  /// socket and never answers, or a tower handover mid-request — hangs sync
  /// forever. The engine's re-entrancy guard then makes every later sync a no-op
  /// and the app is bricked until it is force-killed.
  final Duration timeout;
  final Duration uploadTimeout;

  /// Bearer token for the current device session.
  String? token;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<Map<String, dynamic>> login({
    required String email,
    required String password,
    required String deviceUuid,
    String? appVersion,
  }) async {
    final response = await _post('/api/v1/auth/login', {
      'email': email,
      'password': password,
      'device_uuid': deviceUuid,
      'platform': 'android',
      if (appVersion != null) 'app_version': appVersion,
    });

    token = response['token'] as String?;

    return response;
  }

  Future<Map<String, dynamic>> bootstrap() => _get('/api/v1/sync/bootstrap');

  Future<Map<String, dynamic>> pushEvents({
    required String deviceUuid,
    required List<Map<String, dynamic>> events,
    DateTime? lastTrustedServerTime,
  }) =>
      _post('/api/v1/sync/events', {
        'device_uuid': deviceUuid,
        if (lastTrustedServerTime != null)
          'last_trusted_server_time': lastTrustedServerTime.toIso8601String(),
        'events': events,
      });

  Future<Map<String, dynamic>> mediaStatus(String clientMediaId) =>
      _get('/api/v1/media/$clientMediaId/status');

  /// Sends one chunk at [offset]. A 409 carries the offset the server actually
  /// holds, so the client can resume instead of starting the file again.
  Future<Map<String, dynamic>> uploadChunk({
    required String clientMediaId,
    required int offset,
    required Uint8List bytes,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/media/$clientMediaId/chunk'),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/octet-stream',
        'X-Upload-Offset': '$offset',
        if (token != null) 'Authorization': 'Bearer $token',
      },
      body: bytes,
    ).timeout(uploadTimeout);

    return _decode(response);
  }

  Future<Map<String, dynamic>> completeUpload({
    required String clientMediaId,
    required String sha256,
  }) =>
      _post('/api/v1/media/$clientMediaId/complete', {'sha256': sha256});

  /// Drops a file that will never upload, naming its replacement if one exists.
  Future<Map<String, dynamic>> discardMedia({
    required String clientMediaId,
    required String reason,
    String? supersededBy,
  }) =>
      _post('/api/v1/media/$clientMediaId/discard', {
        'reason': reason,
        if (supersededBy != null) 'superseded_by': supersededBy,
      });

  Future<Map<String, dynamic>> closeBlockers(int visitId) =>
      _get('/api/v1/visits/$visitId/close-blockers');

  Future<Map<String, dynamic>> _get(String path) async {
    try {
      final response = await _client
          .get(Uri.parse('$baseUrl$path'), headers: _headers)
          .timeout(timeout);
      return _decode(response);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(0, {'code': 'NETWORK', 'message': e.toString()});
    }
  }

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) async {
    try {
      final response = await _client.post(
        Uri.parse('$baseUrl$path'),
        headers: _headers,
        body: jsonEncode(body),
      ).timeout(timeout);
      return _decode(response);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(0, {'code': 'NETWORK', 'message': e.toString()});
    }
  }

  Map<String, dynamic> _decode(http.Response response) {
    final decoded = response.body.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(response.body) as Map<String, dynamic>;

    if (response.statusCode >= 400) {
      throw ApiException(response.statusCode, decoded);
    }

    return decoded;
  }
}
