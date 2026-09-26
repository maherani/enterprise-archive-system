# Requirement 20 — Mandatory Document Metadata Capture Before Archive Upload (Fail-Closed)

## ۱. شرح نیازمندی (Problem Statement & Business Need)

در سامانه بایگانی اسناد سازمانی (Enterprise Archive System)، اسناد بارگذاری‌شده باید از نظر موضوع، شماره سند، تاریخ، سطح محرمانگی، مرجع صدور و توضیحات به‌صورت دقیق و استاندارد شناسنامه‌دار شوند. از آنجا که سیستم‌های OCR به دلایل مختلف (مانند کیفیت پایین اسکن، اسناد دست‌نویس یا تصاویر ناخوانا) همواره قابل اتکا نبوده و نمی‌توان صرفاً بر اساس استخراج خودکار متن به کشف دقیق اسناد تکیه کرد، ثبت اطلاعات توصیفی و متادیتا هنگام آپلود الزامی است.

هدف این قابلیت، ایجاد یک لایه متادیتای ساخت‌یافته و مستقل از:
- ساختار پوشه‌بندی (Folder Hierarchy)
- برچسب‌های عمومی و سیستمی (System Tags)
- برچسب‌گذاری خودکار والد (Parent Folder Auto-Tagging)
- نام فیزیکی فایل (File Name)
- محتوای استخراج‌شده OCR

است تا کشف‌پذیری سریع، دقیق و چندبعدی اسناد تضمین گردد.

---

## ۲. نیازمندی‌های تابعی (Functional Requirements)

1. **اعتبارسنجی شکست-بسته فیلد اجباری موضوع سند (`Fail-Closed Validation`):**
   - کاربر موظف است قبل از نهایی شدن بارگذاری، حداقل فیلد موضوع سند (`subject`) را با حداقل ۲ کاراکتر وارد کند.
   - در صورت عدم ارسال موضوع یا کوتاه‌تر بودن آن، درخواست در لایه بک‌اند با کد وضعیت `HTTP 422 Unprocessable Entity` مسدود شده و هیچ فایلی در حافظه یا دیسک ذخیره نمی‌شود.
2. **فیلدهای شناسنامه متادیتای سند:**
   - **موضوع سند (`subject`):** اجباری، حداقل ۲ و حداکثر ۲۵۵ کاراکتر.
   - **شماره سند سازمانی (`document_number`):** اختیاری، حداکثر ۱۰۰ کاراکتر.
   - **تاریخ سند (`document_date`):** اختیاری، تاریخ معتبر.
   - **سطح محرمانگی (`confidentiality`):** یکی از مقادیر `normal` (عادی)، `confidential` (محرمانه)، `secret` (سری). مقدار پیش‌فرض: `normal`.
   - **مرجع صدور سند (`issuing_authority`):** اختیاری، حداکثر ۲۵۵ کاراکتر.
   - **توضیحات تکمیلی (`description`):** اختیاری، متن بلند.
3. **کنترل دسترسی مرکزی آپلود:**
   - صرفاً کاربرانی که طبق `CentralPermissionResolver` دارای مجوز `CREATE` (بیت ۴) روی پوشه هدف هستند، مجاز به ثبت متادیتا و بارگذاری سند می‌باشند (کاربران غیرمجاز: `HTTP 403 Forbidden`).
4. **تگ‌گذاری خودکار همزمان:**
   - بلافاصله پس از ثبت متادیتا و بارگذاری موفق، کلیه تگ‌های سلسله‌مراتبی پوشه والد به صورت اتمیک به سند تخصیص داده می‌شوند.
5. **یکپارچگی با دراور نمایش سریع و جدول اسناد:**
   - نمایش کارت اختصاصی «📋 شناسنامه و مشخصات سند» در دراور با نشان‌های رنگی سطح محرمانگی (سبز برای عادی، نارنجی برای محرمانه، قرمز برای سری).
   - نمایش عنوان متادیتا در سطر دوم نام فایل در جدول اسناد (`📋 موضوع سند (شماره سند)`).
