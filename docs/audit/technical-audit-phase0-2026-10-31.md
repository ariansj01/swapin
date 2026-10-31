# گزارش فاز ۰ — ممیزی فنی و عملکرد (بدون تغییر کد)

- تاریخ: ۲۰۲۶-۱۰-۳۱
- محدوده این فاز: فقط خواندن و اندازه‌گیری. هیچ فایلی تغییر نکرده است.
- دامنه بررسی: کد اپلیکیشن + فایل‌های کانفیگ نسخه‌بندی‌شده (`ops/`, `.github/`).

---

## خلاصه اجرایی (مهم‌ترین یافته‌ها)

| # | یافته | شدت | مرجع |
|---|-------|-----|------|
| ۱ | N+1 کوئری در کارت آگهی (هر کارت ۲ تا ۳ کوئری اضافه) | بالا | includes/listing_card.php#L18,39,87 |
| ۲ | محدودیت درخواست OTP در `auth/login.php` کامنت شده و غیرفعال است | بالا | auth/login.php#L29-47 |
| ۳ | اندپوینت `/_opcache_reset.php` بدون احراز هویت و بدون هیچ rule مسدودکننده | بالا | _opcache_reset.php |
| ۴ | هیچ لایه کش (APCu/Redis/File) در کد وجود ندارد | بالا | جست‌وجوی سراسری |
| ۵ | `loading="lazy"` بدون `width/height` در کارت‌ها → CLS | متوسط | includes/listing_card.php#L96 |
| ۶ | فونت‌ها بدون `font-display: swap` و بدون preload | متوسط | src/fonts/iransans/fontiran.css |
| ۷ | فایل `error.txt` (۱۵۱KB) در روت و از وب قابل دسترسی | متوسط | error.txt |
| ۸ | اسکریپت‌های JS بدون `defer/async` در همه صفحات | متوسط | includes/layout.php#L703-705 |
| ۹ | کوئری `LIKE '%...%'` روی سه ستون در جست‌وجو | متوسط | index.php#L42-47 |
| ۱۰ | فایل‌های زائد در روت و تصاویر کش‌شده بی‌مصرف | پایین | روت پروژه |

---

## ۱. دیتابیس و کوئری‌ها

