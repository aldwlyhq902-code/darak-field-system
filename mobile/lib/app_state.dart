import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import 'core/action_outbox.dart';
import 'core/api_client.dart';
import 'core/camera_capture.dart';
import 'core/device_platform.dart';
import 'core/device_wipe.dart';
import 'core/error_messages.dart';
import 'core/event_queue.dart';
import 'core/evidence_cipher.dart';
import 'core/evidence_store.dart';
import 'core/field_recorder.dart';
import 'core/geofence.dart';
import 'core/local_db.dart';
import 'core/location.dart';
import 'core/login_session.dart';
import 'core/pinned_http.dart';
import 'core/secure_token_store.dart';
import 'core/sync_engine.dart';
import 'core/sync_message.dart';
import 'core/trusted_clock.dart';
import 'core/visit_progress.dart';

/// Single place the UI reads from. Deliberately small — the interesting logic
/// lives in the queue, the outbox and the sync engine, which are unit-tested.
class AppState extends ChangeNotifier with WidgetsBindingObserver {
  AppState({
    required this.baseUrl,
    http.Client? httpClient,
    this.platform = const DevicePlatform(),
    LocationSource? location,
    this.syncInterval = const Duration(minutes: 2),
  }) : _httpClient = httpClient,
       location = location ?? GeolocatorLocationSource();

  final String baseUrl;
  final http.Client? _httpClient;
  final DevicePlatform platform;
  final LocationSource location;

  /// Foreground safety net: queued work goes out even if no connectivity
  /// change and no tap ever triggers it.
  final Duration syncInterval;

  late final ApiClient api = ApiClient(
    baseUrl: baseUrl,
    client: _httpClient ?? createApiHttpClient(),
  );
  late LocalDb db;
  late TrustedClock clock;
  late EventQueue queue;
  late SyncEngine sync;
  late EvidenceStore evidence;
  late VisitProgress progress;
  late ActionOutbox outbox;
  late FieldRecorder recorder;

  final SecureTokenStore tokens = SecureTokenStore();
  late final CameraCapture camera = CameraCapture(location: location);

  String? deviceUuid;
  String? technicianName;
  int? userId;
  String? appVersion;
  bool ready = false;
  bool online = true;
  bool syncing = false;
  bool sessionExpired = false;
  String? lastSyncMessage;
  DateTime? lastSyncedAt;
  Locale locale = const Locale('ar');

  /// Shown on the login screen: why the technician is there (session expired,
  /// device revoked and wiped).
  String? loginNotice;

  List<Map<String, dynamic>> visits = const [];
  Map<QueuedStatus, int> queueCounts = const {};
  List<PendingAction> outstandingActions = const [];
  int pendingUploads = 0;

  StreamSubscription<List<ConnectivityResult>>? _connectivity;
  Timer? _periodic;
  bool _observing = false;
  bool _syncAgain = false;
  bool _wiping = false;
  DateTime? _lastPulledAt;
  late String _documentsPath;
  String? _cachePath;
  SharedPreferences? _prefs;

