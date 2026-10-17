import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/error_messages.dart';
import '../core/numbers.dart';
import '../core/parts.dart';
import 'scanner_screen.dart';

/// Issuing parts from the technician's own van to this visit — offline.
///
/// This screen did not exist. The close gate asked "parts used or none?" and
/// the only answer the phone could give was "none", so every part fitted on
/// site left the van uncounted and unbilled.
class PartsScreen extends StatefulWidget {
  const PartsScreen({super.key, required this.state, required this.visitId});

  final AppState state;
  final int visitId;

  @override
  State<PartsScreen> createState() => _PartsScreenState();
}

class _PartsScreenState extends State<PartsScreen> {
  VehicleStock? _stock;
  List<IssuedPart> _issued = const [];
  List<IssuedPart> _unsentEverywhere = const [];
  String _filter = '';
  bool _loading = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final stock = await widget.state.vehicleStock();
    final issued = await widget.state.issuedParts(widget.visitId);
    final unsent = await widget.state.unsentIssues();
    if (!mounted) return;
    setState(() {
      _stock = stock;
      _issued = issued;
      _unsentEverywhere = unsent;
      _loading = false;
    });
  }

  VanPart? _partById(int id) {
    for (final part in _stock?.parts ?? const <VanPart>[]) {
      if (part.partId == id) return part;
    }
    return null;
  }

  List<VanPart> get _visibleParts {
    final parts = _stock?.parts ?? const <VanPart>[];
    final filter = _filter.trim().toLowerCase();
    if (filter.isEmpty) return parts;
    return parts
        .where(
          (part) =>
              part.name.toLowerCase().contains(filter) ||
              (part.sku?.toLowerCase().contains(filter) ?? false),
        )
        .toList();
  }

  Future<void> _scan() async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => const ScannerScreen(title: 'مسح ملصق القطعة'),
      ),
    );
    if (code == null || !mounted) return;

    final match = (_stock?.parts ?? const <VanPart>[])
        .where((part) => part.matchesCode(code))
        .firstOrNull;
    if (match == null) {
      _tell('هذه القطعة غير مسجلة في مخزون سيارتك. راجع المشرف.');
      return;
    }
    await _issue(match);
  }

  Future<void> _issue(VanPart part) async {
    final stock = _stock;
    if (stock == null) return;
    final available = availableInVan(part, _unsentEverywhere);

    final controller = TextEditingController(text: '1');
    final qty = await showDialog<double>(
      context: context,
      builder: (context) => AlertDialog(
        title: LText(part.name),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            LText(
              'المتوفر في السيارة: ${formatQuantity(available)} ${part.unit ?? ''}',
              style: const TextStyle(fontSize: 13),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: controller,
              autofocus: true,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              decoration: InputDecoration(
                labelText: context.tr('الكمية المصروفة'),
                border: const OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const LText('إلغاء'),
          ),
          FilledButton(
            onPressed: () =>
                Navigator.pop(context, parseLocalizedNumber(controller.text)),
            child: const LText('صرف'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (qty == null || !mounted) return;

    if (qty <= 0) {
      _tell('أدخل كمية أكبر من صفر.');
      return;
    }
    if (qty > available + 0.0005) {
      _tell(
        'الكمية أكبر من المتوفر في السيارة (${formatQuantity(available)}). صحّحها أو راجع المشرف.',
      );
      return;
    }

    setState(() => _busy = true);
    try {
      await widget.state.issuePart(
        widget.visitId,
        part: part,
        qty: qty,
        fromLocationId: stock.locationId,
      );
      await _load();
      _tell('سُجّل صرف القطعة محلياً وسيُرسل تلقائياً.');
    } catch (error) {
      _tell(friendlyError(error, context: 'part issue'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _withdraw(IssuedPart issue) async {
    setState(() => _busy = true);
    try {
      final withdrawn = await widget.state.withdrawIssue(issue.clientEventId);
      await _load();
      _tell(
        withdrawn
            ? 'أُلغي صرف القطعة.'
            : 'أُرسل الصرف للخادم بالفعل. لإرجاع القطعة راجع المشرف.',
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _tell(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: LText(message)));
  }

  @override
  Widget build(BuildContext context) {
    final stock = _stock;
    return Scaffold(
      appBar: AppBar(
        title: const LText('صرف قطع الغيار'),
        actions: [
          if (stock != null)
            IconButton(
              tooltip: context.tr('مسح ملصق القطعة'),
              onPressed: _busy ? null : _scan,
              icon: const Icon(Icons.qr_code_scanner),
            ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (_issued.isNotEmpty) ...[
                  LText(
                    'صُرفت لهذه الزيارة',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  ..._issued.map(_issuedTile),
                  const SizedBox(height: 20),
                ],
                if (stock == null)
                  const Card(
                    child: Padding(
                      padding: EdgeInsets.all(16),
                      child: LText(
                        'لا يوجد مخزون سيارة مسجل لك على هذا الجهاز. حدّث البيانات عند توفر الشبكة، أو اطلب من المشرف ربط سيارة بحسابك.',
                      ),
                    ),
                  )
                else ...[
                  LText(
                    'مخزون السيارة',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    decoration: InputDecoration(
                      prefixIcon: const Icon(Icons.search),
                      labelText: context.tr('ابحث باسم القطعة أو رمزها'),
                      border: const OutlineInputBorder(),
                    ),
                    onChanged: (value) => setState(() => _filter = value),
                  ),
                  const SizedBox(height: 8),
                  if (stock.parts.isEmpty)
                    const Card(
                      child: Padding(
                        padding: EdgeInsets.all(16),
                        child: LText('لا توجد قطع في مخزون سيارتك.'),
                      ),
                    ),
                  ..._visibleParts.map(_vanTile),
                ],
              ],
            ),
    );
  }

  Widget _vanTile(VanPart part) {
    final available = availableInVan(part, _unsentEverywhere);
    return Card(
      child: ListTile(
        minVerticalPadding: 12,
        leading: const Icon(Icons.inventory_2_outlined),
        title: LText(part.name),
        subtitle: LText(
          '${part.sku ?? ''} · المتوفر: ${formatQuantity(available)} ${part.unit ?? ''}',
        ),
        trailing: const Icon(Icons.add_circle_outline),
        enabled: !_busy && available > 0,
        onTap: () => _issue(part),
      ),
    );
  }

  Widget _issuedTile(IssuedPart issue) {
    final part = _partById(issue.partId);
    final (label, color) = issue.refused
        ? ('رفضه الخادم', Colors.red.shade700)
        : issue.unsent
        ? ('بانتظار الإرسال', Colors.orange.shade900)
        : ('أُرسل', Colors.teal.shade700);
    return Card(
      color: issue.refused ? Colors.red.shade50 : null,
      child: ListTile(
        leading: Icon(Icons.build_circle_outlined, color: color),
        title: LText(
          '${part?.name ?? 'قطعة #${issue.partId}'} × ${formatQuantity(issue.qty)}',
        ),
        subtitle: LText(
          issue.refused
              ? friendlyRejection(
                  issue.lastError?.split(':').first,
                  issue.lastError,
                )
              : label,
          style: TextStyle(color: color, fontSize: 13),
        ),
        trailing: issue.unsent
            ? IconButton(
                tooltip: context.tr('إلغاء الصرف'),
                onPressed: _busy ? null : () => _withdraw(issue),
                icon: const Icon(Icons.undo),
              )
            : null,
      ),
    );
  }
}
