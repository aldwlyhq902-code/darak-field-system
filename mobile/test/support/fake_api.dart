import 'dart:convert';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:darak_field/core/action_outbox.dart';
import 'package:darak_field/core/api_client.dart';
import 'package:darak_field/core/backoff.dart';
import 'package:darak_field/core/event_queue.dart';
import 'package:darak_field/core/evidence_store.dart';
import 'package:darak_field/core/local_db.dart';
import 'package:darak_field/core/sync_engine.dart';
import 'package:darak_field/core/trusted_clock.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// One request as the fake server saw it.
class SeenRequest {
  SeenRequest(this.method, this.path, this.headers, this.bodyBytes);

  final String method;
  final String path;
  final Map<String, String> headers;
  final Uint8List bodyBytes;

  Map<String, dynamic> get json =>
      jsonDecode(utf8.decode(bodyBytes)) as Map<String, dynamic>;
}

/// A scriptable stand-in for the Laravel API, with the same idempotency and
/// resumable-upload contracts.
class FakeApi {
  final requests = <SeenRequest>[];
  final acceptedEventIds = <String>{};

  /// No route to the server at all: every request throws like a dead socket.
  bool offline = false;

  /// Answer every request with this status (and [failBody]).
  int? failWith;
  Map<String, dynamic> failBody = const {'code': 'SERVER', 'message': 'down'};

  /// Per-request override for /sync/events. Return null to fall through to the
  /// default (accept everything, report duplicates).
  http.Response? Function(List<Map<String, dynamic>> events)? onEvents;

  /// Per-request override for upload endpoints (status, chunk, complete).
  http.Response? Function(String path, SeenRequest request)? onMedia;

  /// Per-request override for any other POST (outbox actions).
  http.Response? Function(SeenRequest request)? onAction;

  /// Called while a /sync/events request is "in flight".
  Future<void> Function()? duringEvents;

  List<Map<String, dynamic>> bootstrapVisits = const [];
  List<Map<String, dynamic>> canonicalVisits = const [];

  /// Bytes received per media id, by offset.
  final uploads = <String, BytesBuilder>{};
  final completedHashes = <String, String>{};

  Iterable<SeenRequest> get eventRequests =>
      requests.where((r) => r.path.endsWith('/sync/events'));
  Iterable<SeenRequest> get mediaRequests =>
      requests.where((r) => r.path.contains('/media/'));

  List<String> get sentEventTypes => [
    for (final request in eventRequests)
      for (final event in (request.json['events'] as List))
        (event as Map)['event_type'] as String,
  ];

  http.Client get client => MockClient((request) async {
    final seen = SeenRequest(
      request.method,
      request.url.path,
      request.headers,
      request.bodyBytes,
    );
    requests.add(seen);

    if (offline) {
      throw http.ClientException('Connection refused', request.url);
    }
    if (failWith != null) {
      return http.Response(jsonEncode(failBody), failWith!);
    }

    final path = request.url.path;

    if (path.endsWith('/sync/events')) {
      final events = (seen.json['events'] as List).cast<Map<String, dynamic>>();
      if (duringEvents != null) await duringEvents!();
      final custom = onEvents?.call(events);
      if (custom != null) return custom;
      return _json({
        'server_time': DateTime.now().toUtc().toIso8601String(),
        'results': [
          for (final event in events)
            {
              'client_event_id': event['client_event_id'],
              'status': acceptedEventIds.add(event['client_event_id'] as String)
                  ? 'accepted'
                  : 'duplicate',
            },
        ],
        'visits': canonicalVisits,
      });
    }

    if (path.endsWith('/sync/bootstrap')) {
      return _json({
        'server_time': DateTime.now().toUtc().toIso8601String(),
        'visits': bootstrapVisits,
      });
    }

    if (path.contains('/media/')) {
      final custom = onMedia?.call(path, seen);
      if (custom != null) return custom;
      final id = request.url.pathSegments[request.url.pathSegments.length - 2];

      if (path.endsWith('/status')) {
        final received = uploads[id];
        if (received == null) {
          return _json({'code': 'MEDIA_NOT_FOUND'}, 404);
        }
        return _json({
          'upload_state': completedHashes.containsKey(id)
              ? 'complete'
              : 'uploading',
          'uploaded_bytes': received.length,
        });
      }
      if (path.endsWith('/chunk')) {
        final builder = uploads.putIfAbsent(id, BytesBuilder.new);
        final offset = int.parse(request.headers['x-upload-offset'] ?? '0');
        if (offset != builder.length) {
          return _json({
            'code': 'OFFSET_MISMATCH',
            'expected_offset': builder.length,
          }, 409);
        }
        builder.add(request.bodyBytes);
        return _json({'uploaded_bytes': builder.length});
      }
      if (path.endsWith('/complete')) {
        final expected = seen.json['sha256'] as String;
        final actual = sha256
            .convert(uploads[id]?.toBytes() ?? const [])
            .toString();
        if (expected != actual) {
          return _json({'code': 'HASH_MISMATCH'}, 422);
        }
        completedHashes[id] = actual;
        return _json({'status': 'complete'});
      }
      if (path.endsWith('/discard')) {
        return _json({'status': 'discarded'});
      }
    }

    if (request.method == 'POST') {
      final custom = onAction?.call(seen);
      if (custom != null) return custom;
      return _json({
        'data': {'ok': true},
        'message': 'تم.',
      }, 201);
    }

    return _json({'code': 'NOT_FOUND'}, 404);
  });

  static http.Response _json(
    Map<String, dynamic> body, [
    int status = 200,
    Map<String, String> headers = const {},
  ]) => http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json; charset=utf-8', ...headers},
  );

  static http.Response json(
    Map<String, dynamic> body, [
    int status = 200,
    Map<String, String> headers = const {},
  ]) => _json(body, status, headers);
}

SyncEngine engineFor(
  FakeApi server,
  LocalDb db,
  EventQueue queue,
  TrustedClock clock, {
  EvidenceStore? evidence,
  ActionOutbox? outbox,
  String? root,
  int batchByteLimit = 512 * 1024,
  int chunkSize = 256 * 1024,
}) => SyncEngine(
  api: ApiClient(baseUrl: 'https://test.local', client: server.client)
    ..token = 'token',
  queue: queue,
  db: db,
  clock: clock,
  deviceUuid: '0b9a3f4e-1111-4222-8333-444455556666',
  evidenceRoot: root,
  evidence: evidence,
  outbox: outbox,
  batchByteLimit: batchByteLimit,
  chunkSize: chunkSize,
  backoff: const Backoff(),
);
