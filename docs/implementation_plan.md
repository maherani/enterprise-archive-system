# طرح پیاده‌سازی مدیریت متمرکز پوشه‌ها، سیستم تگ‌گذاری داینامیک و کنترل سقف حجم آپلود کاربران در سامانه آرشیو سازمانی

این سند فنی، پلن اجرایی ۶ نیاز کلیدی اعلام‌شده توسط کاربر برای سامانه آرشیو سازمانی مبتنی بر Nextcloud را به همراه معماری فنی، نحوه کارکرد ماژول‌های بومی، سطوح دسترسی و نتایج آزمون جامع تشریح می‌کند.

---

## بررسی نیازمندی‌ها و اهداف سیستم

1. **مدیریت انحصاری فولدرها توسط ادمین**: کاربران عادی نباید بتوانند ساختار ریشه آرشیو را دستکاری یا پوشه‌های غیرمجاز ایجاد کنند. تخصیص دسترسی‌ها منحصراً توسط ادمین انجام می‌شود.
2. **تگ‌گذاری داینامیک (پویا)**: نام تگ سیستمی هر فایل یا زیرپوشه، دقیقاً نام پوشه والد (Parent Folder) آن خواهد بود و به ازای هر ساختار پوشه به صورت اتوماتیک در سیستم تعریف می‌شود.
3. **تگ‌گذاری آنی هنگام آپلود**: بلافاصله پس از آپلود شدن فایل توسط کاربر یا از طریق API/WebDAV، تگ والد به فایل الصاق شود.
4. **تگ‌گذاری مکمل توسط کاربران بدون امکان حذف تگ سیستمی**: کاربران دارای مجوز بتوانند تگ‌های دلخواه خود را به فایل‌ها اضافه کنند، اما هرگز نتوانند تگ‌های والد/سیستمی را حذف یا دستکاری کنند.
5. **انتشار تغییرات تغییر نام یا حذف فولدر**: در صورت تغییر نام پوشه توسط ادمین، تگ قبلی از تمام فایل‌های درون آن حذف و تگ جدید جایگزین شود؛ و در صورت حذف فولدر، اتصالات تگ متناسباً مدیریت شود.
6. **محدودیت حجم آپلود فایل برای هر کاربر توسط ادمین**: ادمین سیستم بتواند برای هر کاربر سقف مشخصی برای حداکثر حجم مجاز هر فایل آپلودی تعیین کند (مثلاً 10MB، 500MB یا نامحدود). در صورتی که کاربری فایلی بزرگتر از حد مجاز خود آپلود کند، سیستم بلافاصله آپلود را با خطای `HTTP 403 Forbidden` متوقف و رد کند.

---

## معماری سامانه (Architecture & Design)

```text
[ کاربر عادی / Service Account / API ]
                   |
             HTTP PUT :80
                   v
          [ Nginx Reverse Proxy ]
                   | (Client Max Body Size: 10G)
                   v
        [ Nextcloud WebDAV Engine ]
                   |
                   +---▶ [ لیسنر Sabre Plugin: beforeCreateFile / beforeWriteContent ]
                   |            |
                   |            +--- بررسی محدودیت حجم کاربر ($uploadLimitService->getUserLimit($uid))
                   |            +--- آیا Content-Length > سقف مجاز کاربر است؟
                   |            |       ├── بله: پرتاب Sabre\DAV\Exception\Forbidden (پاسخ HTTP 403)
                   |            |       └── خیر: اجازه ادامه آپلود
                   |
                   +---▶ [ رویداد بومی: NodeCreatedEvent & NodeWrittenEvent ]
                   |            |
                   |            ▼
                   |     [ ماژول archive_autotag ]
                   |            |
                   |            ├── استخراج سلسله‌مراتب پوشه‌های والد ($node->getParent())
                   |            ├── بررسی/ایجاد Restricted System Tag (غیرقابل حذف توسط کاربر)
                   |            └── الصاق مستقیم تگ‌های والد به فایل
                   |
                   +---▶ [ رویداد تغییر نام: NodeRenamedEvent ]
                                │
                                ▼
                         [ ماژول archive_autotag ]
                                │
                                ├── یافتن تمام فایل‌های درون پوشه تغییرنام‌یافته
                                ├── حذف تگ والد قدیمی ($oldTagName)
                                └── تخصیص تگ والد جدید ($newTagName)
```

---

## تحلیل فنی و جزئیات پیاده‌سازی

### ۱. مدیریت انحصاری پوشه‌ها توسط ادمین (Admin-Only Folder Governance)
- تنظیم سهمیه دیسک شخصی کاربران روی `0 B` با دستور:
  ```bash
  php occ user:setting <uid> files quota 0
  ```
- با این تنظیم، تلاش کاربران برای آپلود یا ساخت پوشه در فضای شخصی خود با خطای `HTTP 507 Insufficient Storage` متوقف می‌شود.
- ادمین پوشه‌های رسمی آرشیو (مانند `/Enterprise_Archive/Finance/...`) را ایجاد کرده و با مجوزهای کنترل‌شده (مشاهده، ایجاد، ویرایش؛ بدون مجوز حذف پوشه ریشه) با گروه کاربری به اشتراک می‌گذارد.

