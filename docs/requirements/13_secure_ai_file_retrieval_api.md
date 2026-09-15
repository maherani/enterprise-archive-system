# نیازمندی ۱۳: رابط برنامه‌نویسی امن واکشی فایل برای هوش مصنوعی لوکال (Secure AI File Retrieval API & Swagger UI)

## ۱. شرح نیازمندی (Requirement Description)
در فرآیند توسعه سامانه بایگانی اسناد سازمانی (Enterprise Archive System)، ایجاد پل ارتباطی ایمن، کنترل‌شده و ایزوله با دستیارهای هوشمند داخلی (مانند مدل‌های پردازش متن لوکال، سرویس‌های RAG یا Retrieval-Augmented Generation، و ایجنت‌های بازخوانی اسناد On-Premise) یک ضرورت استراتژیک است. هدف این نیازمندی، پیاده‌سازی یک API اختصاصی، استاندارد و با کارایی بالا است که امکان استخراج محتوای باینری فایل‌های بایگانی‌شده را بدون ایجاد سربار حافظه ($O(1)$ RAM Stream) و با رعایت ۱۰۰٪ بدون تنازل مرزهای سازمانی و سیستم کنترل دسترسی (Archive ACL) فراهم سازد. علاوه بر این، به دلیل سیاست‌های سخت‌گیرانه Air-Gapped در مراکز داده سازمانی، این قابلیت مجهز به یک رابط تعاملی Swagger/OpenAPI کاملاً لوکال و مستقل از CDNهای خارجی، توکن‌های تفویض هویت و ثبات ممیزی جامع (Audit Trail) است.

## ۲. نیازمندی‌های تابعی (Functional Requirements)
* **استریم مستقیم و باینری فایل (`GET /api/v1/ai/files/{fileId}`):** تحویل فایل با هدرهای استاندارد (`Content-Type`, `Content-Disposition`, `Content-Length`) به صورت استریم پیوسته بر بستر HTTP بدون لود در رم سرور.
* **استعلام متادیتای پاکیزه و ایمن (`GET /api/v1/ai/files/{fileId}/metadata`):** ارائه اطلاعات شناسه، نام فایل، حجم بایت، حجم خوانا، نوع MIME، زمان ویرایش، مالک و برچسب‌های متصل به فایل بدون افشای مسیرهای فیزیکی فایل‌سیستم سرور.
* **احراز هویت دوگانه (Dual-Mode Authentication):**
  * پشتیبانی از احراز هویت بومی Nextcloud (HTTP Basic Auth با نام‌کاربری و پسورد یا App Password کاربر).
  * پشتیبانی از توکن سرویس اختصاصی AI (`Authorization: Bearer <token>` یا هدر `X-API-KEY`) جهت فراخوانی توسط سرویس‌ها و Daemonهای پس‌زمینه.
* **تفویض هویت کاربری (`X-On-Behalf-Of`):** امکان استفاده از توکن ماشین سرویس AI در ترکیب با هویت یک کاربر مشخص، به منظور اعمال بی‌درنگ محدودیت‌های RAG منطبق بر سطح دسترسی همان کاربر.
* **رعایت کامل و قطعی ACL آرشیو:** اتصال به هسته `FileOwnershipService::canUserAccessFile` به نحوی که هیچ سرویس هوش مصنوعی نتواند به فایلی فراتر از دسترسی دپارتمانی یا مالکیتی کاربر تعیین‌شده دسترسی یابد.
* **شناسه‌گذاری ممیزی و ردگیری توزیع‌شده (`X-Request-ID`):** تخصیص یا انعکاس شناسه پیگیری یکتا در کلیه پاسخ‌ها و ثبت در جدول ممیزی دیتابیس.
* **مستندسازی و پرتال تعاملی تست (Swagger UI & OpenAPI 3.0.3):**
  * ارائه سند ماشین‌خوان استاندارد در اندپوینت `/api/openapi.json`.
  * ارائه واسط کاربری تست تعاملی کاملاً درون‌برنامه‌ای و Air-Gapped در اندپوینت `/api/docs`.

