# مستندات پیکربندی DNS تفکیک‌شده (Split DNS) — سرور اصلی Production

**سامانه بایگانی اسناد سازمانی — سرور `lin-documentmgmt-216`**
تاریخ آخرین بازبینی: ۱۴۰۳/۰۷/۲۲

---

> [!IMPORTANT]
> این سند وضعیت مشاهده‌شده را در زمان اعمال تغییرات DNS مستند می‌کند.
> برخی موارد هنوز **تأییدنشده** (`UNVERIFIED`) هستند و باید در اولین فرصت پس از Reboot سرور اصلی آزمایش شوند.
> هیچ‌یک از دستورات تغییردهنده این سند نباید بدون بررسی وضعیت فعلی سرور اجرا شوند.

---

> [!CAUTION]
> **محرمانگی:** این سند حاوی اشاره به آدرس‌های IP زیرساخت داخلی سازمان است.
> اگر این مخزن به‌صورت عمومی منتشر شود، مقادیر واقعی IP باید با Placeholder جایگزین شوند.
> نسخه کامل با مقادیر واقعی باید تنها در سیستم مدیریت اسناد محدوددسترسی سازمان نگهداری شود.

---

## ۱. هدف این تنظیم

سرور اصلی Production (`lin-documentmgmt-216`) باید هم‌زمان دو مسیر DNS مجزا را پشتیبانی کند:

| نوع درخواست | مثال | DNS پاسخ‌دهنده |
|---|---|---|
| دامنه داخلی سازمان (`maskan.*`) | `docs.maskan` | DNS داخلی سازمان |
| دامنه‌های عمومی اینترنتی | `ubuntu.com`, `github.com` | DNS عمومی |

هدف: اطمینان از اینکه نام‌های داخلی سازمان (مانند `docs.maskan`) تنها از طریق سرورهای DNS داخلی resolve می‌شوند، در حالی که درخواست‌های اینترنتی عادی از مسیر DNS عمومی پردازش می‌شوند.

این الگو در `systemd-resolved` با مفهوم **Split DNS** یا **Domain-Scoped Routing** پیاده‌سازی می‌شود.

---

## ۲. مشخصات سرور و شبکه

| پارامتر | مقدار |
|---|---|
| نام سرور | `lin-documentmgmt-216` |
| سیستم‌عامل | Ubuntu Server |
| رابط شبکه | `ens192` |
| IP سرور | `[INTERNAL — نگهداری در سند محدوددسترسی]` |
| Gateway | `[INTERNAL — نگهداری در سند محدوددسترسی]` |
| مسیر پروژه روی سرور اصلی | `/opt/enterprise-archive-system` |
| مسیر پروژه محلی توسعه | `/home/alborz/enterprise-archive-system` |

> [!WARNING]
> این دو مسیر متعلق به دو محیط متفاوتند و نباید با یکدیگر اشتباه گرفته شوند.

---

## ۳. معماری DNS

```
+------------------------------------------------------------------+
|              سرور lin-documentmgmt-216                           |
|                                                                  |
|  +------------------------------------------------------------+  |
|  |         systemd-resolved (stub resolver)                   |  |
|  |                127.0.0.53                                  |  |
|  |                                                            |  |
|  | /etc/resolv.conf --> stub-resolv.conf                      |  |
|  |                                                            |  |
|  | Rule: *.maskan                                             |  |
|  |   --> DNS داخلی سازمان (Global DNS)                       |  |
|  |       [IP_INTERNAL_DNS_1] (اول)                           |  |
|  |       [IP_INTERNAL_DNS_2] (دوم)                           |  |
|  |                                                            |  |
|  | Rule: سایر درخواست‌ها                                      |  |
|  |   --> DNS عمومی (روی ens192)                              |  |
|  |       178.22.122.101                                       |  |
|  +------------------------------------------------------------+  |
+------------------------------------------------------------------+
```

### ۳.۱. DNS داخلی سازمان

- **DNS اول و دوم:** محرمانه — در سند عملیاتی محدوددسترسی سازمان نگهداری می‌شود
- **دامنه مسیریابی:** `~maskan`
- **روش تنظیم:** از طریق `systemd-resolved` سراسری (Global configuration)

نقش `Domains=~maskan`: در `systemd-resolved`، پیشوند `~` نشانه **Routing Domain** است. یعنی DNS داخلی سازمانی **فقط** برای درخواست‌هایی که به دامنه `maskan` تعلق دارند استفاده می‌شود و نقش DNS پیش‌فرض عمومی ندارد.

### ۳.۲. DNS عمومی

- **آدرس:** `178.22.122.101`
- **روش تنظیم:** روی رابط `ens192` از طریق Netplan
- **هدف:** تأمین resolve برای دامنه‌های اینترنتی

### ۳.۳. تفاوت تنظیمات سراسری و رابط شبکه

| پارامتر | سراسری (Global) | رابط ens192 |
|---|---|---|
| محل تنظیم | `/etc/systemd/resolved.conf.d/10-internal-maskan.conf` | `/etc/netplan/50-cloud-init.yaml` |
| DNS | داخلی سازمان | `178.22.122.101` |
| دامنه | `~maskan` (routing فقط) | همه دامنه‌ها (fallback) |
| مدیریت | `systemd-resolved` | Netplan / `systemd-networkd` |

