# نیازمندی ۰۴: انسداد ساخت دایرکتوری توسط کاربران عادی (Folder Creation Restrictions)

## ۱. شرح نیازمندی (Problem Statement & Business Need)
یکی از مشکلات بنیادین در سامانه‌های ذخیره‌سازی سازمانی، ایجاد بی‌رویه و شلخته پوشه‌ها توسط کاربران مختلف است که ساختار استاندارد دسته‌بندی و بایگانی اسناد را در طول زمان مخدوش می‌سازد. سازمان نیاز داشت که کاربران عادی و پرسنل دپارتمان‌ها صرفاً امکان آپلود، بارگیری و مشاهده اسناد در پوشه‌های از پیش‌تعیین‌شده را داشته باشند و هیچ کاربری به جز مدیر کل سیستم (`admin`) قادر به ساخت پوشه جدید (از طریق رابط وب یا کلاینت‌های متصل به پروتکل WebDAV با متد `MKCOL`) نباشد. در صورت تلاش کاربر عادی برای ایجاد پوشه، سامانه باید بلافاصله عملیات را با خطای صریح ۴۰۳ مسدود کند.

## ۲. نیازمندی‌های تابعی (Functional Requirements)
* **رد درخواست‌های ساخت پوشه (MKCOL Rejection):** رهگیری متد `MKCOL` در پروتکل WebDAV و پرتاب استثنای `Sabre\DAV\Exception\Forbidden` (کد وضعیت ۴۰۳).
* **رهگیری رویداد پیش از ایجاد نود (BeforeNodeCreated):** مسدودسازی تلاش‌های ساخت پوشه از طریق واسط کاربری وب و توابع داخلی با پرتاب استثنای امنیتی.
* **استثنای انحصاری مدیران سیستم:** امکان ساخت پوشه فقط و فقط برای کاربران عضو گروه مدیران ارشد (`admin`).
* **سرویس کنترل خط‌مشی پوشه‌ها (FolderPolicyService):** متمرکزسازی منطق اعتبارسنجی در یک سرویس اختصاصی با قابلیت فعال/غیرفعال‌سازی از طریق تنظیمات سیستم.
* **فرمان مدیریتی خط فرمان (CLI):** دستور `occ archive:folder:policy [status|enable|disable]` جهت مدیریت وضعیت این سیاست امنیتی.

## ۳. نیازمندی‌های غیرتابعی (Non-Functional Requirements)
* **امنیت غیرقابل دور زدن (Fail-Safe Enforcement):** اعمال مسدودسازی در دو لایه مستقل: ۱) لیسنر Sabre DAV Plugin برای پروتکل وب‌دَو و ۲) لیسنر رویداد بومی Nextcloud برای واسط گرافیکی.
* **سرعت ارزیابی آنی:** تصمیم‌گیری در کمتر از ۱ میلی‌ثانیه بدون ایجاد تاخیر برای سایر عملیات‌های فایل‌سیستم.
* **پیام‌رسانی شفاف:** ارائه پیام خطای صریح "Folder creation is restricted to administrators" به کاربر متخلف.

## ۴. معماری و مدل مفهومی (Architectural & Conceptual Model)
این نیازمندی از طریق معماری حفاظتی دولایه در لایه‌های ورود پروتکل و هسته فایل پیاده‌سازی شده است:

```
+───────────────────────────────────────────────────────────+
|      درخواست ساخت پوشه (WebDAV MKCOL یا کلیک رابط وب)      |
+───────────────────────────────────────────────────────────+
                              │
                              ▼
+───────────────────────────────────────────────────────────+
|  لایه ۱: SabrePluginInitListener (پروتکل WebDAV)          |
|  - قلاب کردن beforeMethod:MKCOL و beforeCreateDirectory   |
+───────────────────────────────────────────────────────────+
                              │
                              ▼
+───────────────────────────────────────────────────────────+
|  لایه ۲: BeforeNodeCreatedListener (هسته Nextcloud)       |
|  - بررسی نودهای ورودی از نوع Directory                     |
+───────────────────────────────────────────────────────────+
                              │
                              ▼
+───────────────────────────────────────────────────────────+
|  FolderPolicyService (OCA\ArchiveAutoTag\Service)         |
|  - آیا سیاست فعال است؟ (isRestrictionEnabled)             |
|  - آیا کاربر مدیر است؟ (isAdminUser)                      |
+───────────────────────────────────────────────────────────+
         │                                         │
         │ [کاربر عادی است]                        │ [کاربر مدیر است]
         ▼                                         ▼
+───────────────────────────────────+     +───────────────────────────────────+
|  پرتاب Sabre\DAV\Exception\       |     |  صدور مجوز ایجاد پوشه فیزیکی       |
|  Forbidden (HTTP 403)             |     |  و ثبت متادیتای دایرکتوری          |
+───────────────────────────────────+     +───────────────────────────────────+
```

