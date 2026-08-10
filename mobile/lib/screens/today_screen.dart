import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../app_state.dart';
import '../core/event_queue.dart';
import 'sync_screen.dart';
import 'visit_screen.dart';

class TodayScreen extends StatefulWidget {
  const TodayScreen({super.key, required this.state});

  final AppState state;

  @override
  State<TodayScreen> createState() => _TodayScreenState();
}

class _TodayScreenState extends State<TodayScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => widget.state.pullWork());
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.state;

    return AnimatedBuilder(
      animation: state,
      builder: (context, _) {
        final pending = state.queueCounts[QueuedStatus.pending] ?? 0;
        final failed = state.queueCounts[QueuedStatus.failed] ?? 0;

        return Scaffold(
          appBar: AppBar(
            title: const Text('زيارات اليوم'),
            actions: [
              IconButton(
                tooltip: 'حالة المزامنة',
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => SyncScreen(state: state)),
                ),
                icon: Badge(
                  isLabelVisible: pending + failed > 0,
                  label: Text('${pending + failed}'),
                  backgroundColor: failed > 0 ? Colors.red : Colors.orange,
                  child: const Icon(Icons.sync),
                ),
              ),
            ],
          ),
          body: RefreshIndicator(
            onRefresh: state.runSync,
            child: Column(
              children: [
                _ConnectionBanner(state: state),
                Expanded(
                  child: state.visits.isEmpty
                      ? const _EmptyState()
                      : ListView.builder(
                          padding: const EdgeInsets.all(12),
                          itemCount: state.visits.length,
                          itemBuilder: (context, index) =>
                              _VisitCard(state: state, visit: state.visits[index]),
                        ),
                ),
              ],
            ),
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: state.syncing ? null : state.runSync,
            icon: state.syncing
                ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.cloud_upload_outlined),
            label: Text(state.syncing ? 'جارٍ المزامنة' : 'مزامنة'),
          ),
        );
      },
    );
  }
}

/// Offline is a normal state here, not an error. The bar states the last sync
/// time so a technician always knows how fresh the data in front of them is.
class _ConnectionBanner extends StatelessWidget {
  const _ConnectionBanner({required this.state});

  final AppState state;

  @override
  Widget build(BuildContext context) {
    final synced = state.lastSyncedAt;
    final label = synced == null
        ? 'لم تتم مزامنة بعد'
        : 'آخر مزامنة ${DateFormat('HH:mm', 'ar').format(synced)}';

    return Container(
      width: double.infinity,
      color: state.online ? const Color(0xFFECFDF5) : const Color(0xFFFFF7ED),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          Icon(
            state.online ? Icons.wifi : Icons.wifi_off,
            size: 18,
            color: state.online ? Colors.teal.shade700 : Colors.orange.shade800,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              state.online ? 'متصل · $label' : 'بلا شبكة — العمل مستمر ويُحفظ محلياً · $label',
              style: const TextStyle(fontSize: 12),
            ),
          ),
        ],
      ),
    );
  }
}

class _VisitCard extends StatelessWidget {
  const _VisitCard({required this.state, required this.visit});

  final AppState state;
  final Map<String, dynamic> visit;

  @override
  Widget build(BuildContext context) {
    final start = DateTime.tryParse(visit['scheduled_start'] as String? ?? '');
    final slaStatus = visit['sla_status'] as String?;
    final isRework = (visit['is_rework'] as int? ?? 0) == 1;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => VisitScreen(state: state, visitId: visit['id'] as int)),
        ),
        leading: CircleAvatar(
          backgroundColor: _slaColor(slaStatus).withValues(alpha: 0.15),
          child: Text(
            start == null ? '—' : DateFormat('HH:mm').format(start),
            style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: _slaColor(slaStatus)),
          ),
        ),
        title: Text(
          '${visit['client_name'] ?? ''} — ${visit['site_name'] ?? ''}',
          style: const TextStyle(fontWeight: FontWeight.w600),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 4),
            Text(visit['wo_title'] as String? ?? ''),
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: [
                _Chip(label: _stateLabel(visit['state'] as String?), color: Colors.blueGrey),
                if (visit['wo_type'] == 'preventive')
                  const _Chip(label: 'وقائية', color: Colors.teal),
                if (visit['wo_type'] == 'reactive')
                  const _Chip(label: 'بلاغ', color: Colors.deepOrange),
                if (isRework) const _Chip(label: 'إعادة زيارة', color: Colors.red),
              ],
            ),
          ],
        ),
        trailing: const Icon(Icons.chevron_left),
      ),
    );
  }

  static Color _slaColor(String? status) => switch (status) {
        'red' => Colors.red,
        'amber' => Colors.orange,
        _ => Colors.teal,
      };

  static String _stateLabel(String? state) => switch (state) {
        'scheduled' => 'مجدولة',
        'en_route' => 'في الطريق',
        'started' => 'قيد التنفيذ',
        'paused' => 'متوقفة',
        'awaiting_close' => 'بانتظار الإقفال',
        'completed' => 'مقفلة',
        'reopened' => 'أُعيد فتحها',
        _ => state ?? '—',
      };
}

class _Chip extends StatelessWidget {
  const _Chip({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(label, style: TextStyle(fontSize: 11, color: color, fontWeight: FontWeight.w600)),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();

  @override
  Widget build(BuildContext context) {
    return ListView(
      children: const [
        SizedBox(height: 120),
        Icon(Icons.event_available_outlined, size: 64, color: Colors.black26),
        SizedBox(height: 12),
        Center(child: Text('لا توجد زيارات محمّلة على الجهاز.')),
        SizedBox(height: 4),
        Center(
          child: Text('اسحب للأسفل للمزامنة عند توفر الشبكة.',
              style: TextStyle(fontSize: 12, color: Colors.black54)),
        ),
      ],
    );
  }
}
