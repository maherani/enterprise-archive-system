# Enterprise Archive System - Deployment Runbook & Bare-Metal Setup Guide

این سند راهنمای جامع و مرجع عملیاتی نصب، پیکربندی، استقرار از صفر (روی سرور خام) و نگهداری سامانه آرشیو اسناد سازمانی است. تمامی گام‌ها بر اساس آخرین وضعیت مخزن گیت (`main`)، ماژول بومی `archive_autotag v1.3.4`، فیلتر پیشرفته چندتگی و مکانیزم‌های حاکمیت داده تدوین شده‌اند.

---

## ۱. معماری کلی سیستم

```text
[ External Clients / AI Agents / WebDAV API / Web Browser ]
                            │
                        HTTP :80 (یا 443 SSL)
                            ▼
                   ┌──────────────────┐
                   │  archive_proxy   │  Nginx Alpine (Reverse Proxy)
                   │    Port 80:80    │  - client_max_body_size 10G
                   └────────┬─────────┘  - request_buffering off
                            │ (archive_net bridge)
                            ▼
                   ┌──────────────────┐
                   │   archive_app    │  Nextcloud 34 Apache
                   │   Internal :80   │  - WebDAV Endpoint: /remote.php/dav/files/
                   └────────┬─────────┘  - Custom App: archive_autotag v1.3.4
                            │            - Dynamic Hierarchical Auto-Tagging
                            │            - Multi-Tag Intersection Filter (AND)
                            │            - Granular Per-User Upload Limit
                            │            - Admin-Only Folder Creation Policy
                            │            - Strict Account Governance (Admin-only deletion)
                            ▼
                   ┌──────────────────┐
                   │    archive_db    │  PostgreSQL 15 Alpine
                   │   Internal :5432 │  - Database: nextcloud
                   └──────────────────┘
```

> [!IMPORTANT]
> **جداسازی کامل شبکه (Network Isolation):**
> دسترسی خارجی به سامانه منحصراً از طریق Nginx انجام می‌شود. پورت پایگاه‌داده PostgreSQL (5432) و پورت داخلی Nextcloud به هیچ وجه نباید مستقیماً در شبکه عمومی منتشر شوند.

---

## ۲. استقرار از صفر روی یک سرور خام (Bare Server Deployment)

این بخش برای کارشناس زیرساخت یا DevOps طراحی شده است که یک سرور خام (مثلاً Ubuntu Server 22.04 یا 24.04 LTS تازه نصب شده) در اختیار دارد.

### گام ۰: آماده‌سازی اولیه سیستم‌عامل خام
با کاربر دارای دسترسی `sudo` به سرور SSH بزنید:

```bash
# به‌روزرسانی مخازن لینوکس و نصب ابزارهای پایه
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y curl git jq ca-certificates gnupg ufw

# نصب رسمی Docker Engine و Docker Compose Plugin
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER

# فعال‌سازی سرویس داکر
sudo systemctl enable --now docker

# اعمال عضویت در گروه داکر (یا خروج و ورود مجدد به SSH)
newgrp docker
```

بررسی صحت نصب:
```bash
docker --version
docker compose version
```

### گام ۱: دریافت پروژه از مخزن گیت
```bash
cd ~
git clone https://github.com/maherani/enterprise-archive-system.git
cd ~/enterprise-archive-system
git status
```

### گام ۲: ساخت فایل متغیرهای محیطی (`.env`)
پروژه شامل فایل الگوی استاندارد `.env.example` است:

```bash
cp .env.example .env
nano .env
```

مقادیر امنیتی زیر را با رمزهای عبور قوی تنظیم فرمایید:

```env
# Database Configuration
POSTGRES_DB=nextcloud
POSTGRES_USER=nextcloud_user
POSTGRES_PASSWORD=YourStrongDatabasePassword_Secure123!

# Nextcloud Primary Administrator Configuration
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=YourStrongAdminPassword_Secure123!

# Nextcloud Trusted Domains (آدرس IP سرور یا دامنه سازمانی را اضافه فرمایید)
NEXTCLOUD_TRUSTED_DOMAINS=localhost 127.0.0.1 192.168.1.100 archive.organization.local
```

> [!CAUTION]
> فایل `.env` حاوی کلمات عبور حیاتی است. این فایل به صورت پیش‌فرض در `.gitignore` قرار دارد و هرگز نباید در گیت کامیت شود.

---

### گام ۳: روش‌های استقرار

