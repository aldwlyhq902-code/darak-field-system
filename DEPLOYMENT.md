# دليل النشر والتشغيل والاستعادة

مكتوب ليُنفَّذ من جهاز نظيف بيد شخص لم يبنِ النظام.

---

## 1. متطلبات الخادم

| المكوّن | الحد الأدنى | ملاحظة |
|---|---|---|
| نظام | Ubuntu 22.04+ | |
| PHP | 8.3 مع `pdo_pgsql` · `mbstring` · `gd` · `zip` · `intl` · `curl` · `fileinfo` | `zip` إلزامي للنسخ الاحتياطي |
| PostgreSQL | 16+ | |
| Redis | 7+ | الطوابير والقفل |
| Nginx | — | |
| موارد | 2 vCPU · 4GB RAM · 40GB SSD | يكفي لسيارتين بمساحة نمو |

**الاستضافة داخل السعودية موصى بها** — النظام يحفظ بيانات عملاء وصوراً لمواقعهم.

---

## 2. أول نشر

```bash
git clone <repo> /var/www/darak && cd /var/www/darak/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

عدّل `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.darak.sa
APP_TIMEZONE=Asia/Riyadh
APP_LOCALE=ar

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=darak
DB_USERNAME=darak
DB_PASSWORD=<كلمة قوية>
DB_SSLMODE=require

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
LOG_CHANNEL=daily
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
SANCTUM_EXPIRATION=43200

DARAK_INVOICE_PROVIDER=fake       # يُبدَّل عند التعاقد مع مزود الفوترة
DARAK_SERVICE_START=07:00:00
DARAK_SERVICE_END=23:00:00
DARAK_MAX_MEDIA_BYTES=26214400
DARAK_BACKUP_PATH=/var/backups/darak
DARAK_BACKUP_PASSWORD=<سر عشوائي طويل محفوظ في مدير الأسرار>
DARAK_ENABLE_EXPERIMENTAL_ANALYTICS=false # يبقى مغلقاً حتى اعتماد الميزات التجريبية

