# مأموریت: طراحی و پیاده‌سازی API امن ارائه یک فایل به دستیار هوشمند لوکال، همراه با مستندسازی و تست در Swagger

در پروژه **Enterprise Archive System** یک نیازمندی جدید دارم:

می‌خواهم یک API امن و کنترل‌شده ایجاد شود که یک **دستیار هوشمند داخلی سازمان** بتواند با فراخوانی آن، یک فایل مشخص را از سامانه آرشیو دریافت کند.

این قابلیت بخشی از معماری اتصال آینده سامانه آرشیو به یک دستیار هوشمند لوکال / On-Premise است.

در این مرحله تمرکز فقط روی:

**دریافت امن یک فایل مشخص از طریق API**

است.

اتصال مستقیم به Ollama، AnythingLLM یا هر LLM Provider دیگری در این مرحله انجام نشود.

---

# ⚠️ اصل شماره ۱ — ابتدا بررسی، سپس طراحی، سپس تأیید، سپس پیاده‌سازی

قبل از تغییر هر کدی، ابتدا وضعیت فعلی پروژه را بررسی کن:

* Backend
* APIهای موجود
* Routing
* Authentication
* Authorization
* User / Group / Role
* Permission Model
* File Storage
* Nextcloud Integration
* Audit / Logging
* Configuration
* Testها
* API Documentation
* Swagger / OpenAPI موجود

سپس یک طراحی کامل ارائه بده.

**تا زمانی که من Design را تأیید نکرده‌ام، هیچ کدی تغییر نکند.**

---

# ۱. هدف API

API باید بتواند یک فایل مشخص را از Archive دریافت و به Client مجاز تحویل دهد.

جریان کلی:

```text id="03h78k"
AI Assistant
      ↓
Authenticated Request
      ↓
AI File API
      ↓
Authentication
      ↓
Authorization
      ↓
Archive Permission Check
      ↓
File Exists Check
      ↓
Secure File Retrieval
      ↓
Streaming Response
      ↓
AI Assistant
```

---

# ۲. API نباید Permissionهای Archive را دور بزند

این اصل اجباری است.

صرف داشتن دسترسی به API نباید به معنی دسترسی به تمام فایل‌های آرشیو باشد.

برای هر درخواست بررسی شود:

1. هویت درخواست‌کننده چیست؟
2. آیا این Identity مجاز به استفاده از AI File API است؟
3. آیا این Identity اجازه مشاهده فایل موردنظر را دارد؟
4. آیا فایل در Scope مجاز این Identity قرار دارد؟
5. آیا وضعیت فایل اجازه ارائه به AI را می‌دهد؟

**Permissionهای Archive مرجع اصلی هستند.**

AI نباید خودش تصمیم بگیرد که به چه فایلی دسترسی دارد.

---

# ۳. AI نباید مستقیماً به Storage دسترسی داشته باشد

AI نباید مستقیماً به:

* File System
* Docker Mount
* Nextcloud Data Directory
* PostgreSQL
* Database

دسترسی داشته باشد.

تنها مسیر مجاز:

```text id="y2q8sf"
AI
 ↓
Secure API
 ↓
Archive Permission Layer
 ↓
File
```

باشد.

---

# ۴. Endpoint

پس از بررسی معماری فعلی، یک Endpoint استاندارد پیشنهاد بده.

نمونه مفهومی:

```http id="6n4usx"
GET /api/v1/ai/files/{file_id}
```

اما قبل از تأیید من، Endpoint نهایی را قطعی نکن.

Endpoint باید با Naming Convention و API Architecture فعلی پروژه هماهنگ باشد.

---

# ۵. انتقال فایل

روش انتقال فایل را بررسی و طراحی کن.

ترجیحاً:

* Streaming Response
* عدم بارگذاری کامل فایل در RAM
* پشتیبانی از فایل‌های حجیم
* Content-Type صحیح
* Content-Disposition صحیح
* Content-Length در صورت امکان
* مدیریت Timeout

در صورت نیاز، پشتیبانی از Range Request نیز بررسی شود.

---

# ۶. Metadata

در صورت نیاز، API می‌تواند Metadata کنترل‌شده فایل را همراه Response ارائه کند.

موارد قابل بررسی:

* File ID
* File Name
* MIME Type
* File Size
* Parent Folder
* Path
* Group
* Owner
* Creator
* Tags
* Creation Time
* Modification Time
* Version

فقط Metadata ضروری ارائه شود.

از **Excessive Data Exposure** جلوگیری کن.

---

# ۷. Authentication

با توجه به معماری فعلی پروژه، بهترین روش Authentication را پیشنهاد بده.

