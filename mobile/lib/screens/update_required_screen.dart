import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/local_db.dart';

/// The server stopped serving this build (426 APP_UPDATE_REQUIRED).
///
/// Shown instead of the app, never instead of the data: nothing is wiped and
/// nothing is signed out. The new APK installs over this one and the same
/// queue syncs straight after.
class UpdateRequiredScreen extends StatefulWidget {
  const UpdateRequiredScreen({super.key, required this.state});

  final AppState state;

  @override
  State<UpdateRequiredScreen> createState() => _UpdateRequiredScreenState();
}

class _UpdateRequiredScreenState extends State<UpdateRequiredScreen> {
  UnsyncedCounts? _unsynced;
  bool _checking = false;

  @override
  void initState() {
    super.initState();
    widget.state.unsyncedWork().then((counts) {
      if (mounted) setState(() => _unsynced = counts);
    });
  }

  Future<void> _recheck() async {
    setState(() => _checking = true);
    await widget.state.recheckAppVersion();
    if (mounted) setState(() => _checking = false);
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.state;
    final unsynced = _unsynced;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.system_update, size: 64, color: Colors.teal),
                  const SizedBox(height: 16),
                  LText(
                    'حدّث التطبيق',
                    style: Theme.of(context).textTheme.headlineSmall,
                  ),
                  const SizedBox(height: 10),
                  const LText(
                    'هذه النسخة لم تعد مدعومة. اطلب ملف التحديث من المشرف وثبّته فوق هذه النسخة دون حذفها.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 12),
                  LText(
                    'نسختك: ${state.appVersion ?? '—'} · المطلوبة: ${state.requiredVersion ?? '—'}',
                    style: const TextStyle(fontSize: 13),
                  ),
                  if (unsynced != null && !unsynced.isEmpty) ...[
                    const SizedBox(height: 12),
                    const Card(
                      color: Color(0xFFFFFBEB),
                      child: Padding(
                        padding: EdgeInsets.all(12),
                        child: LText(
                          'لديك عمل لم يُرسل بعد، وهو محفوظ على الجهاز. لا تحذف التطبيق — التحديث فوقه يحفظه ويرسله.',
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  FilledButton.icon(
                    onPressed: _checking ? null : _recheck,
                    icon: const Icon(Icons.refresh),
                    label: const LText('تحقّق مرة أخرى'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