## ۳. نیازمندی‌های غیرتابعی (Non-Functional Requirements)
* **عملکرد و بهره‌وری حافظه ($O(1)$ RAM Overhead):** استفاده از چانک‌های استریم فایل‌سیستم بدون خواندن کل فایل در متغیرهای PHP؛ تضمین پایداری تحویل فایل‌های حجیم چندگیگابایتی.
* **امنیت و عدم افشای ساختار دایرکتوری (Anti-IDOR & Zero Enumeration):** پاسخ‌های ۴۰۳ (عدم دسترسی) و ۴۰۴ (عدم وجود) ساختار داخلی دایرکتوری‌ها و مسیرهای لینوکسی سرور را فاش نمی‌کنند.
* **سازگاری با ایزولاسیون کامل شبکه (Air-Gapped & Offline Ready):** رابط کاربری Swagger UI بدون وابستگی به هیچ اسکریپت یا استایل خارجی (مانند unpkg یا cdnjs) درون خود سرور رندر می‌شود.
* **انطباق با سیاست‌های امنیت محتوا (CSP Compliance):** تنظیم صریح Content-Security-Policy برای اندپوینت‌های API و واسط کاربری مستندات.

## ۴. معماری و مدل مفهومی (Architecture & Conceptual Model)
معماری اتصال AI در این سامانه بر پایه اصل «کمترین سطح دسترسی» (Principle of Least Privilege) طراحی شده است. هوش مصنوعی به عنوان یک کلاینت خارجی اما درون‌سازمانی فرض می‌شود که محتوای فایل را برای ایندکس و پردازش نیاز دارد.

```
+-------------------------------------------------------------------------+
|                         Local AI / RAG Engine                           |
|       (Ollama / Local LLM / Vector DB Ingestion Pipeline)               |
+-------------------------------------------------------------------------+
       |                                                    |
       | [Mode 1: Basic Auth]                               | [Mode 2: Service Token]
       | Authorization: Basic <user:pass>                   | Authorization: Bearer <ai_token>
       |                                                    | X-On-Behalf-Of: <user_uid>
       v                                                    v
+-------------------------------------------------------------------------+
|                  Enterprise Archive AI Gateway Controller               |
|                 (OCA\ArchiveAutoTag\Controller\AiFileController)         |
+-------------------------------------------------------------------------+
                                   |
                                   v
+-------------------------------------------------------------------------+
|                         AiFileService Gateway                           |
| 1. Authenticate Request (Basic vs Bearer Token vs X-API-KEY)            |
| 2. Resolve Effective Actor (Direct User or Delegated On-Behalf-Of)      |
| 3. Query Node via RootFolder by Numeric fileId                          |
| 4. Security Enforcement: FileOwnershipService::canUserAccessFile()      |
| 5. Stream binary handle chunked directly to client (O(1) Memory)        |
| 6. Audit Logging: Record transaction in oc_archive_ai_audit             |
+-------------------------------------------------------------------------+
         |                                                 |
         v                                                 v
+-----------------------------------+    +--------------------------------+
|  Archive Physical Storage         |    | PostgreSQL Database            |
|  (Local / Shared Group Storage)   |    | (oc_archive_ai_audit table)    |
+-----------------------------------+    +--------------------------------+
```

