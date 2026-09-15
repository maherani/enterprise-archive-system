# نیازمندی ۰۲: سیستم برچسب‌گذاری خودکار سلسله‌مراتبی (Hierarchical Auto-Tagging)

## ۱. شرح نیازمندی (Problem Statement & Business Need)
در سامانه‌های آرشیو اسناد، اتکا به کاربران برای دسته‌بندی و الصاق دستی برچسب به پرونده‌ها، منجر به خطای انسانی، شلختگی، تگ‌های سلیقه‌ای و در نهایت ناتوانی در بازیابی دقیق اسناد می‌شود. بنابراین، نیازمندی کسب‌وکار سازمان ایجاب می‌کرد که سامانه بر اساس ساختار درختی پوشه‌های آرشیو (به عنوان مثال: `Enterprise_Archive/SOC/1403/Reports/file.pdf`)، تمامی بخش‌های مسیر را تجزیه کرده و برچسب‌های سیستمی معادل (مانند `SOC`, `1403`, `Reports`) را به صورت خودکار و در لحظه ایجاد فایل تولید و به سند الصاق نماید. همچنین هنگام جابه‌جایی یا تغییر نام پوشه‌ها، تگ‌ها باید به صورت خودکار اصلاح شوند.

## ۲. نیازمندی‌های تابعی (Functional Requirements)
* **تحلیل خودکار مسیر پوشه‌ها:** استخراج نام پوشه‌های موجود در مسیر فایل از دایرکتوری پایه آرشیو (`Enterprise_Archive`).
* **ایجاد خودکار تگ‌های سیستمی:** ساخت خودکار برچسب در جدول `oc_systemtag` در صورت عدم وجود برچسب در سامانه.
* **الصاق خودکار برچسب‌ها به فایل:** برقراری ارتباط بین شناسه فایل و تگ‌های استخراج‌شده در جدول `oc_systemtag_object_mapping`.
* **پشتیبانی از چرخه حیات فایل:** همگام‌سازی تگ‌ها در زمان انتقال سند (`NodeRenamedEvent`)، بازنویسی سند (`NodeWrittenEvent`) و پاک‌سازی در زمان حذف (`NodeDeletedEvent`).
* **دستورات خط فرمان (CLI Tools):** فرامین `occ archive:retag` برای بازتولید کلیه برچسب‌های آرشیو و `occ archive:tag:reconcile` جهت اصلاح و پالایش مغایرت‌ها.

## ۳. نیازمندی‌های غیرتابعی (Non-Functional Requirements)
* **سرعت پردازش و عدم ایجاد وقفه در آپلود:** عملیات استخراج مسیر و برچسب‌گذاری در کسری از ثانیه (کمتر از ۵۰ میلی‌ثانیه) انجام شود تا کاربر در حین آپلود وب‌دَو معطل نگردد.
* **عدم ایجاد تگ‌های تکراری و ناهماهنگ:** ایجاد برچسب‌ها با بررسی تطابق دقیق کاراکتری و جلوگیری از ثبت موارد مضاعف.
* **ایمنی داده و تطابق تراکنشی:** عدم شکست کل فرآیند آپلود فایل در صورت بروز خطای مقطعی در موتور تگ.

## ۴. معماری و مدل مفهومی (Architectural & Conceptual Model)
سیستم برچسب‌گذاری خودکار سلسله‌مراتبی به عنوان یک ماژول پس‌زمینه‌ای رویدادمحور (Event-Driven) طراحی شده است. لیسنرهای رویداد هسته Nextcloud رویدادهای فایل را دریافت کرده و پردازش مسیر را به سرویس مرکزی `AutoTagService` می‌سپارند:

```
+───────────────────────────────────────────────────────────+
|               File Action (Upload / Rename / Move)        |
+───────────────────────────────────────────────────────────+
                              │
                              ▼
+───────────────────────────────────────────────────────────+
|  Event Dispatcher (NodeCreated / NodeWritten / Renamed)   |
+───────────────────────────────────────────────────────────+
                              │
                              ▼
+───────────────────────────────────────────────────────────+
|  AutoTagService (OCA\ArchiveAutoTag\Service)              |
|  - Parse path segments: Enterprise_Archive/A/B/file.pdf   |
|  - Extract tokens: ['A', 'B']                             |
|  - Ensure system tags exist -> ISystemTagManager          |
|  - Assign tags to file -> ISystemTagObjectMapper          |
+───────────────────────────────────────────────────────────+
                              │
                              ▼
+───────────────────────────────────────────────────────────+
|  Database Persistence                                     |
|  - oc_systemtag (Tag Metadata)                            |
|  - oc_systemtag_object_mapping (Object <-> Tag Links)     |
+───────────────────────────────────────────────────────────+
```