#### 🚀 روش اول (توصیه‌شده): استقرار تمام‌خودکار با یک دستور
اسکریپت `deploy/deploy_from_scratch.sh` تمامی مراحل راه‌اندازی، انتظار برای سلامت پایگاه‌داده، نصب بدون نیاز به مداخله (Headless) نکست‌کلود، کپی و فعال‌سازی ماژول بومی `archive_autotag v1.3.4`، تنظیم پراکسی معتمد و بررسی سلامت را خودکار انجام می‌دهد:

```bash
./deploy/deploy_from_scratch.sh
```

خروجی نهایی اسکریپت تاییدیه اجرای موفق و سلامت ۱۰۰٪ سیستم به همراه اطلاعات دسترسی را نمایش می‌دهد.

---

#### 🛠️ روش دوم: استقرار دستی گام‌به‌گام (Manual Step-by-Step)
در صورت نیاز به اجرای دستی فرآیند توسط ادمین سیستم:

1. **اجرای کانتینرها:**
   ```bash
   docker compose up -d
   docker compose ps
   ```

2. **بررسی آمادگی پایگاه‌داده:**
   ```bash
   docker exec archive_db pg_isready -U nextcloud_user -d nextcloud
   ```

3. **نصب هسته Nextcloud (در صورت عدم نصب خودکار):**
   ```bash
   docker exec -u www-data archive_app php occ maintenance:install \
       --database "pgsql" \
       --database-name "nextcloud" \
       --database-user "nextcloud_user" \
       --database-pass "<POSTGRES_PASSWORD>" \
       --database-host "db" \
       --admin-user "admin" \
       --admin-pass "<NEXTCLOUD_ADMIN_PASSWORD>"
   ```

4. **تنظیم دامنه‌ها و پراکسی معتمد:**
   ```bash
   docker exec -u www-data archive_app php occ config:system:set trusted_domains 1 --value="localhost"
   docker exec -u www-data archive_app php occ config:system:set trusted_domains 2 --value="127.0.0.1"
   docker exec -u www-data archive_app php occ config:system:set trusted_domains 3 --value="<SERVER_IP>"
   docker exec -u www-data archive_app php occ config:system:set trusted_proxies 0 --value="172.16.0.0/12"
   docker exec -u www-data archive_app php occ config:system:set default_phone_region --value="IR"
   ```

5. **استقرار ماژول بومی `archive_autotag`:**
   ```bash
   docker exec archive_app mkdir -p /var/www/html/custom_apps/archive_autotag
   docker cp apps/archive_autotag/. archive_app:/var/www/html/custom_apps/archive_autotag/
   docker exec archive_app chown -R www-data:www-data /var/www/html/custom_apps/archive_autotag
   docker exec -u www-data archive_app php occ app:enable archive_autotag
   docker exec -u www-data archive_app php occ upgrade
   ```

6. **فعال‌سازی سیاست حاکمیت ساختار پوشه‌ها:**
   ```bash
   docker exec -u www-data archive_app php occ archive:folder:policy enable
   ```

7. **بررسی سلامت نهایی سامانه:**
   ```bash
   ./deploy/check_health.sh
   ```

---

## ۳. حاکمیت پوشه‌ها و محدودسازی دسترسی کاربران (Folder Governance)

بر اساس سیاست‌های امنیتی آرشیو سازمانی:
1. **سهمیه فضای شخصی صفر (`0 B`):**
   کاربران عادی مجاز به آپلود پراکنده در ریشه شخصی نیستند:
   ```bash
   docker exec -u www-data archive_app php occ user:setting <username> files quota 0
   ```
2. **ساخت دایرکتوری‌های سازمانی توسط ادمین:**
   ساختار بایگانی منحصراً در ریشه `/Enterprise_Archive` توسط ادمین مدیریت می‌شود:
   ```bash
   docker exec -u www-data archive_app php occ files:mkdir "/admin/files/Enterprise_Archive"
   ```
3. **تنظیم دسته‌ای سهمیه اعضای گروه (`deploy/set-group-quota.sh`):**
   ```bash
   # صفر کردن سهمیه دیسک برای تمام کاربران گروه SOC
   ./deploy/set-group-quota.sh SOC "0 B"

   # اعمال سهمیه برای گروه Compliance_Unit
   ./deploy/set-group-quota.sh Compliance_Unit "0 B"
   ```

---

## ۴. مدیریت حاکمیت حساب‌های کاربری و مرزبندی نقش‌ها (User Governance)

| سطح کاربری | ایجاد حساب | ویرایش اعضای گروه خود | ویرایش اعضای سایر گروه‌ها | حذف حساب کاربری |
| :--- | :---: | :---: | :---: | :---: |
| **ادمین ارشد سامانه (`admin`)** | ✔️ مجاز | ✔️ مجاز | ✔️ مجاز | ✔️ **منحصراً مجاز** |
| **مدیر گروه (`Subadmin`)** | ✔️ در گروه خود | ✔️ در گروه خود | ❌ مسدود (۴۰۳) | ❌ **مسدود قطعی (۴۰۳ Forbidden)** |
| **کاربر عادی** | ❌ مسدود | ❌ مسدود | ❌ مسدود | ❌ مسدود |

