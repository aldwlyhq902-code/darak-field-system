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
  bool get isRetryable =>
      statusCode >= 500 || statusCode == 0 || statusCode == 429;

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
  String locale = 'ar';

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Accept-Language': locale,
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
  }) => _post('/api/v1/sync/events', {
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
    final response = await _client
        .post(
          Uri.parse('$baseUrl/api/v1/media/$clientMediaId/chunk'),
          headers: {
            'Accept': 'application/json',
            'Accept-Language': locale,
            'Content-Type': 'application/octet-stream',
            'X-Upload-Offset': '$offset',
            if (token != null) 'Authorization': 'Bearer $token',
          },
          body: bytes,
        )
        .timeout(uploadTimeout);

    return _decode(response);
  }

  Future<Map<String, dynamic>> completeUpload({
    required String clientMediaId,
    required String sha256,
  }) => _post('/api/v1/media/$clientMediaId/complete', {'sha256': sha256});

  /// Drops a file that will never upload, naming its replacement if one exists.
  Future<Map<String, dynamic>> discardMedia({
    required String clientMediaId,
    required String reason,
    String? supersededBy,
  }) => _post('/api/v1/media/$clientMediaId/discard', {
    'reason': reason,
    if (supersededBy != null) 'superseded_by': supersededBy,
  });

  Future<Map<String, dynamic>> closeBlockers(int visitId) =>
      _get('/api/v1/visits/$visitId/close-blockers');

  Future<List<Map<String, dynamic>>> custodies() async =>
      _dataList(await _get('/api/v1/custodies'));

  Future<void> acceptCustody(int id, String name) async =>
      _post('/api/v1/custodies/$id/accept', {'accepted_name': name});

  Future<List<Map<String, dynamic>>> stocktakes() async =>
      _dataList(await _get('/api/v1/inventory/stocktakes'));

  Future<void> scanStocktake(int id, String code, double qty) async => _post(
    '/api/v1/inventory/stocktakes/$id/scan',
    {'code': code, 'qty': qty},
  );

  Future<void> completeStocktake(int id) async =>
      _post('/api/v1/inventory/stocktakes/$id/complete', {});

  Future<List<Map<String, dynamic>>> transferReleases() async =>
      _dataList(await _get('/api/v1/inventory/vehicle-transfer-releases'));

  Future<List<Map<String, dynamic>>> transferReceipts() async =>
      _dataList(await _get('/api/v1/inventory/vehicle-transfers'));

  Future<void> releaseTransfer(int id) async =>
      _post('/api/v1/inventory/vehicle-transfers/$id/release', {});

  Future<void> acceptTransfer(int id) async =>
      _post('/api/v1/inventory/vehicle-transfers/$id/accept', {});

  Future<Map<String, dynamic>> currentVehicle() =>
      _get('/api/v1/fleet/vehicle');

  Future<Map<String, dynamic>> submitVehicleInspection({
    required double odometerKm,
    required Map<String, bool> checklist,
    String? defects,
    Uint8List? photo,
  }) async {
    try {
      final request = http.MultipartRequest(
        'POST',
        Uri.parse('$baseUrl/api/v1/fleet/vehicle/inspection'),
      );
      request.headers.addAll({
        'Accept': 'application/json',
        'Accept-Language': locale,
        if (token != null) 'Authorization': 'Bearer $token',
      });
      request.fields['odometer_km'] = '$odometerKm';
      for (final entry in checklist.entries) {
        request.fields[entry.key] = entry.value ? '1' : '0';
      }
      if (defects != null && defects.trim().isNotEmpty) {
        request.fields['defects'] = defects.trim();
      }
      if (photo != null) {
        request.files.add(
          http.MultipartFile.fromBytes(
            'photo',
            photo,
            filename: 'vehicle-inspection.jpg',
          ),
        );
      }
      final streamed = await _client.send(request).timeout(uploadTimeout);
      return _decode(await http.Response.fromStream(streamed));
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(0, {'code': 'NETWORK', 'message': e.toString()});
    }
  }

  Future<Map<String, dynamic>> diagnosisSuggestions(
    int visitId,
    String faultCode,
  ) => _get(
    '/api/v1/visits/$visitId/diagnosis-suggestions?fault_code=${Uri.encodeQueryComponent(faultCode)}',
  );

  Future<void> recordDiagnosis({
    required int visitId,
    required String faultCode,
    required String diagnosisCode,
    required String summary,
  }) async => _post('/api/v1/visits/$visitId/diagnosis', {
    'fault_code': faultCode,
    'diagnosis_code': diagnosisCode,
    'resolution_summary': summary,
  });

  Future<Map<String, dynamic>> createAdditionalWork({
    required int visitId,
    required String title,
    required String description,
    required List<Map<String, dynamic>> items,
  }) => _post('/api/v1/visits/$visitId/additional-work', {
    'title': title,
    'description': description,
    'items': items,
  });

  Future<void> updateVisitLocation(int visitId, double lat, double lng) async =>
      _post('/api/v1/visits/$visitId/location', {'lat': lat, 'lng': lng});

  List<Map<String, dynamic>> _dataList(Map<String, dynamic> response) =>
      ((response['data'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList();

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

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body,
  ) async {
    try {
      final response = await _client
          .post(
            Uri.parse('$baseUrl$path'),
            headers: _headers,
            body: jsonEncode(body),
          )
          .timeout(timeout);
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