# لا تُملأ إلا بالقيم المعتمدة في PRIVACY_AND_RETENTION.md
DARAK_PRIVACY_CONTROLLER_NAME=<الاسم النظامي المعتمد>
DARAK_PRIVACY_REQUEST_CHANNEL=<القناة المعتمدة>
DARAK_PRIVACY_PROVIDERS_REGISTER=<مرجع سجل المزودين المعتمد>
DARAK_PRIVACY_REQUEST_PROCEDURE=<مرجع الإجراء المعتمد>
DARAK_PRIVACY_NOTICE_VERSION=<إصدار الإشعار المعتمد>
DARAK_PRIVACY_EMERGENCY_CONSENT_VERSION=<إصدار موافقة بلاغ QR>
DARAK_RETENTION_VISIT_PHOTOS_DAYS=<أيام>
DARAK_RETENTION_EMERGENCY_REPORTS_DAYS=<أيام>
DARAK_RETENTION_SIGNATURES_REPORTS_DAYS=<أيام>
DARAK_RETENTION_CAPTURE_COORDINATES_DAYS=<أيام>
DARAK_RETENTION_AUDIT_LOGS_DAYS=<أيام>
DARAK_RETENTION_BACKUPS_DAYS=<أيام>
```

> `APP_DEBUG=false` غير قابل للتفاوض في الإنتاج: `true` يكشف مسارات الملفات ومتغيرات البيئة في أي صفحة خطأ.

```bash
php artisan migrate --force
php artisan db:seed --class=DarakDemoSeeder   # بيانات تجريبية فقط — تجاوزه في الإنتاج
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
mkdir -p /var/backups/darak && chown www-data:www-data /var/backups/darak
php artisan darak:preflight
```

`darak:preflight` حاجز نشر إلزامي: يعيد كود فشل إذا كانت البيئة أو HTTPS أو Cookie الجلسة أو عمر الرموز أو PostgreSQL أو تشفير/مسار النسخ الاحتياطي غير آمن، أو كانت قرارات الخصوصية ومدد الاحتفاظ ناقصة، أو كانت المنصة ذات قرص مؤقت مثل Vercel.

> **Vercel للعرض غير الإنتاجي فقط.** نقطة `api/index.php` ترفض التشغيل افتراضياً، ولا تعمل إلا عند `DARAK_VERCEL_EPHEMERAL_DEMO=true` مع `APP_ENV` غير production. لا تضع بيانات عميل أو أدلة حقيقية هناك؛ `/tmp` مؤقت ولا يضمن بقاء أجزاء الرفع أو الصور.

**أول حساب في الإنتاج** (بدل بيانات البذر):

```bash
php artisan tinker --execute="
App\Models\User::create([
  'name' => 'المالك',
  'email' => 'owner@darak.sa',
  'password' => Illuminate\Support\Facades\Hash::make('<كلمة قوية>'),
  'role' => App\Models\User::ROLE_OWNER,
  'is_active' => true,
]);"
```

---

## 3. المجدول

**سطر cron واحد، وهو الشرط الوحيد لعمل الإشعارات والنسخ الاحتياطي:**

```bash
* * * * * cd /var/www/darak/backend && php artisan schedule:run >> /dev/null 2>&1
```

يشغّل: كنس SLA كل 10 دقائق · تصريف طابور الإشعارات كل 5 دقائق · نسخة احتياطية يومياً 2:30 صباحاً.

### لا يوجد عامل طوابير — عمداً

النسخة الحالية **لا ترسل أي مهمة إلى طابور Laravel**. الإشعارات تعمل بنمط outbox: تُكتب في قاعدة البيانات مع الحدث الذي سبّبها، ويصرّفها أمر `darak:notifications-run` من المجدول.

كان هذا الدليل يفرض عامل `queue:work` على Redis — وهو ما كان سيرسل من يشخّص إشعاراً متأخراً إلى مكان لا علاقة له بالمشكلة. **إن لم تصل رسالة، افحص الـcron وصفحة الإشعارات، لا العامل.**

`QUEUE_CONNECTION=redis` في `.env` احتياط لما بعد، وRedis نفسه مستخدم للكاش والجلسات فأبقِه.

### نبض المجدول والفحص التشغيلي

يسجل المجدول نبضاً كل دقيقة. نفّذ الفحص التالي من مراقب خارجي أو cron منفصل كل خمس دقائق:

```bash
cd /var/www/darak/backend
php artisan darak:health --json || logger -p auth.crit -t darak-health "Darak operational health failed"
```

يتحقق الأمر من اتصال قاعدة البيانات، وحداثة نبض المجدول، وقابلية كتابة مسارات التشغيل، والمساحة الحرة، ووجود نسخة احتياطية ضمن RPO مع مهلة ساعتين. لا يعرض endpoint عاماً ولا يكشف تفاصيل البنية للإنترنت.

---

## 4. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name panel.darak.sa;
    root /var/www/darak/backend/public;

    ssl_certificate     /etc/letsencrypt/live/panel.darak.sa/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/panel.darak.sa/privkey.pem;

    index index.php;
    charset utf-8;

    # رفع الأدلة يصل 8MB للقطعة الواحدة
    client_max_body_size 12M;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {
    listen 80;
    server_name panel.darak.sa;
    return 301 https://$host$request_uri;
}
```

---

## 5. النسخ الاحتياطي

### يومياً (آلي)

```bash
php artisan darak:backup
```

ينتج أرشيفاً في `DARAK_BACKUP_PATH` يحوي قاعدة البيانات والأدلة و`manifest.json` بعدد صفوف كل جدول وبصمة sha256 لكل ملف — **ويتحقق من نفسه فور الإنشاء**. إن فشل التحقق يرجع بكود فشل، فيظهر في سجل cron.

### PostgreSQL

`darak:backup` **ينفّذ `pg_dump` بنفسه** ويضع الناتج داخل الأرشيف بمساره `database/dump.sql` مع بصمته. لا خطوة يدوية منفصلة.

**شرط واحد:** `pg_dump` على `PATH` للمستخدم الذي يشغّل الـcron:

```bash
sudo -u www-data pg_dump --version   # يجب أن ينجح
```

إن تعذّر الدمب **يرفض الأمر كتابة الأرشيف أصلاً** ويرجع بكود فشل. هذا مقصود: نسخة تحوي الصور بلا قاعدة بيانات أسوأ من عدم وجود نسخة، لأنك تثق بها.

> كانت النسخة السابقة من هذا الدليل تطلب `pg_dump` يدوياً في مهمة منفصلة، والكود يعلن مدخلاً للقاعدة **ولا يضيفه**. النتيجة: أرشيفات ليلية تطبع «مطابقة كاملة» وهي فارغة من كل صف.

### النقل خارج الخادم

