import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite/sqflite.dart' show ConflictAlgorithm;
import 'package:uuid/uuid.dart';

import 'core/api_client.dart';
import 'core/camera_capture.dart';
import 'core/event_queue.dart';
import 'core/evidence_store.dart';
import 'core/local_db.dart';
import 'core/secure_token_store.dart';
import 'core/sync_engine.dart';
import 'core/trusted_clock.dart';
import 'core/visit_progress.dart';

/// Single place the UI reads from. Deliberately small — the interesting logic
/// lives in the queue and the sync engine, which are unit-tested.
class AppState extends ChangeNotifier {
  AppState({required this.baseUrl});

  final String baseUrl;

  late final ApiClient api = ApiClient(baseUrl: baseUrl);
  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late SyncEngine sync;
  late EvidenceStore evidence;
  late VisitProgress progress;

  final SecureTokenStore tokens = SecureTokenStore();
  final CameraCapture camera = CameraCapture();

  String? deviceUuid;
  String? technicianName;
  bool ready = false;
  bool online = true;
  bool syncing = false;
  bool sessionExpired = false;
  String? lastSyncMessage;
  DateTime? lastSyncedAt;

  List<Map<String, dynamic>> visits = const [];
  Map<QueuedStatus, int> queueCounts = const {};

  StreamSubscription<List<ConnectivityResult>>? _connectivity;

  @override
  void dispose() {
    _connectivity?.cancel();
    super.dispose();
  }

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    final dir = await getApplicationDocumentsDirectory();

    deviceUuid = prefs.getString('device_uuid');
    if (deviceUuid == null) {
      deviceUuid = const Uuid().v4();
      await prefs.setString('device_uuid', deviceUuid!);
    }

    db = await LocalDb.open(directory: dir.path);
    clock = await TrustedClock.load();
    queue = EventQueue(db, clock);
    sync = SyncEngine(
      api: api,
      queue: queue,
      db: db,
      clock: clock,
      deviceUuid: deviceUuid!,
      evidenceRoot: dir.path,
    );
    evidence = EvidenceStore(db: db, queue: queue, clock: clock, rootDirectory: dir.path);
    progress = VisitProgress(db);

    // Queued work used to sit untouched until someone tapped sync. A technician
    // who walks out of a basement and back into coverage should not have to
    // remember to do that.
    _connectivity = Connectivity().onConnectivityChanged.listen((results) {
      final hasNetwork = results.any((r) => r != ConnectivityResult.none);

      if (hasNetwork && isAuthenticated && !syncing) {
        unawaited(runSync());
      }
    });

    // The credential comes from the keystore, not from the sqlite file.
    api.token = await tokens.readToken();
    technicianName = await tokens.readTechnicianName();

    // The day's work is already on the device, so the list renders with no
    // network at all — that is the whole point of the local-first design.
    await refreshLocal();