  @override
  void dispose() {
    _connectivity?.cancel();
    _periodic?.cancel();
    if (_observing) WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> init() async {
    await _connectivity?.cancel();
    _connectivity = null;
    _periodic?.cancel();
    ready = false;

    final prefs = _prefs = await SharedPreferences.getInstance();
    locale = Locale(prefs.getString('ui_locale') == 'en' ? 'en' : 'ar');
    api.locale = locale.languageCode;

    // The real build version, on login and on every request (contract C7).
    // It was hard-coded '0.1.0' long after 0.2.0 shipped.
    try {
      final info = await PackageInfo.fromPlatform();
      appVersion = info.buildNumber.isEmpty
          ? info.version
          : '${info.version}+${info.buildNumber}';
    } catch (error) {
      debugPrint('Package info unavailable: $error');
    }
    api.appVersion = appVersion;
    api.onAuthFailure = _onAuthFailure;

    final dir = await getApplicationDocumentsDirectory();
    _documentsPath = dir.path;
    try {
      _cachePath = (await getTemporaryDirectory()).path;
    } catch (_) {
      _cachePath = null;
    }

    deviceUuid = prefs.getString('device_uuid');
    if (deviceUuid == null) {
      deviceUuid = const Uuid().v4();
      await prefs.setString('device_uuid', deviceUuid!);
    }

    final databaseKey = await tokens.readOrCreateDatabaseKey();
    db = await LocalDb.open(directory: dir.path, password: databaseKey);
    await DeviceWipe.restoreSequenceFloor(db, prefs);
    clock = await TrustedClock.load(prefs: prefs);
    queue = EventQueue(db, clock);

    final cipher = EvidenceCipher(await tokens.readOrCreateEvidenceKey());
    evidence = EvidenceStore(
      db: db,
      queue: queue,
      clock: clock,
      rootDirectory: dir.path,
      cipher: cipher,
    );
    // Also encrypts, in place, any evidence captured before encryption at rest.
    await evidence.reconcile();
    if (_cachePath != null) {
      await CameraCapture.purgeLeftovers(_cachePath!);
    }

    progress = VisitProgress(db, now: () => clock.bestEstimate);
    recorder = FieldRecorder(
      db: db,
      queue: queue,
      progress: progress,
      now: () => clock.bestEstimate,
    );
    outbox = ActionOutbox(db, attachmentRoot: dir.path, cipher: cipher);
    sync = SyncEngine(
      api: api,
      queue: queue,
      db: db,
      clock: clock,
      deviceUuid: deviceUuid!,
      progress: progress,
      evidenceRoot: dir.path,
      evidence: evidence,
      outbox: outbox,
    );

    // Queued work used to sit untouched until someone tapped sync. A technician
    // who walks out of a basement and back into coverage should not have to
    // remember to do that.
    _connectivity = Connectivity().onConnectivityChanged.listen((results) {
      final hasNetwork = results.any((r) => r != ConnectivityResult.none);
      if (hasNetwork) unawaited(_autoSync());
    });

    // Credentials and the SQLCipher key come from the platform keystore.
    api.token = await tokens.readToken();
    technicianName = await tokens.readTechnicianName();
    userId = await tokens.readUserId();
    if (userId != null) await db.adoptOrphans(userId!);

    if (!_observing) {
      WidgetsBinding.instance.addObserver(this);
      _observing = true;
    }
    _startPeriodic();

    // The day's work is already on the device, so the list renders with no
    // network at all — that is the whole point of the local-first design.
    await refreshLocal();

    ready = true;
    notifyListeners();
  }

  bool get isAuthenticated => api.token != null;

  bool get isArabic => locale.languageCode == 'ar';

  /// How far the phone's clock is from the server's, when knowable.
  Duration? get clockDrift => ready ? clock.suspectedDrift : null;

  Future<void> toggleLocale() async {
    locale = Locale(isArabic ? 'en' : 'ar');
    api.locale = locale.languageCode;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('ui_locale', locale.languageCode);
    notifyListeners();
  }

  // ------------------------------------------------------------ lifecycle

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.resumed:
        // The phone may have slept for an hour; read the real elapsed time.
        if (ready) unawaited(clock.refresh());
        _startPeriodic();
        unawaited(_autoSync());
      case AppLifecycleState.paused:
      case AppLifecycleState.detached:
        _periodic?.cancel();
        _periodic = null;
      default:
        break;
    }
  }

  void _startPeriodic() {
    _periodic?.cancel();
    _periodic = Timer.periodic(syncInterval, (_) => unawaited(_autoSync()));
  }

  /// A sync nobody asked for: on resume, on connectivity, on the timer. It
  /// respects retry backoff and pulls the day only every few minutes.
  Future<void> _autoSync() async {
    if (!ready || !isAuthenticated || syncing || _wiping) return;

    try {
      final results = await Connectivity().checkConnectivity();
      if (results.every((r) => r == ConnectivityResult.none)) {
        if (online) {
          online = false;
          notifyListeners();
        }
        return;
      }
    } catch (_) {
      // Unknown connectivity: just try.
    }

    final last = _lastPulledAt;
    final pull =
        last == null ||
        DateTime.now().difference(last) > const Duration(minutes: 10);
    await runSync(manual: false, pull: pull);
  }

  /// Fired by every local write. Coalesces: a write during a running sync
  /// makes that sync go round once more instead of being dropped.
  void requestSync() => unawaited(runSync(manual: false, pull: false));

  // ------------------------------------------------------------ session

  Future<String?> login(String email, String password) async {
    try {
      final integrity = await platform.integrity();
      final response = await api.login(
        email: email,
        password: password,
        deviceUuid: deviceUuid!,
        appVersion: appVersion,
        deviceIntegrity: integrity,
      );

      // A 200 without a token used to throw a TypeError that left the button
      // spinning forever.
      final session = LoginSession.parse(response);
      if (session == null) {
        debugPrint('Login response without token/user: ${response.keys}');
        return 'استجابة غير متوقعة من الخادم. أعد المحاولة.';
      }

      // Another technician's unsynced work must never go out under this
      // account's name (C6). It stays until its owner syncs it, or a
      // supervisor revokes the device (which wipes it).
      final foreign = await db.unsyncedCounts(foreignTo: session.userId);
      if (!foreign.isEmpty) {
        unawaited(
          api.logout(bearer: session.token).catchError((Object error) {
            debugPrint('Revoking the refused login token failed: $error');
          }),
        );
        return 'على هذا الجهاز ${foreign.total} عنصر غير مُزامَن يخص فنياً آخر. '
            'يجب أن يسجّل ذلك الفني دخوله ويزامن عمله أولاً، أو يُلغي المشرف اعتماد الجهاز لمسحه.';
      }

      await tokens.writeToken(session.token);
      await tokens.writeUserId(session.userId);
      userId = session.userId;
      technicianName = session.name;
      await tokens.writeTechnicianName(technicianName ?? '');
      await db.adoptOrphans(session.userId);
      // Adopted only now: until here the app must not look signed in.
      api.token = session.token;

      final serverTime = DateTime.tryParse(
        response['server_time'] as String? ?? '',
      );
      if (serverTime != null) await clock.adopt(serverTime);

      sessionExpired = false;
      loginNotice = null;
      notifyListeners();

      // Push whatever survived the expired session first, then pull.
      await runSync(manual: true, pull: true);
      return null;
    } on ApiException catch (e) {
      api.token = null;
      if (e.isRemoteWipe) {
        await _remoteWipe(e.code);
        return loginNotice;
      }
      if (e.code == 'DEVICE_PENDING_APPROVAL') {
        return devicePendingApprovalMessage;
      }
      return friendlyError(e, context: 'login');
    } catch (error, stackTrace) {
      api.token = null;
      debugPrint('Login failed unexpectedly: $error');
      debugPrintStack(stackTrace: stackTrace);
      return genericErrorMessage;
    }
  }

  void _onAuthFailure(ApiException error) {
    if (error.isRemoteWipe) {
      unawaited(_remoteWipe(error.code));
    } else if (error.isPendingApproval) {
      unawaited(_expireSession(notice: devicePendingApprovalMessage));
    } else if (error.isSessionExpired) {
      unawaited(_expireSession());
    }
  }

  /// 401 (expired, or idle for 14 days) and 403 DEVICE_PENDING_APPROVAL: back
  /// to the login screen with the queue and every local byte untouched (C1).
  /// It all syncs after signing in again.
  Future<void> _expireSession({String notice = sessionExpiredMessage}) async {
    if (api.token == null || _wiping) return;
    api.token = null;
    await tokens.clear();
    sessionExpired = true;
    loginNotice = notice;
    lastSyncMessage = notice;
    notifyListeners();
  }

  /// 403 DEVICE_REVOKED / USER_INACTIVE: tell the technician, forget
  /// everything, return to login (C1).
  Future<void> _remoteWipe(String code) async {
    final notice = code == 'USER_INACTIVE'
        ? 'هذا الحساب معطّل، فمُسحت بيانات العمل من هذا الجهاز. تواصل مع المشرف.'
        : 'أُلغي اعتماد هذا الجهاز من المشرف، فمُسحت بيانات العمل منه. تواصل مع المشرف.';
    await _wipeAndRestart(notice: notice);
  }

  /// Unsynced items that would be lost by signing out.
  Future<UnsyncedCounts> unsyncedWork() => db.unsyncedCounts();

  /// Sign-out (item 22). Refused while anything is unsynced — the caller shows
  /// the count and why. Nothing of this user is kept but the device UUID.
  Future<bool> signOut() async {
    if (!(await unsyncedWork()).isEmpty) return false;

    try {
      await api.logout().timeout(const Duration(seconds: 8));
    } catch (error) {
      // Best effort: the token dies with the wipe either way.
      debugPrint('Logout request failed: $error');
    }

    await _wipeAndRestart(notice: null);
    return true;
  }

  Future<void> _wipeAndRestart({required String? notice}) async {
    if (_wiping) return;
    _wiping = true;
    api.token = null;
    notifyListeners();

    try {
      _periodic?.cancel();
      await _connectivity?.cancel();
      _connectivity = null;

      // Under the engine's lock: a sync in flight finishes (it stops at the
      // same refusal) before the database it writes to is deleted.
      await sync.exclusive(
        () => DeviceWipe.run(
          db: db,
          documentsDirectory: _documentsPath,
          cacheDirectory: _cachePath,
          tokens: tokens,
          prefs: _prefs!,
        ),
      );

      technicianName = null;
      userId = null;
      visits = const [];
      queueCounts = const {};
      outstandingActions = const [];
      pendingUploads = 0;
      lastSyncMessage = null;
      lastSyncedAt = null;
      sessionExpired = false;
      loginNotice = notice;
    } finally {
      _wiping = false;
    }

    // A fresh, empty, newly keyed database for whoever signs in next.
    await init();
  }

  /// Older installs stored no user id. Learn it once, and stamp the queued
  /// rows that predate authorship (C6).
  Future<void> _ensureUserId() async {
    if (userId != null || api.token == null) return;
    try {
      final me = await api.me();
      final id = (me['id'] as num?)?.toInt();
      if (id == null) return;
      userId = id;
      await tokens.writeUserId(id);
      await db.adoptOrphans(id);
    } catch (error) {
      debugPrint('Could not resolve the signed-in user: $error');
    }
  }

  // ------------------------------------------------------------ sync

  Future<void> refreshLocal() async {
    if (_wiping) return;
    try {
      visits = await db.todayVisits();
      queueCounts = await queue.counts();
      outstandingActions = await outbox.outstanding();
      final uploads = await db.raw.rawQuery(
        "SELECT COUNT(*) AS c FROM pending_media WHERE state IN ('pending', 'uploading', 'discard_pending')",
      );
      pendingUploads = (uploads.first['c'] as int?) ?? 0;
    } catch (error) {
      debugPrint('Local refresh failed: $error');
    }
    notifyListeners();
  }

  /// Push, then pull. The refresh used to run first, on every screen open, and
  /// rolled offline progress back before the queue had a chance to send it.
  Future<void> pullWork() => runSync(manual: true, pull: true);

  /// [manual]: the technician asked — retry backoffs are ignored.
  Future<void> runSync({bool manual = true, bool pull = true}) async {
    if (!ready || _wiping || !isAuthenticated) return;

    // Coalesce concurrent calls. Every checklist tap fires a background sync;
    // one recorded while a sync is running makes it go round once more
    // instead of being silently dropped until the next trigger.
    if (syncing) {
      _syncAgain = true;
      return;
    }

    syncing = true;
    notifyListeners();

    try {
      var rounds = 0;
      do {
        _syncAgain = false;
        await _ensureUserId();
        if (!isAuthenticated) break;

        final outcome = await sync.sync(force: manual, pull: pull);
        if (outcome == null) break; // a retry/discard holds the engine
        await _applyOutcome(outcome);
        if (outcome.mustStop || outcome.connectivityLost) break;

        manual = false;
        pull = false;
      } while (_syncAgain && ++rounds < 3);
    } catch (error, stackTrace) {
      online = false;
      lastSyncMessage =
          'تعذّرت المزامنة بشكل غير متوقع. عملك محفوظ للمحاولة التالية.';
      debugPrint('Unexpected sync failure: $error');
      debugPrintStack(stackTrace: stackTrace);
    } finally {
      syncing = false;
      await refreshLocal();
    }
  }

  Future<void> _applyOutcome(SyncOutcome outcome) async {
    if (outcome.remoteWipe) {
      unawaited(_remoteWipe(outcome.remoteWipeCode!));
      return;
    }
    if (outcome.pendingApproval) {
      await _expireSession(notice: devicePendingApprovalMessage);
      return;
    }
    if (outcome.sessionExpired) {
      await _expireSession();
      return;
    }

    online = !outcome.connectivityLost;
    if (online && outcome.error == null) lastSyncedAt = DateTime.now();
    if (outcome.pulled) _lastPulledAt = DateTime.now();

    await refreshLocal();
    final stillPending =
        (queueCounts[QueuedStatus.pending] ?? 0) +
        outstandingActions
            .where((a) => a.status == ActionStatus.pending)
            .length +
        pendingUploads;
    lastSyncMessage = describeSync(outcome, stillPending: stillPending);
  }

  // ------------------------------------------------------------ field work

  /// Every field action goes through here: written locally first, sent later.
  Future<void> record(
    int visitId,
    String eventType, {
    Map<String, dynamic> payload = const {},
  }) async {
    await recorder.record(
      visitId,
      eventType,
      payload: payload,
      location: await location.quick(),
      actorUserId: userId,
    );
    await refreshLocal();
    requestSync();
  }

  /// Where the technician is relative to the site, for "start" and close.
  /// Waits at most ~5 s for a fix; no fix never blocks the work.
  Future<GeofenceCheck> checkGeofence(int visitId) async {
    final visit = await db.visit(visitId);
    final fix = await location.fresh(timeout: const Duration(seconds: 5));
    return GeofenceCheck.evaluate(
      siteLat: (visit?['site_lat'] as num?)?.toDouble(),
      siteLng: (visit?['site_lng'] as num?)?.toDouble(),
      radiusM: visit?['geofence_radius_m'] as num?,
      fix: fix,
    );
  }

  /// Moves the visit forward. The local state advances immediately so the next
  /// button appears with no network; the server's canonical state overrides it
  /// once it has seen the transition. Returns false when the transition is not
  /// legal from here.
  Future<bool> transition(
    int visitId,
    String target, {
    GeofenceCheck? geofence,
    String? overrideReason,
  }) async {
    final fix = geofence?.fix ?? await location.quick();

    final applied = await recorder.transition(
      visitId,
      target,
      location: fix,
      geofenceOverrideReason: overrideReason,
      actorUserId: userId,
    );
    if (!applied) return false;

    await refreshLocal();
    requestSync();

    // Live ETA while driving: advisory, online-only by nature. The position
    // that counts already travels on the queued event (C3).
    if (target == 'en_route' && fix != null) {
      unawaited(
        api.updateVisitLocation(visitId, fix.lat, fix.lng).catchError((
          Object error,
        ) {
          debugPrint('ETA update skipped: $error');
        }),
      );
    }

    return true;
  }

  Future<List<String>> nextStates(int visitId) => progress.nextStates(visitId);

  /// Explicit per-visit declaration, decoupled from asset status.
  Future<void> declareNoParts(int visitId, bool value) async {
    await recorder.declareNoParts(
      visitId,
      value,
      location: await location.quick(),
      actorUserId: userId,
    );
    await refreshLocal();
    requestSync();
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
    await recorder.setChecklist(
      visitId,
      assetId,
      status: status,
      note: note,
      noPartsUsed: noPartsUsed,
      location: await location.quick(),
      actorUserId: userId,
    );
    await refreshLocal();
    requestSync();
  }

  /// Camera capture for one asset. Returns null when the technician cancels.
  Future<String?> capturePhoto({
    required int visitId,
    required int assetId,
    String kind = EvidenceStore.kindPhotoAfter,
  }) async {
    final capture = await camera.takePhoto();
    if (_cachePath != null) {
      unawaited(CameraCapture.purgeLeftovers(_cachePath!));
    }
    if (capture == null) return null;

    final id = await evidence.store(
      visitId: visitId,
      kind: kind,
      bytes: capture.bytes,
      mime: capture.mime,
      assetId: assetId,
      lat: capture.lat,
      lng: capture.lng,
      actorUserId: userId,
    );

    await refreshLocal();
    requestSync();
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
      actorUserId: userId,
    );

    // The name and role travel as their own event so the signature image is never
    // the only record of who approved the work.
    await record(
      visitId,
      'signature.captured',
      payload: {
        'client_media_id': id,
        'signer_name': signerName,
        'signer_role': signerRole,
      },
    );

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

  // ------------------------------------------------------------ actions

  /// An online-only action (custody, stocktake, transfer, diagnosis,
  /// additional work, vehicle inspection), queued with its idempotency key and
  /// tried at once. Offline it simply waits in the outbox (C2).
  Future<ActionAttempt> performAction(ActionRequest request) async {
    final action = await outbox.add(request, actorUserId: userId);
    final attempt = await sync.trySendAction(action.idempotencyKey);
    await refreshLocal();
    if (attempt.kind == ActionAttemptKind.queued && !attempt.connectivityLost) {
      requestSync();
    }
    return attempt;
  }

  /// Read-through cache for the field tools data (lists, the assigned
  /// vehicle), kept in the encrypted database so the screens still show — and
  /// can still be acted on, through the outbox — with no signal.
  Future<({T value, bool stale})> cached<T>(
    String key,
    Future<T> Function() fetch,
    T Function(Object? json) decode,
  ) async {
    try {
      final value = await fetch();
      await db.setValue('cache:$key', jsonEncode(value));
      return (value: value, stale: false);
    } on ApiException catch (e) {
      if (!e.isConnectivity && !e.isServerTransient) rethrow;
      final stored = await db.getValue('cache:$key');
      if (stored == null) rethrow;
      return (value: decode(jsonDecode(stored)), stale: true);
    }
  }
}

List<Map<String, dynamic>> decodeRows(Object? json) =>
    (json as List? ?? const [])
        .whereType<Map>()
        .map((row) => Map<String, dynamic>.from(row))
        .toList();
