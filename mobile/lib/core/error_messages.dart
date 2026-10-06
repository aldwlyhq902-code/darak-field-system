import 'package:flutter/foundation.dart';

import 'api_client.dart';

/// Turns any failure into one Arabic sentence a technician can act on.
///
/// Raw exception text ("SocketException: Failed host lookup…", "HTTP_500:
/// Request failed.") used to reach the screen. It is English, it is noise to a
/// technician, and it can leak internals. The details go to the debug log; the
/// screen gets the sentence. The English UI translates these through
/// [AppLocalizations] like any other Arabic copy.
String friendlyError(Object error, {String? context}) {
  if (error is! ApiException) {
    debugPrint(
      'Unexpected error${context == null ? '' : ' ($context)'}: $error',
    );
    return genericErrorMessage;
  }

  if (error.cause != null || error.statusCode >= 500) {
    debugPrint(
      'API error${context == null ? '' : ' ($context)'}: '
      '${error.statusCode} ${error.code} ${error.message} ${error.cause ?? ''}',
    );
  }

  if (error.code == 'ACCOUNT_TEMPORARILY_LOCKED') {
    return accountLockedMessage(error.retryAfter);
  }

  final byCode = _byCode[error.code];
  if (byCode != null) return byCode;

  if (error.isConnectivity) return _byCode['NETWORK']!;
  if (error.isServerTransient) {
    return error.statusCode == 429
        ? 'طلبات كثيرة خلال وقت قصير. انتظر قليلاً ثم أعد المحاولة.'
        : 'الخادم غير متاح حالياً. عملك محفوظ وستُعاد المحاولة تلقائياً.';
  }
  if (error.isSessionExpired) return sessionExpiredMessage;

  // The server's own sentence when it is already Arabic (several endpoints
  // answer in Arabic), or a known English one mapped.
  final serverMessage = _serverMessage(error.body);
  if (serverMessage != null) return serverMessage;

  return switch (error.statusCode) {
    403 => 'غير مسموح لك بتنفيذ هذا الإجراء.',
    404 => 'العنصر لم يعد موجوداً على الخادم. حدّث البيانات.',
    409 => 'تعارض مع حالة أحدث على الخادم. حدّث البيانات ثم أعد المحاولة.',
    413 => 'حجم البيانات أكبر من المسموح.',
    422 => 'البيانات المدخلة غير مقبولة. راجعها ثم أعد المحاولة.',
    _ => genericErrorMessage,
  };
}

/// A rejection reason stored on a queued item, in the technician's language.
String friendlyRejection(String? code, String? message) {
  final byCode = _byCode[code];
  if (byCode != null) return byCode;
  final mapped = _serverMessage({'message': message});
  if (mapped != null) return mapped;
  return 'رفض الخادم هذه العملية.';
}

/// Login refused after too many attempts (429 ACCOUNT_TEMPORARILY_LOCKED).
/// Rounded UP: "try in 0 minutes" would only earn another refusal.
String accountLockedMessage(Duration? retryAfter) {
  final seconds = retryAfter?.inSeconds;
  if (seconds == null || seconds <= 0) {
    return 'أُقفل الحساب مؤقتاً بعد محاولات دخول متكررة. أعد المحاولة بعد قليل.';
  }
  final minutes = (seconds + 59) ~/ 60;
  return 'أُقفل الحساب مؤقتاً بعد محاولات دخول متكررة. أعد المحاولة بعد $minutes دقيقة.';
}

const genericErrorMessage = 'تعذّر تنفيذ الطلب. أعد المحاولة لاحقاً.';
const sessionExpiredMessage =
    'انتهت الجلسة — سجّل الدخول مرة أخرى. عملك محفوظ ولم يضع منه شيء.';
const devicePendingApprovalMessage =
    'بانتظار موافقة المشرف على هذا الجهاز. أعد المحاولة لاحقاً.';

