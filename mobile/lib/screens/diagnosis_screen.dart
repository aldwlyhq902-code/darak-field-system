import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/api_client.dart';

class DiagnosisScreen extends StatefulWidget {
  const DiagnosisScreen({
    super.key,
    required this.state,
    required this.visitId,
  });
  final AppState state;
  final int visitId;

  @override
  State<DiagnosisScreen> createState() => _DiagnosisScreenState();
}

class _DiagnosisScreenState extends State<DiagnosisScreen> {
  final fault = TextEditingController();
  final diagnosis = TextEditingController();
  final summary = TextEditingController();
  List<Map<String, dynamic>> suggestions = const [];
  bool busy = false;

  Future<void> _suggest() async {
    if (fault.text.trim().isEmpty) return;
    setState(() => busy = true);
    try {
      final response = await widget.state.api.diagnosisSuggestions(
        widget.visitId,
        fault.text.trim(),
      );
      final list = (response['data'] as List? ?? const [])
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
      if (mounted) {
        setState(() => suggestions = list);
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

  Future<void> _save() async {
    if (fault.text.trim().isEmpty || diagnosis.text.trim().isEmpty) return;
    setState(() => busy = true);
    try {
      await widget.state.api.recordDiagnosis(
        visitId: widget.visitId,
        faultCode: fault.text.trim(),
        diagnosisCode: diagnosis.text.trim(),
        summary: summary.text.trim(),
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
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const LText('التشخيص المقترح')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          controller: fault,
          decoration: InputDecoration(
            labelText: context.tr('كود العطل أو وصف مختصر'),
          ),
        ),
        const SizedBox(height: 10),
        FilledButton.tonalIcon(
          onPressed: busy ? null : _suggest,
          icon: const Icon(Icons.auto_awesome),
          label: const LText('اقتراح من التاريخ وقاعدة المعرفة'),
        ),
        ...suggestions.map(
          (row) => Card(
            child: ListTile(
              title: LText('${row['title'] ?? row['diagnosis'] ?? 'اقتراح'}'),
              subtitle: LText('${row['solution'] ?? row['diagnosis'] ?? ''}'),
              onTap: () {
                diagnosis.text = '${row['diagnosis'] ?? row['title'] ?? ''}';
                summary.text = '${row['solution'] ?? ''}';
              },
            ),
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: diagnosis,
          decoration: InputDecoration(
            labelText: context.tr('كود/اسم التشخيص المعتمد'),
          ),
        ),
        const SizedBox(height: 10),
        TextField(
          controller: summary,
          maxLines: 5,
          decoration: InputDecoration(
            labelText: context.tr('ملخص الحل المنفذ'),
          ),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: busy ? null : _save,
          child: const LText('اعتماد التشخيص'),
        ),
      ],
    ),
  );
}
