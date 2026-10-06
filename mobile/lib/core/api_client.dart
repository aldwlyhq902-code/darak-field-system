import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  ApiException(this.statusCode, this.body, {this.cause, this.retryAfter});

  final int statusCode;
  final Map<String, dynamic> body;

  /// The transport error behind a statusCode-0 failure. Debug logs only — it is
  /// English, technical, and never shown to a technician.
  final Object? cause;

  /// The server's own "come back in…" (`Retry-After` header or `retry_after`
  /// field, seconds), when it gave one. Preferred over our own backoff.
  final Duration? retryAfter;

  /// 403 codes that mean "this handset must forget everything" (contract C1).
  /// Any other 403 refuses that one request and nothing more.
  static const remoteWipeCodes = {'DEVICE_REVOKED', 'USER_INACTIVE'};

  /// Programming errors on our side of the idempotency contract (C2): logged
  /// loudly, shown generically, never retried.
  static const idempotencyBugCodes = {
    'IDEMPOTENCY_KEY_REUSED',
    'IDEMPOTENCY_KEY_INVALID',
  };

  String get code =>
      (body['code'] as String?) ??
      (statusCode == 0 ? 'NETWORK' : 'HTTP_$statusCode');
  String get message => (body['message'] as String?) ?? 'Request failed.';

  /// No answer at all: no route, DNS, a dropped socket, a timeout, a TLS
  /// failure. The request may or may not have landed.
  bool get isConnectivity => statusCode == 0;

  /// The server answered but cannot take the request right now — including a
  /// first attempt under the same idempotency key that is still running.
  bool get isServerTransient =>
      statusCode >= 500 ||
      statusCode == 429 ||
      statusCode == 408 ||
      body['code'] == 'IDEMPOTENCY_REQUEST_IN_PROGRESS';

  /// 5xx and network errors are worth retrying; 4xx means the request itself is
  /// wrong and retrying forever would just burn battery.
  bool get isRetryable => isConnectivity || isServerTransient;

  /// The token is dead (expired, or idle for 14 days). Sign in again; the
  /// queue is kept (C1).
  bool get isSessionExpired => statusCode == 401;

  /// The device is registered but a supervisor has not approved it yet. Back
  /// to the login screen with the queue and the data kept — never a wipe.
  bool get isPendingApproval =>
      statusCode == 403 && body['code'] == 'DEVICE_PENDING_APPROVAL';

  /// Revoked device or disabled account: the remote-wipe path (C1).
  bool get isRemoteWipe =>
      statusCode == 403 && remoteWipeCodes.contains(body['code']);

  /// Nothing more can be sent under this session: stop the sync here.
  bool get stopsSession =>
      isSessionExpired || isPendingApproval || isRemoteWipe;

  /// The server understood this exact request and refused it. Only these count
  /// against an item's attempt budget — a basement must never "use up" a photo.
  bool get isDefinitiveRejection =>
      statusCode >= 400 &&
      statusCode < 500 &&
      !isServerTransient &&
      !stopsSession;

  @override
  String toString() => '$code: $message';
}

/// A response plus what the idempotency layer said about it.
class ApiResult {
  const ApiResult(this.statusCode, this.body, {this.replayed = false});

  final int statusCode;
  final Map<String, dynamic> body;

  /// `Idempotent-Replayed: true` — the server had already applied this action
  /// under the same key and is replaying its answer. A success (contract C2).
  final bool replayed;
}

