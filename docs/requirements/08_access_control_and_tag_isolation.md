# نیازمندی ۰۸: کنترل دسترسی و ایزولاسیون تگ‌ها (Access Control & Tag Isolation)

## ۱. شرح نیازمندی (Problem Statement & Business Need)
در سازمان‌های بزرگ و موسسات مالی، اصل تفکیک وظایف و حداقل دسترسی (Principle of Least Privilege) ایجاب می‌کند که اسناد و فراداده‌های هر واحد سازمانی (مانند مرکز عملیات امنیت SOC، تیم امداد رایانه‌ای CERT، تیم شبکه، مدیریت حوادث و واحد انطباق) کاملاً محرمانه مانده و در دسترس سایر واحدها قرار نگیرد. در بستر پیش‌فرض Nextcloud، برچسب‌های سیستمی ماهیتی اشتراکی و سراسری دارند؛ بدین معنا که هر کاربر با باز کردن بخش فیلتر یا جزئیات فایل، تمامی برچسب‌های ایجادشده در کل سامانه را مشاهده می‌کند. علاوه بر آن، دسترسی به فایل‌های آرشیو به تفکیک آپلودکننده و مجوزهای اعطایی مدیریت کنترل نمی‌شود. بنابراین، نیازمندی قطعی سازمان، پیاده‌سازی سازوکار ایزولاسیون کامل فایل‌ها بر اساس آپلودکننده/مجوز صریح، و ایزولاسیون کامل تگ‌های سیستمی بر اساس عضویت در گروه‌های سازمانی بود.

## ۲. نیازمندی‌های تابعی (Functional Requirements)
* **ثبت خودکار مالکیت فایل (File Ownership Tracking):** ثبت قطعی شناسه کاربری آپلودکننده فایل در جدول اختصاصی `oc_archive_file_ownership` بلافاصله پس از ایجاد هر سند در آرشیو.
* **مجوزدهی صریح به فایل‌ها (Granular Access Grants):** امکان اعطای مجوز دسترسی فایل به کاربران یا گروه‌ها توسط مدیر سیستم در جدول `oc_archive_file_grants`.
* **ایزولاسیون تگ‌ها بر اساس گروه‌های سازمانی:** اتصال هر برچسب سیستمی به یک یا چند گروه سازمانی در جدول `oc_archive_tag_groups`.
* **محدودسازی دید تگ‌ها:** فیلتر شدن خودکار فهرست برچسب‌ها در کل رابط‌های کاربری و APIها، به‌گونه‌ای که هر کاربر فقط تگ‌های مرتبط با گروه‌های خود را مشاهده کند.
* **رپرهای امنیتی فایل و متادیتا:** پیاده‌سازی رپر ذخیره‌سازی اختصاصی (`ArchiveFileIsolationWrapper`) و مدیر تگ ایزوله‌شده (`IsolatedSystemTagManager`).
* **دستورات مدیریتی CLI:** فراهم ساختن فرامین خط فرمان `occ archive:file:grant` و `occ archive:tag:assign-group` جهت مدیریت متمرکز مجوزها.

## ۳. نیازمندی‌های غیرتابعی (Non-Functional Requirements)
* **نفوذناپذیری امنیتی (Security Enforcement):** اعمال محدودیت‌ها در عمیق‌ترین لایه دسترسی به فایل (پروتکل WebDAV و رپر ذخیره‌سازی) تا امکان دور زدن محدودیت از طریق کلاینت‌های متفرقه وجود نداشته باشد.
* **سربار حداقلی پایگاه داده:** کش کردن شناسه‌های گروه‌های کاربر در طول درخواست و استفاده از ایندکس‌های منحصربه‌فرد بر روی جداول نگاشت مجوز.
* **عدم ناسازگاری با ارتقاهای Nextcloud:** پیاده‌سازی از طریق رابط‌های استاندارد (`IStorageWrapper`, `ISystemTagManager`) بدون دستکاری فایل‌های هسته اصلی.

## ۴. معماری و مدل مفهومی (Architectural & Conceptual Model)
معماری امنیت و ایزولاسیون بر پایه یک مدل دولایه طراحی شده است:
۱. **لایه فایل و داده (Storage Isolation Layer):** به وسیله کلاس `ArchiveFileIsolationWrapper` که دور استوریج کاربر پیچیده می‌شود، تمامی فراخوانی‌های خواندن، نوشتن و جستجوی فایل کنترل شده و متد `canUserAccessFile` از سرویس `FileOwnershipService` فراخوانی می‌گردد.
۲. **لایه متادیتا و تگ (Metadata Isolation Layer):** به وسیله `IsolatedSystemTagManager` که جایگزین سرویس پیش‌فرض تگ در کانتینر DI اپلیکیشن شده است، متدهای `getAllTags` و `getTagsByIds` خروجی را بر اساس متد `getVisibleTagIds` از `TagOwnershipService` پالایش می‌کنند.

