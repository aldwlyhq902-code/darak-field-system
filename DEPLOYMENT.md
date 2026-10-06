# دليل النشر والتشغيل والاستعادة

مكتوب ليُنفَّذ من جهاز نظيف بيد شخص لم يبنِ النظام. البنية كلها مكتوبة كشيفرة في مجلد [`deploy/`](deploy/):

| الملف | الغرض |
|---|---|
| `deploy/provision-ubuntu.sh` | تجهيز خادم Ubuntu 24.04 من الصفر (قابل لإعادة التشغيل بأمان) |
| `deploy/deploy.sh` | نشر وسم إصدار: نسخة قبل النشر → بناء → preflight → ترحيل → تشغيل |
| `deploy/nginx/darak.conf` | موقع Nginx: HTTPS، HSTS، `client_max_body_size 30m`، منع الملفات المخفية وأي شيء خارج `public/` |
| `deploy/php-fpm/darak.conf` | تجمّع PHP-FPM خاص بمستخدم التطبيق |
| `deploy/cron/darak` | سطر المجدول `schedule:run` وفحص الصحة كل 5 دقائق |
| `deploy/logrotate/darak` | تدوير سجلات Laravel وPHP-FPM |
| `deploy/postgres/app-grants.sql` | صلاحيات دور التطبيق بعد كل ترحيل |
| `deploy/restore-drill.md` | تمرين الاستعادة على خادم جديد مع سجل توقيت (هدف ≤ 4 ساعات) |

---

## 1. متطلبات الخادم

| المكوّن | الحد الأدنى | ملاحظة |
|---|---|---|
| نظام | Ubuntu 24.04 LTS | السكربت مكتوب له |
| PHP | 8.3 مع `pdo_pgsql` · `mbstring` · `gd` · `zip` · `intl` · `bcmath` · `curl` · `xml` · `redis` · `opcache` | `zip` إلزامي للنسخ الاحتياطي |
| PostgreSQL | 16 | على localhost فقط، SSL مفعّل افتراضياً في Ubuntu |
| Redis | 7+ | الكاش والجلسات، بكلمة مرور وعلى localhost |
| Nginx + certbot | — | شهادة Let's Encrypt تتجدد آلياً |
| Node.js | 22.x | لبناء الواجهة أثناء النشر فقط |
| rclone | — | النسخ خارج الخادم |
| موارد | 2 vCPU · 4GB RAM · 40GB SSD | السكربت يضيف 2GB swap |

**الاستضافة داخل السعودية موصى بها** — النظام يحفظ بيانات عملاء وصوراً لمواقعهم. راجع جدول المستلمين والنقل خارج المملكة في `PRIVACY_AND_RETENTION.md`.

---

## 2. أول نشر

### 2.1 تجهيز الخادم

```bash
git clone <repo> /root/darak-kit && cd /root/darak-kit
sudo DARAK_DOMAIN=panel.darak.sa DARAK_ADMIN_EMAIL=ops@darak.sa \
     DARAK_REPO=git@github.com:<owner>/darak.git ./deploy/provision-ubuntu.sh
```

ينفّذ: المنطقة الزمنية Asia/Riyadh، swap، الحزم، Node 22، جدار ufw (22/80/443 فقط)، fail2ban، التحديثات الأمنية التلقائية، Redis بكلمة مرور، مستخدم التطبيق `darak`، مجلد النسخ `/var/backups/darak` (0700)، تجمّع PHP-FPM، Nginx والشهادة، cron وlogrotate، وأدوار قاعدة البيانات أدناه. إعادة تشغيله آمنة: كل خطوة تتحقق قبل أن تغيّر.

> يحتاج مستخدم `darak` مفتاح نشر (deploy key) للقراءة من المستودع إن كان خاصاً.

### 2.2 أدوار قاعدة البيانات — أقل صلاحية