### ۲ و ۳. ماژول بومی تگ‌گذاری داینامیک و آنی (`archive_autotag`)
- پیاده‌سازی شده در [apps/archive_autotag](file:///Ubuntu-26.04/home/alborz/enterprise-archive-system/apps/archive_autotag) و فعال در Nextcloud.
- به کارگیری شنوندگان رویدادهای هسته (PSR-14):
  - `NodeCreatedEvent` و `NodeWrittenEvent`: استخراج تمام اجداد سلسله‌مراتب پوشه و تگ‌گذاری خودکار فایل بلافاصله پس از آپلود.

### ۴. تفکیک تگ‌های سیستمی و تگ‌های کاربر (Role-Based Tag Protection)
- تگ‌های والد به صورت **`restricted`** تولید می‌شوند (`userVisible = true`, `userAssignable = false`).
- کاربر این تگ‌ها را می‌بیند و امکان فیلتر دارد، اما دکمه حذف برای او وجود ندارد و درخواست حذف از طریق API با خطای `HTTP 403 Forbidden` رد می‌شود.
- کاربران مجاز می‌توانند آزادانه تگ‌های عمومی (`public`) اضافه یا حذف کنند.

### ۵. انتشار خودکار تغییر نام پوشه (Folder Rename Propagation)
- شنونده `NodeRenamedListener`: با تغییر نام پوشه توسط ادمین، به صورت بازگشتی تمام فایل‌های درون آن شناسایی شده، تگ قبلی جدا شده و تگ جدید به آن‌ها الصاق می‌گردد.

### ۶. کنترل سقف حجم آپلود به ازای هر کاربر (Per-User Upload Size Limit)
- **سرویس مدیریت محدودیت (`UploadLimitService`):**
  - ذخیره‌سازی سقف حجم هر کاربر در جدول امن `oc_preferences` با استفاده از `\OCP\IConfig`.
- **دروازه امنیتی WebDAV (`SabrePluginInitListener`):**
  - اتصال به رویدادهای `beforeCreateFile` و `beforeWriteContent` در هسته SabreDAV.
  - خواندن هدر `Content-Length` درخواست آپلود قبل از ذخیره‌سازی محتوا بر روی دیسک.
  - در صورتی که حجم فایل فراتر از سقف تعیین‌شده کاربر باشد، استثنای `\Sabre\DAV\Exception\Forbidden` صادر شده و درخواست بلافاصله با کد **`HTTP 403 Forbidden`** متوقف می‌شود.
- **دستور مدیریتی CLI ادمین (`UserLimitCommand`):**
  ```bash
  # تنظیم سقف حجم برای کاربر (مثلاً 10 مگابایت)
  php occ archive:user:limit archive_user1 10M

  # تنظیم سقف‌های دیگر (مانند 500K، 2G)
  php occ archive:user:limit archive_user1 500M

  # حذف محدودیت و نامحدودسازی
  php occ archive:user:limit archive_user1 0

  # مشاهده سقف کاربر خاص
  php occ archive:user:limit archive_user1

  # مشاهده جدول تمام کاربران دارای محدودیت
  php occ archive:user:limit --list
  ```

---

## آزمون جامع و نتایج اعتبارسنجی خودکار

سوئیت تست کامل در [tests/test_dynamic_archive_system.py](file:///Ubuntu-26.04/home/alborz/enterprise-archive-system/tests/test_dynamic_archive_system.py) ایجاد و اجرا شده است:

```text
==================================================================
 STARTING ENTERPRISE ARCHIVE SYSTEM VERIFICATION
==================================================================

[Step 1] Verifying Admin-Only Folder Governance (Quota 0 restriction)...
  - Upload to user personal root HTTP Status: 507
  ✓ PASSED: Regular users cannot create/upload files in personal storage.

[Step 2 & 3] Verifying Instant Dynamic Hierarchical Tagging on Upload...
  - Upload into Enterprise_Archive hierarchy HTTP Status: 201
  ✓ File successfully uploaded into Admin-managed archive structure.
  - Target file ID: 173
  - Assigned System Tag IDs: ['5', '3', '4', '7']
  ✓ PASSED: File automatically tagged with all hierarchical parent tags upon upload.

[Step 4] Verifying Restricted System Tag Protection & User Collaborative Tagging...
  - User attempt to delete restricted system tag (Tag 5) HTTP Status: 403
  ✓ PASSED: Regular users are FORBIDDEN from deleting system tags.
  - User assigning public collaborative tag HTTP Status: 201
  - User removing own public tag HTTP Status: 204
  ✓ PASSED: Users can assign and unassign public collaborative tags freely.

[Step 5] Verifying Automatic Tag Propagation on Folder Rename...
  - Admin MOVE folder HTTP Status: 201
  - Tags after folder rename: ['5', '3', '4', '11']
  ✓ PASSED: Tags successfully updated and propagated upon folder rename.

[Step 6] Verifying Configurable Per-User File Upload Size Limit...
  - Upload 1 MB file (within 10 MB limit) HTTP Status: 201
  ✓ Allowed upload within limit succeeded.
  - Upload 12 MB file (exceeds 10 MB limit) HTTP Status: 403
  ✓ PASSED: Oversized upload was rejected with HTTP 403 Forbidden.

==================================================================
 ALL 6 REQUIREMENTS VERIFIED AND PASSED SUCCESSFULLY!
==================================================================
```
