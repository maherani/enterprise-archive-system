# سند نیازمندی شماره ۲۲: بازطراحی مدل معنایی، چرخه حیات و حسابداری دقیق بایت‌ها در ممیزی هوش مصنوعی (AI Audit Semantic Model & Precise Byte Accounting)

---

## ۱. شرح نیازمندی و چالش‌های گذشته (Problem Statement & Context)
در نسخه‌های پیشین سامانه بایگانی اسناد سازمانی (Enterprise Archive System)، ممیزی فراخوانی اسناد توسط خطوط هوش مصنوعی (`AiFileController::getFile`) دچار یک خطای اساسی در مدل معنایی (Semantic Model) بود:
1. **ثبت موفقیت کاذب (False-Success Audit Logging):** متد `getFile()` پیش از آنکه استریم فایل واقعاً از منبع ذخیره‌سازی باز شود و داده‌ها به سمت کلاینت جریان یابند، رکورد ممیزی را با وضعیت `ALLOWED` و مقدار `bytes_served` برابر با کل اندازه فایل (`fileSize`) در دیتابیس ثبت می‌کرد.
2. **فقدان تفکیک مراحل چرخه حیات (Missing Lifecycle Stages):** تفکیکی بین احراز هویت سرویس/توکن (`AUTH`)، اعتبارسنجی دسترسی به منبع (`ACCESS_CHECK`)، مجوز دسترسی (`AUTHORIZED`)، آغاز استریم داده (`STREAMING`)، و پایان موفق یا لغو تبادل وجود نداشت.
3. **عدم تشخیص قطع ارتباط زودهنگام کلاینت (Premature Client Abort):** در صورتی که کلاینت هوش مصنوعی (مثلاً موتور RAG یا Agent) تنها بخش هدر یا چند بایت اول فایل را دریافت کرده و سوکت را قطع می‌کرد، ممیزی سامانه ادعا می‌کرد که تمامی بایت‌ها با موفقیت واکشی و تحویل داده شده‌اند.
4. **عدم ثبت خطاهای میانی خواندن فایل (Mid-Stream Storage Failures):** در صورت بروز خطای خواندن I/O از استوریج میانی یا پاک شدن فایل بین مرحله مجوز و استریم، رکورد ممیزی بدون بازتاب این خرابی باقی می‌ماند.

**هدف نیازمندی:** بازطراحی جامع زیرسیستم ممیزی واکشی اسناد هوش مصنوعی جهت تفکیک کامل تصمیم امنیتی (Security Decision) از وضعیت انتقال داده (Data Transfer Status)، ارائه چرخه حیات گام‌به‌گام با مراحل دقیق، محاسبه بلادرنگ بایت‌های واقعی تحویل‌شده (`bytes_served` در برابر `bytes_requested`)، ثبت قطعی قطع کلاینت یا خطای استوریج، و سازگاری کامل با استانداردهای ردیابی توزیع‌شده (`correlation_id`).

---

## ۲. مدل معنایی و چرخه حیات داده‌ها (Audit Semantic Model & Lifecycle)

### ۲.۱. تفکیک ابعاد سه‌گانه وضعیت
برای جلوگیری از هرگونه تناقض معنایی، رکوردهای ممیزی هوش مصنوعی اکنون بر اساس سه بعد مستقل تفکیک می‌شوند:

