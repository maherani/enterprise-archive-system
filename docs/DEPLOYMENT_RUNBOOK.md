# Enterprise Archive System - Deployment Runbook

این سند راهنمای اجرایی نصب، پیکربندی و راه‌اندازی سامانه آرشیو سازمانی برای کارشناس زیرساخت و DevOps است. مبنای آن وضعیت جاری Repository پروژه و ویژگی‌های پیاده‌سازی‌شده مدیریت متمرکز پوشه‌ها و تگ‌گذاری داینامیک است.

---

## 1. معماری

```text
[ External Clients / AI Agents / WebDAV API ]
                      |
                  HTTP :80
                      v
             +------------------+
             |  archive_proxy   |  Nginx Alpine (Reverse Proxy)
             |    Port 80:80    |  - client_max_body_size 10G
             +--------+---------+  - request_buffering off
                      | (archive_net bridge)
                      v
             +------------------+
             |   archive_app    |  Nextcloud Apache
             |   Internal :80   |  - WebDAV Endpoint: /remote.php/dav/files/
             +--------+---------+  - Custom App: archive_autotag (PSR-14 Event Engine)
                      |            - User Quota: 0 B (Admin Folder Governance)
                      v
             +------------------+
             |    archive_db    |  PostgreSQL 15 Alpine
             |   Internal :5432 |  - Database: nextcloud
             +------------------+
```

دسترسی خارجی فقط از طریق Nginx انجام می‌شود؛ سرویس‌های PostgreSQL و پورت داخلی Nextcloud به هیچ وجه نباید مستقیماً روی شبکه عمومی منتشر شوند.

---

## 2. پیش‌نیازها

- سیستم‌عامل Ubuntu Server 22.04 یا 24.04 LTS
- دسترسی `sudo` و اتصال پایدار شبکه جهت دریافت پکیج‌ها و ایمیج‌های داکر
- نصب Docker Engine و Docker Compose Plugin (v2)
- فضای دیسک کافی متناسب با ظرفیت آرشیو اسناد سازمانی و پایگاه داده PostgreSQL
- پورت 80 آزاد (در فاز Production، پورت 443 و گواهی SSL/TLS نیز الزامی است)
- تنظیم صحیح ساعت سیستم و NTP سرور

---

## 3. دریافت پروژه از مخزن گیت

```bash
cd ~
git clone https://github.com/maherani/enterprise-archive-system.git
cd ~/enterprise-archive-system
git status
```

شاخه اصلی `main` و Commit معتبر پروژه باید کنترل شود و Working Tree در وضعیت `clean` باشد.

---

## 4. ایجاد و تنظیم فایل متغیرهای محیطی (`.env`)

```bash
nano .env
```

نمونه پیکربندی استاندارد:

```env
# Database Configuration
POSTGRES_DB=nextcloud
POSTGRES_USER=nextcloud_user
POSTGRES_PASSWORD=<STRONG_DB_PASSWORD>

# Nextcloud Admin Configuration
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=<STRONG_ADMIN_PASSWORD>
NEXTCLOUD_TRUSTED_DOMAINS=localhost 127.0.0.1 <SERVER_IP_OR_FQDN>
```

> [!CAUTION]
> رمزهای عبور واقعی باید توسط مسئول امنیت/بهره‌برداری تعیین شوند. فایل `.env` حاوی اطلاعات محرمانه است و هرگز نباید وارد Git، تیکت‌ها، پیام‌رسان‌ها یا اسکرین‌شات‌ها شود.

کنترل وضعیت گیت:

```bash
git status
```

باید فایل `.env` توسط `.gitignore` نادیده گرفته شده و وضعیت مخزن clean بماند.

---

## 5. راه‌اندازی کانتینرها با Docker Compose

```bash
docker compose up -d
docker compose ps
```

سرویس‌های زیر باید در وضعیت Healthy / Up باشند:
- `archive_db` (PostgreSQL 15)
- `archive_app` (Nextcloud Apache)
- `archive_proxy` (Nginx Alpine)

> [!WARNING]
> هرگز برای رفع خطاهای موقت از دستور `docker compose down -v` یا حذف دستی دایرکتوری‌های `db/` و `nextcloud/` استفاده نکنید زیرا داده‌ها و متادیتا پاک خواهند شد.

---

## 6. اعتبارسنجی Nginx و سرویس وب

```bash
curl -I http://localhost
```

