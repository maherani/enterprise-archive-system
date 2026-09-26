# سند نیازمندی شماره ۱۸: لایه تست‌های مرورگری واقعی سرتاسری (Real Browser End-to-End Testing Layer) با Playwright

---

## ۱. شرح نیازمندی و چالش‌های گذشته (Problem Statement & Context)
سامانه بایگانی اسناد سازمانی (Enterprise Archive System) بر پایه افزونه `archive_autotag` در Nextcloud 34 توسعه یافته و دارای معماری SPA مدرن، سلسله‌مراتب پوشه‌های سازمانی، مدیریت تگ‌های مقید به گروه، کارتابل تأیید/رد ادمین، و درگاه رندر اختصاصی Obsidian است.
پیش از این نیازمندی، کلیه تست‌های سامانه (۳۰ سوئیت آزمون) بر پایه ارسال درخواست‌های مستقیم HTTP (`requests`) یا شبیه‌سازی فراخوانی کنترلرها و دیتابیس استوار بودند. با وجود پوشش عمیق منطق تجاری و امنیتی در لایه سرور، سامانه‌ی تست با چالش‌های زیر مواجه بود:

1. **فقدان ارزیابی رندر واقعی مرورگر (DOM / Layout Blindness):** آزمون‌های متکی بر بازرسی استاتیک HTML یا JSON، رفتارهای مبتنی بر جاوااسکریپت کلاینت، چرخه حیات SPA، رندر رویدادهای Drag & Drop و انیمیشن‌های مدال را نمی‌سنجیدند.
2. **پنهان ماندن خطاهای زمان اجرای جاوااسکریپت (Runtime Reference Errors):** خطاهای نحوی یا توابع مفقود در فرانت‌اند (مانند بروز `ReferenceError: formatBytes is not defined` در هندلر آپلود فایل) در تست‌های HTTP شناسایی نمی‌شدند زیرا کد کلاینت اصلاً توسط مرورگر تفسیر و اجرا نمی‌شد.
3. **عدم اعتبارسنجی ایزولاسیون بصری و ریسپانسیو (Visual & Responsive Isolation):** نحوه نمایش منوهای نوار ناوبری بالا (`#appmenu`) برای کاربران عادی در مقایسه با ادمین‌ها، رفتارهای هدرهای شناور (`position: sticky`)، و چیدمان رابط کاربری در دستگاه‌های موبایل (Viewport 375x812) بدون موتور رندر مرورگری واقعی غیرقابل ارزیابی بود.
4. **ابهام در تجربه کاربری چرخه درگاه و مدال‌ها:** باز شدن پنجره‌های پاپ‌آپ، ارسال تأییدیه‌ها (`confirm`) و اخذ دلایل اداری رد (`prompt`) در کارتابل نیازمند شبیه‌سازی مکالمات تعاملی کاربر در یک نشست واقعی مرورگر بود.

**هدف نیازمندی:** طراحی و استقرار یک زیرساخت تست مرورگری سرتاسری (Real Browser E2E) کاملاً آفلاین و ایزوله با استفاده از کتابخانه **Playwright** و موتور Chromium Headless در محیط لینوکس (WSL)، پیاده‌سازی الگوی معماری **Page Object Model (POM)**، پوشش کامل **۲۰ سناریوی بحرانی** در ۶ سوئیت آزمون مستقل، مجهز به مکانیزم ضبط تریس (`trace.zip`) و اسکرین‌شات خودکار در زمان بروز خطا.

---

## ۲. معماری لایه تست E2E و الگوی Page Object Model (POM)

زیرساخت تست در مسیر `tests/e2e/` مستقر گردیده و لایه‌بندی آن به شرح زیر است:

