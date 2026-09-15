# قانون دائمی مستندسازی Requirementهای جدید — Enterprise Archive System

از این لحظه این دستور به‌عنوان **استاندارد دائمی Documentation پروژه Enterprise Archive System** در نظر گرفته شود.

هر زمان از طرف من یک Requirement، Feature، قابلیت، تغییر معماری، الزام امنیتی، تکمیل Workflow یا درخواست جدید برای پروژه مطرح شد، باید قبل از پایان اجرای آن، وضعیت Documentation مربوط به آن نیز تعیین و ثبت شود.

---

# ⚠️ اصل شماره ۱ — استاندارد Documentation قبلی کاملاً پابرجاست

تمام قواعد تعیین‌شده در مأموریت قبلی بازسازی Documentation همچنان معتبر و اجباری هستند.

در نتیجه برای هر Requirement جدید باید این اصول رعایت شوند:

* Documentation باید دقیق، واقعی و قابل اعتماد باشد.
* اطلاعات فنی نباید حدس زده شوند.
* Repository و وضعیت فعلی آن مرجع Implementation نهایی است.
* Requirement، Design/Architecture و Implementation باید از یکدیگر تفکیک شوند.
* تاریخچه Requirement باید از Debug History جدا باشد.
* وضعیت تاریخی و وضعیت فعلی نباید با یکدیگر مخلوط شوند.
* Testهای ذکرشده باید واقعی و قابل اثبات باشند.
* Architecture نباید بزرگ‌نمایی شود.
* API، Tool، Service، Script، Command و Architecture Pattern نباید با یکدیگر اشتباه گرفته شوند.
* ادعاهای امنیتی، پایداری، مقیاس‌پذیری و Production فقط در صورت وجود شواهد واقعی ثبت شوند.
* تمام متن توضیحی Documentation باید فارسی باشد.
* نام فایل‌ها و عناصر فنی مانند Class، Function، API، Table، Column، Command و Path می‌توانند انگلیسی باشند.
* Source Code کامل فقط در صورت ضرورت مستند شود.
* تمرکز Documentation روی ساختار، منطق، Workflow و ارتباط اجزای سیستم باشد.

---

# ⚠️ اصل شماره ۲ — قبل از ایجاد یا تغییر سند، ابتدا Requirement را طبقه‌بندی کن

هر Requirement جدید را ابتدا با تمام Requirementهای ثبت‌شده قبلی مقایسه کن.

ابتدا مشخص کن:

```text
آیا این Requirement ادامه یا تکمیل یک Requirement موجود است؟
```

یا:

```text
آیا این Requirement یک Requirement مستقل و جدید است؟
```

این تصمیم را فقط بر اساس عنوان یا شباهت ظاهری نگیرید.

برای تشخیص از موارد زیر استفاده کن:

* هدف
* Scope
* Actor
* Role
* Permission
* Workflow
* Domain / Capability
* Data Model
* API
* Architecture
* Implementation
* Test
* Dependency
* ارتباط با Featureهای قبلی

---

# ۳. اگر Requirement جدید ادامه Requirement قبلی است

اگر مشخص شد Requirement جدید در واقع:

* ادامه Requirement قبلی،
* تکمیل Requirement قبلی،
* توسعه Scope قبلی،
* تکمیل Security قبلی،
* تکمیل Permission قبلی،
* تکمیل UI/UX قبلی،
* تکمیل Audit قبلی،
* تکمیل Notification قبلی،
* یا هر نوع گسترش همان Capability

است:

## ⚠️ سند جدید ایجاد نکن.

باید **سند Requirement قبلی را Update کنی.**

---

# ۴. نحوه Update کردن Requirement قبلی

سند قبلی باید با همان استاندارد قبلی بازبینی و به‌روزرسانی شود.

اطلاعات جدید باید در بخش‌های مناسب قرار بگیرند.

در صورت نیاز این بخش‌ها را به‌روزرسانی کن:

```text id="2x2isf"
شرح نیازمندی
Scope
درخواست‌های تکمیلی
تاریخچه و تکامل
Actors / Roles
Rules
Scenarios
Design / Architecture
Workflow
Backend
Frontend
Database
API
Permission / Security
Audit
Notification
Implementation
Tests
Dependencies
Related Requirements
Final Status
```

---

# ۵. تاریخچه Requirement در هنگام Update حفظ شود

هنگام Update کردن یک سند:

**اطلاعات قبلی معتبر حذف نشوند.**

در صورت نیاز، بخش:

```markdown id="0w6a1p"
## تاریخچه و تکامل Requirement
```

را گسترش بده تا مشخص شود Requirement چگونه توسعه یافته است.

مثلاً:

```text id="pm3dnk"
Requirement اولیه
      ↓
تکمیل Permission
      ↓
تکمیل Security
      ↓
تکمیل Audit
      ↓
قابلیت جدید مرتبط
      ↓
وضعیت فعلی
```

اما Debug History یا تلاش‌های ناموفق وارد این Timeline نشود.

---

# ۶. Requirement جدید نباید فقط به دلیل Commit جدید ایجاد شود

وجود موارد زیر به‌تنهایی دلیل ایجاد Requirement جدید نیست:

* Commit جدید
* Pull Request جدید
* فایل جدید
* Service جدید
* API جدید
* Refactor
* Bug Fix
* تغییر Implementation

ابتدا بررسی کن که این تغییر بخشی از Requirement موجود است یا واقعاً Capability جدیدی ایجاد کرده است.

---

# ۷. اگر Requirement واقعاً جدید است

اگر Requirement جدید واقعاً مستقل است:

**یک سند جدید ایجاد کن.**

شماره آن باید ادامه آخرین شماره Requirement موجود باشد.

مثلاً اگر آخرین سند:

```text id="hkcgwm"
12_advanced_search.md
```

باشد، سند جدید:

```text id="7t7eyj"
13_new_feature.md
```

خواهد بود.

---

# ۸. سند Requirement جدید باید همان استاندارد قبلی را داشته باشد

Requirement جدید باید از همان ساختار و سطح دقت استفاده کند.

ساختار پیشنهادی:

```markdown
# عنوان Requirement

## ۱. شرح نیازمندی

## ۲. هدف و مسئله

## ۳. محدوده Requirement

## ۴. درخواست‌های تکمیلی مرتبط

## ۵. تاریخچه و تکامل Requirement

## ۶. Actors و Roleها

## ۷. قوانین و محدودیت‌ها

## ۸. سناریوهای اصلی

## ۹. Design و Architecture تأییدشده

## ۱۰. Workflow

## ۱۱. Backend

## ۱۲. Frontend

## ۱۳. Database

## ۱۴. APIها و پروتکل‌ها

## ۱۵. Permission و Security

## ۱۶. Audit و Logging

## ۱۷. Notification

## ۱۸. ساختار فایل‌ها و اجزای پیاده‌سازی

## ۱۹. منطق عملکرد

## ۲۰. Testها و معیارهای پذیرش

## ۲۱. وابستگی‌ها و Integrationها

## ۲۲. ارتباط با Requirementهای دیگر

## ۲۳. وضعیت نهایی
```

بخش‌هایی که برای Requirement مربوط نیستند حذف شوند.

---

# ۹. Requirement Lineage در Requirementهای جدید

برای Requirement جدید نیز بررسی کن که آیا پیشینه یا درخواست‌های مرتبطی در تاریخچه پروژه وجود دارد.

اگر این Requirement در گذشته به‌صورت بخشی از یک درخواست بزرگ‌تر مطرح شده ولی اکنون به‌دلیل Scope مستقل باید سند جداگانه داشته باشد، این رابطه را در:

```text id="f4pn31"
## ارتباط با Requirementهای دیگر
```

ثبت کن.