| الدور | من يستخدمه | الصلاحيات |
|---|---|---|
| `darak_owner` | `deploy.sh` للترحيل فقط (`/etc/darak/migrate.env`، root:darak 0640) | مالك قاعدة `darak` والمخطط `public` والجداول |
| `darak_app` | التطبيق والمجدول والنسخ (`DB_USERNAME` في `.env`) | `SELECT/INSERT/UPDATE/DELETE` على الجداول، استخدام التسلسلات، `BYPASSRLS` — **ليس superuser ولا مالكاً** |

لماذا: دور التطبيق لا يستطيع تعطيل مشغلات حماية سجل التدقيق ولا حذف جداول ولا تنفيذ أي DDL، و`app-grants.sql` يسحب منه `UPDATE/DELETE/TRUNCATE` على `audit_logs` تحديداً. `BYPASSRLS` لازم لأن ترحيل عزل Supabase يفعّل RLS على كل الجداول ولا يتجاوزه إلا المالك أو هذا الامتياز. `darak:preflight` **يفشل** إن كان دور الاتصال superuser.

### 2.3 ملف البيئة

بصفة `darak`: `cp backend/.env.example backend/.env && chmod 600 backend/.env`، ثم:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.darak.sa
APP_TIMEZONE=Asia/Riyadh
APP_LOCALE=ar

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=darak
DB_USERNAME=darak_app
DB_PASSWORD=<محتوى /etc/darak/db-app.pass>
DB_SSLMODE=require

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_PASSWORD=<محتوى /etc/darak/redis.pass>
LOG_CHANNEL=stack
LOG_STACK=single              # ملف واحد يدوّره deploy/logrotate/darak
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
SANCTUM_EXPIRATION=43200

MAIL_MAILER=smtp              # log/array يُفشل preflight: استعادة كلمة المرور تحتاج بريداً حقيقياً
MAIL_HOST=<مزود البريد>
MAIL_USERNAME=<...>
MAIL_PASSWORD=<...>
MAIL_FROM_ADDRESS=no-reply@darak.sa

DARAK_INVOICE_PROVIDER=manual # fake يُفشل preflight؛ يُبدَّل لمزود الفوترة عند التعاقد
DARAK_SERVICE_START=07:00:00
DARAK_SERVICE_END=23:00:00
DARAK_MAX_MEDIA_BYTES=26214400
DARAK_BACKUP_PATH=/var/backups/darak
DARAK_BACKUP_PASSWORD=<openssl rand -hex 32 — في مدير الأسرار>
DARAK_AUDIT_HMAC_KEY=<openssl rand -hex 32 — في مدير الأسرار، لا يُدوَّر أبداً>
DARAK_ENABLE_EXPERIMENTAL_ANALYTICS=false

# النسخ خارج الخادم والتدوير (§5)
DARAK_BACKUP_RCLONE_REMOTE=offsite:darak-backups/prod
DARAK_BACKUP_KEEP_DAILY=7
DARAK_BACKUP_KEEP_WEEKLY=4
DARAK_BACKUP_KEEP_MONTHLY=6

# المراقبة (§7)
DARAK_HEARTBEAT_URL=https://hc-ping.com/<uuid-المجدول>
DARAK_BACKUP_HEARTBEAT_URL=https://hc-ping.com/<uuid-النسخ>
LOG_ALERT_WEBHOOK_URL=        # اختياري: Slack/Mattermost، أو Discord مع /slack في آخر الرابط

