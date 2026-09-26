# نیازمندی ۱۳: ایزولاسیون کامل سیستم، مسدودسازی سرویس‌های خارجی و محیط بسته (Air-Gapped Isolation & External Services Lockdown)

## ۱. بیان مسئله و زمینه (Problem Statement & Context)
اسناد و پرونده‌های بارگذاری‌شده در سامانه بایگانی اسناد سازمانی (**Enterprise Archive System**) در بالاترین سطوح محرمانگی، انطباق و حاکمیت داده قرار دارند. هرگونه ارتباط سامانه‌ای با شبکه اینترنت، ارسال تله‌متری، استعلام به‌روزرسانی نرم‌افزار، امکان تبادل فایل با سرورهای خارجی (Federation)، اتصال به فضاهای ابری تجاری نظیر Dropbox و Google Drive، و همچنین دسترسی کاربران به پیوندها و دامنه‌های بیرونی (نظیر مستندات عمومی، مارکت‌پلیس‌ها و شبکه‌های اجتماعی) مخاطره‌ای امنیتی و نقض حاکمیت بر داده‌های محرمانه سازمانی تلقی می‌گردد.

سازمان نیازمند تبدیل قطعی این سامانه به یک **محیط آرشیو کاملاً بسته و کنترل‌شده (Fully Air-Gapped / Isolated On-Premise)** بود؛ محیطی که در آن علاوه بر حذف بصری دسترسی‌ها در رابط کاربری، کل زنجیره ناوبری، روت‌ها، کنترلرها، وب‌سرویس‌های OCS/REST، پروتکل WebDAV و پردازش‌های پس‌زمینه (Background Cron Jobs) به صورت هماهنگ و بدون راه دور زدن مسدود شوند.

---

## ۲. اصل حاکم: مسدودسازی فراتر از مخفی‌سازی ظاهری (Beyond Visual Hiding)
بر اساس اصل شماره ۱ این نیازمندی، هر قابلیتی که نباید برای کاربران یا در کل سامانه وجود داشته باشد، در تمامی سطوح هفت‌گانه مسدود گردیده است:
```text
UI Menu
  ↓
Direct URL
  ↓
Internal Route
  ↓
API / OCS
  ↓
WebDAV
  ↓
Configuration
  ↓
Background Operation
```

---

## ۳. نیازمندی‌های تابعی (Functional Requirements)
1. **مسدودسازی کامل فروشگاه برنامه‌ها (App Store Lockdown):**
   - غیرفعالسازی کامل اتصال به `apps.nextcloud.com`.
   - مسدودسازی دسترسی به روت `/settings/apps` با خطای صریح ۴۰۳ برای کاربران غیرادمین.
   - حذف کامل دکمه‌های حاشیه‌دار `+`، نشانه‌ها و برچسب‌های اپ‌استور در لایه کلاینت.
2. **امحای فدراسیون و اشتراک با ابرهای خارجی (Federation & Remote Sharing Purge):**
   - قطع امکان اشتراک‌گذاری با سامانه‌های ابری خارجی بر بستر Federated Cloud ID.
   - تنظیم `sharing.federation.allow_outgoing = false` و `sharing.federation.allow_incoming = false`.
   - تخلیه و خنثی‌سازی آدرس سرور جهانی جستجو (`lookup_server = ''`).
3. **مسدودسازی ذخیره‌سازهای خارجی (External Storage Lockdown):**
   - تثبیت وضعیت غیرفعال برنامه `files_external`.
   - تخلیه پارامتر `user_mounting_backends` جهت سلب هرگونه امکان افزودن ذخیره‌سازهای Google Drive، Dropbox، SFTP، SMB یا WebDAV توسط کاربران.
   - بازگرداندن خطای صریح ۴۰۴ در استعلام مسیرها و APIهای `files_external`.
4. **مسدودسازی ایجاد لینک‌های عمومی (Public Link Sharing Block):**
   - جلوگیری از تولید پیوندهای عمومی دانلود ناشناس (`shareType: 3`) برای اسناد آرشیو با تنظیم `shareapi_allow_links = no`.
   - حفظ اشتراک‌گذاری داخلی میان اعضا و گروه‌های سازمانی مجاز.
5. **امحای لینک‌ها، مستندات و شبکه‌های اجتماعی بیرونی:**
   - مسدودسازی مسیر `/settings/help` با خطای ۴۰۳ صریح و تنظیم `knowledgebaseenabled = false`.
   - حذف منوهای Help و دیالوگ معرفی کلاینت‌های اندروید/iOS و دانلود کلاینت دسکتاپ (`firstrunwizard`).
   - حذف لینک‌های شبکه‌های اجتماعی (Bluesky, Mastodon, Facebook, Github) از فوتر صفحات تنظیمات.
   - پاکسازی پیوند پیش‌فرض `https://nextcloud.com` از تنظیمات برندینگ (`theming.url = ''`).