موارد قابل بررسی:

* Service Account
* Bearer Token
* API Token
* Internal Service Authentication
* mTLS
* مکانیزم Authentication فعلی پروژه

Credentialها:

* نباید Hardcoded باشند.
* نباید داخل Source Code قرار گیرند.
* باید از Configuration امن پروژه استفاده کنند.

---

# ۸. Authorization

بعد از Authentication حتماً Authorization انجام شود.

مثال:

```text id="cy8xf5"
Authenticated?
    ↓
Can use AI API?
    ↓
Can read requested file?
    ↓
Allow / Deny
```

در صورت عدم مجوز:

```http
403 Forbidden
```

برگردانده شود.

تغییر `file_id` نباید باعث دسترسی به فایل غیرمجاز شود.

---

# ۹. جلوگیری از IDOR و Enumeration

API باید در برابر:

* IDOR
* File Enumeration
* Path Traversal
* Privilege Escalation
* Excessive Data Exposure

محافظت شود.

رفتار مناسب برای `403` و `404` نیز بررسی شود تا وجود فایل‌های حساس افشا نشود.

Rate Limiting نیز بررسی شود.

---

# ۱۰. Audit Trail

هر درخواست AI File Retrieval در صورت امکان Audit شود.

حداقل:

* Timestamp
* Request Identity
* File ID
* File Name
* Result
* Authorization Result
* AI Client / Service Identifier
* Request ID / Correlation ID
* Error

اگر سیستم Audit موجود است، همان سیستم توسعه داده شود.

سیستم موازی غیرضروری ایجاد نکن.

---

# ۱۱. امنیت محتوای فایل و Prompt Injection

این API صرفاً فایل را تحویل می‌دهد.

محتوای فایل نباید:

* Authorization ایجاد کند.
* Permission تغییر دهد.
* باعث دسترسی به فایل دیگر شود.
* Tool Permission ایجاد کند.
* باعث اجرای Command شود.

فایل برای AI صرفاً **Data** است.

---

# ۱۲. Swagger / OpenAPI — اجباری

این API باید به‌صورت کامل در **Swagger / OpenAPI** مستند شود.

هدف این است که بتوان API را از داخل Swagger UI مستقیماً تست کرد.

Swagger باید حداقل شامل موارد زیر باشد:

* Endpoint
* HTTP Method
* Path Parameters
* Authentication Scheme
* Required Headers
* Response Headers
* Success Response
* Error Responses
* File Response
* Content-Type
* مثال Request
* مثال Error

---

# ۱۳. Authentication در Swagger

Swagger UI باید امکان وارد کردن Credential مربوط به API را داشته باشد.

پس از بررسی مکانیزم Authentication نهایی، OpenAPI Security Scheme مناسب تعریف کن.

مثلاً در صورت استفاده از Bearer Token:

```yaml id="t0t3bw"
securitySchemes:
  bearerAuth:
    type: http
    scheme: bearer
```

اما نوع Authentication نهایی را بر اساس Design تأییدشده تعیین کن.

---

# ۱۴. تست مستقیم Endpoint در Swagger

پس از اجرای Implementation، باید بتوان از داخل Swagger:

```text id="1x36a8"
Authorize
↓
Enter Credential
↓
Try it out
↓
Enter file_id
↓
Execute
```

را انجام داد.

Swagger باید Response واقعی API را نشان دهد.

---

# ۱۵. تست سناریوهای موفق و ناموفق از Swagger

برای این Endpoint Testهای Swagger باید شامل حداقل این موارد باشند:

### سناریوی موفق

Identity مجاز + File مجاز:

```http
200 OK
```

و فایل واقعاً برگردانده شود.

### بدون Authentication

```http
401 Unauthorized
```

### Authentication معتبر ولی بدون Permission

```http
403 Forbidden
```

### فایل غیرموجود

```http
404 Not Found
```

### File ID غیرمجاز

نباید بتوان با تغییر ID به فایل غیرمجاز دسترسی پیدا کرد.

### فایل حجیم

Response باید به‌صورت Streaming تحویل شود و API نباید فایل را یکجا در RAM بارگذاری کند.

### Audit

درخواست‌های موفق و ناموفق باید طبق طراحی Audit ثبت شوند.

---

# ۱۶. نمایش Response فایل در Swagger

بررسی کن که Swagger/OpenAPI نوع Response را به‌درستی نمایش دهد.

در صورت مناسب بودن:

```yaml id="q3z0rb"
responses:
  "200":
    content:
      application/octet-stream:
        schema:
          type: string
          format: binary
```

و در صورت وجود MIME Typeهای مشخص، آن‌ها نیز درست مستند شوند.

