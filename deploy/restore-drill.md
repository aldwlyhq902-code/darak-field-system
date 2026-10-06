# تمرين الاستعادة على خادم جديد

الهدف: إعادة النظام كاملاً من آخر نسخة خارج الخادم إلى خادم Ubuntu 24.04 فارغ خلال **4 ساعات أو أقل (RTO)**، بخسارة بيانات لا تتجاوز **24 ساعة (RPO)**. يُنفَّذ كل ثلاثة أشهر على الأقل، وبعد أي تغيير في البنية أو مزود التخزين، ويُسجَّل في جدول التوقيت أدناه. تمرين بلا سجل لم يحدث.

> هذا التمرين يختبر البنية الإنتاجية والتخزين الخارجي والأسرار. workflow «Backup restore drill» في GitHub يختبر الآلية فقط ولا يغني عنه.

## قبل البدء (تحقق — لا تبدأ العدّاد قبل اكتمالها)

- [ ] الوصول إلى مدير الأسرار وفيه: `DARAK_BACKUP_PASSWORD` · `DARAK_AUDIT_HMAC_KEY` · `APP_KEY` · نسخة من `.env` الإنتاجي.
- [ ] إعداد rclone للوصول إلى حاوية النسخ (`rclone.conf` أو مفاتيح الحاوية) محفوظ في مدير الأسرار.
- [ ] مفتاح نشر (deploy key) يسمح بقراءة المستودع.
- [ ] وسم الإصدار المنشور وقت النسخة (من سجل النشر أو `git describe` على الإنتاج).
- [ ] خادم Ubuntu 24.04 جديد (2 vCPU · 4GB · 40GB) ونطاق تجريبي يشير إليه، مثل `restore-test.example.sa`. **لا تستعد فوق الإنتاج.**

## الخطوات

### 1. تجهيز الخادم (~20 دقيقة)

```bash
git clone <repo> /root/darak-kit && cd /root/darak-kit
sudo DARAK_DOMAIN=restore-test.example.sa DARAK_ADMIN_EMAIL=ops@example.sa \
     DARAK_REPO=<repo> ./deploy/provision-ubuntu.sh
```

### 2. جلب آخر نسخة من خارج الخادم (~10–30 دقيقة حسب الحجم)

```bash
sudo -u darak mkdir -p ~darak/.config/rclone   # ثم ضع rclone.conf من مدير الأسرار
sudo -u darak rclone lsf --format tsp offsite:darak-backups/prod | sort | tail -5
sudo -u darak rclone copy offsite:darak-backups/prod/darak-backup-YYYYMMDD-HHMMSS.zip /var/backups/darak/
```

سجّل وقت النسخة (من اسم الملف) — الفرق بينه وبين لحظة «الحادثة» هو RPO الفعلي.

### 3. الكود والإعداد (~15 دقيقة)

```bash
cd /var/www/darak
sudo -u darak git checkout --detach <وسم الإصدار وقت النسخة>
sudo -u darak cp /path/from/secrets/.env backend/.env && sudo chmod 600 backend/.env
# عدّل في .env: APP_URL للنطاق التجريبي، DB_USERNAME=darak_app، DB_PASSWORD=$(cat /etc/darak/db-app.pass)،
# REDIS_PASSWORD=$(cat /etc/darak/redis.pass). أبقِ APP_KEY وDARAK_BACKUP_PASSWORD وDARAK_AUDIT_HMAC_KEY كما هي.
# عطّل أي إرسال خارجي أثناء التمرين: MAIL_MAILER=log مؤقتاً، وأفرغ DARAK_HEARTBEAT_URL وLOG_ALERT_WEBHOOK_URL.
cd backend
sudo -u darak composer install --no-dev --optimize-autoloader --no-interaction
sudo -u darak bash -c 'npm ci --no-audit --no-fund && npm run build'
```

### 4. استخراج الأدلة وملف SQL (~10–20 دقيقة)

```bash
sudo -u darak php artisan darak:restore /var/backups/darak/darak-backup-YYYYMMDD-HHMMSS.zip --force
```

يتحقق من بصمة كل ملف قبل كتابته في `storage/app/private`، ويكتب دمب القاعدة بجوار الأرشيف بامتداد `.sql`.

