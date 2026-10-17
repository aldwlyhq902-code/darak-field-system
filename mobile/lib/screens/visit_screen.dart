import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/business_time.dart';
import '../core/error_messages.dart';
import '../core/evidence_store.dart';
import '../core/geofence.dart';
import '../core/visit_progress.dart';
import 'scanner_screen.dart';
import 'signature_screen.dart';
import 'diagnosis_screen.dart';
import 'additional_work_screen.dart';
import 'parts_screen.dart';
import 'tile_button.dart';

/// The button for one edge of the visit state machine.
class VisitTransitionAction {
  const VisitTransitionAction({
    required this.from,
    required this.to,
    required this.label,
    required this.icon,
    this.backwards = false,
    this.confirmation,
  });

  final String from;
  final String to;

  /// Arabic; English comes from [AppLocalizations].
  final String label;
  final IconData icon;
  final bool backwards;

  /// When set, the technician confirms first.
  final String? confirmation;

  bool get needsConfirmation => confirmation != null;

  /// One entry per edge of `contracts/visit_transitions.json`; a test fails
  /// if an edge is ever added without a label here.
  static const all = <String, VisitTransitionAction>{
    'scheduled>en_route': VisitTransitionAction(
      from: 'scheduled',
      to: 'en_route',
      label: 'انطلاق',
      icon: Icons.directions_car,
    ),
    'en_route>started': VisitTransitionAction(
      from: 'en_route',
      to: 'started',
      label: 'بدء العمل في الموقع',
      icon: Icons.play_arrow,
    ),
    'en_route>scheduled': VisitTransitionAction(
      from: 'en_route',
      to: 'scheduled',
      label: 'إلغاء الانطلاق',
      icon: Icons.undo,
      backwards: true,
      confirmation: 'تعود الزيارة إلى «مجدولة» ويتوقف احتساب وقت الطريق.',
    ),
    'started>paused': VisitTransitionAction(
      from: 'started',
      to: 'paused',
      label: 'إيقاف مؤقت',
      icon: Icons.pause,
    ),
    'started>awaiting_close': VisitTransitionAction(
      from: 'started',
      to: 'awaiting_close',
      label: 'إنهاء العمل',
      icon: Icons.done,
    ),
    'paused>started': VisitTransitionAction(
      from: 'paused',
      to: 'started',
      label: 'استئناف',
      icon: Icons.play_arrow,
    ),
    'paused>awaiting_close': VisitTransitionAction(
      from: 'paused',
      to: 'awaiting_close',
      label: 'إنهاء العمل',
      icon: Icons.done,
    ),
    'awaiting_close>completed': VisitTransitionAction(
      from: 'awaiting_close',
      to: 'completed',
      label: 'إقفال الزيارة',
      icon: Icons.lock,
    ),
    'awaiting_close>started': VisitTransitionAction(
      from: 'awaiting_close',
      to: 'started',
      label: 'العودة للعمل',
      icon: Icons.replay,
      backwards: true,
    ),
    'completed>reopened': VisitTransitionAction(
      from: 'completed',
      to: 'reopened',
      label: 'إعادة فتح الزيارة',
      icon: Icons.lock_open,
      backwards: true,
      confirmation:
          'الزيارة مقفلة وقد أُرسل تقريرها. أعد فتحها فقط إن كان هناك عمل لم يكتمل فعلاً.',
    ),
    'reopened>started': VisitTransitionAction(
      from: 'reopened',
      to: 'started',
      label: 'استئناف العمل',
      icon: Icons.play_arrow,
    ),
  };

  static VisitTransitionAction of(String from, String to) =>
      all['$from>$to'] ??
      VisitTransitionAction(
        from: from,
        to: to,
        label: to,
        icon: Icons.arrow_forward,
      );
}

/// One visit, from "انطلاق" to "إقفال".
///
/// Everything writes locally first. The close button is gated on the same
/// conditions the server enforces, evaluated here so the technician learns what
/// is missing while still in front of the equipment — not after driving away.
class VisitScreen extends StatefulWidget {
  const VisitScreen({super.key, required this.state, required this.visitId});

  final AppState state;
  final int visitId;

  @override
  State<VisitScreen> createState() => _VisitScreenState();
}