پاسخ `HTTP 200` یا ریدایرکت `HTTP 302` نشان‌دهنده در دسترس بودن سرویس است. همچنین از طریق مرورگر، آدرس `http://<SERVER_IP_OR_FQDN>` را باز کرده و صفحه ورود Nextcloud را بررسی نمایید.

---

## 7. بررسی وضعیت هسته Nextcloud

```bash
docker compose exec app php occ status
```

خروجی مورد انتظار:
- `installed: true`
- `version: 34.0.3.2`
- `maintenance: false`

---

## 8. بررسی مسیرهای نصب برنامه‌ها (`apps_paths`)

```bash
docker compose exec app php occ config:system:get apps_paths
```

مسیرهای استاندارد پیکربندی‌شده:
```text
0: /var/www/html/apps (Read-only)
1: /var/www/html/custom_apps (Writable)
```

دایرکتوری `custom_apps` جهت استقرار ماژول‌های بومی و اپلیکیشن‌های سفارشی استفاده می‌شود.

---

## 9. یکپارچه‌سازی با سامانه دایرکتوری سازمانی (LDAP / Active Directory)

در محیط‌های سازمانی متصل به اکتیودایرکتوری یا OpenLDAP:

```bash
docker compose exec app php occ app:enable user_ldap
```

سپس مقادیر Base DN، Bind DN، پورت (389 یا 636) و فیلترهای ورود و گروه‌ها طبق شناسنامه هویتی سازمان تنظیم و تست اتصال انجام گیرد.

---

## 10. ساختار حاکمیت پوشه‌ها توسط ادمین (Admin-Only Folder Governance)

بر اساس خط‌مشی امنیت داده‌های آرشیو، کاربران عادی نباید بتوانند پوشه‌های دلخواه در ریشه شخصی ایجاد کنند یا ساختار آرشیو را تغییر دهند:

### ۱. محدودسازی سهمیه دیسک شخصی کاربران به صفر:
برای کاربران استاندارد آرشیو (مانند `archive_user1` یا کل گروه کاربران):
```bash
docker compose exec app php occ user:setting <username> files quota 0
```
با این تنظیم، تلاش کاربر برای آپلود یا ساخت پوشه در فضای شخصی با خطای `HTTP 507 (Insufficient Storage)` مسدود می‌شود.

### ۲. ساخت پوشه‌های سازمانی توسط ادمین:
پوشه‌های اصلی آرشیو منحصراً توسط ادمین در وب یا از طریق خط فرمان ایجاد می‌شوند:
- `/Enterprise_Archive/Finance/2026/Invoices`
- `/Enterprise_Archive/Legal/Contracts`
- `/Enterprise_Archive/Technical/Blueprints`

### ۳. اشتراک‌گذاری پوشه‌ها با گروه‌ها با دسترسی‌های کنترل‌شده:
ادمین پوشه ریشه `/Enterprise_Archive` را با گروه دسترسی مربوطه (مثلاً `Compliance_Unit`) به اشتراک می‌گذارد:
- مجوزهای مجاز: **مشاهده (Read)، ایجاد فایل (Create)، ویرایش (Update)**
- مجوزهای غیرمجاز: **حذف پوشه ریشه (Delete Root) و اشتراک مجدد (Reshare)**

بدین ترتیب کاربران صرفاً درون پوشه‌های هدایت‌شده توسط ادمین امکان آپلود دارند.

### ۴. تنظیم دسته‌ای سهمیه دیسک اعضای یک گروه (`deploy/set-group-quota.sh`):
جهت اعمال سریع سهمیه دیسک شخصی (مانند `0 B` یا سهمیه اختصاصی) برای تمام اعضای یک گروه سازمانی (مانند `SOC` یا `Compliance_Unit`)، اسکریپت خودکار زیر در مسیر `deploy/` توسعه داده شده است:
```bash
# تنظیم سهمیه صفر برای تمامی اعضای گروه SOC
./deploy/set-group-quota.sh SOC "0 B"

# تنظیم سهمیه صفر برای اعضای گروه Compliance_Unit
./deploy/set-group-quota.sh Compliance_Unit "0 B"

# تنظیم سهمیه سفارشی (مثلاً ۵ گیگابایت) برای یک گروه دیگر
./deploy/set-group-quota.sh Finance "5 GB"
```
این اسکریپت لیست اعضای گروه را از طریق خروجی JSON فرمان Nextcloud OCC دریافت کرده و سهمیه دیسک تک‌تک اعضا را به صورت خودکار اعمال می‌نماید.