---

# ۱۰. وضعیت نهایی همیشه باید بر اساس Repository فعلی باشد

بعد از اجرای Requirement و قبل از نهایی کردن Documentation:

اطلاعات فعلی را با Repository تطبیق بده.

به‌خصوص:

* File Path
* Version
* API
* Database
* Configuration
* Container
* Port
* Mount
* Service
* Class
* Function
* Script
* Test

باید با وضعیت واقعی فعلی مطابقت داشته باشند.

اگر Implementation در طول زمان تغییر کرده است:

* وضعیت فعلی در Final Implementation ثبت شود.
* وضعیت تاریخی فقط در صورت ضرورت در History ثبت شود.

---

# ۱۱. Requirement، Design و Implementation را مخلوط نکن

در سند همیشه مشخص باشد:

### Requirement

چه چیزی از طرف من خواسته شده است.

### Design / Architecture

چه طرحی برای اجرای آن تأیید شده است.

### Final Implementation

الان واقعاً چه چیزی در Repository پیاده‌سازی شده است.

اگر چیزی فقط از روی Source Code کشف شده، آن را به‌عنوان «درخواست کاربر» معرفی نکن.

---

# ۱۲. Fact و Derived Information

در Documentation میان:

**واقعیت قابل مشاهده**

و

**نتیجه‌گیری مبتنی بر چند شواهد**

تفاوت بگذار.

مثلاً اگر چیزی مستقیماً در `docker-compose.yml` وجود دارد، آن را به‌عنوان وضعیت واقعی ثبت کن.

اگر رابطه‌ای از چند فایل و Feature نتیجه‌گیری شده است، آن را به‌عنوان ارتباط یا تحلیل معماری بیان کن.

اگر قابل اثبات نیست، حدس نزن.

---

# ۱۳. Test Documentation

هر Requirement جدید یا Updateشده باید Testهای واقعی خود را مستند کند.

در صورت وجود:

* مسیر Test
* هدف
* سناریو
* معیار پذیرش
* نتیجه

را ثبت کن.

از ادعاهای کلی و اثبات‌نشده خودداری کن.

---

# ۱۴. Update کردن Index

هر زمان:

### Requirement جدید ایجاد شد:

فایل:

```text id="cntwmt"
docs/requirements/README.md
```

را نیز به‌روزرسانی کن.

### Requirement قبلی Update شد:

در صورت تغییر عنوان، شماره، Scope یا ارتباطات، Index را نیز متناسب با وضعیت جدید اصلاح کن.

Index باید همیشه نماینده ساختار فعلی Requirementها باشد.

---

# ۱۵. هیچ Requirement جدیدی بدون بررسی Requirementهای قبلی

قبل از ایجاد سند جدید، حتماً Requirementهای مرتبط قبلی را بررسی کن.

به‌خصوص Requirementهای:

* هم‌حوزه
* دارای Actor مشترک
* دارای API مشترک
* دارای Data Model مشترک
* دارای Workflow مشترک
* دارای Permission مشترک
* دارای Implementation مشترک

چون ممکن است Requirement جدید در واقع ادامه یکی از آن‌ها باشد.

---

# ۱۶. چه زمانی Documentation Update شود؟

پس از اینکه Requirement:

```text id="m4ktdw"
Requirement
→ Design
→ Approval
→ Implementation
→ Test
→ Final Approval
```

را طی کرد، Documentation نهایی شود.

Documentation نباید قبل از مشخص شدن وضعیت نهایی Implementation، ادعاهای قطعی درباره وضعیت نهایی سیستم ثبت کند.

---

# ۱۷. Commit / Push

در اجرای معمول Feature:

* Documentation متناسب با Requirement ایجاد یا Update شود.
* Documentation همراه با Feature در فرآیند Git ثبت شود.

اما در صورتی که من صراحتاً گفته باشم:

```text
فعلاً فقط طراحی و بررسی انجام بده
```

