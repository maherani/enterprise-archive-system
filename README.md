# سامانه بایگانی اسناد سازمانی (Enterprise Archive System)
## مرجع رسمی و انحصاری اسناد، فراداده‌ها و مجوزهای سازمانی (System of Record)

**محل استقرار مخزن:** `/home/alborz/enterprise-archive-system`  
**فناوری‌های پایه:** Nextcloud Hub 26 (PHP 8.2)، پایگاه داده PostgreSQL 15، وب‌سرور Nginx Reverse Proxy  
**وضعیت:** عملیاتی، ایزوله، سخت‌گیرانه (Hardened) و یکپارچه‌شده با لایه هوش مصنوعی  

---

## ۱. معرفی و نقش سامانه در معماری کلان
سامانه Enterprise Archive به عنوان **تنها منبع موثق و رسمی اسناد (System of Record)** عمل می‌کند. تمامی فرآیندهای مربوط به بارگذاری اسناد، طبقه‌بندی دپارتمانی، اعمال مجوزهای امنیتی و ممیزی دسترسی‌ها منحصراً در این سامانه انجام می‌شود. هیچ سرویس بیرونی یا سامانه‌ای در هوش مصنوعی حق دستکاری مستقیم در فایل‌سیستم یا پایگاه داده این سیستم را ندارد.

---

## ۲. هسته سفارشی سازمانی (`apps/archive_autotag`)
تمامی منطق‌های تجاری سازمانی، امنیت داده‌ها و یکپارچه‌سازی با هوش مصنوعی در نرم‌افزار بومی `archive_autotag` پیاده‌سازی شده است:

### الف) شنوندگان رویدادهای چرخه حیات اسناد (Event Listeners):
1. **`NodeWrittenListener.php`:**
   - به محض بارگذاری یا ویرایش یک فایل در Nextcloud فعال می‌شود.
   - پسوند، حجم و ساختار فایل را بررسی کرده و در صورت احراز شرایط فایل‌های متنی (`.txt`, `.md`, `.json`, `.csv`, `.log`)، متد `recordFileEvent` را فراخوانی می‌کند.
   - رویداد را به صورت کاملاً اتمیک با وضعیت `PENDING` در جدول Outbox ثبت می‌نماید.
2. **`NodeDeletedListener.php`:**
   - حذف فیزیکی فایل‌ها را رهگیری می‌کند.
   - رویدادی با نوع `file.deleted` در جدول Outbox ثبت می‌نماید تا سند متناظر از پایگاه داده برداری (Vector DB) در AI Box به صورت خودکار پاکسازی گردد.
3. **`NodeRenamedListener.php`:**
   - جابجایی فایل بین پوشه‌ها یا تغییر نام فایل را رهگیری می‌کند.
   - رویدادی با نوع `file.moved` به همراه ثبت اسلاگ فضای کاری قبلی (`old_workspace_slug`) و فضای کاری جدید ثبت می‌کند تا جابجایی سند میان فضاهای کاری هوش مصنوعی محقق گردد.

### ب) سرویس‌های اصلی (Core Services):
1. **`AiOutboxService.php`:**
   - قلب تپنده الگوی Transactional Outbox در آرشیو.
   - بررسی واجد شرایط بودن فایل‌ها (فایل‌های باینری و بزرگتر از سقف مجاز ثبت نمی‌شوند).
   - حل‌کننده فضای حاکم بر سند (`resolveGoverningFolder`): تشخیص خودکار پوشه اصلی سازمانی تحت شاخه `Enterprise_Archive` یا تفکیک اسناد شخصی (`personal-{uid}`).
   - متد `getUserAccessibleWorkspaces`: استخراج بلادرنگ فضاهای مجاز کاربر بر پایه ارزیابی `CentralPermissionResolver`.
2. **`CentralPermissionResolver.php`:**
   - موتور یکپارچه و قطعی ارزیابی دسترسی سازمانی (Deny-by-Default).
   - استفاده از ماسک ۸-بیتی برای عملیات خواندن، نوشتن، حذف و اشتراک.
   - ارزیابی ترکیبی نقش کاربری، عضویت در گروه‌ها و دسترسی‌های تفویضی.
3. **`FileOwnershipService.php`:**
   - تفکیک قطعی مالکیت فایل‌ها و اعمال مجوزهای تفصیلی در جدول `oc_archive_file_grants`.
   - اعمال وراثت دسترسی پوشه والد و بازنشانی دسترسی‌های منقضی‌شده.
4. **`AiFileService.php`:**
   - احراز هویت درخواست‌های ماشینی با کلیدهای امنیتی Bearer Token.
   - ثبت دقیق جزئیات درخواست در جدول ممیزی `oc_archive_ai_audit`.

