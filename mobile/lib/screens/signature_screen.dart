import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:signature/signature.dart';

/// Site representative sign-off. Returns PNG bytes plus the signer's name and
/// role — a signature with no name attached proves very little later.
class SignatureScreen extends StatefulWidget {
  const SignatureScreen({super.key});

  @override
  State<SignatureScreen> createState() => _SignatureScreenState();
}

class SignatureResult {
  const SignatureResult({required this.png, required this.name, required this.role});

  final Uint8List png;
  final String name;
  final String role;
}

class _SignatureScreenState extends State<SignatureScreen> {
  final _controller = SignatureController(
    penStrokeWidth: 3,
    penColor: Colors.black87,
    exportBackgroundColor: Colors.white,
  );
  final _name = TextEditingController();
  final _role = TextEditingController();
  bool _saving = false;

  @override
  void dispose() {
    _controller.dispose();
    _name.dispose();
    _role.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_controller.isEmpty || _name.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('التوقيع واسم المسؤول مطلوبان.')),
      );
      return;
    }

    setState(() => _saving = true);

    final png = await _controller.toPngBytes();

    if (!mounted) return;

    if (png == null) {
      setState(() => _saving = false);
      return;
    }

    Navigator.of(context).pop(SignatureResult(
      png: png,
      name: _name.text.trim(),
      role: _role.text.trim(),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('اعتماد مسؤول الموقع'),
        actions: [
          IconButton(
            tooltip: 'مسح',
            onPressed: _controller.clear,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            TextField(
              controller: _name,
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(
                labelText: 'اسم المسؤول',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _role,
              decoration: const InputDecoration(
                labelText: 'الصفة (مدير الفرع، الكابتن…)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 16),
            const Align(
              alignment: AlignmentDirectional.centerStart,
              child: Text('وقّع في المساحة أدناه:'),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: Container(
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.black26),
                  borderRadius: BorderRadius.circular(10),
                  color: Colors.white,
                ),
                clipBehavior: Clip.antiAlias,
                child: Signature(
                  controller: _controller,
                  backgroundColor: Colors.white,
                ),
              ),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: _saving ? null : _save,
              icon: const Icon(Icons.check),
              label: Text(_saving ? 'جارٍ الحفظ…' : 'حفظ التوقيع'),
            ),
          ],
        ),
      ),
    );
  }
}
