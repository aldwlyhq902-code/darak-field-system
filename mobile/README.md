# تطبيق الفني — دارك (Flutter، أندرويد فقط)

تطبيق ميداني يعمل بلا شبكة: كل إجراء يُكتب أولاً في قاعدة SQLCipher على الجهاز ثم
يُزامن لاحقاً. المرجع العام للنظام في [`../README.md`](../README.md)، ومفتاح التوقيع في
[`android/KEYSTORE_README.md`](android/KEYSTORE_README.md).

## التشغيل والاختبار

```bash
cd mobile
flutter pub get
flutter analyze
flutter test                      # اختبارات الوحدة على SQLite الحقيقي (FFI)
flutter run                       # debug — يتصل بـ http://10.0.2.2:8000 افتراضياً
```

اختبار السيناريو بلا شبكة على محاكي أو جهاز أندرويد حقيقي (SQLCipher وقناة الساعة
الأصلية حقيقيتان، والخادم وهمي داخل الاختبار):

```bash
flutter devices
flutter test integration_test/offline_scenario_test.dart -d <device-id>
```

## خيارات البناء (`--dart-define`)

| المتغير | الإلزام | المعنى |
|---|---|---|
| `DARAK_API` | إلزامي في release | عنوان الخادم، HTTPS فقط في release. |
| `DARAK_SECURE_SCREEN` | اختياري | `FLAG_SECURE` يمنع لقطات الشاشة وصورة التطبيق في قائمة التطبيقات الأخيرة. **مفعّل افتراضياً في release** ومعطّل في debug. `false` يعطّله (لالتقاط صور المتجر مثلاً). |
| `DARAK_PINS` | اختياري | تثبيت مفتاح شهادة الخادم (انظر أدناه). إن غاب يعمل التطبيق كما كان: تحقق الشهادة المعتاد فقط. |

```bash
flutter build apk --release --split-per-abi \
  --dart-define=DARAK_API=https://panel.darak.sa \
  --dart-define=DARAK_PINS=<البصمة الحالية>,<بصمة المفتاح الاحتياطي>
```

## تثبيت الشهادة (Certificate pinning)

عند تمرير `DARAK_PINS` يرفض التطبيق أي اتصال TLS لا يقدّم فيه الخادم شهادة **مفتاحها
العام** (SubjectPublicKeyInfo) مطابق لإحدى البصمات، وذلك **إضافةً** إلى التحقق المعتاد
من سلسلة الشهادات. الفحص يتم بعد المصافحة وقبل إرسال أي بايت، فلا يصل رمز الدخول ولا
بيانات الطلب إلى خادم فشل الفحص. يُفحص مفتاح الشهادة النهائية (leaf) لأن Dart لا يكشف
بقية السلسلة. التثبيت يُطبَّق متى مُرّرت البصمات (في debug أيضاً، ولا أثر له على اتصال
`http` المحلي).

البصمة = `base64(SHA-256(SubjectPublicKeyInfo))`.

**بصمة الشهادة الحالية على الخادم:**

```bash
openssl s_client -connect panel.darak.sa:443 -servername panel.darak.sa </dev/null 2>/dev/null \
  | openssl x509 -pubkey -noout \
  | openssl pkey -pubin -outform der \
  | openssl dgst -sha256 -binary | openssl enc -base64
```

**بصمة مفتاح احتياطي (إلزامية):** أنشئ مفتاحاً خاصاً جديداً الآن، واحفظه خارج الخادم
(مثل مفتاح التوقيع)، وضع بصمته مع البصمة الحالية:

```bash
openssl ecparam -genkey -name prime256v1 -noout -out darak-backup-pin.key
openssl pkey -in darak-backup-pin.key -pubout -outform der \
  | openssl dgst -sha256 -binary | openssl enc -base64
```

### خطر "قفل" التطبيق عند تغيير الشهادة — اقرأ قبل التفعيل

البصمات مدمجة داخل ملف APK. إذا تغيّر **مفتاح** شهادة الخادم إلى مفتاح ليست بصمته
في القائمة، تتوقف كل النسخ المثبتة عن الاتصال فوراً: لا دخول، لا مزامنة، والعمل
المحفوظ على الأجهزة يبقى عالقاً حتى يثبّت كل فني تحديثاً جديداً يدوياً — ولا يمكن
إيصال التحديث عبر التطبيق نفسه.

