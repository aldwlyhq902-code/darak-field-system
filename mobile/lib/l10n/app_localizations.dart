import 'package:flutter/material.dart';

class AppLocalizations {
  const AppLocalizations(this.locale);

  final Locale locale;

  static AppLocalizations of(BuildContext context) =>
      AppLocalizations(Localizations.localeOf(context));

  bool get isArabic => locale.languageCode == 'ar';

  String translate(String source) {
    if (isArabic) return _arabicAliases[source] ?? source;

    final exact = _english[source] ?? _englishAliases[source];
    if (exact != null) return exact;

    // Dynamic UI messages use a small set of explicit templates. Arbitrary
    // substring replacement produced half-Arabic, half-English sentences and
    // could even alter server/user data that happened to contain a UI word.
    for (final entry in _englishPrefixTemplates.entries) {
      if (source.startsWith(entry.key)) {
        return '${entry.value}${source.substring(entry.key.length)}';
      }
    }
    for (final entry in _englishSuffixTemplates.entries) {
      if (source.endsWith(entry.key)) {
        return '${source.substring(0, source.length - entry.key.length)}${entry.value}';
      }
    }
    return source;
  }

  static const Map<String, String> _englishPrefixTemplates = {
    'آخر مزامنة ': 'Last sync ',
    'حالة الأصل: ': 'Asset status: ',
    'سُجّل الحضور بالملصق: ': 'Attendance recorded with label: ',
    'الكمية الفعلية · ': 'Actual quantity · ',
    'محاولات: ': 'Attempts: ',
    'حُدّثت من الخادم: ': 'Updated from server: ',
    'قبل الضريبة: ': 'Before VAT: ',
    'بعد ضريبة 15%: ': 'After 15% VAT: ',
    'متصل · ': 'Online · ',
    'تعذّرت المزامنة: ': 'Sync failed: ',
    'تمت مزامنة ': 'Synced ',
  };

  static const Map<String, String> _englishSuffixTemplates = {
    ' سليمة': ' is safe',
  };

  static const Map<String, String> _englishAliases = {
    'scheduled': 'Scheduled',
    'en_route': 'En route',
    'started': 'In progress',
    'paused': 'Paused',
    'awaiting_close': 'Awaiting close',
    'completed': 'Completed',
    'reopened': 'Reopened',
    'pending': 'Pending',
    'failed': 'Failed',
    'uploaded': 'Uploaded',
    'accepted': 'Accepted',
    'in_transit': 'In transit',
  };

  static const Map<String, String> _arabicAliases = {
    'scheduled': 'مجدولة',
    'en_route': 'في الطريق',
    'started': 'قيد التنفيذ',
    'paused': 'متوقفة',
    'awaiting_close': 'بانتظار الإقفال',
    'completed': 'مكتملة',
    'reopened': 'أعيد فتحها',
    'pending': 'قيد الانتظار',
    'failed': 'فشلت',
    'uploaded': 'مرفوعة',
    'accepted': 'مقبولة',
    'in_transit': 'قيد النقل',
    'Request failed.': 'تعذّر تنفيذ الطلب.',
    'Invalid credentials.': 'بيانات الدخول غير صحيحة.',
    'Authentication required.': 'يلزم تسجيل الدخول.',
    'You are not allowed to access this resource.':
        'غير مسموح لك بالوصول إلى هذا المورد.',
    'This account is disabled.': 'هذا الحساب معطّل.',
    'This device is no longer authorised.': 'لم يعد هذا الجهاز مصرحًا له.',
    'This device has been revoked. Contact the supervisor.':
        'أُلغي اعتماد هذا الجهاز. تواصل مع المشرف.',
    'This device is registered to another account. Ask a supervisor to revoke it first.':
        'هذا الجهاز مسجل لحساب آخر. اطلب من المشرف إلغاء اعتماده أولًا.',
    'This device is registered to another user.':
        'هذا الجهاز مسجل لمستخدم آخر.',
    'This technician cannot take the visit.':
        'لا يمكن إسناد هذه الزيارة إلى هذا الفني.',
    'No bytes received.': 'لم تُستلم أي بيانات.',
    'Chunk exceeds 8MB.': 'يتجاوز الجزء 8 ميجابايت.',
    'Resume from the server offset.': 'تابع الرفع من موضع الخادم.',
    'Could not lock the upload. Retry this chunk.':
        'تعذّر حجز الرفع. أعد محاولة هذا الجزء.',
    'The chunk could not be stored. Retry it.':
        'تعذّر حفظ الجزء. أعد المحاولة.',
  };