```
tests/e2e/
├── config.py                       # تنظیمات آدرس‌ها، تایم‌اوت‌ها، Viewportها و مشخصات پرسوناهای کاربری
├── fixtures/
│   ├── users.py                    # مدیریت نشست‌های احراز هویت و ذخیره StorageState کوکی‌ها
│   └── file_factory.py             # تولید فایل‌های آزمایشی قطعی و پاکسازی ردپاها
├── helpers/
│   └── failure_reporter.py         # کانتکست منیجر ذخیره خودکار اسکرین‌شات و تریس Playwright هنگام خطا
├── pages/                          # کلاس‌های پیاده‌سازی Page Object Model (POM)
│   ├── base_page.py                # لایه پایه کنش‌ها، پیمایش و انتظارات (Waiters)
│   ├── login_page.py               # صفحه لاگین Nextcloud 34 و تشخیص پیام‌های اعتبارسنجی
│   ├── portal_page.py              # درگاه آرشیو اسناد، جستجوی بلادرنگ، نوار تگ‌ها و ناوبری
│   ├── upload_modal.py             # مدال بارگذاری سند سازمانی، دراپ‌زون و نوار پیشرفت
│   ├── folder_request_modal.py     # مدال ثبت درخواست پوشه و کارتابل تایید/رد ادمین
│   ├── tag_drawer.py               # دراور مدیریت و حاکمیت تگ‌های گروه سازمانی و دکمه تطبیق
│   └── swagger_page.py             # درگاه مستندات آفلاین AI API بر پایه استاندارد OpenAPI
├── test_01_auth_and_portal.py      # سناریوهای ۱ تا ۵ (لاگین، رندر پرتال، ناوبری، SPA، پوشه‌گردی)
├── test_02_tags_and_filter.py      # سناریوهای ۶، ۱۳، ۱۴ (فیلتر AND، مدیریت تگ گروه، نمایش در پوشه)
├── test_03_upload_lifecycle.py     # سناریوهای ۷ تا ۹ (مدال آپلود، Drag & Drop، بارگذاری کامل)
├── test_04_folder_workflow.py      # سناریوهای ۱۰ تا ۱۲ (ثبت درخواست پوشه، کارتابل تایید و رد ادمین)
├── test_05_layout_and_ux.py        # سناریوهای ۱۵ تا ۱۹ (اسکرول عمودی، موبایل، ایزولاسیون منو و استور)
└── test_06_ai_swagger.py           # سناریو ۲۰ (مستندات ایزوله و بدون CDN هوش مصنوعی)
```

---

## ۳. فهرست سناریوهای بیست‌گانه (20 End-to-End Scenarios)

