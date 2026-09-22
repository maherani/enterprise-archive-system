# Enterprise Archive System — Remaining Work Master Plan

این سند بر اساس Requirementهای Canonical شماره 01 تا 28، `PROJECT_STATE.md` و Promptهای تاریخی ادغام‌شده تهیه شده است.

## اصل حاکم

Requirementهای Completed نباید دوباره‌سازی شوند. ابتدا implementation، test و evidence بررسی شود؛ فقط در صورت مشاهده Gap اصلاح انجام شود.

---

## 1. وضعیت کلان

| Requirements | وضعیت ثبت‌شده | اقدام بعدی |
|---|---|---|
| 01–11 | Completed | Verification / Drift check |
| 12 | Roadmap مصوب | فعلاً فقط prerequisite verification |
| 13–15 | Completed | Security Regression |
| 16–22 | Completed / Hardened | Regression / Verification |
| 23 | Completed | **Hardening واقعی Test Infrastructure لازم است** |
| 24–28 | Completed | Verification و رفع Gap |

نتیجه: مرحله بعدی پروژه **Hardening + Verification + Documentation Drift** است، نه اضافه‌کردن بی‌هدف قابلیت جدید.

---

# 2. دسته A — Test Infrastructure و Credential Hygiene

**مرجع: Requirement 23**

### A1 — حذف Credentialهای Hard-coded
در `tests/e2e/config.py` username/password مستقیماً در Source قرار گرفته‌اند.

الزام:
- Credential از Source خارج شود.
- از Environment یا configuration محلی خارج از Git تأمین شود.
- نبود Credential باعث Failure واضح شود.
- default ناامن برای secret مجاز نباشد.
- Secret در stdout، exception، screenshot، trace یا artifact افشا نشود.

### A2 — File Factory
در `tests/e2e/fixtures/file_factory.py` تابع `cleanup_remote_test_folder()` credential پیش‌فرض دارد. باید از configuration مرکزی استفاده کند و نتیجه HTTP cleanup را بررسی کند.

### A3 — Repository-wide Secret Review
تمام Test/Helperها برای password، Bearer Token، Basic Auth، DB credential، AI token، application token و credential داخل URL/artifact بررسی شوند.

### A4 — StorageState
در `tests/e2e/fixtures/users.py` StorageStateها در `/tmp/e2e_storage_states` هستند. باید stale state، invalidation، cleanup و عدم افشای session بررسی شود.

**پذیرش:** هیچ credential واقعی در Source نباشد؛ تست بدون credential با خطای واضح متوقف شود؛ secret در artifact افشا نشود؛ E2E قابل پیکربندی روی محیط دیگر باشد.

---

# 3. دسته B — Test Isolation و Cleanup

**مرجع: Requirement 23**

هر Test mutating باید resourceهایی را که خودش ایجاد می‌کند track و حتی در Failure تا حد امکان cleanup کند.

مشمول:
- File
- Folder
- Tag
- Share
- Metadata
- Folder Request
- Database state

الزامات:
- نام resourceها collision-resistant باشد.
- Test هرگز resource دیگران را حذف نکند.
- `cleanup_remote_test_folder()` نتیجه HTTP را بررسی کند و Failure را silently swallow نکند.
- `FileFactory.cleanup()` قابل اتکا باشد.
- Failure pathها نیز cleanup داشته باشند.
- leftover state با diagnostic مشخص گزارش شود.

---

# 4. دسته C — کیفیت Assertionهای E2E

**مرجع: Requirement 23 و قابلیت‌های 16، 24، 25، 27، 28**

در `tests/e2e/test_03_upload_lifecycle.py` عبارت زیر وجود دارد:

`any(unique_filename in name for name in names) or len(names) >= 0`

شرط دوم همیشه True است و موفقیت Upload را اثبات نمی‌کند.

باید Assertion واقعاً وجود فایل، مسیر صحیح، metadata و tag مورد انتظار را اثبات کند.

کل E2E suite برای assertionهای همیشه-True، شرط‌های بی‌اثر و Testهایی که فقط status code را موفقیت تلقی می‌کنند بازبینی شود.

---

# 5. دسته D — Failure Diagnostics و Artifact Security

**مرجع: Requirement 23**

در `tests/e2e/helpers/failure_reporter.py` screenshot و Playwright trace وجود دارد.