# الخصوصية — لا تُملأ إلا بالقيم المعتمدة في PRIVACY_AND_RETENTION.md
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
DARAK_RETENTION_PURGE_ENABLED=false   # true فقط بعد مراجعة تقرير dry run مع المستشار
DARAK_MAP_TILES_URL=https://tile.openstreetmap.org/{z}/{x}/{y}.png   # أو none
```

ثم `php artisan key:generate`. **انسخ `APP_KEY` و`DARAK_BACKUP_PASSWORD` و`DARAK_AUDIT_HMAC_KEY` إلى مدير الأسرار فوراً**: بدون الأول لا تُفك الجلسات والحقول المشفرة، وبدون الثاني لا تُفتح أي نسخة، وبدون الثالث لا يمكن إثبات سلامة سجل التدقيق.

> `APP_DEBUG=false` غير قابل للتفاوض في الإنتاج: `true` يكشف مسارات الملفات ومتغيرات البيئة في أي صفحة خطأ.
>
> `TRUSTED_PROXIES` لا يوضع في `.env`: يُقرأ قبل تحميله، ولذلك يضبطه تجمّع PHP-FPM (`env[TRUSTED_PROXIES] = 127.0.0.1,::1` — Nginx على الخادم نفسه). لا تجعله `*` خلف Nginx: أي عميل سيستطيع تزوير عنوانه عبر `X-Forwarded-For`.

مفاتيح تشغيلية إضافية (كلها في `.env.example` تحت `# --- ops ---`):

| المتغير | الافتراضي | المعنى |
|---|---|---|
| `DARAK_RCLONE_PATH` / `DARAK_RCLONE_TIMEOUT_SECONDS` | `rclone` / `3600` | مسار rclone ومهلة النسخ |
| `DARAK_AUDIT_KEEP_FULL_IP` | `false` | `true` يحفظ IP ووكيل المتصفح كاملين في سجل تدقيق لا يُمحى — قرار خصوصية |
| `LOG_ALERT_LEVEL` | `critical` | أدنى مستوى يُرسل إلى قناة التنبيه |
| `DARAK_MAP_ATTRIBUTION` | `© OpenStreetMap contributors` | نص الإسناد تحت الخريطة |
| `DARAK_MODULE_SALES` · `_COMMISSIONS` · `_PROCUREMENT_AUTOMATION` · `_AUTO_REASSIGN` · `_FLEET_HR_ALERTS` | `true` | تجميد المهام المجدولة لوحدة غير أساسية (الشاشات لا تتأثر) |
| `darak.files_disk` (متغير البيئة يحدده فريق البيانات) | `local` | قرص الأدلة؛ إن كان محلياً (driver local) فالنسخ خارج الخادم إلزامي في preflight |

### 2.4 rclone (النسخ خارج الخادم)

بصفة `darak` (الإعداد يُحفظ في `~darak/.config/rclone/rclone.conf`):

```bash
sudo -u darak rclone config        # أنشئ remote باسم offsite نحو حاوية S3-compatible، يفضَّل داخل المملكة
sudo -u darak rclone mkdir offsite:darak-backups/prod
sudo -u darak rclone lsd offsite:darak-backups
```

الأرشيفات مشفرة أصلاً بـAES-256 قبل النقل. فعّل على الحاوية قاعدة دورة حياة تحذف الكائنات الأقدم من `DARAK_RETENTION_BACKUPS_DAYS` (أمر الإتلاف يدير النسخ المحلية فقط)، ويفضَّل تفعيل object lock/versioning كي لا يمحو مخترِق الخادم النسخ البعيدة بمفاتيحه.

### 2.5 النشر

```bash
sudo -u darak /var/www/darak/deploy/deploy.sh v0.3.0
```

يفعل بالترتيب: وضع الصيانة ونسخة احتياطية موثقة قبل النشر → `git checkout` للوسم → `composer install --no-dev --optimize-autoloader` → `npm ci && npm run build` → **`darak:preflight` (إن فشل يعيد الإصدار السابق ويُخرج الموقع من الصيانة دون أي ترحيل)** → `migrate --force` بدور المالك → `app-grants.sql` → كاش الإعداد والمسارات والعروض → إعادة تحميل PHP-FPM → خروج من الصيانة → `darak:revoke-backoffice-tokens` **مرة واحدة فقط** في أول نشر يحوي هذا الأمر (علامة في `/var/lib/darak`). أي فشل بعد بدء الترحيل يُبقي الموقع في الصيانة عمداً: الكود القديم على مخطط جديد أسوأ من التوقف.