class _VisitScreenState extends State<VisitScreen> {
  Map<String, dynamic>? _visit;
  List<Map<String, dynamic>> _assets = const [];
  Map<int, Map<String, dynamic>> _checklist = const {};
  List<Map<String, dynamic>> _media = const [];
  bool _noPartsDeclared = false;
  bool _loading = true;
  bool _busy = false;

  /// The local state is ahead of the server (a transition not yet confirmed).
  bool _unconfirmed = false;

  /// What the server said was missing when it last refused this visit's close.
  List<String> _refusedClose = const [];

  /// Parts this phone issued to the visit that the server has not refused.
  int _issuedParts = 0;

  @override
  void initState() {
    super.initState();
    // A sync can change this visit underneath the screen — most of all, the
    // server refusing a close the phone had already shown as done. Without
    // listening, the screen kept saying "مقفلة" until it was reopened.
    widget.state.addListener(_onAppStateChanged);
    _load();
  }

  @override
  void dispose() {
    widget.state.removeListener(_onAppStateChanged);
    super.dispose();
  }

  void _onAppStateChanged() {
    if (mounted && !_busy) _load();
  }

  Future<void> _load() async {
    final visit = await widget.state.db.visit(widget.visitId);
    final assets = await widget.state.db.assetsFor(widget.visitId);
    final checklist = await widget.state.checklistFor(widget.visitId);
    // Live evidence only — a discarded file must not tell the technician the
    // visit is ready while the server is still asking for a replacement.
    final media = await widget.state.evidence.liveForVisit(widget.visitId);
    final noParts = await widget.state.noPartsDeclared(widget.visitId);
    final unconfirmed = await widget.state.hasUnconfirmedTransition(
      widget.visitId,
    );
    final refusedClose = await widget.state.refusedCloseBlockers(
      widget.visitId,
    );
    final issued = await widget.state.issuedParts(widget.visitId);

    if (mounted) {
      setState(() {
        _visit = visit;
        _assets = assets;
        _checklist = checklist;
        _media = media;
        _noPartsDeclared = noParts;
        _unconfirmed = unconfirmed;
        _refusedClose = refusedClose;
        _issuedParts = issued.where((issue) => !issue.refused).length;
        _loading = false;
      });
    }
  }

  String get _state => _visit?['state'] as String? ?? 'scheduled';

  /// Photos OF THIS ASSET. The earlier version counted every photo on the visit,
  /// so the local gate said "ready" while the server — which requires one per
  /// asset — refused. The two checks must ask the same question.
  int _photosFor(int assetId) => _media
      .where((m) => (m['kind'] as String).startsWith('photo'))
      .where((m) => m['asset_id'] == assetId)
      .length;

  bool get _hasSignature =>
      _media.any((m) => m['kind'] == EvidenceStore.kindSignature);

  /// Built from the shared contract (`VisitProgress.transitions`), not a
  /// hand-kept copy. The copy had drifted: a reopened visit had no button at
  /// all (reopened → started), nor did "back to work" from awaiting close or
  /// "cancel departure" — a dead end the technician could not leave.
  List<VisitTransitionAction> get _actions =>
      (VisitProgress.transitions[_state] ?? const <String>[])
          .map((to) => VisitTransitionAction.of(_state, to))
          .toList();

  List<String> get _missing {
    final missing = <String>[];

    // The server needs at least one inspected asset (NO_CHECKLIST). With none
    // linked, the phone used to call the visit ready and the server refused
    // every close, for good.
    if (_assets.isEmpty) {
      missing.add(
        'لا توجد أصول مرتبطة بهذه الزيارة — اطلب من المشرف ربط أصل بها قبل الإقفال.',
      );
    }

    for (final asset in _assets) {
      final id = asset['id'] as int;
      final entry = _checklist[id];

      if (entry == null || entry['status'] == null) {
        missing.add('حالة الأصل: ${asset['name']}');
      }

      // Per asset, exactly as the server requires.
      if (_photosFor(id) == 0) {
        missing.add('صورة للأصل: ${asset['name']}');
      }
    }

    // As the server asks it: a part issued to the visit, or an explicit "none".
    if (_issuedParts == 0 && !_noPartsDeclared) {
      missing.add('سجّل القطع المستخدمة أو صرّح بعدم استخدام قطع');
    }

    if (!_hasSignature) {
      missing.add('توقيع مسؤول الموقع');
    }

    return missing;
  }