```bash
rclone copy /var/backups/darak remote:darak-backups --max-age 48h
```

**نسخة على نفس الخادم ليست نسخة احتياطية.** فقدان القرص يفقد الاثنين معاً.

---

## 6. تمرين الاستعادة — شهرياً وموثقاً

الهدف **RTO 4 ساعات · RPO 24 ساعة**. لا تعتمد نسخة لم تُستعد.

**١. تحقق بلا كتابة:**

```bash
php artisan darak:restore /var/backups/darak/darak-backup-YYYYMMDD-HHMMSS.zip --verify-only
```

يقارن عدد الصفوف وبصمات الملفات ويطبع أي فرق.

**٢. استعادة كاملة على بيئة نظيفة** (لا على الإنتاج):

```bash
# الاستعادة تستخرج الأدلة وتكتب دمب القاعدة بجوار الأرشيف بامتداد .sql
DB_DATABASE=darak_restore_test php artisan darak:restore <archive> --force

createdb -U darak darak_restore_test
psql -U darak -d darak_restore_test -f /var/backups/darak/<archive-name>.sql

DB_DATABASE=darak_restore_test php artisan darak:restore <archive> --verify-only
```

الأمر **يرفض** أرشيفاً يعلن قاعدة ولا يحويها، بدل الإبلاغ عن استعادة ناجحة.

**٣. سجّل النتيجة:** التاريخ، زمن الاستعادة، عدد الصفوف، وأي فرق. تمرين بلا سجل لم يحدث.

يوجد كذلك workflow شهري باسم **Backup restore drill** يمكن تشغيله يدوياً. ينشئ قاعدة مصدر، يأخذ نسخة مشفرة، يستعيد SQL في قاعدة PostgreSQL نظيفة، ثم يقارن أعداد الصفوف ويفشل إذا تجاوز RTO أربع ساعات. هذا يختبر الآلية باستمرار، لكنه لا يغني عن تمرين البنية الإنتاجية وتخزينها الخارجي.

---

## 7. المراقبة

النسخة الأساسية لا تتطلب خدمة مدفوعة. اضبط `LOG_CHANNEL=daily` و`LOG_LEVEL=warning`، واجمع `storage/logs/laravel-*.log` بأداة الخادم المتاحة. كل استجابة تحمل `X-Request-ID`، ويُسجّل المعرّف نفسه مع الاستثناء لتتبع الطلب من بلاغ المستخدم إلى السجل.

يمكن إضافة Sentry أو مزود مشابه لاحقاً، لكنه ليس شرطاً لسلامة الإطلاق ولا تُحفظ مفاتيحه في المستودع.

راقب أسبوعياً:

| المؤشر | أين | ماذا يعني الشذوذ |
|---|---|---|
| الرسائل الميتة | لوحة الإشعارات | إشعارات لا تصل أحداً |
| طابور إشعارات ينمو ولا ينقص | لوحة الإشعارات | **المجدول متوقف** — افحص الـcron أولاً |
| رفع أدلة عالق `failed` | جدول `pending_media` | زيارات لا تُقفل لأن أدلتها لم تصل |
| أجهزة بلا مزامنة >3 ساعات | لوحة اليوم | فني خارج التغطية أو التطبيق مغلق |
| انحراف ساعة >120 ثانية | لوحة اليوم | ساعة جهاز عُدّلت |
| رفع عالق `uploading` | جدول `media_files` | أدلة لم تصل الخادم |
| فروق الجرد | المخزون | فقد مخزون |
| فشل `darak:health` | cron/syslog | قاعدة/مجدول/قرص/نسخة احتياطية غير سليمة |

### عزل Supabase

الترحيل الأمني الأخير يفعّل RLS على جميع جداول `public` الحالية ويلغي كل صلاحيات `anon` و`authenticated` على الجداول والتسلسلات والدوال وكذلك الصلاحيات الافتراضية. Laravel يستمر عبر مستخدم قاعدة البيانات مالك الجداول، لذلك لا تعتمد الواجهة على JWT أو Data API. لا تمنح أدوار Supabase صلاحيات مباشرة لاحقاً؛ إن احتجت API مباشراً فذلك تصميم أمني مستقل بسياسات tenant واختبارات تسريب قبل الإنتاج.

بعد كل ترحيل يضيف جدولاً جديداً، يشترط CI أن يكون RLS مفعلاً عليه. أضف `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` في نفس الترحيل أو حدّث ترحيل العزل قبل الإصدار.