**أول حساب في الإنتاج** (بدل بيانات البذر — لا تشغّل `DarakDemoSeeder` في الإنتاج):

```bash
cd /var/www/darak/backend
sudo -u darak php artisan tinker --execute="
App\Models\User::create([
  'name' => 'المالك',
  'email' => 'owner@darak.sa',
  'password' => Illuminate\Support\Facades\Hash::make('<كلمة قوية>'),
  'role' => App\Models\User::ROLE_OWNER,
  'is_active' => true,
]);"
```

### 2.6 `darak:preflight` — حاجز النشر

يعيد كود فشل عند أي `FAIL`؛ و`WARN` لا يمنع النشر لكنه يُراجع.

| يفشل (FAIL) | يحذّر (WARN) |
|---|---|
| البيئة ليست production · `APP_DEBUG` · رابط غير HTTPS · غياب `APP_KEY` · Cookie جلسة غير آمن أو غير مشفر · عمر رمز الجهاز · غير PostgreSQL أو بلا SSL · السجل معطل أو بمستوى debug · نسخ غير مشفرة أو داخل مجلد النشر · منصة مؤقتة (Vercel) · قرارات الخصوصية ومدد الاحتفاظ ناقصة · مزود الفوترة `fake` أو فارغ · البريد `log`/`array` · أدلة على قرص محلي بلا `DARAK_BACKUP_RCLONE_REMOTE` · `pg_dump` غير موجود · rclone غير موجود مع remote مضبوط · دور قاعدة البيانات superuser (أو تعذر التحقق) · غياب `DARAK_AUDIT_HMAC_KEY` أو قِصره عن 32 حرفاً | `APP_TIMEZONE` غير `Asia/Riyadh` · نبض المجدول أقدم من 5 دقائق أو غائب (طبيعي في أول نشر قبل أن يبدأ cron) |

> **Vercel للعرض غير الإنتاجي فقط.** نقطة `api/index.php` ترفض التشغيل إلا عند `DARAK_VERCEL_EPHEMERAL_DEMO=true` مع `APP_ENV` غير production، **وترفض أيضاً إن كان `APP_DEBUG` مفعّلاً**، وتفرض `APP_DEBUG=false` قبل الإقلاع. `.vercelignore` يستبعد ملفات `.env*` والنسخ والأدلة والسجلات وقواعد SQLite. لا تضع بيانات عميل أو أدلة حقيقية هناك.

---

## 3. المجدول

**سطر cron واحد** (`deploy/cron/darak` → `/etc/cron.d/darak`)، وهو الشرط الوحيد لعمل الإشعارات والنسخ والإتلاف:

```bash
* * * * * darak cd /var/www/darak/backend && php artisan schedule:run >> /dev/null 2>&1
```

| المهمة | التوقيت | الشرط |
|---|---|---|
| `darak:heartbeat` (نبض + ping خارجي كل 5 دقائق) | كل دقيقة | — |
| `darak:sla-sweep` | كل 10 دقائق | — |
| `darak:notifications-run` | كل 5 دقائق | — |
| `darak:reassign-unavailable` | كل 15 دقيقة | `DARAK_MODULE_AUTO_REASSIGN` |
| `darak:sales-alerts` (ومعه Web Push) | كل ساعة | `DARAK_MODULE_SALES` |
| `darak:backup` (تحقق → خارج الخادم → تدوير → نبض) | 02:30 | — |
| `darak:fault-model-train` | الاثنين 03:30 | `DARAK_ENABLE_EXPERIMENTAL_ANALYTICS=true` فقط |
| `darak:retention-purge --execute` | 04:15 | `DARAK_RETENTION_PURGE_ENABLED=true` فقط |
| `model:prune` | 04:45 | — |
| `darak:maintenance-generate` | 05:00 | — |
| `darak:replenishment-scan` | 06:00 | `DARAK_MODULE_PROCUREMENT_AUTOMATION` |
| `darak:compliance-alerts` (وثائق الموظفين والمركبات) | 07:00 | `DARAK_MODULE_FLEET_HR_ALERTS` |
| `darak:renewal-offers --days=45` | 07:30 | — |
| `darak:commercial-alerts` | 08:00 | — |
| `darak:commissions-generate` | 23:30 | `DARAK_MODULE_COMMISSIONS` |

