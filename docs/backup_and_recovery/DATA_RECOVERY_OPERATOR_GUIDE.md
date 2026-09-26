# راهنمای جامع اپراتور: بازیابی اطلاعات سامانه آرشیو در شرایط بحران (Disaster Recovery SOP)

این سند مرجع عملیاتی و دستورالعمل اجرایی استاندارد (Standard Operating Procedure - SOP) برای **مدیران سیستم (System Administrators) و اپراتورهای فنی** جهت بازیابی اطلاعات، اسناد و فراداده‌های سامانه `enterprise-archive-system` از روی نسخه‌های پشتیبان معتبر است.

> [!IMPORTANT]
> **محدوده عملکرد این راهنما:**  
> این راهنما مختص سناریویی است که کانتینرها و زیرساخت کلی سامانه وجود دارد، اما به دلایلی نظیر خرابی پایگاه داده، خطای انسانی، نفوذ، حذف سهوی پوشه‌های آرشیو، یا آسیب به فایل‌ها و متاداده‌ها، نیاز به بازگرداندن اطلاعات به یک **نقطه بازیابی معتبر (Recovery Point)** وجود دارد.  
> *(برای راه‌اندازی سامانه بر روی سرور جدید و خام از صفر، به [DEPLOYMENT_RUNBOOK.md](file:///Ubuntu-26.04/home/alborz/enterprise-archive-system/docs/DEPLOYMENT_RUNBOOK.md) مراجعه فرمایید).*

---

## ۱. نقشه راه و جریان عملیاتی دسترسی دوگانه

سامانه دارای **دو درگاه عملیاتی کاملاً مستقل** برای پشتیبان‌گیری و بازیابی اطلاعات است:

```text
                                    ┌────────────────────────┐
                                    │    بروز حادثه یا نیاز   │
                                    │       به بازیابی       │
                                    └───────────┬────────────┘
                                                │
                     ┌──────────────────────────┴──────────────────────────┐
                     ▼                                                     ▼
┌─────────────────────────────────────────┐               ┌─────────────────────────────────────────┐
│     درگاه ۱: پنل وب مدیریت ارشد          │               │      درگاه ۲: ترمینال سرور (CLI)         │
│  (برای شرایطی که رابط وب در دسترس است)    │               │  (برای شرایط بحران شدید یا عدم قطعی وب)   │
├─────────────────────────────────────────┤               ├─────────────────────────────────────────┤
│ ۱. ورود با حساب مدیر ارشد               │               │ ۱. اتصال SSH یا شل به سرور               │
│ ۲. ورود به تب Backup & Recovery پورتال  │               │ ۲. اجرای deploy/manage_backup.sh status │
│ ۳. انتخاب نسخه و کلیک روی «بازیابی»    │               │ ۳. اجرای ./deploy/restore_db.sh         │
│ ۴. تایید مودال امنیتی و پایش نوار پیشرفت│               │ ۴. پایش خروجی کنسول لینوکس              │
└────────────────────┬────────────────────┘               └────────────────────┬────────────────────┘
                     │                                                         │
                     └──────────────────────────┬──────────────────────────────┘
                                                ▼
                               ┌──────────────────────────────────┐
                               │ فاز اعتبارسنجی خودکار سلامت      │
                               │ - اجرای deploy/check_health.sh   │
                               │ - راستی‌آزمایی ورود و اسناد      │
                               └──────────────────────────────────┘
```

---

## ۲. درگاه اول: راهنمای گام‌به‌گام از طریق پنل وب مدیریت (Web Admin Console)

این روش سریع‌ترین و ساده‌ترین حالت برای مدیر ارشد است و نیازی به دانش خط فرمان لینوکس ندارد.

### ۲.۱. نحوه ورود به بخش مدیریت پشتیبان‌گیری
1. مرورگر خود را باز کرده و با حساب مدیر ارشد (`admin`) وارد پورتال سامانه آرشیو شوید.
2. از منوی اصلی بالای صفحه یا آیکون تنظیمات سمت چپ، بر روی دکمه **«مدیریت پشتیبان‌گیری و بازیابی» (Backup & Disaster Recovery)** کلیک کنید.
3. داشبورد وضعیت نمایش داده می‌شود که شامل:
   - کارت وضعیت آخرین پشتیبان‌گیری (تاریخ، ساعت، حجم و وضعیت سلامت هش).
   - جدول نسخه‌های پشتیبان موجود بر روی سرور.
   - فرم تنظیمات زمان‌بندی خودکار (Schedule).

### ۲.۲. تهیه نسخه پشتیبان دستی از طریق وب (Instant Web Backup)
1. در بالای صفحه روی دکمه آبی‌رنگ **«تهیه نسخه پشتیبان جدید»** کلیک کنید.
2. پنجره کوچکی نمایش داده می‌شود؛ نوع بکاپ (`Data & Metadata Backup`) را انتخاب و دکمه **«شروع فرآیند»** را بزنید.
3. فرآیند به صورت آسنکرون در پس‌زمینه آغاز شده و نوار پیشرفت مراحل را نشان می‌دهد:
   - *مرحله ۱: فعال‌سازی قفل تغییرات سامانه (Maintenance Mode)*
   - *مرحله ۲: استخراج اسنپ‌شات اتمیک دیتابیس*
   - *مرحله ۳: فشرده‌سازی فایل‌های اسناد کاربران*
   - *مرحله ۴: محاسبه کد هش SHA-256 و ذخیره شناسنامه مانیفست*
4. پس از اتمام (معمولاً زیر ۴۰ ثانیه)، پیام سبز موفقیت‌آمیز صادر شده و نسخه جدید به بالای جدول اضافه می‌شود.

### ۲.۳. بازیابی اطلاعات از طریق پنل وب (Web-Triggered Restore)
> [!CAUTION]
> عملیات بازیابی باعث جایگزینی پایگاه‌داده و فایل‌های فعلی با اطلاعات نسخه پشتیبان انتخاب‌شده خواهد شد. در طول فرآیند، سامانه موقتاً در حالت تعمیرات قرار می‌گیرد.

1. در جدول نسخه‌ها، نسخه پشتیبان مورد نظر خود را بر اساس تاریخ و ساعت انتخاب کنید.
2. بر روی دکمه **«بازیابی این نسخه» (Restore)** در انتهای ردیف کلیک کنید.
3. **مودال هشدارهای بحران (Disaster Warning Modal)** باز می‌شود که جزئیات زیر را نشان می‌دهد:
   - تاریخ و شناسه نسخه انتخابی.
   - تعداد کاربران و اسنادی که بازگردانی خواهند شد.
   - وضعیت امضای دیجیتال SHA-256 (باید معتبر و سبز باشد).
4. جهت اطمینان از عدم کلیک اشتباهی، کلمه **`RESTORE-CONFIRM`** را در کادر متنی تاییدیه تایپ کنید و سپس روی دکمه قرمز **«تایید نهایی و آغاز بازیابی»** کلیک نمایید.
5. صفحه وارد وضعیت **«در حال بازیابی اطلاعات...»** می‌شود:
   - ارتباط سایر کاربران به صورت خودکار به صفحه وضعیت تعمیرات منتقل می‌شود.
   - پیشرفت مراحل به صورت زنده نمایش داده می‌شود.
6. پس از تکمیل فرآیند، دکمه ورود مجدد ظاهر می‌شود. شما به صفحه ورود منتقل می‌شوید و اطلاعات سامانه دقیقاً به همان نقطه بازیابی بازگشته است.

### ۲.۴. اجرای آزمون بازیابی در محیط ایزوله از پنل وب (Web Test Restore)
1. برای اطمینان از این‌که یک فایل بکاپ در آینده بدون خطا قابل بازیابی خواهد بود، در جدول نسخه‌ها روی گزینه **«تست سلامت بازیابی» (Run Test)** کلیک کنید.
2. سامانه در پس‌زمینه یک دیتابیس آزمایشی موقت در سندباکس ایجاد کرده و صحت اکسترکت و پیوستگی متاداده‌ها را تست می‌کند.
3. در صورت سلامت کامل، نشان سبز **`100% Verified`** در ستون وضعیت ثبت می‌شود؛ این عملیات هیچ‌گونه توقفی در سامانه لایو ایجاد نمی‌کند.

---

## ۳. درگاه دوم: راهنمای گام‌به‌گام از طریق ترمینال و خط فرمان (Terminal CLI & Scripts)

این روش برای زمان‌هایی است که به دلیل اختلال شدید، وب‌سرور یا اپلیکیشن در دسترس نیست، یا اپراتور ترجیح می‌دهد مستقیماً از طریق SSH و کنسول سرور اقدام کند.

### ۳.۱. ورود به محیط سرور و بررسی وضعیت
وارد محیط لینوکس / WSL سرور شوید و به دایرکتوری ریشه پروژه بروید:
```bash
cd /home/alborz/enterprise-archive-system
```

وضعیت کلی سرویس‌ها و آخرین وضعیت بکاپ‌ها را استعلام کنید:
```bash
./deploy/manage_backup.sh status
```

### ۳.۲. مشاهده فهرست نسخه‌های معتبر
```bash
./deploy/manage_backup.sh list
```
نمونه خروجی:
```text
========================================================================================
 Enterprise Archive System - Backup Repository Catalog
========================================================================================
ID           TYPE        DATE & TIME          SIZE    SHA256 CHECKSUM    TEST STATUS
----------------------------------------------------------------------------------------
bk-0925-0200 data_only   2026-09-25 02:00:15  72M     [OK] 4a5c6d7e...   PASSED (100%)
bk-0924-0200 data_only   2026-09-24 02:00:10  68M     [OK] e1f2a3b4...   PASSED (100%)
latest       data_only   (Symlink to bk-0925) 72M     [OK] 4a5c6d7e...   VALID
========================================================================================
```

### ۳.۳. تهیه نسخه پشتیبان فوری از طریق ترمینال
برای گرفتن بکاپ فوری از طریق ترمینال کافی است دستور زیر را اجرا کنید:
```bash
./deploy/manage_backup.sh run
# یا اجرای مستقیم اسکریپت:
./deploy/backup_db.sh
```

### ۳.۴. اجرای بازیابی اطلاعات از طریق ترمینال
#### حالت الف: بازیابی خودکار از آخرین نسخه پشتیبان سالم:
```bash
./deploy/manage_backup.sh restore deploy/backups/latest_nextcloud_backup.tar.gz
# یا اجرای مستقیم:
./deploy/restore_db.sh
```

#### حالت ب: بازیابی از یک فایل پشتیبان با برچسب تاریخی مشخص:
```bash
./deploy/restore_db.sh deploy/backups/nextcloud_full_backup_20260925_020000.tar.gz
```

### خروجی مورد انتظار در کنسول اپراتور:
```text
[INFO] Verifying backup checksum...
latest_nextcloud_backup.tar.gz: OK
[WARNING] This will replace the current Nextcloud database, data, config, and custom apps.
[INFO] Backup source: .../latest_nextcloud_backup.tar.gz
[INFO] Database: nextcloud (owner: nextcloud_user)
[INFO] Terminating active database connections...
[INFO] Dropping and recreating database 'nextcloud'...
[INFO] Synchronizing PostgreSQL role password with .env...
[INFO] Importing database dump...
[INFO] Replacing file-backed Nextcloud state...
[INFO] Restoring Nextcloud data...
[INFO] Restoring Nextcloud config...
[INFO] Aligning restored Nextcloud database config with .env...
[INFO] Rebuilding file cache from restored storage...
Scanning files for 4 users...
[SUCCESS] Full Nextcloud backup successfully restored
```

---

## ۴. فاز ۳: اجرای گام‌به‌گام دستی در شرایط اضطراری (Manual Recovery Runbook)

در صورتی که اسکریپت خودکار با خطای سیستمی مواجه شود، اپراتور می‌تواند مراحل زیر را به تفکیک و دستی اجرا نماید:

### گام ۴.۱: ایزوله‌سازی سامانه
```bash
docker compose stop app proxy
docker compose up -d db
docker exec archive_db pg_isready -U nextcloud_user -d postgres
```

### گام ۴.۲: قطع اتصالات باز و بازسازی دیتابیس
```bash
docker exec archive_db psql -U nextcloud_user -d postgres -c \
  "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'nextcloud' AND pid <> pg_backend_pid();"
docker exec archive_db psql -U nextcloud_user -d postgres -c "DROP DATABASE IF EXISTS \"nextcloud\";"
docker exec archive_db psql -U nextcloud_user -d postgres -c "CREATE DATABASE \"nextcloud\" OWNER \"nextcloud_user\";"
```

### گام ۴.۳: استخراج موقت فایل پشتیبان و ایمپورت SQL
```bash
TARGET="deploy/backups/latest_nextcloud_backup.tar.gz"
TMP_DIR=$(mktemp -d)
tar -xzf "$TARGET" -C "$TMP_DIR"
BACKUP_ROOT=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d -print -quit)

docker exec -i archive_db psql -v ON_ERROR_STOP=1 -U nextcloud_user -d nextcloud < "$BACKUP_ROOT/database.sql"
```

### گام ۴.۴: جایگزینی فایل‌های فیزیکی
```bash
docker compose run --rm --no-deps --entrypoint sh app -c \
    'rm -rf /var/www/html/data /var/www/html/config /var/www/html/custom_apps && mkdir -p /var/www/html/data /var/www/html/config /var/www/html/custom_apps'

docker compose run --rm --no-deps -T --entrypoint tar app -xzf - -C /var/www/html < "$BACKUP_ROOT/data.tar.gz"
docker compose run --rm --no-deps -T --entrypoint tar app -xzf - -C /var/www/html < "$BACKUP_ROOT/config.tar.gz"
docker compose run --rm --no-deps -T --entrypoint tar app -xzf - -C /var/www/html < "$BACKUP_ROOT/custom_apps.tar.gz"

rm -rf "$TMP_DIR"
```

### گام ۴.۵: تنظیم دسترسی‌ها، رفع قفل‌ها و بازسازی کش
```bash
docker compose up -d app
docker exec archive_app chown -R www-data:www-data /var/www/html/data /var/www/html/config /var/www/html/custom_apps
docker exec archive_db psql -U nextcloud_user -d nextcloud -c "TRUNCATE TABLE oc_file_locks;"
docker exec -u www-data archive_app php occ files:scan --all
docker compose up -d proxy
```

---

## ۵. فاز ۴: راستی‌آزمایی و ممیزی پس از بازیابی (Post-Recovery Health Audit)

چه بازیابی از درگاه پنل وب انجام شده باشد و چه از طریق ترمینال، اپراتور باید تست‌های زیر را جهت صدور تاییدیه نهایی انجام دهد:

### تست ۱: اجرای اسکریپت ممیزی سلامت
```bash
./deploy/check_health.sh
```
- کانتینرهای `archive_proxy`، `archive_app` و `archive_db` در وضعیت `RUNNING`.
- تعداد رکوردهای جدول `oc_users` بیش از ۰.
- اسکن پوشه `/admin/files/Enterprise_Archive` بدون خطا.

### تست ۲: اعتبارسنجی لاگین و احراز هویت
- ورود موفق با رمز عبور کاربران پیشین (تایید صحت `passwordsalt` و کلیدهای امنیتی).

### تست ۳: بررسی اسناد و ساختار درختی
- دسترسی به پوشه‌های سازمانی (مالی، قراردادها، اداری).
- امکان دانلود و مشاهده سالم فایل‌های PDF و تصاویر.

### تست ۴: بررسی پیوستگی متاداده و تگ‌ها
- بررسی تطابق برچسب‌ها و فراداده‌های ثبتی اسناد (`document_number` و تاریخ ثبت).

---

## ۶. فاز ۵: راهنمای عیب‌یابی حوادث رایج (Troubleshooting)

| حادثه / خطا | علت احتمالی | دستور و راهکار رفع سریع |
| :--- | :--- | :--- |
| **خطای Checksum verification failed** | خرابی یا دانلود ناقص فایل بکاپ | فایل پشتیبان روز قبل را بررسی و استفاده نمایید. |
| **خطای Database connection error** | مغایرت رمز دیتابیس با فایل `.env` | اجرای: `docker exec -u www-data archive_app php occ config:system:set dbpassword --value="$POSTGRES_PASSWORD"` |
| **خطای File is locked** | باقی ماندن قفل‌های قبلی در دیتابیس | اجرای: `docker exec archive_db psql -U nextcloud_user -d nextcloud -c "TRUNCATE TABLE oc_file_locks;"` |
| **فایل‌ها در دیسک هستند اما در وب دیده نمی‌شوند** | عدم تطابق کش متاداده فایل‌ها | اجرای: `docker exec -u www-data archive_app php occ files:scan --all` |

---

## ۷. فرم ثبت گزارش ممیزی حادثه و بازیابی (Post-Incident Audit Form)

پس از اتمام موفقیت‌آمیز عملیات بازیابی، جدول زیر تکمیل و در سوابق ممیزی بایگانی می‌گردد:

| آیتم ممیزی | مقدار ثبت‌شده |
| :--- | :--- |
| **روش اجرا:** | `[ ] پنل وب مدیریت  /  [ ] ترمینال سرور (CLI)` |
| **تاریخ و ساعت شروع بازیابی:** | `YYYY/MM/DD - HH:MM` |
| **تاریخ و ساعت پایان بازیابی:** | `YYYY/MM/DD - HH:MM` |
| **مدت زمان توقف سرویس (Downtime):** | ... دقیقه |
| **نام فایل پشتیبان استفاده‌شده:** | `... .tar.gz` |
| **نقطه بازیابی (Recovery Point):** | `YYYY/MM/DD - HH:MM` |
| **کد چک‌سام SHA-256 نسخه:** | `[تایید شد]` |
| **تعداد اسناد و فایل‌های بازگردانی‌شده:** | ... عدد |
| **نتیجه ارزیابی سلامت (`check_health.sh`):** | `PASS / FAIL` |
| **نام و امضای اپراتور مسئول:** | ......................... |