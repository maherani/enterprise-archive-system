# راهنمای جامع اپراتوری استقرار و بازیابی سامانه در محیط جدید
# System Deployment & Recovery Operator Runbook

> **وضعیت سند:** سند زنده و عملیاتی (Living Document)  
> **نگارش سامانه:** v2.9.0  
> **مرجع نیازمندی مرتبط:** [Requirement 30 — System Deployment & Recovery Runbook](requirements/30_system_deployment_and_recovery_runbook.md)  
> **مخاطب:** مدیران سیستم (SysAdmins)، کارشناسان DevOps، و اپراتورهای ارشد فناوری اطلاعات  

---

## ۱. بیانیه حاکمیت مستندات زنده (Living Documentation Policy)

> [!IMPORTANT]
> **قانون دائمی به‌روزرسانی همگام:**  
> این سند راهنمای رسمی و زنده اپراتوری سامانه آرشیو اسناد سازمانی (`enterprise-archive-system`) است.  
> بر اساس سیاست حاکمیت مستندات پروژه، **به‌ازای هرگونه تغییر در معماری سرویس‌ها، ایمیج‌های داکر، ساختار متغیرهای محیطی (`.env`)، پورت‌ها، اسکریپت‌های پوشه `deploy/` یا کامپوننت‌های سفارشی، این سند باید بلافاصله و در همان کامیت به‌روزرسانی شود.**  
> هیچ تغییری در زیرساخت بدون بازتاب در این Runbook معتبر تلقی نخواهد شد.

---

## ۲. خلاصه معماری و دو درگاه عملیاتی استقرار

این راهنما دو سناریوی کاملاً مستقل را برای استقرار سامانه روی **سرور جدید، ماشین مجازی (VM) خام، یا محیط کلاود تازه** پوشش می‌دهد:

```text
                               ┌────────────────────────────────────────┐
                               │   سرور یا محیط اجرایی جدید (Clean OS)   │
                               └───────────────────┬────────────────────┘
                                                   │
                        ┌──────────────────────────┴──────────────────────────┐
                        ▼                                                     ▼
        ┌───────────────────────────────┐                     ┌───────────────────────────────┐
        │   سناریوی الف: بازسازی و بازیابی   │                     │    سناریوی ب: استقرار تمیز     │
        │   (Scenario A: Rebuild + Restore)  │                     │ (Scenario B: Fresh Deployment) │
        ├───────────────────────────────┤                     ├───────────────────────────────┤
        │ • سرور قبلی نابود شده/تعویض شده│                     │ • راه‌اندازی اولیه سامانه     │
        │ • بکاپ معتبر قبلی وجود دارد   │                     │ • بدون اطلاعات قبلی (صفر)     │
        │ • بازگردانی کامل دیتابیس و سند │                     │ • نصب هدلس و ساختار پیش‌فرض    │
        │ • همگام‌سازی کلیدها و Saltها   │                     │ • پیکربندی سازمانی و نقش‌ها   │
        └───────────────┬───────────────┘                     └───────────────┬───────────────┘
                        │                                                     │
                        └──────────────────────────┬──────────────────────────┘
                                                   ▼
                                ┌─────────────────────────────────────┐
                                │ ارزیابی جامع سلامت و آزمون‌های خودکار│
                                │  - اجرای deploy/check_health.sh     │
                                │  - آزمون‌های پایتون و ممیزی دسترسی  │
                                └─────────────────────────────────────┘
```

---

## ۳. پیش‌نیازهای زیرساختی و سخت‌افزاری (Prerequisites)

پیش از آغاز استقرار، اطمینان حاصل کنید که سرور مقصد مشخصات زیر را دارا باشد:

### ۳.۱. مشخصات سخت‌افزاری پیشنهادی
- **پردازنده (CPU):** حداقل ۴ هسته (توصیه: ۸ هسته برای بیش از ۱۰۰ کاربر همزمان).
- **حافظه رم (RAM):** حداقل ۸ گیگابایت (توصیه: ۱۶ گیگابایت).
- **فضای دیسک (Storage):** حداقل ۵۰ گیگابایت SSD/NVMe برای سیستم‌عامل و دیتابیس + فضای متناسب با حجم اسناد سازمانی (حداقل دو برابر حجم تخمینی آرشیو).
- **شبکه:** کارت شبکه ۱ گیگابیت بر ثانیه یا بالاتر با IP استاتیک اختصاصی.