أعلام الوحدات افتراضها `true` (لا تغيير في السلوك)؛ `false` يوقف المهمة المجدولة فقط. بعد تغيير أي علم: `php artisan config:cache`. `php artisan schedule:list` يعرض الجدول.

### لا يوجد عامل طوابير — عمداً

النسخة الحالية **لا ترسل أي مهمة إلى طابور Laravel**. الإشعارات تعمل بنمط outbox: تُكتب في قاعدة البيانات مع الحدث الذي سبّبها، ويصرّفها `darak:notifications-run` من المجدول. **إن لم تصل رسالة، افحص الـcron وصفحة الإشعارات، لا العامل.** `QUEUE_CONNECTION=redis` احتياط لما بعد، وRedis مستخدم للكاش والجلسات.

---

## 4. Nginx وPHP-FPM

القالبان في `deploy/nginx/darak.conf` و`deploy/php-fpm/darak.conf` ويثبّتهما سكربت التجهيز. أهم ما فيهما:

- جذر الويب `backend/public` فقط، و`index.php` هو نقطة PHP الوحيدة؛ أي ملف `.php` آخر يعيد 404.
- منع الملفات المخفية (`.env`, `.git`) وأي `sql/zip/log/sqlite/...` حتى لو وُضع في `public/` خطأً.
- TLS 1.2/1.3، HSTS سنة كاملة، `server_tokens off`، حماية httpoxy.
- `client_max_body_size 30m` (أكبر دليل 25MB مع هامش).
- التجمّع يعمل بمستخدم `darak` لا `www-data`، فلا يقرأ `.env` غيره.

---

## 5. النسخ الاحتياطي

### يومياً (آلي، 02:30)

`darak:backup` ينتج أرشيفاً في `DARAK_BACKUP_PATH` يحوي دمب PostgreSQL (`pg_dump` يُشغَّل من الأمر نفسه) والأدلة و`manifest.json` بعدد صفوف كل جدول وبصمة sha256 لكل ملف، ثم:

1. **يتحقق** من الأرشيف فوراً؛ أي فرق = فشل.
2. **ينسخه خارج الخادم** بـ`rclone copy` إن ضُبط `DARAK_BACKUP_RCLONE_REMOTE` (أمر بمصفوفة وسائط لا عبر shell، ويُفحص كود الخروج). الفشل **لا يحذف** النسخة المحلية.
3. **يدوّر** النسخ المحلية: أحدث نسخة لكل يوم من آخر 7 أيام، ولكل أسبوع من آخر 4، ولكل شهر من آخر 6 (قابلة للضبط). الفترات بلا نسخ لا تستهلك خانة، و**النسخة التي أُنشئت وتُحقق منها للتو وأحدث نسخة لا تُحذفان أبداً**.
4. **يرسل نبض النسخ** إلى `DARAK_BACKUP_HEARTBEAT_URL` — فقط إن نجحت كل الخطوات السابقة.

أي فشل (إنشاء، تحقق، نسخ خارجي، تدوير) يكتب سطراً `CRITICAL` (يصل قناة التنبيه إن ضُبطت) **وإشعاراً داخل التطبيق لكل مالك نشط**، ويعيد كود فشل.

