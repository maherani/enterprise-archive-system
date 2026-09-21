# Requirement 27 — Responsive, Readable and User-Resizable Archive Table

## هدف

در سامانه Enterprise Archive System، جدول نمایش فایل‌ها و Folderها در حال حاضر از نظر استفاده از فضای افقی، خوانایی متن‌های طولانی و کنترل اندازه ستون‌ها برای کاربر مناسب نیست.

هدف این Requirement این است که Table View فعلی سامانه به یک جدول حرفه‌ای، خوانا و قابل تنظیم تبدیل شود، به‌گونه‌ای که:

1. در حالت پیش‌فرض، کاربر بتواند کل جدول و ستون‌های اصلی را تا حد امکان در Viewport فعلی مشاهده کند.
2. متن‌های طولانی داخل سلول‌ها باعث عریض‌شدن غیرمنطقی جدول نشوند.
3. متن‌های طولانی حداکثر در 2 یا 3 خط نمایش داده شوند.
4. کاربر بتواند عرض ستون‌ها را با Mouse به‌صورت Drag تغییر دهد.
5. عرض ستون‌ها بعد از تغییر در صورت امکان برای همان کاربر حفظ شود.
6. کاربر بتواند اندازه ستون‌ها را به حالت پیش‌فرض برگرداند.
7. Header و Body همیشه کاملاً هم‌تراز باقی بمانند.
8. Table با RTL، Search، Sort، Tag Filter، Metadata Filter، Share و Delete فعلی کاملاً سازگار باقی بماند.
9. Grid View فعلی تحت تأثیر این تغییر قرار نگیرد.
10. این تغییر هیچ اثر منفی روی Permission، ACL، Storage Isolation، Share یا Metadata نداشته باشد.

---

# 1 — اصل مهم: Table فعلی را اصلاح کن

قبل از هرگونه تغییر:

- Table View فعلی را پیدا کن.
- مشخص کن Rendering در کدام فایل/فایل‌ها انجام می‌شود.
- Columnهای فعلی را شناسایی کن.
- CSS فعلی جدول را بررسی کن.
- JavaScript مربوط به Table را بررسی کن.
- Sort، Search، Tag Filter و Metadata Filter را بررسی کن.
- Actionهای موجود مانند Share، Delete، View و سایر عملیات را شناسایی کن.
- بررسی کن آیا Table از HTML Table، CSS Grid، Flexbox یا ساختار ترکیبی استفاده می‌کند.

به هیچ عنوان بدون نیاز یک Table جدید ایجاد نکن.

هدف این Requirement اصلاح و ارتقای Table موجود است.

---

# 2 — Columnهای جدول

همه Columnهای فعلی سامانه را حفظ کن.

Columnها نباید صرفاً برای جا شدن در صفحه حذف شوند.

برای هر Column بر اساس ماهیت داده، Width منطقی تعیین کن.

الگوی کلی:

### File / Folder Name
بیشترین فضای منطقی را دریافت کند.

### Subject / Metadata Text
فضای متوسط تا زیاد.

### Source
فضای متوسط.

### Document Type
فضای متوسط.

### Date
فضای محدود و مناسب.

### Size
فضای محدود.

### Tags
فضای انعطاف‌پذیر.

### Archive ID
فضای محدود ولی قابل خواندن.

### Actions
عرض ثابت و مشخص.

مقادیر دقیق Width را بر اساس ساختار فعلی Table تعیین کن و از مقادیر کور و بدون بررسی استفاده نکن.

---

# 3 — هدف اصلی: مشاهده کل جدول

در Desktop:

```text
Table Width <= Available Viewport Width


تا حد امکان کاربر نباید مجبور به Horizontal Scroll برای مشاهده ستون‌های اصلی شود.

اما این موضوع نباید با حذف اطلاعات یا فشرده‌سازی غیرمنطقی Table حل شود.

اولویت:

Column sizing منطقی
Text wrapping
Responsive sizing
در صورت لزوم Horizontal Scroll فقط در Viewportهای بسیار کوچک
4 — Text Wrapping

سلول‌های متنی باید قابلیت چندخطی داشته باشند.

این موضوع حداقل برای:

File Name
Folder Name
Subject
Source
Document Type
Sender
Recipient
Related Unit
Related Case
Description
Keywords
Tags در صورت طولانی‌بودن

بررسی و اعمال شود.

5 — حداکثر تعداد خطوط

Text داخل Cell در حالت پیش‌فرض:

Maximum = 3 lines

باشد.

رفتار:

1 line
2 lines
3 lines

مجاز است.

اگر متن از 3 خط بیشتر بود:

...

نمایش داده شود.

از ارتفاع نامحدود Row جلوگیری کن.

6 — نمایش کامل متن

اگر متن در جدول به دلیل محدودیت 3 خط کوتاه شده است، اطلاعات کامل نباید از بین برود.

کاربر باید بتواند متن کامل را مشاهده کند.

ترجیحاً از قابلیت‌های موجود سامانه استفاده کن:

Quick View
Drawer
Tooltip
Title
Detail Panel

از ایجاد UI موازی غیرضروری خودداری کن.

7 — تفاوت Columnهای متنی و داده‌ای

Columnهای متنی:

Name
Subject
Source
Description
Sender
Recipient
Keywords

می‌توانند Wrap شوند.

اما Columnهای داده‌ای:

Date
Size
ID
Archive ID
Actions

ترجیحاً Single-Line باقی بمانند.

هدف این است که ارتفاع Table فقط به دلیل داده‌های کوتاه افزایش پیدا نکند.

8 — File Name Handling

نام‌های بسیار طولانی فایل نباید باعث افزایش Width جدول شوند.

مثال:

This-is-a-very-very-long-document-name...

باید بتواند:

This-is-a-very-very-long
document-name-that...

نمایش داده شود.

در صورت نیاز:

overflow-wrap: anywhere

یا مکانیزم مناسب مشابه استفاده شود.

رفتار باید با زبان فارسی و انگلیسی هر دو درست باشد.

9 — Resizable Columns

مهم‌ترین قابلیت جدید:

کاربر باید بتواند Width هر Column را با Mouse تغییر دهد.

رفتار:

Column A | Column B | Column C
          ^
       resize handle

وقتی Pointer روی Border بین دو Column قرار گرفت:

cursor = col-resize

نمایش داده شود.

10 — Mouse Drag

کاربر بتواند:

Mouse Down
   ↓
Move Mouse Left / Right
   ↓
Column Width Changes
   ↓
Mouse Up

را انجام دهد.

Resize باید:

Smooth
Real-time
بدون Page Reload
بدون Flicker
بدون Layout Jump

باشد.

11 — RTL

سامانه RTL است و Resize باید با RTL به‌درستی کار کند.

بررسی کن که:

جهت محاسبه Mouse movement درست باشد.
Border صحیح انتخاب شود.
تغییر Width در سمت درست انجام شود.
Header/Body alignment خراب نشود.
ترتیب Columnها تغییر نکند مگر در صورت وجود رفتار فعلی.

Resize نباید به دلیل direction: rtl رفتار معکوس یا غیرقابل‌پیش‌بینی داشته باشد.

12 — Minimum Width

برای هر Column یک حداقل Width منطقی تعیین کن.

مثلاً:

Actions

عرض آن نباید آن‌قدر کم شود که Buttonها از کار بیفتند.

Date

عرض نباید آن‌قدر کم شود که تاریخ غیرقابل خواندن شود.

Name

عرض نباید آن‌قدر کم شود که حتی یک عبارت کوتاه نیز قابل مشاهده نباشد.

مقادیر واقعی باید بر اساس داده‌های موجود پروژه تعیین شوند.

13 — Maximum Width

برای جلوگیری از این‌که یک Column کل Viewport را اشغال کند، Maximum Width منطقی تعیین کن.

مثلاً کاربر نباید بتواند:

File Name = 1800px

تعریف کند و تمام Columnهای دیگر را از صفحه خارج کند.

Maximum Width باید قابل استفاده و منطقی باشد.

14 — حفظ سایر Columnها

هنگام Resize:

Changed Column
+
Other Columns
+
Actions

باید همچنان usable باقی بمانند.

Resize یک Column نباید باعث:

خروج Actionها از صفحه
Collapse شدن Header
شکستن Layout
ایجاد Rowهای غیرعادی
عدم نمایش ستون‌های دیگر

شود.

15 — Header / Body Synchronization

بعد از هر Resize:

Header Column Width
=
Body Cell Width

باید دقیقاً برقرار باشد.

هیچ حالتی نباید به وجود آید که Header یک Column با Cellهای آن Column هم‌تراز نباشد.

16 — Auto Fit

در صورت امکان یک قابلیت Auto Fit نیز اضافه کن.

مثلاً:

Double Click روی Border

باعث شود Column تا اندازه مناسب تنظیم شود.

Auto Fit باید با موارد زیر محاسبه شود:

Header
محتوای چند Row
Minimum Width
Maximum Width

اگر Auto Fit باعث Complexity یا Performance مشکل‌زا می‌شود، ابتدا بررسی کن آیا پیاده‌سازی آن ارزش دارد یا خیر.

این قابلیت نباید اجباری باشد.

17 — Reset Widths

کاربر باید بتواند Width همه Columnها را Reset کند.

مثلاً:

بازنشانی اندازه ستون‌ها

بعد از Reset:

Default Table Layout

بازگردد.

Reset نباید داده‌های Table را تغییر دهد.

18 — Persistence

در صورت امکان Width Columnها برای همان User ذخیره شوند.

مثلاً:

Column Name = 420px
Subject = 360px
Source = 240px

بعد از:

Page Reload

همان اندازه‌ها باقی بمانند.

19 — User-specific Persistence

تنظیمات یک User نباید روی User دیگر اثر بگذارد.

یعنی:

User A
File Name = 450px

و:

User B
File Name = 280px

بتوانند مستقل باشند.

ترجیحاً از Local Storage یا State persistence فعلی پروژه استفاده کن.

اگر از Local Storage استفاده شد، یک Key نسخه‌دار ایجاد کن.

مثلاً:

enterprise_archive_table_columns_v1
20 — Invalid Persisted State

اگر Width ذخیره‌شده خراب باشد:

NaN
negative
zero
too large
invalid JSON
invalid object structure

جدول نباید Crash کند.

سیستم باید:

Invalid persisted state
        ↓
Ignore
        ↓
Load default widths

را انجام دهد.

21 — Responsive Desktop

در مانیتورهای بزرگ:

جدول باید فضای موجود را منطقی استفاده کند.
Columnهای اصلی باید readable باشند.
فضای خالی غیرضروری کاهش یابد.

نباید Width تمام Columnها صرفاً بر اساس درصد مساوی تقسیم شود.

22 — Responsive Small Screen

اگر Viewport بسیار کوچک شد:

اولویت با حفظ اطلاعات است.

در صورت عدم امکان نمایش کامل:

Columnهای ثانویه می‌توانند کاهش Width داشته باشند.
اطلاعات کامل می‌تواند در Quick View نمایش داده شود.
Horizontal Scroll فقط به‌عنوان آخرین راه‌حل استفاده شود.

از حذف بی‌دلیل Columnهای مهم خودداری کن.

23 — Action Column

Action Column باید Width پایدار داشته باشد.

Actionهای موجود مانند:

View
Share
Delete
More
Download

نباید در اثر Resize سایر Columnها خراب شوند.

این Column نباید اجازه Resize به اندازه‌ای را داشته باشد که Buttonها غیرقابل استفاده شوند.

24 — Compatibility با Search

Resize نباید Search را تغییر دهد.

مثلاً:

Search
 ↓
Results
 ↓
Resize

باید کاملاً طبیعی کار کند.

25 — Compatibility با Sort

Columnهایی که Sort دارند باید بعد از Resize همچنان:

Sortable
Clickable
Usable

باقی بمانند.

Resize Handle نباید با Click روی Header اشتباه گرفته شود.

26 — Compatibility با Tag Filter

تمام قابلیت‌های فعلی:

Single Tag Filter
Multi-Tag Filter

باید بعد از Resize بدون Regression کار کنند.

27 — Compatibility با Metadata

در صورت پیاده‌سازی Document Metadata:

Columnهای:

Subject
Source
Document Type
Date
Archive ID

باید رفتار Responsive صحیح داشته باشند.

28 — Compatibility با Group Share

Action فعلی Share:

Share with Group

نباید تحت تأثیر Resize قرار گیرد.

Buttonها و Modalهای فعلی باید همچنان کار کنند.

29 — Compatibility با Delete

Delete Action جدید نیز باید پس از Resize قابل دسترسی باقی بماند.

Resize نباید:

Delete را مخفی کند.
Button را خارج از viewport ببرد.
Action Menu را خراب کند.
30 — Grid View

Requirement 27 فقط برای:

Table View

است.

Grid View نباید تحت تأثیر قرار گیرد مگر تغییر مشترک کاملاً ضروری باشد.

31 — Performance

Resize نباید در هر mousemove باعث Render کامل Table شود.

از Render سنگین اجتناب کن.

در صورت نیاز از موارد زیر استفاده کن:

requestAnimationFrame
Direct style update
CSS variables
Debounce/Throttle مناسب

هدف:

Smooth resize
No visible lag
No full table rerender on every mouse movement
32 — Virtualization

اگر Table فعلی Virtualized نیست، برای این Requirement بدون نیاز Virtualization اضافه نکن.

اول Performance فعلی را بررسی کن.

هدف Requirement تغییر Column Layout است، نه بازطراحی کل Rendering Engine.

33 — Accessibility

Resize Handle باید تا حد امکان قابل تشخیص باشد.

حداقل:

Cursor مناسب
Focus state مناسب
aria-label در صورت امکان
Keyboard fallback در صورت عملی بودن

برای مثال:

"تغییر عرض ستون نام فایل"
34 — Security

Column Resize یک UI State است.

نباید هیچ تغییری در:

Permission
ACL
File Ownership
Share
Tag
Metadata
Storage Isolation

ایجاد کند.

35 — JavaScript Implementation

قبل از اضافه‌کردن Event Listener جدید:

ساختار فعلی Event Handling را بررسی کن.

از Event Listenerهای تکراری و Memory Leak جلوگیری کن.

اگر Table بعداً Re-render می‌شود:

Resize Handlerها نباید چند بار روی همان Element ثبت شوند.

36 — CSS Implementation

CSS جدید باید با Styleهای فعلی پروژه هماهنگ باشد.

از:

!important

فقط در صورت ضرورت استفاده کن.

از شکستن Styleهای موجود جلوگیری کن.

RTL و Theme فعلی نیز باید حفظ شوند.

37 — Browser Compatibility

پیاده‌سازی باید در Browserهای هدف فعلی سامانه کار کند.

حداقل:

Chrome / Chromium
Edge
Firefox

را در نظر بگیر.

از APIهای Experimental بدون fallback استفاده نکن.

38 — Unit / Integration Tests

حداقل تست‌های زیر اضافه شوند:

Default column width calculation
Minimum width enforcement
Maximum width enforcement
Long text wrapping
Three-line clamping
Full text accessibility
Resize interaction
RTL resize behavior
Persisted width loading
Invalid persisted width recovery
Reset widths
Action column protection
Header/body width synchronization
39 — Playwright E2E

از زیرساخت Playwright موجود پروژه استفاده کن.

E2E-01 — Table View
Login
 ↓
Open Archive
 ↓
Switch to Table View
 ↓
Verify expected columns exist
E2E-02 — Long Text
Open resource with long filename / metadata
 ↓
Verify wrapping occurs
 ↓
Verify maximum displayed lines <= 3
 ↓
Verify full text remains accessible
E2E-03 — Resize
Open Table
 ↓
Locate resize border
 ↓
Drag border horizontally
 ↓
Verify column width changed
E2E-04 — Header Alignment
Resize Column
 ↓
Verify Header and Body remain aligned
E2E-05 — Persistence
Resize Column
 ↓
Reload page
 ↓
Verify width persists
E2E-06 — Reset
Resize Column
 ↓
Reset widths
 ↓
Verify default width restored
E2E-07 — RTL
Open Table
 ↓
Verify RTL direction
 ↓
Resize column
 ↓
Verify correct RTL behavior
E2E-08 — Search
Search
 ↓
Results
 ↓
Resize
 ↓
Verify search results remain intact
E2E-09 — Share
Open resource
 ↓
Resize
 ↓
Open Share
 ↓
Verify Share still works
E2E-10 — Delete
Open resource
 ↓
Resize
 ↓
Open Delete
 ↓
Verify Delete action remains accessible
40 — Regression Test

قبل از تغییر:

Existing Unit Tests
Existing Integration Tests
Existing E2E Tests

اجرا شوند.

بعد از تغییر:

Existing Unit Tests
Existing Integration Tests
Existing E2E Tests
New Table Tests
New Resize Tests
New Responsive Tests

هیچ Test موجودی صرفاً برای سبزشدن Build حذف یا ضعیف نشود.

41 — Documentation

Requirement را به عنوان:

Requirement 27

ثبت کن.

حداقل مستندات:

docs/requirements/27_responsive_resizable_archive_table.md
docs/requirements/README.md

به‌روزرسانی شوند.

در صورت نیاز:

README.md
PROJECT_STATE.md

نیز اصلاح شوند.

مستندات باید توضیح دهند:

هدف Table redesign
Column sizing
Text wrapping
Resize
Persistence
Reset
RTL
Responsive behavior
Performance
Browser support
42 — Acceptance Criteria

Requirement فقط زمانی Complete است که:

[ ] Table View فعلی شناسایی و همان Table اصلاح شده باشد.

[ ] Columnهای اصلی حذف نشده باشند.

[ ] Width پیش‌فرض Columnها منطقی باشد.

[ ] جدول در Desktop تا حد امکان در Viewport قابل مشاهده باشد.

[ ] Textهای طولانی Wrap شوند.

[ ] Textهای طولانی حداکثر در 3 خط نمایش داده شوند.

[ ] Textهای بیشتر از 3 خط با Ellipsis کنترل شوند.

[ ] متن کامل همچنان قابل مشاهده باشد.

[ ] کاربر بتواند Columnها را با Mouse Resize کند.

[ ] Cursor مناسب در Resize Handle نمایش داده شود.

[ ] Resize در RTL صحیح عمل کند.

[ ] Minimum Width اعمال شود.

[ ] Maximum Width اعمال شود.

[ ] Header و Body هم‌تراز باقی بمانند.

[ ] Action Column قابل استفاده باقی بماند.

[ ] Share Action پس از Resize کار کند.

[ ] Delete Action پس از Resize کار کند.

[ ] Search پس از Resize کار کند.

[ ] Sort پس از Resize کار کند.

[ ] Tag Filter پس از Resize کار کند.

[ ] Metadata Filter پس از Resize کار کند.

[ ] Grid View تحت تأثیر قرار نگیرد.

[ ] Column Widthها در صورت فعال بودن Persistence بعد از Reload حفظ شوند.

[ ] Userهای مختلف State مستقل داشته باشند.

[ ] Invalid persisted state باعث Crash نشود.

[ ] Reset Widths کار کند.

[ ] Resize باعث Full Table Re-render سنگین در هر mousemove نشود.

[ ] Resize هیچ Permission یا Authorizationی را تغییر ندهد.

[ ] Accessibility پایه رعایت شود.

[ ] Playwright E2E Tests اضافه شوند.

[ ] Regression Tests قبلی همچنان Pass باشند.

[ ] Documentation به‌روزرسانی شود.

نحوه اجرای کار

قبل از Implementation:

Table فعلی را پیدا کن.
محل Rendering و CSS آن را مشخص کن.
Columnهای فعلی را فهرست کن.
Search، Sort، Filter، Share و Delete integration را بررسی کن.
RTL implementation را بررسی کن.
مشخص کن بهترین محل برای اضافه‌کردن Resize چیست.
مشخص کن Persistence فعلی پروژه را چگونه می‌توان reuse کرد.
سپس Implementation را انجام بده.

از ایجاد Table جدید، UI جدید یا Architecture موازی خودداری کن.

اگر برای Resize نیاز به Library خارجی وجود دارد، فقط پس از بررسی implementation فعلی از آن استفاده کن.

در صورت استفاده از Library خارجی:

Dependency را مستند کن.
حجم و Performance آن را بررسی کن.
Compatibility با Nextcloud 34 و Browserهای هدف را بررسی کن.
امکان استفاده در محیط Air-Gapped را بررسی کن.
در صورت امکان از Dependency جدید پرهیز کن و قابلیت را با JavaScript/CSS موجود پیاده‌سازی کن.

در پایان گزارش کاملی ارائه کن:

Root Cause / Current Table Structure
Changed Files
HTML/Table Changes
CSS Changes
JavaScript Changes
Resize Mechanism
Persistence Mechanism
Responsive Behavior
RTL Behavior
Accessibility
Performance
Tests Added
Tests Executed
Playwright Results
Regression Results
Documentation Changes
Known Limitations


این نسخه را می‌توانی **مستقیماً به‌عنوان یک Requirement مستقل به Antigravity بدهی** و لازم نیست Promptهای قبلی مربوط به جدول را هم کنارش ارسال کنی.