### ۳.۲. نیازمندی‌های نرم‌افزاری سیستم‌عامل
- **سیستم‌عامل تاییدشده:** Ubuntu Server 22.04 LTS یا 24.04 LTS (یا Debian 12 / RHEL 9).
- **موتور کانتینری:** Docker Engine نسخه ۲۴.۰ یا بالاتر.
- **ارکستراسیون:** Docker Compose Plugin نسخه ۲.۲۰ یا بالاتر (`docker compose`).
- **پکیج‌های سیستمی مورد نیاز:** `curl`, `git`, `jq`, `tar`, `gzip`, `sha256sum`, `postgresql-client`.

### ۳.۳. ماتریس دسترسی و پورت‌های شبکه
| پورت | پروتکل | سرویس | جهت ترافیک | دسترسی عمومی / شبکه داخلی |
|---|---|---|---|---|
| **80** | TCP | Nginx Reverse Proxy (HTTP) | ورودی (Inbound) | مجاز برای کاربران شبکه سازمانی |
| **443** | TCP | Nginx Reverse Proxy (HTTPS) | ورودی (Inbound) | مجاز برای کاربران شبکه سازمانی |
| **5432** | TCP | PostgreSQL (archive_db) | داخلی داکر | **اکیداً مسدود برای خارج** (فقط درون داکر) |
| **80** (داخلی) | TCP | Nextcloud (archive_app) | داخلی داکر | **اکیداً مسدود برای خارج** (فقط از طریق Nginx) |

---

## ۴. ساختار مخزن، داده‌ها و محرمانگی (Repository & Storage Layout)

تمامی داده‌های پایدار سامانه بر روی دیسک هاست به صورت Bind-Mount نگهداری می‌شوند:

```text
/home/alborz/enterprise-archive-system/
├── .env                              # مقادیر و کلمات عبور فعال (Permissions: 600)
├── .env.example                      # قالب استاندارد متغیرها
├── docker-compose.yml                # مانیفست اصلی سرویس‌های داکر
├── db/                               # مخزن فیزیکی پایگاه داده PostgreSQL (uid: 70)
├── nextcloud/
│   ├── config/config.php             # پیکربندی هسته و کلیدهای نمک (passwordsalt, secret)
│   ├── data/                         # مخزن اسناد و فایل‌های کاربران (uid: 33 / www-data)
│   └── custom_apps/                  # اپلیکیشن‌های مارکت نکست‌کلاد
├── apps/
│   └── archive_autotag/              # سورس‌کد اپلیکیشن اختصاصی آرشیو (۵۳ ماژول)
├── nginx/
│   ├── default.conf                  # پیکربندی پروکسی و قوانین کش لبه
│   └── maintenance.html              # صفحه اختصاصی حالت تعمیرات در زمان بحران
└── deploy/
    ├── backup_daemon.sh              # دیمن مانیتورینگ و عملیات پس‌زمینه
    ├── backup_db.sh                  # اسکریپت تهیه پشتیبان استاندارد
    ├── restore_db.sh                 # اسکریپت بازیابی اتمیک (موتور سناریوی A)
    ├── deploy_from_scratch.sh        # اسکریپت استقرار خودکار (موتور سناریوی B)
    ├── manage_backup.sh              # کنسول یکپارچه خط فرمان برای اپراتور
    └── check_health.sh               # ابزار اعتبارسنجی جامع سلامت سیستم
```

> [!CAUTION]
> **حفاظت از فایل محرمانه `.env`:**  
> فایل `.env` حاوی کلمات عبور دیتابیس و کلیدهای مدیریتی است. هرگز این فایل را به گیت کامیت نکنید. مجوز این فایل باید همیشه `chmod 600 .env` باشد تا فقط کاربر مالک سرور به آن دسترسی داشته باشد.

---

## ۵. دستورالعمل اجرایی سناریوی الف (Scenario A: Rebuild + Restore)