---

## ۴. فایل‌های پیکربندی مرتبط

### ۴.۱. فایل اصلی Split DNS

**مسیر:** `/etc/systemd/resolved.conf.d/10-internal-maskan.conf`

محتوای مورد انتظار (بر اساس آنچه در زمان اعمال تنظیمات گزارش شده):

```ini
[Resolve]
DNS=[IP_INTERNAL_DNS_1] [IP_INTERNAL_DNS_2]
Domains=~maskan
```

برای بازبینی فعلی: `cat /etc/systemd/resolved.conf.d/10-internal-maskan.conf`

### ۴.۲. فایل Netplan (رابط شبکه)

**مسیر:** `/etc/netplan/50-cloud-init.yaml`

وضعیت گزارش‌شده: رابط `ens192` از IP ثابت، Gateway مشخص‌شده و DNS `178.22.122.101` استفاده می‌کند.

> [!WARNING]
> محتوای دقیق این فایل از مشاهده مستقیم تأیید نشده است (`UNVERIFIED`).
> پیش از هر تغییر، ابتدا با `cat /etc/netplan/50-cloud-init.yaml` وضعیت را بررسی کنید.

### ۴.۳. لینک نمادین `/etc/resolv.conf`

وضعیت گزارش‌شده: `/etc/resolv.conf --> /run/systemd/resolve/stub-resolv.conf`

در **stub mode**: درخواست‌های DNS به `127.0.0.53` ارسال می‌شوند و `systemd-resolved` بر اساس Routing Domains پاسخ می‌دهد.

برای تأیید: `readlink -f /etc/resolv.conf`

### ۴.۴. نسخه‌های پشتیبان

| فایل | هدف | وضعیت |
|---|---|---|
| `/etc/netplan/50-cloud-init.yaml.bak` | نسخه قبل از تغییرات DNS | وجود گزارش شده — محتوا `UNVERIFIED` |
| `/etc/resolv.conf.before-splitdns` | وضعیت قبل از تغییر به stub mode | وجود گزارش شده — محتوا `UNVERIFIED` |
| `/etc/resolv.conf.before-splitdns.contents` | محتوای تکمیلی پشتیبان | وجود گزارش شده — محتوا `UNVERIFIED` |

> [!CAUTION]
> وجود این فایل‌های پشتیبان به‌تنهایی تضمین نمی‌کند که بازیابی کامل تنظیمات با هر کدام از آن‌ها امکان‌پذیر است.
> پیش از هر عملیات بازیابی، محتوا و کامل‌بودن هر فایل را مستقیماً بررسی کنید.

---

## ۵. آزمون‌های انجام‌شده و نتایج مشاهده‌شده

جدول زیر نتایج آزمون‌هایی را که **در زمان اعمال تنظیمات DNS** گزارش شده‌اند نشان می‌دهد:

| آزمون | دستور | نتیجه گزارش‌شده | وضعیت |
|---|---|---|---|
| Resolve دامنه داخلی | `resolvectl query docs.maskan` | پاسخ موفق با IP سرور | PASS (در زمان آزمون) |
| Resolve دامنه عمومی | `resolvectl query ubuntu.com` | پاسخ موفق | PASS (در زمان آزمون) |
| وضعیت سرویس | `systemctl is-active systemd-resolved` | `active` | PASS (در زمان آزمون) |
| وضعیت DNS | `resolvectl status` | DNS داخلی در Global، DNS عمومی در `ens192` | PASS (در زمان آزمون) |
| پاسخ HTTP | `curl -I http://docs.maskan` | `HTTP/1.1 302 Found` به `/login` | PASS (در زمان آزمون) |
| دسترسی مرورگر | باز کردن `http://docs.maskan` | صفحه Login نمایش داده شد | PASS (در زمان آزمون) |

> [!IMPORTANT]
> **محدودیت‌های نتیجه‌گیری:**
> 1. نتایج وضعیت سرور را **در همان لحظه آزمون** نشان می‌دهند؛ پایداری پس از Reboot تأیید نشده است.
> 2. مسیر دقیق DNS که پاسخ `ubuntu.com` را تأمین کرده با شواهد موجود قابل اثبات قطعی نیست.
> 3. این سند گواه تأیید کامل Disaster Recovery نیست.

---

## ۶. آزمون‌های انجام‌نشده (نیازمند بررسی آینده)

| آزمون | علت انجام نشدن | اولویت |
|---|---|---|
| **ماندگاری پس از Reboot** | سرور در حال سرویس‌دهی بود | بالا — پیش از Reboot بعدی تأیید شود |
| **تأیید مسیر دقیق DNS برای دامنه‌های عمومی** | شواهد کافی برای اثبات قطعی مسیر وجود نداشت | متوسط |
| **عملکرد DNS در صورت Failover** | آزمون خرابی DNS داخلی و fallback | متوسط |
| **تأیید محتوای فایل‌های پشتیبان** | دسترسی به Production برای تأیید در این مرحله لازم نبود | بالا — پیش از عملیات بازیابی |