  Future<void> _transition(VisitTransitionAction action) async {
    final to = action.to;
    if (to == 'completed' && _missing.isNotEmpty) {
      _showMissing(_missing);
      return;
    }

    if (action.needsConfirmation && !await _confirm(action)) return;
    if (!mounted) return;

    setState(() => _busy = true);

    try {
      // What only the server knows (the client's answer on additional work),
      // asked while still on site. Offline this returns nothing and the close
      // goes ahead; a refusal then comes back through sync with its list.
      if (to == 'completed') {
        final serverOnly = await widget.state.serverCloseBlockers(
          widget.visitId,
        );
        if (!mounted) return;
        if (serverOnly.isNotEmpty) {
          _showMissing(serverOnly);
          return;
        }
      }

      // At "start" and at close, where the technician is matters. One short
      // wait for a fix (never more than ~5 s, never a refusal for lack of
      // one); far from the site, a reason is required to continue (C3).
      GeofenceCheck? geofence;
      String? reason;
      if (GeofenceCheck.appliesTo(to)) {
        geofence = await widget.state.checkGeofence(widget.visitId);
        if (!mounted) return;
        if (geofence.needsReason) {
          reason = await _askGeofenceReason(geofence);
          if (reason == null) return;
        }
      }

      // The local state advances immediately, so the next button appears with
      // no network. The server's canonical state overrides it once it has seen
      // the transition.
      final applied = await widget.state.transition(
        widget.visitId,
        to,
        geofence: geofence,
        overrideReason: reason,
      );
      await _load();

      if (!mounted) return;
      final messenger = ScaffoldMessenger.of(context);
      messenger.showSnackBar(
        SnackBar(
          content: LText(
            applied
                ? 'سُجّلت العملية محلياً وستُزامن تلقائياً.'
                : 'هذه الخطوة غير متاحة من الحالة الحالية.',
          ),
        ),
      );
      if (applied && (geofence?.isMocked ?? false)) {
        messenger.showSnackBar(
          SnackBar(
            backgroundColor: Colors.orange.shade800,
            duration: const Duration(seconds: 6),
            content: const LText(
              'تنبيه: يبدو أن الموقع صادر من تطبيق مواقع وهمية. سُجّلت العملية، وسيظهر ذلك للمشرف.',
            ),
          ),
        );
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: LText(friendlyError(error, context: 'transition'))),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _confirm(VisitTransitionAction action) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: LText(action.label),
        content: LText(action.confirmation ?? ''),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const LText('تراجع'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: LText(action.label),
          ),
        ],
      ),
    );
    return confirmed == true;
  }

  /// The technician is clearly away from the site. Allowed — buildings with
  /// several entrances, wrong pins on the map — but never silently.
  Future<String?> _askGeofenceReason(GeofenceCheck check) async {
    final controller = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const LText('أنت خارج نطاق موقع العميل'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              LText(
                'المسافة عن الموقع نحو ${check.distanceM?.round() ?? '—'} م '
                '(المسموح ${check.allowedM?.round() ?? '—'} م). اكتب سبباً مختصراً للمتابعة.',
              ),
              const SizedBox(height: 12),
              TextField(
                controller: controller,
                autofocus: true,
                maxLength: 200,
                decoration: InputDecoration(
                  labelText: context.tr('سبب العمل خارج النطاق'),
                  border: const OutlineInputBorder(),
                ),
                onChanged: (_) => setDialogState(() {}),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const LText('إلغاء'),
            ),
            FilledButton(
              onPressed: controller.text.trim().length < 3
                  ? null
                  : () => Navigator.pop(context, controller.text.trim()),
              child: const LText('تأكيد ومتابعة'),
            ),
          ],
        ),
      ),
    );
    controller.dispose();
    return reason;
  }

  void _showMissing(List<String> missing) {
    showModalBottomSheet<void>(
      context: context,
      builder: (context) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.lock_outline, color: Colors.orange),
                const SizedBox(width: 8),
                LText(
                  'لا يمكن الإقفال بعد',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ],
            ),
            const SizedBox(height: 12),
            ...missing.map(
              (m) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: [
                    const Icon(Icons.circle, size: 7, color: Colors.orange),
                    const SizedBox(width: 8),
                    Expanded(child: LText(m)),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const LText('حسناً'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _capture(Map<String, dynamic> asset) async {
    setState(() => _busy = true);

    try {
      await widget.state.capturePhoto(
        visitId: widget.visitId,
        assetId: asset['id'] as int,
      );
      await _load();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: LText(friendlyError(error, context: 'capture'))),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sign() async {
    final result = await Navigator.of(context).push<SignatureResult>(
      MaterialPageRoute(builder: (_) => const SignatureScreen()),
    );

    if (result == null) return;

    await widget.state.captureSignature(
      visitId: widget.visitId,
      png: result.png,
      signerName: result.name,
      signerRole: result.role,
    );

    await _load();
  }

  Future<void> _scanSite() async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => const ScannerScreen(
          title: 'مسح ملصق الموقع',
          expectedPrefix: 'SITE-',
        ),
      ),
    );

    if (code == null) return;

    await widget.state.record(
      widget.visitId,
      'site.scanned',
      payload: {'qr_code': code},
    );

    if (mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: LText('سُجّل الحضور بالملصق: $code')));
    }
  }

  Future<void> _scanAsset(Map<String, dynamic> asset) async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => const ScannerScreen(
          title: 'مسح ملصق الأصل',
          expectedPrefix: 'ASSET-',
        ),
      ),
    );

    if (code == null) return;

    final expected = asset['qr_code'] as String?;

    if (expected != null && expected != code) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.orange.shade800,
            content: LText(
              'الملصق الممسوح لا يطابق «${asset['name']}». تحقق من الجهاز.',
            ),
          ),
        );
      }
      return;
    }

    await widget.state.record(
      widget.visitId,
      'asset.scanned',
      payload: {'asset_id': asset['id'], 'qr_code': code},
    );

    if (mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: LText('طوبق الأصل بالملصق.')));
    }
  }

  /// Asset status only. Whether parts were used is a separate, deliberate
  /// statement — inferring it from "سليم" both blocked faulty assets from ever
  /// closing and silently asserted "no parts" after a real replacement.
  Future<void> _setStatus(Map<String, dynamic> asset, String status) async {
    await widget.state.setChecklist(
      widget.visitId,
      asset['id'] as int,
      status: status,
    );
    await _load();
  }

  Future<void> _toggleNoParts(bool value) async {
    await widget.state.declareNoParts(widget.visitId, value);
    await _load();
  }

  /// Parts are fitted on site: from arrival until the visit is closed.
  bool get _canWorkOnSite =>
      _state == 'started' || _state == 'paused' || _state == 'awaiting_close';

  Future<void> _openParts() async {
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) =>
            PartsScreen(state: widget.state, visitId: widget.visitId),
      ),
    );
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final visit = _visit;
    if (visit == null) {
      return const Scaffold(
        body: Center(child: LText('الزيارة غير موجودة على الجهاز.')),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: LText('${visit['client_name'] ?? ''}'),
        actions: [
          IconButton(
            tooltip: context.tr('مسح ملصق الموقع'),
            onPressed: _scanSite,
            icon: const Icon(Icons.qr_code_scanner),
          ),
        ],
      ),
      body: Stack(
        children: [
          ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _InfoCard(visit: visit),
              const SizedBox(height: 16),
              Row(
                children: [
                  LText(
                    'الأصول',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const Spacer(),
                  LText(
                    '${_media.where((m) => (m['kind'] as String).startsWith('photo')).length} صورة',
                    style: const TextStyle(fontSize: 12, color: Colors.black54),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              if (_assets.isEmpty)
                const Card(
                  child: Padding(
                    padding: EdgeInsets.all(16),
                    child: LText('لا توجد أصول مسجلة لهذا الموقع بعد.'),
                  ),
                ),
              ..._assets.map(
                (asset) => _AssetTile(
                  asset: asset,
                  status: _checklist[asset['id'] as int]?['status'] as String?,
                  photos: _photosFor(asset['id'] as int),
                  onStatus: (status) => _setStatus(asset, status),
                  onCapture: () => _capture(asset),
                  onScan: () => _scanAsset(asset),
                ),
              ),
              const SizedBox(height: 16),
              if (_canWorkOnSite)
                Card(
                  child: ListTile(
                    leading: Icon(
                      Icons.build_circle_outlined,
                      color: _issuedParts > 0 ? Colors.teal : Colors.black45,
                    ),
                    title: const LText('قطع الغيار المصروفة'),
                    subtitle: LText(
                      _issuedParts == 0
                          ? 'لم تُصرف قطع لهذه الزيارة بعد'
                          : 'صُرفت $_issuedParts قطعة',
                    ),
                    trailing: TileButton(
                      onPressed: _busy ? null : _openParts,
                      label: 'صرف قطعة',
                    ),
                  ),
                ),
              Card(
                child: SwitchListTile(
                  value: _noPartsDeclared,
                  // Once a part is issued, "none used" cannot be true.
                  onChanged: _busy || _issuedParts > 0
                      ? null
                      : (v) => _toggleNoParts(v),
                  secondary: Icon(
                    _noPartsDeclared
                        ? Icons.check_circle
                        : Icons.build_outlined,
                    color: _noPartsDeclared ? Colors.teal : Colors.black45,
                  ),
                  title: const LText('لم تُستخدم قطع غيار في هذه الزيارة'),
                  subtitle: const LText(
                    'إن استُخدمت قطع فاصرفها من مخزون سيارتك بدل تفعيل هذا الخيار.',
                    style: TextStyle(fontSize: 12),
                  ),
                ),
              ),
              if (_state == 'started' || _state == 'paused') ...[
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.auto_awesome),
                    title: const LText('اقتراح وتسجيل التشخيص'),
                    trailing: const Icon(Icons.chevron_left),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => DiagnosisScreen(
                          state: widget.state,
                          visitId: widget.visitId,
                        ),
                      ),
                    ),
                  ),
                ),
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.request_quote_outlined),
                    title: const LText('طلب اعتماد عمل أو قطع إضافية'),
                    subtitle: const LText(
                      'يرسل البنود والأسعار للعميل قبل التركيب',
                    ),
                    trailing: const Icon(Icons.chevron_left),
                    onTap: () async {
                      final sent = await Navigator.push<bool>(
                        context,
                        MaterialPageRoute(
                          builder: (_) => AdditionalWorkScreen(
                            state: widget.state,
                            visitId: widget.visitId,
                          ),
                        ),
                      );
                      if (!context.mounted) return;
                      if (sent == true) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                            content: LText(
                              'أُرسل الطلب للعميل، وانتظر موافقته قبل التنفيذ.',
                            ),
                          ),
                        );
                      }
                    },
                  ),
                ),
              ],
              Card(
                child: ListTile(
                  leading: Icon(
                    _hasSignature ? Icons.check_circle : Icons.draw_outlined,
                    color: _hasSignature ? Colors.teal : Colors.black45,
                  ),
                  title: const LText('توقيع مسؤول الموقع'),
                  subtitle: LText(
                    _hasSignature ? 'تم التوقيع' : 'مطلوب قبل الإقفال',
                  ),
                  // Not a bare FilledButton: the theme's full-width minimum
                  // made it swallow the tile and the whole page failed layout.
                  trailing: TileButton(
                    onPressed: _sign,
                    label: _hasSignature ? 'إعادة' : 'توقيع',
                  ),
                ),
              ),
              const SizedBox(height: 20),
              ..._actions.map(
                (action) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  // Steps backwards are outlined so a gloved thumb does not
                  // undo a departure or reopen a closed visit by accident.
                  child: action.backwards
                      ? OutlinedButton.icon(
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size.fromHeight(52),
                          ),
                          onPressed: _busy ? null : () => _transition(action),
                          icon: Icon(action.icon),
                          label: LText(action.label),
                        )
                      : FilledButton.icon(
                          onPressed: _busy ? null : () => _transition(action),
                          icon: Icon(action.icon),
                          label: LText(action.label),
                        ),
                ),
              ),
              if (_state == 'awaiting_close' && _missing.isNotEmpty)
                Card(
                  color: Colors.orange.shade50,
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const LText(
                          'نواقص تمنع الإقفال:',
                          style: TextStyle(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 6),
                        ..._missing.map(
                          (m) => LText(
                            '• $m',
                            style: const TextStyle(fontSize: 13),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              if (_state == 'awaiting_close' && _refusedClose.isNotEmpty)
                Card(
                  color: Colors.red.shade50,
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const LText(
                          'رفض الخادم الإقفال:',
                          style: TextStyle(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 6),
                        ..._refusedClose.map(
                          (m) => LText(
                            '• $m',
                            style: const TextStyle(fontSize: 13),
                          ),
                        ),
                        const SizedBox(height: 6),
                        const LText(
                          'أكمل هذه النواقص ثم أعد الإقفال.',
                          style: TextStyle(fontSize: 13),
                        ),
                      ],
                    ),
                  ),
                ),
              // "Closed" only once the server has it. Until then it is closed on
              // this phone, which the server may still refuse.
              if (_state == 'completed' && _unconfirmed)
                const Card(
                  color: Color(0xFFFFFBEB),
                  child: ListTile(
                    leading: Icon(Icons.cloud_upload_outlined),
                    title: LText('الزيارة مقفلة على الجهاز'),
                    subtitle: LText('بانتظار تأكيد الخادم بعد المزامنة.'),
                  ),
                )
              else if (_state == 'completed')
                const Card(
                  color: Color(0xFFECFDF5),
                  child: ListTile(
                    leading: Icon(Icons.verified, color: Colors.teal),
                    title: LText('الزيارة مقفلة'),
                    subtitle: LText(
                      'التقرير يُرسل للعميل بعد اكتمال المزامنة.',
                    ),
                  ),
                ),
              const SizedBox(height: 40),
            ],
          ),
          if (_busy)
            const Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: LinearProgressIndicator(minHeight: 3),
            ),
        ],
      ),
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.visit});

  final Map<String, dynamic> visit;

  @override
  Widget build(BuildContext context) {
    final lastSynced = BusinessTime.parse(visit['last_synced_at'] as String?);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            LText(
              visit['wo_title'] as String? ?? '',
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 6),
            LText(
              '${visit['site_name'] ?? ''} — ${visit['site_address'] ?? ''}',
            ),
            if ((visit['access_notes'] as String?)?.isNotEmpty ?? false) ...[
              const SizedBox(height: 8),
              Row(
                children: [
                  const Icon(
                    Icons.key_outlined,
                    size: 16,
                    color: Colors.black54,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: LText(
                      visit['access_notes'] as String,
                      style: const TextStyle(
                        fontSize: 13,
                        color: Colors.black54,
                      ),
                    ),
                  ),
                ],
              ),
            ],
            const Divider(height: 24),
            // Stale data must announce itself. Working offline is fine; pretending
            // the numbers are live is not.
            Row(
              children: [
                const Icon(Icons.history, size: 15, color: Colors.black45),
                const SizedBox(width: 6),
                // Expanded: a long line (English, large system font) must
                // wrap, not overflow the card.
                Expanded(
                  child: LText(
                    lastSynced == null
                        ? 'بيانات غير مزامنة'
                        : 'حُدّثت من الخادم: ${BusinessTime.dateTime(lastSynced)}',
                    style: const TextStyle(fontSize: 12, color: Colors.black45),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _AssetTile extends StatelessWidget {
  const _AssetTile({
    required this.asset,
    required this.status,
    required this.photos,
    required this.onStatus,
    required this.onCapture,
    required this.onScan,
  });

  final Map<String, dynamic> asset;
  final String? status;
  final int photos;
  final ValueChanged<String> onStatus;
  final VoidCallback onCapture;
  final VoidCallback onScan;

  @override
  Widget build(BuildContext context) {
    final underWarranty = (asset['under_warranty'] as int? ?? 0) == 1;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: LText(
                    asset['name'] as String? ?? '',
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                ),
                if (underWarranty)
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 3,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.amber.shade100,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    // Billing a part on an in-warranty asset is a real and
                    // expensive mistake, so the warning sits on the tile itself.
                    child: const LText(
                      'تحت الضمان',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
              ],
            ),
            if ((asset['location'] as String?)?.isNotEmpty ?? false)
              LText(
                asset['location'] as String,
                style: const TextStyle(fontSize: 12, color: Colors.black54),
              ),
            const SizedBox(height: 10),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'ok', label: LText('سليم')),
                ButtonSegment(value: 'needs_followup', label: LText('متابعة')),
                ButtonSegment(value: 'fault', label: LText('عطل')),
              ],
              selected: status == null ? <String>{} : {status!},
              emptySelectionAllowed: true,
              showSelectedIcon: false,
              onSelectionChanged: (selection) {
                if (selection.isNotEmpty) onStatus(selection.first);
              },
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: onCapture,
                    icon: const Icon(Icons.photo_camera_outlined, size: 18),
                    label: const LText('تصوير'),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton(
                  onPressed: onScan,
                  child: const Icon(Icons.qr_code_scanner, size: 18),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