---

## 11. استقرار و فعال‌سازی ماژول تگ‌گذاری داینامیک (`archive_autotag`)

پروژه شامل یک اپلیکیشن بومی سبک و بدون وابستگی خارجی به نام `archive_autotag` است که در مسیر `apps/archive_autotag` مخزن گیت قرار دارد.

### ۱. کپی سورس ماژول به دایرکتوری `custom_apps`:
در صورتی که برنامه هنوز در کانتینر مستقر نشده باشد:
```bash
docker compose exec app mkdir -p custom_apps/archive_autotag
docker cp apps/archive_autotag/. archive_app:/var/www/html/custom_apps/archive_autotag/
docker compose exec app chown -R www-data:www-data /var/www/html/custom_apps/archive_autotag
```

### ۲. فعال‌سازی اپلیکیشن در Nextcloud:
```bash
docker compose exec app php occ app:enable archive_autotag
```

خروجی تایید:
```text
archive_autotag 1.0.0 enabled
```

---

## 12. نحوه کارکرد تگ‌گذاری داینامیک و سلسله‌مراتبی (Hierarchical Auto-Tagging)

ماژول `archive_autotag` با استفاده از شنوندگان رویدادهای هسته Nextcloud (PSR-14 Event Dispatcher) به صورت آنی و خودکار عمل می‌کند:

1. **رویداد آپلود فایل (`NodeCreatedEvent` و `NodeWrittenEvent`):**
   - به محض بارگذاری یک فایل توسط کاربر یا سیستم از طریق Web UI یا WebDAV API، سلسله‌مراتب کامل پوشه‌های والد استخراج می‌شود.
   - مثال: برای فایل در مسیر `/Enterprise_Archive/Finance/2026/Invoices/doc.pdf`، تگ‌های `Enterprise_Archive`، `Finance`، `2026` و `Invoices` به صورت داینامیک ایجاد و به فایل الصاق می‌شوند.
2. **محافظت از تگ‌های سیستمی (`Restricted Tags`):**
   - تمامی تگ‌های والد به صورت سیستمی و با ویژگی **Restricted** (`userAssignable=false` و `userVisible=true`) ساخته می‌شوند.
   - کاربران عادی این تگ‌ها را می‌بینند و می‌توانند اسناد را بر اساس آن‌ها فیلتر کنند، اما دکمه حذف ندارند و هرگونه تلاش مستقیم از طریق API با خطای `HTTP 403 Forbidden` متوقف می‌شود.
3. **تگ‌گذاری مشارکتی توسط کاربران مجاز:**
   - کاربران مجاز می‌توانند علاوه بر تگ‌های والد، تگ‌های عمومی دلخواه (`public`) را به فایل‌ها اضافه یا از آن‌ها حذف نمایند.
4. **انتشار تغییرات تغییر نام پوشه (`NodeRenamedEvent`):**
   - هرگاه ادمین نام یک پوشه را تغییر دهد (مثلاً از `Invoices` به `Invoices_Archive`)، تگ قبلی به صورت خودکار از تمام اسناد فرزند حذف و تگ جدید به آن‌ها الصاق می‌گردد.

---

## 13. مدیریت سطوح دسترسی تگ‌ها توسط ادمین (CLI)

ادمین سیستم می‌تواند تگ‌ها را با هر سه سطح دسترسی استاندارد مدیریت کند:

```bash
# ایجاد تگ عمومی (قابل مشاهده و ویرایش توسط تمام کاربران)
docker compose exec app php occ tag:add "Public_Tag_Name" public

# ایجاد تگ حفاظت‌شده سیستمی (قابل مشاهده، اما غیرقابل حذف توسط کاربران عادی)
docker compose exec app php occ tag:add "System_Protected_Tag" restricted

# ایجاد تگ مخفی (نامرئی برای کاربران عادی)
docker compose exec app php occ tag:add "Internal_Audit_Tag" invisible

# لیست تمامی تگ‌های موجود در سیستم
docker compose exec app php occ tag:list
```

---

## 14. دستور خط فرمان اسکن و تگ‌گذاری دسته‌ای (`occ archive:retag`)

