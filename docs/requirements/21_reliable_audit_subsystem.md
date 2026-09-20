# سند نیازمندی شماره ۲۱: سیستم ممیزی قابل‌اعتماد و نفوذناپذیر (Reliable Audit Subsystem & Fail-Closed Gating)

---

## ۱. شرح نیازمندی (Problem Statement & Business Need)
در سامانه‌های بایگانی اسناد سازمانی (Enterprise Archive System)، داده‌های ممیزی (Audit Logs) پایه و اساس اثبات‌پذیری قانونی، امنیت، پاسخ‌گویی (Accountability) و انطباق با استانداردهای امنیتی سازمانی هستند. در نسخه‌های پیشین، نگارش رکوردهای ممیزی در نقاط متعددی با رویکرد **Best-Effort** انجام می‌شد:
1. **بلعیدن خطای ممیزی و ادامه‌یافتن عملیات (Swallowed Failures):** در مواردی نظیر دسترسی هوش مصنوعی به اسناد، تغییر یا حذف برچسب‌ها و تایید درخواست‌های پوشه، در صورت خطای پایگاه داده در ثبت لاگ ممیزی، خطا با `catch` نادیده گرفته می‌شد و عملیات حساس ادامه می‌یافت که منجر به فقدان ردپا (Audit Trail Gap) می‌گردید.
2. **فقدان جدول اختصاصی برای ممیزی مجوزها:** رویدادهای مربوط به اعطای مجوز دسترسی فایل (`grantAccess`)، لغو دسترسی (`revokeAccess`) و حذف قطعی رکوردهای دسترسی (`purgeGrant`) ثبت ممیزی ساختاریافته در دیتابیس نداشتند.
3. **ریسک جعل و تزریق در لاگ‌ها (Audit Log Injection):** عدم پاک‌سازی کاراکترهای کنترلی، شکست خط (CRLF: `\r\n`) و بایت صفر (`\x00`) می‌توانست امکان جعل رویدادها را در صورت ثبت متنی فراهم سازد.
4. **فقدان مکانیزم تاب‌آوری در برابر قطع دیتابیس (Lack of DLQ):** در صورت افت گذرای ارتباط پایگاه داده، رکوردهای ممیزی حساس از بین می‌رفتند و امکان بازیابی بعدی آن‌ها وجود نداشت.

**هدف نیازمندی:** بازطراحی جامع زیرسیستم ممیزی، طبقه‌بندی دقیق عملیات حساس به دو رده **Audit-Required** (با تضمین ممانعت Fail-Closed) و **Audit-Best-Effort** (جهت حفاظت در برابر DoS)، ایجاد جدول پایگاه داده `oc_archive_permission_audit`، تعبیه صف اضطراری (Emergency Dead Letter Queue - DLQ)، پاک‌سازی کامل ورودی‌ها در برابر Log Injection، و ارائه ابزارهای مانیتورینگ سلامت و استریم لاگ‌ها.

---

## ۲. طبقه‌بندی عملیات و ماتریس رفتار شکست (Audit Classification & Failure Matrix)

