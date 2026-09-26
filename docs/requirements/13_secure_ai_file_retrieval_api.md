# سند نیازمندی ۱۳: رابط برنامه‌نویسی امن واکشی فایل برای هوش مصنوعی لوکال (Secure AI File Retrieval API & Hardened Delegated Identity)

## ۱. هدف و انگیزه (Goal & Motivation)
جهت پیاده‌سازی معماری پردازش هوش مصنوعی درون‌سازمانی (On-Premise AI / RAG Roadmap) در بستر کاملاً ایزوله (Air-Gapped) بدون اتصال به اینترنت، مدل‌های زبانی محلی (LLM) و خطوط پردازش متن (Vector DB / Ingestion Pipelines) نیازمند استخراج سریع، بهینه و ساختاریافته محتوای متنی و باینری اسناد آرشیو می‌باشند. هدف این نیازمندی ارائه یک لایه API پایدار، پرسرعت، کم‌مصرف و از نظر امنیتی کاملاً سخت‌گیرانه (Hardened) است که:
۱. تحویل مستقیم فایل‌های آرشیو بر اساس شناسه یکتای عددی (`fileId`) را به صورت استریم باینری ممکن سازد.
۲. با احراز هویت ماشین رمزنگاری‌شده قوی، چرخش بدون قطعی توکن (Zero-Downtime Rotation)، ابطال آنی (Instant Revocation) و ذخیره‌سازی صرفاً به صورت هش SHA-256 در پایگاه‌داده، هرگونه ریسک پیش‌بینی‌پذیری یا سرقت توکن را به صفر برساند.
۳. تفویض هویت هوشمند (`X-On-Behalf-Of`) را بر پایه مدل قطعی **Deny-by-default** و لیست‌های مجاز تفویض (Delegation Allowlists) پیاده‌سازی کرده و با مسدودسازی صریح جعل حساب‌های مدیریتی (`admin`)، مانع ارتقای غیرمجاز دسترسی (Privilege Escalation) گردد.
۴. فرآیند فراخوانی و تست را با رابط کاربری تعاملی، مستقل و ۱۰۰٪ آفلاین Swagger UI درون‌برنامه‌ای تسهیل نماید.

## ۲. نیازمندی‌های کارکردی (Functional Requirements)
* **استریم مستقیم و بهینه فایل با شناسه عددی (`fileId`):** اندپوینت `GET /api/v1/ai/files/{fileId}` امکان دانلود باینری بدون نیاز به دانستن مسیر پوشه‌های لینوکسی یا سلسله‌مراتب فایل‌سیستم سرور را فراهم می‌آورد.
* **استعلام متادیتای پاکسازی‌شده (Sanitized Metadata):** اندپوینت `GET /api/v1/ai/files/{fileId}/metadata` اطلاعات امن و ساختاریافته فایل (شناسه، نام، حجم، سایز انسانی، تایم‌استمپ، مالک و برچسب‌های فعال قابل رویت) را بدون افشای مسیرهای فیزیکی لینوکس برمی‌گرداند.
* **احراز هویت دوگانه مستقل و امن (Dual-Mode Authentication):**
  * **حالت کاربری:** پشتیبانی کامل از Session بومی یا HTTP Basic Auth استاندارد برای دسترسی مستقیم کاربر انسانی.
  * **حالت ماشینی (Service Principal):** پشتیبانی از هدر `Authorization: Bearer <token>` یا `X-API-KEY` متصل به جدول مدیریت توکن‌های هش‌شده ماشین.