برای فایل‌ها یا پوشه‌هایی که قبل از نصب ماژول آپلود شده‌اند، از دستور اختصاصی زیر برای بازخوانی سلسله‌مراتب و تگ‌گذاری دسته‌ای استفاده کنید:

```bash
# تگ‌گذاری مجدد برای تمامی کاربران سیستم
docker compose exec app php occ archive:retag

# تگ‌گذاری مجدد فقط برای یک حساب کاربری خاص
docker compose exec app php occ archive:retag admin
```

---

## 15. تنظیم سقف حجم آپلود فایل برای هر کاربر توسط ادمین (`occ archive:user:limit`)

ادمین سیستم می‌تواند حداکثر حجم مجاز برای آپلود هر فایل را به تفکیک هر کاربر در جدول امن تنظیمات کاربر ذخیره کند. این محدودیت در لایه WebDAV/Storage بررسی شده و در صورت ارسال فایل بزرگتر از سقف مجاز، آپلود بلافاصله با کد خطای `HTTP 403 Forbidden` متوقف و رد می‌شود.

```bash
# تنظیم سقف حجم فایل (مثال: حداکثر 10 مگابایت برای archive_user1)
docker compose exec app php occ archive:user:limit archive_user1 10M

# نمونه‌های دیگر با واحدهای مختلف (K, M, G)
docker compose exec app php occ archive:user:limit archive_user1 500M
docker compose exec app php occ archive:user:limit archive_user1 2G

# حذف سقف محدودیت حجم برای کاربر (نامحدودسازی)
docker compose exec app php occ archive:user:limit archive_user1 0

# استعلام سقف فعلی یک کاربر
docker compose exec app php occ archive:user:limit archive_user1

# نمایش جدول تمام کاربران دارای سقف حجم سفارشی
docker compose exec app php occ archive:user:limit --list
```

---

## 16. اجرای تست‌های خودکار جامع انتها-به-انتها (E2E Verification)

مخزن شامل اسکریپت تست کامل `tests/test_dynamic_archive_system.py` است که هر ۶ نیازمندی حاکمیت و تگ‌گذاری را بررسی می‌کند:

```bash
# فعال‌سازی محیط مجازی پایتون
source venv/bin/activate

# اجرای سوئیت آزمون
python tests/test_dynamic_archive_system.py
```

گام‌های اعتبارسنجی اسکریپت:
1. بررسی مسدود بودن آپلود کاربر در فضای شخصی (`HTTP 507/403`).
2. آپلود سند در ساختار سلسله‌مراتبی ادمین و بررسی دریافت پاسخ موفق (`HTTP 201/204`).
3. بازخوانی تگ‌های فایل و تایید الصاق خودکار تمام تگ‌های سلسله‌مراتب پوشه‌های والد.
4. تلاش کاربر برای حذف تگ والد و تایید دریافت خطای `HTTP 403 Forbidden`.
5. الصاق و حذف تگ عمومی توسط کاربر و تایید عملکرد موفق.
6. تغییر نام پوشه توسط ادمین و تایید تعویض خودکار تگ تمام اسناد درون آن.

نتیجه نهایی باید پیام `7. بررسی کنترل سقف حجم آپلود کاربر (موفقیت آپلود فایل ۱ مگابایتی و رد قطعی فایل ۱۲ مگابایتی با خطای `HTTP 403 Forbidden` برای کاربری با سقف ۱۰ مگابایت).

نتیجه نهایی باید پیام `ALL 6 REQUIREMENTS VERIFIED AND PASSED SUCCESSFULLY!` باشد.` باشد.

---


---

## 17. مدیریت حاکمیت حساب‌های کاربری و مرزبندی نقش‌ها (User Account Governance)

بر اساس سیاست‌های امنیتی آرشیو سازمانی، سطوح دسترسی روی حساب‌های کاربری به‌صورت سخت‌گیرانه مرزبندی و قفل شده است:

### ۱. مرزبندی نقش‌ها و ماتریس دسترسی:
| نقش کاربری | ایجاد کاربر | ویرایش اعضای گروه خود | ویرایش کاربران سایر گروه‌ها | حذف کاربر |
| :--- | :---: | :---: | :---: | :---: |
| **ادمین کل سیستم (`admin`)** |  مجاز |  مجاز |  مجاز |  مجاز |
| **مدیر گروه (`Group Admin / Subadmin`)** |  مجاز (در گروه خود) |  مجاز | ❌ مسدود (۴۰۳/۹۹۸) | ❌ **مسدود قطعی (۴۰۳ Forbidden)** |
| **کاربر عادی استاندارد** | ❌ مسدود | ❌ مسدود | ❌ مسدود | ❌ مسدود |