**وضعیت لایه اتصال (خوب):**
- کلاس `DB` به‌صورت Singleton با یک اتصال PDO در هر درخواست کار می‌کند: [config.php#L1432-L1445](file:///c:/xampp/htdocs/swaapin/includes/config.php#L1432-L1445)
- `ATTR_EMULATE_PREPARES => false` فعال است (Prepared Statement واقعی سمت MySQL) → محافظت خوب در برابر SQL Injection.
- `ATTR_PERSISTENT` تنظیم نشده و هر بار اتصال جدید ساخته می‌شود.

**مشکلات شناسایی‌شده:**
1. **N+1 در کارت آگهی** — در [listing_card.php](file:///c:/xampp/htdocs/swaapin/includes/listing_card.php#L18) برای هر کارت جداگانه کوئری `saved_listings` زده می‌شود، در [خط ۳۹](file:///c:/xampp/htdocs/swaapin/includes/listing_card.php#L39) اطلاعات کاربر صاحب آگهی و در [خط ۸۷](file:///c:/xampp/htdocs/swaapin/includes/listing_card.php#L87) تعداد تصاویر. اگر ۲۰ کارت در صفحه باشد ⇒ حدود ۴۰–۶۰ کوئری اضافه.
2. **صفحه اصلی** ~۶ کوئری مستقل دارد (count + لیست + ویژه + فروشگاه‌ها + دسته‌ها): [index.php#L116-L210](file:///c:/xampp/htdocs/swaapin/index.php#L116-L210)
3. **درخت دسته‌بندی در حلقه**: در [all.php#L340-L400](file:///c:/xampp/htdocs/swaapin/listings/all.php#L340-L400) برای ساخت سایدبار، کوئری‌های فرزند به‌صورت تودرتو اجرا می‌شوند.
4. **`COUNT(*)` جداگانه** برای صفحه‌بندی در [index.php#L116](file:///c:/xampp/htdocs/swaapin/index.php#L116) و [all.php#L126](file:///c:/xampp/htdocs/swaapin/listings/all.php#L126).
5. **جست‌وجوی wildcard ابتدایی**: `LIKE '%?%'` روی `title`, `description`, `want_in_return` → غیرقابل استفاده از ایندکس: [index.php#L42-L47](file:///c:/xampp/htdocs/swaapin/index.php#L42-L47) و [all.php#L55](file:///c:/xampp/htdocs/swaapin/listings/all.php#L55).
6. **صفحه‌بندی OFFSET-محور** (`LIMIT ? OFFSET ?`) → در صفحات عمیق کند می‌شود.
7. **هیچ کش کوئری وجود ندارد** — جست‌وجوی `apcu|memcache|redis|cache_get|cache_set` در `includes/` هیچ نتیجه معتبری نداشت.
8. **ایندکس‌ها**: با دسترسی read-only به DB در این محیط قابل راستی‌آزمایی نبود (`SHOW INDEX` اجرا نشد). نیاز به بررسی جداگانه روی سرور.

---

## ۲. فرانت‌اند و دارایی‌های استاتیک

**بارگذاری دارایی‌ها** ([layout.php](file:///c:/xampp/htdocs/swaapin/includes/layout.php)):
- CSS: `fonts.css`، `bootstrap-icons.css`، `main.css` ([#L195-197](file:///c:/xampp/htdocs/swaapin/includes/layout.php#L195-L197))
- JS: `app.js`، `pwa/platform-detector.js`، `pwa/pwa-core.js` ([#L703-705](file:///c:/xampp/htdocs/swaapin/includes/layout.php#L703-L705)) — **هیچ‌کدام `defer` یا `async` ندارند.**
- هیچ `preload` / `preconnect` برای فونت و GTM وجود ندارد.
- اسکریپت GTM به‌صورت inline در `<head>` و رندر-بلاک‌کننده است.

**فونت‌ها** ([fontiran.css](file:///c:/xampp/htdocs/swaapin/src/fonts/iransans/fontiran.css)):
- ۱۰ وزن × ۲ فرمت (woff/woff2) تعریف شده؛ `src/css/fonts.css` فقط `@import` می‌کند.
- **`font-display` تعریف نشده** → FOUT و ریسک CLS.

**تصاویر:**
- نکات مثبت: هیرو با `srcset` + WebP + eager/async در [index.php#L269-270](file:///c:/xampp/htdocs/swaapin/index.php#L269-L270).
- مشکل: کارت آگهی و صفحه آگهی `width/height` ندارند → CLS: [listing_card.php#L96](file:///c:/xampp/htdocs/swaapin/includes/listing_card.php#L96)، [listings/view.php#L399](file:///c:/xampp/htdocs/swaapin/listings/view.php#L399).

**PWA (اصلاح یافته قبلی):**
- سرویس‌ورکرها **موجود** هستند: [sw-core.js](file:///c:/xampp/htdocs/swaapin/sw-core.js)، `sw.js`، `sw-ios.js`، `sw-android.js`.
- نسخه کش: `1.0.2` و `PRECACHE_URLS` تعریف‌شده: [sw-core.js#L6-L27](file:///c:/xampp/htdocs/swaapin/sw-core.js#L6-L27)
- مسیرهای پویا مثل `/listings/map` از کش خارج هستند: [sw-core.js#L29-L45](file:///c:/xampp/htdocs/swaapin/sw-core.js#L29-L45)
- جای بهبود: کش runtime برای تصاویر آگهی تعریف نشده است.

---

## ۳. امنیت

**محافظت‌ها (خوب):**
- دایرکتوری‌های حساس بلاک شده‌اند: `tools/.htaccess` = `Require all denied`، همچنین `includes/`, `database/`, `storage/`, `seed/`.
- Nginx هم مسیرهای `(database|includes|storage|seed|tools|vendor|logs|backups|sql)` را deny می‌کند: [swaapin.ir.conf#L155-L157](file:///c:/xampp/htdocs/swaapin/ops/nginx/swaapin.ir.conf#L155-L157)
- API موبایل: Bearer Token + هش SHA256 + Rate Limit برای OTP.
- Store API: `X-Api-Key` با هش SHA256 و قابلیت revoke.
- Session: `HttpOnly`, `Secure`, `SameSite`, `use_strict_mode` فعال.
- CSRF: در `includes/security.php` با `random_bytes(32)` و `hash_equals` پیاده شده و در ۸۶ فایل استفاده می‌شود.

**مشکلات:**
1. **`/_opcache_reset.php` عمومی است** — هیچ rule مسدودی آن را پوشش نمی‌دهد و بدون احراز هویت `opcache_reset()` اجرا می‌کند ⇒ امکان DoS با تخریب کش. [فایل](file:///c:/xampp/htdocs/swaapin/_opcache_reset.php)
2. **Rate limit درخواست OTP غیرفعال است** — کدهای `rate_limit_ip_status` و `rate_limit_ip` در [auth/login.php#L29-L47](file:///c:/xampp/htdocs/swaapin/auth/login.php#L29-L47) کامنت شده‌اند ⇒ خطر SMS Flooding (هزینه + سوءاستفاده). توجه: `verify_otp` در [خط ۸۸](file:///c:/xampp/htdocs/swaapin/auth/login.php#L88) هنوز محدودیت دارد.
3. **`error.txt` از وب قابل خواندن است** — نه با rule آپاچی (پسوند `.txt`) و نه با rule نگینکس مسدود می‌شود. [فایل](file:///c:/xampp/htdocs/swaapin/error.txt)
4. **APIهای AI عمومی بدون CSRF/Rate Limit**: `api/ai_match.php`، `api/ai_search.php`، `api/negotiator_analyze.php`.
5. **کد مرده با اطلاعات حساس**: [tools/debug_admins.php](file:///c:/xampp/htdocs/swaapin/tools/debug_admins.php) (ایمیل ادمین و email ثابت را چاپ می‌کند) — اگرچه پوشه `tools/` بلاک است، بهتر است حذف شود.
6. **فایل‌های secrets** (`ai_secrets.php`, `mail_secrets.php`, `sms_secrets.php`, `sep_secrets.php`) داخل `includes/` هستند که بلاک شده، اما محتوای آن‌ها کلید واقعی دارد؛ توصیه: چرخش کلیدها و انتقال خارج از وب‌روت.

> نکته: هیچ مورد SQL Injection تأییدشده‌ای پیدا نشد؛ کوئری‌ها از Prepared Statement استفاده می‌کنند. (کلیدهای واقعی در این گزارش درج نشده‌اند.)

---

## ۴. ترافیک بالا و ظرفیت

- **کانفیگ PHP-FPM در ریپو نیست** (روی سرور مدیریت می‌شود) ⇒ `pm.max_children`, `pm.max_requests` قابل ممیزی نیست. اتصال از طریق `unix:/run/php/php8.4-fpm.sock`: [swaapin.ir.conf#L401-L405](file:///c:/xampp/htdocs/swaapin/ops/nginx/swaapin.ir.conf#L401-L405)
- **بدون Persistent Connection** و بدون Pool ⇒ تعداد اتصال MySQL = تعداد worker فعال. نیاز به هم‌راستاسازی `pm.max_children` با `max_connections` دیتابیس.
- **بدون کش لایه‌ای** ⇒ تمام بار روی MySQL و PHP می‌افتد.
- **بدون Rate Limiting سطح Nginx** (`limit_req`) برای نقاط پرترافیک/عمومی.
- **بدون gzip/cache header در Nginx** (فاز سرور، خارج از محدوده کد فعلی).
- **تست بار (k6/JMeter) انجام نشده** — نیاز به محیط staging.

---

## ۵. مانیتورینگ و پایداری

- **لاگینگ سفارشی خوب**: تابع `swapin_debug_log` با `Request ID`.
  **اما فقط در non-production فعال است** (`if (APP_ENV !== 'production')`) ⇒ در پروداکشن تقریباً کور هستیم.
- **بدون زمان‌سنجی پاسخ** — هیچ `microtime`/`hrtime` در `config.php` یا `layout.php` نیست.
- **بدون APM** (Sentry/New Relic/Prometheus/Datadog).
- **Metabase** برای تحلیل بیزینسی هست (۱۰ داشبورد)، ولی برای مانیتورینگ فنی (RAM/CPU/خطا) نیست.
- **healthcheck.sh** فقط سلامت Metabase/Docker را چک می‌کند: [healthcheck.sh](file:///c:/xampp/htdocs/swaapin/ops/metabase/scripts/healthcheck.sh)
- **Log Rotation** برای `storage/logs/error.log` تعریف نشده.
- **CI/CD**: `deploy.yml` فقط گیت‌های SEO را اجرا می‌کند؛ هیچ gate عملکردی/امنیتی/تست بار وجود ندارد: [deploy.yml](file:///c:/xampp/htdocs/swaapin/.github/workflows/deploy.yml)

---

## ۶. پاکسازی فایل‌ها (کم‌ریسک، سریع)

- `temp_chunk1.json` … `temp_chunk3.json`
- `users-import.json`، `ads-import.json`، `image_manifest.json`
- `error.txt` (لاگ، نباید در وب‌روت باشد)
- `_opcache_reset.php`
- پوشه `import_cache_images/` (صدها jpg موقت)
- تصاویر پراکنده در روت: `91880dba-....png`، `image.png`، `ChatGPT Image ...png`، `آبمیوه‌گیری ...jpg`

---

## نقشه فازها (پس از تأیید)

| فاز | عنوان | وضعیت |
|-----|-------|-------|
| ۰ | ممیزی (همین سند) | ✅ انجام شد |
| ۱ | کوئری‌ها و دیتابیس | ✅ انجام شد |
| ۲ | تصاویر و استاتیک | ✅ انجام شد |
| ۳ | فرانت‌اند و Core Web Vitals | ✅ انجام شد |
| ۴ | پایداری و پاکسازی | ✅ انجام شد |
| ۵ | امنیت | ✅ انجام شد |
| ۶ | ترافیک بالا و ظرفیت | 🟡 بخش کد انجام شد؛ بخش سرور نیازمند تصمیم |
| ۷ | مانیتورینگ | ✅ انجام شد |
| ۸ | اندازه‌گیری مجدد و مقایسه | 🟡 نیازمند محیط production/staging |

---

## خروجی مورد انتظار از فاز ۰

- پایه‌ی مقایسه (baseline) برای فاز ۸.
- فهرست اولویت‌دار بالا که مبنای اجرای فازهای بعدی است.

## نکات نیازمند دسترسی/تصمیم

1. دسترسی read-only به MySQL برای بررسی ایندکس‌ها (`SHOW INDEX`).
2. تأیید برای تنطیمات سرور (Nginx cache/gzip، OPcache، PHP-FPM) — فعلاً خارج از محدوده کد.
3. تأیید حذف فایل‌های زائد و `_opcache_reset.php`.
4. محیط staging برای تست بار.
5. تأیید چرخش کلیدهای secrets.

---

# گزارش اجرا — فازهای ۱ تا ۷ (۲۰۲۶-۱۰-۳۱)

> محدوده: فقط کد اپلیکیشن (بدون تنظیمات سرور). همه فایل‌های تغییر‌یافته با `php -l` بدون خطا تأیید شدند.

## فاز ۱ — کوئری‌ها و دیتابیس
- **رفع N+1 کارت آگهی**: تابع `listing_card_prefetch()` در [includes/config.php](../../includes/config.php) اضافه شد و در [includes/listing_card.php](../../includes/listing_card.php) کوئری فروشگاه از حالت per-card به یک کوئری دسته‌ای (`WHERE id IN (...)`) تبدیل شد (کش در `$GLOBALS['lc_store_map']`).
- **حذف کوئری بی‌مصرف `imgCount`** در کارت آگهی (خروجی آن در هیچ markup استفاده نمی‌شد).
- **بهینه‌سازی درخت دسته‌بندی**: `swaapin_category_descendant_ids()` در [includes/i18n.php](../../includes/i18n.php) از BFS با «یک کوئری به‌ازای هر گره» به «یک کوئری کل جدول + ساخت نقشه فرزندان در PHP» تغییر کرد.
- فراخوانی پیش‌واکشی در صفحات: `index.php`، `listings/all.php`، `listings/saved.php`، `profile.php`، `shop/index.php`، `search/ai.php`، `listings/view.php`.

## فاز ۲ — تصاویر و استاتیک
- تصویر کارت آگهی: افزودن `width/height` + `loading="lazy"` + `decoding="async"`.
- [listings/view.php](../../listings/view.php): تصویر اول گالری موبایل و تصویر اصلی دسکتاپ از `lazy` به eager با `fetchpriority="high"` تغییر کردند (LCP) و `width/height` + `decoding="async"` گرفتند؛ تصاویر بندانگشتی نیز ابعاد و `lazy` گرفتند.

## فاز ۳ — فرانت‌اند و Core Web Vitals
- [includes/layout.php](../../includes/layout.php): افزودن `preconnect` به GTM، `dns-prefetch` به GA، و `preload` فونت Medium.
- افزودن `defer` به `app.js`، `pwa/platform-detector.js`، `pwa/pwa-core.js`، `push-alerts.js` (هر دو بلوک فوتر) و تبدیل بلاک init مربوط به PWA به `DOMContentLoaded`.
- [src/fonts/iransans/fontiran.css](../../src/fonts/iransans/fontiran.css): افزودن `font-display: swap` به همه‌ی ۱۱ بلوک `@font-face`.

## فاز ۴ — پایداری و پاکسازی
- حذف `error.txt` (لاگ build فلاتر، ۱۵۱KB، در وب‌روت).
- حذف `tools/debug_admins.php` (چاپ ایمیل ادمین‌ها).
- `_opcache_reset.php` محدود به ادمین احراز هویت‌شده شد (`require_admin()`) — رفع خطر DoS.

## فاز ۵ — امنیت
- **فعال‌سازی محدودیت نرخ ارسال OTP** در [auth/login.php](../../auth/login.php) (۳ درخواست/۱۰ دقیقه + cooldown) — رفع SMS Flooding.
- **عدم ثبت کد OTP در لاگ پروداکشن** (فقط در محیط غیرتولیدی ثبت می‌شود).
- بررسی شد: `api/ai_match.php`، `api/ai_search.php`، `api/negotiator_analyze.php` همگی احراز هویت + Rate Limit دارند (یافته‌ی شماره ۴ گزارش فاز ۰ دیگر معتبر نیست).

## فاز ۶ — ترافیک بالا (بخش کد)
- رفع DoS اندپوینت OPcache و افزودن Rate Limitهای تکمیلی (هم‌راستا با فاز ۵).
- **خارج از محدوده کد (نیازمند تصمیم/دسترسی سرور):** لایه کش (APCu/Redis/File)، gzip و cache header در Nginx، و تنظیم `pm.max_children`/`max_connections`، و تست بار روی staging.

## فاز ۷ — مانیتورینگ
- افزودن زمان‌سنج درخواست در [includes/config.php](../../includes/config.php) (`SWAPIN_REQ_START`) و یک `register_shutdown_function` که درخواست‌های کندتر از ۱.۵ ثانیه را با URI و مدت‌زمان در لاگ ثبت می‌کند.
- لاگینگ خطا در `storage/logs/error.log` فعال بود و حفظ شد.

## فاز ۸ — اندازه‌گیری مجدد
- نیازمند محیط production/staging؛ اسکریپت گیت‌ها: `./tools/seo-production-check.sh` و `./scripts/check-seo-analytics.sh` (این دو روی سایت production اجرا می‌شوند، نه لوکال).