---

## ۷. روش‌های بررسی وضعیت (Read-Only)

### ۷.۱. بررسی وضعیت کلی DNS

```bash
resolvectl status
```

### ۷.۲. بررسی مقصد نهایی `/etc/resolv.conf`

```bash
readlink -f /etc/resolv.conf
# انتظار: /run/systemd/resolve/stub-resolv.conf
```

### ۷.۳. آزمون Resolve دامنه‌ها

```bash
resolvectl query docs.maskan
resolvectl query ubuntu.com
```

### ۷.۴. بررسی پاسخ HTTP سامانه

```bash
curl -I http://docs.maskan
# انتظار: HTTP/1.1 302 Found
```

### ۷.۵. بررسی محتوای فایل Split DNS

```bash
cat /etc/systemd/resolved.conf.d/10-internal-maskan.conf
```

### ۷.۶. بررسی وضعیت سرویس

```bash
systemctl is-active systemd-resolved
systemctl status systemd-resolved --no-pager
```

---

## ۸. نکات عیب‌یابی

| علائم | احتمال علت | اقدام اولیه |
|---|---|---|
| `docs.maskan` resolve نمی‌شود | DNS داخلی سازمان در دسترس نیست | `resolvectl status` — بررسی DNS داخلی در Global |
| `ubuntu.com` resolve نمی‌شود | DNS عمومی در دسترس نیست | `resolvectl status` — بررسی DNS روی `ens192` |
| هر دو resolve می‌شوند ولی سامانه پاسخ نمی‌دهد | مشکل در Docker، Nginx یا Nextcloud | `docker ps` و `systemctl status nginx` |
| `docs.maskan` پاسخ اشتباه می‌دهد | احتمال استفاده از DNS اشتباه | `resolvectl query docs.maskan` — بررسی IP پاسخ |

---

## ۹. ملاحظات نصب مجدد سرور

### ۹.۱. گام‌های بازسازی تنظیمات DNS

> [!WARNING]
> دستورات زیر تغییردهنده هستند. پیش از اجرا:
> 1. وضعیت فعلی سرور را بررسی کنید.
> 2. از فایل‌های موجود نسخه پشتیبان تهیه کنید.
> 3. اطمینان حاصل کنید که IPهای DNS داخلی سازمان در دسترس هستند.

```bash
# گام ۱: ایجاد دایرکتوری تنظیمات
sudo mkdir -p /etc/systemd/resolved.conf.d/

# گام ۲: ایجاد فایل Split DNS
# [IP_INTERNAL_DNS_1] و [IP_INTERNAL_DNS_2] را با مقادیر واقعی جایگزین کنید
sudo tee /etc/systemd/resolved.conf.d/10-internal-maskan.conf > /dev/null <<CONFEOF
[Resolve]
DNS=[IP_INTERNAL_DNS_1] [IP_INTERNAL_DNS_2]
Domains=~maskan
CONFEOF

# گام ۳: بررسی لینک resolv.conf — [نیازمند تأیید اپراتور]
# sudo ln -sf /run/systemd/resolve/stub-resolv.conf /etc/resolv.conf

# گام ۴: reload سرویس — [پس از تأیید وضعیت]
# sudo systemctl restart systemd-resolved
```

### ۹.۲. محدودیت‌های بازیابی از فایل‌های پشتیبان

- وجود فایل‌های پشتیبان به‌تنهایی کافی نیست.
- محتوا و کامل‌بودن هر فایل باید قبل از استفاده تأیید شود.
- ساختار Netplan باید با نسخه Ubuntu سرور جدید سازگار باشد.
- IP ثابت، Gateway و DNS باید با شبکه محیط جدید تطبیق داشته باشند.

---

## ۱۰. چک‌لیست بررسی پس از تغییرات آینده

- [ ] `readlink -f /etc/resolv.conf` — اشاره به `stub-resolv.conf`
- [ ] `resolvectl status` — DNS داخلی در Global با Routing Domain `~maskan`
- [ ] `resolvectl status` — DNS عمومی روی `ens192`
- [ ] `resolvectl query docs.maskan` — پاسخ صحیح
- [ ] `resolvectl query ubuntu.com` — پاسخ موفق
- [ ] `curl -I http://docs.maskan` — `302 Found` به `/login`
- [ ] سامانه از مرورگر در دسترس باشد
- [ ] **پس از Reboot:** تمام موارد بالا را تکرار کنید

---

## ۱۱. ارجاعات

- [docs/backup_and_recovery/DEPLOYMENT_RUNBOOK.md](../backup_and_recovery/DEPLOYMENT_RUNBOOK.md)
- [docs/requirements/01_infrastructure_and_containerization.md](../requirements/01_infrastructure_and_containerization.md)
- [docs/requirements/23_system_deployment_and_recovery_runbook.md](../requirements/23_system_deployment_and_recovery_runbook.md)

---

*این سند بر اساس شواهد مشاهده‌شده در زمان اعمال تنظیمات DNS تنظیم شده است.
موارد `UNVERIFIED` باید در اولین آزمون سرور پس از Reboot تکمیل شوند.*