> [!IMPORTANT]
> **قفل‌گذاری حذف کاربر برای ادمین کل:**
> لیسنر بومی `BeforeUserDeletedListener` در ماژول `archive_autotag` رویداد حذف کاربر را رهگیری کرده و چنانچه کاربری غیر از ادمین کل (مانند مدیر گروه) اقدام به حذف اکانت نماید، بلافاصله خطای `403 Forbidden` با پیام زیر برمی‌گرداند:
> `"User deletion is strictly restricted to system administrators. Group administrators may only modify group members."`

### ۲. پایش و پاک‌سازی نقش‌ها با اسکریپت (`deploy/audit_user_roles.sh`):
جهت ممیزی فوری تمام کاربران، شناسایی کاربرانی که تصادفاً دسترسی ادمین پیدا کرده‌اند و رفع سریع آن:
```bash
./deploy/audit_user_roles.sh
```
* **سلب دسترسی ادمین از کاربر عادی:**
  ```bash
  docker compose exec app php occ group:removeuser admin <username>
  ```
* **تعیین کاربر به‌عنوان مدیر یک گروه مشخص (Subadmin):**
  ```bash
  docker exec archive_db psql -U nextcloud_user -d nextcloud -c     "INSERT INTO oc_group_admin (gid, uid) VALUES ('<group_name>', '<username>') ON CONFLICT DO NOTHING;"
  ```
* **لغو نقش مدیر گروه:**
  ```bash
  docker exec archive_db psql -U nextcloud_user -d nextcloud -c     "DELETE FROM oc_group_admin WHERE gid = '<group_name>' AND uid = '<username>';"
  ```

### ۳. آزمون خودکار اعتبارسنجی حاکمیت کاربران (`tests/test_user_governance.py`):
برای اطمینان از اعمال کامل مرزبندی‌های نقشی، سوئیت تست خودکار پایتون زیر اجرا می‌شود:
```bash
python3 tests/test_user_governance.py
```
این آزمون ۵ سناریوی کلیدی (اختیارات ادمین، ویرایش مجاز مدیر گروه، ایزولاسیون بین‌گروهی، ممنوعیت حذف برای مدیر گروه و عدم دسترسی کاربر عادی) را به صورت ایزوله تست و با موفقیت ۱۰۰٪ اعتبارسنجی می‌کند.

## 18. Step 7 - لاگ ممیزی امنیتی (`admin_audit`)

جهت ممیزی دسترسی به فایل‌ها، دانلودها، اشتراک‌گذاری‌ها و لاگین‌ها:

```bash
docker compose exec app php occ app:enable admin_audit
```

رویدادهای حساس ممیزی در مسیر `/var/www/html/data/audit.log` (یا لاگ سیستم) ثبت می‌شوند.

---

## 19. Step 8 - فعال‌سازی امنیتی SSL/TLS و HTTPS

