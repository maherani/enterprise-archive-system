# سند نیازمندی شماره ۲۰: حاکمیت اتمیک تگ‌های گروهی و حفظ یکپارچگی (Atomic Group Tag Governance & Consistency)

---

## ۱. شرح نیازمندی (Problem Statement & Business Need)
در نسخه قبلی سامانه بایگانی اسناد سازمانی (Enterprise Archive System)، متد حذف تگ‌های اختصاصی گروه (`GroupTagService::deleteGroupTag`) دارای یک آسیب‌پذیری و نقص معماری عمده در مدیریت خطا و مرز تراکنش‌ها بود:
1. **تولید Success کاذب (False Success):** فراخوانی حذف تگ سیستمی هسته نکست‌کلود (`ISystemTagManager::deleteTags`) درون یک بلوک `try-catch` عمومی قرار داشت که در صورت وقوع هرگونه خطا، استثنا را بلعیده و تنها یک Warning ثبت می‌کرد. سپس متدهای حذف متادیتا (`archive_tag_ownership` و `archive_tag_groups`) و ثبت لاگ آدیت اجرا می‌شدند و پیام موفقیت (`status: success`) بازگردانده می‌شد.
2. **عدم وجود مرز یکپارچه تراکنش (Lack of Unified Transaction Boundary):** عملیات حذف فیزیکی تگ و روابط انتساب آن از جداول هسته (`oc_systemtag`, `oc_systemtag_object_mapping`) خارج از یک تراکنش کنترل‌شده با جداول ماژول بایگانی انجام می‌گرفت.
3. **ایجاد تگ‌های یتیم و بدون حاکمیت (Orphan & Unmanaged Tags):** با حذف ناقص، تگ در دیتابیس باقی می‌ماند اما رکوردهای ایزولاسیون گروهی آن حذف می‌شدند که منجر به نشت تگ به لیست عمومی یا سایر کاربران می‌گردید.
4. **حذف بدون بررسی تگ‌های در حال استفاده:** حذف تگ بدون بررسی انتساب فعال آن به اسناد، موجب قطع ارتباط داده‌ها بدون هشدار به کاربر می‌شد.
5. **نبود مکانیزم بازآشتی و خودترمیمی (Reconciliation):** راهکاری برای شناسایی تگ‌های یتیم یا رکوردهای فانتوم جامانده از قبل وجود نداشت.

**هدف نیازمندی:** بازطراحی کامل چرخه حیات و متد حذف تگ‌های اختصاصی گروه با ایجاد مرز تراکنش اتمیک در پایگاه داده PostgreSQL، قفل‌گذاری بدبینانه سطری (`FOR UPDATE`)، ممانعت قطعی از Success کاذب، مدیریت تگ‌های در حال استفاده با پرچم `--force`، و پیاده‌سازی موتور خودترمیمی و بازآشتی (Reconciliation Engine).

---

## ۲. نیازمندی‌های تابعی (Functional Requirements)
- **FR-01:** ممانعت ۱۰۰٪ از صدور پاسخ موفقیت یا ثبت لاگ آدیت `success` قبل از Commit قطعی تمامی عملیات حذف در پایگاه داده.
- **FR-02:** اجرای یکپارچه تمامی مراحل حذف فیزیکی (`oc_systemtag_object_mapping`، `oc_systemtag`، `oc_systemtag_group`، `oc_archive_tag_groups` و `oc_archive_tag_ownership`) در یک تراکنش دیتابیس با Rollback کامل در صورت بروز هرگونه استثنا.
- **FR-03:** قفل‌گذاری سطری بدبینانه (`SELECT ... FOR UPDATE`) بر روی رکورد مالکیت تگ جهت جلوگیری از رقابت (Race Condition) و حذف موازی/همزمان.
- **FR-04:** بررسی انتساب تگ به فایل‌ها قبل از حذف؛ بازگرداندن خطای `409 Conflict` با کد `TAG_IN_USE` در صورت وجود فایل‌های متصل بدون پرچم `force`.
- **FR-05:** پشتیبانی از حذف اجباری (`force: true` یا پرچم `--force` در CLI) جهت جداسازی آبشاری تگ از اسناد و حذف اتمیک آن.
- **FR-06:** پیاده‌سازی موتور خودترمیمی تگ‌های گروه (`reconcileGroupTags`) جهت شناسایی و پیوند مجدد تگ‌های یتیم و پاکسازی رکوردهای فانتوم.
- **FR-07:** تعبیه دکمه «همگام‌سازی و ترمیم تگ‌ها» و مدال تایید حذف اجباری در پرتال آرشیو اسناد (UI).
- **FR-08:** هماهنگی کامل CLI `occ archive:tag:gov` با اکشن‌های جدید `delete --force` و `reconcile-group <groupId>`.