### 5. تحميل القاعدة بدور المالك (~10–30 دقيقة)

```bash
set -a; . /etc/darak/migrate.env; set +a
PGPASSWORD="$DB_PASSWORD" psql -X -v ON_ERROR_STOP=1 --host=127.0.0.1 --username="$DB_USERNAME" --dbname=darak \
     --file=/var/backups/darak/darak-backup-YYYYMMDD-HHMMSS.sql
PGPASSWORD="$DB_PASSWORD" psql -X -v ON_ERROR_STOP=1 --host=127.0.0.1 --username="$DB_USERNAME" --dbname=darak \
     -v app_role=darak_app --file=/var/www/darak/deploy/postgres/app-grants.sql
shred -u /var/backups/darak/darak-backup-YYYYMMDD-HHMMSS.sql   # نسخة نصية كاملة من القاعدة — لا تُترك
```

الدمب مأخوذ بـ`--no-owner --no-privileges`، فتصبح الجداول والمشغلات (ومنها حماية سجل التدقيق) مملوكة لدور المالك تلقائياً.

### 6. التحقق — هذه الخطوة هي التمرين (~10 دقائق)

```bash
cd /var/www/darak/backend
sudo -u darak php artisan migrate --force          # يجب أن يقول Nothing to migrate للوسم نفسه
sudo -u darak php artisan darak:restore /var/backups/darak/darak-backup-YYYYMMDD-HHMMSS.zip --verify-only
sudo -u darak php artisan darak:audit-verify       # يفشل إن اختلف DARAK_AUDIT_HMAC_KEY
sudo -u darak php artisan config:cache && sudo -u darak php artisan route:cache && sudo -u darak php artisan view:cache
sudo -u darak php artisan darak:preflight          # FAIL الوحيد المقبول هنا: MAIL_MAILER=log المؤقت
```

- `--verify-only` يجب أن يطابق عدد صفوف كل جدول وبصمة كل ملف: **أي فرق = التمرين فاشل**.
- `darak:audit-verify` يجب أن يقول `Audit chain valid`.

### 7. فحص يدوي (~15 دقيقة)

- [ ] الدخول إلى اللوحة بحساب المالك (MFA).
- [ ] فتح آخر زيارة مقفلة قبل وقت النسخة وصورها وتوقيعها، وتنزيل تقرير PDF.
- [ ] فتح بلاغ QR وصورته.
- [ ] `php artisan darak:health --json` — المجدول يحتاج دقيقتين بعد تشغيل cron.

### 8. الإنهاء

- أعد `MAIL_MAILER` للمزود الحقيقي **فقط** إن كان هذا خادماً بديلاً للإنتاج، ثم حوّل DNS.
- إن كان تمريناً: احذف الخادم بعد تسجيل النتيجة (يحوي بيانات حقيقية)، واحذف `rclone.conf` منه أولاً.

## سجل التوقيت

انسخ الجدول لكل تمرين واحفظه في سجل التشغيل.

| # | الخطوة | البداية | النهاية | المدة | المنفّذ | ملاحظات / مشكلات |
|---|---|---|---|---|---|---|
| 0 | قرار البدء (بدء عدّاد RTO) | | — | — | | |
| 1 | تجهيز الخادم | | | | | |
| 2 | جلب النسخة من خارج الخادم | | | | | حجم الأرشيف: |
| 3 | الكود والإعداد | | | | | الوسم: |
| 4 | استخراج الأدلة | | | | | عدد الملفات: |
| 5 | تحميل القاعدة | | | | | |
| 6 | التحقق الآلي | | | | | نتيجة verify-only / audit-verify: |
| 7 | الفحص اليدوي | | | | | |
| — | **الإجمالي (الهدف ≤ 4 ساعات)** | | | | | |

| البند | القيمة |
|---|---|
| تاريخ التمرين | |
| اسم الأرشيف ووقت إنشائه | |
| RPO الفعلي (وقت الحادثة − وقت النسخة) | |
| RTO الفعلي (الإجمالي أعلاه) | |
| فروق عدد الصفوف أو البصمات | |
| ما يجب تحسينه قبل التمرين القادم | |
| المعتمد | |