```
+---------------------------------------------------------------------------------------+
|                                    REQUEST ARRIVAL                                    |
|                               (request_id, correlation_id)                            |
+---------------------------------------------------------------------------------------+
                                           |
                                  [ 1. AUTH STAGE ]
                                           |
                   +-----------------------+-----------------------+
                   |                                               |
              [FAILED]                                         [SUCCESS]
         result: UNAUTHORIZED / FORBIDDEN                 actor_uid resolved
         transfer_status: NONE                            delegation checked
         stage: AUTH, bytes: 0/0                                   |
                                                                   v
                                                        [ 2. ACCESS_CHECK STAGE ]
                                                                   |
                                           +-----------------------+-----------------------+
                                           |                                               |
                                       [DENIED]                                        [ALLOWED]
                                  result: FORBIDDEN / NOT_FOUND                   result: ALLOWED
                                  transfer_status: NONE                           transfer_status: PENDING
                                  stage: ACCESS_CHECK                             stage: AUTHORIZED
                                  bytes: 0/0                                      bytes_requested: fileSize
                                                                                  bytes_served: 0
                                                                                           |
                                                                                           v
                                                                                [ 3. STREAM OPENING ]
                                                                                           |
                                                   +---------------------------------------+--------------------+
                                                   |                                                            |
                                             [FOPEN FAILS]                                                [FOPEN OK]
                                        transfer_status: STREAM_FAILED                           transfer_status: PENDING
                                        stage: FAILED, bytes: 0                                  stage: STREAMING
                                        HTTP 500 returned                                               |
                                                                                                        v
                                                                                           [ 4. CHUNKED STREAM LOOP ]
                                                                                              (8KB Blocks / Flush)
                                                                                                        |
                                                   +--------------------------------+-------------------+
                                                   |                                |                   |
                                            [CLIENT ABORT]                   [STORAGE ERROR]      [FULL TRANSFER]
                                        transfer_status: ABORTED         transfer_status: FAILED  transfer_status: COMPLETED
                                        stage: TERMINATED                stage: TERMINATED        stage: FINISHED
                                        bytes_served: <exact partial>    bytes_served: <partial>  bytes_served: == requested
                                        duration_ms recorded             duration_ms recorded     duration_ms recorded
```

### ۲.۲. ماتریس وضعیت‌ها و مقادیر مجاز

| مرحله (Stage) | نتیجه امنیتی (Result) | وضعیت انتقال (Transfer Status) | بایت‌های درخواستی (bytes_requested) | بایت‌های تحویل‌شده (bytes_served) | شرح و سناریو |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `AUTH` | `UNAUTHORIZED` / `FORBIDDEN` | `NONE` | `0` | `0` | شکست در احراز هویت توکن، انقضای توکن یا سیاست منع تفویض به ادمین |
| `ACCESS_CHECK` | `FORBIDDEN` | `NONE` | `0` | `0` | کلاینت احراز هویت شده ولی به دلیل ایزولاسیون بین‌دپارتمانی یا عدم مجوز مسدود شد |
| `ACCESS_CHECK` | `NOT_FOUND` | `NONE` | `0` | `0` | شناسه فایل در مخزن وجود ندارد یا برای دپارتمان مربوطه نامرئی است |
| `AUTHORIZED` | `ALLOWED` | `PENDING` | `fileSize` | `0` | دسترسی تایید شد؛ لاگ Fail-Closed قبل از ارسال بایت ثبت شد |
| `FAILED` | `ALLOWED` | `STREAM_FAILED` | `fileSize` | `0` | فایل در استوریج باز نشد (فایل بین مرحله چک و استریم حذف شده یا غیرقابل‌دسترس است) |
| `STREAMING` | `ALLOWED` | `PENDING` | `fileSize` | `> 0` | استریم داده‌ها در حال ارسال به کلاینت است |
| `TERMINATED` | `ALLOWED` | `ABORTED` | `fileSize` | `< fileSize` | سوکت توسط کلاینت قطع شد؛ مقدار دقیق بایت‌های ارسالی تا لحظه قطع ثبت شد |
| `TERMINATED` | `ALLOWED` | `FAILED` | `fileSize` | `< fileSize` | بروز خطای I/O هنگام خواندن استریم فایل از دیسک یا شبکه |
| `FINISHED` | `ALLOWED` | `COMPLETED` | `fileSize` | `== fileSize` | انتقال ۱۰۰٪ کامل تمام بایت‌ها با موفقیت پایان یافت |

---