| ردیف | شناسه سناریو | سوئیت تست | شرح عملیات و معیارهای پذیرش | پرسونای مجری |
| :---: | :---: | :---: | :--- | :---: |
| ۱ | `test_01_login_flow` | Suite 1 | ورود با نام کاربری و رمز معتبر؛ نمایش خطای قرمز هنگام ورود رمز نادرست بدون کرش صفحه. | Admin / Invalid |
| ۲ | `test_02_archive_portal_hydration` | Suite 1 | هیدراتاسیون SPA، لود ساختار تیره Obsidian، سوئیچ بین حالت‌های گرید (Grid) و جدول (Table). | Admin |
| ۳ | `test_03_global_navigation_bar` | Suite 1 | نمایش هدر ناوبری سراسری دسترسی‌محور (`#ea-global-nav-root`)، چیپ‌های دپارتمان‌های مجاز و هویت کاربر. | Admin |
| ۴ | `test_04_navigation_click_spa` | Suite 1 | کلیک روی چیپ‌های نوار ناوبری و تغییر دایرکتوری در حافظه کلاینت بدون لود مجدد صفحه (Zero Reload). | Admin |
| ۵ | `test_05_folder_browsing` | Suite 1 | کلیک روی ردیف/کارت پوشه سازمانی، به‌روزرسانی محتویات دایرکتوری و اصلاح شمارنده اسناد. | Admin |
| ۶ | `test_06_multi_tag_and_filtering` | Suite 2 | انتخاب چند تگ در نوار تگ‌ها، اعمال منطق اشتراک ریاضی (AND Logic)، نمایش ریبون فیلترها و پاکسازی یکپارچه. | SOC Admin |
| ۷ | `test_07_upload_modal_open_close` | Suite 3 | باز شدن مدال بارگذاری با لایه مات پشت (Backdrop Overlay)، بستن مدال با دکمه ✕ و دکمه انصراف. | SOC Admin |
| ۸ | `test_08_drag_and_drop_dropzone` | Suite 3 | فعال شدن وضعیت Dragover روی کادر Dropzone، استیج شدن فایل در کارت پیش‌نمایش و فعال شدن دکمه ثبت. | SOC Admin |
| ۹ | `test_09_upload_and_autotagging` | Suite 3 | آپلود واقعی فایل متنی از طریق WebDAV، به‌روزرسانی بلادرنگ جدول اسناد، و تخصیص خودکار تگ پوشه. | SOC Admin |
| ۱۰ | `test_10_folder_request_submission` | Suite 4 | تکمیل فرم درخواست ایجاد پوشه توسط ادمین گروه، اعتبارسنجی فیلدها و نمایش پیام موفقیت‌آمیز Toast. | SOC Admin |
| ۱۱ | `test_11_admin_approval_cartable` | Suite 4 | بررسی درخواست‌های در انتظار توسط ادمین کل در کارتابل، تایید با دیالوگ تاییدیه و ساخت اتمیک پوشه. | Super Admin |
| ۱۲ | `test_12_admin_rejection_cartable` | Suite 4 | رد درخواست پوشه توسط ادمین کل، دریافت دلیل رد الزامی با دیالوگ تعاملی و ثبت در سابقه درخواست. | Super Admin |
| ۱۳ | `test_13_group_tag_management_governance` | Suite 2 | باز شدن پنل مدیریت تگ‌های گروه، اعتبارسنجی نام خالی، ساخت تگ اختصاصی و اجرای ترمیم (Reconcile). | SOC Admin |
| ۱۴ | `test_14_locate_in_folder` | Suite 2 | کلیک روی دکمه «مکان در پوشه» در دراور جزئیات فایل، ثبت مسیر در SessionStorage و هدایت به والد. | SOC Admin |
| ۱۵ | `test_15_vertical_scrolling_sticky_header` | Suite 5 | ثبات موقعیت نوار ناوبری بالای صفحه هنگام اسکرول عمیق عمودی به واسطه خاصیت چسبندگی (Sticky Header). | Super Admin |
| ۱۶ | `test_16_responsive_mobile_layout` | Suite 5 | تنظیم رزولوشن مرورگر روی ابعاد موبایل (375x812) و حفظ دسترسی کامل فیلدهای جستجو و تگ‌ها بدون افست افقی. | Super Admin |
| ۱۷ | `test_17_app_menu_isolation` | Suite 5 | عدم دسترسی کاربر عادی به تنظیمات حساس، عدم مشاهده دکمه‌های کارتابل ادمین و تگ‌های گروهی. | Regular CERT |
| ۱۸ | `test_18_app_store_isolation` | Suite 5 | مسدودسازی قطعی کاربر عادی در صورت پیمایش مستقیم به آدرس `/settings/apps` (هدایت مجدد یا رد دسترسی). | Regular CERT |
| ۱۹ | `test_19_url_masking_and_routes` | Suite 5 | پوشش آدرس‌های فیزیکی دیسک سرور در مرورگر و هدایت درگاه به مسیر امن و فاقد اطلاعات افشاکننده سیستم‌عامل. | SOC Admin |
| ۲۰ | `test_20_ai_swagger_ui_air_gapped` | Suite 6 | رندر مستندات تعاملی AI API در مسیر `/api/docs` بدون فراخوانی فونت‌ها یا کتابخانه‌های CDN خارجی (۱۰۰٪ آفلاین). | Super Admin |

---

## ۴. باگ بحرانی فرانت‌اند شناسایی و برطرف‌شده توسط E2E
در جریان تست سناریوی شماره ۸ و ۹ (چرخه آپلود و دراپ‌زون فایل)، تست مرورگری با شکست مواجه شد و خطای زیر را از کنسول رندر گرفت:
```text
playwright._impl._errors.Error: Page.evaluate: ReferenceError: formatBytes is not defined
    at handleFile (archive_portal.js:1187:13)
```
**علت:** تابع `formatBytes()` که برای تبدیل بایت فایل به مقادیر خوانا (کیلوبایت، مگابایت) در هنگام Drag & Drop و استیج فایل فراخوانی می‌شد، در اسکریپت `archive_portal.js` تعریف نشده بود. این خطا منجر به توقف کامل فرآیند آپلود فایل و غیرفعال ماندن دکمه «بارگذاری و ثبت سند» در محیط کاربری می‌شد.