class ApiClient {
  ApiClient({
    required this.baseUrl,
    http.Client? client,
    this.timeout = const Duration(seconds: 30),
    this.uploadTimeout = const Duration(seconds: 90),
    this.appVersion,
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

  /// The real build version, sent on every request (contract C7).
  String? appVersion;

  /// Called for a 401 or a remote-wipe 403 on any AUTHENTICATED request, from
  /// whichever screen made it. The sign-out and wipe decisions live in one
  /// place instead of being re-implemented by every caller.
  void Function(ApiException error)? onAuthFailure;

  Map<String, String> _headers({
    String contentType = 'application/json',
    String? idempotencyKey,
  }) => {
    'Accept': 'application/json',
    'Accept-Language': locale,
    'Content-Type': contentType,
    if (appVersion != null) 'X-App-Version': appVersion!,
    if (token != null) 'Authorization': 'Bearer $token',
    if (idempotencyKey != null) 'Idempotency-Key': idempotencyKey,
  };

  /// Does NOT adopt the returned token: the caller validates the response
  /// (and checks the device's queue belongs to this user) first, so the app
  /// never looks signed in on a half-accepted login.
  Future<Map<String, dynamic>> login({
    required String email,
    required String password,
    required String deviceUuid,
    String? appVersion,
    Map<String, bool>? deviceIntegrity,
  }) => _post('/api/v1/auth/login', {
    'email': email,
    'password': password,
    'device_uuid': deviceUuid,
    'platform': 'android',
    if (appVersion != null) 'app_version': appVersion,
    if (deviceIntegrity != null) 'device_integrity': deviceIntegrity,
  });

  /// Best-effort server-side revocation of the device token. [bearer] revokes
  /// a token that was issued but never adopted (a login refused locally).
  Future<void> logout({String? bearer}) async {
    final auth = bearer ?? token;
    if (auth == null) return;
    await _guard(
      () => _client
          .post(
            Uri.parse('$baseUrl/api/v1/auth/logout'),
            headers: {..._headers(), 'Authorization': 'Bearer $auth'},
            body: '{}',
          )
          .timeout(timeout),
      reportAuthFailure: false,
    );
  }

  Future<Map<String, dynamic>> me() => _get('/api/v1/auth/me');

  Future<Map<String, dynamic>> bootstrap() => _get('/api/v1/sync/bootstrap');

  Future<Map<String, dynamic>> pushEvents({
    required String deviceUuid,
    required List<Map<String, dynamic>> events,
    DateTime? lastTrustedServerTime,
  }) => _post('/api/v1/sync/events', {
    'device_uuid': deviceUuid,
    if (lastTrustedServerTime != null)
      'last_trusted_server_time': lastTrustedServerTime
          .toUtc()
          .toIso8601String(),
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
    final result = await _guard(
      () => _client
          .post(
            Uri.parse('$baseUrl/api/v1/media/$clientMediaId/chunk'),
            headers: {
              ..._headers(contentType: 'application/octet-stream'),
              'X-Upload-Offset': '$offset',
            },
            body: bytes,
          )
          .timeout(uploadTimeout),
    );
    return result.body;
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

  Future<List<Map<String, dynamic>>> stocktakes() async =>
      _dataList(await _get('/api/v1/inventory/stocktakes'));

  Future<List<Map<String, dynamic>>> transferReleases() async =>
      _dataList(await _get('/api/v1/inventory/vehicle-transfer-releases'));

  Future<List<Map<String, dynamic>>> transferReceipts() async =>
      _dataList(await _get('/api/v1/inventory/vehicle-transfers'));

  Future<Map<String, dynamic>> currentVehicle() =>
      _get('/api/v1/fleet/vehicle');

  /// Needs the server's knowledge base, so it can only ever be online.
  Future<Map<String, dynamic>> diagnosisSuggestions(
    int visitId,
    String faultCode,
  ) => _get(
    '/api/v1/visits/$visitId/diagnosis-suggestions?fault_code=${Uri.encodeQueryComponent(faultCode)}',
  );

  /// Live ETA hint while driving. Advisory and online-only by nature; the
  /// position that matters travels on the queued transition event (C3).
  Future<void> updateVisitLocation(int visitId, double lat, double lng) async =>
      _post('/api/v1/visits/$visitId/location', {'lat': lat, 'lng': lng});

  /// A JSON action from the outbox, under its persisted Idempotency-Key (C2).
  Future<ApiResult> postAction({
    required String path,
    required Map<String, dynamic> body,
    required String idempotencyKey,
  }) => _guard(
    () => _client
        .post(
          Uri.parse('$baseUrl$path'),
          headers: _headers(idempotencyKey: idempotencyKey),
          body: jsonEncode(body),
        )
        .timeout(timeout),
  );

  /// A multipart action from the outbox (the vehicle inspection photo).
  Future<ApiResult> postMultipartAction({
    required String path,
    required Map<String, String> fields,
    required String idempotencyKey,
    String? fileField,
    Uint8List? fileBytes,
    String? fileName,
  }) => _guard(() async {
    final request = http.MultipartRequest('POST', Uri.parse('$baseUrl$path'));
    final headers = _headers(idempotencyKey: idempotencyKey)
      ..remove('Content-Type');
    request.headers.addAll(headers);
    request.fields.addAll(fields);
    if (fileField != null && fileBytes != null) {
      request.files.add(
        http.MultipartFile.fromBytes(
          fileField,
          fileBytes,
          filename: fileName ?? 'upload.jpg',
        ),
      );
    }
    final streamed = await _client.send(request).timeout(uploadTimeout);
    return http.Response.fromStream(streamed);
  });

  List<Map<String, dynamic>> _dataList(Map<String, dynamic> response) =>
      ((response['data'] as List?) ?? const [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList();

  Future<Map<String, dynamic>> _get(String path) async => (await _guard(
    () => _client
        .get(Uri.parse('$baseUrl$path'), headers: _headers())
        .timeout(timeout),
  )).body;

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body,
  ) async => (await _guard(
    () => _client
        .post(
          Uri.parse('$baseUrl$path'),
          headers: _headers(),
          body: jsonEncode(body),
        )
        .timeout(timeout),
  )).body;

  /// Every request funnels through here so a transport failure is always an
  /// [ApiException] with statusCode 0 — never a raw SocketException escaping
  /// into a button handler.
  Future<ApiResult> _guard(
    Future<http.Response> Function() send, {
    bool reportAuthFailure = true,
  }) async {
    final authenticated = reportAuthFailure && token != null;
    final http.Response response;

    try {
      response = await send();
    } on TimeoutException catch (e) {
      throw ApiException(0, const {
        'code': 'TIMEOUT',
        'message': 'The server did not answer in time.',
      }, cause: e);
    } on TlsException catch (e) {
      // Includes a certificate-pin mismatch. Same class as "no network" for
      // retry purposes, but worth telling apart in the message.
      throw ApiException(0, const {
        'code': 'TLS',
        'message': 'The server identity could not be verified.',
      }, cause: e);
    } catch (e) {
      throw ApiException(0, const {
        'code': 'NETWORK',
        'message': 'The server could not be reached.',
      }, cause: e);
    }

    return _decode(response, authenticated: authenticated);
  }

  ApiResult _decode(http.Response response, {required bool authenticated}) {
    final decoded = _safeJson(response.body);

    if (response.statusCode >= 400) {
      final error = ApiException(
        response.statusCode,
        decoded,
        retryAfter: _retryAfter(response, decoded),
      );

      if (ApiException.idempotencyBugCodes.contains(error.code)) {
        // A key reused for a DIFFERENT request, or a malformed key, is a bug
        // in this app, never something the technician did. Loud in the logs,
        // generic on screen.
        debugPrint('${error.code} on ${response.request?.url}');
      }

      if (authenticated && error.stopsSession) {
        onAuthFailure?.call(error);
      }

      throw error;
    }

    // Only 2xx answers are ever replayed: the server confirming that the
    // first attempt under this key landed (C2).
    final replayed =
        response.headers['idempotent-replayed']?.toLowerCase() == 'true';
    return ApiResult(response.statusCode, decoded, replayed: replayed);
  }

  static Duration? _retryAfter(
    http.Response response,
    Map<String, dynamic> body,
  ) {
    final header = int.tryParse(response.headers['retry-after']?.trim() ?? '');
    final field = body['retry_after'];
    final seconds = header ?? (field is num ? field.toInt() : null);
    if (seconds == null || seconds < 0) return null;
    return Duration(seconds: seconds);
  }

  /// A proxy's HTML error page is still an HTTP status worth classifying. It
  /// used to throw FormatException, which surfaced as "no network" and hid a
  /// 502 behind the wrong explanation.
  static Map<String, dynamic> _safeJson(String body) {
    if (body.isEmpty) return <String, dynamic>{};
    try {
      final decoded = jsonDecode(body);
      return decoded is Map<String, dynamic> ? decoded : <String, dynamic>{};
    } on FormatException {
      return <String, dynamic>{};
    }
  }
}