## ۳. تغییرات ساختاری پایگاه داده (Database Migration Version2500)
فایل مایگریشن `apps/archive_autotag/lib/Migration/Version2500Date20260920000001.php`:
- افزودن ستون `bytes_requested` از نوع `BigInt` با مقدار پیش‌فرض `0`.
- افزودن ستون `transfer_status` از نوع `Varchar(32)` با مقدار پیش‌فرض `'NONE'`.
- افزودن ستون `stage` از نوع `Varchar(32)` با مقدار پیش‌فرض `'INIT'`.
- افزودن ستون `duration_ms` از نوع `Integer` با مقدار پیش‌فرض `0`.
- ایجاد ایندکس‌های کارایی روی `transfer_status` و `stage` برای گزارش‌گیری سریع داشبورد ممیزی.

---

## ۴. اجزای پیاده‌سازی‌شده (Implementation Components)

### ۴.۱. کلاس استریم ممیزی‌شده (`AuditedStreamResponse`)
- مسیر: `apps/archive_autotag/lib/Http/AuditedStreamResponse.php`
- ویژگی‌ها:
  - پیاده‌سازی اینترفیس `OCP\AppFramework\Http\ICallbackResponse`.
  - تنظیم `ignore_user_abort(true)` تا در صورت قطع ارتباط کلاینت، بلوک `finally` در PHP متوقف نشود و بتواند متریک‌های نهایی بایت‌ها و زمان را در دیتابیس ممیزی بنویسد.
  - حلقه خواندن و ارسال چانک‌های ۸ کیلوبایتی (`8192` بایت) به همراه `flush()` بلافاصله جهت تخلیه بافرهای خروجی.
  - بررسی بلادرنگ وضعیت سوکت با متد `connection_aborted()`.
  - اندازه‌گیری دقیق زمان تبادل با دقت میلی‌ثانیه (`hrtime`).
  - هوک‌های شبیه‌سازی تست‌های سیستمی (`X-Simulate-Abort-After-Bytes`, `X-Simulate-Stream-Failure`, `X-Simulate-Fopen-Failure`).

### ۴.۲. بروزرسانی کنترلر هوش مصنوعی (`AiFileController`)
- مسیر: `apps/archive_autotag/lib/Controller/AiFileController.php`
- ویژگی‌ها:
  - استخراج هدر ردیابی توزیع‌شده `X-Correlation-ID` و اتصال آن به تمام پاسخ‌ها و رکوردهای ممیزی.
  - تنظیم هدر `'X-Accel-Buffering' => 'no'` برای ممانعت از کش و بافرینگ کاذب ریورس‌پروکسی Nginx/Apache.
  - ثبت ممیزی اولیه Fail-Closed با وضعیت `AUTHORIZED` و `bytes_requested = fileSize` و `bytes_served = 0`.
  - ثبت ممیزی مراحل شکست در مرحله `AUTH` و `ACCESS_CHECK`.
  - استفاده از `AuditedStreamResponse` به جای استریم بدون ممیزی پیش‌فرض.

### ۴.۳. ارتقای سرویس ممیزی هوش مصنوعی (`AiFileService`)
- متد `updateTransferProgress($requestId, $bytesServed, $transferStatus, $stage, $durationMs, $errorMessage)`:
  - بروزرسانی وضعیت بایت‌های تحویل‌شده در جدول `archive_ai_audit`.
  - پشتیبانی از صف اضطراری (DLQ) از طریق `ReliableAuditService` در صورت افت موقت ارتباط دیتابیس در طول انتقال.

---

## ۵. ماتریس سناریوهای مرزی و تاب‌آوری (Edge-Case Resilience Matrix)