* **تفویض هویت ایمن بر مبنای Deny-by-default (`X-On-Behalf-Of`):** امکان استفاده از توکن ماشین سرویس AI در چارچوب سیاست‌های تعریف‌شده (Allowlist کاربران و گروه‌ها) جهت اعمال بی‌درنگ محدودیت‌های RAG بر سطح دسترسی همان کاربر.
* **حفاظت قطعی در برابر جعل ادمین (Anti-Privilege-Escalation Gate):** هرگونه تلاش سرویس AI برای نمایندگی از حساب `admin` یا اعضای گروه ادمین به صورت پیش‌فرض بلافاصله با کد ۴۰۳ مسدود می‌گردد.
* **مدیریت چرخه عمر توکن (Token Lifecycle Management):** امکان صدور توکن‌های تصادفی امن (`random_bytes(32)`), چرخش توکن با دوره تنفس (Grace Period)، و ابطال فوری توکن‌های سازش‌یافته.
* **رعایت کامل و قطعی ACL آرشیو:** اتصال مستقیم به هسته `FileOwnershipService::canUserAccessFile` به نحوی که هیچ سرویس هوش مصنوعی نتواند به فایلی فراتر از دسترسی دپارتمانی کاربر تاییدشده دست یابد.
* **ممیزی ساختاریافته و غنی (`oc_archive_ai_audit`):** ثبت دقیق شناسه سرویس احرازشده، شناسه توکن، هویت تفویض‌شده، وضعیت سیاست تفویض، آدرس IP کلاینت و شناسه پیگیری `X-Request-ID`.
* **مستندسازی و پرتال تعاملی تست (Swagger UI & OpenAPI 3.0.3):**
  * ارائه سند استاندارد ماشین‌خوان در `/api/openapi.json`.
  * ارائه واسط کاربری تعاملی آفلاین و بدون CDN در `/api/docs`.

## ۳. نیازمندی‌های غیرتابعی (Non-Functional Requirements)
* **عملکرد و بهره‌وری حافظه ($O(1)$ RAM Overhead):** استفاده از استریم بافر مستقیم فایل‌سیستم بدون لود کامل فایل در رم PHP؛ تضمین پایداری برای فایل‌های چندگیگابایتی.
* **امنیت و عدم افشای ساختار دایرکتوری (Anti-IDOR & Zero Enumeration):** پاسخ‌های ۴۰۳ و ۴۰۴ هیچگونه اطلاعاتی از ساختار پوشه‌های داخلی سرور فاش نمی‌کنند.
* **ایزولاسیون کامل شبکه (100% Air-Gapped & Zero Data Egress):** واسط کاربری Swagger UI بدون وابستگی به هیچ کتابخانه خارجی (مانند unpkg یا cdnjs) عمل می‌کند.
* **مقاومت در برابر Timing Attacks:** بهره‌گیری از توابع `hash_equals` در مقایسه هش توکن‌ها و جلوگیری از استخراج بایت‌ها از طریق اندازه‌گیری زمان پاسخ.

## ۴. معماری و مدل مفهومی (Architecture & Hardened Concept)

```
+─────────────────────────────────────────────────────────────────────────+
|                         Local AI / RAG Engine                           |
|       (Ollama / Local LLM / Vector DB Ingestion Pipeline)               |
+─────────────────────────────────────────────────────────────────────────+
       │                                                    │
       │ [حالت ۱: کاربر مستقیم]                             │ [حالت ۲: توکن سرویس ماشینی]
       │ Authorization: Basic <user:pass>                   │ Authorization: Bearer nc_ai_...
       │                                                    │ X-On-Behalf-Of: <user_uid>
       ▼                                                    ▼
+─────────────────────────────────────────────────────────────────────────+
|                  Enterprise Archive AI Gateway Controller               |
|                 (OCA\ArchiveAutoTag\Controller\AiFileController)         |
+─────────────────────────────────────────────────────────────────────────+
                                   │
                                   ▼
+─────────────────────────────────────────────────────────────────────────+
|                         AiFileService Security Engine                   |
| 1. استخراج پیشوند و اعتبارسنجی هش SHA-256 در oc_archive_ai_tokens        |
| 2. بررسی وضعیت توکن (ACTIVE / GRACE_PERIOD / REVOKED / EXPIRED)         |
| 3. استخراج شناسه سرویس از oc_archive_ai_services و بررسی وضعیت فعال     |
| 4. بررسی تفویض هویت X-On-Behalf-Of بر پایه Deny-by-default              |
|    - مسدودسازی قطعی تفویض به ادمین (Anti-Admin Spoofing Gate)            |
|    - تطابق کاربر یا گروه هدف با oc_archive_ai_delegations                |
| 5. استخراج نود فایلی و اعمال FileOwnershipService::canUserAccessFile()  |
| 6. استریم باینری O(1) و ثبت ممیزی غنی در oc_archive_ai_audit            |
+─────────────────────────────────────────────────────────────────────────+
         │                                                 │
         ▼                                                 ▼
+───────────────────────────────────+    +────────────────────────────────+
|  Archive Physical Storage         |    | PostgreSQL Database            |
|  (استوریج محلی و دپارتمانی ایزوله) |    | (سرویس‌ها، توکن‌ها و ممیزی)    |
+───────────────────────────────────+    +────────────────────────────────+
```