| عملیات (Operation) | رده ممیزی (Classification) | رفتار در صورت شکست دیتابیس (Failure Behavior) | مرز تراکنش / مکانیزم |
| :--- | :--- | :--- | :--- |
| **Folder Approval** | `Audit-Required` | **Fail-Closed:** تراکنش بازگردانده شده و درخواست تایید نمی‌شود. | تراکنش اتمیک دیتابیس + ذخیره در DLQ |
| **Folder Rejection** | `Audit-Required` | **Fail-Closed:** تراکنش Rollback شده و وضعیت پوشه تغییر نمی‌کند. | تراکنش اتمیک دیتابیس + ذخیره در DLQ |
| **Tag Create** | `Audit-Required` | **Fail-Closed:** ایجاد تگ در Nextcloud متوقف و لغو می‌شود. | ثبت پیش از پاسخ موفقیت + ذخیره در DLQ |
| **Tag Delete** | `Audit-Required` | **Fail-Closed:** تراکنش حذف لغو و Rollback می‌شود. | تراکنش اتمیک دیتابیس + قفل بدبینانه سطری |
| **Tag Assign** | `Audit-Best-Effort` | **Fallback to DLQ:** انتساب تگ انجام می‌شود و لاگ در صف ثبت می‌گردد. | ذخیره مقاوم در فایل اضطراری |
| **AI File Retrieval** | `Audit-Required` | **Fail-Closed:** استریم فایل قبل از ارسال حتی یک بایت مسدود و خطای 503 برمی‌گردد. | بررسی قطعی ثبت ممیزی قبل از باز کردن استریم فایل |
| **AI Auth Failure** | `Audit-Best-Effort` | **Anti-DoS Resilience:** درخواست مسدود می‌شود؛ لاگ تلاش غیرمجاز در DLQ ثبت می‌گردد. | عدم کرش و عدم اجازه حمله DoS از طریق پر کردن دیتابیس |
| **Permission Grant** | `Audit-Required` | **Fail-Closed:** مجوز اعطا نمی‌شود و تراکنش Rollback می‌گردد. | تراکنش دیتابیس جدول `archive_permission_audit` |
| **Permission Revoke** | `Audit-Required` | **Fail-Closed:** لغو مجوز ثبت نمی‌شود و تغییرات Rollback می‌گردد. | ثبت مجوز قبلی (Prev Permissions) + تراکنش دیتابیس |
| **Permission Purge** | `Audit-Required` | **Fail-Closed:** رکوردهای حذف لغو و تراکنش Rollback می‌شود. | ثبت وضعیت قبلی + تراکنش دیتابیس |

---

## ۳. اجزای معماری پیاده‌سازی‌شده (Architectural Components)

### ۳.۱. سرویس ممیزی قابل‌اعتماد (`ReliableAuditService`)
- مسیر: `apps/archive_autotag/lib/Service/ReliableAuditService.php`
- وظایف:
  - `recordRequired($table, $data, $transactionalDb)`: تضمین ثبت لاگ در دیتابیس؛ در صورت بروز استثنا، رکورد را در DLQ ذخیره کرده و با پرتاب استثنای `AuditRequiredException` عملیات را لغو می‌کند (Fail-Closed).
  - `recordBestEffort($table, $data)`: ثبت لاگ با بازخورد موفقیت؛ در صورت شکست دیتابیس، بدون ایجاد وقفه رکورد را در DLQ ذخیره می‌کند.
  - `sanitize($input)`: تبدیل کاراکترهای `\r\n` به فاصله و حذف بایت‌های کنترلی برای جلوگیری از جعل لاگ.
  - `flushEmergencyDlq()`: پردازش خط‌به‌خط رکوردهای فایل اضطراری `archive_audit_emergency.jsonl` و ورود دسته‌جمعی به پایگاه داده.
  - `getAuditHealth()`: ارائه آمار زنده جداول ممیزی، وضعیت ارتباط دیتابیس، و تعداد رکوردهای معلق در صف DLQ.
  - `getUnifiedAuditStream()`: ارائه جریان تجمیعی رکوردهای ممیزی ۴ دامنه (مجوز، هوش مصنوعی، تگ‌ها و درخواست‌های پوشه).

### ۳.۲. ساختار پایگاه داده و مایگریشن نسخه ۲۴۰۰
- مسیر: `apps/archive_autotag/lib/Migration/Version2400Date20260920000001.php`
- جدول جدید `oc_archive_permission_audit`:
  - `id`: شناسه عددی یکتا (BigInt, PK)
  - `request_id`, `correlation_id`: شناسه‌های ردیابی درخواست
  - `actor_uid`: کاربری که مجوز را تغییر داده است
  - `file_id`: شناسه فایل مورد نظر
  - `grantee_type`: نوع گیرنده مجوز (`user` یا `group`)
  - `grantee_id`: شناسه گیرنده مجوز
  - `action`: نوع عملیات (`grant`, `revoke`, `purge`)
  - `permissions`: ماسک بیت عددی جدید
  - `prev_permissions`: ماسک بیت عددی قبلی برای امکان ردیابی تفاوت‌ها
  - `result`: نتیجه عملیات (`success`, `failure`)
  - `client_ip`: آدرس IP مبدا
  - `error_info`: شرح خطا در صورت بروز
  - `created_at`: زمان ثبت (Epoch Timestamp)