---

## ۳. نیازمندی‌های غیرتابعی (Non-Functional Requirements)
- **NFR-01 (Atomicity & ACID):** تمامی جداول درگیر باید یا به‌طور کامل پاکسازی شوند یا در صورت شکست، حالت سیستم دقیقاً به وضعیت قبل از شروع بازگردد (Zero Partial State).
- **NFR-02 (Audit Integrity):** در صورت بروز خطا، لاگ آدیت با نتیجه `failure` و شرح دقیق خطا ثبت گردد؛ نتیجه `success` صرفاً پس از اتمام موفق تراکنش مجاز است.
- **NFR-03 (Performance):** بررسی وجود و انتساب تگ با استفاده از ایندکس‌های یکتای PostgreSQL در کمتر از ۵۰ میلی‌ثانیه پردازش شود.
- **NFR-04 (Zero-Regression):** حفظ سازگاری ۱۰۰٪ با تمامی آزمون‌های قبلی سیستم شامل فیلتر چندتگی، ایزولاسیون دپارتمانی و AI File Retrieval.

---

## ۴. معماری و مدل مفهومی (Architectural & Conceptual Model)

### ماشین وضعیت چرخه حیات تگ (Tag Lifecycle State Machine)
```
  [ایجاد تگ] ──> ACTIVE ──[تلاش برای حذف]──> DELETING (قفل سطری)
                  ▲                                │
                  │ (Rollback)                     ├──> شکست / خطا ──> FAILED_DELETION (ثبت آدیت failure)
                  │                                │                          │
                  └─────────────── (Reconciliation) ┴──> Commit موفق         │
                                                              │              ▼
                                                              ▼          RECOVERED /
                                                           DELETED     (اصلاح یا پاکسازی)
```

---

## ۵. رفتار پیش‌فرض Nextcloud و شکاف موجود
در نکست‌کلود پایه:
- کلاس `ISystemTagManager::deleteTags` متدهای حذفی را فراخوانی می‌کند اما تراکنش سراسری پایگاه داده باز نمی‌کند.
- هیچ نگاشت مالکیتی یا ایزولاسیون دپارتمانی سخت‌گیرانه برای تگ‌ها وجود ندارد.
- ماژول `archive_autotag` برای اعمال ایزولاسیون سازمانی، جداول `oc_archive_tag_ownership` و `oc_archive_tag_groups` را اضافه کرده است. عدم هماهنگی تراکنشی میان حذف تگ سیستمی و حذف متادیتای ماژول منجر به شکست ایزولاسیون می‌شد.

---

## ۶. رویکردهای بررسی‌شده و دلایل رد گزینه‌های نامناسب
1. **رویکرد دو مرحله‌ای بدون تراکنش (Two-Phase without DB Transaction):** ابتدا حذف تگ سیستم و سپس حذف متادیتا -> رد شد: در صورت قطع ارتباط شبکه یا کرش پروسس بین دو دستور، رکوردها فانتوم یا یتیم می‌شدند.
2. **رویکرد Soft Delete با Flag بدون حذف سیستم‌تگ:** صرفاً غیرفعال کردن تگ -> رد شد: نیاز سازمان به حذف کامل تگ‌های موقت یا اشتباه بدون باقی‌ماندن در لیست‌های جستجوی نکست‌کلود.
3. **رویکرد برگزیده (Single ACID Transaction with Pessimistic Locking & Cascade Cleanup):** اجرای مستقیم دستورات SQL روی جداول درون تراکنش واحد با قفل `FOR UPDATE` و اعتبارسنجی قطعی انتساب‌ها.