```bash
sudo -u darak pg_dump --version     # يجب أن ينجح (preflight يتحقق أيضاً)
sudo -u darak php artisan darak:backup --label=manual
sudo -u darak php artisan darak:backup --no-offsite --no-rotate   # عند الحاجة فقط
```

إن تعذر الدمب **يرفض الأمر كتابة الأرشيف أصلاً**: نسخة تحوي الصور بلا قاعدة بيانات أسوأ من عدم وجود نسخة، لأنك تثق بها.

**نسخة على نفس الخادم ليست نسخة احتياطية.** فقدان القرص يفقد الاثنين معاً — لذلك يفشل preflight إن كانت الأدلة على قرص محلي بلا remote.

---

## 6. تمرين الاستعادة

الهدف **RTO 4 ساعات · RPO 24 ساعة**. لا تعتمد نسخة لم تُستعد. الإجراء الكامل خطوة بخطوة على خادم جديد مع جدول توقيت للتسجيل: [`deploy/restore-drill.md`](deploy/restore-drill.md). نفّذه كل ثلاثة أشهر على الأقل.

تحقق سريع بلا كتابة على أي أرشيف:

```bash
sudo -u darak php artisan darak:restore /var/backups/darak/darak-backup-YYYYMMDD-HHMMSS.zip --verify-only
```

يوجد كذلك workflow شهري باسم **Backup restore drill** يختبر الآلية (نسخة مشفرة → استعادة في PostgreSQL نظيف → مقارنة أعداد الصفوف)، لكنه لا يغني عن تمرين البنية الإنتاجية وتخزينها الخارجي ومفاتيحها.

---

## 7. المراقبة والتنبيه

النسخة الأساسية لا تتطلب خدمة مدفوعة.

### نبض خارجي (dead man's switch)

الخادم الميت لا يبلغ عن موته؛ الخدمة الخارجية تنبّه حين **تتوقف** النبضات.