در محیط Production، ترافیک پورت 80 باید به 443 هدایت شده و گواهی معتبر SSL/TLS (مانند Let's Encrypt یا گواهی سازمانی) بر روی Nginx پیکربندی شود:
1. قرار دادن گواهی در مسیر `nginx/certs/`.
2. تنظیم بلوک `server` روی پورت `443 ssl` در `nginx/default.conf`.
3. تنظیم پارامترهای `overwriteprotocol => 'https'` و `trusted_proxies` در Nextcloud.

---

## 20. Step 9 - پایداری داده‌ها، پشتیبان‌گیری خودکار و بازیابی بحران (`deploy/`)

جهت سهولت و پایداری قطعی اطلاعات سامانه، اسکریپت‌های عملیاتی در دایرکتوری `deploy/` تعبیه شده است:

### ۲.۱. پشتیبان‌گیری سریع از پایگاه داده (`deploy/backup_db.sh`)
تهیه نسخه پشتیبان با فرمت SQL و برچسب زمانی در مسیر `deploy/backups/`:
```bash
./deploy/backup_db.sh
```
این اسکریپت همچنین یک کپی در `deploy/backups/latest_db_backup.sql` نگهداری می‌کند.

### ۲.۲. بازگردانی پایگاه داده در شرایط بحران (`deploy/restore_db.sh`)
برای بازگردانی آنی پایگاه داده از آخرین نسخه یا فایل مشخص:
```bash
# بازگردانی از آخرین بکاپ موجود
./deploy/restore_db.sh

# یا بازگردانی از یک فایل مشخص
./deploy/restore_db.sh deploy/backups/db_backup_20260911_184332.sql
```
این اسکریپت نشست‌های باز را خاتمه داده، دیتابیس را بازسازی کرده، داده‌ها را ایمپورت کرده و قفل‌های معلق فایل را ریست می‌نماید.

### ۲.۳. ارزیابی جامع سلامت و بررسی پایداری (`deploy/check_health.sh`)
جهت مانیتورینگ وضعیت کانتینرها، شمارش کاربران دیتابیس، تطابق اعضا و یکپارچگی ساختار آرشیو:
```bash
./deploy/check_health.sh
```

> [!CAUTION]
> **ممنوعیت استفاده از دستور `docker compose down -v`:**
> هرگز از سوییچ `-v` استفاده نکنید، زیرا این سوییچ باعث حذف دیسک‌ها و داده‌های سامانه می‌شود. همیشه از `docker compose stop` یا `docker compose down` (بدون `-v`) استفاده نمایید.

> [!NOTE]
> **مستند تفصیلی:** جزئیات کامل تحلیل ریشه‌ای و معماری ذخیره‌سازی در مستند [docs/DATA_PERSISTENCE_AND_RELIABILITY.md](DATA_PERSISTENCE_AND_RELIABILITY.md) مدون شده است.

---

### ۲.۴. تنظیم دسته‌ای سهمیه کاربران یک گروه (`deploy/set-group-quota.sh`)
اعمال سهمیه دیسک شخصی (مثل `0 B`) برای کلیه کاربران عضو یک گروه:
```bash
./deploy/set-group-quota.sh SOC "0 B"
```

### ۲.۵. ممیزی و پالایش نقش‌ها و اعتبارات کاربری (`deploy/audit_user_roles.sh`)
شناسایی ادمین‌ها، مدیران گروه، کاربران عادی و رفع دسترسی‌های ادمین ناخواسته:
```bash
./deploy/audit_user_roles.sh
```

## 21. چک‌لیست تحویل سامانه به تیم بهره‌برداری

- [ ] سیستم‌عامل و سرویس داکر در وضعیت پایدار است.
- [ ] فایل `.env` پیکربندی شده و خارج از گیت است.
- [ ] کانتینرهای `archive_db`، `archive_app` و `archive_proxy` بدون خطا در حال اجرا هستند.
- [ ] تنطیمات Nginx برای فایل‌های حجیم (`client_max_body_size 10G` و `request_buffering off`) فعال است.
- [ ] ماژول بومی `archive_autotag` نصب و فعال است (`occ app:list`).
- [ ] سهمیه دیسک کاربران عادی روی `0 B` تنظیم شده است.
- [ ] ساختار پوشه‌های آرشیو توسط ادمین ساخته شده و با مجوزهای لازم به اشتراک گذاشته شده است.
- [ ] آزمون تست خودکار انتها-به-انتها (`tests/test_dynamic_archive_system.py`) با موفقیت ۱۰۰٪ پاس شده است.
- [ ] خطای 403 در صورت تلاش کاربر برای حذف تگ والد تایید شده است.
- [ ] انتشار خودکار تغییر نام پوشه به تگ فایل‌ها تست و تایید شده است.
- [ ] فرآیند پشتیبان‌گیری دوره‌ای و مستندات بازیابی مستقر شده است.

---

## 22. نکات حیاتی و الزامات نگهداشت

- هیچ Volume، Container یا کلاستری بدون پشتیبان‌گیری قبلی حذف یا دستکاری نشود.
- Secretها، Tokenها و رمزهای پایگاه داده هرگز نباید وارد مستندات عمومی یا مخزن گیت شوند.
- هرگونه به‌روزرسانی کدها ابتدا روی محیط تست اعتبارسنجی شده و سپس با ثبت لاگ در `PROJECT_STATE.md` اعمال گردد.


### 11. آزمایش و اعتبارسنجی فیلتر چندتگی اسناد (Multi-Tag Filter)
برای تست عملکرد منطق اشتراک چند تگ (`AND`)، تفکیک دسترسی و بازخورد کنترلر:
```bash
python3 tests/test_multi_tag_filter.py
```