## ۵. رفتار پیش‌فرض Nextcloud و شکاف موجود (Nextcloud Default Behavior vs Custom Need)
در نسخه استاندارد Nextcloud، هر کاربری که دسترسی نوشتن روی یک پوشه داشته باشد می‌تواند آزادانه درون آن زیرپوشه ایجاد کند. هیچ تنظیم پیش‌فرضی برای تفکیک مجوز "آپلود فایل" از "ساخت پوشه" وجود ندارد. این شکاف عمیق توسط سرویس `FolderPolicyService` و رپرهای وب‌دَو اپلیکیشن برطرف گردید.

## ۶. رویکردهای بررسی‌شده و دلایل رد گزینه‌های نامناسب (Trade-offs & Rejected Alternatives)
* **رویکرد اول: مخفی کردن دکمه "پوشه جدید" با کدهای CSS در مرورگر:**
  * *علت رد:* این رویکرد به سادگی توسط کلاینت‌های دستکتاپ، موبایل یا درخواست‌های cURL دور زده می‌شد و هیچ اعتباری نداشت.
* **رویکرد دوم: محدودسازی دسترسی فایل‌سیستم در سطح لینوکس:**
  * *علت رد:* حذف دسترسی نوشتن دایرکتوری مانع از آپلود فایل‌های جدید نیز می‌شد زیرا در لینوکس نوشتن فایل درون پوشه نیازمند دسترسی Write روی دایرکتوری است.
* **رویکرد برگزیده: رهگیری سطح پروتکل WebDAV و رویدادهای هسته با پلاگین Sabre:**
  * *مزیت:* جلوگیری دقیق از ساخت پوشه بدون کوچک‌ترین اختلال در فرآیند آپلود فایل‌ها.

## ۷. مدل داده و تغییرات پایگاه داده (Data Model & Schema Evolution)
وضعیت فعال/غیرفعال بودن سیاست در جدول پیکربندی اپلیکیشن ذخیره می‌شود:
* جدول: `oc_appconfig`
* شناسه اپلیکیشن: `archive_autotag`
* کلید: `restrict_folder_creation` (مقادیر: `yes` یا `no`)

## ۸. ساختار کد و فایل‌های پیاده‌سازی (Code Structure & File Breakdown)
* **سرویس خط‌مشی دایرکتوری‌ها:**
  * `apps/archive_autotag/lib/Service/FolderPolicyService.php`: متدهای `isRestrictionEnabled`، `setRestrictionEnabled`، `canCreateFolder`.
* **شنونده پروتکل WebDAV:**
  * `apps/archive_autotag/lib/Listener/SabrePluginInitListener.php`: اتصال مستقیم به رویدادهای سرور Sabre DAV.
* **شنونده رویداد هسته:**
  * `apps/archive_autotag/lib/Listener/BeforeNodeCreatedListener.php`: متصل به رویداد `OCP\Files\Events\BeforeNodeCreatedEvent`.
* **فرمان مدیریت خط فرمان:**
  * `apps/archive_autotag/lib/Command/FolderPolicyCommand.php`: دستور `archive:folder:policy`.
* **اسکریپت تست خودکار:**
  * `tests/test_folder_creation_restriction.py`: اعتبارسنجی ارسال متد MKCOL توسط کاربر عادی و مدیر.

## ۹. هوک‌ها، ایونت‌ها و نقاط اتصال به هسته (Hooks, Events & Integration Points)
* هوک درونی سرور Sabre DAV از طریق رویداد `OCA\DAV\Connector\Sabre\Event\SabrePluginInitEvent`.
* ثبت متدهای `beforeMethod:MKCOL` و `beforeCreateDirectory`.

## ۱۰. منطق گام‌به‌گام پردازش (Detailed Flow / Algorithm)
۱. درخواست ایجاد پوشه با متد `MKCOL` به سرور وب‌دَو می‌رسد.
۲. لیسنر `SabrePluginInitListener` متد را رهگیری می‌کند.
۳. وضعیت فعال بودن محدودیت از `FolderPolicyService` استعلام می‌شود.
۴. کاربر جاری استخراج و وضعیت مدیر بودن وی سنجیده می‌شود.
۵. اگر کاربر غیرمدیر باشد، بلافاصله استثنای `Forbidden` با کد ۴۰۳ پرتاب شده و فرآیند لغو می‌شود.
۶. اگر کاربر مدیر باشد، متد ادامه یافته و پوشه با کد وضعیت ۲۰۱ Created ساخته می‌شود.