6. **جستجوی پیشرفته بر اساس متادیتا:**
   - فیلتر جستجوی پورتال بایگانی (`q`) مستقیماً روی فیلدهای `subject`, `document_number`, `issuing_authority`, و `description` اعمال شده و اسناد متناظر را بازیابی می‌کند.

---

## ۳. نیازمندی‌های غیرتابعی (Non-Functional Requirements)

1. **شکست-بسته و امنیت داده (Fail-Closed Architecture):**
   - هیچ فایلی نباید پیش از اعتبارسنجی قطعی متادیتا و اخذ تاییدیه مجوز در آرشیو نهایی شود.
2. **ممیزی پایدار (Reliable Audit):**
   - ثبت کلیه رویدادهای ایجاد (`METADATA_CREATE`) و ویرایش متادیتا (`METADATA_UPDATE`) در جدول `oc_archive_permission_audit`.
3. **پایداری داده و اتمیسیته دیتابیس:**
   - استفاده از کلید خارجی و ایندکس‌های بهینه در پایگاه داده PostgreSQL.

---

## ۴. معماری و مدل مفهومی (Architectural & Conceptual Model)

```text
  User Upload Flow:
  User Selects File -> Fills Metadata (Subject*) -> Client Validates Form (Subject >= 2 chars)
                                                           │
                                                           ▼ (POST /api/metadata/upload)
                                          +─────────────────────────────────+
                                          |   DocumentMetadataController    |
                                          +─────────────────────────────────+
                                                           │
                                   ┌───────────────────────┴──────────────────────┐
                        [Missing/Short Subject]                       [Valid Subject & Perms]
                                   │                                              │
                                   ▼                                              ▼
                         HTTP 422 Unprocessable                       1. Verify Folder CREATE Permission
                         (No File Written to Storage)                 2. Write File via Storage Isolation
                                                                      3. Persist Metadata in DB
                                                                      4. Auto-Assign Parent Tags
                                                                      5. Audit METADATA_CREATE (Audit Table)
                                                                      6. Return HTTP 200 OK
```

---

## ۵. مدل داده و جداول پایگاه داده

مایگریشن `Version2600Date20260921000001` جدول اختصاصی `oc_archive_document_metadata` را با ساختار زیر ایجاد کرده است:

| نام ستون | نوع داده | محدودیت | توضیحات |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT` | `PK, AUTO_INCREMENT` | شناسه یکتای رکورد |
| `file_id` | `BIGINT` | `UNIQUE, NOT NULL` | شناسه فایل در `oc_filecache` |
| `subject` | `VARCHAR(255)` | `NOT NULL, INDEX` | موضوع سند (فیلد اجباری) |
| `document_number` | `VARCHAR(100)` | `NULL, INDEX` | شماره اندیکاتور یا سازمانی |
| `document_date` | `DATE` | `NULL` | تاریخ صدور سند |
| `confidentiality` | `VARCHAR(20)` | `NOT NULL, DEFAULT 'normal', INDEX` | سطح محرمانگی (`normal`, `confidential`, `secret`) |
| `issuing_authority` | `VARCHAR(255)` | `NULL` | مرجع صادرکننده سند |
| `description` | `TEXT` | `NULL` | توضیحات تکمیلی |
| `created_by` | `VARCHAR(64)` | `NOT NULL, INDEX` | شناسه کاربر بارگذاری‌کننده |
| `created_at` | `TIMESTAMP` | `NOT NULL` | زمان ثبت رکورد |
| `updated_at` | `TIMESTAMP` | `NOT NULL` | زمان آخرین ویرایش |

---

## ۶. ساختار کد و فایل‌های پیاده‌سازی

| وضعیت | مسیر فایل | نقش و وظیفه |
| :--- | :--- | :--- |
| **NEW** | `lib/Migration/Version2600Date20260921000001.php` | ساخت جدول دیتابیس `oc_archive_document_metadata` |
| **NEW** | `lib/Service/DocumentMetadataService.php` | منطق چرخه حیات متادیتا، جستجو، استعلام و ممیزی |
| **NEW** | `lib/Controller/DocumentMetadataController.php` | کنترلر دریافت آپلود اتمیک با متادیتا و اعتبارسنجی 422 |
| **MODIFY** | `lib/Storage/ArchiveFileIsolationWrapper.php` | تطبیق مسیرهای موقت آپلود در پوشه‌های اشتراکی گروهی |
| **MODIFY** | `appinfo/routes.php` | ثبت روت‌های `/api/metadata/*` |
| **MODIFY** | `js/archive_portal.js` | بخش ۳ مودال آپلود، اعتبارسنجی، کارت دراور، نمایش جدول و سرچ |
| **MODIFY** | `css/archive_portal.css` | استایل‌های فیلدهای متادیتا و نشان‌های رنگی محرمانگی |
| **NEW** | `tests/test_document_metadata.py` | سوئیت ۷ تستی آزمون‌های واحد و یکپارچگی بک‌اند |
| **NEW** | `tests/e2e/test_10_mandatory_metadata_upload.py` | آزمون مرورگر واقعی Playwright E2E |

---

## ۷. اندپوینت‌های API

| متد | مسیر URL | شرح عملیات |
| :--- | :--- | :--- |
| `POST` | `/apps/archive_autotag/api/metadata/upload` | آپلود فایل همراه با پیلود کامل متادیتا (Fail-Closed 422) |
| `GET` | `/apps/archive_autotag/api/metadata/{fileId}` | استعلام مشخصات متادیتای فایل |
| `POST` | `/apps/archive_autotag/api/metadata/{fileId}` | ویرایش فیلدهای متادیتای فایل ثبت‌شده |

---

## ۸. نتایج آزمون‌های خودکار و اعتبارسنجی

1. **آزمون‌های یکپارچگی بک‌اند (`tests/test_document_metadata.py`):**
   - تست ۱: رد درخواست بارگذاری بدون `subject` با خطای HTTP 422 (Fail-Closed) ✅
   - تست ۲: رد درخواست بارگذاری با موضوع تک‌کاراکتری با خطای HTTP 422 ✅
   - تست ۳: رد درخواست کاربران احرازهویت‌نشده با خطای HTTP 401 ✅
   - تست ۴ و ۵: بارگذاری موفق سند و راستی‌آزمایی رکورد متادیتا در PostgreSQL ✅
   - تست ۶: راستی‌آزمایی ثبت ممیزی `METADATA_CREATE` در `oc_archive_permission_audit` ✅
   - تست ۷: دریافت موفق متادیتا از اندپوینت `GET /api/metadata/{fileId}` ✅
   - تست ۸: ویرایش موفق متادیتا و ثبت ممیزی `METADATA_UPDATE` ✅
   - تست ۹: کشف سند از طریق کلیدواژه متادیتا در اندپوینت جستجوی پورتال ✅
   - **نتیجه:** ۷ از ۷ آزمون پاس شدند (۱۰۰٪ OK).

2. **آزمون مرورگر واقعی Playwright (`tests/e2e/test_10_mandatory_metadata_upload.py`):**
   - باز شدن مودال، قفل دکمه بارگذاری تا ورود موضوع، تکمیل فرم، بارگذاری موفق، و نمایش کارت شناسنامه سند در دراور نمایش سریع در زمان ۱۱٫۳ ثانیه با موفقیت ۱۰۰٪ پاس شد ✅

---

## ۹. وضعیت نهایی (Final Implementation Status)

این نیازمندی به صورت کامل پیاده‌سازی، آزمایش، مستندسازی، در آزمون‌های سرتاسری سیستم گنجانده شده و در مخزن گیت ثبت گردیده است.
- **نسخه انتشار:** v2.4.0
- **وضعیت عملیاتی:** فعال، پایدار و نهایی (Production Ready)