**مورد کاربرد:** زمانی که سرور قبلی از بین رفته، سیستم‌عامل مجدداً نصب شده، یا سخت‌افزار ارتقا یافته است، اما یک **نسخه پشتیبان سالم (`*.tar.gz` به همراه `*.sha256`)** در اختیار داریم.

### گام ۱: آماده‌سازی سرور خام و بسته‌ها
با دسترسی `sudo` وارد سرور جدید شوید:

```bash
# ۱. به‌روزرسانی پکیج‌های سیستم‌عامل
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y curl git jq ca-certificates gnupg ufw tar gzip

# ۲. نصب رسمی داکر و داکر کامپوز
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
sudo systemctl enable --now docker

# اعمال عضویت گروه داکر در سشن جاری
newgrp docker
```

### گام ۲: کلون مخزن پروژه و تنظیم متغیرهای محیطی
```bash
# ۳. کلون مخزن پروژه
cd /home/$USER
git clone <REPOSITORY_URL> enterprise-archive-system
cd enterprise-archive-system

# ۴. ایجاد فایل .env از روی الگو و اعمال مجوز امن
cp .env.example .env
chmod 600 .env

# ۵. ویرایش مقادیر دیتابیس بر اساس تنظیمات سرور جدید
nano .env
# اطمینان حاصل کنید متغیرهای POSTGRES_DB، POSTGRES_USER و POSTGRES_PASSWORD تنظیم شده‌اند.
```

### گام ۳: انتقال فایل پشتیبان و اعتبارسنجی اولیه
فایل بکاپ سالم و فایل هش آن را به پوشه `deploy/backups/` انتقال دهید:

```bash
mkdir -p deploy/backups
# فرض کنید نام فایل nextcloud_full_backup_20260925_212005.tar.gz است
cp /path/to/backup/nextcloud_full_backup_*.tar.gz deploy/backups/latest_nextcloud_backup.tar.gz
cp /path/to/backup/nextcloud_full_backup_*.tar.gz.sha256 deploy/backups/latest_nextcloud_backup.tar.gz.sha256

# بررسی دستی هش SHA-256 قبل از اجرا
cd deploy/backups
sha256sum -c latest_nextcloud_backup.tar.gz.sha256
cd ../..
```

### گام ۴: راه‌اندازی مقدماتی سرویس پایگاه‌داده
کانتینر دیتابیس را روشن کنید تا آماده دریافت اطلاعات شود:

```bash
docker compose up -d db
# صبر کنید تا دیتابیس در حالت Healthy قرار گیرد:
docker compose ps
```

### گام ۵: اجرای اسکریپت خودکار بازیابی اطلاعات (`restore_db.sh`)
دستور بازیابی را به عنوان اپراتور اجرا نمایید:

```bash
./deploy/restore_db.sh deploy/backups/latest_nextcloud_backup.tar.gz
```

#### اسکریپت به صورت خودکار مراحل زیر را انجام می‌دهد:
1. **تست یکپارچگی هش SHA-256:** در صورت دستکاری یا خرابی بایت‌ها، فوراً متوقف می‌شود.
2. **ایزولاسیون سامانه:** کانتینرهای `archive_app` و `archive_proxy` را خاموش می‌کند تا هیچ ترافیکی وارد نشود.
3. **ریست و درون‌ریزی دیتابیس:** ساختار قبلی دیتابیس را پاک و فایل `database.sql` بکاپ را تزریق می‌کند.
4. **همگام‌سازی نقش دیتابیس:** کلمه عبور نقش دیتابیس در PostgreSQL را با متغیر `POSTGRES_PASSWORD` در `.env` هماهنگ می‌سازد.
5. **استخراج فایل‌ها و کانفیگ:** پوشه‌های `data/`، `config/` و `custom_apps/` را در هاست استخراج می‌کند.
6. **همگام‌سازی کلیدها در `config.php`:** کلیدهای `passwordsalt` و `secret` قبلی را حفظ کرده و رشته‌های اتصال به دیتابیس جدید را با `.env` منطبق می‌سازد.
7. **تصحیح مجوزها:** مالکیت تمام فایل‌ها را به `www-data:www-data` (`uid: 33`) تغییر می‌دهد.
8. **رفع قفل‌ها و بازسازی کش:** قفل‌های موقت دیتابیس را تخلیه کرده و دستور `occ files:scan --all` را برای شاخص‌گذاری کامل اسناد اجرا می‌کند.
9. **روشن‌سازی مجدد کانتینرها:** کانتینر اپلیکیشن و پروکسی را روشن کرده و سامانه را به مدار بازمی‌گرداند.