  static const Map<String, String> _english = {
    'دارك — تطبيق الفني': 'Darak — Technician app',
    'دارك': 'Darak',
    'تطبيق الفني الميداني': 'Field technician app',
    'العربية': 'Arabic',
    'الإنجليزية': 'English',
    'تغيير اللغة': 'Change language',
    'تعذّر تجهيز التطبيق': 'Could not initialize the app',
    'لم نتمكن من فتح التخزين الآمن أو قاعدة البيانات المحلية. أعد المحاولة، وإن استمرت المشكلة تواصل مع الدعم قبل حذف التطبيق حتى لا تفقد الأعمال غير المرسلة.':
        'Secure storage or the local database could not be opened. Try again. If the problem continues, contact support before deleting the app so unsent work is not lost.',
    'إعادة المحاولة': 'Try again',
    'البريد الإلكتروني': 'Email address',
    'كلمة المرور': 'Password',
    'تسجيل الدخول': 'Sign in',
    'دخول آمن للفنيين المسجلين فقط.':
        'Secure access for registered technicians only.',
    'كل عملك يُحفظ على الجهاز أولاً ثم يُزامن عند توفر الشبكة.':
        'Your work is saved on the device first and synced when a network is available.',
    'زيارات اليوم': 'Today’s visits',
    'العهد والجرد والتحويلات': 'Custody, stocktake & transfers',
    'حالة المزامنة': 'Sync status',
    'مزامنة': 'Sync',
    'جارٍ المزامنة': 'Syncing',
    'جارٍ المزامنة…': 'Syncing…',
    'جارٍ الحفظ…': 'Saving…',
    'لا توجد زيارات محمّلة على الجهاز.': 'No visits are loaded on this device.',
    'اسحب للتحديث أو نفّذ مزامنة عند توفر الشبكة.':
        'Pull to refresh or sync when a network is available.',
    'متصل': 'Online',
    'غير متصل': 'Offline',
    'آخر مزامنة': 'Last sync',
    'لم تتم المزامنة بعد': 'Not synced yet',
    'زيارة': 'Visit',
    'مجدولة': 'Scheduled',
    'في الطريق': 'En route',
    'قيد التنفيذ': 'In progress',
    'متوقفة': 'Paused',
    'بانتظار الإقفال': 'Awaiting close',
    'مكتملة': 'Completed',
    'أعيد فتحها': 'Reopened',
    'الزيارة غير موجودة على الجهاز.':
        'The visit is not available on this device.',
    'مسح ملصق الموقع': 'Scan site label',
    'مسح ملصق الأصل': 'Scan asset label',
    'لا توجد أصول مسجلة لهذا الموقع بعد.':
        'No assets are registered for this site yet.',
    'لم تُستخدم قطع غيار في هذه الزيارة':
        'No spare parts were used during this visit',
    'أؤكد أن الزيارة لم تتطلب صرف أي قطعة.':
        'I confirm that this visit required no parts.',
    'اقتراح وتسجيل التشخيص': 'Suggest and record diagnosis',
    'طلب اعتماد عمل أو قطع إضافية':
        'Request approval for additional work or parts',
    'لن يبدأ العمل الإضافي قبل موافقة العميل.':
        'Additional work will not start before client approval.',
    'توقيع مسؤول الموقع': 'Site representative signature',
    'إعادة': 'Redo',
    'توقيع': 'Sign',
    'الزيارة مقفلة': 'Visit closed',
    'التقرير يُرسل للعميل بعد اكتمال المزامنة.':
        'The report is sent to the client after sync completes.',
    'حسناً': 'OK',
    'طوبق الأصل بالملصق.': 'Asset label matched.',
    'سُجّل الحضور بالملصق:': 'Attendance recorded with label:',
    'سليم': 'Pass',
    'متابعة': 'Follow-up',
    'عطل': 'Fault',
    'تصوير': 'Capture photo',
    'اعتماد مسؤول الموقع': 'Site representative approval',
    'مسح': 'Clear',
    'اسم المسؤول': 'Representative name',
    'الصفة (مدير الفرع، الكابتن…)': 'Role (branch manager, captain…)',
    'وقّع في المساحة أدناه:': 'Sign in the area below:',
    'حفظ التوقيع': 'Save signature',
    'التوقيع واسم المسؤول مطلوبان.':
        'Signature and representative name are required.',
    'التشخيص المقترح': 'Suggested diagnosis',
    'كود العطل أو وصف مختصر': 'Fault code or short description',
    'اقتراح من التاريخ وقاعدة المعرفة':
        'Suggest from history and knowledge base',
    'اقتراح': 'Suggestion',
    'كود/اسم التشخيص المعتمد': 'Approved diagnosis code/name',
    'ملخص الحل المنفذ': 'Implemented solution summary',
    'اعتماد التشخيص': 'Approve diagnosis',
    'اعتماد عمل وقطع إضافية': 'Additional work and parts approval',
    'عنوان الطلب': 'Request title',
    'سبب الحاجة للعمل الإضافي': 'Reason additional work is needed',
    'البنود': 'Items',
    'العمل أو القطعة': 'Work or part',
    'الكمية': 'Quantity',
    'سعر الوحدة': 'Unit price',
    'إضافة البند': 'Add item',
    'إرسال للعميل للموافقة': 'Send to client for approval',
    'فحص السيارة اليومي': 'Daily vehicle inspection',
    'العداد، السلامة، العيوب والصورة': 'Odometer, safety, defects, and photo',
    'لا توجد سيارة مسندة إليك.': 'No vehicle is assigned to you.',
    'قراءة العداد الحالية (كم)': 'Current odometer reading (km)',
    'سليمة': 'is safe',
    'العيوب أو الملاحظات': 'Defects or notes',
    'حفظ الفحص': 'Save inspection',
    'إقرار استلام العهدة': 'Custody receipt acknowledgement',
    'اسم المستلم كما في التوقيع': 'Recipient name as signed',
    'أوافق وأوقّع': 'Agree and sign',
    'مسح صنف الجرد': 'Scan stocktake item',
    'الكمية الفعلية': 'Actual quantity',
    'الكمية المعدودة': 'Counted quantity',
    'توقيع الاستلام': 'Sign receipt',
    'جلسة جرد': 'Stocktake session',
    'مسح صنف': 'Scan item',
    'إقفال الجرد': 'Close stocktake',
    'صنف': 'Item',
    'الفلاش': 'Flash',
    'ضع الرمز داخل الإطار': 'Place the code inside the frame',
    'إسقاط الملف؟': 'Discard file?',
    'هذا يحذف الملف من الجهاز نهائيًا. استخدمه فقط إذا تعذّر إعادة التصوير.':
        'This permanently deletes the file from the device. Use only when recapturing is impossible.',
    'تراجع': 'Cancel',
    'إسقاط': 'Discard',
    'إعادة الرفع': 'Retry upload',
    'إسقاط الملف': 'Discard file',
    'لا توجد عمليات مرفوضة': 'No rejected operations',
    'العمليات المعلقة': 'Pending operations',
    'الملفات': 'Files',
    'الأحداث': 'Events',
    'مرفوعة': 'Uploaded',
    'فشلت': 'Failed',
    'قيد الانتظار': 'Pending',
    'غير معروف': 'Unknown',
    'لا توجد ملفات بانتظار الرفع.': 'No files are waiting for upload.',
    'لا توجد أحداث بانتظار الإرسال.': 'No events are waiting to be sent.',
    'التقاط صورة': 'Capture photo',
    'حفظ': 'Save',
    'إلغاء': 'Cancel',
    'إرسال': 'Submit',
    'إغلاق': 'Close',
    'نعم': 'Yes',
    'لا': 'No',
    'يلزم اتصال بالشبكة لأول تسجيل دخول فقط. بعده يعمل التطبيق بلا إنترنت.':
        'A network connection is required only for the first sign-in. The app then works offline.',
    'السيارة': 'Vehicle',
    'العهد': 'Custody',
    'جلسات الجرد': 'Stocktake sessions',
    'تحويلات مخزون السيارات': 'Vehicle stock transfers',
    'لا توجد عهد مسندة إليك.': 'No custody items are assigned to you.',
    'لا توجد جلسة جرد مفتوحة لك.': 'You have no open stocktake session.',
    'لا توجد تحويلات تنتظر إجراءك.':
        'No transfers are waiting for your action.',
    'تأكيد التسليم': 'Confirm dispatch',
    'تأكيد الاستلام': 'Confirm receipt',
    'تم إقفال الجرد وتسوية الفروقات.':
        'The stocktake was closed and variances reconciled.',
    'تم تسجيل الإجراء.': 'Action recorded.',
    'لن يُرفع هذا الملف ولن يمنع إقفال الزيارة بعد الآن. التقط بديلاً إن كان الدليل ما زال مطلوباً.':
        'This file will not be uploaded and will no longer block visit closure. Capture a replacement if the evidence is still required.',
    'بانتظار المزامنة': 'Waiting to sync',
    'فشل': 'Failed',
    'تمت': 'Done',
    'مزامنة الآن': 'Sync now',
    'أدلة لم تُرفع': 'Evidence not uploaded',
    'الزيارة لا تُقفل بدونها. أعد المحاولة، وإن تعذّر فأعد الالتقاط.':
        'The visit cannot close without it. Retry or capture it again.',
    'صورة': 'Photo',
    'محاولات:': 'Attempts:',
    'عمليات مرفوضة': 'Rejected operations',
    'رفضها الخادم لسبب واضح. صحّح السبب ثم أعد المحاولة.':
        'The server rejected these operations with a reason. Correct it and retry.',
    'انطلاق': 'Start route',
    'بدء العمل في الموقع': 'Start on-site work',
    'إيقاف مؤقت': 'Pause',
    'إنهاء العمل': 'Finish work',
    'استئناف': 'Resume',
    'إقفال الزيارة': 'Close visit',
    'إقفال': 'Close',
    'لا يمكن الإقفال بعد': 'Visit cannot be closed yet',
    'حالة الأصل:': 'Asset status:',
    'صورة للأصل:': 'Asset photo:',
    'سجّل القطع المستخدمة أو صرّح بعدم استخدام قطع':
        'Record used parts or declare that no parts were used',
    'سُجّلت العملية محلياً وستُزامن تلقائياً.':
        'The action was saved locally and will sync automatically.',
    'هذه الخطوة غير متاحة من الحالة الحالية.':
        'This action is not available from the current state.',
    'الملصق الممسوح لا يطابق': 'The scanned label does not match',
    'تحقق من الجهاز.': 'Check the asset.',
    'الأصول': 'Assets',
    'صورة للأصل': 'Asset photo',
    'إن استُخدمت قطع فاصرفها من مخزون سيارتك بدل تفعيل هذا الخيار.':
        'If parts were used, issue them from your vehicle stock instead of enabling this option.',
    'يرسل البنود والأسعار للعميل قبل التركيب':
        'Sends items and prices to the client before installation',
    'أُرسل الطلب للعميل، وانتظر موافقته قبل التنفيذ.':
        'The request was sent to the client. Wait for approval before proceeding.',
    'تم التوقيع': 'Signed',
    'مطلوب قبل الإقفال': 'Required before closing',
    'نواقص تمنع الإقفال:': 'Missing requirements blocking closure:',
    'بيانات غير مزامنة': 'Unsynced data',
    'حُدّثت من الخادم:': 'Updated from server:',
    'تحت الضمان': 'Under warranty',
    'مسح رمز QR': 'Scan QR code',
    'هذا الرمز ليس من النوع المطلوب': 'This is not the required code type',
    'امسح الملصق الصحيح.': 'Scan the correct label.',
    'وجّه الكاميرا نحو الملصق': 'Point the camera at the label',
    'قبل الضريبة:': 'Before VAT:',
    'بعد ضريبة 15%:': 'After 15% VAT:',
    'متصل ·': 'Online ·',
    'بلا شبكة — العمل مستمر ويُحفظ محلياً ·':
        'Offline — work continues and is saved locally ·',
    'وقائية': 'Preventive',
    'بلاغ': 'Reactive',
    'إعادة زيارة': 'Revisit',
    'مقفلة': 'Closed',
    'أُعيد فتحها': 'Reopened',
    'اسحب للأسفل للمزامنة عند توفر الشبكة.':
        'Pull down to sync when a network is available.',
    'الإطارات': 'Tires',
    'الفرامل': 'Brakes',
    'الأنوار': 'Lights',
    'الزيوت والسوائل': 'Fluids',
    'الهيكل الخارجي': 'Body',
    'النظافة': 'Cleanliness',
    'أدخل قراءة عداد صحيحة.': 'Enter a valid odometer reading.',
    'تم إرفاق الصورة — إعادة الالتقاط': 'Photo attached — retake',
    'انتهت الجلسة — سجّل الدخول مرة أخرى. عملك محفوظ ولم يضع منه شيء.':
        'Your session expired. Sign in again; your saved work was not lost.',
    'تعذّرت المزامنة:': 'Sync failed:',
    'تعذّر الرفع': 'Upload failed',
    'أسقطه الفني بعد فشل الرفع':
        'Discarded by the technician after the upload failed',
    'رُفضت': 'Rejected',
    'عملية': 'operation(s)',
    'عملية — راجع شاشة المزامنة':
        'operation(s) were rejected — review the sync screen',
    'بانتظار اكتمال رفع الأدلة قبل إرسال الإقفال':
        'Waiting for evidence uploads before sending closure',
    'لا شيء بانتظار المزامنة': 'Nothing is waiting to sync',
    'تمت مزامنة': 'Synced',
    'تعذّرت المزامنة بشكل غير متوقع. عملك محفوظ للمحاولة التالية.':
        'Sync failed unexpectedly. Your work is saved for the next attempt.',
    'حُفظ الفحص والسيارة صالحة.':
        'The inspection was saved and the vehicle is roadworthy.',
    'فشل فحص السلامة وأُوقفت السيارة.':
        'The safety inspection failed and the vehicle was taken out of service.',
  };
}

