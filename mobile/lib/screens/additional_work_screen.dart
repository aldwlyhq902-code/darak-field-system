import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/api_client.dart';

class AdditionalWorkScreen extends StatefulWidget {
  const AdditionalWorkScreen({
    super.key,
    required this.state,
    required this.visitId,
  });
  final AppState state;
  final int visitId;
  @override
  State<AdditionalWorkScreen> createState() => _AdditionalWorkScreenState();
}

class _AdditionalWorkScreenState extends State<AdditionalWorkScreen> {
  final title = TextEditingController();
  final description = TextEditingController();
  final item = TextEditingController();
  final qty = TextEditingController(text: '1');
  final price = TextEditingController();
  final List<Map<String, dynamic>> items = [];
  bool busy = false;

  void _add() {
    final quantity = double.tryParse(qty.text),
        unitPrice = double.tryParse(price.text);
    if (item.text.trim().isEmpty ||
        quantity == null ||
        quantity <= 0 ||
        unitPrice == null ||
        unitPrice < 0) {
      return;
    }
    setState(() {
      items.add({
        'description': item.text.trim(),
        'qty': quantity,
        'unit_price': unitPrice,
      });
      item.clear();
      qty.text = '1';
      price.clear();
    });
  }

  Future<void> _send() async {
    if (title.text.trim().isEmpty ||
        description.text.trim().isEmpty ||
        items.isEmpty) {
      return;
    }
    setState(() => busy = true);
    try {
      await widget.state.api.createAdditionalWork(
        visitId: widget.visitId,
        title: title.text.trim(),
        description: description.text.trim(),
        items: items,
      );
      if (mounted) {
        Navigator.pop(context, true);
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: LText(e.message)));
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final total = items.fold<double>(
      0,
      (sum, row) =>
          sum + (row['qty'] as double) * (row['unit_price'] as double),
    );
    return Scaffold(
      appBar: AppBar(title: const LText('اعتماد عمل وقطع إضافية')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            controller: title,
            decoration: InputDecoration(labelText: context.tr('عنوان الطلب')),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: description,
            maxLines: 3,
            decoration: InputDecoration(
              labelText: context.tr('سبب الحاجة للعمل الإضافي'),
            ),
          ),
          const Divider(height: 32),
          LText('البنود', style: Theme.of(context).textTheme.titleMedium),
          TextField(
            controller: item,
            decoration: InputDecoration(
              labelText: context.tr('العمل أو القطعة'),
            ),
          ),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: qty,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: context.tr('الكمية')),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  controller: price,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: context.tr('سعر الوحدة'),
                  ),
                ),
              ),
            ],
          ),
          FilledButton.tonal(
            onPressed: _add,
            child: const LText('إضافة البند'),
          ),
          ...items.indexed.map(
            (entry) => ListTile(
              title: LText('${entry.$2['description']}'),
              subtitle: LText('${entry.$2['qty']} × ${entry.$2['unit_price']}'),
              trailing: IconButton(
                onPressed: () => setState(() => items.removeAt(entry.$1)),
                icon: const Icon(Icons.delete_outline),
              ),
            ),
          ),
          Card(
            color: const Color(0xFFECFDF5),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: LText(
                'قبل الضريبة: ${total.toStringAsFixed(2)} ر.س\nبعد ضريبة 15%: ${(total * 1.15).toStringAsFixed(2)} ر.س',
              ),
            ),
          ),
          FilledButton(
            onPressed: busy ? null : _send,
            child: const LText('إرسال للعميل للموافقة'),
          ),
        ],
      ),
    );
  }
}