| سناریوی بحرانی | رفتار سیستم | رکورد نهایی در ممیزی | کد وضعیت HTTP |
| :--- | :--- | :--- | :--- |
| **قطع سوکت کلاینت در بایت ۸۱۹۲** | حلقه استریم قطع را تشخیص می‌دهد، ارسال متوقف می‌شود، بلوک `finally` اجرا می‌گردد. | `result: ALLOWED`, `bytes_served: 8192`, `transfer_status: ABORTED`, `stage: TERMINATED` | ۲۰۰ (شروع شده و قطع شد) |
| **خطای خواندن استوریج حین استریم** | استثنای I/O در حلقه چانک مهار شده، لاگ خطا ثبت می‌شود. | `result: ALLOWED`, `bytes_served: <n>`, `transfer_status: FAILED`, `stage: TERMINATED` | ۲۰۰ (قطع شد) |
| **عدم امکان باز کردن استریم (`fopen` شکست خورد)** | قبل از شروع ارسال خطا شناسایی می‌شود. | `result: ALLOWED`, `bytes_served: 0`, `transfer_status: STREAM_FAILED`, `stage: FAILED` | ۵۰۰ Server Error |
| **تلاش برای دسترسی بین‌دپارتمانی (IDOR)** | اعتبارسنجی ACL دسترسی را رد می‌کند. | `result: FORBIDDEN`, `bytes_served: 0`, `transfer_status: NONE`, `stage: ACCESS_CHECK` | ۴۰۳ Forbidden |
| **فراخوانی شناسه ناموجود** | اعتبارسنجی فایل متوجه نبود فایل در انبار می‌شود. | `result: NOT_FOUND`, `bytes_served: 0`, `transfer_status: NONE`, `stage: ACCESS_CHECK` | ۴۰۴ Not Found |
| **خرابی پایگاه داده حین آپدیت ممیزی** | سرویس ممیزی به صف اضطراری (DLQ) فایل سوییچ می‌کند. | ذخیره رکورد در `archive_audit_emergency.jsonl` جهت پخش بعدی با `occ` | مطابق نتیجه عملیات |

---

## ۶. نتایج اعتبارسنجی آزمون‌های خودکار (Verification & Test Results)

### ۶.۱. آزمون جامع مدل معنایی هوش مصنوعی (`test_ai_audit_semantics.py`)
- تعداد کل تست‌ها: ۸ مورد
- درصد موفقیت: ۱۰۰٪ (۸ از ۸ PASS)
1. `test_01_full_successful_retrieval_semantic_audit`: انتقال موفق ۱۰۰٪ فایل، تطابق کامل بایت‌ها (`bytes_served == 40154 == bytes_requested`)، ثبت `COMPLETED` و `FINISHED`.
2. `test_02_early_client_disconnect_abort_semantic_audit`: شبیه‌سازی قطع کلاینت در بایت ۸۱۹۲، ثبت دقیق `bytes_served = 8192`، ثبت `ABORTED` و `TERMINATED`.
3. `test_03_stream_read_error_semantic_audit`: شبیه‌سازی خطای خواندن رسانه، ثبت `FAILED` و `TERMINATED` به همراه پیام خطا.
4. `test_04_stream_open_failure_fopen_semantic_audit`: شبیه‌سازی شکست در `fopen`، بازگرداندن خطای ۵۰۰، ثبت `STREAM_FAILED` و `FAILED`.
5. `test_05_unauthenticated_request_semantic_audit`: درخواست بدون توکن، بازگرداندن ۴۰۱، ثبت مرحله `AUTH` و `UNAUTHORIZED`.
6. `test_06_forbidden_request_cross_department_semantic_audit`: پروب کاربر SOC روی فایل دپارتمان CERT، بازگرداندن ۴۰۳، ثبت مرحله `ACCESS_CHECK` و `FORBIDDEN`.
7. `test_07_not_found_file_semantic_audit`: شناسه فایل ناموجود، بازگرداندن ۴۰۴، ثبت مرحله `ACCESS_CHECK` و `NOT_FOUND`.
8. `test_08_correlation_and_client_tracking`: پایداری شناسه‌های `request_id`، `correlation_id`، `client_id`، `actor_uid` و `client_ip` در پایگاه داده.

### ۶.۲. آزمون‌های سازگاری گذشته‌نگر (Regression Suites)
- `tests/test_ai_file_retrieval_api.py`: ۱۶ از ۱۶ آزمون با موفقیت کامل پاس شدند (۱۰۰٪ بدون رگرسیون).
- `tests/test_reliable_audit_subsystem.py`: ۱۰ از ۱۰ آزمون با موفقیت کامل پاس شدند.
- مجموعه کامل سوئیت‌های اجرایی پروژه (`run_all_tests.py`): ۲۳ سوئیت آزمون یکپارچه بدون خطا.