---

## ۷. مدل داده و تغییرات پایگاه داده (Data Model & Schema Evolution)
- **مایگریشن:** `Version2300Date20260920000001.php`
- **تغییرات جدول `oc_archive_tag_ownership`:**
  - افزودن ستون `status` از نوع `VARCHAR(32)` با مقدار پیش‌فرض `'ACTIVE'`.
  - افزودن ایندکس `arch_tag_own_stat_idx` بر روی ستون `status`.

---

## ۸. ساختار کد و فایل‌های پیاده‌سازی
1. **[`GroupTagService.php`](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Service/GroupTagService.php):**
   - بازنویسی متد `deleteGroupTag` با تراکنش سراسری `$this->db->beginTransaction()`.
   - پیاده‌سازی متد `getTagUsageCount`.
   - پیاده‌سازی موتور بازآشتی `reconcileGroupTags`.
   - ارتقای متد `listGroupTags` برای بازگرداندن `file_count`, `usage_count` و `status`.
2. **[`GroupTagController.php`](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Controller/GroupTagController.php):**
   - هندل کردن استثناهای `TagInUseException` (کد ۴۰۹)، `TagNotFoundException` (کد ۴۰۴)، `SecurityPermissionException` (کد ۴۰۳) و `TagDeletionException` (کد ۵۰۰).
   - افزودن اندپوینت `POST /api/group-tags/reconcile`.
3. **[`TagOwnershipService.php`](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Service/TagOwnershipService.php):**
   - افزودن متدهای `getTagStatus` و `setTagStatus`.
4. **[`TagGovernanceCommand.php`](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Command/TagGovernanceCommand.php):**
   - افزودن آپشن `--force` به اکشن `delete`.
   - افزودن اکشن `reconcile-group <groupId>`.
5. **[`archive_portal.js`](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/js/archive_portal.js):**
   - تعبیه دکمه بازآشتی و مدال تایید هوشمند حذف اجباری اسناد.
