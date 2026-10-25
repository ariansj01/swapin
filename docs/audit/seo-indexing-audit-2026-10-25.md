# گزارش فنی ایندکس‌شدن Swaapin (swaapin.ir)

تاریخ تهیه: 2026-10-25 — دامنه: `https://swaapin.ir` — وضعیت: **فقط بررسی و طراحی راهکار؛ هیچ فایلی تغییر نکرده است.**

---

## 0) محدودهٔ بررسی و صداقت‌سنجی منابع

### چیزهایی که از کد واقعی تأیید شد (این مخزن، commit فعلی)
- هر نقطه‌ای که `meta robots` تولید می‌کند + مقدار پیش‌فرض `index, follow` در `includes/layout.php:79`.
- منطق ساخت `rel=canonical` برای همهٔ انواع صفحه.
- `robots.txt` مخزن، `sitemap.xml`، `sitemap.php`، `sitemap-pages.php`، `sitemap-main.xml`.
- قوانین `Nginx` (`ops/nginx/swaapin.ir.conf`) و `.htaccess` ریشه و `page/.htaccess`.
- پارامترهای خوانده‌شده در صفحهٔ اصلی و در `/listings/`.
- عدم وجود `X-Robots-Tag` در کل مخزن (Nginx و .htaccess و PHP).

### چیزهایی که **قابل بررسی در کد نبود** (صریحاً استنباط‌شده یا نامشخص)
1. **سورس WordPress وجود ندارد.** در هیچ‌جای این مخزن `wp-config.php`، `wp-content`، `wp-admin`، `wp-includes` یا پوشهٔ `blog/` نیست. وبلاگ یک نصب مستقل است که در Nginx با `try_files $uri $uri/ /blog/index.php?$args;` سرو می‌شود (`ops/nginx/swaapin.ir.conf:313-315`). پس **سورس و تنظیمات افزونه‌های SEO وبلاگ از روی کد تأیید نشده؛ فقط خروجی HTTP زندهٔ آن در بخش ۹ بررسی شد.**
2. ~~**`robots.txt` و هدرهای زندهٔ سرور تولید** بررسی نشد.~~ ← **در فاز ۰ بررسی شد (بخش ۹): `robots.txt` زنده دقیقاً برابر فایل مخزن است و این فرضیه رد شد.**
3. ~~**خروجی واقعی HTTP** (کد وضعیت، ریدایرکت‌ها) گرفته نشد.~~ ← **در فاز ۰ گرفته شد (بخش ۹).**
4. **محتوای دیتابیس** (`content_pages.index_status`، دسته‌بندی‌ها، آگهی‌ها) بررسی نشد.
5. **Drilldown فقط برای ۲ مورد** در اختیار بود («Alternate canonical» و «noindex»). برای ۴۰۴ (۳۴۵)، robots (۵۸۴)، Duplicate (۱۷+۵)، Crawled/Discovered (۸۴+۱۶۲) و Redirect (۱۰۲) فایل URL-level داده نشد؛ این‌ها **فقط دسته‌بندی و فرضیه** هستند.

### داده‌های ورودی
| فایل | محتوا |
|---|---|
| `swaapin.ir-Coverage-2026-10-25.zip` | `Chart.csv`، `Critical issues.csv`، `Non-critical issues.csv`، `Metadata.csv` |
| `swaapin.ir-Coverage-Drilldown-2026-10-25.zip` | `Table.csv` = نمونهٔ **۱۰۰۰ ردیفی** (سقف اکسپورت GSC) از ۳۹۲۹ URL «Alternate page with proper canonical tag» |
| `swaapin.ir-Coverage-Drilldown-2026-10-25_2.zip` | `Table.csv` = فهرست کامل ۱۹ URL «Excluded by noindex tag» |

نکتهٔ مهم: `Table.csv` کانونیکال شامل **۱۰۰۰ ردیف** است نه ۳۹۲۹ ردیف؛ ترکیب درصدها در بخش ۲ بر همین نمونه استوار است.

---

## 1) خلاصهٔ اجرایی

| شاخص (2026-10-16) | مقدار |
|---|---|
| Indexed | ۲۱۲ |
| Not indexed | ۵۲۵۱ |
| Impressions روزانه | ۲۰–۸۰ |
| Alternate page with proper canonical tag | ۳۹۲۹ |
| Blocked by robots.txt | ۵۸۴ |
| Not found (404) | ۳۴۵ |
| Discovered – currently not indexed | ۱۶۲ |
| Page with redirect | ۱۰۲ |
| Crawled – currently not indexed | ۸۴ |
| Excluded by noindex | ۱۹ |
| Duplicate without user-selected canonical | ۱۷ |
| Duplicate, Google chose different canonical | ۵ |

**تشخیص کلان:** مشابه همان چهار فایلی که گوگل استفاده می‌کند، لایهٔ canonical این سایت **عمدتاً درست کار می‌کند** و ۳۹۲۹ ورودی «Alternate» در واقع نتیجهٔ درستِ کانونیکال به صفحهٔ اصلی است. بنابراین ریشهٔ مشکل «کانونیکال شکسته» نیست، بلکه سه چیز است:

1. **انفجار URLهای پارامتردار** روی صفحهٔ اصلی و روی `/listings/map` که کراول‌بودجه را می‌بلعد و ریشهٔ اصلی آن با شواهد گیت شناسایی شد (بخش ۳-۲).
2. **یک URL با `noindex` که در Sitemap اعلام شده** (`/ai/chat`) → سیگنال متناقض و خطای سایتمپ.
3. **۲۴۶ URL «Crawled/Discovered – not indexed»** که مسئلهٔ کیفیت/اعتبار محتواست، نه کد؛ و **۳۴۵ تا ۴۰۴** و **۵۸۴ مسدود** که برای تشخیص قطعی به drilldown نیاز دارند.

---

## 2) تطبیق گزارش Coverage با کد (مرحلهٔ اول)