هدف این است که Swagger صرفاً Endpoint را نشان ندهد، بلکه **نوع واقعی خروجی فایل** را نیز درست مستند کند.

---

# ۱۷. Error Schema

برای Error Responseها یک ساختار استاندارد و سازگار با APIهای فعلی پروژه پیشنهاد بده.

مثلاً:

```json id="xkh6d5"
{
  "detail": "Access denied"
}
```

اما اگر پروژه قبلاً Error Schema استاندارد دارد، همان ساختار استفاده شود.

---

# ۱۸. File Version

بررسی کن API باید:

* آخرین نسخه فایل
* نسخه مشخص
* یا Snapshot

را ارائه دهد.

در این مرحله، فقط یک رفتار مشخص و مستند انتخاب کن.

در صورت نیاز به تصمیم معماری، قبل از Implementation آن را در Design مطرح کن.

---

# ۱۹. محدودیت حجم و Performance

برای API مشخص کن:

* Maximum File Size
* Timeout
* Streaming
* Rate Limit
* Concurrent Requests
* Memory Constraints

این مقادیر باید با وضعیت واقعی پروژه و زیرساخت فعلی هماهنگ باشند.

---

# ۲۰. Provider Independence

این API نباید مستقیماً وابسته به:

* Ollama
* AnythingLLM
* OpenAI
* Gemini
* یا Provider خاص

باشد.

API فقط مسئول:

```text id="z1jxy0"
Secure File Retrieval
```

است.

---

# ۲۱. معماری آینده

طراحی API باید در آینده امکان اضافه شدن قابلیت‌های زیر را داشته باشد:

```text id="quxq37"
Get File
Get Metadata
Search Files
Search by Metadata
Retrieve Multiple Files
Document Extraction
RAG Integration
AI Tool Calling
```

اما در این مرحله فقط:

**Get One File**

پیاده‌سازی شود.

---

# ۲۲. Testهای Backend

علاوه بر Swagger، Testهای خودکار Backend نیز طراحی و اجرا شوند.

حداقل:

* Authentication Test
* Authorization Test
* Access Control Test
* File Not Found Test
* IDOR Test
* Large File / Streaming Test
* Audit Test
* Error Response Test

Swagger برای تست دستی و مشاهده رفتار API استفاده شود؛ جای Testهای خودکار را نمی‌گیرد.

---

# ۲۳. مستندسازی Requirement

بعد از نهایی شدن این Feature، Documentation آن نیز باید مطابق قانون دائمی Documentation پروژه انجام شود.

ابتدا بررسی کن:

```text id="kq6e8v"
آیا این Requirement ادامه Requirement موجود است؟
```

### اگر ادامه Requirement موجود است:

سند قبلی Update شود.

### اگر مستقل است:

شماره بعدی در:

```text id="r7ca8t"
docs/requirements/
```

ایجاد شود.

Documentation باید طبق همه استانداردهای قبلی باشد:

* Requirement
* درخواست‌های تکمیلی
* Requirement Lineage
* Design / Architecture
* Final Implementation
* Test
* Security
* API
* Swagger
* Dependencies
* ارتباط با Requirementهای دیگر
* وضعیت نهایی

تمام مستندات توضیحی باید فارسی باشند.

---

# ۲۴. Git

پس از تأیید Design و انجام Implementation و Test:

* Documentation مناسب ایجاد یا Update شود.
* Testها بررسی شوند.
* تغییرات طبق فرآیند معمول Git پروژه Commit شوند.
* Push انجام شود.

---

# ۲۵. مرحله اول — فقط Design

اکنون فقط این مراحل را انجام بده:

```text id="ojqmxk"
Inspect Current Repository
        ↓
Inspect Existing API Architecture
        ↓
Inspect Authentication / Authorization
        ↓
Inspect Archive Permission Model
        ↓
Inspect File Storage
        ↓
Inspect Audit System
        ↓
Inspect Existing Swagger / OpenAPI
        ↓
Determine New vs Existing Requirement
        ↓
Design API
        ↓
Design Authentication
        ↓
Design Authorization
        ↓
Design File Streaming
        ↓
Design Audit
        ↓
Design Swagger/OpenAPI
        ↓
Design Automated Tests
        ↓
Present Final Technical Design
        ↓
STOP
```

### ⚠️ در این مرحله:

**هیچ کدی تغییر نده.**

**هیچ Migration ایجاد نکن.**

**هیچ APIای پیاده‌سازی نکن.**

**هیچ Test جدیدی ایجاد نکن.**

**هیچ Commit یا Push انجام نده.**

پس از ارائه Design، متوقف شو و منتظر تأیید من بمان.