6. **[`TagInUseException.php`](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Exception/TagInUseException.php) و [`TagDeletionException.php`](file:///home/alborz/enterprise-archive-system/apps/archive_autotag/lib/Exception/TagDeletionException.php)**

---

## ۹. هوک‌ها، ایونت‌ها و نقاط اتصال به هسته
- اتصال مستقیم به `oc_systemtag` و `oc_systemtag_object_mapping` از طریق Doctrine DBAL نکست‌کلود.
- سازگاری با ایونت‌های `ManagerEvent::EVENT_DELETE` در کلاس‌های لیسنر هسته.

---

## ۱۰. منطق گام‌به‌گام پردازش (Detailed Flow / Algorithm)
```
1. اعتبارسنجی اینکه کاربر جاری، Group Admin گروه هدف باشد (در غیر این صورت ۴۰۳ Forbidden).
2. آغاز تراکنش پایگاه داده: $this->db->beginTransaction().
3. دریافت قفل بدبینانه سطری: SELECT tag_id, status FROM oc_archive_tag_ownership WHERE tag_id = :id FOR UPDATE.
4. بررسی وجود تگ در oc_systemtag (در صورت عدم وجود: Rollback و صدور ۴۰۴).
5. بررسی تعلق تگ به گروه هدف (در صورت عدم تطابق: Rollback و صدور ۴۰۳).
6. شمارش فایل‌های منتسب به تگ در oc_systemtag_object_mapping:
   - اگر تعداد > ۰ و پرچم force معادل false باشد:
     Rollback، ثبت آدیت با وضعیت aborted، و صدور خطای ۴۰۹ Conflict.
7. به‌روزرسانی موقت وضعیت به DELETING.
8. حذف فیزیکی رکوردهای نگاشت فایل‌ها (oc_systemtag_object_mapping).
9. حذف فیزیکی رکوردهای سیستم‌تگ هسته (oc_systemtag و oc_systemtag_group).
10. حذف فیزیکی رکوردهای متادیتای آرشیو (oc_archive_tag_groups و oc_archive_tag_ownership).
11. قطعی کردن تراکنش: $this->db->commit().
12. ثبت لاگ آدیت با نتیجه success و تعداد فایل‌های تفکیک‌شده.
13. بازگرداندن پاسخ JSON استاندارد با کد ۲۰۰ OK.
```

---

## ۱۱. وابستگی‌ها و پیش‌نیازها
- پایگاه داده PostgreSQL نسخه ۱۶+ با پشتیبانی از تراکنش‌های سطح READ COMMITTED و `FOR UPDATE`.
- ماژول `archive_autotag` نسخه v2.1.3+.
- کلاس‌های هسته نکست‌کلود ۳۴.

---

## ۱۲. مدیریت خطا و سناریوهای استثنا (Failure Modes & Edge Cases)
| حالت خطا | پیامد | رفتار سیستم | کد پاسخ HTTP |
| :--- | :--- | :--- | :--- |
| تگ ناموجود | تلاش برای حذف ID نامعتبر | عدم تغییر داده، ثبت لاگ | 404 Not Found |
| گروه نامنطبق | مدیر گروه دیگر قصد حذف دارد | رول‌بک، ثبت آدیت failure | 403 Forbidden |
| تگ متصل به فایل | حذف بدون پرچم force | رول‌بک، ثبت آدیت aborted | 409 Conflict (TAG_IN_USE) |
| خطای دیتابیس / کرش | شکست در حین اجرای کوئری | رول‌بک ۱۰۰٪، ثبت failure | 500 Internal Server Error |
| حذف همزمان | ارسال همزمان ۲ درخواست حذف | قفل سطری، ۱ موفق و بقیه ۴۰۴ | 200 برای اولی، 404 برای بقیه |

---

## ۱۳. دسترسی‌ها، نقش‌ها و امنیت
- مدیران ارشد سیستم (`admin`) بدون داشتن نقش مدیر گروه در `group_admin` حق حذف تگ گروهی از طریق وب را ندارند (Zero-Bypass Principle).
- مدیر سیستم از طریق CLI (`occ archive:tag:gov`) دسترسی کامل برای بازآشتی و حذف اجباری دارد.
- کاربران عادی گروه به اندپوینت‌های حذف و بازآشتی دسترسی ندارند (۴۰۳).

---

## ۱۴. APIها و پروتکل‌ها
### ۱. حذف تگ گروهی
- **مسیر:** `POST /index.php/apps/archive_autotag/api/group-tags/delete`
- **ورودی:**
  ```json
  {
    "group_id": "SOC",
    "tag_id": 95,
    "force": true
  }
  ```
- **خروجی موفق (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Group tag deleted successfully",
    "tag_id": 95,
    "data": {
      "status": "success",
      "tag_id": 95,
      "tag_name": "[SOC] Security_Tag",
      "usage_detached": 3
    }
  }
  ```

### ۲. بازآشتی و خودترمیمی تگ‌ها
- **مسیر:** `POST /index.php/apps/archive_autotag/api/group-tags/reconcile`
- **ورودی:** `{"group_id": "SOC"}`
- **خروجی موفق (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Group tags reconciled successfully",
    "report": {
      "status": "success",
      "group_id": "SOC",
      "orphans_restored": [{"id": 59, "name": "[SOC] Restored_Tag"}],
      "ghosts_purged": [],
      "dangling_mappings_pruned": 0
    }
  }
  ```

---

## ۱۵. تنظیمات و متغیرهای پیکربندی
- تنظیمات پایگاه داده در `config.php` نکست‌کلود.
- هیچ متغیر محیطی جدید یا وابستگی خارجی اضافه‌ای مورد نیاز نیست.

---

## ۱۶. عملکرد و مقیاس‌پذیری
- استفاده از `FOR UPDATE` با کمترین زمان نگهداری قفل (چند میلی‌ثانیه).
- کوئری‌های حذف با استفاده از کلید اصلی `PRIMARY KEY (id)` و ایندکس‌های یکتا انجام می‌شوند.

---

## ۱۷. قابلیت مشاهده‌پذیری، لاگ‌ها و آدیت
جدول `oc_archive_tag_audit` با فیلدهای زیر عملیات را ثبت می‌کند:
- `actor_uid`: کاربر اجراکننده
- `group_id`: گروه هدف
- `action`: `delete_tag` یا `reconcile_tag`
- `result`: `success`, `failure`, `aborted`
- `details`: شامل جزئیات خطا یا تعداد فایل‌های تفکیک‌شده

---

## ۱۸. سناریوهای تست و اعتبارسنجی
مجموعه تست خودکار [`tests/test_atomic_group_tag_deletion.py`](file:///home/alborz/enterprise-archive-system/tests/test_atomic_group_tag_deletion.py) با ۱۱ سناریو:
1. حذف اتمیک موفق و پاکسازی تمام جداول
2. رد درخواست برای تگ ناموجود (۴۰۴)
3. ممانعت از حذف تگ گروه دیگر (۴۰۳)
4. رد حذف تگ در حال استفاده بدون force (۴۰۹)
5. حذف موفق تگ در حال استفاده با force (۲۰۰)
6. بازگشت ۱۰۰٪ تراکنش (Rollback) در زمان خطای شبیه‌سازی‌شده سیستم (۵۰۰)
7. تضمین تراکنش در خطای دیتابیس
8. رفتار idempotent در حذف مکرر تگ
9. امنیت در حذف همزمان (Concurrency Safety)
10. کشف و ترمیم تگ‌های یتیم و فانتوم توسط موتور بازآشتی
11. تطابق عملکردی دستورات CLI

---

## ۱۹. بدهی فنی و محدودیت‌های شناخته‌شده
- در صورتی که تعداد فایل‌های منتسب به یک تگ بیش از ۱۰،۰۰۰ سند باشد، حذف اجباری ممکن است چند ثانیه زمان ببرد که در نسخه‌های آتی به صف پس‌زمینه (Background Job) منتقل خواهد شد.

---

## ۲۰. تحلیل اثر بر سایر نیازمندی‌ها
- **نیازمندی ۱۵ (Group Admin Tag Governance):** یکپارچگی کامل؛ تست‌های نیازمندی ۱۵ بدون کوچکترین رگرسیون با نرخ ۱۰۰٪ پاس شدند.
- **نیازمندی ۱۹ (File Ownership & Effective ACL):** هماهنگی کامل لاگ‌ها و اعتبارسنجی‌ها.

---

## ۲۱. چک‌لیست استقرار، بکاپ و ریکاوری
- [x] اجرای مایگریشن `Version2300Date20260920000001.php`
- [x] ارتقای نسخه ماژول به **v2.1.3**
- [x] بازنشانی کش آپاچی (`apache2ctl graceful`)
- [x] پشتیبان‌گیری منظم از جدول `oc_archive_tag_audit`

---

## ۲۲. ارتباط با سایر اسناد
- [نیازمندی ۱۵: حاکمیت تگ‌های گروهی](file:///home/alborz/enterprise-archive-system/docs/requirements/15_group_admin_tag_governance.md)
- [نیازمندی ۱۹: بازطراحی مالکیت فایل و مجوز موثر](file:///home/alborz/enterprise-archive-system/docs/requirements/19_file_ownership_redesign_and_effective_acl.md)
- [ایندکس نیازمندی‌ها](file:///home/alborz/enterprise-archive-system/docs/requirements/README.md)

---

## ۲۳. وضعیت نهایی (Final Implementation Status)
- **وضعیت:** تکمیل‌شده، مستقر و اعتبارسنجی‌شده (Deployed & Verified).
- **پوشش تست:** ۱۱ از ۱۱ تست آزمون جدید پاس شدند (۱۰۰٪)، و تمام تست‌های قبلی با نرخ ۱۰۰٪ پایدار هستند.