**اصلاح انجام‌شده:**
تابع استاندارد تبدیل بایت با پشتیبانی از ارقام فارسی به ابتدای `archive_portal.js` اضافه گردید:
```javascript
function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '۰ بایت';
    var k = 1024;
    var sizes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    var val = (bytes / Math.pow(k, i)).toFixed(1);
    return toPersianDigits(val) + ' ' + sizes[i];
}
```
پس از همگام‌سازی با کانتینر و ریلود وب‌سرور، سناریوهای آپلود با موفقیت ۱۰۰٪ پاس شدند.

---

## ۵. نحوه اجرای آزمون‌ها و گزارش‌گیری عیب‌یابی

### ۵.۱. اجرای کلیه تست‌های E2E
برای اجرای مستقل تمام ۲۰ سناریوی مرورگری:
```bash
python3 run_e2e_tests.py
```

### ۵.۲. سیاست مواجهه با خطا (Failure Diagnostics)
در صورت بروز هرگونه شکست در هر مرحله از تست:
1. **اسکرین‌شات با نمای تمام‌صفحه:** در دایرکتوری `artifacts/e2e_reports/screenshots/` ذخیره می‌شود (مثال: `FAIL_test_08_...png`).
2. **فایل زیپ تریس Playwright:** شامل استک رویدادهای مرورگر، تایم‌لاین شبکه و DOM Snapshot در مسیر `artifacts/e2e_reports/traces/` ذخیره می‌گردد تا از طریق ابزار `playwright show-trace` قابل تحلیل تصویری باشد.


---

## ۸. تجمیع سوابق Prompt و معیارهای نهایی زیرساخت تست

محتوای تاریخی Promptهای 08، 14، 15 و 16 در این Requirement ادغام شده است. از این پس Requirement 23 مرجع واحد معماری E2E و Test Infrastructure است.

### ۸.۱ Test Runner قابل‌حمل
- run_all_tests.py و runnerهای مرتبط نباید به مسیر absolute یا machine-specific وابسته باشند.
- Root repository باید از محل واقعی پروژه/اسکریپت به‌صورت dynamic تعیین شود.
- Python executable نباید hard-code شود.
- runner باید در Linux، WSL، virtualenv با مسیر متفاوت و CI قابل اجرا باشد.
- خروجی و grouping موجود تا حد امکان حفظ و رفتار تست‌ها بدون دلیل تغییر نکند.

### ۸.۲ Credential Hygiene
- هیچ username/password، Bearer Token، AI Service Token، Database Credential یا secret واقعی نباید در source code تست‌ها قرار گیرد.
- credentialهای Test باید از یک configuration/fixture/environment امن و قابل تنظیم تأمین شوند.
- نبود credential باید با خطای واضح و بدون چاپ secret اعلام شود.
- مقدار deterministic یا default ناامن برای secret واقعی مجاز نیست.
- گزارش‌های تست و failure artifacts نباید secret را افشا کنند.
- credentialهای موجود در repository باید در مستندات فقط به‌صورت masked و بدون بازتولید مقدار واقعی توصیف شوند.

### ۸.۳ Isolation و Cleanup تست‌های Mutating
هر تستی که File/Folder/User/Tag/Share/Metadata یا Database state ایجاد یا تغییر می‌دهد باید resourceهای ساخته‌شده توسط خودش را track کند؛ از fixture/finalizer/context cleanup مناسب استفاده کند؛ cleanup را حتی در failure تا حد امکان اجرا کند؛ هرگز resource متعلق به کاربر یا گروه دیگر را حذف نکند؛ نام resourceهای تستی را collision-resistant انتخاب کند؛ در صورت باقی‌ماندن state diagnostics مشخص تولید کند؛ و سازگاری همزمان File System و Database را در cleanup در نظر بگیرد.

### ۸.۴ معیار پذیرش تکمیلی
[ ] Test runner هیچ path یا Python runtime hard-code شده‌ای ندارد.
[ ] E2E credentials از source code خارج شده‌اند و مقدار واقعی در Git نیست.
[ ] نبود credential باعث failure واضح و بدون افشای secret می‌شود.
[ ] Testهای mutating دارای cleanup قابل اتکا هستند.
[ ] failure pathها نیز cleanup را اجرا می‌کنند.
[ ] Testها فقط state ساخته‌شده توسط خودشان را حذف می‌کنند.
[ ] E2E suite در محیط توسعه و CI قابل پیکربندی است.
[ ] این الزامات بخشی از Requirement 23 محسوب می‌شوند و Prompt مستقل برای آن‌ها لازم نیست.