بررسی شود artifact شامل Cookie، Authorization Header، Password، Token، URL حساس یا داده محرمانه سند نباشد.

همچنین:
- artifact وارد Git نشود.
- retention/cleanup مشخص باشد.
- StorageState و secret fileها وارد artifact نشوند.
- tracing lifecycle صحیح باشد.

**Gap مشخص:** در `tests/e2e/test_10_mandatory_metadata_upload.py`، `capture_failure(...)` در بخش `except` به شکل context manager استفاده نشده است؛ Failure Reporter باید واقعاً در Failure اجرا شود.

---

# 6. دسته E — Navigation و UI Hardening

**مرجع: Requirement 16**

Promptهای 09، 10، 12 و 13 قبلاً در Requirement 16 ادغام شده‌اند.

با Browser واقعی بررسی شود:
- `javascript:void(0)` وجود نداشته باشد.
- Navigation واقعی با Link/Href یا Router استاندارد باشد.
- Action غیر-navigation با `button` باشد.
- inline JavaScript در href وجود نداشته باشد.
- Tab/Enter/Space کار کند.
- `setInterval` دائمی برای Navigation وجود نداشته باشد.
- event listener/observer تکراری و memory leak وجود نداشته باشد.
- mount در Files App layout را خراب نکند.
- route change و SPA lifecycle پایدار باشد.
- access-aware navigation resource leakage ایجاد نکند.

---

# 7. دسته F — Security Regression و ACL

**مرجع: Requirements 08، 17، 18، 19، 20، 21، 22، 24، 25، 26، 28**

این Requirements در PROJECT_STATE Completed/Hardened هستند؛ کار فعلی Regression و اثبات دوباره است.

### F1 — CentralPermissionResolver
تمام مسیرهای حساس باید از Resolver مرکزی استفاده کنند:
READ=1، WRITE=2، CREATE=4، DELETE=8، SHARE=16، MANAGE=32، READ_METADATA=64، TAG_ASSIGN=128.

هیچ authorization موازی و متناقض باقی نماند.

### F2 — Fail-Closed Storage
Regression برای unknown path، stale cache، unindexed file، rename، nested upload، WebDAV مستقیم، AI retrieval و group-shared resource.

### F3 — Explicit Revocation
`permissions = 0` باید inherited/owner/group access را واقعاً قطع کند.

### F4 — Group Sharing
ترکیب‌های Read/Update/Create/Delete/Share/All تست شوند و Read-only share نتواند Upload کند.

### F5 — Tag Isolation
Group A نباید tagهای Group B را ببیند یا مدیریت کند. Group Admin نباید System/Admin Tag را حذف کند.

### F6 — Secure Deletion
حذف Admin باید resource و stateهای وابسته را صحیح پاک کند و Audit ثبت شود؛ resource خارج از scope حذف نشود.

### F7 — Central Tag Management
Create، Assign، Remove، 409 در Tag-in-use، Force و Reconcile باید با ACL و Audit سازگار باشند.

---

# 8. دسته G — Audit و AI Audit

**مرجع: Requirements 13، 21، 22**

### G1 — Audit Required
برای Folder approval/rejection، Permission grant/revoke، Tag create/delete، AI retrieval و Secure deletion:

**Audit Failure → Operation Failure**

### G2 — DLQ
بررسی شود event به DLQ می‌رود، replay ممکن است، duplicate ایجاد نمی‌شود و corruption قابل تشخیص است.

### G3 — AI Audit lifecycle
AUTHORIZED/PENDING/STREAMING/COMPLETED/ABORTED/FAILED/STREAM_FAILED باید با واقعیت stream منطبق باشند.

### G4 — Byte Accounting
`bytes_requested` و `bytes_served` جدا و دقیق ثبت شوند، حتی در قطع ارتباط client.

---

# 9. دسته H — Infrastructure، Backup و Air-Gap

**مرجع: Requirements 01، 05، 14**

### H1 — Infrastructure
واقعاً بررسی شود:
- proxy/app/db
- internal network
- عدم expose PostgreSQL
- 10GB upload
- proxy buffering
- timeout
- restart policy
- trusted domains

### H2 — Backup/Restore
چرخه واقعی اجرا و evidence ثبت شود:

`Backup → SHA-256 Integrity Check → Restore → Health Check`

Database، files، config و custom apps باید پوشش داده شوند.