```
                  +-----------------------------------+
                  |     درخواست کاربر (Web / WebDAV)  |
                  +-----------------------------------+
                                    │
                                    ▼
       +────────────────────────────┴────────────────────────────+
       │                                                         │
       ▼                                                         ▼
[ فراخوانی فایل / پوشه ]                                [ فراخوانی برچسب‌ها ]
       │                                                         │
       ▼                                                         ▼
+─────────────────────────────+                 +─────────────────────────────+
| ArchiveFileIsolationWrapper |                 |  IsolatedSystemTagManager   |
+─────────────────────────────+                 +─────────────────────────────+
       │                                                         │
       ▼                                                         ▼
+─────────────────────────────+                 +─────────────────────────────+
|    FileOwnershipService     |                 |     TagOwnershipService     |
+─────────────────────────────+                 +─────────────────────────────+
       │                                                         │
       ├─ بررسی مالکیت فایل (ownership)                          ├─ بررسی گروه‌های کاربر
       ├─ بررسی گرنت‌های دسترسی (grants)                         ├─ بررسی نگاشت تگ به گروه
       ▼                                                         ▼
+─────────────────────────────+                 +─────────────────────────────+
| oc_archive_file_ownership   |                 | oc_archive_tag_groups       |
| oc_archive_file_grants      |                 | oc_archive_tag_ownership    |
+─────────────────────────────+                 +─────────────────────────────+
```

## ۵. رفتار پیش‌فرض Nextcloud و شکاف موجود (Nextcloud Default Behavior vs Custom Need)
در Nextcloud استاندارد:
۱. تگ‌های سیستمی (System Tags) برای کلیه کاربران احراز هویت‌شده دارای دید همگانی هستند و امکان تخصیص برچسب به گروه مشخص به صورت پیش‌فرض پیاده نشده است.
۲. فایل‌های اشتراکی بر پایه مدل P2P و مالکیت پوشه کاربر عمل می‌کنند؛ در یک مخزن آرشیو شرکتی متمرکز، کاربران در صورت باز کردن پوشه اشتراکی تمام محتویات آن را می‌بینند مگر آنکه در سطح رپر ذخیره‌سازی ایزولاسیون مبتنی بر رکورد مالک اعمال شود.

## ۶. رویکردهای بررسی‌شده و دلایل رد گزینه‌های نامناسب (Trade-offs & Rejected Alternatives)
* **رویکرد اول: استفاده از Group Folders رسمی Nextcloud و ACL استاندارد آن:**
  * *علت رد:* افزونه Group Folders فاقد امکان ایزولاسیون برچسب‌های سیستمی است و تعریف دسترسی‌های پویا به ازای تک‌تک فایل‌های درون پوشه پیچیدگی اداری و خطای انسانی بالایی دارد.
* **رویکرد دوم: مخفی‌سازی کلاینت‌ساید با جاوااسکریپت و CSS:**
  * *علت رد:* نقض فاحش اصول امنیت اطلاعات؛ کاربر می‌توانست از طریق فراخوانی مستقیم APIهای OCS یا WebDAV به تمامی اطلاعات و برچسب‌های سایر دپارتمان‌ها دست یابد.
* **رویکرد برگزیده: لایه امنیتی دوگانه در سطح Storage Wrapper و SystemTag Decorator در لایه سرور:**
  * *مزیت:* امنیت ۱۰۰٪ تضمین‌شده در سطح هسته و پروتکل‌های شبکه بدون امکان دور زدن.

## ۷. مدل داده و تغییرات پایگاه داده (Data Model & Schema Evolution)
تغییرات پایگاه داده طی دو مایگریشن اختصاصی اعمال شدند:
* **مایگریشن `Version1400Date20260913000001.php`:**
  * **`oc_archive_file_ownership`**:
    * `id` (bigint, PK, autoincrement)
    * `file_id` (bigint, unique index)
    * `owner_uid` (string, length 64, index)
    * `created_at` (bigint)
  * **`oc_archive_file_grants`**:
    * `id` (bigint, PK, autoincrement)
    * `file_id` (bigint)
    * `grantee_type` (string: `user` یا `group`)
    * `grantee_id` (string, length 64)
    * `granted_by` (string, default `admin`)
    * `permissions` (integer, default 31)
    * `created_at` (bigint)
    * ایندکس یکتای کامپوزیت روی `(file_id, grantee_type, grantee_id)`.
  * **`oc_archive_tag_ownership`**:
    * `id` (bigint, PK)
    * `tag_id` (bigint, unique index)
    * `owner_uid` (string, length 64, index)
    * `created_at` (bigint)
