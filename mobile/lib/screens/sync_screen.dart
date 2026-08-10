import 'package:flutter/material.dart';

import '../app_state.dart';
import '../core/event_queue.dart';

/// The three states the technician must be able to see at a glance:
/// بانتظار المزامنة · فشل · تمت.
///
/// A failed action keeps its reason and can be retried. Nothing is ever hidden to
/// make the queue look clean — a silently dropped action is how field evidence
/// disappears.
class SyncScreen extends StatefulWidget {
  const SyncScreen({super.key, required this.state});

  final AppState state;

  @override
  State<SyncScreen> createState() => _SyncScreenState();
}

class _SyncScreenState extends State<SyncScreen> {
  List<QueuedEvent> _failed = const [];
  List<Map<String, dynamic>> _failedUploads = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final failed = await widget.state.queue.failed();
    // Uploads that gave up. Without them on screen, a visit could sit
    // un-closable and the technician would have no way to see why.
    final uploads = await widget.state.sync.failedUploads();
    await widget.state.refreshLocal();

    if (mounted) {
      setState(() {
        _failed = failed;
        _failedUploads = uploads;
      });
    }
  }

  Future<void> _confirmDiscard(Map<String, dynamic> upload) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('إسقاط الملف؟'),
        content: const Text(
          'لن يُرفع هذا الملف ولن يمنع إقفال الزيارة بعد الآن. '
          'التقط بديلاً إن كان الدليل ما زال مطلوباً.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('تراجع')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('إسقاط'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    await widget.state.sync.discardUpload(
      upload['client_media_id'] as String,
      reason: 'أسقطه الفني بعد فشل الرفع',
    );

    await widget.state.runSync();
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.state,
      builder: (context, _) {
        final counts = widget.state.queueCounts;

        return Scaffold(
          appBar: AppBar(title: const Text('حالة المزامنة')),
          body: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Row(
                children: [
                  _CountTile(
                    label: 'بانتظار المزامنة',
                    value: counts[QueuedStatus.pending] ?? 0,
                    color: Colors.orange,
                    icon: Icons.schedule,
                  ),
                  const SizedBox(width: 10),
                  _CountTile(
                    label: 'فشل',
                    value: counts[QueuedStatus.failed] ?? 0,
                    color: Colors.red,
                    icon: Icons.error_outline,
                  ),
                  const SizedBox(width: 10),
                  _CountTile(
                    label: 'تمت',
                    value: counts[QueuedStatus.synced] ?? 0,
                    color: Colors.teal,
                    icon: Icons.check_circle_outline,
                  ),
                ],
              ),
              const SizedBox(height: 20),
              FilledButton.icon(
                onPressed: widget.state.syncing
                    ? null
                    : () async {
                        await widget.state.runSync();
                        await _load();
                      },
                icon: const Icon(Icons.cloud_upload_outlined),
                label: Text(widget.state.syncing ? 'جارٍ المزامنة…' : 'مزامنة الآن'),
              ),
              if (widget.state.lastSyncMessage != null) ...[
                const SizedBox(height: 12),
                Text(widget.state.lastSyncMessage!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 13, color: Colors.black54)),
              ],
              const SizedBox(height: 24),
              if (_failedUploads.isNotEmpty) ...[
                Text('أدلة لم تُرفع', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                const Text(
                  'الزيارة لا تُقفل بدونها. أعد المحاولة، وإن تعذّر فأعد الالتقاط.',
                  style: TextStyle(fontSize: 12, color: Colors.black54),
                ),
                const SizedBox(height: 10),
                ..._failedUploads.map((upload) => Card(
                      color: Colors.orange.shade50,
                      child: ListTile(
                        leading: const Icon(Icons.image_not_supported_outlined, color: Colors.orange),
                        title: Text(
                          upload['kind'] == 'signature' ? 'توقيع' : 'صورة',
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('زيارة ${upload['visit_id']} · محاولات: ${upload['attempts']}',
                                style: const TextStyle(fontSize: 12)),
                            if (upload['last_error'] != null)
                              Text('${upload['last_error']}',
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(fontSize: 11, color: Colors.orange.shade900)),
                          ],
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            IconButton(
                              tooltip: 'إعادة الرفع',
                              icon: const Icon(Icons.upload_file),
                              onPressed: () async {
                                await widget.state.sync.retryUpload(upload['client_media_id'] as String);
                                await widget.state.runSync();
                                await _load();
                              },
                            ),
                            // The way out. Without it a file that will never
                            // upload holds the visit open indefinitely and the
                            // technician has nothing to press.
                            IconButton(
                              tooltip: 'إسقاط الملف',
                              icon: const Icon(Icons.delete_outline, color: Colors.red),
                              onPressed: () => _confirmDiscard(upload),
                            ),
                          ],
                        ),
                      ),
                    )),
                const SizedBox(height: 24),
              ],
              if (_failed.isNotEmpty) ...[
                Text('عمليات مرفوضة', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                const Text(
                  'رفضها الخادم لسبب واضح. صحّح السبب ثم أعد المحاولة.',
                  style: TextStyle(fontSize: 12, color: Colors.black54),
                ),
                const SizedBox(height: 10),
                ..._failed.map((event) => Card(
                      color: Colors.red.shade50,
                      child: ListTile(
                        title: Text(event.eventType),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('زيارة ${event.visitId} · محاولات: ${event.attempts}',
                                style: const TextStyle(fontSize: 12)),
                            if (event.lastError != null)
                              Text(event.lastError!,
                                  style: TextStyle(fontSize: 12, color: Colors.red.shade900)),
                          ],
                        ),
                        trailing: IconButton(
                          tooltip: 'إعادة المحاولة',
                          icon: const Icon(Icons.refresh),
                          onPressed: () async {
                            await widget.state.queue.requeue(event.clientEventId);
                            await widget.state.runSync();
                            await _load();
                          },
                        ),
                      ),
                    )),
              ] else
                const Card(
                  child: ListTile(
                    leading: Icon(Icons.verified_outlined, color: Colors.teal),
                    title: Text('لا توجد عمليات مرفوضة'),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

class _CountTile extends StatelessWidget {
  const _CountTile({
    required this.label,
    required this.value,
    required this.color,
    required this.icon,
  });

  final String label;
  final int value;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withValues(alpha: 0.25)),
        ),
        child: Column(
          children: [
            Icon(icon, color: color, size: 22),
            const SizedBox(height: 6),
            Text('$value',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: color)),
            const SizedBox(height: 2),
            Text(label,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 11, color: Colors.black54)),
          ],
        ),
      ),
    );
  }
}