### H3 — Air-Gap
بررسی شود:
- external service call وجود ندارد.
- CDN dependency وجود ندارد.
- Federation/Telemetry طبق سیاست بسته است.
- Help/App Store routeهای ممنوع بسته‌اند.
- public sharing طبق سیاست کنترل شده است.
- CSP با UI سفارشی سازگار است.

---

# 10. دسته I — AI Roadmap

**مرجع: Requirement 12**

Requirement 12 فعلاً **Roadmap مصوب** است، نه قابلیت عملیاتی جدید.

فعلاً دوباره‌سازی نشود. فقط prerequisiteهای آن با 01، 02، 08 و 13 بررسی شوند و Architecture document با implementation فعلی متناقض نباشد.

---

# 11. دسته J — Documentation Governance

مرجع: `docs/requirements/README.md`، `PROJECT_STATE.md` و `README.md`.

برای هر وضعیت Completed باید Evidence وجود داشته باشد:
- test
- code
- runtime verification
- deployment verification

عبارت‌هایی مثل `100% verified` یا `production stable` بدون Evidence تازه نباید اضافه شوند.

همچنین:
- نسخه Requirement، App و Test هماهنگ باشد.
- نام فایل و Test با Requirement فعلی هماهنگ باشد.
- لینک‌های machine-specific در صورت نیاز repository-relative شوند.
- Prompt جدید برای Requirement موجود ساخته نشود.
- حذف Prompt تاریخی به معنی حذف Requirement نیست.

---

# 12. ترتیب اجرای Antigravity

1. **Phase A — Test Security:** A1–A4
2. **Phase B — Test Reliability:** B1–B5
3. **Phase C — Navigation:** E1–E7
4. **Phase D — Security Regression:** F1–F7
5. **Phase E — Audit:** G1–G4
6. **Phase F — Infrastructure:** H1–H3
7. **Phase G — Documentation:** J1–J4

پس از هر Phase:

`Implement → Test → Inspect Diff → Run Regression → Report Evidence`

---

# 13. ممنوعیت‌ها

Antigravity نباید:
- Completed feature را بدون دلیل بازنویسی کند.
- برای سبز شدن Test، Test یا Assertion را حذف/ضعیف کند.
- Credential جدید را hard-code کند.
- Security Boundary را برای عبور Test دور بزند.
- Audit را برای عبور عملیات حذف کند.
- CentralPermissionResolver را bypass کند.
- Prompt تکراری جدید ایجاد کند.
- بدون Evidence وضعیت را Completed اعلام کند.

---

# 14. Definition of Done

- [ ] Credential hard-coded در E2E حذف شده.
- [ ] Test runner portable است.
- [ ] StorageState امن و قابل مدیریت است.
- [ ] Mutating tests cleanup قابل اتکا دارند.
- [ ] Assertionهای ضعیف حذف شده‌اند.
- [ ] Failure diagnostics امن است.
- [ ] Navigation semantic و lifecycle-safe است.
- [ ] polling غیرضروری حذف شده است.
- [ ] Security Regression Matrix پاس شده است.
- [ ] Audit Required واقعاً Fail-Closed است.
- [ ] AI Audit byte accounting دقیق است.
- [ ] Backup/Restore واقعاً End-to-End تست شده است.
- [ ] Air-Gap واقعاً verification شده است.
- [ ] Requirement 12 فقط Roadmap باقی مانده مگر اینکه Phase AI رسماً آغاز شود.
- [ ] Documentation فقط وضعیت دارای Evidence را اعلام می‌کند.
- [ ] Master E2E suite در محیط واقعی فعلی اجرا و نتیجه ثبت شده است.

---

# 15. گزارش الزامی Antigravity

در پایان هر Phase این جدول تکمیل شود:

| ID | کار | Requirement | فایل‌های تغییرکرده | Test | Result | Evidence |
|---|---|---|---|---|---|---|
| A1 | Credential Hygiene | 23 | ... | ... | PASS/FAIL | ... |

و در انتها:
- فهرست تغییرات
- تست‌های اجراشده
- PASS/FAIL
- Failureهای باقی‌مانده
- ریسک‌های باقی‌مانده
- مواردی که به علت Environment قابل Verification نیستند

**هیچ موردی فقط با عبارت Completed پذیرفته نیست؛ باید Evidence ارائه شود.**