- ارتقای سایر جداول:
  - افزودن `request_id`, `correlation_id`, `client_ip` و `error_info` به `oc_archive_tag_audit`
  - افزودن `correlation_id` و `client_ip` به `oc_archive_folder_request_audit`
  - افزودن `correlation_id` به `oc_archive_ai_audit`

### ۳.۳. فرمان حاکمیت ممیزی در CLI نکست‌کلود (`AuditGovernanceCommand`)
- مسیر: `apps/archive_autotag/lib/Command/AuditGovernanceCommand.php`
- دستورات در دسترس:
  ```bash
  # مشاهده وضعیت سلامت، وضعیت ارتباط دیتابیس و تعداد لاگ‌های معلق در DLQ
  php occ archive:audit:gov health

  # بازپخش و ثبت مجدد رکوردهای معلق در صف اضطراری درون دیتابیس
  php occ archive:audit:gov flush-dlq

  # شبیه‌سازی شکست ممیزی برای راستی‌آزمایی Fail-Closed
  php occ archive:audit:gov simulate-failure <operation>
  ```

### ۳.۴. رابط کاربری و امنیت دسترسی APIها
- مسیرها در `routes.php`:
  - `GET /api/ai/audit/health`: مانیتورینگ سلامت زیرسیستم ممیزی (صرفاً مخصوص ادمین سیستم)
  - `GET /api/ai/audit/stream`: دریافت زنده وقایع ممیزی از تمام دامنه‌ها
  - `POST /api/ai/audit/flush-dlq`: تخلیه و ثبت صف اضطراری
  - `POST /api/ai/audit/simulate-failure`: تست تزریق خطا
- تمامی کاربران غیرادمین (نظیر ادمین‌های گروه SOC و CERT) در فراخوانی این اندپوینت‌ها با خطای `403 Forbidden` مواجه می‌شوند.

---

## ۴. نتایج آزمون‌های اعتبارسنجی (Automated Verification)
مجموعه آزمون خودکار [`tests/test_reliable_audit_subsystem.py`](file:///home/alborz/enterprise-archive-system/tests/test_reliable_audit_subsystem.py) شامل سناریوهای آزمون زیر است:
- `test_01`: صحت API وضعیت سلامت سیستم ممیزی و ارتباط پایگاه‌داده.
- `test_02`: ممیزی اجباری (Audit-Required) در ایجاد و حذف تگ‌های گروهی.
- `test_03`: پایایی و ثبت دقیق Grant، Revoke و Purge در `oc_archive_permission_audit`.
- `test_04`: مکانیزم Fail-Closed ممیزی هوش مصنوعی پیش از استریم فایل (عدم نشت حتی ۱ بایت در صورت خطای ممیزی).
- `test_05`: مدیریت بهینه (Best-Effort) در تلاش‌های غیرمجاز هوش مصنوعی جهت جلوگیری از حملات منع سرویس.
- `test_06`: پاک‌سازی کاراکترهای تزریقی و CRLF (Log Injection Defense).
- `test_07`: پایایی صف اضطراری DLQ و بازپخش موفقیت‌آمیز آن با دستور `occ archive:audit:gov flush-dlq`.
- `test_08`: استریم یکپارچه وقایع ممیزی برای رابط کاربری.
- `test_09`: اعتبارسنجی رفتار Fail-Closed اندپوینت شبیه‌سازی خطا.
- `test_10`: محدودسازی دسترسی کاربران غیرادمین و صدور خطای 403 Forbidden.

**نتیجه تست:** ۱۰ از ۱۰ آزمون با موفقیت کامل پاس شدند (100% PASS).