1. في [healthchecks.io](https://healthchecks.io) (أو UptimeRobot → Heartbeat monitor) أنشئ فحصين:
   - **المجدول**: period 5 دقائق، grace 10 دقائق → `DARAK_HEARTBEAT_URL`.
   - **النسخ**: period يوم واحد، grace ساعتان → `DARAK_BACKUP_HEARTBEAT_URL`.
2. اربط قناة إشعار (بريد/SMS/تطبيق) بجوال المالك.
3. `php artisan config:cache`، ثم تأكد من وصول أول نبض خلال 5 دقائق.

فشل الـping يُسجَّل تحذيراً ولا يوقف المجدول أبداً. الرابطان سرّيان ولا يُكتبان في السجل.

### تنبيه الأخطاء الحرجة

`LOG_ALERT_WEBHOOK_URL` (Slack incoming webhook، أو Mattermost، أو Discord بإضافة `/slack` لآخر رابط الـwebhook) يضم قناة `alert_webhook` إلى `stack` تلقائياً — لذلك يجب `LOG_CHANNEL=stack`. يُرسل نوع الخطأ ونصه المختصر ومعرّف الطلب فقط (بلا سياق، وبلا قيم SQL)، بمهلة ثوانٍ، ومرة واحدة لكل رسالة كل 10 دقائق. Slack/Discord نقل خارج المملكة — راجع `PRIVACY_AND_RETENTION.md`.

### الفحص المحلي

`deploy/cron/darak` يشغّل `darak:health --json` كل 5 دقائق ويكتب في syslog عند الفشل. يتحقق من قاعدة البيانات، **وعمر نبض المجدول بالثواني (`age_seconds`)**، وقابلية الكتابة، والمساحة، وعمر آخر نسخة، ونتيجة آخر نسخ خارجي (إن ضُبط). لا يعرض endpoint عاماً.

كل استجابة تحمل `X-Request-ID`، ويُسجَّل المعرّف نفسه مع الاستثناء.

راقب أسبوعياً:

| المؤشر | أين | ماذا يعني الشذوذ |
|---|---|---|
| إشعارات `ops.alert` | لوحة الإشعارات | فشل نسخ أو نسخ خارجي أو تدوير أو إتلاف |
| الرسائل الميتة | لوحة الإشعارات | إشعارات لا تصل أحداً |
| طابور إشعارات ينمو ولا ينقص | لوحة الإشعارات | **المجدول متوقف** — افحص الـcron أولاً |
| رفع أدلة عالق `failed` | جدول `pending_media` | زيارات لا تُقفل لأن أدلتها لم تصل |
| أجهزة بلا مزامنة >3 ساعات | لوحة اليوم | فني خارج التغطية أو التطبيق مغلق |
| انحراف ساعة >120 ثانية | لوحة اليوم | ساعة جهاز عُدّلت |
| فروق الجرد | المخزون | فقد مخزون |
| فشل `darak:health` | syslog | قاعدة/مجدول/قرص/نسخة احتياطية غير سليمة |
| `darak:audit-verify` | شهرياً يدوياً | أي كسر في سلسلة التدقيق = حادثة أمنية |

### عزل Supabase

الترحيل الأمني يفعّل RLS على جميع جداول `public` ويلغي صلاحيات `anon` و`authenticated`. على الخادم الإنتاجي يتجاوز `darak_app` الـRLS بامتياز `BYPASSRLS` ويبقى غير مالك. لا تمنح أدوار Supabase صلاحيات مباشرة لاحقاً. بعد كل ترحيل يضيف جدولاً جديداً يشترط CI أن يكون RLS مفعلاً عليه.

### Runbook للحوادث

1. سجّل وقت البلاغ و`X-Request-ID` وحساب/شركة المستخدم من دون نسخ كلمات مرور أو رموز.
2. شغّل `php artisan darak:health --json` واحفظ الناتج، ثم افحص سجل Laravel وNginx حول المعرّف.
3. عند اشتباه اختراق: عطّل الحساب المتأثر، ألغِ أجهزته ورموزه، ودوّر الأسرار المتأثرة من مدير الأسرار (عدا `DARAK_AUDIT_HMAC_KEY` الذي يجب أن يبقى ثابتاً). لا تحذف سجل التدقيق، وشغّل `darak:audit-verify`.
4. عند توقف المجدول: أصلح cron ثم نفّذ `php artisan darak:heartbeat` وراقب تصريف outbox؛ لا تعِد إرسال الرسائل الميتة جماعياً قبل معرفة السبب.
5. عند فقد بيانات: أوقف الكتابة، خذ snapshot للحالة المتضررة، واختر آخر نسخة سليمة. نفّذ الاستعادة على قاعدة جديدة فقط (`deploy/restore-drill.md`) ثم بدّل الاتصال بعد التحقق.
6. وثّق السبب الجذري، نطاق العملاء، الخط الزمني، وإجراء منع التكرار. الإشعار النظامي للمتضررين يقرره مسؤول الخصوصية وفق السياسة المعتمدة.

---

## 8. الأمن قبل الإطلاق

- [ ] `APP_DEBUG=false` و`APP_ENV=production`
- [ ] نجاح `php artisan darak:preflight` بلا أي `FAIL`، ومراجعة كل `WARN`
- [ ] دور قاعدة البيانات `darak_app` (ليس superuser ولا مالكاً)، والترحيل بـ`darak_owner` فقط
- [ ] كلمات مرور البذر مُبدَّلة، وحسابات `*@darak.test` محذوفة
- [ ] HTTPS إجباري وشهادة تتجدد آلياً (`certbot renew --dry-run`)
- [ ] PostgreSQL وRedis على localhost فقط، وRedis بكلمة مرور؛ ufw يسمح بـ22/80/443 فقط
- [ ] `storage/` و`.env` غير قابلين للوصول عبر الويب (`curl -I https://<domain>/.env` → 404)
- [ ] النسخ مشفرة بـAES-256، تُنسخ خارج الخادم إلى حاوية منفصلة بقاعدة دورة حياة، ومفتاحها و`DARAK_AUDIT_HMAC_KEY` في مدير أسرار
- [ ] نبض المجدول ونبض النسخ يصلان إلى خدمة المراقبة، وقناة الإشعار مربوطة بجوال المالك
- [ ] تمرين استعادة كامل مسجّل وفق `deploy/restore-drill.md`
- [ ] المستودع وكل الخدمات والمفاتيح **بحساب المالك**
- [ ] لا أسرار في `.env.example` ولا في المستودع
- [ ] اعتماد جدول الخصوصية والاحتفاظ ونص الإشعار في `PRIVACY_AND_RETENTION.md`، ومراجعة تقرير `darak:retention-purge` قبل تفعيل الإتلاف
- [ ] تنفيذ دورة هاتف حقيقية كاملة: كاميرا + QR + توقيع + موقع + وضع طيران + عودة الشبكة
- [ ] تثبيت 0.2.0 فوق نسخة تحمل قاعدة قديمة، والتحقق ميدانياً من بقاء الزيارات والطابور بعد ترحيل SQLCipher
- [ ] بناء APK/AAB من نفس commit المنشور والتحقق من بصمة توقيعهما وSHA-256
- [ ] نجاح CI على commit الإصدار ووسمه بإصدار غير قابل للتغيير

---

## 9. تحديث نسخة

```bash
sudo -u darak /var/www/darak/deploy/deploy.sh <الوسم الجديد>
```

السكربت يأخذ نسخة موثقة قبل أي تغيير (`--label=predeploy-<tag>`)، ويرفض النشر فوق ملفات معدّلة على الخادم، ويعيد الإصدار السابق تلقائياً إن فشل البناء أو preflight قبل الترحيل.

**التراجع بعد الترحيل:** لا تشغّل الكود القديم على مخطط جديد. أصلح للأمام، أو استعد نسخة `predeploy` على قاعدة جديدة. الهجرات العكسية موجودة لكن **لا تُشغَّل على الإنتاج قبل نسخة احتياطية مؤكدة** — تراجع هجرة يمكن أن يحذف عموداً فيه بيانات.

### ملكية الشركة للسجلات المشتركة سابقاً (هجرات `2026_10_07_*`)

أصبحت سياسات العمولة، ودليل مقاولي الباطن، ودليل الموردين وأسعارهم، وقاعدة المعرفة، وبلاغات فشل القطع، ونموذج التنبؤ بالأعطال، تابعة لشركة واحدة (`operating_company_id`).

- **منشأة بشركة واحدة:** تُسند كل الصفوف القديمة إليها تلقائياً، ولا يتغير شيء في التشغيل.
- **أكثر من شركة:** يُسند الصف القديم حين تدل عليه بيانات شركة واحدة فقط (أوامر الشريك، أو مشتريات المورد، أو كاتب المقال). ما بقي بلا شركة يصبح «افتراضياً للمنصة»: لا يراه إلا مشرف المنصة، ولا تولّد سياسة العمولة بلا شركة أي عمولة. على مشرف المنصة إعادة إدخال السياسات والشركاء والموردين المشتركين لكل شركة. نماذج التنبؤ القديمة تُعطَّل لأنها تعلّمت من بيانات عدة شركات؛ يدرّب التشغيل الليلي نموذجاً لكل شركة.
- **أوزان وأهداف الأداء:** تبقى الصفوف الحالية افتراضيات المنصة؛ أول حفظ من مالك شركة ينشئ نسخة خاصة بشركته فقط.
- **أرقام الموظفين وأرقام الفواتير اليدوية:** فريدة داخل الشركة فقط.
- **كتالوج القطع** يبقى مشتركاً عمداً بين الشركات، بما فيه تكلفة الشراء وسعر البيع المسجلان عليه.

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