## ۵. رفتار پیش‌فرض Nextcloud و شکاف موجود (Nextcloud Default Behavior vs Custom Need)
در حالت پیش‌فرض، Nextcloud صرفاً برچسب‌گذاری دستی توسط کاربر را پشتیبانی می‌کند و هیچ سازوکاری برای استخراج متادیتا از ساختار درختی پوشه‌ها و همگام‌سازی آن در زمان رینیم یا جابه‌جایی ندارد. این کاستی به طور کامل توسط سرویس `AutoTagService` پوشش داده شد.

## ۶. رویکردهای بررسی‌شده و دلایل رد گزینه‌های نامناسب (Trade-offs & Rejected Alternatives)
* **رویکرد اول: استفاده از برنامه‌های خودکارسازی قوانین بومی Nextcloud (Workflow Rules):**
  * *علت رد:* افزونه Workflow Rules از استخراج پویای بخش‌های مسیر و ایجاد درختی تگ‌های متغیر در زمان رانتایم پشتیبانی نمی‌کرد و نیاز به تنظیم دستی قوانین ثابت برای هر پوشه داشت.
* **رویکرد دوم: اسکریپت‌های کرون دوره‌ای (Cron Job Scanning):**
  * *علت رد:* تاخیر زمانی میان آپلود فایل و اجرای کرون جاب مانع از دسترسی فوری به نتایج در پرتال آرشیو می‌شد.
* **رویکرد برگزیده: اتصال مستقیم به رویدادهای همگام لایف‌سایکل فایل‌ها در سطح هسته PHP:**
  * *مزیت:* اعمال آنی برچسب‌ها در همان لحظه ایجاد سند با سرعت کسر میلی‌ثانیه.

## ۷. مدل داده و تغییرات پایگاه داده (Data Model & Schema Evolution)
این سیستم مستقیماً از جداول استاندارد هسته تگ‌های Nextcloud بهره می‌برد:
* `oc_systemtag`: شناسه، نام برچسب، وضعیت دسترسی کاربر.
* `oc_systemtag_object_mapping`: اتصال فیلد `objectid` (شناسه فایل) به `systemtagid` برای `objecttype = 'files'`.

## ۸. ساختار کد و فایل‌های پیاده‌سازی (Code Structure & File Breakdown)
* **سرویس اصلی برچسب‌گذاری خودکار:**
  * `apps/archive_autotag/lib/Service/AutoTagService.php`: حاوی متدهای اصلی `tagNode`، `buildTagsForPath`، `syncTagsOnRename`.
* **شنونده‌های رویداد:**
  * `apps/archive_autotag/lib/Listener/NodeCreatedListener.php`
  * `apps/archive_autotag/lib/Listener/NodeWrittenListener.php`
  * `apps/archive_autotag/lib/Listener/NodeRenamedListener.php`
  * `apps/archive_autotag/lib/Listener/NodeDeletedListener.php`
* **دستورات CLI:**
  * `apps/archive_autotag/lib/Command/RetagAllCommand.php` (`archive:retag`)
  * `apps/archive_autotag/lib/Command/TagReconcileCommand.php` (`archive:tag:reconcile`)
* **تست‌های خودکار:**
  * `tests/test_autotagging.py` و بخش تگ‌گذاری در `tests/test_dynamic_archive_system.py`.

## ۹. هوک‌ها، ایونت‌ها و نقاط اتصال به هسته (Hooks, Events & Integration Points)
* ثبت لیسنرها روی رویدادهای هسته `OCP\Files\Events\*`.
* استفاده از سرویس‌های `ISystemTagManager` و `ISystemTagObjectMapper`.

## ۱۰. منطق گام‌به‌گام پردازش (Detailed Flow / Algorithm)
۱. با آپلود یا جابه‌جایی فایل، رویداد متناظر فایر می‌شود.
۲. مسیر فایل بررسی می‌شود؛ اگر فایل در پوشه ریشه یا خارج از `Enterprise_Archive` باشد نادیده گرفته می‌شود.
۳. پوشه والد به بخش‌های تفکیک‌شده (Segments) شکسته شده و اسامی پوشه‌ها استخراج می‌گردند.
۴. برای هر بخش، بررسی می‌شود که آیا برچسب سیستمی با این نام وجود دارد؛ در صورت عدم وجود، برچسب ساخته می‌شود.
۵. نگاشت‌های جدید در جدول `oc_systemtag_object_mapping` ثبت شده و تگ‌های تاریخ‌گذشته فایل حذف می‌گردند.

## ۱۱. وابستگی‌ها و پیش‌نیازها (Dependencies & Prerequisites)
* نصب و فعال بودن اپلیکیشن اصلی `systemtags` در هسته Nextcloud.
* دسترسی ادمین جهت اجرای دستورات خط فرمان `archive:retag`.