| دلیل GSC | تعداد | منبع | الگوی URL (نمونه) | شاهد در کد | قضاوت |
|---|---|---|---|---|---|
| Alternate page with proper canonical tag | ۳۹۲۹ | Website | `/?cat=…&city=…&sort=new&time_ago=…` (۹۹۱ از ۱۰۰۰ نمونه) | `index.php:234-240` (canonical ثابت `APP_URL . '/'`) | رفتار کانونیکال **درست**؛ مشکل «حجم کراول» |
| همان | – | Website | `/listings/?slug_path=…&cat=…&page=1`، `/listings/?page=1` (۵ نمونه) | `listings/all.php:210-217` | کانونیکال ناهمخوان با `/listings/` (بدون اسلش) |
| همان | – | Website | `/shops/` (۱ نمونه) | `shops/index.php:77-82` (`/shops` بدون اسلش) | ناهمخوانی اسلش انتهایی |
| همان | – | Website | `/support/report.php` (۱ نمونه) | `support/report.php:36` | درست (نسخهٔ .php → نسخهٔ تمیز) |
| همان | – | Website | `/blog/category/barter-goods/?filter_by=random_posts\|popular` (۲ نمونه) | خارج از مخزن (افزونه/قالب WP) | فیلتر تکراری سمت وبلاگ |
| Excluded by noindex | ۱۹ | Website | `/pwa`, `/search/ai`, `/ai/chat`, `/ai/chat.php`, `/blog/author/*`, `/blog/page/2..5/`, `/blog/2026/07/page/2/`, `/blog/2026/page/3/`, `/blog/search/{search_term_string}/`, `/blog/?s={search_term_string}` | `pwa.php:12`, `search/ai.php:34`, `ai/chat.php:10` (+ وبلاگ: خارج از مخزن) | ۳ مورد آن **عمدی و صحیح**، ۱ مورد **در تناقض با Sitemap**، بقیه سمت WP |
| Blocked by robots.txt | ۵۸۴ | Website | نامشخص | `robots.txt:13-45` (لیست کوتاه مسیرهای خصوصی) | **فاز ۰: `robots.txt` زنده عیناً برابر مخزن است → فرضیهٔ تفاوت نسخه رد شد.** ۵۸۴ URL باید از همان الگوهای اعلام‌شده (مثل `/auth/*`، `/listings/create*`، `/blog/wp-admin/`) باشند؛ تفکیک قطعی نیازمند drilldown |
| Not found (404) | ۳۴۵ | Website | نامشخص | `listings/view.php:42-49` و `:95-102` (۴۰۴ + noindex)، `404.php:5-7` | منابع محتمل: آگهی‌های حذف‌شده، `/page/<slug>` ناموجود، URLهای وبلاگ؛ **قطعی نیست** |
| Page with redirect | ۱۰۲ | Website | نامشخص | `nginx:96-98` (تاریخ WP)، `nginx:65-79` (`*.php` → تمیز)، `nginx:207-213` (`/listings/all*` → `/listings/`)، `.htaccess:106` (`/shop/<slug>` → `/shop/both/<slug>`) | بخشی عادی است؛ مقدار ۱۰۲ نیازمند drilldown |
| Crawled – currently not indexed | ۸۴ | Google | نامشخص | — | کیفیت/اعتبار محتوا؛ خارج از دامنهٔ کد |
| Discovered – currently not indexed | ۱۶۲ | Google | نامشخص | — | بودجهٔ کراول/کیفیت |
| Duplicate without user-selected canonical | ۱۷ | Website | نامشخص | فرضیه: `/page/<slug>` در برابر مسیر کوتاه‌تر؛ `/shop/<type>/<slug>` | **اثبات نشده** |
| Duplicate, Google chose different canonical | ۵ | Google | نامشخص | فرضیه:店铺/آگهی با محتوای مشابه | **اثبات نشده** |
| Blocked due to other 4xx | ۳ | Website | نامشخص | — | نیازمند drilldown |
| Server error (5xx) | ۱ | Website | نامشخص | `500.php` | تک‌مورد |
| Indexed, though blocked by robots.txt | ۰ | — | — | — | ✅ سالم |

---

## 3) جدول تشخیص کامل (مرحلهٔ دوم و چهارم)

ستون‌ها: **URL یا الگو | علت | فایل و خط | اقدام پیشنهادی | اولویت | ریسک تغییر**