## ۱۱. وابستگی‌ها و پیش‌نیازها (Dependencies & Prerequisites)
* نصب و اجرای صحیح اپلیکیشن `archive_autotag`.
* تنظیم پارامتر `restrict_folder_creation = yes` در پایگاه داده.

## ۱۲. مدیریت خطا و سناریوهای استثنا (Failure Modes & Edge Cases)
* **آپلود فایل‌های معمولی:** این نیازمندی هیچ تداخلی با آپلود فایل با متد `PUT` ندارد و جریان آپلود آزادانه انجام می‌شود.
* **درخواست‌های ساخت پوشه از طریق پرتال تایید نیازمندی ۱۱:** درخواست‌های نیازمندی ۱۱ توسط ادمین در سطح سیستم اجرا می‌شوند و مسدود نمی‌گردند.

## ۱۳. دسترسی‌ها، نقش‌ها و امنیت (Security, Roles & Permissions)
* مدیر سیستم (`admin`): مجوز انحصاری ساخت پوشه.
* پرسنل عادی دپارتمان‌ها: مسدود بودن ۱۰۰٪ ساخت پوشه.

## ۱۴. APIها و پروتکل‌ها (APIs & Protocols)
* **پروتکل WebDAV MKCOL:**
  ```http
  MKCOL /remote.php/dav/files/archive_user1/NewFolder HTTP/1.1
  Host: localhost
  Authorization: Basic ...
  ```
  * پاسخ کاربر عادی: `HTTP/403 Forbidden`
  * پاسخ کاربر ادمین: `HTTP/201 Created`
* **فرمان CLI:**
  ```bash
  docker compose exec -u www-data app php occ archive:folder:policy enable
  ```

## ۱۵. تنظیمات و متغیرهای پیکربندی (Configuration & Parameters)
* کلید اپ‌کانفیگ: `restrict_folder_creation` (پیش‌فرض: `yes`).

## ۱۶. عملکرد و مقیاس‌پذیری (Performance & Scalability)
* بدون نیاز به هیچ کوئری سنگین دیتابیس؛ کش پارامتر در حافظه رم PHP پاسخ‌دهی در کمتر از ۰.۵ میلی‌ثانیه را به همراه دارد.

## ۱۷. قابلیت مشاهده‌پذیری، لاگ‌ها و آدیت (Observability & Logging)
* مسدودسازی ساخت پوشه با سطح `WARNING` در فایل لاگ Nextcloud ثبت می‌شود.

## ۱۸. سناریوهای تست و اعتبارسنجی (Testing & Verification Scenarios)
* **تست ۱:** ارسال درخواست `MKCOL` با اعتبارنامه `archive_user1` و تایید دریافت خطای ۴۰۳ صریح.
* **تست ۲:** ارسال همان درخواست با اعتبارنامه `admin` و تایید دریافت کد ۲۰۱ موفق.
* اسکریپت تست مرجع: `tests/test_folder_creation_restriction.py`.

## ۱۹. بدهی فنی و محدودیت‌های شناخته‌شده (Technical Debt & Known Limitations)
* با مسدود شدن ساخت پوشه، کاربران نیاز به سازوکار رسمی برای درخواست پوشه داشتند که در نیازمندی ۱۱ پوشش داده شد.

## ۲۰. تحلیل اثر بر سایر نیازمندی‌ها (Impact Analysis & Cross-Requirement Matrix)
* نیازمندی ۱۱ (پرتال درخواست پوشه) به طور مستقیم بر پایه این محدودیت بنا شده است.

## ۲۱. چک‌لیست استقرار، بکاپ و ریکاوری (Deployment, Backup & Recovery Checklist)
* [x] فعال بودن سرویس `FolderPolicyService`.
* [x] تنظیم مقدار `yes` برای کلید محدودیت.
* [x] اجرای موفق تست خودکار `tests/test_folder_creation_restriction.py`.

## ۲۲. ارتباط با سایر اسناد (Related Documents)
* سند [02_hierarchical_autotagging.md](file:///home/alborz/enterprise-archive-system/docs/requirements/02_hierarchical_autotagging.md)
* سند [11_group_admin_folder_request_portal.md](file:///home/alborz/enterprise-archive-system/docs/requirements/11_group_admin_folder_request_portal.md)

## ۲۳. وضعیت نهایی (Final Implementation Status)
* **وضعیت پیاده‌سازی:** کامل، عملیاتی و فعال در محیط واقعی.
* **تست‌های امنیتی:** قبولی ۱۰۰٪ در آزمون‌های نفوذ و شبیه‌سازی متدهای HTTP.