## ۵. رفتار پیش‌فرض Nextcloud و شکاف موجود (Default Nextcloud Behavior vs Hardened Design)
* وب‌داو پیش‌فرض Nextcloud نیازمند ارسال مسیر متنی کامل است و دسترسی مستقیم با شناسه یکتای عددی (`fileId`) ندارد.
* وب‌داو فاقد مدل توکن سرویس ماشینی همراه با تفویض داینامیک امن (`X-On-Behalf-Of`) است و هوش مصنوعی باید پسورد هر کاربر را ذخیره کند.
* در نگارش اولیه، توکن سرویس استاتیک و قابل پیش‌بینی بوده و هر کاربری می‌توانست با هدر `X-On-Behalf-Of` هویت `admin` را جعل کند؛ در این معماری سخت‌گیرانه (v2.0.9)، سیستم مجهز به هویت مستقل سرویس، هش پایگاه‌داده و سیاست قطعی Deny-by-default گردیده است.

## ۶. رویکردهای بررسی‌شده و دلایل رد گزینه‌های نامناسب (Evaluated Alternatives)
* **رویکرد اول: استفاده از توکن سراسری در appconfig:** رد شد؛ به دلیل نبود تفکیک سرویس‌ها، عدم امکان ابطال مستقل و خطر افشای عمومی.
* **رویکرد دوم: پذیرش آزادانه X-On-Behalf-Of بدون Allowlist:** رد شد؛ خطر قطعی ارتقای دسترسی و دور زدن ایزولاسیون محرمانگی دپارتمان‌ها.
* **رویکرد سوم: ذخیره توکن‌ها به صورت متن آشکار (Plaintext):** رد شد؛ نقض اصول امنیت داده و خطر افشا در بکاپ‌ها. ذخیره صرفاً به صورت هش SHA-256 الزامی گردید.

## ۷. مدل داده و جداول پایگاه داده (Hardened Schema)

### ۱. جدول سرویس‌های ماشین: `oc_archive_ai_services`
```sql
CREATE TABLE oc_archive_ai_services (
    id BIGSERIAL PRIMARY KEY,
    service_id VARCHAR(64) NOT NULL UNIQUE,
    display_name VARCHAR(128) NOT NULL,
    description TEXT,
    default_actor_uid VARCHAR(64) NOT NULL DEFAULT 'api_worker',
    delegation_policy VARCHAR(32) NOT NULL DEFAULT 'DENY_ALL', -- DENY_ALL, SPECIFIC_USERS, SPECIFIC_GROUPS, SPECIFIC_USERS_AND_GROUPS
    allow_admin_delegation BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0
);
CREATE INDEX arch_ai_svc_id_idx ON oc_archive_ai_services (service_id);
```

### ۲. جدول توکن‌های هش‌شده و چرخش: `oc_archive_ai_tokens`
```sql
CREATE TABLE oc_archive_ai_tokens (
    id BIGSERIAL PRIMARY KEY,
    service_id VARCHAR(64) NOT NULL,
    token_name VARCHAR(128) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE', -- ACTIVE, REVOKED, EXPIRED, GRACE_PERIOD
    expires_at BIGINT NULL,
    grace_period_until BIGINT NULL,
    last_used_at BIGINT NULL,
    last_used_ip VARCHAR(45) NULL,
    created_by VARCHAR(64) NOT NULL DEFAULT 'system',
    created_at BIGINT NOT NULL DEFAULT 0
);
CREATE INDEX arch_ai_tok_pfx_idx ON oc_archive_ai_tokens (token_prefix);
CREATE INDEX arch_ai_tok_svc_idx ON oc_archive_ai_tokens (service_id);
CREATE INDEX arch_ai_tok_stat_idx ON oc_archive_ai_tokens (status);
```