6. **سلب ارتباطات تله‌متری و به‌روزرسانی (Telemetry & Update Checker Elimination):**
   - غیرفعال‌سازی سرویس `survey_client` و لغو ارسال آمارهای محرمانه مصرف.
   - تنظیم `updatechecker = false` و غیرفعال‌سازی برنامه `updatenotification`.
   - تنظیم `has_internet_connection = false` در هسته جهت ممانعت از ارسال پینگ‌های سلامت به دامنه‌های خارجی.

---

## ۴. معماری و مدل مفهومی (Architectural Model)

```
                               ┌─────────────────────────────────────────┐
                               │   کاربر سازمانی / پرتال آرشیو اسناد     │
                               └────────────────────┬────────────────────┘
                                                    │
                                                    ▼
 ┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
 │                                   Nginx Reverse Proxy (لایه ۱)                                  │
 │ - اعمال CSP محلی: default-src 'self' data: blob: (حذف دامنه‌های خارجی نظیر OpenStreetMap)      │
 │ - فیلتر روت مستقیم: مسدودسازی /settings/help با خطای ۴۰۳ صریح                                    │
 └──────────────────────────────────────────────────┬──────────────────────────────────────────────┘
                                                    │
                                                    ▼
 ┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
 │                              Nextcloud Core & Configuration (لایه ۲)                            │
 │ - has_internet_connection => false  |  appstoreenabled => false  |  updatechecker => false      │
 │ - knowledgebaseenabled => false     |  lookup_server => ''       |  shareapi_allow_links => no  │
 └──────────────────────────────────────────────────┬──────────────────────────────────────────────┘
                                                    │
                                                    ▼
 ┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
 │                             Pruned Apps & Disabled Endpoints (لایه ۳)                           │
 │ - غیرفعالسازی: federation, nextcloud_announcements, survey_client, updatenotification,         │
 │   weather_status, firstrunwizard, support, sharebymail                                          │
 └──────────────────────────────────────────────────┬──────────────────────────────────────────────┘
                                                    │
                                                    ▼
 ┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
 │                              Custom App Menu & UI Purge Filter (لایه ۴)                         │
 │ - app_menu_filter.js & .css: امحای لینک‌های خارجی، غیرفعال‌سازی کلیک و ممانعت از پرش چیدمان     │
 └─────────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## ۵. ماتریس دسترسی و طبقه‌بندی نقش‌ها (Role & Access Matrix)

| قابلیت | کاربران عادی | مدیران گروه‌ها | مدیر ارشد سیستم (`admin`) | نوع مکانیزم مسدودسازی |
| :--- | :---: | :---: | :---: | :--- |
| **دسترسی به App Store** | مسدود (۴۰۳) | مسدود (۴۰۳) | غیرفعال در هسته | `appstoreenabled=false` + بررسی مجوز OCS |
| **ارسال به ابرهای خارجی (Federation)** | مسدود (غیرفعال) | مسدود (غیرفعال) | مسدود (غیرفعال) | `sharing.federation.allow_outgoing=false` |
| **افزودن ذخیره‌ساز خارجی (External Storage)** | مسدود (۴۰۴) | مسدود (۴۰۴) | مسدود (غیرفعال) | اپلیکیشن غیرفعال + `user_mounting_backends=''` |
| **ایجاد پیوند عمومی (Public Links)** | مسدود | مسدود | مسدود | `shareapi_allow_links=no` |
| **مستندات بیرونی و Help** | مسدود (۴۰۳) | مسدود (۴۰۳) | مسدود (۴۰۳) | مسدودسازی روت در Nginx + `knowledgebaseenabled=false` |
| **دیالوگ کلاینت‌ها (FirstRunWizard)** | مسدود (حذف) | مسدود (حذف) | مسدود (حذف) | اپلیکیشن غیرفعال |
| **استعلام آپدیت و تله‌متری** | مسدود | مسدود | مسدود | `has_internet_connection=false` + `updatechecker=false` |
| **پرتال اسناد سازمانی (Enterprise Archive)** | مجاز و فعال | مجاز و فعال | مجاز و فعال | هسته بومی آرشیو |
| **آپلود، بارگیری، جستجو و فیلتر تگ‌ها** | مجاز و فعال | مجاز و فعال | مجاز و فعال | موتور برچسب‌گذاری مستقل محلی |

---

## ۶. اقدامات پیاده‌سازی‌شده در کدهای سامانه (Implementation Details)

### ۶.۱. پیکربندی هسته در `config/config.php`
مقادیر زیر به صورت دائمی در پیکربندی ذخیره شدند:
```php
'has_internet_connection' => false,
'appstoreenabled' => false,
'updatechecker' => false,
'lookup_server' => '',
'check_for_working_htaccess' => false,
'knowledgebaseenabled' => false,
'sharing.federation.allow_outgoing' => false,
'sharing.federation.allow_incoming' => false,
'simpleSignUpLink.shown' => false,
'lost_password_link' => 'disabled',
'connectivity_check_domains' => array(),
```

### ۶.۲. توقف برنامه‌های دارای ریسک تماس خارجی
برنامه‌های زیر از چرخه هسته خارج شدند:
* `federation`
* `nextcloud_announcements`
* `survey_client`
* `updatenotification`
* `weather_status`
* `firstrunwizard`
* `support`
* `sharebymail`

### ۶.۳. مقاوم‌سازی Nginx Reverse Proxy (`nginx/default.conf`)
* افزودن `proxy_hide_header Content-Security-Policy;` جهت ممانعت از ارسال دامنه‌های نقشه خارجی توسط هسته.
* اعمال هدر ایزوله محلی:
  ```nginx
  add_header Content-Security-Policy "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; connect-src 'self'; font-src 'self' data:; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; frame-src 'self'; object-src 'none';" always;
  ```
* مسدودسازی روت مستقیم به Help:
  ```nginx
  location ~* ^/(index\.php/)?settings/help {
      return 403 "External documentation is disabled in this air-gapped archive system.";
  }
  ```

### ۶.۴. فیلتر کلاینت (`app_menu_filter.js` و `app_menu_filter.css`)
* قوانین CSS جهت امحای المان‌های ارجاع‌دهنده به دامنه‌های بیرونی.
* اسکریپت مسدودکننده رویداد کلیک بر روی هرگونه پیوند منتهی به آدرس‌های اینترنتی خارج از سامانه.

---

## ۷. سناریوهای آزمون و اعتبارسنجی (Testing & Verification)
سوئیت آزمون تخصصی **`tests/test_air_gapped_isolation.py`** طراحی و پیاده‌سازی شد که موارد زیر را به صورت خودکار می‌آزماید:
1. `test_01`: انطباق قطعی متغیرهای ایزولاسیون هسته (`has_internet_connection`، `appstoreenabled` و ...).
2. `test_02`: عدم حضور اپلیکیشن‌های وابسته به اینترنت در لیست برنامه‌های فعال سرور.
3. `test_03`: خطای صریح ۴۰۳ برای کاربران غیرادمین در فراخوانی `/settings/apps`.
4. `test_04`: خطای ۴۰۴ در فراخوانی API و روت‌های `files_external`.
5. `test_05`: غیرفعال بودن قابلیت اشتراک عمومی و فدراسیون در خروجی قابلیت‌های هسته OCS.
6. `test_06`: دریافت پاسخ ۴۰۳ در فراخوانی مستقیم `/settings/help` و `/index.php/settings/help`.
7. `test_07`: عدم وجود گزینه‌های Help و About در منوی ناوبری کاربر.
8. `test_08`: انطباق هدر CSP پراکسی و عدم وجود دامنه‌های خارجی.
9. `test_09`: وجود کدهای امحای پیوندهای خارجی در دارایی‌های استاتیک فرانت‌اند.

---

## ۸. پیشگیری از رگرسیون (Regression Prevention)
تمامی قابلیت‌های بومی و ضروری آرشیو سازمانی دست‌نخورده باقی مانده‌اند:
* مشاهده، بارگیری و بارگذاری اسناد با پروتکل داخلی WebDAV.
* درخت دپارتمان‌ها و فرآیند درخواست و ساخت پوشه توسط ادمین و مدیران گروه.
* سامانه برچسب‌گذاری سلسله‌مراتبی و فیلتر همزمان تگ‌ها.
* احراز هویت توکن Bearer و دسترسی سرویس هوش مصنوعی به فایل‌ها.

---

## ۹. ارتباط با سایر نیازمندی‌ها (Requirement Lineage)
* **امتداد و تکمیل نیازمندی ۱۰:** این نیازمندی ایزولاسیون فرانت‌اند در سند [10_branding_masking_and_app_menu_filter.md](file:///home/alborz/enterprise-archive-system/docs/requirements/10_branding_masking_and_app_menu_filter.md) را به سطوح عمیق معماری شبکه، هسته، بک‌اند، API و پردازش‌های پس‌زمینه گسترش داد.
* **ارتباط با نیازمندی ۸ و ۱۱:** تضمین می‌کند که داده‌های کنترل دسترسی و پوشه‌های سازمانی هیچ‌گاه از مرزهای محلی خارج نمی‌شوند.

---

## ۱۰. وضعیت نهایی (Final Implementation Status)
* **وضعیت پیاده‌سازی:** کامل، تست‌شده و فعال در محیط عملیاتی کانتینرها.
* **تست‌های خودکار:** قبولی ۱۰۰٪ در سوئیت آزمون اختصاصی `test_air_gapped_isolation.py` و تایید عدم رگرسیون در رانر جامع ۱۹گانه.
