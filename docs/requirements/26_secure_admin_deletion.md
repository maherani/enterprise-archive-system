# Requirement 26 — Secure System Administrator File & Folder Deletion

## ۱. هدف و دامنه نیازمندی (Goal & Scope)

در سامانه بایگانی اسناد سازمانی (Enterprise Archive System)، حذف منابع (فایل‌ها و پوشه‌ها) یکی از حساس‌ترین عملیات‌های حاکمیتی محسوب می‌شود. هدف این نیازمندی، فعال‌سازی کامل، امن، قابل ممیزی و یکپارچه قابلیت حذف دائم فایل‌ها و پوشه‌ها منحصراً برای مدیران ارشد سیستم (System Administrators) با رعایت اصول زیر است:

1. **انحصار به مدیران ارشد:** فقط کاربران دارای نقش System Admin در Nextcloud مجاز به حذف منابع هستند. کاربران عادی و مدیران گروه به هیچ عنوان امکان حذف ندارند (Strict 403 Forbidden).
2. **استفاده از مدل مجوزهای موجود:** عملیات `DELETE = 8` از کلاس `PermissionOperation` و تصمیم‌گیری از طریق `CentralPermissionResolver`.
3. **گیت‌های Fail-Closed در سطح Storage:** بازنویسی متدهای `unlink` و `rmdir` در `ArchiveFileIsolationWrapper`.
4. **محافظت از ریشه بایگانی:** ممانعت قطعی از حذف پوشه ریشه `/` یا `Enterprise_Archive`.
5. **پاکسازی آبشاری (Cascading Cleanup):** حذف خودکار متادیتای سند (`archive_document_metadata`)، مالکیت فایل (`archive_file_ownership`)، گرنت‌ها (`archive_file_grants`) و اشتراک‌های گروهی (`oc_share`). در صورت حذف پوشه، تمامی اسناد فرزند نیز به صورت بازگشتی پاکسازی می‌شوند.
6. **ممیزی پایدار (Reliable Audit Subsystem):** ثبت تراکنشی رویدادهای `FILE_DELETE` و `FOLDER_DELETE` با سطح دسترسی ۸ در جدول `oc_archive_permission_audit`.
7. **رابط کاربری ادمین:** نمایش دکمه حذف (`🗑️`) در جدول، کارت‌ها و دراور همراه با مودال تاییدیه مدرن (Obsidian Confirmation Modal).

---

## ۲. تحلیل ریشه‌ای مشکلات قبلی (Root Cause Analysis)

عدم امکان حذف منبع در وضعیت قبلی سامانه ناشی از موارد زیر بود:
- **UI:** عدم وجود هرگونه دکمه یا اکشن حذف در نماهای جدول، شبکه‌ای و دراور.
- **Frontend:** عدم وجود توابع جاوااسکریپت و مودال تاییدیه حذف در `archive_portal.js`.
- **API & Routing:** عدم تعریف Routeهای مربوط به حذف در `routes.php` و نبود کنترلر اختصاصی.
- **Storage Wrapper:** عدم بازنویسی `unlink` و `rmdir` در رپر ایزولاسیون فایل.
- **Cleanup:** عدم پاکسازی خودکار جداول متادیتا و گرنت‌ها در زمان حذف فیزیکی.

---

## ۳. معماری فنی پیاده‌سازی (Technical Architecture)

### ۳.۱. مدل مجوزها و کنترل دسترسی
```php
$isAdmin = ($userId === 'admin' || $this->groupManager->isAdmin($userId));
```
- در `CentralPermissionResolver::evaluateFile` و `evaluateFolder`، مدیران ارشد به صورت سراسری دارای `PermissionOperation::ALL` (شامل بیت `DELETE = 8`) هستند.
- تلاش هر کاربر غیرادمین بلافاصله رد شده و در لاگ ممیزی ثبت می‌گردد.