extension LocalizationContext on BuildContext {
  String tr(String source) => AppLocalizations.of(this).translate(source);
}

class LText extends StatelessWidget {
  const LText(
    this.data, {
    super.key,
    this.style,
    this.textAlign,
    this.textDirection,
    this.softWrap,
    this.overflow,
    this.maxLines,
    this.semanticsLabel,
  });

  final String data;
  final TextStyle? style;
  final TextAlign? textAlign;
  final TextDirection? textDirection;
  final bool? softWrap;
  final TextOverflow? overflow;
  final int? maxLines;
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) => Text(
    context.tr(data),
    style: style,
    textAlign: textAlign,
    textDirection: textDirection,
    softWrap: softWrap,
    overflow: overflow,
    maxLines: maxLines,
    semanticsLabel: semanticsLabel == null ? null : context.tr(semanticsLabel!),
  );
}

class LanguageButton extends StatelessWidget {
  const LanguageButton({
    required this.isArabic,
    required this.onPressed,
    this.compact = false,
    super.key,
  });

  final bool isArabic;
  final VoidCallback onPressed;
  final bool compact;

  @override
  Widget build(BuildContext context) => TextButton.icon(
    onPressed: onPressed,
    icon: const Icon(Icons.language),
    label: Text(isArabic ? 'English' : 'العربية'),
    style: compact
        ? TextButton.styleFrom(
            minimumSize: const Size(44, 44),
            padding: const EdgeInsets.symmetric(horizontal: 8),
          )
        : null,
  );
}