---

## ۳. کنترل‌کننده‌ها و اندپوینت‌های API

### الف) اندپوینت‌های Outbox و تفکیک دسترسی (`AiOutboxApiController.php`):
* **`GET /api/v1/acl/workspaces`:**
  - استعلام آنی فضاهای کاری مجاز برای کاربر جاری (از روی نشست یا توکن ماشینی).
  - خروجی شامل لیست فضاهای سازمانی (`folder-{id}`) و فضای شخصی کاربر (`personal-{uid}`).
* **`GET /api/v1/outbox/pending?limit=50`:**
  - استعلام دسته‌ای از رخدادهای معلق و پردازش‌نشده برای ارسال به پیام‌رسان RabbitMQ.
* **`POST /api/v1/outbox/ack`:**
  - دریافت تأییدیه انتشار پیام در RabbitMQ از سوی ناشر لایه میانی و ارتقای وضعیت رخداد به `PUBLISHED`.
* **`POST /api/v1/outbox/fail`:**
  - ثبت گزارش خطا و افزایش شمارنده تلاش‌های مجدد در صورت بروز مشکل در صف پیام.

### ب) اندپوینت‌های واکشی فایل برای هوش مصنوعی (`AiFileController.php`):
* **`GET /api/v1/ai/files/{fileId}`:**
  - استریم بایت‌به‌بایت فایل برای پراکسی لایه میانی با مصرف حافظه ثابت $O(1)$.
  - مجهز به کنترل کامل مجوز کاربر درخواست‌دهنده (`X-On-Behalf-Of`).
* **`GET /api/v1/ai/files/{fileId}/metadata`:**
  - بازگرداندن متادیتای پاکسازی‌شده فایل (شامل نام، هش SHA256، حجم و برچسب‌ها).
* **`GET /api/docs` و `GET /api/openapi.json`:**
  - رابط کاربری مستندسازی و تست تعاملی OpenAPI/Swagger به صورت ۱۰۰٪ آفلاین و بدون وابستگی به اینترنت.

---

## ۴. معماری شبکه و مسیریابی Nginx
وب‌سرور Nginx (`archive_proxy`) به عنوان لایه مرزی عمل کرده و پورت‌های استاندارد را مدیریت می‌کند:
* پورت `80` و `443`: دریافت ترافیک وب، فایل‌ها و API آرشیو.
* مسیریابی خودکار فراخوانی‌های چت در پرتال وب (`/api/workspace/...`) به درگاه گیت‌وی هوش مصنوعی (`http://127.0.0.1:3001`).

---

## ۵. ساختار کانتینرهای داکر
سامانه از سه کانتینر در شبکه ایزوله تشکیل شده است:
1. **`archive_proxy`:** سرور وب و پراکسی معکوس Nginx.
2. **`archive_app`:** کانتینر اصلی Nextcloud مبتنی بر PHP 8.2 FPM شامل هسته برنامه و `archive_autotag`.
3. **`archive_db`:** پایگاه داده PostgreSQL نسخه ۱۵ حاوی تمامی داده‌ها و متادیتاها.

```bash
cd /home/alborz/enterprise-archive-system

# راه‌اندازی کانتینرها:
docker compose up -d

# مشاهده لاگ برنامه:
docker compose logs -f app
```

---

## ۶. دستورات خط فرمان مدیریتی (CLI Commands)
مدیران سیستم می‌توانند با ابزار خط فرمان `occ` سامانه را مدیریت کنند:

```bash
# اعطای دسترسی به یک فایل برای کاربر یا گروه:
docker compose exec app php occ archive:file:grant grant <file_id> <username>
docker compose exec app php occ archive:file:grant grant <file_id> <group_name> --group

# سلب دسترسی:
docker compose exec app php occ archive:file:grant revoke <file_id> <username>

# مشاهده لیست دسترسی‌های یک فایل:
docker compose exec app php occ archive:file:grant list <file_id>

# بازبینی و رفع تعارض برچسب‌های سازمانی:
docker compose exec app php occ archive:tag:gov reconcile

# تعیین سقف حجم بارگذاری برای کاربر:
docker compose exec app php occ archive:user:limit archive_user1 10M
```

---

## ۷. آزمون‌های خودکار و راستی‌آزمایی (Automated Test Suites)
مجموعه کاملی از ۲۷ آزمون جامع برای تضمین صحت عملکرد سامانه تعبیه شده است:

```bash
# اجرای کل آزمون‌های سامانه آرشیو:
python3 run_all_tests.py
```