### گام ۶: ارزیابی و اعتبارسنجی پس از بازیابی
```bash
./deploy/check_health.sh
python3 tests/test_backup_and_recovery.py
```

---

## ۶. دستورالعمل اجرایی سناریوی ب (Scenario B: Fresh Deployment)

**مورد کاربرد:** زمانی که سامانه قرار است **برای اولین بار در یک سازمان یا محیط جدید** بدون هیچ اطلاعات یا پیشینه‌ای نصب و راه‌اندازی شود.

### گام ۱: آماده‌سازی اولیه سرور خام
همانند گام ۱ سناریوی الف، پکیج‌های سیستم‌عامل، داکر و داکر کامپوز را نصب نمایید.

### گام ۲: کلون مخزن و ایجاد پیکربندی سازمانی
```bash
cd /home/$USER
git clone <REPOSITORY_URL> enterprise-archive-system
cd enterprise-archive-system

# ایجاد فایل متغیرها
cp .env.example .env
chmod 600 .env

# ویرایش و تعیین نام کاربری و پسوردهای قوی برای ادمین و دیتابیس
nano .env
```
نمونه تنظیمات الزامی در `.env`:
```env
POSTGRES_DB=nextcloud
POSTGRES_USER=nextcloud_user
POSTGRES_PASSWORD=VeryStrongDatabasePassword_2026!
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=VeryStrongAdminPassword_2026!
NEXTCLOUD_TRUSTED_DOMAINS="localhost archive.corp.internal 192.168.1.100"
OVERWRITEPROTOCOL=http
```

### گام ۳: اجرای استقرار کاملاً خودکار (`deploy_from_scratch.sh`)
اسکریپت نصب تمیز را اجرا کنید:

```bash
chmod +x deploy/*.sh
./deploy/deploy_from_scratch.sh
```

#### اسکریپت به صورت ۱۰۰٪ خودکار مراحل زیر را انجام می‌دهد:
1. پوشه‌های فیزیکی ماندگار (`./db`, `./nextcloud/data`, `./nextcloud/config`, `./nextcloud/custom_apps`) را با مجوزهای لازم ایجاد می‌کند.
2. کانتینرهای `archive_db`, `archive_app` و `archive_proxy` را بالا می‌آورد.
3. با ابزار `pg_isready` منتظر بالا آمدن قطعی پایگاه داده می‌ماند.
4. فرآیند نصب Headless را بدون نیاز به ویزارد مرورگر با دستور زیر اجرا می‌کند:
   ```bash
   occ maintenance:install --database "pgsql" ...
   ```
5. تنظیمات بومی‌سازی شامل زبان فارسی (`fa`)، منطقه زمانی (`Asia/Tehran`) و تلفن پیش‌فرض ایران (`IR`) را اعمال می‌کند.
6. اپلیکیشن اختصاصی `archive_autotag` را فعال کرده و مایگریشن‌های پایگاه داده را پیاده‌سازی می‌نماید.
7. پوسته مات Obsidian، استایل‌های برندینگ و فونت وزیرمتن را تزریق می‌کند.
8. پوشه ریشه بایگانی (`/admin/files/Enterprise_Archive`) را به همراه متادیتا می‌سازد.
9. گروه‌ها و کاربران پیش‌فرض سازمانی را با سهمیه‌های استاندارد ایجاد می‌کند.
10. سرویس پس‌زمینه پشتیبان‌گیری را فعال کرده و وضعیت سلامت نهایی را چاپ می‌نماید.

---

## ۷. راه‌اندازی و مدیریت دیمن پس‌زمینه (Background Daemon Management)

سامانه دارای یک دیمن پس‌زمینه سبک به نام `deploy/backup_daemon.sh` است که وظیفه اجرای وظایف زمان‌بندی‌شده، مانیتورینگ وضعیت و اجرای آزمون‌های دوره‌ای سندباکس را بر عهده دارد.