- ضع دائماً بصمتين على الأقل: المفتاح الحالي + المفتاح الاحتياطي.
- تجديد الشهادة لا يغيّر البصمة **إذا أُعيد استخدام المفتاح نفسه**. مع Let's Encrypt
  استخدم `certbot renew --reuse-key` (أو ما يعادله لدى مزوّدك)؛ وإلا تتغيّر البصمة كل
  90 يوماً.
- عند تدوير المفتاح: أصدر الشهادة الجديدة **بالمفتاح الاحتياطي** (CSR منه)، ثم انشر
  نسخة تطبيق تحمل بصمته + بصمة مفتاح احتياطي جديد، وانتظر وصولها لكل الأجهزة قبل
  التدوير التالي.
- إن كان الخادم خلف CDN أو منصة تدير الشهادات تلقائياً (مثل Vercel أو Cloudflare)
  فقد يتغيّر المفتاح دون علمك: لا تفعّل التثبيت في هذه الحالة.

## حماية البيانات على الجهاز

- القاعدة مشفّرة بـ SQLCipher، ومفتاحها في Android Keystore عبر
  `flutter_secure_storage`.
- الصور والتوقيعات مشفّرة بـ AES-256-GCM بمفتاح منفصل (`lib/core/evidence_cipher.dart`)،
  مقسّمة إلى مقاطع 64KB ليُرفع أي جزء منها دون كتابة نسخة مفكوكة على القرص. بصمة
  SHA-256 المرسلة للخادم هي بصمة الملف الأصلي (غير المشفّر). الملفات القديمة غير
  المشفّرة تُشفَّر تلقائياً عند أول تشغيل لهذه النسخة.
- نسخ `image_picker` المؤقتة تُحذف فور التقاط الصورة وعند كل تشغيل.
- النسخ الاحتياطي السحابي والنقل من جهاز لجهاز مستبعدان كلياً
  (`res/xml/data_extraction_rules.xml` و`backup_rules.xml`).
- إلغاء اعتماد الجهاز من المشرف (403 `DEVICE_REVOKED`) أو تعطيل الحساب
  (`USER_INACTIVE`) يمسح كل بيانات العمل والمفاتيح من الجهاز. تسجيل الخروج مسموح فقط
  حين لا يوجد شيء غير مُزامَن، ويمسح كذلك كل شيء عدا معرّف الجهاز.

## محاذاة الصفحات 16KB (Android 15+)

AGP 8.11 يحاذي المكتبات الأصلية غير المضغوطة على 16KB افتراضياً، وNDK الافتراضي في
Flutter 3.38 هو r28 (يبني بمحاذاة 16KB)، و`sqlcipher-android` 4.10 يدعمها.
`mobile_scanner` يجب أن يبقى على 7.x أو أحدث: الإصدار 5.2.3 كان يجلب مكتبتَي ML Kit
وCameraX (`libbarhopper_v3.so` و`libimage_processing_util_jni.so`) بمحاذاة 4KB، وهذا
يمنع الرفع إلى Google Play.

فحص `zipalign` وحده لا يكفي، لأنه يتحقق من مواضع الملفات داخل الحزمة فقط ونجح رغم مكتبات
ML Kit غير المحاذاة. لذلك افحص أيضاً محاذاة مقاطع `LOAD` في كل مكتبة:

```bash
APK=build/app/outputs/flutter-apk/app-release.apk
zipalign -c -P 16 -v 4 "$APK"

# يجب أن تكون كل قيمة 0x4000 أو أكبر في arm64-v8a وx86_64.
mkdir -p build/so && unzip -q -o "$APK" -d build/so
for f in $(find build/so/lib -name '*.so'); do
  echo "$f: $(llvm-readelf -lW "$f" | awk '$1=="LOAD"{print $NF}' | sort -u | tr '\n' ' ')"
done
```

`llvm-readelf` موجود في NDK تحت `toolchains/llvm/prebuilt/<host>/bin/`. المتطلب يخص
المعماريات 64-bit فقط، لأن أجهزة صفحات 16KB كلها 64-bit؛ لذلك لا يمنع الرفعَ بقاءُ
`armeabi-v7a/libbarhopper_v3.so` على 4KB.
