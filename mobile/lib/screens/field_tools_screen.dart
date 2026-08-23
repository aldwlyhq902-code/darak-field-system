import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/api_client.dart';
import 'scanner_screen.dart';
import 'vehicle_inspection_screen.dart';

class FieldToolsScreen extends StatefulWidget {
  const FieldToolsScreen({super.key, required this.state});
  final AppState state;

  @override
  State<FieldToolsScreen> createState() => _FieldToolsScreenState();
}

class _FieldToolsScreenState extends State<FieldToolsScreen> {
  bool loading = true;
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

  Future<void> _load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result = await Future.wait([
        widget.state.api.custodies(),
        widget.state.api.stocktakes(),
        widget.state.api.transferReleases(),
        widget.state.api.transferReceipts(),
      ]);
      if (!mounted) return;
      setState(() {
        custodies = result[0];
        stocktakes = result[1];
        releases = result[2];
        receipts = result[3];
        loading = false;
      });
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          error = e.message;
          loading = false;
        });
      }
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
    if (name == null || name.isEmpty) return;
    await widget.state.api.acceptCustody(custody['id'] as int, name);
    await _load();
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
    if (qty == null) return;
    await widget.state.api.scanStocktake(session['id'] as int, code, qty);
    await _load();
  }

  Future<void> _action(Future<void> Function() action, String message) async {
    try {
      await action();
      await _load();
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: LText(message)));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: LText(e.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const LText('العهد والجرد والتحويلات'),
      actions: [IconButton(onPressed: _load, icon: const Icon(Icons.refresh))],
    ),
    body: loading
        ? const Center(child: CircularProgressIndicator())
        : error != null
        ? Center(child: LText(error!))
        : RefreshIndicator(
            onRefresh: _load,
            child: ListView(
              padding: const EdgeInsets.all(12),
              children: [
                _title(context, 'السيارة'),
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.fact_check_outlined),
                    title: const LText('فحص السيارة اليومي'),
                    subtitle: const LText('العداد، السلامة، العيوب والصورة'),
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
                ...custodies.map(
                  (row) => Card(
                    child: ListTile(
                      leading: const Icon(Icons.badge_outlined),
                      title: LText('${row['item_name']}'),
                      subtitle: LText(
                        '${row['custody_no']} · ${row['status']}',
                      ),
                      trailing: row['status'] == 'issued'
                          ? FilledButton.tonal(
                              onPressed: () => _acceptCustody(row),
                              child: const LText('توقيع الاستلام'),
                            )
                          : const Icon(Icons.verified, color: Colors.teal),
                    ),
                  ),
                ),
                if (custodies.isEmpty) const _Empty('لا توجد عهد مسندة إليك.'),
                _title(context, 'جلسات الجرد'),
                ...stocktakes.map(
                  (row) => Card(
                    child: Column(
                      children: [
                        ListTile(
                          leading: const Icon(Icons.qr_code_scanner),
                          title: LText('${row['reference'] ?? 'جلسة جرد'}'),
                          subtitle: LText(
                            '${(row['location'] as Map?)?['name'] ?? ''}',
                          ),
                        ),
                        OverflowBar(
                          alignment: MainAxisAlignment.end,
                          children: [
                            TextButton(
                              onPressed: () => _scan(row),
                              child: const LText('مسح صنف'),
                            ),
                            FilledButton.tonal(
                              onPressed: () => _action(
                                () => widget.state.api.completeStocktake(
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
                  ),
                ),
                if (stocktakes.isEmpty)
                  const _Empty('لا توجد جلسة جرد مفتوحة لك.'),
                _title(context, 'تحويلات مخزون السيارات'),
                ...releases.map(
                  (row) => _transfer(
                    row,
                    'تأكيد التسليم',
                    () => widget.state.api.releaseTransfer(row['id'] as int),
                  ),
                ),
                ...receipts.map(
                  (row) => _transfer(
                    row,
                    'تأكيد الاستلام',
                    () => widget.state.api.acceptTransfer(row['id'] as int),
                  ),
                ),
                if (releases.isEmpty && receipts.isEmpty)
                  const _Empty('لا توجد تحويلات تنتظر إجراءك.'),
              ],
            ),
          ),
  );

  Widget _transfer(
    Map<String, dynamic> row,
    String label,
    Future<void> Function() action,
  ) => Card(
    child: ListTile(
      leading: const Icon(Icons.swap_horiz),
      title: LText(
        '${(row['part'] as Map?)?['name'] ?? 'صنف'} × ${row['qty']}',
      ),
      subtitle: LText('${row['transfer_no']}'),
      trailing: FilledButton.tonal(
        onPressed: () => _action(action, 'تم تسجيل الإجراء.'),
        child: LText(label),
      ),
    ),
  );

  Widget _title(BuildContext context, String label) => Padding(
    padding: const EdgeInsets.only(top: 14, bottom: 6),
    child: LText(label, style: Theme.of(context).textTheme.titleLarge),
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
