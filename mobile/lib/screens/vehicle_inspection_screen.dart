import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/api_client.dart';

class VehicleInspectionScreen extends StatefulWidget {
  const VehicleInspectionScreen({super.key, required this.state});

  final AppState state;

  @override
  State<VehicleInspectionScreen> createState() =>
      _VehicleInspectionScreenState();
}

class _VehicleInspectionScreenState extends State<VehicleInspectionScreen> {
  final odometer = TextEditingController();
  final defects = TextEditingController();
  Map<String, dynamic>? vehicle;
  Uint8List? photo;
  bool loading = true;
  bool saving = false;
  String? error;
  final checks = <String, bool>{
    'tires': true,
    'brakes': true,
    'lights': true,
    'fluids': true,
    'body': true,
    'cleanliness': true,
  };

  static const labels = <String, String>{
    'tires': 'الإطارات',
    'brakes': 'الفرامل',
    'lights': 'الأنوار',
    'fluids': 'الزيوت والسوائل',
    'body': 'الهيكل الخارجي',
    'cleanliness': 'النظافة',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    odometer.dispose();
    defects.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await widget.state.api.currentVehicle();
      if (!mounted) return;
      final data = response['data'];
      setState(() {
        vehicle = data is Map ? Map<String, dynamic>.from(data) : null;
        odometer.text = '${vehicle?['current_odometer_km'] ?? ''}';
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

  Future<void> _capture() async {
    final capture = await widget.state.camera.takePhoto();
    if (capture != null && mounted) setState(() => photo = capture.bytes);
  }

  Future<void> _submit() async {
    final reading = double.tryParse(odometer.text);
    if (reading == null) {
      setState(() => error = 'أدخل قراءة عداد صحيحة.');
      return;
    }
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final response = await widget.state.api.submitVehicleInspection(
        odometerKm: reading,
        checklist: checks,
        defects: defects.text,
        photo: photo,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: LText('${response['message']}')));
      Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          error = e.message;
          saving = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const LText('فحص السيارة اليومي')),
    body: loading
        ? const Center(child: CircularProgressIndicator())
        : vehicle == null
        ? Center(child: LText(error ?? 'لا توجد سيارة مسندة إليك.'))
        : ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Card(
                child: ListTile(
                  leading: const Icon(Icons.local_shipping_outlined),
                  title: LText('${vehicle!['plate']}'),
                  subtitle: LText(
                    '${vehicle!['make'] ?? ''} ${vehicle!['model'] ?? ''}',
                  ),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: odometer,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: InputDecoration(
                  labelText: context.tr('قراءة العداد الحالية (كم)'),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              ...checks.keys.map(
                (key) => SwitchListTile(
                  value: checks[key]!,
                  title: LText('${labels[key]} سليمة'),
                  secondary: Icon(
                    checks[key]! ? Icons.check_circle : Icons.warning,
                  ),
                  onChanged: (value) => setState(() => checks[key] = value),
                ),
              ),
              TextField(
                controller: defects,
                maxLines: 3,
                decoration: InputDecoration(
                  labelText: context.tr('العيوب أو الملاحظات'),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: _capture,
                icon: Icon(
                  photo == null ? Icons.camera_alt_outlined : Icons.check,
                ),
                label: LText(
                  photo == null
                      ? 'التقاط صورة'
                      : 'تم إرفاق الصورة — إعادة الالتقاط',
                ),
              ),
              if (error != null)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: LText(
                    error!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ),
              FilledButton(
                onPressed: saving ? null : _submit,
                child: saving
                    ? const SizedBox(
                        width: 24,
                        height: 24,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const LText('حفظ الفحص'),
              ),
            ],
          ),
  );
}
