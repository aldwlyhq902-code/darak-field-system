import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

import '../app_state.dart';
import '../core/action_outbox.dart';
import '../core/business_time.dart';
import '../core/event_queue.dart';
import 'sync_screen.dart';
import 'field_tools_screen.dart';
import 'visit_screen.dart';

class TodayScreen extends StatefulWidget {
  const TodayScreen({super.key, required this.state});

  final AppState state;

  @override
  State<TodayScreen> createState() => _TodayScreenState();
}

class _TodayScreenState extends State<TodayScreen> {
  bool _initialLoad = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadInitialWork());
  }

  /// Pushes queued work BEFORE refreshing. Pulling first, on every open, rolled
  /// offline progress back to the server's older state.
  Future<void> _loadInitialWork() async {
    try {
      await widget.state.pullWork();
    } finally {
      if (mounted) {
        setState(() => _initialLoad = false);
      }
    }
  }

  /// Sign-out is refused while anything is unsynced: signing out wipes the
  /// device, and that work exists nowhere else.
  Future<void> _signOut() async {
    final state = widget.state;
    final unsynced = await state.unsyncedWork();
    if (!mounted) return;

    if (!unsynced.isEmpty) {
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const LText('لا يمكن تسجيل الخروج الآن'),
          content: LText(
            'على الجهاز ${unsynced.total} عنصر لم يُرسل بعد '
            '(${unsynced.events} عملية، ${unsynced.media} ملف، ${unsynced.actions} إجراء). '
            'تسجيل الخروج يمسح بيانات الجهاز، فزامن أولاً من شاشة المزامنة.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const LText('حسناً'),
            ),
            FilledButton(
              onPressed: () {
                Navigator.pop(context);
                Navigator.of(this.context).push(
                  MaterialPageRoute(builder: (_) => SyncScreen(state: state)),
                );
              },
              child: const LText('حالة المزامنة'),
            ),
          ],
        ),
      );
      return;
    }

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const LText('تسجيل الخروج؟'),
        content: const LText(
          'ستُمسح زيارات اليوم وبياناتها من هذا الجهاز، ويلزم تسجيل الدخول والاتصال بالشبكة للعودة.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const LText('تراجع'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const LText('تسجيل الخروج'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    final done = await state.signOut();
    if (!done && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: LText('ظهر عمل غير مُزامَن للتو. زامن ثم أعد المحاولة.'),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.state;

    return AnimatedBuilder(
      animation: state,
      builder: (context, _) {
        final pending =
            (state.queueCounts[QueuedStatus.pending] ?? 0) +
            state.pendingUploads +
            state.outstandingActions
                .where((a) => a.status == ActionStatus.pending)
                .length;
        final failed =
            (state.queueCounts[QueuedStatus.failed] ?? 0) +
            state.outstandingActions
                .where((a) => a.status == ActionStatus.failed)
                .length;
        final drift = state.clockDrift;

        return Scaffold(
          appBar: AppBar(
            title: const LText('زيارات اليوم'),
            actions: [
              LanguageButton(
                isArabic: state.isArabic,
                onPressed: state.toggleLocale,
                compact: true,
              ),
              IconButton(
                tooltip: context.tr('العهد والجرد والتحويلات'),
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => FieldToolsScreen(state: state),
                  ),
                ),
                icon: const Icon(Icons.inventory_2_outlined),
              ),
              IconButton(
                tooltip: context.tr('حالة المزامنة'),
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => SyncScreen(state: state)),
                ),
                icon: Badge(
                  isLabelVisible: pending + failed > 0,
                  label: LText('${pending + failed}'),
                  backgroundColor: failed > 0 ? Colors.red : Colors.orange,
                  child: const Icon(Icons.sync),
                ),
              ),
              PopupMenuButton<String>(
                tooltip: context.tr('المزيد'),
                onSelected: (value) {
                  if (value == 'sign_out') _signOut();
                },
                itemBuilder: (context) => [
                  PopupMenuItem(
                    value: 'sign_out',
                    child: Row(
                      children: [
                        const Icon(Icons.logout, size: 20),
                        const SizedBox(width: 10),
                        LText(
                          state.technicianName?.isNotEmpty == true
                              ? 'تسجيل الخروج (${state.technicianName})'
                              : 'تسجيل الخروج',
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ],
          ),
          body: RefreshIndicator(
            onRefresh: state.runSync,
            child: Column(
              children: [
                _ConnectionBanner(state: state),
                // A phone clock far from the server's makes every timestamp
                // look tampered with. Say so where it can be fixed.
                if (drift != null && drift.abs() > const Duration(minutes: 5))
                  Container(
                    width: double.infinity,
                    color: const Color(0xFFFFF7ED),
                    padding: const EdgeInsets.symmetric(
                      horizontal: 16,
                      vertical: 8,
                    ),
                    child: LText(
                      'ساعة الجهاز تختلف عن توقيت الخادم بنحو ${drift.inMinutes.abs()} دقيقة. فعّل الضبط التلقائي للوقت من الإعدادات.',
                      style: const TextStyle(fontSize: 12),
                    ),
                  ),
                Expanded(
                  child: (_initialLoad || state.syncing) && state.visits.isEmpty
                      ? const Center(child: CircularProgressIndicator())
                      : state.visits.isEmpty
                      ? const _EmptyState()
                      : ListView.builder(
                          padding: const EdgeInsets.all(12),
                          itemCount: state.visits.length,
                          itemBuilder: (context, index) => _VisitCard(
                            state: state,
                            visit: state.visits[index],
                          ),
                        ),
                ),
              ],
            ),
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: state.syncing ? null : state.runSync,
            icon: state.syncing
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : const Icon(Icons.cloud_upload_outlined),
            label: LText(state.syncing ? 'جارٍ المزامنة' : 'مزامنة'),
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
        ? 'لم تتم المزامنة بعد'
        : 'آخر مزامنة ${BusinessTime.time(synced, state.locale.languageCode)}';

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
            child: LText(
              state.online
                  ? 'متصل · $label'
                  : 'بلا شبكة — العمل مستمر ويُحفظ محلياً · $label',
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
    // Site (Riyadh) time, never the raw UTC fields of the parsed instant.
    final start = BusinessTime.parse(visit['scheduled_start'] as String?);
    final day = start == null
        ? null
        : BusinessTime.dayLabel(
            start,
            state.clock.bestEstimate,
            state.locale.languageCode,
          );
    final slaStatus = visit['sla_status'] as String?;
    final isRework = (visit['is_rework'] as int? ?? 0) == 1;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 10,
        ),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) =>
                VisitScreen(state: state, visitId: visit['id'] as int),
          ),
        ),
        leading: CircleAvatar(
          backgroundColor: _slaColor(slaStatus).withValues(alpha: 0.15),
          child: LText(
            start == null ? '—' : BusinessTime.time(start),
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.bold,
              color: _slaColor(slaStatus),
            ),
          ),
        ),
        title: LText(
          '${visit['client_name'] ?? ''} — ${visit['site_name'] ?? ''}',
          style: const TextStyle(fontWeight: FontWeight.w600),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 4),
            LText(visit['wo_title'] as String? ?? ''),
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: [
                // The list also carries tomorrow's work; say which day.
                if (day != null) _Chip(label: day, color: Colors.indigo),
                _Chip(
                  label: _stateLabel(visit['state'] as String?),
                  color: Colors.blueGrey,
                ),
                if (visit['wo_type'] == 'preventive')
                  const _Chip(label: 'وقائية', color: Colors.teal),
                if (visit['wo_type'] == 'reactive')
                  const _Chip(label: 'بلاغ', color: Colors.deepOrange),
                if (isRework)
                  const _Chip(label: 'إعادة زيارة', color: Colors.red),
              ],
            ),
          ],
        ),
        trailing: Icon(
          state.isArabic ? Icons.chevron_left : Icons.chevron_right,
        ),
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
      child: LText(
        label,
        style: TextStyle(
          fontSize: 11,
          color: color,
          fontWeight: FontWeight.w600,
        ),
      ),
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
        Center(child: LText('لا توجد زيارات محمّلة على الجهاز.')),
        SizedBox(height: 4),
        Center(
          child: LText(
            'اسحب للأسفل للمزامنة عند توفر الشبكة.',
            style: TextStyle(fontSize: 12, color: Colors.black54),
          ),
        ),
      ],
    );
  }
}