### ۷.۱. راه‌اندازی دستی دیمن در پس‌زمینه
```bash
nohup /home/$USER/enterprise-archive-system/deploy/backup_daemon.sh > /home/$USER/enterprise-archive-system/deploy/backup_daemon.log 2>&1 &
```

### ۷.۲. ثبت به عنوان سرویس خودکار سیستم‌عامل (`systemd`) — روش پیشنهادی
برای اطمینان از بالا آمدن دیمن پس از ریبوت سرور، یک فایل سرویس بسازید:

```bash
sudo bash -c 'cat > /etc/systemd/system/enterprise-archive-daemon.service <<EOF
[Unit]
Description=Enterprise Archive System Operational Daemon
After=docker.service
Requires=docker.service

[Service]
Type=simple
User=alborz
WorkingDirectory=/home/alborz/enterprise-archive-system
ExecStart=/home/alborz/enterprise-archive-system/deploy/backup_daemon.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF'

# فعال‌سازی و استارت سرویس
sudo systemctl daemon-reload
sudo systemctl enable --now enterprise-archive-daemon.service
sudo systemctl status enterprise-archive-daemon.service
```

### ۷.۳. پایش لاگ‌های دیمن
```bash
tail -f deploy/backup_daemon.log
```

---

## ۸. دستورات سریع کنسول خط فرمان اپراتور (`manage_backup.sh`)

اپراتور سیستم می‌تواند تمامی فرآیندهای روزمره را از طریق شل‌اسکریپت متمرکز زیر مدیریت کند:

```bash
# مشاهده وضعیت سلامت، آخرین نسخه بکاپ و وضعیت دیمن
./deploy/manage_backup.sh status

# مشاهده لیست کامل فایل‌های پشتیبان، حجم و هش‌ها
./deploy/manage_backup.sh list

# اجرای فوری یک نسخه پشتیبان کامل
./deploy/manage_backup.sh run

# اجرای آزمون ارزیابی سلامت بکاپ در محیط سندباکس ایزوله
./deploy/manage_backup.sh test deploy/backups/latest_nextcloud_backup.tar.gz

# اجرای بازیابی تعاملی در ترمینال
./deploy/manage_backup.sh restore deploy/backups/latest_nextcloud_backup.tar.gz

# پاکسازی فایل‌های پشتیبان منقضی بر اساس سیاست نگهداری (Retention)
./deploy/manage_backup.sh prune
```

---

## ۹. حفظ و تداوم سفارشی‌سازی‌ها (Customization Preservation)

اپراتور باید بداند که چرا و چگونه سفارشیسازی‌های سیستم در زمان استقرار مجدد از بین نمی‌روند:

1. **کدهای اختصاصی (`apps/archive_autotag`):** این پوشه حاوی کلیه ۵۳ کلاس PHP، کنترلرها، سرویس ممیزی پایدار، رزولور دسترسی و رابط کاربری پورتال است. از آنجا که این پوشه مستقیماً از هاست به داخل کانتینر متصل (Bind-Mount) شده است، هرگز وابسته به چرخه کانتینر داکر نیست و با خاموش یا تعویض شدن ایمیج دست‌نخورده باقی می‌ماند.
2. **پوسته مات Obsidian و فونت وزیرمتن:** کدهای CSS در مسیر `apps/archive_autotag/css/` نسخه شده‌اند و با فعال بودن اپ، به طور خودکار به سربرگ صفحات تزریق می‌شوند.
3. **ماسک برندینگ:** استایل‌های مخفی‌سازی Nextcloud درون `branding_mask.css` قرار داشته و در تمام صفحات عمومی و خصوصی بدون وقفه اعمال می‌گردند.
4. **تگ‌های سیستمی و متادیتای اسناد:** شِما و رکوردهای جداول اختصاصی (`oc_archive_tags`, `oc_archive_document_metadata`, `oc_archive_permission_audit`) در سناریوی A از طریق فایل `database.sql` بازیابی شده و در سناریوی B از طریق مایگریشن‌های اپلیکیشن از نو ساخته می‌شوند.

---

## ۱۰. ماتریس عیب‌یابی و سناریوهای اضطراری (Troubleshooting Matrix)