هیچ Documentation نهایی یا Commit/Push مربوط به Implementation انجام نده.

همیشه دستور صریح مرحله فعلی من را اولویت بده.

---

# ۱۸. قانون دائمی تصمیم‌گیری

برای هر Requirement جدید این الگوریتم را اجرا کن:

```text id="d3ppd1"
New Requirement
      ↓
Search Existing Requirements
      ↓
Compare Goal / Scope / Workflow / Architecture / Dependencies
      ↓
Is this a continuation?
      ├── YES
      │     ↓
      │  Update Existing Requirement Document
      │     ↓
      │  Update Requirement Lineage
      │     ↓
      │  Update README.md if needed
      │
      └── NO
            ↓
         Create Next Requirement Document
            ↓
         Update README.md
```

---

# ۱۹. اصل مهم برای جلوگیری از Documentation Drift

Documentation نباید از وضعیت واقعی کد عقب یا جلو باشد.

بنابراین پس از هر تغییر نهایی:

```text id="6gv3gq"
Implementation
        ↓
Test
        ↓
Documentation Update
        ↓
Git
```

باید بررسی شود که Documentation با Implementation فعلی هماهنگ است.

اگر Implementation یک Requirement قبلی تغییر کرد، حتی اگر Requirement جدید محسوب نشود، سند مربوط به آن Requirement باید در صورت نیاز Update شود.

---

# ۲۰. قالب پاسخ تو هنگام پردازش Requirement جدید

پس از بررسی Requirement جدید، قبل از هر تغییر Documentation، نتیجه طبقه‌بندی را مشخص کن:

```text id="twsuio"
نوع: ادامه Requirement موجود
Requirement مرتبط: 07
اقدام Documentation: Update 07_xxx.md
```

یا:

```text id="13f21h"
نوع: Requirement مستقل
شماره جدید: 13
اقدام Documentation: Create 13_xxx.md
```

سپس بعد از نهایی شدن Feature، Documentation مربوطه را تولید یا Update کن.

---

# ۲۱. قانون اساسی این سیستم

هیچ Requirement جدیدی نباید بدون پاسخ به این سؤال مستند شود:

> **«آیا این Requirement ادامه یکی از Requirementهای قبلی است یا یک Requirement مستقل؟»**

این تشخیص باید با بررسی واقعی پروژه و تاریخچه آن انجام شود.

---

# معیار نهایی پذیرش

هر بار که Requirement جدیدی اجرا و نهایی شد:

### اگر ادامه Requirement قبلی بود:

* سند قبلی Update شود.
* Lineage حفظ شود.
* اطلاعات قبلی معتبر حفظ شود.
* اطلاعات جدید در بخش مناسب اضافه شود.
* وضعیت نهایی با Repository فعلی تطبیق داده شود.
* `README.md` در صورت نیاز Update شود.

### اگر Requirement جدید و مستقل بود:

* شماره بعدی تعیین شود.
* سند جدید ایجاد شود.
* تمام استانداردهای Documentation قبلی رعایت شود.
* `README.md` Update شود.
* ارتباط آن با Requirementهای قبلی ثبت شود.

در هر دو حالت:

**Documentation باید دقیق، فارسی، مبتنی بر شواهد و منطبق با وضعیت واقعی Repository باشد.**

---

# دستور دائمی

از این لحظه برای هر Requirement جدید دقیقاً این فرآیند را اجرا کن:

```text id="w46g8r"
Receive Requirement
        ↓
Inspect Existing Requirements
        ↓
Identify Related Requirements
        ↓
Determine Continuation vs Independent
        ↓
Design / Approval / Implementation / Test
        ↓
Update Existing Document OR Create New Document
        ↓
Verify Against Current Repository
        ↓
Update README.md
        ↓
Git Commit / Push according to current instruction
```

این دستور از این لحظه **استاندارد دائمی مستندسازی پروژه Enterprise Archive System** است.
