import '../core/error_messages.dart';
import '../core/sync_engine.dart';

const queuedActionMessage =
    'حُفظ الإجراء على الجهاز وسيُرسل تلقائياً عند توفر الشبكة.';

/// The one sentence to show after an outbox action was submitted.
String actionMessage(
  ActionAttempt attempt, {
  required String done,
  String queued = queuedActionMessage,
}) => switch (attempt.kind) {
  ActionAttemptKind.done => done,
  ActionAttemptKind.queued => queued,
  ActionAttemptKind.rejected => attempt.message ?? genericErrorMessage,
  ActionAttemptKind.sessionExpired => attempt.message ?? sessionExpiredMessage,
  ActionAttemptKind.remoteWipe => attempt.message ?? genericErrorMessage,
};

/// Done or safely queued: the form can close — nothing the technician typed
/// is lost either way.
bool actionKept(ActionAttempt attempt) =>
    attempt.kind == ActionAttemptKind.done ||
    attempt.kind == ActionAttemptKind.queued ||
    // Still in the outbox under its key; it goes out after signing in again.
    attempt.kind == ActionAttemptKind.sessionExpired;