### ۳.۲. سرویس متمرکز `ArchiveDeletionService`
- متد `deleteResource(int $fileId, ?string $folderPath, string $actorUid, ...)`
- اعتبارسنجی ریشه و گارد امنیتی:
```php
if ($cleanPath === '' || strcasecmp($cleanPath, 'Enterprise_Archive') === 0 || str_ends_with(strtolower($cleanPath), '/enterprise_archive')) {
    throw new \InvalidArgumentException("عملیات غیرمجاز: ریشه بایگانی سازمانی قابل حذف نمی‌باشد.");
}
```
- اجرای تراکنشی پاکسازی آبشاری و ثبت ممیزی:
```php
$action = $isFolder ? 'FOLDER_DELETE' : 'FILE_DELETE';
$this->auditService->recordRequired('archive_permission_audit', [
    'request_id' => $reqId,
    'actor_uid' => $actorUid,
    'file_id' => $effectiveFileId,
    'grantee_type' => $isFolder ? 'folder' : 'file',
    'grantee_id' => $nodeName,
    'action' => $action,
    'permissions' => PermissionOperation::DELETE, // 8
    'result' => 'success',
    ...
], $this->db);
```

### ۳.۳. اندپوینت API و کنترلر `ArchiveResourceController`
- `POST /api/resource/delete` (در هر دو بستر `/index.php/apps/archive_autotag/api/resource/delete` و OCS).
- پاسخ‌دهی استاندارد JSON با کدهای وضعیت HTTP (401، 403، 400، 200).

### ۳.۴. فرانت‌اند و مودال تایید
- دکمه‌های `.ea-table-delete`، `.ea-card-delete` و `#ea-drawer-delete-btn` فقط برای کاربران با `is_admin: true` رندر می‌شوند.
- باز شدن `#ea-delete-confirm-modal` با هشدار صریح غیرقابل بازگشت بودن عملیات و امکان انصراف یا تایید نهایی.

---

## ۴. فایل‌های تغییریافته و ایجادشده

| نوع تغییر | مسیر فایل | توضیحات |
| :--- | :--- | :--- |
| **NEW** | `lib/Service/ArchiveDeletionService.php` | سرویس اصلی حذف امن، اعمال گارد و پاکسازی آبشاری |
| **NEW** | `lib/Controller/ArchiveResourceController.php` | کنترلر دریافت درخواست‌های حذف REST |
| **MODIFY** | `lib/Service/FileOwnershipService.php` | افزودن `deleteFileOwner` و `purgeAllGrants` |
| **MODIFY** | `lib/Storage/ArchiveFileIsolationWrapper.php` | بازنویسی `unlink` و `rmdir` با گیت `DELETE` |
| **MODIFY** | `appinfo/routes.php` | ثبت روت‌های `ArchiveResource#delete` |
| **MODIFY** | `js/archive_portal.js` | اکشن‌های حذف در جدول/کارت/دراور و مودال تایید |
| **MODIFY** | `css/archive_portal.css` | استایل‌های بصری دکمه‌ها و مودال حذف |
| **NEW** | `tests/test_secure_deletion.py` | تست‌های خودکار امنیت، ممیزی و پاکسازی بک‌اند |
| **NEW** | `tests/e2e/test_12_secure_admin_deletion.py` | آزمون‌های مرورگر واقعی Playwright E2E |

---

## ۵. نتایج اعتبارسنجی (Verification Results)

1. **تست‌های بک‌اند (`test_secure_deletion.py`):**
   - ۶ سناریوی جامع شامل ۴۰۱، ۴۰۳، ۴۰۰، ۲۰۰، پاکسازی آبشاری و لاگ ممیزی -> **۱۰۰٪ PASSED**.
2. **تست‌های مرورگر واقعی Playwright (`test_12_secure_admin_deletion.py`):**
   - عدم نمایش دکمه برای کاربران غیرادمین، نمایش برای ادمین، دیالوگ تایید/انصراف و حذف قطعی -> **۱۰۰٪ PASSED**.