### ۳. جدول قوانین تفویض مجاز: `oc_archive_ai_delegations`
```sql
CREATE TABLE oc_archive_ai_delegations (
    id BIGSERIAL PRIMARY KEY,
    service_id VARCHAR(64) NOT NULL,
    subject_type VARCHAR(16) NOT NULL, -- 'USER' or 'GROUP'
    subject_id VARCHAR(64) NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    CONSTRAINT arch_ai_del_uniq UNIQUE (service_id, subject_type, subject_id)
);
CREATE INDEX arch_ai_del_svc_idx ON oc_archive_ai_delegations (service_id);
```

### ۴. جدول ارتقایافته ممیزی: `oc_archive_ai_audit`
```sql
CREATE TABLE oc_archive_ai_audit (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    actor_uid VARCHAR(64) NOT NULL,
    client_id VARCHAR(64) NOT NULL DEFAULT 'ai_assistant',
    service_id VARCHAR(64) NOT NULL DEFAULT 'unknown',
    token_id BIGINT NULL,
    delegation_requested VARCHAR(64) NULL,
    delegation_status VARCHAR(32) NOT NULL DEFAULT 'NONE',
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

## ۸. ساختار کد و فایل‌های پیاده‌سازی (Code Structure & Files)
* [Version2200Date20260920000001.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Migration/Version2200Date20260920000001.php): مایگریشن پایگاه‌داده جهت ایجاد ساختار سرویس‌ها، توکن‌های هش‌شده، قوانین تفویض و گسترش جدول ممیزی.
* [AiServiceCommand.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Command/AiServiceCommand.php): ابزار جامع خط فرمان OCC جهت صدور توکن تصادفی، چرخش با بازه Grace، ابطال و مدیریت قوانین تفویض.
* [AiFileService.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Service/AiFileService.php): هسته مرکزی اعتبارسنجی توکن، ارزیابی سیاست تفویض بر پایه Deny-by-default، استخراج هندل باینری و ثبت ممیزی جامع.
* [AiFileController.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Controller/AiFileController.php): کنترلر وب برای مدیریت اندپوینت‌های استریم، متادیتا، تمایز کدهای ۴۰۱ و ۴۰۳، و سند OpenAPI 3.0.3.
* [swagger.php](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/templates/swagger.php): قالب درون‌برنامه‌ای، آفلاین و Air-Gapped مستندات API.
* [test_ai_file_retrieval_api.py](file:///home/alborz/enterprise-archive-system/tests/test_ai_file_retrieval_api.py): مجموعه آزمون‌های ۱۶گانه پوشش‌دهنده سناریوهای کارکردی و حملات امنیتی.

## ۹. هوک‌ها، ایونت‌ها و نقاط اتصال به هسته (Hooks & Integration Points)
* **اتصال به `IRootFolder::getById`:** واکشی امن نود شیء بر مبنای شناسه عددی.
* **اتصال به `FileOwnershipService::canUserAccessFile`:** اعمال قطعی و غیرقابل دور زدن قوانین مالکیت، اشتراک و ایزولاسیون دپارتمان.
* **اتصال به `ISystemTagObjectMapper`:** استخراج برچسب‌های فایل متناسب با سطح دسترسی کاربر تفویض‌شده.

## ۱۰. منطق گام‌به‌گام پردازش (Step-by-Step Security Execution Logic)
1. **دریافت درخواست:** کلاینت اندپوینت `GET /api/v1/ai/files/{fileId}` را صدا می‌زند.
2. **بررسی احراز هویت:**
   * در صورت وجود Basic Auth یا سشن وب، کاربر تایید می‌شود (`auth_type: BASIC_AUTH`).
   * در صورت ارسال توکن ماشینی، پیشوند ۱۲ کاراکتری واکشی شده و هش SHA-256 با رکوردهای `archive_ai_tokens` مقایسه می‌شود (`hash_equals`).
   * وضعیت توکن بررسی می‌گردد؛ توکن‌های `REVOKED`، `EXPIRED` یا خارج از `GRACE_PERIOD` بلافاصله با کد ۴۰۱ رد می‌شوند.
3. **ارزیابی تفویض هویت (`X-On-Behalf-Of`):**
   * در صورت عدم ارسال هدر، کاربر پیش‌فرض سرویس (`api_worker`) تعیین می‌گردد.
   * در صورت ارسال هدر، وجود کاربر بررسی می‌شود (در صورت عدم وجود: ۴۰۱).
   * اگر کاربر ادمین باشد، بررسی می‌شود آیا `allow_admin_delegation` فعال است یا خیر؛ در غیر این صورت، درخواست بلافاصله با **۴۰۳ Forbidden** مسدود می‌گردد.
   * بر اساس سیاست تفویض (`delegation_policy`)، بررسی می‌شود آیا کاربر یا گروهش در جدول `archive_ai_delegations` قرار دارد یا خیر. در غیر این صورت درخواست رد می‌شود (**Deny-by-default**).
4. **اعتبارسنجی نود و دسترسی فایل:** بررسی پوشه نبودن و ارزیابی `canUserAccessFile` بر اساس هویت نهایی.
5. **استریم باینری محتوا:** ارسال استریم به صورت O(1) رم بدون بافرینگ موقت در حافظه.
6. **ثبت ممیزی جامع:** ثبت رکورد کامل در `oc_archive_ai_audit` با ذکر `service_id`، `token_id` و `delegation_status`.

## ۱۱. وابستگی‌ها و پیش‌نیازها (Dependencies & Prerequisites)
* اپلیکیشن `archive_autotag` نسخه 2.0.9 یا بالاتر.
* اجرای موفق مایگریشن ۲۲۰۰ در دیتابیس PostgreSQL.

## ۱۲. مدیریت خطا و سناریوهای استثنا (Failure Modes & Edge Cases)
* **توکن نامعتبر، باطل‌شده یا منقضی:** بازگشت کد `401 Unauthorized` همراه با هدر چالش `WWW-Authenticate`.
* **تلاش برای جعل هویت ادمین (`X-On-Behalf-Of: admin`):** بازگشت کد `403 Forbidden` با پیام صریح مبنی بر غیرمجاز بودن تفویض به اکانت‌های مدیریتی.
* **تلاش برای نمایندگی خارج از Allowlist سرویس:** بازگشت کد `403 Forbidden` با رعایت کامل Deny-by-default.
* **شناسه فایل نامعتبر یا پوشه:** بازگشت خطای `400 Bad Request`.
* **تلاش برای دسترسی به فایل دپارتمان دیگر:** بازگشت `403 Forbidden` یا `404 Not Found`.

## ۱۳. دستورات خط فرمان مدیریت (OCC CLI Management)
* **مشاهده لیست سرویس‌ها:** `php occ archive:ai service-list`
* **ایجاد سرویس جدید:** `php occ archive:ai service-create <id> --name="Name" --policy=SPECIFIC_GROUPS`
* **صدور توکن تصادفی امن:** `php occ archive:ai token-create <service_id> --name="Token Label"`
* **چرخش توکن با دوره Grace:** `php occ archive:ai token-rotate <service_id> --grace-hours=48`
* **ابطال توکن:** `php occ archive:ai token-revoke --token=<prefix_or_id>`
* **افزودن قانون تفویض:** `php occ archive:ai delegation-add <service_id> --type=group --subject=SOC`
* **مشاهده قوانین تفویض:** `php occ archive:ai delegation-list <service_id>`

## ۱۴. سناریوهای تست و اعتبارسنجی ۱۶گانه (Verification & Test Suite)
اسکریپت خودکار `tests/test_ai_file_retrieval_api.py` شامل ۱۶ سناریوی قطعی با قبولی ۱۰۰٪ است:
1. عدم احراز هویت -> ۴۰۱.
2. دریافت موفق فایل با Basic Auth -> ۲۰۰ + هدرهای استاندارد.
3. انسداد نفوذ عرضی (IDOR) بین دپارتمان‌ها -> ۴۰۳/۴۰۴.
4. مدیریت فایل ناموجود و تلاش برای دریافت پوشه -> ۴۰۴ و ۴۰۰.
5. احراز هویت با Bearer Token معتبر و رد توکن نامعتبر.
6. تفویض هویت معتبر به کاربر مجاز در Allowlist با اعمال دقیق دسترسی.
7. رد کاربر تفویض‌شده ناموجود با ۴۰۱.
8. **دفاع در برابر جعل هویت ادمین:** تلاش برای `X-On-Behalf-Of: admin` و دریافت قطعی ۴۰۳.
9. **سیاست Deny-by-default:** تلاش برای تفویض در سرویس با سیاست محدود و دریافت ۴۰۳.
10. **ابطال آنی توکن:** ابطال توکن و دریافت بلافاصله ۴۰۱.
11. **چرخش بدون Downtime:** اثبات فعال بودن همزمان توکن جدید و توکن قدیمی در دوره Grace.
12. **امنیت دیتابیس:** اثبات اینکه توکن‌ها صرفاً به صورت هش SHA-256 در دیتابیس ذخیره شده‌اند.
13. دریافت متادیتای پاکسازی‌شده بدون نشت مسیرهای لینوکس.
14. ممیزی غنی در دیتابیس شامل `service_id`، `token_id` و `delegation_status`.
15. استعلام مشخصات فنی OpenAPI 3.0.3.
16. بارگذاری پرتال مستقل و کاملاً Air-Gapped مستندات Swagger UI.

## ۱۵. وضعیت نهایی (Final Implementation Status)
* **وضعیت پیاده‌سازی:** کامل، عملیاتی و ارتقایافته به نسخه 2.0.9 سامانه.
* **پوشش تست:** قبولی ۱۰۰٪ آزمون‌های ۱۶گانه خودکار در `tests/test_ai_file_retrieval_api.py`.
* **امنیت و استانداردهای رمزنگاری:** تطابق با سخت‌گیرانه‌ترین استانداردهای Air-Gapped، مجهز به هشینگ SHA-256 دیتابیس، ابطال و چرخش توکن، و انسداد مطلق Privilege Escalation.


---

## ۹. مدل معنایی چرخه حیات ممیزی و حسابداری دقیق بایت‌ها (AI Audit Semantic Model & Precise Byte Accounting)
> [!NOTE]
> *این بخش بر اساس تجمیع سند نیازمندی ۲۲ (AI Audit Semantic Model & Precise Byte Accounting) به این مرجع الحاق شده است.*

### ۹.۱. تفکیک ابعاد سه‌گانه وضعیت ممیزی
برای جلوگیری از تناقضات معنایی و ثبت موفقیت کاذب، رکوردهای جدول `oc_archive_ai_audit` بر پایه سه بعد مستقل بازطراحی شده‌اند:
1. **نتیجه تصمیم امنیتی (`decision_result`):** مقادیر `ALLOWED` (مجوز دسترسی صادر شد) یا `DENIED` (عدم احراز هویت یا نقض سد دسترسی).
2. **مرحله چرخه حیات (`lifecycle_stage`):** مقادیر `AUTH` (مرحله بررسی توکن)، `ACCESS_CHECK` (ارزیابی دسترسی فایل) یا `STREAMING` (جریان انتقال داده).
3. **وضعیت نهایی انتقال (`transfer_status`):** مقادیر `NONE` (عدم شروع انتقال)، `SUCCESS` (انتقال کامل فایل)، `CLIENT_ABORTED` (قطع ارتباط زودهنگام کلاینت هوش مصنوعی) یا `STORAGE_ERROR` (خطای خواندن از دیسک).

### ۹.۲. پاسخ استریم ممیزی‌شده (`AuditedStreamResponse`)
برای حل مشکل محاسبه اندازه اسناد در صورت قطع ناگهانی ارتباط توسط مدل یا ایجنت هوش مصنوعی، کلاس اختصاصی `AuditedStreamResponse` پیاده‌سازی شده است که بایت‌های واقعی تحویل‌داده‌شده به شبکه را در لحظه شمارش کرده و فیلد `bytes_served` را به صورت دقیق (و نه الزاماً برابر با حجم کل فایل) ذخیره می‌نماید.