## ۵. رفتار پیش‌فرض Nextcloud و شکاف موجود (Default Nextcloud Behavior & The Gap)
* در معماری پیش‌فرض Nextcloud، دانلود فایل تنها از طریق اندپوینت‌های پیچیده و کند WebDAV (`/remote.php/dav/files/...`) با استفاده از مسیر متنی کامل فایل امکان‌پذیر است.
* وب‌داو فاقد امکان دسترسی مستقیم با شناسه عددی فایل (`fileId`) به صورت یک مرحله‌ای است و کلاینت ابتدا باید مسیر کامل پوشه‌های سلسله‌مراتبی را بداند.
* وب‌داو پیش‌فرض فاقد مکانیزم توکن ماشین مجزا با تفویض هویت (`X-On-Behalf-Of`) است و هوش مصنوعی باید اعتبارنامه تک‌تک کاربران را ذخیره کند که نقض آشکار امنیت است.
* هیچ لاگ اختصاصی برای مانیتورینگ مصرف هوش مصنوعی و تفکیک آن از دانلودهای کاربران وب وجود ندارد.

## ۶. رویکردهای بررسی‌شده و دلایل رد گزینه‌های نامناسب (Evaluated Approaches & Rejected Options)
* **گزینه ۱: استفاده از WebDAV پیش‌فرض Nextcloud:** رد شد؛ زیرا نیازمند افشای مسیر ساختاری فایل‌ها است و از مدل توکن سرویس AI با تفویض داینامیک پشتیبانی نمی‌کند.
* **گزینه ۲: خواندن مستقیم فایل از دیسک سیستم‌عامل توسط هوش مصنوعی:** رد شد؛ نقض مهلک امنیت؛ دسترسی مستقیم به مسیرهای لینوکسی کلیه لایه‌های کنترلی، مجوزهای گروهی و برچسب‌های محرمانگی را دور می‌زند.
* **گزینه ۳: لود کل فایل در حافظه موقت PHP (`file_get_contents`):** رد شد؛ موجب بروز خطای `Memory Exhaustion` روی فایل‌های بزرگ سازمانی شده و کارایی سرور را مختل می‌سازد.

## ۷. مدل داده و تغییرات پایگاه داده (Data Model & Database Schema)
یک جدول اختصاصی برای ممیزی تمامی فراخوانی‌های هوش مصنوعی ایجاد شده و کلید توکن پیش‌فرض در تنظیمات اپلیکیشن ذخیره گردیده است:

### جدول `oc_archive_ai_audit`
```sql
CREATE TABLE oc_archive_ai_audit (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    actor_uid VARCHAR(64) NOT NULL,
    client_id VARCHAR(64) NOT NULL DEFAULT 'ai_assistant',
    file_id BIGINT NOT NULL,
    file_name VARCHAR(255) NOT NULL DEFAULT '',
    auth_type VARCHAR(32) NOT NULL DEFAULT 'BASIC_AUTH',
    result VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    client_ip VARCHAR(45) NOT NULL DEFAULT '',
    bytes_served BIGINT NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    created_at BIGINT NOT NULL DEFAULT 0
);

CREATE INDEX arch_ai_aud_rid_idx ON oc_archive_ai_audit (request_id);
CREATE INDEX arch_ai_aud_act_idx ON oc_archive_ai_audit (actor_uid);
CREATE INDEX arch_ai_aud_fid_idx ON oc_archive_ai_audit (file_id);
CREATE INDEX arch_ai_aud_res_idx ON oc_archive_ai_audit (result);
```

### تنظیمات اپلیکیشن در `oc_appconfig`
* `archive_autotag / ai_service_token`: کلید محرمانه رمزنگاری‌شده توکن سرویس AI.
* `archive_autotag / ai_default_user`: کاربر پیش‌فرض اجرای توکن در صورت عدم ارسال هدر تفویض (پیش‌فرض: `api_worker`).