### ممیزی فوری نقش‌ها با اسکریپت (`deploy/audit_user_roles.sh`):
```bash
./deploy/audit_user_roles.sh
```

- سلب دسترسی ادمین از یک کاربر عادی:
  ```bash
  docker exec -u www-data archive_app php occ group:removeuser admin <username>
  ```
- تعیین یک کاربر به عنوان مدیر گروه (Group Admin):
  ```bash
  docker exec archive_db psql -U nextcloud_user -d nextcloud -c \
    "INSERT INTO oc_group_admin (gid, uid) VALUES ('<group_name>', '<username>') ON CONFLICT DO NOTHING;"
  ```

---

## ۵. تگ‌گذاری خودکار داینامیک و فیلتر همپوشانی چندتگی (`archive_autotag v1.3.4`)

### ۱. الصاق خودکار تگ‌های سیستمی:
هنگام آپلود هر سند، تمامی پوشه‌های والد به صورت تگ‌های سیستمیِ محافظت‌شده (`restricted`) استخراج و الصاق می‌شوند.

### ۲. فیلتر همپوشانی چند برچسب (Multi-Tag Intersection):
- در وب‌پنل فایل‌ها، نوار **🏷️ فیلتر پیشرفته برچسب‌های اسناد** تعبیه شده است.
- کاربران با انتخاب همزمان چندین تگ (مثلاً `افتا` + `الزامات امنیتی`)، اسناد را با منطق اشتراک ریاضی (`AND`) فیلتر می‌کنند.
- نمایش اطلاعات مسیر، حجم، تگ‌های مرتبط، دکمه «📂 مشاهده در پوشه» و دکمه «⬇️ دانلود».
- کنترل دقیق سطح دسترسی (کاربر فقط اسناد مجاز را می‌بیند).

### ۳. مدیریت برچسب‌ها و تگ‌گذاری مجدد از طریق CLI:
```bash
# اسکن و تگ‌گذاری مجدد تمامی فایل‌ها
docker exec -u www-data archive_app php occ archive:retag

# مدیریت وضعیت خط‌مشی پوشه‌سازی
docker exec -u www-data archive_app php occ archive:folder:policy status

# تنظیم سقف حجم فایل آپلودی برای هر کاربر (مثلاً حداکثر 20MB)
docker exec -u www-data archive_app php occ archive:user:limit archive_user1 20M
```

---

## ۶. پایداری داده‌ها، پشتیبان‌گیری و بازیابی بحران (`deploy/`)

### پشتیبان‌گیری آنی (`deploy/backup_db.sh`):
```bash
./deploy/backup_db.sh
```
فایل پشتیبان به همراه برچسب زمانی در `deploy/backups/db_backup_YYYYMMDD_HHMMSS.sql` ذخیره می‌شود.

### بازیابی دیتابیس در شرایط بحران (`deploy/restore_db.sh`):
```bash
# بازیابی از آخرین فایل بکاپ
./deploy/restore_db.sh

# بازیابی از فایل مشخص
./deploy/restore_db.sh deploy/backups/db_backup_20260911_184332.sql
```

### پایش جامع سلامت سامانه (`deploy/check_health.sh`):
```bash
./deploy/check_health.sh
```

> [!CAUTION]
> هرگز از دستور `docker compose down -v` استفاده نکنید. سوییچ `-v` باعث حذف کامل والیوم‌ها و دیتای پایگاه‌داده می‌گردد.

---

## ۷. آزمون‌های خودکار جامع (Automated Test Suites)

مخزن پروژه شامل ۳ سوئیت آزمون جامع پایتون برای صحه‌گذاری فنی تمامی قابلیت‌ها است:

```bash
# فعال‌سازی محیط تست
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# ۱. آزمون جامع تگ‌گذاری سلسله‌مراتبی، سقف حجم و سیاست پوشه‌ها
python3 tests/test_dynamic_archive_system.py

# ۲. آزمون مرزبندی حاکمیت حساب‌های کاربری و ایزولاسیون مدیر گروه
python3 tests/test_user_governance.py

# ۳. آزمون فیلتر همپوشانی چندتگی اسناد (Multi-Tag Intersection) و ACL
python3 tests/test_multi_tag_filter.py
```

تمامی آزمون‌ها باید با موفقیت ۱۰۰٪ پاس شوند.