### Runbook للحوادث

1. سجّل وقت البلاغ و`X-Request-ID` وحساب/شركة المستخدم من دون نسخ كلمات مرور أو رموز.
2. شغّل `php artisan darak:health --json` واحفظ الناتج، ثم افحص سجل Laravel وNginx حول المعرّف.
3. عند اشتباه اختراق: عطّل الحساب المتأثر، ألغِ أجهزته ورموزه، ودوّر الأسرار المتأثرة من مدير الأسرار. لا تحذف سجل التدقيق.
4. عند توقف المجدول: أصلح cron ثم نفّذ `php artisan darak:heartbeat` وراقب تصريف outbox؛ لا تعِد إرسال الرسائل الميتة جماعياً قبل معرفة السبب.
5. عند فقد بيانات: أوقف الكتابة، خذ snapshot للحالة المتضررة، واختر آخر نسخة سليمة. نفّذ الاستعادة على قاعدة جديدة فقط ثم بدّل الاتصال بعد التحقق.
6. وثّق السبب الجذري، نطاق العملاء، الخط الزمني، وإجراء منع التكرار. الإشعار النظامي للمتضررين يقرره مسؤول الخصوصية وفق السياسة المعتمدة.

---

## 8. الأمن قبل الإطلاق

- [ ] `APP_DEBUG=false` و`APP_ENV=production`
- [ ] نجاح `php artisan darak:preflight` بلا أي `FAIL`
- [ ] كلمات مرور البذر مُبدَّلة، وحسابات `*@darak.test` محذوفة
- [ ] HTTPS إجباري وشهادة تتجدد آلياً
- [ ] PostgreSQL لا يستمع على واجهة عامة
- [ ] Redis بكلمة مرور وعلى localhost
- [ ] `storage/` و`.env` غير قابلين للوصول عبر الويب
- [ ] النسخ الاحتياطية مشفرة بـAES-256 وبحساب تخزين منفصل عن الخادم، ومفتاحها في مدير أسرار
- [ ] المستودع وكل الخدمات والمفاتيح **بحساب المالك**
- [ ] لا أسرار في `.env.example` ولا في المستودع
- [ ] اعتماد جدول الخصوصية والاحتفاظ ونص الإشعار في `PRIVACY_AND_RETENTION.md`
- [ ] تنفيذ دورة هاتف حقيقية كاملة: كاميرا + QR + توقيع + موقع + وضع طيران + عودة الشبكة
- [ ] تثبيت 0.2.0 فوق نسخة تحمل قاعدة قديمة، والتحقق ميدانياً من بقاء الزيارات والطابور بعد ترحيل SQLCipher
- [ ] بناء APK/AAB من نفس commit المنشور والتحقق من بصمة توقيعهما وSHA-256
- [ ] نجاح CI على commit الإصدار ووسمه بإصدار غير قابل للتغيير

---

## 9. تحديث نسخة

```bash
cd /var/www/darak/backend
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
systemctl restart php8.3-fpm
php artisan up
```

**التراجع:** `git checkout <الوسم السابق>` ثم نفس الخطوات. الهجرات العكسية موجودة لكن **لا تُشغَّل على الإنتاج قبل نسخة احتياطية مؤكدة** — تراجع هجرة يمكن أن يحذف عموداً فيه بيانات.

---

## 10. تطبيق الفني

```bash
cd mobile
flutter build apk --release --dart-define=DARAK_API=https://panel.darak.sa
flutter build appbundle --release --dart-define=DARAK_API=https://panel.darak.sa
```

بناء release **يفشل** إذا غاب `mobile/android/key.properties` أو أي مفتاح مطلوب أو ملف keystore؛ لا توجد عودة تلقائية إلى توقيع debug. احتفظ بالـkeystore وكلمات مروره في مدير أسرار وخزنة مستقلة، لأن فقده يمنع تحديث النسخة المثبتة.

يُوزَّع APK مباشرة على أجهزة الشركة، أو AAB عبر قناة Play داخلية. لا تُنقل حزمة
مبنية لبيئة اختبار إلى الإنتاج: عنوان API جزء من البناء.

**قبل التوزيع اختبر على جهاز حقيقي:** الكاميرا، التوقيع، مسح QR، أذونات الموقع، ودورة زيارة كاملة في وضع الطيران ثم مزامنة. **هذا لم يُختبر على جهاز بعد.**