| # | URL یا الگو | علت (شافی/محتمل) | فایل و خط | اقدام پیشنهادی | اولویت | ریسک |
|---|---|---|---|---|---|---|
| 1 | `/?*` (تمام پارامترهای صفحهٔ اصلی): `?cat`, `?city`, `?sort`, `?time_ago`, `?want`, `?page`, `?price_min/max`, `?q`, `?seller` — ۳۹۲۹ URL | کانونیکال ثابت به `/` (درست) + تولید بی‌پایان URL از لینک‌های شهری و فرم GET ناوبار. صفحهٔ اصلی ۹ پارامتر را می‌خواند ولی هیچ‌کدام تغییر «هویت URL» نمی‌دهد | `index.php:19-28` (خواندن پارامترها)، `index.php:234-240` (canonical)، `includes/layout.php:300` (hidden city)، `includes/layout.php:307` (لینک `/?city=…`)، `includes/layout.php:333` (form action=`/` GET) | افزودن `Disallow: /?` به robots.txt (بند P1-1). مقصد: توقف کراول URLهای منقضی. کانونیکال را دست نزن | P1 | متوسط — نیازمند تأیید زنده؛ اگر روزی کمپین `/?ref=` راه افتاد باید بازبینی شود |
| 2 | `/listings/map?cat=…&city=…&sort=…&time_ago=…` | **نقطهٔ ورودی فعلی همین الگوها**: لینک‌های سایدبار همه به این آدرس می‌روند و صفحه کانونیکال پارامتری ندارد؛ کانونیکال همهٔ واریانت‌ها `/listings/map` می‌شود | `includes/sidebar_home.php:49-62` (ساخت لینک)، `listings/map_view.php:146-149` (بدون canonical صریح → پیش‌فرض `includes/seo.php:4-10`) | `noindex, follow` برای واریانت‌های فیلترشدهٔ مپ (بند P2-1). گزینهٔ معماری بهتر: تغییر لینک سایدبار به `category_url()` یا `/listings/?cat=` (نیازمند تصمیم شما؛ UI را عوض می‌کند) | P2 | کم (فقط متا) |
| 3 | `/ai/chat` | URL با `noindex, nofollow` که **در Sitemap اعلام شده** → خطای سایتمپ و سیگنال متناقض | `sitemap.php:24` در برابر `ai/chat.php:9-10` | حذف ردیف `/ai/chat` از `sitemap.php` (بند P1-2) | P1 | کم |
| 4 | `/ai/chat.php` | نسخهٔ .php همان صفحه؛ کانونیکال به `/ai/chat` (درست)؛ هر دو `noindex` | `ai/chat.php:9-10`، `.htaccess:152-156` (نگاشت `/ai/chat` → `ai/chat.php`) | اختیاری: ۳۰۱ از `/ai/chat.php` به `/ai/chat` (بند P3-2) | P3 | کم |
| 5 | `/pwa` | `noindex, nofollow` ولی در فوتر/سایدبار لینک داخلی دارد (`sidebar_home.php:206`) و در `robots.txt` مجاز است | `pwa.php:7-13`، `includes/sidebar_home.php:206` | تغییر به `noindex, follow` تا ارزش لینک داخلی هدر نرود (بند P2-2) | P2 | کم |
| 6 | `/search/ai` | ابزار جستجو، محتوای نازک — `noindex, nofollow` | `search/ai.php:32-35` | **بدون تغییر** (تصمیم: noindex درست است). لینک داخلی دارد → اختیاری `noindex, follow` | P3 | کم |
| 7 | `/blog/author/*` (۴ URL) | آرشیو نویسنده در وردپرس noindex شده | خارج از مخزن (WP) | **noindex درست است**؛ فقط تأیید شود که این تنظیم از افزونه آمده و روی «نوشته‌ها» اعمال نشده | P3 | نیاز به دسترسی WP |
| 8 | `/blog/page/2..5/` | صفحه‌بندی وبلاگ noindex | خارج از مخزن (WP) | بازنگری: برای وبلاگ، صفحه‌بندی `index, follow` با کانونیکال به خودش بهتر است (مقالات از صفحهٔ ۱ و wp-sitemap هم قابل کشف‌اند → ضرر کم) | P3 | نیاز به دسترسی WP |
| 9 | `/blog/2026/07/page/2/`, `/blog/2026/page/3/` | آرشیو تاریخ | خارج از مخزن (WP) | **noindex درست است**؛ بدون تغییر | P3 | — |
| 10 | `/blog/search/{search_term_string}/`, `/blog/?s={search_term_string}` | الگوی خام `{search_term_string}` از Schema جستجوی داخلی WP (جایگزین‌نشده) | خارج از مخزن (WP)؛ مشابه الگوی ما در `includes/seo.php:58-62` | در WP: اصلاح `SearchAction` + افزودن `Disallow: /blog/?s=` و `Disallow: /blog/search/` (بند P1-1) | P2 | کم (robots) |
| 11 | `/blog/category/*/?filter_by=random_posts\|popular` | پارامتر `filter_by` از افزونه/قالب WP؛ کانونیکال آن را حذف نمی‌کند | خارج از مخزن (WP) | در WP: `noindex` برای `?filter_by=` یا حذف لینک‌های فیلتر | P3 | نیاز به دسترسی WP |
| 12 | `/listings/?slug_path=…&cat=…&page=1` | شاخهٔ `slug_path` در کانونیکال، `rtrim(...,'/')` می‌زند و `/listings` (بدون اسلش) تولید می‌کند ≠ کانونیکال استاندارد `/listings/` | `listings/all.php:210-217` | استفاده از مسیر بدون دست‌کاری: `$canonical = APP_URL . $requestCanonicalPath;` (بند P2-3). مراقب: `tools/seo-production-check.sh:163` انتظار کانونیکال `/listings/` را دارد | P2 | کم |
| 13 | `/shops/` | کانونیکال `/shops` (بدون اسلش) در حالی که URL اسلش دارد | `shops/index.php:77-82`، `sitemap.php:25-27` | همسان‌سازی به یک شکل واحد (`/shops/`) + ۳۰۱ از `/shops` به `/shops/` (بند P3-3) | P3 | کم |
| 14 | `/support/report.php` | کانونیکال `/support/report` (درست) | `support/report.php:35-37` | اختیاری: ۳۰۱ از نسخهٔ .php به نسخهٔ تمیز | P3 | کم |
| 15 | `/shop/<slug>` → `/shop/both/<slug>` → کانونیکال `/shop/<type>/<slug>` | زنجیرهٔ ۳۰۱ + کانونیکال متفاوت | `.htaccess:103-109`، `shop/index.php:61`، `includes/store.php:621` | لینک‌های داخلی از قبل درست‌اند (`store_url`)؛ فقط لینک‌های خارجی/قدیمی زنجیره می‌سازند | P3 | کم |
| 16 | `/page/<slug>` در برابر `/{slug}` | CMS صفحه را در `/page/<slug>` با کانونیکال همان مسیر سرو می‌کند؛ احتمال محتوای مشابه با فایل‌های ایستا (مثل `/about`) | `page/index.php:22-34`، `.htaccess:150` | **فرضیه — اثبات‌نشده.** نیاز به نمونه‌ی واقعی از drilldown «Duplicate» | P3 | نیاز به داده |
| 17 | `/?seller=N` | `index.php` پارامتری با نام `seller` نمی‌خواند؛ پس لینک «مشاهده همه آگهی‌ها» در پروفایل در واقع صفحهٔ اصلی را با پارامتر بی‌اثر نشان می‌دهد (هم باگ کارکردی، هم URL تکراری) | تولید: `profile.php:188` — مصرف: هیچ‌کجا در `index.php:19-28` و `listings/all.php` | **تصمیم شما لازم است:** (الف) پیاده‌سازی فیلتر فروشنده در `/listings/` با کانونیکال مستقل، یا (ب) حذف/تغییر لینک. بدون تصمیم، پچی پیشنهاد نشده | P2 | متوسط — تغییر رفتار UI |
| 18 | `/trades` و `/trades.php` | `robots.txt` مسدود کرده ولی `trades.php:256` کانونیکال + `index,follow` می‌دهد (تناقض) | `robots.txt:44-45`، `trades.php:256` | یا مسدودی robots را نگه دار و انتظار ایندکس نداشته باش (وضع موجود = قابل قبول)، یا مسدودی را بردار و `noindex` بده. **بدون تغییر پیشنهادی تا تصمیم شما** | P3 | کم |
| 19 | `/page/404` و هر ۴۰۴ | `404.php` وضعیت ۴۰۴ درست می‌دهد ولی متای پیش‌فرض `index, follow` است | `404.php:5-8` | افزودن `noindex, follow` (بند P2-4) | P3 | کم |
| 20 | Schema ناوبری سایت | `SiteNavigationElement` به `/listings/all` اشاره می‌کند که ۳۰۱ به `/listings/` می‌شود؛ و به `/listings/create` (مسدود در robots) | `includes/seo.php:70-94` | تغییر `/listings/all` → `/listings/` (بند P2-5) | P3 | کم |
| 21 | `sitemap-main.xml` | فایل ایستا با ۲ URL که سمت سرور ۳۰۱ به `/sitemap.xml` می‌شود | `sitemap-main.xml`، `ops/nginx/swaapin.ir.conf:54-56` | در GSC: اگر به‌عنوان سایتمپ ثبت شده، حذف و `/sitemap.xml` ثابت شود | P2 | کم (اقدام در GSC) |
| 22 | `/iso/*` (noindex) | درخواست‌های «به دنبال…» کاربران؛ می‌تواند برنامه‌ریزی‌شده برای SEO باشد ولی فعلاً بسته است | `iso/index.php:58`، `iso/view.php:16,76`، `iso/create.php:72`، `iso/edit.php:16,83` | **تصمیم کسب‌وکار** — اگر محتوای UGC ارزشمند شد، فقط فهرست/جزئیات عمومی باز شود (نه فرم‌های create/edit) | P3 | نیاز به تصمیم |
| 23 | ۲۴۶ URL «Crawled/Discovered – not indexed» | کیفیت محتوا، اعتبار دامنه، محتوای نازک آگهی‌ها | — | بدون drilldown قابل اقدام نیست: دریافت اکسپورت، سپس بهبود توضیحات آگهی/دسته و لینک‌سازی داخلی | P2 | — |
| 24 | ۳۴۵ URL ۴۰۴ | نامشخص | `listings/view.php:42-49`، `listings/view.php:55-93` (۳۰۱ به مشابه)، `404.php` | قاعدتاً آگهی حذف‌شده بهتر است ۳۰۱ به دسته شود (منطق مشابه از قبل هست) ولی **بدون drilldown ریدایرکت کورکورانه نکن** | P2 | متوسط |