const Map<String, String> _byCode = {
  'NETWORK': 'تعذّر الوصول إلى الخادم. تحقق من الشبكة — عملك محفوظ على الجهاز.',
  'TIMEOUT': 'الخادم لم يردّ في الوقت المحدد. ستُعاد المحاولة تلقائياً.',
  'TLS': 'تعذّر التحقق من هوية الخادم. تجنّب الشبكات العامة وأعد المحاولة.',
  'DEVICE_REVOKED': 'أُلغي اعتماد هذا الجهاز من المشرف.',
  'USER_INACTIVE': 'هذا الحساب معطّل. تواصل مع المشرف.',
  'DEVICE_PENDING_APPROVAL': devicePendingApprovalMessage,
  'DEVICE_BOUND_TO_ANOTHER_USER':
      'هذا الجهاز مسجل لحساب آخر. اطلب من المشرف إلغاء اعتماده أولًا.',
  'DEVICE_MISMATCH': 'هذا الجهاز مسجل لمستخدم آخر.',
  'IDEMPOTENCY_KEY_REUSED': genericErrorMessage,
  'IDEMPOTENCY_KEY_INVALID': genericErrorMessage,
  'IDEMPOTENCY_REQUEST_IN_PROGRESS':
      'الطلب السابق نفسه ما زال قيد التنفيذ على الخادم. سيُعاد التحقق تلقائياً.',
  // Per-event refusals (sync/events). Definitive: shown on the sync screen.
  'ACTOR_MISMATCH': 'سُجّلت هذه العملية باسم فني آخر فرفضها الخادم.',
  'VISIT_NOT_ASSIGNED': 'الزيارة غير مسندة إليك، فرفض الخادم العملية.',
  'VISIT_REASSIGNED':
      'أُعيد إسناد الزيارة إلى فني آخر، فرفض الخادم العملية. راجع المشرف.',
  'PART_NOT_ON_VISIT': 'القطعة ليست ضمن قطع هذه الزيارة.',
  'STOCK_LOCATION_FORBIDDEN': 'لا تملك صلاحية الصرف من موقع المخزون هذا.',
  'STOCK_MOVE_REFUSED':
      'رُفضت حركة المخزون — قد لا تكون الكمية متاحة في مخزون سيارتك.',
  'NOT_FOUND': 'العنصر لم يعد موجوداً على الخادم. حدّث البيانات.',
  // Server-side failures: retried automatically, never parked as failed.
  'EVENT_FAILED':
      'تعذّر على الخادم تنفيذ العملية مؤقتاً. ستُعاد المحاولة تلقائياً.',
  'DB_ERROR':
      'تعذّر على الخادم تنفيذ العملية مؤقتاً. ستُعاد المحاولة تلقائياً.',
  // Media.
  'IMAGE_TOO_LARGE':
      'الصورة أكبر من الحد الذي يقبله الخادم. أسقطها والتقط صورة بديلة.',
  'VISIT_CLOSE_BLOCKED': 'لا يمكن إقفال الزيارة بعد — تنقصها متطلبات.',
  'INVALID_VISIT_TRANSITION':
      'هذه الخطوة لم تعد متاحة من حالة الزيارة على الخادم.',
  'INVALID_EVENT_PAYLOAD': 'بيانات العملية غير صالحة فرفضها الخادم.',
  'TWO_FACTOR_REQUIRED': 'رمز التحقق بخطوتين مطلوب لهذا الحساب.',
  'TWO_FACTOR_SETUP_REQUIRED':
      'أكمل إعداد التحقق بخطوتين من لوحة الإدارة أولاً.',
};

/// Server copy that ships in English, mapped once here.
const Map<String, String> _knownServerMessages = {
  'Invalid credentials.': 'بيانات الدخول غير صحيحة.',
  'Authentication required.': 'يلزم تسجيل الدخول.',
  'Unauthenticated.': 'يلزم تسجيل الدخول.',
  'You are not allowed to access this resource.':
      'غير مسموح لك بالوصول إلى هذا المورد.',
  'This action is unauthorized.': 'غير مسموح لك بتنفيذ هذا الإجراء.',
  'This account is disabled.': 'هذا الحساب معطّل. تواصل مع المشرف.',
  'This technician cannot take the visit.':
      'لا يمكن إسناد هذه الزيارة إلى هذا الفني.',
};

final RegExp _arabic = RegExp(r'[؀-ۿ]');

String? _serverMessage(Map<String, dynamic> body) {
  final candidates = <String>[
    if (body['message'] is String) body['message'] as String,
    // Laravel validation: {"errors": {"field": ["first problem", ...]}}
    if (body['errors'] is Map)
      for (final value in (body['errors'] as Map).values)
        if (value is List && value.isNotEmpty && value.first is String)
          value.first as String,
  ];

  for (final text in candidates) {
    final known = _knownServerMessages[text.trim()];
    if (known != null) return known;
  }
  for (final text in candidates) {
    if (_arabic.hasMatch(text) && text.length <= 300) return text.trim();
  }
  return null;
}

/// True when [code] has its own sentence (rather than a generic one).
bool hasFriendlyMessage(String? code) => _byCode.containsKey(code);