    ready = true;
    notifyListeners();
  }

  bool get isAuthenticated => api.token != null;

  Future<String?> login(String email, String password) async {
    try {
      final response = await api.login(
        email: email,
        password: password,
        deviceUuid: deviceUuid!,
        appVersion: '0.1.0',
      );

      await tokens.writeToken(response['token'] as String);
      technicianName = (response['user'] as Map<String, dynamic>)['name'] as String?;
      await tokens.writeTechnicianName(technicianName ?? '');

      final serverTime = DateTime.tryParse(response['server_time'] as String? ?? '');
      if (serverTime != null) await clock.adopt(serverTime);

      await pullWork();
      notifyListeners();
      return null;
    } on ApiException catch (e) {
      return e.message;
    }
  }

  Future<void> refreshLocal() async {
    visits = await db.todayVisits();
    queueCounts = await queue.counts();
    notifyListeners();
  }

  Future<void> pullWork() async {
    try {
      await sync.bootstrap();
      online = true;
      lastSyncedAt = DateTime.now();
    } on ApiException catch (e) {
      online = false;
      lastSyncMessage = e.message;
    }

    await refreshLocal();
  }

  Future<void> runSync() async {
    // Coalesce concurrent calls. Every checklist tap fires a background sync, so
    // without this a burst of taps produced overlapping runs that each reported
    // "nothing to sync" and stamped a fresh success time over a real one.
    if (syncing) {
      return;
    }

    syncing = true;
    notifyListeners();

    final outcome = await sync.sync();

    if (outcome == null) {
      syncing = false;
      notifyListeners();
      return;
    }

    // A dead token cannot be retried into life. Clear it and let the UI fall back
    // to the login screen; the queue is untouched and syncs after signing in.
    if (outcome.sessionExpired) {
      await tokens.clear();
      api.token = null;
      sessionExpired = true;
      syncing = false;
      lastSyncMessage = 'انتهت الجلسة — سجّل الدخول مرة أخرى. عملك محفوظ ولم يضع منه شيء.';
      notifyListeners();
      return;
    }

    online = outcome.error == null;
    lastSyncedAt = online ? DateTime.now() : lastSyncedAt;
    lastSyncMessage = switch (outcome) {
      _ when outcome.error != null => 'تعذّرت المزامنة: ${outcome.error}',
      _ when outcome.rejected > 0 => 'رُفضت ${outcome.rejected} عملية — راجع شاشة المزامنة',
      _ when outcome.heldBack > 0 =>
        'بانتظار اكتمال رفع الأدلة قبل إرسال الإقفال (${outcome.heldBack})',
      _ when outcome.total == 0 => 'لا شيء بانتظار المزامنة',
      _ => 'تمت مزامنة ${outcome.accepted + outcome.duplicate} عملية',
    };

    if (online) {
      await pullWork();
    }

    syncing = false;
    await refreshLocal();
  }

  /// Every field action goes through here: written locally first, sent later.
  Future<void> record(
    int visitId,
    String eventType, {
    Map<String, dynamic> payload = const {},
  }) async {
    await queue.enqueue(visitId: visitId, eventType: eventType, payload: payload);
    await refreshLocal();
    unawaited(runSync());
  }

  /// Moves the visit forward. The local state advances immediately so the next
  /// button appears with no network; the server's canonical state overrides it on
  /// the next sync. Returns false when the transition is not legal from here.
  Future<bool> transition(int visitId, String target) async {
    final applied = await progress.applyTransition(visitId, target);

    if (!applied) {
      return false;
    }

    await record(visitId, 'visit.transition', payload: {'to': target});

    return true;
  }

  Future<List<String>> nextStates(int visitId) => progress.nextStates(visitId);

  /// Explicit per-visit declaration, decoupled from asset status.
  ///
  /// It used to be inferred from status == 'ok', which broke both ways: a faulty
  /// asset could never satisfy the gate, and a healthy asset whose part had just
  /// been replaced silently asserted "no parts used" — the exact stock leak the
  /// gate exists to catch.
  Future<void> declareNoParts(int visitId, bool value) async {
    await db.raw.update(
      'local_checklists',
      {'no_parts_used': value ? 1 : 0},
      where: 'visit_id = ?',
      whereArgs: [visitId],
    );

    await record(visitId, 'parts.declaration', payload: {'no_parts_used': value});
    await refreshLocal();
  }

  Future<bool> noPartsDeclared(int visitId) async {
    final rows = await db.raw.rawQuery(
      'SELECT COUNT(*) AS c FROM local_checklists WHERE visit_id = ? AND no_parts_used = 1',
      [visitId],
    );

    return ((rows.first['c'] as int?) ?? 0) > 0;
  }

  Future<void> setChecklist(
    int visitId,
    int assetId, {
    required String status,
    String? note,
    bool noPartsUsed = false,
  }) async {
    await db.raw.insert(
      'local_checklists',
      {
        'visit_id': visitId,
        'asset_id': assetId,
        'status': status,
        'note': note,
        'no_parts_used': noPartsUsed ? 1 : 0,
        'updated_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );

    await record(visitId, 'checklist.upsert', payload: {
      'asset_id': assetId,
      'status': status,
      'note': note,
      'no_parts_used': noPartsUsed,
    });
  }

  /// Camera capture for one asset. Returns null when the technician cancels.
  Future<String?> capturePhoto({
    required int visitId,
    required int assetId,
    String kind = EvidenceStore.kindPhotoAfter,
  }) async {
    final capture = await camera.takePhoto();
    if (capture == null) return null;

    final id = await evidence.store(
      visitId: visitId,
      kind: kind,
      bytes: capture.bytes,
      mime: capture.mime,
      assetId: assetId,
      lat: capture.lat,
      lng: capture.lng,
    );

    await refreshLocal();
    unawaited(runSync());
    return id;
  }

  Future<String> captureSignature({
    required int visitId,
    required Uint8List png,
    required String signerName,
    required String signerRole,
  }) async {
    final id = await evidence.store(
      visitId: visitId,
      kind: EvidenceStore.kindSignature,
      bytes: png,
      mime: 'image/png',
      declaredSource: 'on_screen',
    );

    // The name and role travel as their own event so the signature image is never
    // the only record of who approved the work.
    await record(visitId, 'signature.captured', payload: {
      'client_media_id': id,
      'signer_name': signerName,
      'signer_role': signerRole,
    });

    return id;
  }

  /// What the close gate can see locally, so the technician is told what is
  /// missing while still standing in front of the equipment.
  Future<({int photos, bool signature})> evidenceSummary(int visitId) async {
    return (
      photos: await evidence.photoCount(visitId),
      signature: await evidence.hasSignature(visitId),
    );
  }

  Future<Map<int, Map<String, dynamic>>> checklistFor(int visitId) async {
    final rows = await db.raw.query(
      'local_checklists',
      where: 'visit_id = ?',
      whereArgs: [visitId],
    );

    return {for (final row in rows) row['asset_id'] as int: row};
  }
}