---

## 4) بررسی ویژه (مرحلهٔ سوم) — پاسخ صریح

**۱. URLهای پارامتردار صفحهٔ اصلی و درستی کانونیکال**
کانونیکال برای **همهٔ** حالت‌ها `https://swaapin.ir/` است (`index.php:235`, بدون هیچ شرطی روی `$_GET`). این «درست» است چون صفحهٔ اصلی با هر پارامتری همان محتوای صفحهٔ اصلی را رندر می‌کند (صفحه‌بندی `?page` هم فقط بخش آگهی‌های صفحهٔ اصلی است). پس ۳۹۲۹ ردیف «Alternate» در واقع **نتیجهٔ سالمِ کانونیکال** است، نه خطا. تنها اشکال واقعی: هدر‌رفتن کراول‌بودجه و پوشاندن مشکلات واقعی زیر ۳۹۲۹ ردیف. جمع‌بندی: کانونیکال را عوض **نکن**؛ کراول را محدود کن.

**۲. `/pwa`، `/search/ai`، `/ai/chat`، `/ai/chat.php` — تصمیم ایندکس**

| URL | کاربرد واقعی | تصمیم | چرا |
|---|---|---|---|
| `/pwa` | صفحهٔ نصب اپلیکیشن، فقط CTA | `noindex, follow` | محتوای یکتایی ندارد؛ ولی چند لینک داخلی دارد پس nofollow ارزش لینک را هدر می‌دهد |
| `/search/ai` | ابزار جستجوی هوش مصنوعی | `noindex, follow` | نتیجهٔ جستجو نباید ایندکس شود |
| `/ai/chat` و `/ai/chat.php` | چت با دستیار | `noindex, follow` (بماند) + **حذف از Sitemap** | در `sitemap.php:24` اعلام شده بود که تناقض است |

**۳. آرشیو نویسنده و صفحه‌بندی وبلاگ**
هر ۴ نویسنده و `page/2..5` در لیست noindex هستند (۱۹ مورد). این‌ها **عمدی و از سمت وردپرس** است، نه خطای سایت PHP. فقط دو نکته: (الف) مطمئن شوید noindex روی «نوشته‌های تکی» اعمال نشده؛ (ب) برای وبلاگی که هدف رشد ترافیک ارگانیک دارد، صفحه‌بندی `index, follow` معمولاً بهتر است (چون صفحهٔ ۱ همیشه ضعیف‌ترین توزیع لینک را دارد). هر دو نیازمند دسترسی به پنل WP است.

