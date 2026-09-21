# مستندات نیازمندی‌های سامانه آرشیو سازمانی (Enterprise Archive System Requirements)

این پوشه، مرجع رسمی، زنده و ماژولار (Living Documentation) برای تمامی نیازمندی‌ها، سوابق معماری، جریان‌های کاری و جزئیات پیاده‌سازی پروژه **Enterprise Archive System** است. هر سند در این مجموعه، یک نیازمندی واقعی را همراه با کلیه جزئیات فنی، مدل مفهومی، اجزای بک‌اند، فرانت‌اند، دیتابیس، امنیت، آزمون‌های واقعی و چک‌لیست‌های استقرار در ۲۳ بخش استاندارد مستند می‌کند.

---

## ۱. نقشه جامع نیازمندی‌های سامانه (Master Requirement Map)

بر اساس بررسی موشکافانه کل مخزن Git، کلیه کامیت‌ها، کدهای عملیاتی و تغییرات تاریخی، تمامی نیازمندی‌های سیستم در قالب **۱۲ سند استاندارد و ماژولار** تدوین و نهایی‌سازی شده‌اند:

| شماره | عنوان نیازمندی | سند مرجع | ماهیت | وابستگی‌ها | خلاصه حوزه پیاده‌سازی | وضعیت در مخزن |
| :---: | :--- | :--- | :---: | :---: | :--- | :---: |
| **01** | **زیرساخت کانتینری و استقرار پایه سامانه** | [`01_infrastructure_and_containerization.md`](01_infrastructure_and_containerization.md) | مستقل | — | استک سه‌لایه Nginx+Nextcloud+PostgreSQL، پورت 80، بایند مانت‌های محلی، آپلود بدون بافر ۱۰ گیگابایت، اسکریپت استقرار و سلامت‌سنجی | عملیاتی و نهایی |
| **02** | **موتور برچسب‌گذاری خودکار سلسله‌مراتبی اسناد** | [`02_hierarchical_autotagging.md`](02_hierarchical_autotagging.md) | مستقل | 01 | تگ‌گذاری خودکار حین آپلود، استخراج سلسله‌مراتبی پوشه‌های والد، همگام‌سازی تغییر نام، فرامین خط فرمان `archive:retag` و `archive:tag:reconcile` | عملیاتی و نهایی |
| **03** | **سقف حجم مجاز آپلود فایل به ازای هر کاربر** | [`03_per_user_upload_limits.md`](03_per_user_upload_limits.md) | مستقل | 01, 02 | کنترل حجم تک‌فایل مستقل از سهمیه کل دیسک، ذخیره در `oc_preferences`، فرمان خط فرمان `archive:user:limit` و استثنای حساب مدیر | عملیاتی و نهایی |
| **04** | **محدودیت ایجاد پوشه و انضباط ساختار آرشیو** | [`04_folder_creation_restrictions.md`](04_folder_creation_restrictions.md) | مستقل | 01, 02 | تفکیک مجوز آپلود از ساخت پوشه، مسدودسازی متد WebDAV MKCOL با خطای ۴۰۳ برای کاربران عادی، انحصار ساخت دایرکتوری به مدیر سیستم | عملیاتی و نهایی |
| **05** | **پایداری داده‌ها، معماری ذخیره‌سازی و بازیابی فاجعه** | [`05_data_persistence_and_backup.md`](05_data_persistence_and_backup.md) | مستقل | 01 | سیاست کانتینری `unless-stopped`، بکاپ جامع دیتابیس و کدهای سفارشی همراه با هش SHA-256 در `backup_db.sh` و بازیابی در `restore_db.sh` | عملیاتی و نهایی |
| **06** | **حاکمیت حساب‌های کاربری، ایزولاسیون سهمیه و چرخه عمر** | [`06_user_governance_and_lifecycle.md`](06_user_governance_and_lifecycle.md) | مستقل | 01, 04 | سهمیه شخصی 0B برای تمرکز اسناد در آرشیو مشترک، لیسنر `BeforeUserDeletedListener` برای منع حذف کاربر توسط غیرادمین، ممیزی نقش‌ها | عملیاتی و نهایی |
| **07** | **فیلتر چندتگی اسناد آرشیو (Multi-Tag AND Engine)** | [`07_multi_tag_filtering.md`](07_multi_tag_filtering.md) | مستقل | 02 | کنترلر `TagFilterController`، فیلتر اشتراک مجموعه‌ها در SQL، جدول یکپارچه ۴ ستونه آبسیدین در تمام نماهای پوشه و فیلتر تگ‌ها، ناوبری مستقیم و شکست خط استاندارد | عملیاتی و نهایی |
| **08** | **کنترل دسترسی به فایل و ایزولاسیون برچسب‌ها** | [`08_access_control_and_tag_isolation.md`](08_access_control_and_tag_isolation.md) | مستقل | 02, 07 | رپر ذخیره‌سازی `ArchiveFileIsolationWrapper`، سیستم تگ ایزوله `IsolatedSystemTagManager`، مایگریشن‌های ۱۴۰۰ و ۱۶۰۰ و دستورات گرنت | عملیاتی و نهایی |
| **09** | **پرتال اختصاصی آرشیو و پوسته یکپارچه آبسیدین** | [`09_archive_portal_and_obsidian_theme.md`](09_archive_portal_and_obsidian_theme.md) | مستقل | 07, 08 | پورتال اختصاصی SPA، تم تیره آبسیدین (Glassmorphism)، آکاردئون ناوبری، بوم تمام‌صفحه بدون حاشیه کاذب، ناوبری مستقیم پوشه والد، رفع هم‌پوشانی هدر و چیپ‌های فیلتر | عملیاتی و نهایی |
| **10** | **شخصی‌سازی هویت بصری، ماسکینگ، ویزارد آغازین و فیلتر اپ‌استور** | [`10_branding_masking_and_app_menu_filter.md`](10_branding_masking_and_app_menu_filter.md) | مستقل / تکمیلی | 09, 14 | فیلتر منوها، حذف اپ‌استور، یکپارچه‌سازی تم سازمانی، ماسک URL (`url_mask.js`)، هاست `docs.maskan`، و بازطراحی کامل ویزارد آغازین ۲ صفحه‌ای با تم آبسیدین (Hero و Core Capabilities) | عملیاتی و نهایی |
| **11** | **کارتابل درخواست ایجاد پوشه توسط مدیران گروه** | [`11_group_admin_folder_request_portal.md`](11_group_admin_folder_request_portal.md) | تکمیلی | 04, 08, 09 | فرم پاپ‌آپ پرتال با منوی کشویی داینامیک پوشه‌های والد، کارتابل تایید/رد ادمین، ساخت خودکار اتمیک پوشه و تگ، ممیزی و ضد رقابت | عملیاتی و نهایی |
| **12** | **معماری و نقشه راه هوش مصنوعی On-Premise ایزوله** | [`12_on_premise_ai_roadmap.md`](12_on_premise_ai_roadmap.md) | نقشه راه | 01, 02, 08 | معماری پردازش آفلاین بدون GPU بر بستر پردازنده اینتل i7، اجرای محلی مدل `Qwen2.5-1.5B`، پایگاه داده FTS5 و تحلیل درصد پیشرفت | نقشه راه مصوب |
| **13** | **رابط برنامه‌نویسی امن واکشی فایل برای هوش مصنوعی لوکال** | [`13_secure_ai_file_retrieval_api.md`](13_secure_ai_file_retrieval_api.md) | مستقل | 02, 08, 12 | استریم باینری O(1) رم، احراز هویت ماشین رمزنگاری‌شده، هشینگ SHA-256، تفویض ایمن Deny-by-default، چرخش و ابطال توکن، پرتال Swagger UI آفلاین و ممیزی غنی oc_archive_ai_audit | عملیاتی و نهایی (v2.0.9) |
| **14** | **ایزولاسیون کامل سیستم، مسدودسازی سرویس‌های خارجی و محیط بسته** | [`14_air_gapped_isolation_and_external_services_lockdown.md`](14_air_gapped_isolation_and_external_services_lockdown.md) | تکمیلی / مستقل | 01, 04, 10 | ایزولاسیون Air-Gapped کامل، قطع اینترنت هسته، غیرفعالسازی برنامه‌های فدراسیون/تله‌متری، مسدودسازی روت‌های Help/Apps، سلب پیوندهای عمومی و CSP بومی | عملیاتی و نهایی |
| **15** | **مدیریت تگ‌های اختصاصی گروه توسط ادمین گروه و ایزولاسیون بین‌گروهی** | [`15_group_admin_tag_governance.md`](15_group_admin_tag_governance.md) | مستقل / تکمیلی | 02, 07, 08, 11 | مدیریت کامل تگ‌های گروهی توسط Group Admin با مدل Zero-Bypass، حفظ ۱۰۰٪ تگ‌گذاری خودکار، الصاق و حذف درجا در پورتال و کشو، جدول ممیزی اختصاصی `oc_archive_tag_audit` | عملیاتی و نهایی |
| **16** | **نوار ناوبری دسترسی‌محور سراسری** | [`16_access_aware_global_navigation.md`](16_access_aware_global_navigation.md) | مستقل | 04, 09 | کنترلر ناوبری سلسله‌مراتبی، ریشه سازمانی Enterprise_Archive، چیپ‌های دپارتمان مجاز با هایلایت فعال | عملیاتی و نهایی |
| **17** | **لایه متمرکز حل دسترسی و محاسبه مجوزهای موثر (Central Permission Resolver)** | [`17_central_permission_resolver_and_effective_acl.md`](17_central_permission_resolver_and_effective_acl.md) | مستقل / زیرساختی | 08, 13, 15, 16 | موتور یکپارچه CentralPermissionResolver بر اساس Deny-by-Default، بیت‌ماسک عملیات ۸گانه، تقدم ساختار دپارتمانی بر مالکیت، بازرس تعاملی در UI | عملیاتی و نهایی (v2.1.0) |
| **18** | **ایزولاسیون ذخیره‌سازی بسته در برابر شکست و مقاوم‌سازی کش (Fail-Closed Storage Isolation & Cache Hardening)** | [`18_fail_closed_storage_isolation_and_cache_hardening.md`](18_fail_closed_storage_isolation_and_cache_hardening.md) | مستقل / زیرساختی امنیتی | 08, 17 | حذف قطعی آسیب‌پذیری Fail-Open، مسدودسازی پروب‌های خارج از کش و مسیرهای دستکاری‌شده، ارزیابی مجوز والد در آپلود و ایجاد فایل، اسکن داینامیک فیزیکی استوریج قبل از رد درخواست | عملیاتی و نهایی (v2.1.1) |
| **19** | **بازطراحی سرویس مالکیت فایل و انطباق با دسترسی موثر (FileOwnershipService Redesign & Effective ACL)** | [`19_file_ownership_redesign_and_effective_acl.md`](19_file_ownership_redesign_and_effective_acl.md) | مستقل / زیرساختی امنیتی | 08, 17, 18 | تفکیک ۴ بعد مالکیت، سد دفاعی ابطال صریح (Explicit Revocation با ماسک ۰)، ارث‌بری آبشاری گرنت والد، مهار رخدادهای رقابتی و حذف کامل کوئری‌های N+1 | عملیاتی و نهایی (v2.1.2) |
| **20** | **حاکمیت اتمیک تگ‌های گروهی، رفع موفقیت کاذب و موتور همگام‌سازی (Atomic Group Tag Deletion & Consistency Engine)** | [`20_atomic_group_tag_governance_and_consistency.md`](20_atomic_group_tag_governance_and_consistency.md) | مستقل / زیرساختی حاکمیتی | 08, 15, 17 | حذف موفقیت کاذب در حذف تگ، تراکنش اتمیک ۴ لایه هسته و آرشیو، قفل سطری بدبینانه، پیشگیری از حذف تگ‌های در حال استفاده با خطای 409، و موتور خودترمیم Reconcile برای کشف و رفع تگ‌های یتیم و ارواح متا‌دیتا | عملیاتی و نهایی (v2.1.3) |
| **21** | **سیستم ممیزی قابل‌اعتماد و نفوذناپذیر (Reliable Audit Subsystem & Fail-Closed Gating)** | [`21_reliable_audit_subsystem.md`](21_reliable_audit_subsystem.md) | مستقل / زیرساختی امنیتی | 08, 13, 17, 20 | طبقه‌بندی ممیزی به Audit-Required و Audit-Best-Effort، جدول اختصاصی `oc_archive_permission_audit`، صف اضطراری DLQ محلی مقاوم، پاک‌سازی لاگ‌ها در برابر CRLF Injection و اندپوینت‌های سلامت و استریم لاگ | عملیاتی و نهایی (v2.1.4) |
| **22** | **بازطراحی مدل معنایی، چرخه حیات و حسابداری دقیق بایت‌ها در ممیزی هوش مصنوعی (AI Audit Semantic Model & Precise Byte Accounting)** | [`22_ai_audit_semantic_model.md`](22_ai_audit_semantic_model.md) | تکمیلی / امنیتی هوش مصنوعی | 13, 21 | تفکیک سه‌بعدی تصمیم امنیتی، چرخه حیات و وضعیت انتقال، حذف موفقیت کاذب، پاسخ استریم ممیزی‌شده `AuditedStreamResponse`، حسابداری بایت‌های واقعی ارسالی، مدیریت قطعی کلاینت و خطای استوریج | عملیاتی و نهایی (v2.1.5) |
| **23** | **لایه تست‌های مرورگری واقعی سرتاسری با Playwright و الگوی Page Object Model (Real Browser E2E Testing Layer)** | [`23_real_browser_e2e_testing.md`](23_real_browser_e2e_testing.md) | تکمیلی / تضمین کیفیت و حاکمیت | 01-22 | پوشش کامل ۲۰ سناریوی مرورگری در ۶ سوئیت تست مستقل با کرومیوم هدلس، ایزولاسیون کامل نشست‌ها با StorageState، اعتبارسنجی فرانت‌اند SPA و کشف باگ‌های زمان اجرای جاوااسکریپت، تشخیص خرابی با اسکرین‌شات و فایل تریس خودکار | عملیاتی و نهایی (v2.2.0) |

