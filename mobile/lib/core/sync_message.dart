import 'sync_engine.dart';

/// The one line under the sync button.
///
/// It used to say "تمت مزامنة 0 عملية" (synced 0) whenever the only events
/// left were deferred for a retry — a success message for a sync that sent
/// nothing. [stillPending] is what remains queued after the run (events,
/// actions and uploads), so the message can say what is actually true.
String describeSync(SyncOutcome outcome, {required int stillPending}) {
  if (outcome.error != null) return outcome.error!;

  final rejected = outcome.rejected + outcome.actionsRejected;
  if (rejected > 0) {
    return 'رُفضت $rejected عملية — راجع شاشة المزامنة';
  }

  if (outcome.heldBack > 0) {
    return 'بانتظار اكتمال رفع الأدلة قبل إرسال الإقفال (${outcome.heldBack})';
  }

  final sent = outcome.sent + outcome.actionsSent + outcome.uploaded;

  if (sent == 0) {
    return stillPending > 0
        ? 'لم يُرسل شيء بعد — $stillPending بانتظار إعادة المحاولة تلقائياً'
        : 'لا شيء بانتظار المزامنة';
  }

  return stillPending > 0
      ? 'تمت مزامنة $sent عملية، وبقي $stillPending بانتظار إعادة المحاولة'
      : 'تمت مزامنة $sent عملية';
}