* **مایگریشن `Version1600Date20260914000001.php`:**
  * **`oc_archive_tag_groups`**:
    * `id` (bigint, PK, autoincrement)
    * `tag_id` (bigint, index)
    * `group_id` (string, length 64, index)
    * `created_at` (bigint)
    * ایندکس یکتای کامپوزیت روی `(tag_id, group_id)`.

## ۸. ساختار کد و فایل‌های پیاده‌سازی (Code Structure & File Breakdown)
* **سرویس‌های مدیریت مالکیت و دسترسی:**
  * `apps/archive_autotag/lib/Service/FileOwnershipService.php`: متدهای `setOwner`، `getOwner`، `canUserAccessFile`، `grantAccess` و `revokeAccess`.
  * `apps/archive_autotag/lib/Service/TagOwnershipService.php`: متدهای `getVisibleTagIds`، `canUserSeeTag`، `assignTagToGroup` و `setTagOwner`.
* **رپرهای هسته سیستم:**
  * `apps/archive_autotag/lib/Storage/ArchiveFileIsolationWrapper.php`: مداخله در فراخوانی‌های فایل‌سیستم و فیلتر فایل‌های غیرمجاز.
  * `apps/archive_autotag/lib/SystemTag/IsolatedSystemTagManager.php`: فیلتر متدهای `getAllTags` و `getTagsByIds`.
  * `apps/archive_autotag/lib/SystemTag/IsolatedManagerFactory.php`: فکتوری ایجاد مدیر تگ ایزوله‌شده.
* **مایگریشن‌ها:**
  * `apps/archive_autotag/lib/Migration/Version1400Date20260913000001.php`
  * `apps/archive_autotag/lib/Migration/Version1600Date20260914000001.php`
* **دستورات مدیریتی خط فرمان:**
  * `apps/archive_autotag/lib/Command/FileGrantCommand.php`: دستور `archive:file:grant`.
  * `apps/archive_autotag/lib/Command/TagGovernanceCommand.php`: دستور `archive:tag:assign-group`.
* **شنونده‌های رویدادها:**
  * `apps/archive_autotag/lib/Listener/NodeCreatedListener.php`: ثبت مالکیت اولیه فایل هنگام ایجاد.
  * `apps/archive_autotag/lib/Listener/NodeDeletedListener.php`: پاک‌سازی رکوردهای مالکیت و گرنت پس از حذف فایل.

## ۹. هوک‌ها، ایونت‌ها و نقاط اتصال به هسته (Hooks, Events & Integration Points)
* **`OC\Files\Filesystem::addStorageWrapper`:** ثبت رپر `ArchiveFileIsolationWrapper` در زمان بارگذاری اپلیکیشن.
* **رویدادهای `NodeCreatedEvent` و `NodeDeletedEvent`:** اتصال به چرخه حیات فایل‌ها برای مدیریت رکوردهای پایگاه داده.
* **سرویس دیسپچر کانتینر DI:** بازتعریف سرویس `ISystemTagManager` با پیاده‌سازی `IsolatedSystemTagManager`.

## ۱۰. منطق گام‌به‌گام پردازش (Detailed Flow / Algorithm)
۱. **هنگام آپلود فایل:**
   * لیسنر `NodeCreatedListener` اجرا می‌شود.
   * اگر مسیر در محدوده آرشیو باشد، شناسه فایل و شناسه کاربر جاری در جدول `oc_archive_file_ownership` درج می‌شود.
۲. **هنگام استعلام لیست فایل‌ها یا باز کردن فایل:**
   * رپر `ArchiveFileIsolationWrapper` نام کاربری و شناسه نود را دریافت می‌کند.
   * کاربر مدیر (`admin`) دسترسی کامل دارد.
   * اگر کاربر مدیر نباشد، `FileOwnershipService::canUserAccessFile` بررسی می‌کند:
     * آیا کاربر مالک فایل است؟ در صورت مثبت بودن، دسترسی مجاز است.
     * آیا گرنت مستقیمی به شناسه کاربر (`grantee_type = 'user'`) وجود دارد؟ در صورت مثبت بودن، دسترسی مجاز است.
     * آیا گرنت به گروهی از گروه‌های عضویت کاربر (`grantee_type = 'group'`) وجود دارد؟ در صورت مثبت بودن، دسترسی مجاز است.
     * در غیر این صورت دسترسی رد شده و نود از لیست مخفی یا دسترسی مسدود می‌شود.
