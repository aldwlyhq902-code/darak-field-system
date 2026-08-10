import 'package:flutter/material.dart';

import '../app_state.dart';
import '../core/evidence_store.dart';
import 'scanner_screen.dart';
import 'signature_screen.dart';

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

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final visit = await widget.state.db.visit(widget.visitId);
    final assets = await widget.state.db.assetsFor(widget.visitId);
    final checklist = await widget.state.checklistFor(widget.visitId);
    final media = await widget.state.evidence.forVisit(widget.visitId);
    final noParts = await widget.state.noPartsDeclared(widget.visitId);

    if (mounted) {
      setState(() {
        _visit = visit;
        _assets = assets;
        _checklist = checklist;
        _media = media;
        _noPartsDeclared = noParts;
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

  bool get _hasSignature => _media.any((m) => m['kind'] == EvidenceStore.kindSignature);

  /// Local mirror of the server's transition map. An unreachable button beats a
  /// 409 the technician cannot interpret.
  List<({String to, String label, IconData icon})> get _actions => switch (_state) {
        'scheduled' => [(to: 'en_route', label: 'انطلاق', icon: Icons.directions_car)],
        'en_route' => [(to: 'started', label: 'بدء العمل في الموقع', icon: Icons.play_arrow)],
        'started' => [
            (to: 'paused', label: 'إيقاف مؤقت', icon: Icons.pause),
            (to: 'awaiting_close', label: 'إنهاء العمل', icon: Icons.done),
          ],
        'paused' => [
            (to: 'started', label: 'استئناف', icon: Icons.play_arrow),
            (to: 'awaiting_close', label: 'إنهاء العمل', icon: Icons.done),
          ],
        'awaiting_close' => [(to: 'completed', label: 'إقفال الزيارة', icon: Icons.lock)],
        _ => const [],
      };

  List<String> get _missing {
    final missing = <String>[];

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

    if (_checklist.isNotEmpty && !_noPartsDeclared) {
      missing.add('سجّل القطع المستخدمة أو صرّح بعدم استخدام قطع');
    }

    if (!_hasSignature) {
      missing.add('توقيع مسؤول الموقع');
    }

    return missing;
  }

  Future<void> _transition(String to) async {
    if (to == 'completed' && _missing.isNotEmpty) {
      _showMissing();
      return;
    }

    setState(() => _busy = true);

    // The local state advances immediately, so the next button appears with no
    // network. The server's canonical state overrides it on the next sync.
    final applied = await widget.state.transition(widget.visitId, to);
    await _load();

    if (mounted) {
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(applied
              ? 'سُجّلت العملية محلياً وستُزامن تلقائياً.'
              : 'هذه الخطوة غير متاحة من الحالة الحالية.'),
        ),
      );
    }
  }

  void _showMissing() {
    showModalBottomSheet<void>(
      context: context,
      builder: (context) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              const Icon(Icons.lock_outline, color: Colors.orange),
              const SizedBox(width: 8),
              Text('لا يمكن الإقفال بعد', style: Theme.of(context).textTheme.titleMedium),
            ]),
            const SizedBox(height: 12),
            ..._missing.map((m) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    const Icon(Icons.circle, size: 7, color: Colors.orange),
                    const SizedBox(width: 8),
                    Expanded(child: Text(m)),
                  ]),
                )),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('حسناً'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _capture(Map<String, dynamic> asset) async {
    setState(() => _busy = true);

    await widget.state.capturePhoto(
      visitId: widget.visitId,
      assetId: asset['id'] as int,
    );

    await _load();
    if (mounted) setState(() => _busy = false);
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
        builder: (_) => const ScannerScreen(title: 'مسح ملصق الموقع', expectedPrefix: 'SITE-'),
      ),
    );

    if (code == null) return;

    await widget.state.record(widget.visitId, 'site.scanned', payload: {'qr_code': code});

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('سُجّل الحضور بالملصق: $code')),
      );
    }
  }

  Future<void> _scanAsset(Map<String, dynamic> asset) async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => const ScannerScreen(title: 'مسح ملصق الأصل', expectedPrefix: 'ASSET-'),
      ),
    );

    if (code == null) return;

    final expected = asset['qr_code'] as String?;

    if (expected != null && expected != code) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.orange.shade800,
            content: Text('الملصق الممسوح لا يطابق «${asset['name']}». تحقق من الجهاز.'),
          ),
        );
      }
      return;
    }

    await widget.state.record(widget.visitId, 'asset.scanned',
        payload: {'asset_id': asset['id'], 'qr_code': code});

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('طوبق الأصل بالملصق.')),
      );
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

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final visit = _visit;
    if (visit == null) {
      return const Scaffold(body: Center(child: Text('الزيارة غير موجودة على الجهاز.')));
    }

    return Scaffold(
      appBar: AppBar(
        title: Text('${visit['client_name'] ?? ''}'),
        actions: [
          IconButton(
            tooltip: 'مسح ملصق الموقع',
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
                  Text('الأصول', style: Theme.of(context).textTheme.titleMedium),
                  const Spacer(),
                  Text('${_media.where((m) => (m['kind'] as String).startsWith('photo')).length} صورة',
                      style: const TextStyle(fontSize: 12, color: Colors.black54)),
                ],
              ),
              const SizedBox(height: 8),
              if (_assets.isEmpty)
                const Card(
                  child: Padding(
                    padding: EdgeInsets.all(16),
                    child: Text('لا توجد أصول مسجلة لهذا الموقع بعد.'),
                  ),
                ),
              ..._assets.map((asset) => _AssetTile(
                    asset: asset,
                    status: _checklist[asset['id'] as int]?['status'] as String?,
                    photos: _photosFor(asset['id'] as int),
                    onStatus: (status) => _setStatus(asset, status),
                    onCapture: () => _capture(asset),
                    onScan: () => _scanAsset(asset),
                  )),
              const SizedBox(height: 16),
              Card(
                child: SwitchListTile(
                  value: _noPartsDeclared,
                  onChanged: _busy ? null : (v) => _toggleNoParts(v),
                  secondary: Icon(
                    _noPartsDeclared ? Icons.check_circle : Icons.build_outlined,
                    color: _noPartsDeclared ? Colors.teal : Colors.black45,
                  ),
                  title: const Text('لم تُستخدم قطع غيار في هذه الزيارة'),
                  subtitle: const Text(
                    'إن استُخدمت قطع فاصرفها من مخزون سيارتك بدل تفعيل هذا الخيار.',
                    style: TextStyle(fontSize: 12),
                  ),
                ),
              ),
              Card(
                child: ListTile(
                  leading: Icon(
                    _hasSignature ? Icons.check_circle : Icons.draw_outlined,
                    color: _hasSignature ? Colors.teal : Colors.black45,
                  ),
                  title: const Text('توقيع مسؤول الموقع'),
                  subtitle: Text(_hasSignature ? 'تم التوقيع' : 'مطلوب قبل الإقفال'),
                  trailing: FilledButton.tonal(
                    onPressed: _sign,
                    child: Text(_hasSignature ? 'إعادة' : 'توقيع'),
                  ),
                ),
              ),
              const SizedBox(height: 20),
              ..._actions.map((action) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: FilledButton.icon(
                      onPressed: _busy ? null : () => _transition(action.to),
                      icon: Icon(action.icon),
                      label: Text(action.label),
                    ),
                  )),
              if (_state == 'awaiting_close' && _missing.isNotEmpty)
                Card(
                  color: Colors.orange.shade50,
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('نواقص تمنع الإقفال:',
                            style: TextStyle(fontWeight: FontWeight.bold)),
                        const SizedBox(height: 6),
                        ..._missing.map((m) => Text('• $m', style: const TextStyle(fontSize: 13))),
                      ],
                    ),
                  ),
                ),
              if (_state == 'completed')
                const Card(
                  color: Color(0xFFECFDF5),
                  child: ListTile(
                    leading: Icon(Icons.verified, color: Colors.teal),
                    title: Text('الزيارة مقفلة'),
                    subtitle: Text('التقرير يُرسل للعميل بعد اكتمال المزامنة.'),
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
    final lastSynced = visit['last_synced_at'] as String?;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(visit['wo_title'] as String? ?? '',
                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
            const SizedBox(height: 6),
            Text('${visit['site_name'] ?? ''} — ${visit['site_address'] ?? ''}'),
            if ((visit['access_notes'] as String?)?.isNotEmpty ?? false) ...[
              const SizedBox(height: 8),
              Row(children: [
                const Icon(Icons.key_outlined, size: 16, color: Colors.black54),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(visit['access_notes'] as String,
                      style: const TextStyle(fontSize: 13, color: Colors.black54)),
                ),
              ]),
            ],
            const Divider(height: 24),
            // Stale data must announce itself. Working offline is fine; pretending
            // the numbers are live is not.
            Row(children: [
              const Icon(Icons.history, size: 15, color: Colors.black45),
              const SizedBox(width: 6),
              Text(
                lastSynced == null
                    ? 'بيانات غير مزامنة'
                    : 'حُدّثت من الخادم: ${lastSynced.substring(0, 16).replaceFirst('T', ' ')}',
                style: const TextStyle(fontSize: 12, color: Colors.black45),
              ),
            ]),
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
                  child: Text(asset['name'] as String? ?? '',
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
                if (underWarranty)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: Colors.amber.shade100,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    // Billing a part on an in-warranty asset is a real and
                    // expensive mistake, so the warning sits on the tile itself.
                    child: const Text('تحت الضمان',
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                  ),
              ],
            ),
            if ((asset['location'] as String?)?.isNotEmpty ?? false)
              Text(asset['location'] as String,
                  style: const TextStyle(fontSize: 12, color: Colors.black54)),
            const SizedBox(height: 10),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'ok', label: Text('سليم')),
                ButtonSegment(value: 'needs_followup', label: Text('متابعة')),
                ButtonSegment(value: 'fault', label: Text('عطل')),
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
                    label: const Text('تصوير'),
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