---

## ۲. ساختار استاندارد ۲۳گانه هر سند نیازمندی
تمامی ۲۰ سند نیازمندی دارای ساختار کاملاً یکپارچه و استاندارد شامل بخش‌های زیر هستند:
1. **شرح نیازمندی (Problem Statement & Business Need)**
2. **نیازمندی‌های تابعی (Functional Requirements)**
3. **نیازمندی‌های غیرتابعی (Non-Functional Requirements)**
4. **معماری و مدل مفهومی (Architectural & Conceptual Model)**
5. **رفتار پیش‌فرض Nextcloud و شکاف موجود (Nextcloud Default Behavior vs Custom Need)**
6. **رویکردهای بررسی‌شده و دلایل رد گزینه‌های نامناسب (Trade-offs & Rejected Alternatives)**
7. **مدل داده و تغییرات پایگاه داده (Data Model & Schema Evolution)**
8. **ساختار کد و فایل‌های پیاده‌سازی (Code Structure & File Breakdown)**
9. **هوک‌ها، ایونت‌ها و نقاط اتصال به هسته (Hooks, Events & Integration Points)**
10. **منطق گام‌به‌گام پردازش (Detailed Flow / Algorithm)**
11. **وابستگی‌ها و پیش‌نیازها (Dependencies & Prerequisites)**
12. **مدیریت خطا و سناریوهای استثنا (Failure Modes & Edge Cases)**
13. **دسترسی‌ها، نقش‌ها و امنیت (Security, Roles & Permissions)**
14. **APIها و پروتکل‌ها (APIs & Protocols)**
15. **تنظیمات و متغیرهای پیکربندی (Configuration & Parameters)**
16. **عملکرد و مقیاس‌پذیری (Performance & Scalability)**
17. **قابلیت مشاهده‌پذیری، لاگ‌ها و آدیت (Observability & Logging)**
18. **سناریوهای تست و اعتبارسنجی (Testing & Verification Scenarios)**
19. **بدهی فنی و محدودیت‌های شناخته‌شده (Technical Debt & Known Limitations)**
20. **تحلیل اثر بر سایر نیازمندی‌ها (Impact Analysis & Cross-Requirement Matrix)**
21. **چک‌لیست استقرار، بکاپ و ریکاوری (Deployment, Backup & Recovery Checklist)**
22. **ارتباط با سایر اسناد (Related Documents)**
23. **وضعیت نهایی (Final Implementation Status)**

---

## ۳. قانون دائمی مستندات پویا (Living Documentation Governance)
تمامی تغییرات، اصلاحات و قابلیت‌های آینده سیستم باید از این چرخه استاندارد عبور کنند:
```text
Requirement → Design → Approval → Implementation → Test → Verification → Documentation Update → Commit/Push
```
* **توسعه قابلیت‌های موجود:** در صورت توسعه یا بهینه‌سازی قابلیت‌های قبلی، سند شماره‌دار مربوطه مستقیماً به‌روزرسانی و سوابق آن تکمیل می‌شود.
* **معرفی قابلیت‌های بنیادین جدید:** در صورت ارائه نیازمندی کاملاً مستقل با دامنه جدید، سند شماره ۲۱ به بعد طبق همین فرمت ۲۳گانه ایجاد خواهد شد.

