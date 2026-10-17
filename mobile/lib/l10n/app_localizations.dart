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
    for (final (pattern, render) in _englishPatterns) {
      final match = pattern.firstMatch(source);
      if (match != null) return render(match);
    }
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

  /// Whole-sentence templates whose only variable parts are numbers or a
  /// name, so the English never comes out half-translated.
  static final List<(RegExp, String Function(Match))> _englishPatterns = [
    (
      RegExp(r'^تمت مزامنة (\d+) عملية، وبقي (\d+) بانتظار إعادة المحاولة$'),
      (m) => 'Synced ${m[1]} operation(s); ${m[2]} still waiting for a retry',
    ),
    (RegExp(r'^تمت مزامنة (\d+) عملية$'), (m) => 'Synced ${m[1]} operation(s)'),
    (
      RegExp(
        r'^أُقفل الحساب مؤقتاً بعد محاولات دخول متكررة\. أعد المحاولة بعد (\d+) دقيقة\.$',
      ),
      (m) =>
          'The account is temporarily locked after repeated sign-in attempts. Try again in ${m[1]} minute(s).',
    ),
    (
      RegExp(r'^لم يُرسل شيء بعد — (\d+) بانتظار إعادة المحاولة تلقائياً$'),
      (m) => 'Nothing sent yet — ${m[1]} waiting for an automatic retry',
    ),
    (
      RegExp(r'^رُفضت (\d+) عملية — راجع شاشة المزامنة$'),
      (m) => '${m[1]} operation(s) were rejected — review the sync screen',
    ),
    (
      RegExp(r'^بانتظار اكتمال رفع الأدلة قبل إرسال الإقفال \((\d+)\)$'),
      (m) => 'Waiting for evidence uploads before sending the close (${m[1]})',
    ),
    (
      RegExp(
        r'^على هذا الجهاز (\d+) عنصر غير مُزامَن يخص فنياً آخر\. '
        r'يجب أن يسجّل ذلك الفني دخوله ويزامن عمله أولاً، أو يُلغي المشرف اعتماد الجهاز لمسحه\.$',
      ),
      (m) =>
          'This device holds ${m[1]} unsynced item(s) belonging to another technician. '
          'That technician must sign in and sync first, or a supervisor must revoke the device to erase them.',
    ),
    (
      RegExp(
        r'^على الجهاز (\d+) عنصر لم يُرسل بعد \((\d+) عملية، (\d+) ملف، (\d+) إجراء\)\. '
        r'تسجيل الخروج يمسح بيانات الجهاز، فزامن أولاً من شاشة المزامنة\.$',
      ),
      (m) =>
          '${m[1]} item(s) on this device have not been sent yet (${m[2]} operation(s), ${m[3]} file(s), ${m[4]} action(s)). '
          'Signing out erases the device data, so sync first from the sync screen.',
    ),
    (
      RegExp(
        r'^ساعة الجهاز تختلف عن توقيت الخادم بنحو (\d+) دقيقة\. فعّل الضبط التلقائي للوقت من الإعدادات\.$',
      ),
      (m) =>
          'The phone clock differs from server time by about ${m[1]} minute(s). Turn on automatic time in Settings.',
    ),
    (
      RegExp(
        r'^المسافة عن الموقع نحو (\S+) م \(المسموح (\S+) م\)\. اكتب سبباً مختصراً للمتابعة\.$',
      ),
      (m) =>
          'You are about ${m[1]} m from the site (allowed ${m[2]} m). Enter a short reason to continue.',
    ),
    (
      RegExp(
        r'^الكمية أكبر من المتوفر في السيارة \((\S+)\)\. صحّحها أو راجع المشرف\.$',
      ),
      (m) =>
          'More than the van holds (${m[1]}). Correct it or check with the supervisor.',
    ),
    (RegExp(r'^صُرفت (\d+) قطعة$'), (m) => '${m[1]} part issue(s) recorded'),
    (
      RegExp(
        r'^أصناف معدودة: (\d+)\. بعد الإرسال لا يمكن مسح أصناف أخرى، ويعتمد المشرف الفروقات\.$',
      ),
      (m) =>
          'Items counted: ${m[1]}. After submitting no more items can be scanned; the supervisor approves the variances.',
    ),
    (
      RegExp(r'^نسختك: (\S+) · المطلوبة: (\S+)$'),
      (m) => 'Your version: ${m[1]} · Required: ${m[2]}',
    ),
    (RegExp(r'^(.*) · المتوفر: (.*)$'), (m) => '${m[1]} · Available: ${m[2]}'),
  ];

  static const Map<String, String> _englishPrefixTemplates = {
    'تسجيل الخروج (': 'Sign out (',
    'المتوفر في السيارة: ': 'Available in the van: ',
    // The server's blocker list stays in Arabic (it is the server's wording).
    'لم يقبل الخادم الإقفال. النواقص: ':
        'The server did not accept the close. Missing: ',
    'عمليات مسح بانتظار الإرسال: ': 'Scans waiting to be sent: ',
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
    ' · بانتظار الاستلام': ' · Awaiting receipt',
    ' · مستلمة': ' · Received',
    ' · مرجعة': ' · Returned',
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
    // Forced update (update_required_screen.dart).
    'حدّث التطبيق': 'Update the app',
    'هذه النسخة لم تعد مدعومة. اطلب ملف التحديث من المشرف وثبّته فوق هذه النسخة دون حذفها.':
        'This version is no longer supported. Ask the supervisor for the update file and install it over this version without uninstalling it.',
    'لديك عمل لم يُرسل بعد، وهو محفوظ على الجهاز. لا تحذف التطبيق — التحديث فوقه يحفظه ويرسله.':
        'You have work that has not been sent yet; it is saved on the device. Do not uninstall the app — updating over it keeps it and sends it.',
    'تحقّق مرة أخرى': 'Check again',
    'نسخة التطبيق قديمة ولم تعد مدعومة. ثبّت النسخة الجديدة من المشرف — عملك محفوظ على الجهاز وسيُرسل بعد التحديث.':
        'This app version is no longer supported. Install the new version from the supervisor — your work is saved on the device and will be sent after the update.',
    // Parts issue (parts_screen.dart, visit_screen.dart).
    'صرف قطع الغيار': 'Issue spare parts',
    'مسح ملصق القطعة': 'Scan part label',
    'هذه القطعة غير مسجلة في مخزون سيارتك. راجع المشرف.':
        'This part is not in your van stock. Check with the supervisor.',
    'الكمية المصروفة': 'Quantity issued',
    'صرف': 'Issue',
    'أدخل كمية أكبر من صفر.': 'Enter a quantity above zero.',
    'سُجّل صرف القطعة محلياً وسيُرسل تلقائياً.':
        'Part issue saved on the device; it will be sent automatically.',
    'أُلغي صرف القطعة.': 'Part issue withdrawn.',
    'أُرسل الصرف للخادم بالفعل. لإرجاع القطعة راجع المشرف.':
        'The issue already reached the server. To return the part, contact the supervisor.',
    'صُرفت لهذه الزيارة': 'Issued to this visit',
    'لا يوجد مخزون سيارة مسجل لك على هذا الجهاز. حدّث البيانات عند توفر الشبكة، أو اطلب من المشرف ربط سيارة بحسابك.':
        'No van stock is registered for you on this device. Refresh when online, or ask the supervisor to link a van to your account.',
    'مخزون السيارة': 'Van stock',
    'ابحث باسم القطعة أو رمزها': 'Search by part name or code',
    'لا توجد قطع في مخزون سيارتك.': 'There are no parts in your van stock.',
    'رفضه الخادم': 'Refused by the server',
    'أُرسل': 'Sent',
    'إلغاء الصرف': 'Withdraw issue',
    'قطع الغيار المصروفة': 'Parts issued',
    'لم تُصرف قطع لهذه الزيارة بعد': 'No parts issued to this visit yet',
    'صرف قطعة': 'Issue a part',
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
    'غداً': 'Tomorrow',
    'أمس': 'Yesterday',
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
    'التوقيع واسم المسؤول وصفته مطلوبة.':
        'Signature, representative name and role are required.',
    'أكمل البند: الوصف، وكمية أكبر من صفر، وسعر صحيح.':
        'Complete the item: a description, a quantity above zero and a valid price.',
    'أكمل العنوان والوصف وأضف بنداً واحداً على الأقل.':
        'Fill in the title and description and add at least one item.',
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
    'إرسال الجرد للمراجعة': 'Submit stocktake for review',
    'إرسال الجرد للمراجعة؟': 'Submit the stocktake for review?',
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
    'أُرسل الجرد للمراجعة. يعتمد المشرف الفروقات.':
        'Stocktake submitted for review. The supervisor approves the variances.',
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
    // Errors (lib/core/error_messages.dart).
    'تعذّر تنفيذ الطلب. أعد المحاولة لاحقاً.':
        'The request could not be completed. Try again later.',
    'بانتظار موافقة المشرف على هذا الجهاز. أعد المحاولة لاحقاً.':
        'Waiting for supervisor approval of this device. Try again later.',
    'تعذّر الوصول إلى الخادم. تحقق من الشبكة — عملك محفوظ على الجهاز.':
        'The server could not be reached. Check the network — your work is saved on the device.',
    'الخادم لم يردّ في الوقت المحدد. ستُعاد المحاولة تلقائياً.':
        'The server did not answer in time. It will be retried automatically.',
    'تعذّر التحقق من هوية الخادم. تجنّب الشبكات العامة وأعد المحاولة.':
        'The server identity could not be verified. Avoid public networks and try again.',
    'أُلغي اعتماد هذا الجهاز من المشرف.': 'A supervisor revoked this device.',
    'هذا الحساب معطّل. تواصل مع المشرف.':
        'This account is disabled. Contact your supervisor.',
    'هذا الجهاز مسجل لحساب آخر. اطلب من المشرف إلغاء اعتماده أولًا.':
        'This device is registered to another account. Ask a supervisor to revoke it first.',
    'هذا الجهاز مسجل لمستخدم آخر.':
        'This device is registered to another user.',
    'سُجّلت هذه العملية باسم فني آخر فرفضها الخادم.':
        'This action was recorded under another technician, so the server refused it.',
    'لا يمكن إقفال الزيارة بعد — تنقصها متطلبات.':
        'The visit cannot be closed yet — requirements are missing.',
    'هذه الخطوة لم تعد متاحة من حالة الزيارة على الخادم.':
        'This step is no longer available from the visit state on the server.',
    'بيانات العملية غير صالحة فرفضها الخادم.':
        'The action data was invalid, so the server refused it.',
    'رمز التحقق بخطوتين مطلوب لهذا الحساب.':
        'A two-factor code is required for this account.',
    'أكمل إعداد التحقق بخطوتين من لوحة الإدارة أولاً.':
        'Finish two-factor setup in the admin panel first.',
    'طلبات كثيرة خلال وقت قصير. انتظر قليلاً ثم أعد المحاولة.':
        'Too many requests in a short time. Wait a moment and try again.',
    'الخادم غير متاح حالياً. عملك محفوظ وستُعاد المحاولة تلقائياً.':
        'The server is unavailable right now. Your work is saved and will be retried automatically.',
    'غير مسموح لك بتنفيذ هذا الإجراء.':
        'You are not allowed to perform this action.',
    'العنصر لم يعد موجوداً على الخادم. حدّث البيانات.':
        'This item no longer exists on the server. Refresh the data.',
    'تعارض مع حالة أحدث على الخادم. حدّث البيانات ثم أعد المحاولة.':
        'This conflicts with a newer state on the server. Refresh and try again.',
    'حجم البيانات أكبر من المسموح.': 'The data is larger than allowed.',
    'البيانات المدخلة غير مقبولة. راجعها ثم أعد المحاولة.':
        'The entered data was not accepted. Review it and try again.',
    'رفض الخادم هذه العملية.': 'The server refused this action.',
    'بيانات الدخول غير صحيحة.': 'Invalid credentials.',
    'يلزم تسجيل الدخول.': 'Sign-in required.',
    'غير مسموح لك بالوصول إلى هذا المورد.':
        'You are not allowed to access this resource.',
    'لا يمكن إسناد هذه الزيارة إلى هذا الفني.':
        'This visit cannot be assigned to this technician.',
    // Session, wipe and sign-out.
    'استجابة غير متوقعة من الخادم. أعد المحاولة.':
        'Unexpected response from the server. Try again.',
    'هذا الحساب معطّل، فمُسحت بيانات العمل من هذا الجهاز. تواصل مع المشرف.':
        'This account is disabled, so the work data was erased from this device. Contact your supervisor.',
    'أُلغي اعتماد هذا الجهاز من المشرف، فمُسحت بيانات العمل منه. تواصل مع المشرف.':
        'A supervisor revoked this device, so its work data was erased. Contact your supervisor.',
    'لا يمكن تسجيل الخروج الآن': 'You cannot sign out now',
    'تسجيل الخروج؟': 'Sign out?',
    'تسجيل الخروج': 'Sign out',
    'ستُمسح زيارات اليوم وبياناتها من هذا الجهاز، ويلزم تسجيل الدخول والاتصال بالشبكة للعودة.':
        'Today’s visits and their data will be erased from this device; signing back in needs a network connection.',
    'ظهر عمل غير مُزامَن للتو. زامن ثم أعد المحاولة.':
        'Unsynced work just appeared. Sync, then try again.',
    'المزيد': 'More',
    // Visit transitions and the geofence.
    'إلغاء الانطلاق': 'Cancel departure',
    'العودة للعمل': 'Back to work',
    'إعادة فتح الزيارة': 'Reopen visit',
    'استئناف العمل': 'Resume work',
    'تعود الزيارة إلى «مجدولة» ويتوقف احتساب وقت الطريق.':
        'The visit returns to “Scheduled” and travel time stops counting.',
    'الزيارة مقفلة وقد أُرسل تقريرها. أعد فتحها فقط إن كان هناك عمل لم يكتمل فعلاً.':
        'The visit is closed and its report was sent. Reopen it only if work is genuinely unfinished.',
    'تنبيه: يبدو أن الموقع صادر من تطبيق مواقع وهمية. سُجّلت العملية، وسيظهر ذلك للمشرف.':
        'Note: the location appears to come from a mock-location app. The action was recorded, and your supervisor will see this.',
    'أنت خارج نطاق موقع العميل': 'You are outside the client site area',
    'سبب العمل خارج النطاق': 'Reason for working outside the area',
    'تأكيد ومتابعة': 'Confirm and continue',
    // The action outbox.
    'حُفظ الإجراء على الجهاز وسيُرسل تلقائياً عند توفر الشبكة.':
        'Saved on the device; it will be sent automatically when a network is available.',
    'بلا شبكة — تُعرض آخر بيانات محفوظة، والإجراءات تُرسل عند عودة الاتصال.':
        'Offline — showing the last saved data; actions are sent when the connection returns.',
    'فحص بانتظار الإرسال': 'Inspection waiting to be sent',
    'بانتظار الإرسال': 'Waiting to send',
    'الاقتراحات تحتاج اتصالاً بالشبكة. يمكنك كتابة التشخيص وحفظه الآن وسيُرسل لاحقاً.':
        'Suggestions need a network connection. You can type and save the diagnosis now; it will be sent later.',
    'اعتُمد التشخيص.': 'Diagnosis recorded.',
    'حُفظ الطلب على الجهاز وسيُرسل للعميل عند توفر الشبكة. لا تبدأ التنفيذ قبل موافقته.':
        'The request was saved on the device and will be sent to the client when a network is available. Do not start before approval.',
    'حُفظ الفحص على الجهاز وسيُرسل تلقائياً عند توفر الشبكة.':
        'The inspection was saved on the device and will be sent automatically when a network is available.',
    'إجراءات بانتظار الإرسال': 'Actions waiting to be sent',
    'العهد والجرد والتحويلات والتشخيص وطلبات العمل الإضافي والفحص. تُرسل تلقائياً عند توفر الشبكة.':
        'Custody, stocktake, transfers, diagnoses, additional-work requests and inspections. Sent automatically when a network is available.',
    'تجاهل': 'Dismiss',
    'تجاهل العملية': 'Dismiss this operation',
    'تجاهل هذه العملية؟': 'Dismiss this operation?',
    'لن تُرسل إلى الخادم أبداً، ولا يمكن التراجع. استخدم هذا فقط إذا رفضها الخادم لسبب لن يتغير، أو بعد مراجعة المشرف.':
        'It will never be sent to the server, and this cannot be undone. Use it only when the server refused it for a reason that will not change, or after checking with the supervisor.',
    'لا توجد أصول مرتبطة بهذه الزيارة — اطلب من المشرف ربط أصل بها قبل الإقفال.':
        'No assets are linked to this visit — ask the supervisor to link one before closing.',
    'الزيارة مقفلة على الجهاز': 'Visit closed on this device',
    'بانتظار تأكيد الخادم بعد المزامنة.':
        'Waiting for the server to confirm after sync.',
    'رفض الخادم الإقفال:': 'The server refused the close:',
    'أكمل هذه النواقص ثم أعد الإقفال.':
        'Complete these items, then close again.',
    'رفض الخادم الملف أو تعذّرت قراءته.':
        'The server refused the file, or it could not be read.',
    // Contract additions: lockout, idempotency in progress, per-event codes.
    'أُقفل الحساب مؤقتاً بعد محاولات دخول متكررة. أعد المحاولة بعد قليل.':
        'The account is temporarily locked after repeated sign-in attempts. Try again shortly.',
    'الطلب السابق نفسه ما زال قيد التنفيذ على الخادم. سيُعاد التحقق تلقائياً.':
        'The same request is still being processed by the server. It will be checked again automatically.',
    'الزيارة غير مسندة إليك، فرفض الخادم العملية.':
        'The visit is not assigned to you, so the server refused the action.',
    'أُعيد إسناد الزيارة إلى فني آخر، فرفض الخادم العملية. راجع المشرف.':
        'The visit was reassigned to another technician, so the server refused the action. Check with your supervisor.',
    'القطعة ليست ضمن قطع هذه الزيارة.':
        'The part is not among this visit’s parts.',
    'لا تملك صلاحية الصرف من موقع المخزون هذا.':
        'You are not allowed to issue stock from this location.',
    'رُفضت حركة المخزون — قد لا تكون الكمية متاحة في مخزون سيارتك.':
        'The stock movement was refused — the quantity may not be available in your vehicle stock.',
    'تعذّر على الخادم تنفيذ العملية مؤقتاً. ستُعاد المحاولة تلقائياً.':
        'The server could not process the action right now. It will be retried automatically.',
    'الصورة أكبر من الحد الذي يقبله الخادم. أسقطها والتقط صورة بديلة.':
        'The image is larger than the server accepts. Discard it and take a replacement.',
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