۳. **هنگام استعلام تگ‌ها:**
   * متد `TagOwnershipService::getVisibleTagIds($uid)` اجرا می‌شود.
   * تگ‌های عمومی یا دارای مالکیت کاربر یا تگ‌های متصل به گروه‌های عضویت کاربر از جداول استخراج و تگ‌های سایر واحدها حذف می‌گردند.

## ۱۱. وابستگی‌ها و پیش‌نیازها (Dependencies & Prerequisites)
* سیستم مدیریت گروه‌ها و کاربران هسته Nextcloud (`IGroupManager`, `IUserManager`).
* اجرای موفقیت‌آمیز مایگریشن‌های پایگاه داده ۱۴۰۰ و ۱۶۰۰.
* مقداردهی اولیه نگاشت گروه‌های سازمانی (SOC, CERT, Network, IncidentMNG, Compliance_Unit).

## ۱۲. مدیریت خطا و سناریوهای استثنا (Failure Modes & Edge Cases)
* **فایل‌های فاقد رکورد مالکیت:** در فرآیند Backfill مایگریشن ۱۴۰۰، کلیه فایل‌های قدیمی آرشیو به مالکیت کاربر `admin` ثبت شده‌اند تا هیچ فایلی بی‌پناه و آسیب‌پذیر رها نشود.
* **حذف گروه یا کاربر:** رکوردهای یتیم در جدول گرنت مانع فعالیت سیستم نشده و بررسی عضویت در گروه در زمان اجرا (Run-time) ارزیابی می‌شود.
* **کاربر فاقد گروه سازمانی:** صرفاً به تگ‌ها و فایل‌های متعلق به خود دسترسی دارد.

## ۱۳. دسترسی‌ها، نقش‌ها و امنیت (Security, Roles & Permissions)
* مدیر سیستم (`admin`): معاف از محدودیت‌های ایزولاسیون، دارای حق اعطای مجوز به فایل‌ها و برچسب‌ها.
* مدیران گروه (`Group Admins`): امکان مدیریت و مشاهده تگ‌ها و فایل‌های دپارتمان خود.
* کاربران عادی (`Regular Users`): ایزولاسیون سخت‌گیرانه؛ عدم امکان مشاهده تگ‌ها یا اسناد سایر واحدها.

## ۱۴. APIها و پروتکل‌ها (APIs & Protocols)
* **فرمان اعطای مجوز دسترسی به فایل:**
  ```bash
  docker compose exec -u www-data app php occ archive:file:grant --file-id=660 --user=archive_user1 --perms=31
  ```
* **فرمان تخصیص برچسب به گروه سازمانی:**
  ```bash
  docker compose exec -u www-data app php occ archive:tag:assign-group 12 SOC
  ```
* **فرمان تنظیم مالک برچسب:**
  ```bash
  docker compose exec -u www-data app php occ archive:tag:set-owner 12 admin
  ```
* **پروتکل WebDAV (PROPFIND / GET):** کلاینت‌های متصل به WebDAV در صورت تلاش برای دسترسی به فایل سایر دپارتمان‌ها با وضعیت `HTTP 403 Forbidden` مواجه می‌شوند.

## ۱۵. تنظیمات و متغیرهای پیکربندی (Configuration & Parameters)
* جدول نگاشت پیش‌فرض گروه‌ها در مایگریشن ۱۶۰۰:
  * برچسب `SOC` -> گروه `SOC`
  * برچسب `CERT` -> گروه `CERT`
  * برچسب `Network` -> گروه `NetWork`
  * برچسب `IncedentMNG` -> گروه `IncidentMNG`
  * برچسب `Compliance_Unit` -> گروه `Compliance_Unit`

## ۱۶. عملکرد و مقیاس‌پذیری (Performance & Scalability)
* تعریف ایندکس‌های منحصر‌به‌فرد کامپوزیت روی جداول `archive_file_grants` و `archive_tag_groups` مانع از تکرار رکوردها و کاهش زمان اجرای کوئری‌ها به کسر میلی‌ثانیه شده است.
* متد `canUserAccessFile` شامل کش محلی سشن برای جلوگیری از تکرار کوئری به ازای بررسی مکرر همان فایل در یک درخواست است.