| خطای مشاهده‌شده | علت ریشه‌ای احتمالی | دستورات سریع رفع مشکل توسط اپراتور |
|---|---|---|
| **خطای خط فرمان: Port 80 is already in use** | سرویس وب محلی (مانند آپاچی یا Nginx خود سرور) روشن است | `sudo systemctl stop nginx apache2`<br>`sudo systemctl disable nginx apache2` |
| **خطای خط فرمان: Checksum verification failed** | فایل بکاپ ناقص کپی شده یا دستکاری شده است | فایل را دوباره دانلود/انتقال دهید و با `sha256sum <file>` بررسی کنید. |
| **خطای وب: 502 Bad Gateway** | کانتینر `archive_app` هنوز در حال بالا آمدن است یا متوقف شده | `docker compose logs -f app`<br>بررسی وضعیت با `docker compose ps` |
| **خطای وب: Data directory is not writable** | مجوز مالکیت پوشه دیتای نکست‌کلاد تغییر یافته است | `docker exec archive_app chown -R www-data:www-data /var/www/html/data` |
| **خطای وب: Access through untrusted domain** | دامنه یا IP سرور در لیست دامنه‌های مجاز نیست | `docker exec -u www-data archive_app php occ config:system:set trusted_domains 3 --value="YOUR_DOMAIN_OR_IP"` |
| **خطای دیتابیس: Password authentication failed** | کلمه عبور در `config.php` با رمز نقش دیتابیس ناهمخوان است | `docker exec -u www-data archive_app php occ config:system:set dbpassword --value="VALUE_FROM_ENV"` |
| **خطای دیتابیس: Database is locked / File lock** | قفل‌های جدول `oc_file_locks` پس از قطعی سرور باقی مانده است | `docker exec archive_db psql -U nextcloud_user -d nextcloud -c "TRUNCATE TABLE oc_file_locks;"` |
| **عدم به‌روزرسانی لیست فایل‌ها در پورتال** | کش ایندکس فایل‌ها نیاز به همگام‌سازی با دیسک دارد | `docker exec -u www-data archive_app php occ files:scan --all` |

---

## ۱۱. دستورالعمل بازگشت به عقب در صورت شکست (Rollback SOP)

اگر در هر مرحله‌ای از اجرای سناریوی الف یا ب فرآیند با شکست غیرقابل ترمیم مواجه شد:

```bash
# ۱. توقف فوری کلیه کانتینرهای فعال
docker compose down

# ۲. استخراج لاگ‌های خطا جهت ارزیابی کارشناسی
docker compose logs > deploy/deployment_failure_$(date +%Y%m%d_%H%M%S).log

# ۳. پاکسازی وضعیت ناقص دیتابیس در صورت نیاز به تکرار از صفر
# هشدار: این دستور دایرکتوری پایگاه داده را بازنشانی می‌کند
rm -rf db/* nextcloud/data/* nextcloud/config/*

# ۴. بررسی و تصحیح مقادیر فایل .env و تلاش مجدد
nano .env
```

---

## ۱۲. چک‌لیست نهایی تحویل سامانه به بهره‌بردار (Production Handover Checklist)

قبل از اعلام آمادگی سامانه به کارفرما یا بهره‌برداران نهایی، تمامی موارد زیر باید تایید شوند:

- [ ] اجرای کامل `./deploy/check_health.sh` و دریافت پیام `Health check completed successfully`.
- [ ] بررسی ورود موفقیت‌آمیز کاربر مدیر ارشد (`admin`) از طریق مرورگر به آدرس سرور.
- [ ] بررسی اعمال ظاهر پوسته مشکی مات Obsidian و فونت یکپارچه وزیرمتن در تمامی دکمه‌ها.
- [ ] آزمایش موفقیت‌آمیز بارگذاری یک فایل جدید در پوشه `/Enterprise_Archive`.
- [ ] آزمایش اختصاص متادیتای الزامی به سند و جستجوی آن با تگ در پورتال آرشیو.
- [ ] بررسی فعال بودن سرویس دیمن در پس‌زمینه (`systemctl status enterprise-archive-daemon.service` یا `ps aux | grep backup_daemon.sh`).
- [ ] اجرای موفقیت‌آمیز آزمون‌های رگرسیون پایتون با `python3 run_all_tests.py` یا `python3 tests/test_backup_and_recovery.py`.