**۴. بررسی اینکه صفحات ارزشمند اشتباهاً noindex نشده‌اند**
تمام ۱۴ نقطهٔ noindex در مخزن بازبینی شد: admin/*، ai/chat، chat/*، dashboard، iso/*، store-offers/*، trades/view، search/*، payment_callback، listings/{promote,success,welcome}، pwa، auth/store-login، و حالت‌های خطای `listings/view.php`. **هیچ صفحهٔ عمومی ارزشمندی (آگهی، دسته‌بندی، صفحهٔ اصلی، فروشگاه، وبلاگ) در کد noindex نشده است.** تنها استثنای «قابل بحث»: `iso/*` (بالقوه محتوای UGC مفید) و `page/index.php` که مقدارش از `content_pages.index_status` دیتابیس می‌آید و نیاز به بررسی دستی DB دارد.

**۵. آیا URLهای مسدودشده در robots.txt در Sitemap هم هستند؟**
خیر. مقایسهٔ لیست سایتمپ‌ها (`sitemap.php`، `sitemap-pages.php`، `sitemap-main.xml`) با `robots.txt` انجام شد: هیچ‌کدام از URLهای تولیدی سایتمپ تحت `Disallow` نیست. (همین مورد در `tools/seo-production-check.sh:262-269` هم گارد شده است.)
⚠️ اما یک تناقض جانبی وجود دارد: JSON-LD ناوبری سایت به `/listings/create` (مسدود در robots) و `/listings/all` (۳۰۱) لینک می‌دهد — فایل `includes/seo.php:73,76`.

**۶. تفکیک noindex عمدی از خطای واقعی SEO**

| عمدی (بدون اقدام) | خطای واقعی (نیازمند اقدام) |
|---|---|
| admin/*, dashboard, chat/*, store-offers/*, trades/*, search/saved, payment_callback, listings/{create,edit,my,saved,bump,promote}, auth/*, pwa, search/ai, ai/chat, 404-state آگهی | ۱) `/ai/chat` داخل Sitemap (`sitemap.php:24`) ۲) `nofollow` روی `/pwa` با لینک داخلی ۳) کانونیکال `/listings` بدون اسلش ۴) `/shops` بدون اسلش |

---

## 5) ریشه‌یابی زمانی (شواهد گیت) — مهم‌ترین یافته

`Chart.csv` نشان می‌دهد شمار «Not indexed» در ۲۰۲۶-۱۰-۰۵ از ۸۴۹ به ۳۷۷۵ و در ۲۰۲۶-۱۰-۱۰ از ۳۷۷۵ به ۵۲۱۳ جهش کرده و «Alternate» از ۱۴۵ (۴ اکتبر) به ۲۵۶۰ (۵ اکتبر) و ۳۹۰۷ (۱۰ اکتبر) رسیده است. تطبیق با تاریخ کامیت‌ها:

| تاریخ | کامیت | ارتباط |
|---|---|---|
| 2026-10-04 | `0b17403` — `.htaccess` for listings routing + import | بازهٔ اولین جهش (۵ اکتبر) — **فرضیه، اثبات‌نشده** |
| **2026-10-09** | **`7972410` "feat: add province city filtering, default Tehran"** و `3efc904` "feat: add compact homepage and navbar city search" | این دو کامیت `includes/layout.php` را تغییر داده و **لینک‌های `/?city=…` و شهر پیش‌فرض تهران** را اضافه کرده‌اند؛ `git log -S '/?city=' -- includes/layout.php` فقط `3efc904` را نشان می‌دهد. جهش ۱۰ اکتبر دقیقاً روز بعد از این دو کامیت است — **قوی‌ترین شاهد** |
| 2026-10-13 | `3e7d420` "add map page for slidebar category" | مسیر لینک‌های سایدبار از `/?cat=…` به `/listings/map?cat=…` منتقل شد؛ یعنی منبع URLهای پارامتردار **بسته نشد، جابه‌جا شد** (`includes/sidebar_home.php:49-62`) |

نتیجهٔ عملی: علاوه بر تمیزکاری گذشته، باید **مقصد فعلی** لینک‌های پارامتری هم تحت کنترل بیاید (جدول، ردیف‌های ۱ و ۲).

---

## 6) پچ‌های پیشنهادی (آماده — **اعمال نشده**)

### P1-1 — `robots.txt` : محدودکردن کراول URLهای فیلترشده

```diff
--- a/robots.txt
+++ b/robots.txt
 Sitemap: https://swaapin.ir/sitemap.xml
 Sitemap: https://swaapin.ir/blog/wp-sitemap.xml
 
 Disallow: /trades
 Disallow: /trades.php
+
+# URLهای فیلترشدهٔ صفحهٔ اصلی (پارامترهای city/cat/sort/time_ago/...).
+# کانونیکال این‌ها درست است (به «/»)، اما کراول‌شدن‌شان ۳۹۲۹ ردیف
+# «Alternate page with proper canonical tag» و کراول‌بودجهٔ هدررفته ساخته است.
+Disallow: /?
+
+# جستجوی داخلی وبلاگ وردپرس (URLهای الگوی {search_term_string})
+Disallow: /blog/?s=
+Disallow: /blog/search/
```

- **اعتبارسنجی پیش از اعمال (الزامی):** «/?» فقط URLهایی را می‌گیرد که بعد از اسلش اول علامت سؤال دارند. `/listings/view?id=123` (آدرس کانونیکال آگهی‌ها) با این قاعده **مسدود نمی‌شود**. با `curl -A Googlebot` روی تعدادی URL تست شود.
- **جایگزین محافظه‌کارانه** اگر به `Disallow: /?` اعتماد ندارید: هشت خط صریح `Disallow: /?cat=`، `/?city=`، `/?q=`، `/?sort=`، `/?time_ago=`، `/?page=`، `/?want=`، `/?price_`، `/?seller=`.
- **اثر جانبی قابل انتظار:** ردیف «Alternate» کم می‌شود و «Blocked by robots.txt» زیاد. این **طبیعی و مطلوب** است.

### P1-2 — حذف URL دارای noindex از Sitemap

```diff
--- a/sitemap.php
+++ b/sitemap.php
     ['loc' => $base . '/valuation',  'priority' => '0.8', 'changefreq' => 'weekly'],
-    ['loc' => $base . '/ai/chat',   'priority' => '0.5', 'changefreq' => 'weekly'],
     ['loc' => $base . '/shops',          'priority' => '0.8', 'changefreq' => 'daily'],
     ['loc' => $base . '/shops/online',   'priority' => '0.8', 'changefreq' => 'daily'],
     ['loc' => $base . '/shops/physical', 'priority' => '0.8', 'changefreq' => 'daily'],
     ['loc' => $base . '/listings/',  'priority' => '0.7', 'changefreq' => 'daily'],
```

### P2-1 — متای `noindex, follow` برای واریانت‌های فیلترشدهٔ نقشه

```diff
--- a/listings/map_view.php
+++ b/listings/map_view.php
 $title = ($category ? category_label($category['slug'], $category['name']) : 'آگهی‌ها') . ' | ' . APP_NAME;
 $desc = "مشاهده آگهی‌های معاوضه در دسته " . ($category ? $category['name'] : 'همه') . " در شهر " . $city;
 
+// واریانت‌های فیلترشدهٔ نقشه نسخهٔ تکراری صفحهٔ اصلی نقشه‌اند و نباید ایندکس شوند.
+$mapHasFilters = !empty(array_intersect(
+    array_keys($_GET),
+    ['cat', 'q', 'city', 'want', 'sort', 'time_ago', 'price_min', 'price_max', 'loc', 'nearby_cities']
+));
+
 render_head($title, $desc, [
     'og_type' => 'website',
     'body_class' => 'page-listings',
+    'robots' => $mapHasFilters ? 'noindex, follow' : 'index, follow',
 ]);
```
> کانونیکال دست‌نخورده می‌ماند (`/listings/map`). اگر ترجیح می‌دهید کراول هم مصرف نشود، به‌جای این پچ از `Disallow: /listings/map?` در robots.txt استفاده کنید (یکی از دو راه، نه هر دو).

### P2-2 تا P2-5 — اصلاحات کوچک

```diff
--- a/pwa.php
+++ b/pwa.php
         'canonical' => APP_URL . '/pwa',
-        'robots'    => 'noindex, nofollow',
+        'robots'    => 'noindex, follow',

--- a/listings/all.php
+++ b/listings/all.php
 $categorySlugPath = trim((string)($_GET['slug_path'] ?? ''), '/');
 $requestCanonicalPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
 
 if ($categorySlugPath !== '' && $requestCanonicalPath !== '') {
-    $canonical = APP_URL . rtrim($requestCanonicalPath, '/');
+    // مسیر را دست‌نخورده نگه می‌داریم تا با کانونیکال استاندارد «/listings/» یکسان بماند
+    $canonical = APP_URL . $requestCanonicalPath;
 } else {
     $canonical = APP_URL . '/listings/';
 }

--- a/404.php
+++ b/404.php
-render_head('صفحه یافت نشد');
+render_head('صفحه یافت نشد', '', ['robots' => 'noindex, follow']);

--- a/includes/seo.php
+++ b/includes/seo.php
-        ['دسته‌بندی‌ها', APP_URL . '/listings/all'],
+        ['دسته‌بندی‌ها', APP_URL . '/listings/'],
```

### P3 — اختیاری و کم‌ارزش‌تر
- همسان‌سازی اسلش انتهایی `/shops/` (کانونیکال در `shops/index.php:77-82` + سایتمپ + یک ۳۰۱ از `/shops` به `/shops/`).
- ۳۰۱ از `/ai/chat.php` به `/ai/chat` و از `/support/report.php` به `/support/report`.
- در سمت وردپرس: `noindex` روی `?filter_by=`، بازنگری صفحه‌بندی، اصلاح `SearchAction`.

---

## 7) برنامهٔ اصلاح مرحله‌ای + تست + Rollback

### فاز ۰ — بررسی زنده (بدون هیچ تغییر کد؛ پیش‌نیاز قطعی همهٔ فازها)
```bash
# ۱) robots.txt واقعی سرور را با مخزن مقایسه کن (کلید معمای ۵۸۴ URL)
curl -sS -A "Googlebot" https://swaapin.ir/robots.txt

# ۲) کد وضعیت و کانونیکال صفحات کلیدی
for u in "/" "/?cat=vehicles&city=%D8%AA%D8%A7%D9%84%D8%B4&sort=new&time_ago=1d" \
         "/pwa" "/search/ai" "/ai/chat" "/ai/chat.php" \
         "/listings/" "/listings/?page=2" "/listings/map?cat=vehicles" \
         "/shops" "/shops/" "/blog/" "/blog/page/2/" "/blog/?s=test" \
         "/about" "/electronics" "/support/report.php" "/trades"; do
  printf '%s -> ' "$u"
  curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' "https://swaapin.ir$u"
done

# ۳) متای robots و کانونیکال چند نمونه
curl -sS https://swaapin.ir/ai/chat | grep -iE 'canonical|name="robots"'
curl -sS https://swaapin.ir/listings/ | grep -iE 'canonical|name="robots"'
```
- دریافت drilldown های باقی‌مانده از GSC: `Not found (404)`، `Blocked by robots.txt`، `Crawled/Discovered – not indexed`.
- در GSC: بررسی بخش Sitemaps (اگر `sitemap-main.xml` ثبت است، حذف و `sitemap.xml` جایگزین شود) و اجرای «URL Inspection» روی ۵ نمونه.
- بررسی دستی: آیا `robots.txt` سرور تولید با فایل مخزن یکسان است؟ (تفاوت = علت ۵۸۴)

### فاز ۱ — P1 (کم‌ریسک، اثر سریع)
- اعمال P1-1 و P1-2.
- **تست‌ها (اجباری طبق `AGENTS.md`):**
  ```bash
  bash ./tools/seo-production-check.sh     # باید PASS شود
  bash ./scripts/check-seo-analytics.sh    # باید PASS شود
  ```
  (این دو اسکریپت روی `https://swaapin.ir` اجرا می‌شوند؛ یعنی بعد از دیپلوی. قبل از دیپلوی فقط `sitemap.php` را با `php -l` و بازرسی چشمی خروجی XML تست کنید.)
- **معیار موفقیت:** خروجی `sitemap.php` دیگر `/ai/chat` نداشته باشد؛ `robots.txt` زنده شامل `Disallow: /?` باشد؛ صفحهٔ اصلی همچنان `index, follow` و کانونیکال `https://swaapin.ir/` بماند.

### فاز ۲ — P2
- اعمال P2-1 تا P2-5.
- تست: `map_view.php` با پارامتر باید `noindex, follow` بدهد و بدون پارامتر `index, follow`؛ کانونیکال `/listings/` در هر دو حالت `/listings/` بماند؛ پروفایل/آگهی/دسته همچنان ۲۰۰ + self-canonical.
- پایش: در GSC انتظار می‌رود «Alternate» کاهش و «Blocked by robots.txt» افزایش یابد (طبیعی)، «Duplicate» کاهش یابد.

### فاز ۳ — P3 و تصمیم‌های کسب‌وکار
- تصمیم دربارهٔ `/?seller=` (پیاده‌سازی فیلتر یا حذف لینک)، `/iso/*`، صفحه‌بندی وبلاگ، اسلش `shops`.
- سمت وبلاگ فقط با دسترسی WP و ترجیحاً با بکاپ قبل از تغییر.

### Rollback
- هر فاز **یک کامیت مستقل** با پیام مشخص → بازگشت = `git revert <sha>`.
- `robots.txt`، `sitemap.php`، `404.php`، `listings/*` فایل‌های ثابت/ساده‌اند: بازگشت با `git revert` کافی است.
- بعد از بازگشت، کش OPcache را پاک کنید (فایل `_opcache_reset.php` در ریشه موجود است) و کش CDN/Service Worker سایت را در نظر بگیرید (`sw-core.js`).
- اگر پچ robots مشکلی ساخت: تنها همان خط `Disallow: /?` را حذف کنید؛ چون فایل ایستا است اثر آن بلافاصله برمی‌گردد (نیازی به بازایندکس‌کردن نیست).

### سنجه‌های پایش (۲ تا ۴ هفته)
۱) تعداد «Alternate page with proper canonical tag» نزولی؛ ۲) «Indexed» صعودی از ۲۱۲؛ ۳) «Crawled – not indexed» نزولی؛ ۴) نرخ رفع خطای سایتمپ `/ai/chat`؛ ۵) Impressions روزانه (پایهٔ فعلی ۲۰–۸۰).

---

## 8) چیزهایی که **عمداً تغییر داده نشدند**
- کانونیکال صفحهٔ اصلی (`index.php:235`) — درست است.
- ساختار URL تمیز، ریدایرکت‌های Nginx/htaccess موجود و منطق دسته‌بندی سلسله‌مراتبی.
- هرگونه مسدودسازی سراسری پارامترها (`Disallow: /*?` یا `IndexIfEmbedded`) — چون آدرس کانونیکال آگهی‌ها (`/listings/view?id=`) را نابود می‌کند.
- ریدایرکت کورکورانه برای ۳۴۵ خطای ۴۰۴ (طبق محدودیت‌های اعلام‌شده، بدون داده).
- GTM/GA4، تنظیمات امنیتی، احراز هویت و منطق معاملات.

---

## 9) فاز ۰ — نتایج بررسی زندهٔ سرور تولید (اجرا شد در 2026-10-25)

> روش: `curl` با UA گوگل‌بات و مرورگر از این ماشین. **هیچ فایلی از پروژه تغییر نکرد.** این بخش، فرضیه‌های بخش‌های ۱ تا ۳ را با دادهٔ واقعی تأیید/رد می‌کند.

### ۹-۱) هدرها و robots.txt
- **`robots.txt` زنده عیناً برابر `robots.txt` مخزن است** (مقایسهٔ خط‌به‌خط انجام شد) → فرضیهٔ «نسخهٔ سرور متفاوت» **رد شد**. پس علت ۵۸۴ «Blocked by robots.txt» فقط از همان قواعد موجود می‌آید: `/auth/`, `/api/`, `/includes/`, `/storage/`, `/vendor/`, `/tools/`, `/seed/`, `/database/`, `/dashboard`, `/messages.php`, `/wallet.php`, `/profile/edit.php`, `/listings/create*`, `/listings/edit.php`, `/listings/my.php`, `/listings/offers.php`, `/listings/bump.php`, `/listings/promote.php`, `/blog/wp-admin/`, `/trades`, `/trades.php`.
- سرور: `nginx`. هدرهای امنیتی فعال (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`).
- **هیچ هدر `X-Robots-Tag` روی هیچ صفحه‌ای وجود ندارد** (تست زندهٔ `/ai/chat` و `/pwa`) → تأیید کامل کد.
- نکتهٔ جانبی: پاسخ صفحات پویا `Cache-Control: no-store, no-cache, must-revalidate` دارد (برای SEO بی‌خطر است؛ اثرش روی کش است، نه خزش).

### ۹-۲) کانونیکال و متای robots (دادهٔ زنده)

| URL | کد | `robots` | `canonical` | قضاوت |
|---|---|---|---|---|
| `/` | 200 | `index, follow` | `https://swaapin.ir/` | ✅ |
| `/?cat=vehicles&city=تهران&sort=new&time_ago=1d` | 200 | `index, follow` | `https://swaapin.ir/` | ✅ **تأیید شد: کانونیکال URLهای پارامتردار درست است** |
| `/listings/` | 200 | `index, follow` | `/listings/` | ✅ |
| `/listings/?page=2` | 200 | `index, follow` | `/listings/` | ⚠️ صفحه‌بندی self-canonical نیست (به صفحهٔ ۱ اشاره می‌کند) |
| `/listings/map?cat=vehicles` | 200 | `index, follow` | `/listings/map` | ⚠️ واریانت فیلترشده ایندکس-مجاز است (موضوع P2-1) |
| `/shops` و `/shops/` | 200 / 200 | `index, follow` | هر دو `/shops` | ⚠️ دو نسخهٔ ۲۰۰ بدون ریدایرکت + canonical بدون اسلش |
| `/ai/chat` و `/ai/chat.php` | 200 / 200 | `noindex, nofollow` | هر دو `/ai/chat` | ⚠️ هر دو ۲۰۰؛ `noindex` تأیید شد |
| `/pwa` | 200 | `noindex, nofollow` | `/pwa` | ⚠️ تأیید موضوع P2-2 |
| `/search/ai` | 200 | `noindex, nofollow` | `/search/ai` | ✅ عمدی |
| `/blog/` | 200 | `index, follow, max-snippet:-1, ...` | `/blog/` | ✅ |
| `/blog/page/2/` | 200 | `noindex, follow` | self | ⚠️ عمدی سمت WP (بازنگری اختیاری) |
| `/blog/?s=test` | 200 | `follow, noindex` | — | ✅ عمدی |
| `/about` | 200 | `index, follow` | `/about` | ✅ |

### ۹-۳) پاسخ‌های HTTP واقعی

| URL | نتیجه | تفسیر |
|---|---|---|
| `/trades` | **302 → `/auth/login?redirect=%2Ftrades`** | منبع بخشی از ۱۰۲ «Page with redirect»؛ در robots هم مسدود است |
| `/listings/view?id=99999999` | 404 | ✅ آگهی ناموجود |
| `/blog/author/admin/` | 404 | آرشیو نویسندهٔ ناموجود (تأیید سمت WP) |
| `/sitemap-main.xml` | 301 → `/sitemap.xml` | ✅ مطابق گزارش |
| `/sitemap.php` | 200 · `application/xml` · ۶۷.۵۸KB | ✅ سالم |
| `/sitemap-pages.php` | 200 · `application/xml` · ۵۶۵B | ✅ سالم |
| `/blog/sitemap.xml` | 301 → `/blog/sitemap_index.xml` | سایتمپ واقعی وبلاگ |
| `/blog/robots.txt` | 404 | — |

### ۹-۴) 🔴 دو خطای قطعیِ جدید که در بخش‌های قبلی فقط فرضیه بودند

**(الف) مرجع سایتمپ شکسته — `/blog/wp-sitemap.xml` → ۴۰۴**

هم `robots.txt:42` و هم `sitemap.xml:14` به `https://swaapin.ir/blog/wp-sitemap.xml` اشاره می‌کنند، ولی این آدرس **۴۰۴** می‌دهد؛ سایتمپ فعال وبلاگ در `/blog/sitemap_index.xml` است (۲۰۰). همچنین گزارش قدیمی‌ترِ خودِ پروژه (`docs/audit/seo-production-ready-v3.txt:32`) این آدرس را `[PASS] ... returns HTTP 200` ثبت کرده بود → **رگرسیون واقعی**.
- اثر: گوگل یک «Sitemap could not be read» می‌گیرد؛ مقالات وبلاگ فقط از طریق `wp-sitemap` داخلی کشف می‌شوند نه از سایتمپ اصلی → این می‌تواند سهمی از «Discovered – currently not indexed» (۱۶۲) را توضیح دهد.
- فایل‌های درگیر: [robots.txt#L42](file:///c:/xampp/htdocs/swaapin/robots.txt#L42)، [sitemap.xml#L14](file:///c:/xampp/htdocs/swaapin/sitemap.xml#L14).
- **اقدام پیشنهادی (P1-3، جدید):** یکسان‌سازی هر دو مرجع به `/blog/sitemap_index.xml` (یا فعال‌کردن wp-sitemap در WP). تا تأیید شما اعمال نمی‌شود.

**(ب) بدنهٔ ۴۰۴ شکسته — صفحهٔ ۴۰۴ واقعی رندر نمی‌شود**

مسیرهای ناشناختهٔ سطح ریشه (مثل `/this-page-does-not-exist-xyz`) توسط [.htaccess#L150](file:///c:/xampp/htdocs/swaapin/.htaccess#L150) به `category/index.php?slug_path=...` فرستاده می‌شوند و در [category/index.php#L56-L59](file:///c:/xampp/htdocs/swaapin/category/index.php#L56-L59) فقط `http_response_code(404); exit;` اجرا می‌شود. نتیجهٔ زنده: **بدنهٔ پاسخ = ۶ بایت `</div>`** در حالی که [404.php](file:///c:/xampp/htdocs/swaapin/404.php) کامل ۴۷.۵KB است و فقط با درخواست مستقیم `/404.php` کار می‌کند.
- اثرِ SEO: **صفر** — کد وضعیت ۴۰۴ برای گوگل کافی است (نه Soft 404).
- اثرِ UX: کاربر صفحهٔ سفیدِ شکسته می‌بیند.
- **اقدام پیشنهادی (P3-4، جدید):** در `category/index.php` در شاخهٔ نبود دسته، `require __DIR__ . '/../404.php'; exit;` جای `http_response_code(404); exit;`.

### ۹-۵) تأیید صریح مواردِ پاسخ‌داده‌شده در بخش ۴
- «URLهای پارامتردار صفحهٔ اصلی → کانونیکال `/`»: **با دادهٔ زنده تأیید شد** ✅
- «`/ai/chat` در Sitemap ولی noindex»: **تأیید زنده شد** (`/ai/chat` واقعاً داخل خروجی `sitemap.php` هست و خود صفحه `noindex, nofollow` می‌دهد) ✅
- «مسدودهای robots در Sitemap نیستند»: همچنان برقرار ✅ (به‌جز تناقض JSON-LD که در بخش ۴-۵ آمد)

### ۹-۶) آنچه فاز ۰ **نتوانست** قطعی کند
- تفکیک URL-level ۵۸۴ «Blocked»، ۳۴۵ «404»، ۱۶۲ «Discovered»، ۸۴ «Crawled»، ۱۷+۵ «Duplicate»، ۱۰۲ «Redirect» — **بدون drilldown قابل اقدام نیست.**
- تنظیمات افزونهٔ SEO و ساختار noindex وبلاگ — نیازمند دسترسی پنل WP.
- مقدار `content_pages.index_status` در دیتابیس — نیازمند کوئری DB.