## ۱۲. مدیریت خطا و سناریوهای استثنا (Failure Modes & Edge Cases)
* **فایل‌های با کاراکترهای خاص در نام پوشه:** مدیریت استاندارد یونیکد و فاصله‌ها در فرآیند تولید تگ.
* **هم‌زمانی ایجاد تگ مشابه:** کدهای محافظتی و ترای-کچ برای جلوگیری از ایجاد خطای دیتابیس در صورت ساخت هم‌زمان یک تگ توسط دو فرآیند.

## ۱۳. دسترسی‌ها، نقش‌ها و امنیت (Security, Roles & Permissions)
* برچسب‌ها با صفت `userAssignable = true` و `userVisible = true` ایجاد می‌شوند تا در پرتال آرشیو قابل مشاهده باشند، اما انتساب آن‌ها توسط لایه ایزولاسیون نیازمندی ۰۸ محافظت می‌شود.

## ۱۴. APIها و پروتکل‌ها (APIs & Protocols)
* **فرمان بازتولید برچسب‌های آرشیو:**
  ```bash
  docker compose exec -u www-data app php occ archive:retag
  ```
* **فرمان اصلاح و اعتبارسنجی تگ‌ها:**
  ```bash
  docker compose exec -u www-data app php occ archive:tag:reconcile
  ```

## ۱۵. تنظیمات و متغیرهای پیکربندی (Configuration & Parameters)
* پوشه ریشه ره‌گیری آرشیو: پیش‌فرض `Enterprise_Archive`.

## ۱۶. عملکرد و مقیاس‌پذیری (Performance & Scalability)
* سیستم با استفاده از کش درون‌حافظه‌ای در طول هر چرخه درخواست، از استعلام‌های تکراری تگ‌ها جلوگیری می‌کند.
* دستور `archive:retag` قابلیت پردازش دسته‌ای هزاران سند را بدون نشت حافظه دارد.

## ۱۷. قابلیت مشاهده‌پذیری، لاگ‌ها و آدیت (Observability & Logging)
* وقایع ایجاد و انتساب برچسب با سطح `DEBUG` و خطاهای غیرمنتظره با سطح `ERROR` در لاگ Nextcloud ثبت می‌شوند.

## ۱۸. سناریوهای تست و اعتبارسنجی (Testing & Verification Scenarios)
* **تست ۱:** ساخت مسیر `Enterprise_Archive/Finance/2026/Invoices` و آپلود فایل؛ تایید الصاق خودکار تگ‌های `Finance`، `2026` و `Invoices`.
* **تست ۲:** تغییر نام پوشه `Invoices` به `Paid_Invoices`؛ تایید به‌روزرسانی خودکار برچسب‌ها به `Paid_Invoices`.
* اسکریپت تست: `tests/test_dynamic_archive_system.py`.

## ۱۹. بدهی فنی و محدودیت‌های شناخته‌شده (Technical Debt & Known Limitations)
* در حال حاضر عمق سلسله‌مراتب پوشه‌ها بدون محدودیت به تگ تبدیل می‌شود؛ پیشنهاد می‌شود برای ساختارهای بسیار عمیق سقف حداکثر عمق تگ‌گذاری تعریف شود.

## ۲۰. تحلیل اثر بر سایر نیازمندی‌ها (Impact Analysis & Cross-Requirement Matrix)
* نیازمندی‌های ۰۷ (فیلتر چندتگی)، ۰۸ (ایزولاسیون تگ‌ها) و ۰۹ (پرتال آرشیو) مستقیماً به خروجی این نیازمندی وابسته‌اند.

## ۲۱. چک‌لیست استقرار، بکاپ و ریکاوری (Deployment, Backup & Recovery Checklist)
* [x] فعال بودن اپلیکیشن `archive_autotag`.
* [x] اجرای موفق تست بازتولید تگ‌ها با دستور `occ archive:retag`.
* [x] نسخه پشتیبان منظم از جداول `oc_systemtag*`.

## ۲۲. ارتباط با سایر اسناد (Related Documents)
* سند [07_multi_tag_filtering.md](file:///home/alborz/enterprise-archive-system/docs/requirements/07_multi_tag_filtering.md)
* سند [08_access_control_and_tag_isolation.md](file:///home/alborz/enterprise-archive-system/docs/requirements/08_access_control_and_tag_isolation.md)

## ۲۳. وضعیت نهایی (Final Implementation Status)
* **وضعیت پیاده‌سازی:** کامل، عملیاتی و ادغام‌شده در شاخه اصلی پروژه.
* **پوشش تست:** قبولی ۱۰۰٪ در آزمون‌های خودکار.