## ۸. ساختار کد و فایل‌های پیاده‌سازی (Code Structure & Implementation Files)
* [Version2000Date20260916000001.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Migration/Version2000Date20260916000001.php): مایگریشن پایگاه‌داده جهت ایجاد جدول ممیزی و مقداردهی اولیه توکن سرویس.
* [AiFileService.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Service/AiFileService.php): سرویس مرکزی اعتبارسنجی احراز هویت، انطباق با مالکیت فایل، استخراج هندل باینری و ثبت ممیزی.
* [AiFileController.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Controller/AiFileController.php): کنترلر MVC برای مدیریت اندپوینت‌های فایل، متادیتا، سند OpenAPI و صفحه Swagger UI.
* [swagger.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/templates/swagger.php): قالب مستقل و درون‌برنامه‌ای رابط کاربری تعاملی تست و مستندات API.
* [routes.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/appinfo/routes.php): ثبت مسیرهای وب و OCS برای سرویس AI.
* [test_ai_file_retrieval_api.py](file:///home/alborz/enterprise-archive-system/tests/test_ai_file_retrieval_api.py): مجموعه آزمون‌های خودکار شامل ۱۰ سناریوی جامع اعتبارسنجی.

## ۹. هوک‌ها، ایونت‌ها و نقاط اتصال به هسته (Hooks, Events & Integration Points)
* **اتصال به `IRootFolder::getByIdInPath`:** واکشی امن نود فایل در محدوده استوریج یا پوشه‌های به اشتراک گذاشته‌شده متناظر با کاربر.
* **اتصال به `FileOwnershipService::canUserAccessFile`:** فراخوانی موتور انحصاری محاسبه دسترسی که روابط مالکیتی، اشتراک‌های گروهی و استثناهای مدیریتی را ارزیابی می‌کند.
* **اتصال به `ISystemTagObjectMapper`:** استخراج ایمن برچسب‌های متصل به فایل در پاسخ متادیتا.

## ۱۰. منطق گام‌به‌گام پردازش (Step-by-Step Execution Logic)
1. **دریافت درخواست:** کلاینت اندپوینت `GET /api/v1/ai/files/{fileId}` را فراخوانی می‌کند.
2. **بررسی احراز هویت:**
   * ابتدا سشن یا هدر Basic Auth بررسی می‌شود. در صورت اعتبار، `actor_uid` برابر نام‌کاربری واردشده تعیین می‌گردد.
   * در صورت عدم وجود سشن، هدر `Authorization: Bearer <token>` یا `X-API-KEY` استخراج شده و با توکن ثبت‌شده در سیستم مقایسه می‌شود.
   * در صورت تطابق توکن، در صورت وجود هدر `X-On-Behalf-Of`، کاربر تفویض‌شده به عنوان `actor_uid` قرار می‌گیرد.
3. **اعتبارسنجی نود فایل:** نود مربوط به شناسه فایل از مخزن بازخوانی شده و اطمینان حاصل می‌شود که از نوع دایرکتوری نباشد.
4. **اعمال قوانین دسترسی:** سرویس `FileOwnershipService` دسترسی کاربر تعیین‌شده را بررسی می‌کند؛ در صورت عدم دسترسی، خطای ۴۰۳ صادر می‌گردد.
5. **استریم باینری محتوا:** فایل با بافر استاندارد از دیسک خوانده شده و به عنوان استریم مستقیم با هدرهای استاندارد دانلود ارسال می‌گردد.
6. **ثبت ممیزی:** تمامی جزئیات تراکنش (شناسه درخواست، کاربر، کلاینت، نتیجه، بایت ارسالی) در جدول `oc_archive_ai_audit` ذخیره می‌شود.

## ۱۱. وابستگی‌ها و پیش‌نیازها (Dependencies & Prerequisites)
* فعال بودن اپلیکیشن `archive_autotag` با نسخه 2.0.0 یا بالاتر.
* اجرای موفق مایگریشن ۲۰۰۰ در دیتابیس PostgreSQL.
* وجود کاربر معتبر در سیستم برای حالت تفویض هویت (`X-On-Behalf-Of`).

## ۱۲. مدیریت خطا و سناریوهای استثنا (Failure Modes & Edge Cases)
* **عدم ارسال اعتبارنامه:** بازگشت کد خطای `401 Unauthorized` همراه با هدر چالش `WWW-Authenticate`.
* **شناسه فایل نامعتبر یا پوشه:** بازگشت خطای `400 Bad Request` با پیام صریح مبنی بر عدم امکان دریافت پوشه به صورت فایل.
* **تلاش برای IDOR یا دسترسی غیرمجاز بین دپارتمان‌ها:** بازگشت خطای `403 Forbidden` یا `404 Not Found` به نحوی که اطلاعات ساختاری فاش نشود.
* **عدم وجود فایل در سیستم:** بازگشت خطای `404 Not Found`.
* **کاربر تفویض‌شده نامعتبر:** بازگشت کد `401 Unauthorized` و ثبت رویداد مشکوک در لاگ.

## ۱۳. دسترسی‌ها، نقش‌ها و امنیت (Security, Roles & Permissions)
* **کاربران عادی:** دسترسی به فایل‌ها صرفاً در چارچوب اسناد مجاز خود یا گروه دپارتمانی مربوطه.
* **مدیران ارشد (`admin`):** دسترسی کامل به دریافت مستقیم تمامی فایل‌ها و مشاهده لاگ ممیزی.
* **توکن سرویس AI:** دسترسی ایزوله به متادیتا و محتوای باینری فایل‌ها صرفاً با اعمال دسترسی کاربر پیش‌فرض یا کاربر تفویض‌شده.

## ۱۴. APIها و پروتکل‌ها (APIs & Protocols)
* **دریافت استریم باینری فایل:**
  * `GET /index.php/apps/archive_autotag/api/v1/ai/files/{fileId}`
  * هدرها: `Authorization: Bearer <token>`, `X-On-Behalf-Of: <uid>`, `X-Client-ID: <client>`
* **استعلام متادیتای پاکسازی‌شده:**
  * `GET /index.php/apps/archive_autotag/api/v1/ai/files/{fileId}/metadata`
* **سند استاندارد OpenAPI 3.0.3:**
  * `GET /index.php/apps/archive_autotag/api/openapi.json`
* **واسط کاربری Swagger UI تعاملی و آفلاین:**
  * `GET /index.php/apps/archive_autotag/api/docs`

## ۱۵. تنظیمات و متغیرهای پیکربندی (Configuration & Parameters)
* `ai_service_token`: کلید احراز هویت توکن سرویس ماشین (پیش‌فرض تولیدشده تصادفی ۶۴ کاراکتری).
* `ai_default_user`: کاربر پس‌زمینه پیش‌فرض سرویس AI (پیش‌فرض: `api_worker`).

## ۱۶. عملکرد و مقیاس‌پذیری (Performance & Scalability)
* مصرف حافظه ثابت ($O(1)$) به ازای هر اتصال هم‌زمان بدون لود محتوا در رم.
* ایندکس‌گذاری بهینه جداول پایگاه‌داده بر روی ستون‌های کلیدی جهت پاسخگویی به استعلام‌ها در کمتر از ۵ میلی‌ثانیه.

## ۱۷. قابلیت مشاهده‌پذیری، لاگ‌ها و آدیت (Observability & Logging)
* جدول اختصاصی `oc_archive_ai_audit` ثبت‌کننده دقیق هرگونه درخواست مجاز یا مسدودشده است.
* شناسه `X-Request-ID` رهگیری تراکنش‌ها در سامانه‌های متمرکز SIEM و Elastic را ممکن می‌سازد.

## ۱۸. سناریوهای تست و اعتبارسنجی (Testing & Verification Scenarios)
* **تست ۱:** ارسال درخواست بدون احراز هویت و دریافت ۴۰۱.
* **تست ۲:** دریافت موفق فایل با اعتبارنامه کاربری و تطابق شناسه.
* **تست ۳:** بررسی انسداد نفوذ عرضی (IDOR) بین دپارتمان‌ها و دریافت ۴۰۳/۴۰۴.
* **تست ۴:** تلاش برای دریافت پوشه به جای فایل و دریافت ۴۰۰.
* **تست ۵:** فراخوانی با Bearer Token معتبر و دریافت فایل با تفویض `X-On-Behalf-Of`.
* **تست ۶:** تلاش برای جعل کاربر تفویض‌شده ناموجود و رد درخواست.
* **تست ۷:** دریافت متادیتای فایل و اثبات عدم نشت مسیرهای فیزیکی لینوکس.
* **تست ۸:** تایید ثبت رکوردها در جدول ممیزی `oc_archive_ai_audit`.
* **تست ۹:** استعلام مشخصات فنی OpenAPI 3.0.3.
* **تست ۱۰:** بارگذاری پرتال تعاملی Swagger UI و عملکرد بدون ارتباط اینترنتی.
* اسکریپت آزمون: `tests/test_ai_file_retrieval_api.py` (قبولی ۱۰۰٪ آزمون‌ها).

## ۱۹. بدهی فنی و محدودیت‌های شناخته‌شده (Technical Debt & Known Limitations)
* در نسخه حاضر، سقف حجم دریافت هم‌زمان فایل به محدودیت پهنای باند شبکه و Time-out وب‌سرور وابسته است؛ در نسخه‌های آتی می‌توان قابلیت Resumable Range Requests (HTTP 206) را افزود.

## ۲۰. تحلیل اثر بر سایر نیازمندی‌ها (Impact Analysis & Cross-Requirement Matrix)
* **نیازمندی ۰۲ (برچسب‌گذاری خودکار):** اندپوینت متادیتا تگ‌های سلسله‌مراتبی تولیدشده در نیازمندی ۰۲ را جهت زمینه‌سازی RAG به دستیار ارائه می‌دهد.
* **نیازمندی ۰۸ (کنترل دسترسی):** هسته احراز صلاحیت فایلی این نیازمندی ۱۰۰٪ متکی بر قوانین نیازمندی ۰۸ است.
* **نیازمندی ۱۲ (ایزولاسیون کامل دپارتمان‌ها):** ایزولاسیون دپارتمانی در سطح هوش مصنوعی نیز بدون نقص اعمال می‌شود.

## ۲۱. چک‌لیست استقرار، بکاپ و ریکاوری (Deployment, Backup & Recovery Checklist)
* [x] ارتقای نسخه اپلیکیشن به 2.0.0 در فایل `info.xml`.
* [x] اجرای مایگریشن دیتابیس ۲۰۰۰ و ایجاد جدول `oc_archive_ai_audit`.
* [x] کپی کدهای جدید کنترلر، سرویس و قالب مستندات به `custom_apps`.
* [x] اجرای آزمون‌های جامع ۱۰گانه با موفقیت ۱۰۰٪.
* [x] همگام‌سازی و پشتیبان‌گیری در اسکریپت بکاپ روزانه.

## ۲۲. ارتباط با سایر اسناد (Related Documents)
* سند [02_hierarchical_parent_folder_tagging.md](file:///home/alborz/enterprise-archive-system/docs/requirements/02_hierarchical_parent_folder_tagging.md)
* سند [08_access_control_and_tag_isolation.md](file:///home/alborz/enterprise-archive-system/docs/requirements/08_access_control_and_tag_isolation.md)
* سند [12_departmental_folder_hierarchy_and_multi_unit_isolation.md](file:///home/alborz/enterprise-archive-system/docs/requirements/12_departmental_folder_hierarchy_and_multi_unit_isolation.md)

## ۲۳. وضعیت نهایی (Final Implementation Status)
* **وضعیت پیاده‌سازی:** کامل، عملیاتی و ادغام‌شده در نسخه 2.0.0 سامانه.
* **پوشش تست:** قبولی ۱۰۰٪ آزمون‌های خودکار در `tests/test_ai_file_retrieval_api.py`.
* **امنیت و محرمانگی:** تطابق کامل با استانداردهای Air-Gapped، جلوگیری از IDOR و ممیزی کامل تراکنش‌ها.
