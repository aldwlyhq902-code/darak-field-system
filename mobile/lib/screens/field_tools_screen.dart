import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/action_outbox.dart';
import '../core/error_messages.dart';
import '../core/sync_engine.dart';
import 'scanner_screen.dart';
import 'tile_button.dart';
import 'vehicle_inspection_screen.dart';

class FieldToolsScreen extends StatefulWidget {
  const FieldToolsScreen({super.key, required this.state});
  final AppState state;

  @override
  State<FieldToolsScreen> createState() => _FieldToolsScreenState();
}

class _FieldToolsScreenState extends State<FieldToolsScreen> {
  bool loading = true;
  bool busy = false;
  bool stale = false;
  String? error;
  List<Map<String, dynamic>> custodies = const [];
  List<Map<String, dynamic>> stocktakes = const [];
  List<Map<String, dynamic>> releases = const [];
  List<Map<String, dynamic>> receipts = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Read-through cache: with no signal the lists come from the last
  /// successful load, and every action below still works — it waits in the
  /// outbox instead of throwing.
  Future<void> _load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final state = widget.state;
      final result = await Future.wait([
        state.cached('custodies', state.api.custodies, decodeRows),
        state.cached('stocktakes', state.api.stocktakes, decodeRows),
        state.cached(
          'transfer_releases',
          state.api.transferReleases,
          decodeRows,
        ),
        state.cached(
          'transfer_receipts',
          state.api.transferReceipts,
          decodeRows,
        ),
      ]);
      if (!mounted) return;
      setState(() {
        custodies = result[0].value;
        stocktakes = result[1].value;
        releases = result[2].value;
        receipts = result[3].value;
        stale = result.any((r) => r.stale);
        loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() {
          error = friendlyError(e, context: 'field tools');
          loading = false;
        });
      }
    }
  }

  /// Every action goes through the outbox with its own idempotency key. A tap
  /// with no signal is saved and sent later; it can no longer crash the screen.
  Future<void> _perform(ActionRequest request, String doneMessage) async {
    setState(() => busy = true);
    try {
      final attempt = await widget.state.performAction(request);
      if (!mounted) return;
      final message = switch (attempt.kind) {
        ActionAttemptKind.done => doneMessage,
        ActionAttemptKind.queued =>
          'حُفظ الإجراء على الجهاز وسيُرسل تلقائياً عند توفر الشبكة.',
        ActionAttemptKind.rejected => attempt.message ?? genericErrorMessage,
        ActionAttemptKind.sessionExpired =>
          attempt.message ?? sessionExpiredMessage,
        ActionAttemptKind.remoteWipe => attempt.message ?? genericErrorMessage,
      };
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: LText(message)));
      if (attempt.kind == ActionAttemptKind.done) await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: LText(friendlyError(e, context: request.kind))),
        );
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _acceptCustody(Map<String, dynamic> custody) async {
    final controller = TextEditingController(text: widget.state.technicianName);
    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const LText('إقرار استلام العهدة'),
        content: TextField(
          controller: controller,
          decoration: InputDecoration(
            labelText: context.tr('اسم المستلم كما في التوقيع'),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const LText('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const LText('أوافق وأوقّع'),
          ),
        ],
      ),
    );
    if (name == null || name.isEmpty || !mounted) return;
    await _perform(
      ActionRequest.acceptCustody(custody['id'] as int, name),
      'تم تسجيل الإجراء.',
    );
  }

  Future<void> _scan(Map<String, dynamic> session) async {
    final code = await Navigator.push<String>(
      context,
      MaterialPageRoute(
        builder: (_) => const ScannerScreen(title: 'مسح صنف الجرد'),
      ),
    );
    if (code == null || !mounted) return;
    final quantity = TextEditingController(text: '1');
    final qty = await showDialog<double>(
      context: context,
      builder: (context) => AlertDialog(
        title: LText('الكمية الفعلية · $code'),
        content: TextField(
          controller: quantity,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: context.tr('الكمية المعدودة')),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const LText('إلغاء'),
          ),
          FilledButton(
            onPressed: () =>
                Navigator.pop(context, double.tryParse(quantity.text)),
            child: const LText('حفظ'),
          ),
        ],
      ),
    );
    if (qty == null || qty < 0 || !mounted) return;
    await _perform(
      ActionRequest.scanStocktake(session['id'] as int, code, qty),
      'تم تسجيل الإجراء.',
    );
  }

  List<PendingAction> _pendingFor(String targetKey) => widget
      .state
      .outstandingActions
      .where(
        (a) => a.targetKey == targetKey && a.status == ActionStatus.pending,
      )
      .toList();

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.state,
    builder: (context, _) => Scaffold(
      appBar: AppBar(
        title: const LText('العهد والجرد والتحويلات'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: loading
          ? const Center(child: CircularProgressIndicator())
          : error != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: LText(error!, textAlign: TextAlign.center),
              ),
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(12),
                children: [
                  if (busy) const LinearProgressIndicator(minHeight: 3),
                  if (stale)
                    Card(
                      color: const Color(0xFFFFF7ED),
                      child: const Padding(
                        padding: EdgeInsets.all(12),
                        child: LText(
                          'بلا شبكة — تُعرض آخر بيانات محفوظة، والإجراءات تُرسل عند عودة الاتصال.',
                          style: TextStyle(fontSize: 12),
                        ),
                      ),
                    ),
                  _title(context, 'السيارة'),
                  Card(
                    child: ListTile(
                      leading: const Icon(Icons.fact_check_outlined),
                      title: const LText('فحص السيارة اليومي'),
                      subtitle: LText(
                        _pendingFor('fleet:inspection').isEmpty
                            ? 'العداد، السلامة، العيوب والصورة'
                            : 'فحص بانتظار الإرسال',
                      ),
                      trailing: const Icon(Icons.chevron_left),
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) =>
                              VehicleInspectionScreen(state: widget.state),
                        ),
                      ),
                    ),
                  ),
                  _title(context, 'العهد'),
                  ...custodies.map((row) {
                    final waiting = _pendingFor('custody:${row['id']}');
                    return Card(
                      child: ListTile(
                        leading: const Icon(Icons.badge_outlined),
                        title: LText('${row['item_name']}'),
                        subtitle: LText(
                          '${row['custody_no']} · ${custodyStatusLabel(row['status'] as String?)}',
                        ),
                        trailing: waiting.isNotEmpty
                            ? const _PendingChip()
                            : row['status'] == 'issued'
                            ? TileButton(
                                onPressed: busy
                                    ? null
                                    : () => _acceptCustody(row),
                                label: 'توقيع الاستلام',
                              )
                            : const Icon(Icons.verified, color: Colors.teal),
                      ),
                    );
                  }),
                  if (custodies.isEmpty)
                    const _Empty('لا توجد عهد مسندة إليك.'),
                  _title(context, 'جلسات الجرد'),
                  ...stocktakes.map((row) {
                    final waiting = _pendingFor('stocktake:${row['id']}');
                    final scans = waiting
                        .where((a) => a.kind == 'stocktake.scan')
                        .length;
                    final closing = waiting.any(
                      (a) => a.kind == 'stocktake.complete',
                    );
                    return Card(
                      child: Column(
                        children: [
                          ListTile(
                            leading: const Icon(Icons.qr_code_scanner),
                            title: LText('${row['reference'] ?? 'جلسة جرد'}'),
                            subtitle: LText(
                              '${(row['location'] as Map?)?['name'] ?? ''}',
                            ),
                            trailing: waiting.isEmpty
                                ? null
                                : const _PendingChip(),
                          ),
                          if (scans > 0)
                            Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 16,
                              ),
                              child: LText(
                                'عمليات مسح بانتظار الإرسال: $scans',
                                style: const TextStyle(fontSize: 12),
                              ),
                            ),
                          OverflowBar(
                            alignment: MainAxisAlignment.end,
                            children: [
                              TextButton(
                                onPressed: busy || closing
                                    ? null
                                    : () => _scan(row),
                                child: const LText('مسح صنف'),
                              ),
                              FilledButton.tonal(
                                onPressed: busy || closing
                                    ? null
                                    : () => _perform(
                                        ActionRequest.completeStocktake(
                                          row['id'] as int,
                                        ),
                                        'تم إقفال الجرد وتسوية الفروقات.',
                                      ),
                                child: const LText('إقفال الجرد'),
                              ),
                            ],
                          ),
                        ],
                      ),
                    );
                  }),
                  if (stocktakes.isEmpty)
                    const _Empty('لا توجد جلسة جرد مفتوحة لك.'),
                  _title(context, 'تحويلات مخزون السيارات'),
                  ...releases.map(
                    (row) => _transfer(
                      row,
                      'تأكيد التسليم',
                      ActionRequest.releaseTransfer(row['id'] as int),
                    ),
                  ),
                  ...receipts.map(
                    (row) => _transfer(
                      row,
                      'تأكيد الاستلام',
                      ActionRequest.acceptTransfer(row['id'] as int),
                    ),
                  ),
                  if (releases.isEmpty && receipts.isEmpty)
                    const _Empty('لا توجد تحويلات تنتظر إجراءك.'),
                ],
              ),
            ),
    ),
  );

  Widget _transfer(
    Map<String, dynamic> row,
    String label,
    ActionRequest request,
  ) => Card(
    child: ListTile(
      leading: const Icon(Icons.swap_horiz),
      title: LText(
        '${(row['part'] as Map?)?['name'] ?? 'صنف'} × ${row['qty']}',
      ),
      subtitle: LText('${row['transfer_no']}'),
      trailing: _pendingFor(request.targetKey!).isNotEmpty
          ? const _PendingChip()
          : TileButton(
              onPressed: busy
                  ? null
                  : () => _perform(request, 'تم تسجيل الإجراء.'),
              label: label,
            ),
    ),
  );

  Widget _title(BuildContext context, String label) => Padding(
    padding: const EdgeInsets.only(top: 14, bottom: 6),
    child: LText(label, style: Theme.of(context).textTheme.titleLarge),
  );
}

/// Custody states as the technician reads them — never the raw code.
String custodyStatusLabel(String? status) => switch (status) {
  'issued' => 'بانتظار الاستلام',
  'accepted' => 'مستلمة',
  'returned' => 'مرجعة',
  _ => '—',
};

class _PendingChip extends StatelessWidget {
  const _PendingChip();

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
    decoration: BoxDecoration(
      color: Colors.orange.withValues(alpha: 0.12),
      borderRadius: BorderRadius.circular(20),
    ),
    child: const Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.schedule, size: 14, color: Colors.orange),
        SizedBox(width: 4),
        LText(
          'بانتظار الإرسال',
          style: TextStyle(fontSize: 11, color: Colors.orange),
        ),
      ],
    ),
  );
}

class _Empty extends StatelessWidget {
  const _Empty(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: LText(text, style: const TextStyle(color: Colors.black54)),
    ),
  );
}