## ۱۷. قابلیت مشاهده‌پذیری، لاگ‌ها و آدیت (Observability & Logging)
* تمامی اقدامات اعطای دسترسی فایل یا تغییر مالکیت تگ از طریق فرمان‌های CLI در لاگ‌های سیستمی Nextcloud با سطح `INFO` ثبت می‌گردند.
* تلاش‌های غیرمجاز برای دسترسی به فایل با جزئیات شناسه کاربر و شناسه فایل در لاگ ممیزی ثبت می‌شوند.

## ۱۸. سناریوهای تست و اعتبارسنجی (Testing & Verification Scenarios)
* **تست ۱:** آپلود فایل توسط `archive_user1` و تایید ثبت شناسه او در جدول `oc_archive_file_ownership`.
* **تست ۲:** استعلام فهرست تگ‌ها توسط کاربر عضو `SOC` و تایید عدم مشاهده تگ‌های واحد `CERT`.
* **تست ۳:** تلاش برای دسترسی مستقیم به فایل واحد SOC توسط کاربر واحد CERT و تایید مسدودسازی با خطای ۴۰۳ یا ۴۰۴.
* **تست ۴:** اعطای مجوز فایل به کاربر دوم از طریق CLI و تایید باز شدن دسترسی برای آن کاربر.
* تست‌های مرجع: `tests/test_archive_acl_and_tag_isolation.py` و `tests/test_group_tag_isolation.py`.

## ۱۹. بدهی فنی و محدودیت‌های شناخته‌شده (Technical Debt & Known Limitations)
* مدیریت گرنت‌های فایل در حال حاضر از طریق CLI یا API انجام می‌شود و رابط گرافیکی مجزایی برای تعریف دسترسی ماتریسی در فاز فعلی طراحی نشده است.
* در صورت تغییر نام گروه سازمانی در دیتابیس، نام گروه در جدول `oc_archive_tag_groups` باید به صورت هماهنگ به‌روزرسانی شود.

## ۲۰. تحلیل اثر بر سایر نیازمندی‌ها (Impact Analysis & Cross-Requirement Matrix)
* **نیازمندی ۰۲ (Autotagging):** هنگام الصاق برچسب به فایل‌ها، تطابق تگ با گروه سازمانی اعتبارسنجی می‌شود.
* **نیازمندی ۰۷ (Multi-Tag Filter):** کنترلر فیلتر مستقیماً از متدهای این نیازمندی برای حذف فایل‌ها و تگ‌های غیرمجاز استفاده می‌کند.
* **نیازمندی ۰۹ (Archive Portal):** اطلاعات نمایش‌یافته در پرتال آرشیو کاملاً تحت تاثیر خروجی این لایه امنیتی است.

## ۲۱. چک‌لیست استقرار، بکاپ و ریکاوری (Deployment, Backup & Recovery Checklist)
* [x] اجرای کامل مایگریشن‌های `Version1400` و `Version1600`.
* [x] اطمینان از اجرای `postSchemaChange` جهت Backfill فایل‌ها و تگ‌های موجود.
* [x] پشتیبان‌گیری از جداول `oc_archive_*` در اسکریپت `deploy/backup_db.sh`.
* [x] تست صحت دسترسی‌ها پس از بازیابی دیتابیس در محیط آزمایشی.

## ۲۲. ارتباط با سایر اسناد (Related Documents)
* سند [02_hierarchical_autotagging.md](file:///home/alborz/enterprise-archive-system/docs/requirements/02_hierarchical_autotagging.md)
* سند [07_multi_tag_filtering.md](file:///home/alborz/enterprise-archive-system/docs/requirements/07_multi_tag_filtering.md)
* سند [09_archive_portal_and_obsidian_theme.md](file:///home/alborz/enterprise-archive-system/docs/requirements/09_archive_portal_and_obsidian_theme.md)
* سند [11_group_admin_folder_request_portal.md](file:///home/alborz/enterprise-archive-system/docs/requirements/11_group_admin_folder_request_portal.md)

## ۲۳. وضعیت نهایی (Final Implementation Status)
* **وضعیت پیاده‌سازی:** کامل، تست‌شده و فعال در محیط پروداکشن.
* **پایداری داده‌ها:** تضمین‌شده از طریق ایندکس‌های یکتا و لایه‌های کنترل خطای نرم‌افزاری.
* **تست‌های خودکار:** قبولی ۱۰۰٪ در تمامی سناریوهای تستی چندکاربره.
