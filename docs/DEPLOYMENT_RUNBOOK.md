# Enterprise Archive System - Deployment Runbook

این سند راهنمای اجرایی نصب و راه‌اندازی سامانه برای کارشناس زیرساخت است. مبنای آن وضعیت فعلی Repository پروژه است.

## 1. معماری

```text
[ External Clients / AI Agents / API ]
                 |
             HTTP :80
                 v
        +------------------+
        |  archive_proxy   |  Nginx Alpine
        |    Port 80:80    |
        +--------+---------+
                 |
                 v
        +------------------+
        |   archive_app    |  Nextcloud Apache
        |   Internal :80   |
        +--------+---------+
                 |
                 v
        +------------------+
        |    archive_db    |  PostgreSQL 15 Alpine
        |   Internal :5432 |
        +------------------+
```

دسترسی خارجی فقط از Nginx انجام شود؛ PostgreSQL و پورت داخلی Nextcloud نباید مستقیم روی شبکه عمومی منتشر شوند.

## 2. پیش‌نیاز

- Ubuntu Server 22.04/24.04 LTS
- sudo و دسترسی شبکه مناسب برای GitHub و Docker Registry
- Docker و Docker Compose Plugin
- فضای دیسک کافی برای PostgreSQL و آرشیو
- پورت 80 آزاد؛ در Production بعداً 443 نیز لازم است
- NTP/زمان سیستم صحیح

## 3. دریافت پروژه

```bash
cd ~
git clone https://github.com/maherani/enterprise-archive-system.git
cd ~/enterprise-archive-system
git status
```

شاخه و Commit مورد تأیید پروژه باید کنترل شود و Working Tree تمیز باشد.

## 4. ایجاد `.env`

```bash
nano .env
```

نمونه:

```env
POSTGRES_DB=nextcloud
POSTGRES_USER=nextcloud
POSTGRES_PASSWORD=<STRONG_DB_PASSWORD>

NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=<STRONG_ADMIN_PASSWORD>

NEXTCLOUD_TRUSTED_DOMAINS=localhost 127.0.0.1 <SERVER_IP_OR_FQDN>
```

رمزهای واقعی توسط مسئول امنیت/بهره‌برداری تعیین شوند. `.env` نباید وارد Git، Ticket، Chat یا Screenshot شود.

کنترل:

```bash
git status
```

باید Working Tree همچنان clean باشد.

## 5. راه‌اندازی Compose

```bash
docker compose up -d
docker compose ps
```

باید سرویس‌های زیر Up باشند:

- `archive_db`
- `archive_app`
- `archive_proxy`

هرگز برای رفع مشکل از `docker compose down -v` یا حذف `db/` و `nextcloud/` استفاده نشود.

## 6. تست Nginx و Nextcloud

```bash
curl -I http://localhost
```

پاسخ 200 یا 302 قابل قبول است. از مرورگر نیز `http://<SERVER_IP_OR_FQDN>` بررسی شود.

## 7. بررسی Nextcloud

```bash
docker compose exec app php occ status
```

نسخه مرجع فعلی پروژه: `34.0.3.2` و نصب باید `true` و maintenance برابر `false` باشد.

## 8. بررسی App Paths

```bash
docker compose exec app php occ config:list
```

مسیرهای مورد انتظار:

```text
/var/www/html/apps
/var/www/html/custom_apps
```

`custom_apps` باید Writable باشد.

## 9. LDAP

در محیط دارای LDAP/Active Directory:

```bash
docker compose exec app php occ app:enable user_ldap
```

سپس Base DN، Bind Account، اتصال و گروه‌ها طبق استاندارد سازمان تنظیم و با یک حساب آزمایشی تست شوند. Bind Password و سایر Secretها در Git ثبت نشوند.

## 10. Compliance Group و Service Account

در صورت استفاده از الگوی فعلی پروژه، گروه `Compliance_Unit` و Service Account `api_worker` با حداقل دسترسی لازم ایجاد شوند. برای API از App Password/Token اختصاصی استفاده شود و Token در Secret Manager نگهداری شود.

## 11. WebDAV/API Ingestion

Repository شامل `test_api.py` برای تست دریافت فایل است:

```bash
source venv/bin/activate
python test_api.py
```

تست موفق باید Upload را از مسیر Nginx انجام دهد و پاسخ HTTP موفق دریافت شود.

## 12. تنظیمات Nginx برای آرشیو حجیم

در `nginx/default.conf` موارد کلیدی فعلی پروژه باید حفظ شوند:

- `client_max_body_size 10G;`
- `proxy_request_buffering off;`
- `proxy_buffering off;`
- Timeoutهای مناسب برای انتقال فایل حجیم
- Service discovery مربوط به CalDAV/CardDAV در صورت نیاز

بعد از هر تغییر، Config و مسیر HTTP تست شوند.

## 13. Step 6 - Retention و Automated Tagging

Appهای مورد نیاز:

- `files_retention` نسخه `5.0.0`
- `files_automatedtagging` نسخه `5.0.0`

نصب معمول:

```bash
docker compose exec app php occ app:install files_retention
docker compose exec app php occ app:install files_automatedtagging
```

بررسی:

```bash
docker compose exec app php occ app:list | grep -E 'files_retention|files_automatedtagging'
```

اگر App Store به علت محدودیت شبکه یا Timeout در دسترس نبود، نسخه رسمی سازگار با Nextcloud 34 باید از منبع رسمی تهیه و پس از بررسی نسخه در `custom_apps` نصب شود.

## 14. ساخت Compliance Tag

```bash
docker compose exec app php occ tag:add "Archive Protected" restricted
docker compose exec app php occ tag:list
```

باید Tag زیر وجود داشته باشد:

```text
name: Archive Protected
access: restricted
```

سپس از Browser روی یک فایل آزمایشی Tag را اعمال و نتیجه را بررسی کنید.

## 15. Automated Tagging Rule

از مسیر زیر در Nextcloud:

`Administration settings -> Workflow -> Automated tagging`

Rule باید فقط محدوده واقعی آرشیو را هدف قرار دهد و در Action، Tag `Archive Protected` را اعمال کند.

ابتدا روی حساب/پوشه آزمایشی اجرا شود و سپس به داده عملیاتی تعمیم داده شود.

## 16. Retention Policy

پس از اطمینان از Automated Tagging، Retention Policy برای فایل‌های دارای `Archive Protected` تعریف شود. مدت نگهداری باید دقیقاً از سیاست رسمی سازمان گرفته شود؛ این Runbook عمداً عدد ثابتی تعیین نمی‌کند.

تا قبل از تأیید Compliance و تست کنترل‌شده، Cleanup روی داده واقعی فعال نشود.

## 17. End-to-End Test

حداقل این موارد ثبت و تأیید شوند:

1. ایجاد فایل آزمایشی در محدوده آرشیو
2. اعمال خودکار `Archive Protected`
3. بررسی محدودیت تغییر Tag توسط کاربر عادی
4. بررسی وضعیت/تاریخ Retention
5. ثبت زمان و نتیجه تست
6. تأیید مالک سامانه/Compliance

## 18. Step 7 - Audit Logging

پس از تکمیل Step 6:

```bash
docker compose exec app php occ app:enable admin_audit
```

سپس رخدادهای حساس و سیاست نگهداری لاگ تست شوند.

## 19. Step 8 - SSL/TLS

برای Production، HTTPS باید روی Nginx فعال شود و گواهی/تمدید آن مدیریت شود. پس از فعال‌سازی HTTPS، `trusted_proxies`، `overwriteprotocol` و URLهای Nextcloud مجدداً تست شوند.

## 20. Step 9 - Backup/Disaster Recovery

Backup باید حداقل شامل این موارد باشد:

- PostgreSQL database
- Nextcloud data directory
- Nextcloud configuration
- `custom_apps` در صورت نیاز
- فایل‌های Repository مانند `docker-compose.yml` و `nginx/default.conf` از Git قابل بازیابی‌اند

Restore Test دوره‌ای الزامی است؛ Backup بدون Restore Test راهکار DR کامل محسوب نمی‌شود.

## 21. Step 10 - Scheduled Cleanup & Reporting

پس از تثبیت Retention و Audit، Job زمان‌بندی‌شده برای گزارش وضعیت Retention، خطاها و موارد مشمول Cleanup ایجاد شود. Agent گزارش‌دهنده نباید بدون مجوز، داده عملیاتی را حذف کند.

## 22. چک‌لیست تحویل

- [ ] OS و Docker سالم
- [ ] Repository و Commit تأییدشده
- [ ] `.env` ساخته شده و خارج از Git است
- [ ] `archive_db`, `archive_app`, `archive_proxy` Up هستند
- [ ] Nginx پاسخ HTTP مناسب می‌دهد
- [ ] Nextcloud version/status تأیید شده
- [ ] LDAP در صورت نیاز متصل و تست شده
- [ ] Service Account و Token با حداقل دسترسی آماده است
- [ ] WebDAV/API Upload تست شده
- [ ] Nginx برای فایل حجیم بررسی شده
- [ ] Retention App فعال است
- [ ] Automated Tagging App فعال است
- [ ] `Archive Protected` به‌صورت Restricted ساخته شده
- [ ] Automated Tagging Rule تست و تأیید شده
- [ ] Retention Policy تست شده
- [ ] Audit Logging فعال و بررسی شده
- [ ] HTTPS فعال و تست شده
- [ ] Backup و Restore Test انجام شده

## 23. نکات حیاتی

- هیچ Volume یا داده‌ای بدون Backup و تأیید حذف نشود.
- رمزها و Tokenها هرگز در Git ذخیره نشوند.
- تغییرات حساس ابتدا در محیط تست انجام شوند.
- هر Step فقط پس از تست موفق تکمیل اعلام شود.
- پس از هر Step تکمیل‌شده، `PROJECT_STATE.md` به‌روزرسانی و Commit/Push شود.

## 24. تفکیک Clean Deployment و Migration

`git clone + docker compose` یک نمونه جدید با همان Software State می‌سازد.

برای انتقال دقیق Instance موجود همراه با فایل‌ها، کاربران، دیتابیس، Tagها و تنظیمات، باید Backup/Restore انجام شود و این کار به‌عنوان Migration با Maintenance Window برنامه‌ریزی گردد.
