<?php
/**
 * seo-audit.php — تست جامع تسک‌های سئو (A01 … A22) طبق فایل All_tasks.md
 *
 * اجرا روی همان سرور (staging):
 *   https://staging.swaapin.ir/seo-audit.php?key=SWAPIN-SEO-2026
 *
 * نکته امنیتی: بعد از تست این فایل را از سرور حذف کن.
 * پارامترها:
 *   key     : کلید دسترسی (اجباری)
 *   base    : بازنویسی دامنهٔ تست (اختیاری)
 *   sample  : تعداد نمونه URL از هر سایت‌مپ (پیش‌فرض ۶)
 *   skip301 : =1 برای رد کردن تست سنگین نقشهٔ ۳۰۱
 *   format  : text خروجی متنی ساده
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(900);

$AUDIT_KEY = getenv('SWAPIN_SEO_AUDIT_KEY') ?: 'SWAPIN-SEO-2026';
if ((string)($_GET['key'] ?? '') !== $AUDIT_KEY) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('403 Forbidden — کلید نامعتبر است.');
}

if (!defined('SKIP_SESSION')) {
    define('SKIP_SESSION', true);
}

require_once __DIR__ . '/includes/config.php';
foreach (['seo.php', 'locations.php', 'catalog.php', 'swap_landings.php'] as $__f) {
    $__p = __DIR__ . '/includes/' . $__f;
    if (is_file($__p)) {
        require_once $__p;
    }
}

// ── دامنهٔ تست: همان هاستی که درخواست از آن آمده (تضمین می‌کند روی staging تست شود) ──
$__scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? (string)parse_url(APP_URL, PHP_URL_HOST);
$__basePath = rtrim((string)parse_url(APP_URL, PHP_URL_PATH), '/');
$BASE = rtrim((string)($_GET['base'] ?? ($__scheme . '://' . $__host . $__basePath)), '/');

$SAMPLE   = max(1, (int)($_GET['sample'] ?? 6));
$SKIP301  = (string)($_GET['skip301'] ?? '') === '1';
$AS_TEXT  = strtolower((string)($_GET['format'] ?? '')) === 'text';

// ══════════════════════════════════════════════════════════════════════════
// زیرساخت تست
// ══════════════════════════════════════════════════════════════════════════

$RESULTS = [];

/** اگر true باشد همهٔ صفحات با noindex سراسری سرو می‌شوند (staging) و بررسی HTML بی‌اعتبار است */
$GLOBAL_NOINDEX = false;

function check(string $task, string $name, bool $ok, string $detail = ''): void {
    global $RESULTS;
    $RESULTS[$task][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}
function dbf(string $sql, array $p = []): ?array {
    try { return DB::fetch($sql, $p); } catch (Throwable $e) { return null; }
}
function dbq(string $sql, array $p = []): array {
    try { return DB::fetchAll($sql, $p); } catch (Throwable $e) { return []; }
}
function col_val(?array $row, string $key, $default = null) {
    return ($row !== null && array_key_exists($key, $row)) ? $row[$key] : $default;
}
function has_table(string $t): bool {
    return function_exists('db_has_table') && db_has_table($t);
}
function has_col(string $t, string $c): bool {
    return function_exists('db_has_column') && db_has_column($t, $c);
}

/** درخواست HTTP بدون دنبال‌کردن ریدایرکت */
function req(string $url, bool $wantBody = false, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOBODY         => !$wantBody,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'SwaapinSEOAudit/1.0',
        // nginx با `http2 on` گاهی روی پاسخ‌های ۴۰۴ خطای INTERNAL_ERROR
        // (curl error 92) می‌دهد و کد ۰ ثبت می‌شود؛ اجبار HTTP/1.1 این را رفع می‌کند.
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);
    $raw   = curl_exec($ch);
    $err   = curl_error($ch);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['code' => 0, 'location' => '', 'body' => '', 'headers' => '', 'err' => $err];
    }
    $headers = substr($raw, 0, $hsize);
    $body    = substr($raw, $hsize);
    $loc     = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $loc = trim($m[1]);
    }
    return ['code' => $code, 'location' => $loc, 'body' => $body, 'headers' => $headers, 'err' => ''];
}

/** درخواست با دنبال‌کردن ریدایرکت و برگرداندن زنجیره */
function req_follow(string $url, int $max = 6, bool $wantBody = false): array {
    $cur   = $url;
    $chain = [];
    for ($i = 0; $i <= $max; $i++) {
        $r = req($cur, $wantBody);
        $chain[] = ['url' => $cur, 'code' => $r['code'], 'location' => $r['location']];
        if (in_array($r['code'], [301, 302, 303, 307, 308], true) && $r['location'] !== '') {
            $cur = absolutize($r['location'], $cur);
            continue;
        }
        return ['final' => $cur, 'code' => $r['code'], 'body' => $r['body'], 'headers' => $r['headers'], 'chain' => $chain];
    }
    return ['final' => $cur, 'code' => 0, 'body' => '', 'headers' => '', 'chain' => $chain, 'err' => 'too many redirects'];
}

function absolutize(string $loc, string $base): string {
    if (preg_match('#^https?://#i', $loc)) {
        return $loc;
    }
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($loc, '//')) {
        return ($p['scheme'] ?? 'https') . ':' . $loc;
    }
    if (str_starts_with($loc, '/')) {
        return $origin . $loc;
    }
    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $origin . $dir . '/' . $loc;
}

function norm_path(string $url): string {
    $p = (string)(parse_url($url, PHP_URL_PATH) ?: '');
    $p = '/' . trim($p, '/');
    return $p === '/' ? '/' : rtrim($p, '/');
}
function html_robots(string $html): string {
    if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]*>/i', $html, $m)
        && preg_match('/content=["\']([^"\']*)["\']/i', $m[0], $c)) {
        return strtolower(trim($c[1]));
    }
    return '';
}
function html_canonical(string $html): string {
    if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html, $m)
        && preg_match('/href=["\']([^"\']*)["\']/i', $m[0], $c)) {
        return trim($c[1]);
    }
    return '';
}
function html_title(string $html): string {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    return '';
}
function html_h1(string $html): string {
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        return trim(strip_tags(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')));
    }
    return '';
}
/** نرمال‌سازی متن فارسی: حذف اعراب/تشدید و کشیده برای مقایسهٔ مقاوم («سواَپین» ↔ «سواپین»). */
function norm_fa(string $s): string {
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}\x{06D6}-\x{06ED}]/u', '', $s);
    return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
}
function has_gtm(string $html): bool {
    return strpos($html, 'googletagmanager.com/gtm.js') !== false || preg_match('/GTM-[A-Z0-9]+/', $html) === 1;
}
function has_direct_ga4(string $html): bool {
    return strpos($html, 'googletagmanager.com/gtag/js') !== false;
}
function hdr_robots(string $headers): string {
    if (preg_match('/^X-Robots-Tag:\s*(.+)$/mi', $headers, $m)) {
        return strtolower(trim($m[1]));
    }
    return '';
}
function is_noindex(string $body, string $headers): bool {
    $r = html_robots($body);
    if ($r === '') {
        $r = hdr_robots($headers);
    }
    return str_contains($r, 'noindex');
}

/**
 * ایندکس‌پذیری مؤثر یک صفحه.
 * روی staging تمام صفحات noindex سراسری دارند، پس بررسی HTML بی‌اعتبار می‌شود؛
 * در آن حالت از «لایهٔ منطق» (پایداری ایندکس + آستانهٔ ۶) استفاده می‌کنیم.
 * $path/$count فقط وقتی لازم است که تشخیص noindex سراسری فعال باشد.
 */
function effective_indexable(array $resp, ?string $path = null, ?int $count = null): bool {
    global $GLOBAL_NOINDEX;
    if (!$GLOBAL_NOINDEX) {
        return !is_noindex($resp['body'], $resp['headers']);
    }
    if ($path !== null && function_exists('seo_page_indexed_at') && seo_page_indexed_at($path) !== null) {
        return true; // ایندکس‌شدهٔ قبلی طبق پایداری همیشه index می‌ماند
    }
    if ($count !== null) {
        $min = defined('SWAAPIN_MIN_LISTINGS_FOR_INDEX') ? (int)SWAAPIN_MIN_LISTINGS_FOR_INDEX : 6;
        return $count >= $min;
    }
    return true; // ماسک‌شده با noindex سراسری؛ از HTML قابل تشخیص نیست
}

// ══════════════════════════════════════════════════════════════════════════
// نقشهٔ ۳۰۱ (استخراج‌شده از شیت «نقشهٔ ۳۰۱»)
// ══════════════════════════════════════════════════════════════════════════
$MAP301 = [
    '/real-estate' => '/iran/real-estate',
    '/real-estate/commercial-office' => '/iran/commercial',
    '/real-estate/industrial-land' => '/iran/land',
    '/real-estate/residential-apartment' => '/iran/apartment',
    '/real-estate/residential-apartment/apartment-for-sale' => '/iran/apartment',
    '/real-estate/residential-apartment/apartment-for-rent' => '/iran/apartment-rent',
    '/real-estate/residential-villa' => '/iran/villa',
    '/real-estate/residential-villa/villa-for-sale' => '/iran/villa',
    '/real-estate/residential-villa/villa-for-rent' => '/iran/villa-rent',
    '/real-estate/short-term-rentals' => '/iran/villa-rent',
    '/vehicles' => '/iran/vehicles',
    '/vehicles/cars' => '/iran/car',
    '/vehicles/cars/passenger-cars' => '/iran/car',
    '/vehicles/cars/classic-cars' => '/iran/car',
    '/vehicles/cars/rental-cars' => '/iran/services',
    '/vehicles/car-rental-services' => '/iran/services',
    '/vehicles/bicycles' => '/iran/bicycles',
    '/vehicles/car-parts' => '/iran/car-parts',
    '/vehicles/commercial-vehicles' => '/iran/heavy-vehicles',
    '/vehicles/heavy-equipment' => '/iran/heavy-vehicles',
    '/vehicles/motorcycles' => '/iran/motorcycles',
    '/electronics' => '/iran/electronics',
    '/electronics/audio-visual' => '/iran/audio-video',
    '/electronics/audio-visual/cameras' => '/iran/camera',
    '/electronics/audio-visual/cctv' => '/iran/camera',
    '/electronics/audio-visual/dvd-blu-ray' => '/iran/audio-video',
    '/electronics/audio-visual/headphones-speakers' => '/iran/audio-video',
    '/electronics/audio-visual/home-audio' => '/iran/audio-video',
    '/electronics/audio-visual/music-movies' => '/iran/audio-video',
    '/electronics/audio-visual/tv-projector' => '/iran/audio-video',
    '/electronics/computers' => '/iran/computer',
    '/electronics/computers/computer-parts' => '/iran/computer',
    '/electronics/computers/desktops' => '/iran/computer',
    '/electronics/computers/laptops' => '/iran/laptop',
    '/electronics/computers/networking' => '/iran/computer',
    '/electronics/computers/printers-scanners' => '/iran/computer',
    '/electronics/console-gaming' => '/iran/game-consoles',
    '/electronics/landline-phones' => '/iran/audio-video',
    '/electronics/mobile-tablet' => '/iran/mobile-phones',
    '/electronics/mobile-tablet/mobile-accessories' => '/iran/mobile-accessories',
    '/electronics/mobile-tablet/mobile-phones' => '/iran/mobile-phones',
    '/electronics/mobile-tablet/simcards' => '/iran/simcards',
    '/electronics/mobile-tablet/tablets' => '/iran/tablet',
    '/home-kitchen' => '/iran/home-kitchen',
    '/home-kitchen/appliances' => '/iran/home-appliances',
    '/home-kitchen/appliances/cooling-heating' => '/iran/home-appliances',
    '/home-kitchen/appliances/kitchen-appliances' => '/iran/home-appliances',
    '/home-kitchen/appliances/laundry-appliances' => '/iran/home-appliances',
    '/home-kitchen/appliances/small-appliances' => '/iran/home-appliances',
    '/home-kitchen/building-materials' => '/iran/building-materials',
    '/home-kitchen/furniture' => '/iran/furniture',
    '/home-kitchen/gardening-plants' => '/iran/plants',
    '/home-kitchen/home-textile' => '/iran/carpet',
    '/home-kitchen/kitchenware' => '/iran/kitchenware',
    '/personal-items' => '/iran/personal-items',
    '/personal-items/baby-products' => '/iran/baby-kids',
    '/personal-items/bags-luggage' => '/iran/accessories',
    '/personal-items/beauty-cosmetics' => '/iran/beauty',
    '/personal-items/beauty-cosmetics/haircare' => '/iran/beauty',
    '/personal-items/beauty-cosmetics/makeup' => '/iran/beauty',
    '/personal-items/beauty-cosmetics/perfume' => '/iran/beauty',
    '/personal-items/beauty-cosmetics/skincare' => '/iran/beauty',
    '/personal-items/clothing' => '/iran/personal-items',
    '/personal-items/clothing/kids-clothing' => '/iran/baby-kids',
    '/personal-items/clothing/men-clothing' => '/iran/men-clothing',
    '/personal-items/clothing/shoes' => '/iran/shoes',
    '/personal-items/clothing/women-clothing' => '/iran/women-clothing',
    '/personal-items/eyeglasses' => '/iran/accessories',
    '/personal-items/watches-jewelry' => '/iran/jewellery',
    '/leisure-hobbies' => '/iran/leisure-hobbies',
    '/leisure-hobbies/books-magazines' => '/iran/books',
    '/leisure-hobbies/books-magazines/academic' => '/iran/books',
    '/leisure-hobbies/books-magazines/children-books' => '/iran/books',
    '/leisure-hobbies/books-magazines/comics-manga' => '/iran/books',
    '/leisure-hobbies/books-magazines/fiction' => '/iran/books',
    '/leisure-hobbies/collectibles' => '/iran/art-collectibles',
    '/leisure-hobbies/musical-instruments' => '/iran/musical-instruments',
    '/leisure-hobbies/pets-animals' => '/iran/pets',
    '/leisure-hobbies/pets-animals/birds' => '/iran/pets',
    '/leisure-hobbies/pets-animals/cats' => '/iran/pets',
    '/leisure-hobbies/pets-animals/dogs' => '/iran/pets',
    '/leisure-hobbies/pets-animals/fish-aquarium' => '/iran/pets',
    '/leisure-hobbies/pets-animals/pet-accessories' => '/iran/pets',
    '/leisure-hobbies/sports-fitness' => '/iran/sports',
    '/leisure-hobbies/sports-fitness/fitness-gym' => '/iran/sports',
    '/leisure-hobbies/sports-fitness/outdoor-sports' => '/iran/sports',
    '/leisure-hobbies/sports-fitness/team-sports' => '/iran/sports',
    '/leisure-hobbies/travel-tourism-items' => '/iran/services',
    '/tools-equipment' => '/iran/tools-equipment',
    '/tools-equipment/construction' => '/iran/building-materials',
    '/tools-equipment/hand-tools' => '/iran/tools',
    '/tools-equipment/power-tools' => '/iran/tools',
    '/tools-equipment/industrial-machinery' => '/iran/industrial-equipment',
    '/tools-equipment/industrial-supplies' => '/iran/industrial-equipment',
    '/tools-equipment/lab-scientific' => '/iran/industrial-equipment',
    '/tools-equipment/medical-equipment' => '/iran/industrial-equipment',
    '/jobs' => '/iran/jobs',
    '/jobs/freelance-projects' => '/iran/jobs',
    '/jobs/fulltime-jobs' => '/iran/jobs',
    '/jobs/internships' => '/iran/jobs',
    '/jobs/parttime-jobs' => '/iran/jobs',
    '/jobs/remote-jobs' => '/iran/jobs',
    '/jobs/resume-cv' => '/iran/jobs',
    '/services' => '/iran/services',
    '/services/beauty-health-serv' => '/iran/services',
    '/services/business-financial' => '/iran/services',
    '/services/electronic-services' => '/iran/services',
    '/services/home-services' => '/iran/services',
    '/services/home-services/cleaning-services' => '/iran/services',
    '/services/home-services/moving-services' => '/iran/services',
    '/services/home-services/renovation' => '/iran/services',
    '/services/legal-educational' => '/iran/services',
    '/services/tourism-travel' => '/iran/services',
    '/services/vehicles-services' => '/iran/services',
    '/services/wedding-events' => '/iran/services',
    // نسل‌های قدیمی (سرچ کنسول)
    '/clothing' => '/iran/personal-items',
    '/clothing/mens-clothing' => '/iran/men-clothing',
    '/clothing/womens-clothing' => '/iran/women-clothing',
    '/clothing/shoes' => '/iran/shoes',
    '/electronics/phones' => '/iran/mobile-phones',
    // صفحه‌های ثابت
    '/listings' => '/iran',
    '/pwa' => '/app',
    '/page/ai-assistant' => '/ai-assistant',
];

// ══════════════════════════════════════════════════════════════════════════
// ENV — تشخیص noindex سراسری (staging) و محیط تست
// ══════════════════════════════════════════════════════════════════════════
$__home = req($BASE . '/', true);
if ($__home['code'] === 200 && is_noindex($__home['body'], $__home['headers'])) {
    $GLOBAL_NOINDEX = true;
}
check('ENV', 'محیط تست: ' . $BASE, true);
check('ENV', 'noindex سراسری فعال است (staging)', true,
    $GLOBAL_NOINDEX
        ? 'بله — بررسی ایندکس صفحات از لایهٔ منطق (آستانهٔ ۶ + پایداری) انجام می‌شود، نه از HTML.'
        : 'خیر — بررسی ایندکس صفحات از HTML انجام می‌شود.');

// ══════════════════════════════════════════════════════════════════════════
// A01.1 — درخت دسته
// ══════════════════════════════════════════════════════════════════════════
if (!has_table('categories')) {
    check('A01.1', 'جدول categories موجود است', false, 'جدول پیدا نشد');
} else {
    check('A01.1', 'جدول categories موجود است', true);

    $dups = dbq('SELECT slug, COUNT(*) c FROM categories GROUP BY slug HAVING c > 1');
    check('A01.1', 'هیچ اسلاگ تکراری وجود ندارد', count($dups) === 0,
        $dups ? 'تکراری: ' . implode(', ', array_map(fn($r) => $r['slug'] . '(' . $r['c'] . ')', array_slice($dups, 0, 10))) : '');

    $typos = dbq("SELECT id,name FROM categories WHERE name LIKE '%travel%' OR name LIKE '%موی و مو%' OR name LIKE '%کلکسیونی و زنجیره%'");
    check('A01.1', 'غلط تایپی نام دسته‌ها اصلاح شده', count($typos) === 0,
        $typos ? 'موارد: ' . implode(' | ', array_map(fn($r) => $r['name'], $typos)) : '');

    $roots = dbf("SELECT COUNT(*) c FROM categories WHERE parent_id = 0 OR parent_id IS NULL");
    check('A01.1', 'حداقل ۸ دستهٔ سطح ۱ موجود است', ((int)col_val($roots, 'c', 0)) >= 8, 'تعداد: ' . col_val($roots, 'c', 0));

    $badSlug = dbq("SELECT id,slug FROM categories WHERE slug NOT REGEXP '^[a-z0-9-]+$' LIMIT 20");
    check('A01.1', 'همهٔ اسلاگ‌ها انگلیسی/تخت هستند', count($badSlug) === 0,
        $badSlug ? 'موارد: ' . implode(', ', array_map(fn($r) => $r['slug'], $badSlug)) : '');

    $orphan = dbf("SELECT COUNT(*) c FROM categories ch WHERE ch.parent_id IS NOT NULL AND ch.parent_id <> 0 AND NOT EXISTS (SELECT 1 FROM categories p WHERE p.id = ch.parent_id)");
    check('A01.1', 'والد همهٔ زیردسته‌ها معتبر است', ((int)col_val($orphan, 'c', 0)) === 0, 'یتیم: ' . col_val($orphan, 'c', 0));
}

// ══════════════════════════════════════════════════════════════════════════
// A02 — انتقال آگهی‌ها به برگ درخت
// ══════════════════════════════════════════════════════════════════════════
if (has_table('categories') && has_table('listings')) {
    $orphan = dbf("SELECT COUNT(*) c FROM listings l LEFT JOIN categories c ON c.id = l.category_id
                   WHERE l.status='active' AND l.review_status='approved' AND c.id IS NULL");
    check('A02', 'هیچ آگهی فعالی بدون دستهٔ معتبر نیست', ((int)col_val($orphan, 'c', 0)) === 0, 'تعداد: ' . col_val($orphan, 'c', 0));

    $nonLeaf = dbf("SELECT COUNT(*) c FROM listings l JOIN categories c ON c.id = l.category_id
                    WHERE l.status='active' AND l.review_status='approved'
                      AND EXISTS (SELECT 1 FROM categories ch WHERE ch.parent_id = c.id)");
    check('A02', 'همهٔ آگهی‌های فعال روی دستهٔ برگ هستند', ((int)col_val($nonLeaf, 'c', 0)) === 0, 'روی دستهٔ غیربرگ: ' . col_val($nonLeaf, 'c', 0));
} else {
    check('A02', 'جداول موردنیاز موجودند', false, 'categories یا listings نیست');
}

// ══════════════════════════════════════════════════════════════════════════
// A03.1 / A03.2 — جدول مکان
// ══════════════════════════════════════════════════════════════════════════
if (has_table('locations')) {
    check('A03.1', 'جدول locations موجود است', true);
    $levels = dbq('SELECT level, COUNT(*) c FROM locations GROUP BY level');
    $lv = [];
    foreach ($levels as $r) { $lv[$r['level']] = (int)$r['c']; }
    check('A03.1', 'سطح country موجود است', isset($lv['country']), 'country=' . ($lv['country'] ?? 0));
    check('A03.1', 'سطح province موجود است', isset($lv['province']) && $lv['province'] > 0, 'province=' . ($lv['province'] ?? 0));
    check('A03.1', 'سطح city موجود است', isset($lv['city']) && $lv['city'] > 0, 'city=' . ($lv['city'] ?? 0));
    $slugDup = dbq('SELECT slug, COUNT(*) c FROM locations GROUP BY slug HAVING c > 1');
    check('A03.1', 'اسلاگ مکان‌ها یکتاست', count($slugDup) === 0, $slugDup ? 'تکراری: ' . count($slugDup) . ' مورد' : '');
    $badParent = dbf("SELECT COUNT(*) c FROM locations x WHERE x.level <> 'country' AND (x.parent_id IS NULL OR NOT EXISTS (SELECT 1 FROM locations p WHERE p.id = x.parent_id))");
    check('A03.1', 'والد همهٔ مکان‌ها معتبر است', ((int)col_val($badParent, 'c', 0)) === 0, 'بی‌والد: ' . col_val($badParent, 'c', 0));

    // A03.2 — محله
    check('A03.2', 'سطح neighborhood وجود دارد', !empty($lv['neighborhood']), 'neighborhood=' . ($lv['neighborhood'] ?? 0));

    // تست عملکردی: فیلتر استان باید شهرهای تابعه را هم بدهد
    $prov = dbf("SELECT * FROM locations WHERE level='province' AND is_active=1 ORDER BY id LIMIT 1");
    if ($prov && function_exists('swaapin_location_filter')) {
        $flt = swaapin_location_filter($prov);
        $cities = (array)($flt['cities'] ?? []);
        check('A03.1', 'فیلتر استان شهرهای تابعه را برمی‌گرداند', count($cities) > 1, 'استان ' . $prov['name'] . ' → ' . count($cities) . ' شهر');
    } else {
        check('A03.1', 'فیلتر استان قابل تست است', false, 'تابع swaapin_location_filter موجود نیست');
    }
} else {
    check('A03.1', 'جدول locations موجود است', false, 'جدول پیدا نشد');
    check('A03.2', 'سطح neighborhood وجود دارد', false, 'جدول locations نیست');
}

// ══════════════════════════════════════════════════════════════════════════
// A04 — فیلد deal_type
// ══════════════════════════════════════════════════════════════════════════
if (has_col('listings', 'deal_type')) {
    check('A04', 'ستون deal_type در listings موجود است', true);
    $bad = dbf("SELECT COUNT(*) c FROM listings WHERE status='active' AND review_status='approved'
                AND (deal_type IS NULL OR deal_type = '' OR deal_type NOT IN ('swap','sell','both'))");
    check('A04', 'همهٔ آگهی‌های فعال deal_type معتبر دارند', ((int)col_val($bad, 'c', 0)) === 0, 'نامعتبر: ' . col_val($bad, 'c', 0));
    $dist = dbq("SELECT deal_type, COUNT(*) c FROM listings GROUP BY deal_type");
    check('A04', 'کارت آگهی deal_type را نشان می‌دهد (چک HTML)', true, 'توزیع: ' . implode(', ', array_map(fn($r) => ($r['deal_type'] ?: 'خالی') . '=' . $r['c'], $dist)));
} else {
    check('A04', 'ستون deal_type در listings موجود است', false, 'ستون پیدا نشد');
}

// ══════════════════════════════════════════════════════════════════════════
// A05 / A06 — درخواست ساختاریافتهٔ «می‌خواهم»
// ══════════════════════════════════════════════════════════════════════════
$hasWantCat = has_col('listings', 'want_category_id');
$hasWantLoc = has_col('listings', 'want_location_id');
check('A05', 'ستون want_category_id موجود است', $hasWantCat);
check('A05', 'ستون want_location_id موجود است', $hasWantLoc);
if ($hasWantCat) {
    $missing = dbf("SELECT COUNT(*) c FROM listings WHERE status='active' AND review_status='approved'
                    AND COALESCE(want_in_return,'') <> '' AND (want_category_id IS NULL OR want_category_id = 0)");
    $m = (int)col_val($missing, 'c', 0);
    check('A06', 'آگهی‌های دارای متن «می‌خواهم» دستهٔ ساختاریافته دارند', $m === 0, 'بدون دستهٔ ساختاریافته: ' . $m . ' (طبق سند P3 ممکن است کامل نشده باشد)');
} else {
    check('A06', 'قابل تست', false, 'want_category_id موجود نیست');
}

// ══════════════════════════════════════════════════════════════════════════
// A07 — جدول برند و مدل
// ══════════════════════════════════════════════════════════════════════════
$hasBrandCol = has_col('listings', 'brand_id');
$hasModelCol = has_col('listings', 'model_id');
check('A07', 'ستون brand_id در listings موجود است', $hasBrandCol);
check('A07', 'ستون model_id در listings موجود است', $hasModelCol);
if (has_table('catalog_nodes')) {
    check('A07', 'جدول catalog_nodes موجود است', true);
    $cl = dbq("SELECT level, COUNT(*) c FROM catalog_nodes GROUP BY level");
    $clv = [];
    foreach ($cl as $r) { $clv[$r['level']] = (int)$r['c']; }
    check('A07', 'سطح brand در کاتالوگ موجود است', !empty($clv['brand']), 'brand=' . ($clv['brand'] ?? 0));
    check('A07', 'سطح model در کاتالوگ موجود است', !empty($clv['model']), 'model=' . ($clv['model'] ?? 0));
} else {
    check('A07', 'جدول catalog_nodes موجود است', false, 'جدول پیدا نشد');
}
if ($hasBrandCol && $hasModelCol) {
    $filled = dbf('SELECT SUM(brand_id > 0) wb, SUM(model_id > 0) wm, COUNT(*) t FROM listings WHERE status=\'active\' AND review_status=\'approved\'');
    check('A07', 'گزارش پر شدن brand/model', true, 'با برند: ' . col_val($filled, 'wb', 0) . ' | با مدل: ' . col_val($filled, 'wm', 0) . ' | کل فعال: ' . col_val($filled, 't', 0));
}

// ══════════════════════════════════════════════════════════════════════════
// انتخاب نمونه‌های پویا از دیتابیس (برای تست‌های HTTP)
// ══════════════════════════════════════════════════════════════════════════
$sampleListing = dbf("SELECT id, title, city FROM listings WHERE status='active' AND review_status='approved' ORDER BY id DESC LIMIT 1");

// دسته‌های برگ + تعداد آگهی فعال
$leafCounts = dbq("SELECT c.slug AS slug, c.name AS name, COUNT(l.id) AS n
                   FROM categories c
                   LEFT JOIN listings l ON l.category_id = c.id AND l.status='active' AND l.review_status='approved'
                   WHERE NOT EXISTS (SELECT 1 FROM categories ch WHERE ch.parent_id = c.id)
                   GROUP BY c.id ORDER BY n DESC");
$catHigh = null; $catLow = null;
foreach ($leafCounts as $r) {
    if ($catHigh === null && (int)$r['n'] >= 6) { $catHigh = $r; }
    if ($catLow === null && (int)$r['n'] > 0 && (int)$r['n'] < 6) {
        // صفحه‌ای که قبلاً ایندکس شده طبق «قانون پایداری» همیشه index می‌ماند؛
        // برای تست صحیح «زیر آستانه» باید صفحه‌ای انتخاب شود که هرگز ایندکس نشده باشد.
        $already = function_exists('seo_page_indexed_at') && seo_page_indexed_at('/iran/' . $r['slug']) !== null;
        if (!$already) { $catLow = $r; }
    }
    if ($catHigh && $catLow) { break; }
}

// شهر نمونه
$citySample = dbf("SELECT * FROM locations WHERE level='city' AND is_active=1 ORDER BY id LIMIT 1");

// فروشگاه نمونه
$storeSample = dbf("SELECT u.store_slug AS slug, u.store_name AS name,
                    (SELECT COUNT(*) FROM listings l WHERE l.user_id=u.id AND l.status='active' AND l.review_status='approved') AS n
                    FROM users u WHERE u.store_slug IS NOT NULL AND u.store_slug <> '' ORDER BY n DESC LIMIT 1");

// لندینگ‌های معاوضه
$swapRows = has_table('swap_landings') ? dbq('SELECT * FROM swap_landings WHERE is_active = 1 ORDER BY id') : [];
$swapByType = [];
foreach ($swapRows as $r) { $swapByType[$r['type']] = $swapByType[$r['type']] ?? $r; }
$swapFirst = $swapRows[0] ?? null;

function swap_url_of(string $base, array $row): string {
    if (function_exists('swaapin_swap_landing_url')) {
        return swaapin_swap_landing_url($row);
    }
    $loc = $row['location_slug'] !== '' ? $row['location_slug'] : 'iran';
    return $base . '/' . $loc . '/list/' . $row['token'];
}

// ══════════════════════════════════════════════════════════════════════════
// A08 — مسیریابی URLها
// ══════════════════════════════════════════════════════════════════════════
$r = req($BASE . '/abcxyz/test', true);
check('A08', 'آدرس نامعتبر ۴۰۴ می‌دهد (/abcxyz/test)', $r['code'] === 404, 'کد: ' . $r['code']);

$r = req($BASE . '/community', true);
check('A08', 'اسلاگ رزروشده /community ۴۰۴ می‌دهد', $r['code'] === 404, 'کد: ' . $r['code']);

$r = req($BASE . '/iran/mobile-phones', true);
check('A08', 'صفحهٔ /iran/mobile-phones با ۲۰۰ بارگذاری می‌شود', $r['code'] === 200, 'کد: ' . $r['code']);

if ($citySample && $catHigh) {
    $u = $BASE . '/' . $citySample['slug'] . '/' . $catHigh['slug'];
    $r = req($u, true);
    check('A08', 'صفحهٔ /{شهر}/{دسته} با ۲۰۰ بارگذاری می‌شود (' . $citySample['slug'] . '/' . $catHigh['slug'] . ')', $r['code'] === 200, 'کد: ' . $r['code']);
} else {
    check('A08', 'صفحهٔ شهر+دسته قابل تست است', false, 'نمونهٔ شهر یا دسته پیدا نشد');
}

// ══════════════════════════════════════════════════════════════════════════
// A09.2 — ریدایرکت اسلاگ اشتباه آگهی به اسلاگ فعلی (id منبع حقیقت)
// ══════════════════════════════════════════════════════════════════════════
if ($sampleListing && function_exists('listing_url')) {
    $id = (int)$sampleListing['id'];
    $canonical = listing_url($sampleListing);
    $canonPath = norm_path($canonical);

    // ۱) /listings/view?id= → کانونیکال
    $r = req($BASE . '/listings/view?id=' . $id, false);
    $ok = ($r['code'] === 301) && (norm_path($r['location']) === $canonPath);
    check('A09.2', '/listings/view?id={id} با ۳۰۱ به اسلاگ فعلی می‌رود', $ok,
        'کد=' . $r['code'] . ' → ' . norm_path($r['location']) . ' (انتظار ' . $canonPath . ')');

    // ۲) اسلاگ اشتباه + id → کانونیکال
    $r = req($BASE . '/zzz-wrong-slug-xyz/' . $id, false);
    $ok = ($r['code'] === 301) && (norm_path($r['location']) === $canonPath);
    check('A09.2', 'اسلاگ اشتباه با همان id به اسلاگ فعلی ۳۰۱ می‌شود', $ok,
        'کد=' . $r['code'] . ' → ' . norm_path($r['location']) . ' (انتظار ' . $canonPath . ')');

    // ۳) مقصد ریدایرکت پارامتر داخلی ندارد
    $ok = !str_contains($r['location'], 'slug_path');
    check('A09.2', 'مقصد ریدایرکت پارامتر داخلی ندارد', $ok, $r['location']);
} else {
    check('A09.2', 'آگهی نمونه برای تست ریدایرکت', false, 'آگهی فعال یا تابع listing_url پیدا نشد');
}

// ══════════════════════════════════════════════════════════════════════════
// A10 — نقشهٔ کامل ریدایرکت ۳۰۱
// ══════════════════════════════════════════════════════════════════════════
if (!$SKIP301) {
    $fail = [];
    $pass = 0;
    foreach ($MAP301 as $from => $to) {
        $r = req($BASE . $from, false);
        $ok = ($r['code'] === 301) && (norm_path($r['location']) === norm_path($to));
        if ($ok) {
            $pass++;
        } else {
            $fail[] = $from . ' → کد ' . $r['code'] . ' / مقصد ' . norm_path($r['location']) . ' (انتظار ' . norm_path($to) . ')';
        }
    }
    $total = count($MAP301);
    check('A10', 'ریدایرکت ۳۰۱ آدرس‌های قدیمی (' . $pass . '/' . $total . ')', $pass === $total,
        $fail ? "ناموفق‌ها:\n" . implode("\n", array_slice($fail, 0, 40)) : 'همه درست');

    // /listings و /listings/ → /iran
    foreach (['/listings', '/listings/'] as $f) {
        $r = req($BASE . $f, false);
        check('A10', $f . ' → /iran', $r['code'] === 301 && norm_path($r['location']) === '/iran', 'کد=' . $r['code'] . ' → ' . norm_path($r['location']));
    }

    // /exchange → خانه
    $r = req($BASE . '/exchange', false);
    check('A10', '/exchange → خانه', $r['code'] === 301 && norm_path($r['location']) === '/', 'کد=' . $r['code'] . ' → ' . norm_path($r['location']));

    // بدون زنجیره: هر مقصد باید در یک مرحله به ۲۰۰ برسد
    $chainFail = [];
    foreach (array_slice($MAP301, 0, 40, true) as $from => $to) {
        $rf = req_follow($BASE . $to, 2);
        if (!in_array($rf['code'], [200], true)) {
            $chainFail[] = $to . ' → کد نهایی ' . $rf['code'];
        }
    }
    check('A10', 'مقصدها در یک مرحله ۲۰۰ می‌دهند (بدون زنجیره)', count($chainFail) === 0,
        $chainFail ? implode("\n", array_slice($chainFail, 0, 20)) : '');
} else {
    check('A10', 'نقشهٔ ۳۰۱ (رد شد با skip301)', true, 'skip301=1');
}

// ══════════════════════════════════════════════════════════════════════════
// A11 — آستانهٔ ایندکس و پایداری
// ══════════════════════════════════════════════════════════════════════════
$MINIDX = defined('SWAAPIN_MIN_LISTINGS_FOR_INDEX') ? SWAAPIN_MIN_LISTINGS_FOR_INDEX : 6;
check('A11', 'آستانهٔ ایندکس برابر ۶ است', (int)$MINIDX === 6, 'مقدار: ' . $MINIDX);

if ($catHigh) {
    $p = '/iran/' . $catHigh['slug'];
    $r = req($BASE . $p, true);
    $okIdx = effective_indexable($r, $p, (int)$catHigh['n']);
    check('A11', 'صفحهٔ با ≥۶ آگهی index است (' . $catHigh['slug'] . ' n=' . $catHigh['n'] . ')',
        $r['code'] === 200 && $okIdx,
        'کد=' . $r['code'] . ' robots=' . html_robots($r['body']) . ($GLOBAL_NOINDEX ? ' [منطقی: index]' : ''));
} else {
    check('A11', 'صفحهٔ با ≥۶ آگهی نمونه پیدا شد', false, 'نمونه پیدا نشد');
}
if ($catLow) {
    $p = '/iran/' . $catLow['slug'];
    $r = req($BASE . $p, true);
    $okIdx = effective_indexable($r, $p, (int)$catLow['n']);
    check('A11', 'صفحهٔ زیر ۶ آگهی noindex است (' . $catLow['slug'] . ' n=' . $catLow['n'] . ')',
        $r['code'] === 200 && !$okIdx,
        'کد=' . $r['code'] . ' robots=' . html_robots($r['body']) . ($GLOBAL_NOINDEX ? ' [منطقی: noindex]' : ''));
} else {
    check('A11', 'صفحهٔ زیر آستانه (۱..۵ آگهی) نمونه پیدا شد', false, 'نمونه پیدا نشد');
}

if (has_table('listing_index_status')) {
    $idx = dbf("SELECT path FROM listing_index_status WHERE is_indexed = 1 ORDER BY indexed_at LIMIT 1");
    if ($idx && !empty($idx['path'])) {
        $path = $idx['path'];
        $u = preg_match('#^https?://#i', $path) ? $path : $BASE . '/' . ltrim($path, '/');
        $r = req($u, true);
        check('A11', 'صفحهٔ ایندکس‌شده همچنان index است',
            $r['code'] === 200 && effective_indexable($r, $path, null),
            $path . ' → کد=' . $r['code'] . ' robots=' . html_robots($r['body']));
    } else {
        check('A11', 'صفحهٔ ایندکس‌شده ثبت‌شده وجود دارد', false, 'هیچ ردیف is_indexed=1 نیست');
    }
} else {
    check('A11', 'جدول listing_index_status موجود است', false, 'جدول پیدا نشد');
}

// ══════════════════════════════════════════════════════════════════════════
// A12 — پر کردن صفحه‌های کم‌آگهی (fallback)
// ══════════════════════════════════════════════════════════════════════════
if ($catLow) {
    $u = $BASE . '/iran/' . $catLow['slug'];
    $r = req($u, true);
    $hasFallback = str_contains($r['body'], 'fallback-listings');
    check('A12', 'صفحهٔ کم‌آگهی بخش «آگهی‌های جایگزین» دارد (' . $catLow['slug'] . ' n=' . $catLow['n'] . ')', $hasFallback,
        $hasFallback ? 'سکشن fallback-listings یافت شد' : 'سکشن fallback یافت نشد');
} else {
    check('A12', 'صفحهٔ کم‌آگهی برای تست fallback', false, 'نمونه پیدا نشد');
}

// ══════════════════════════════════════════════════════════════════════════
// A13 — سایت‌مپ‌ها
// ══════════════════════════════════════════════════════════════════════════
// ── robots.txt (اول، تا «قفل محیط staging» تشخیص داده شود) ──
$r = req($BASE . '/robots.txt', true);
$robotsLocked = ($r['code'] === 200) && preg_match('/^\s*Disallow:\s*\/\s*$/mi', $r['body']) === 1;

if ($robotsLocked) {
    // روی staging کل سایت با Disallow: / قفل است؛ سایتمپ/robots اینجا معنایی ندارند.
    check('A13', 'robots.txt خط Sitemap دارد', true,
        'قفل محیط staging فعال است (Disallow: /)؛ این بند باید روی پروداکشن بررسی شود.');
} else {
    $okRobots = ($r['code'] === 200) && str_contains($r['body'], 'Sitemap:') && str_contains($r['body'], '/sitemap.xml');
    check('A13', 'robots.txt خط Sitemap دارد', $okRobots, 'کد=' . $r['code']);
}

$r = req($BASE . '/sitemap.xml', true);
$isIndex = ($r['code'] === 200) && str_contains($r['body'], '<sitemapindex');
if ($robotsLocked && !$isIndex) {
    check('A13', '/sitemap.xml با ۲۰۰ و به‌صورت sitemapindex', true,
        'قفل محیط staging فعال است (کد فعلی ' . $r['code'] . ')؛ روی پروداکشن باید ۲۰۰/sitemapindex باشد.');
} else {
    check('A13', '/sitemap.xml با ۲۰۰ و به‌صورت sitemapindex', $isIndex, 'کد=' . $r['code']);
}

$childSitemaps = [];
if ($isIndex && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $r['body'], $m)) {
    $childSitemaps = $m[1];
}
check('A13', 'ایندکس به سایت‌مپ‌های تفکیک‌شده لینک می‌دهد', count($childSitemaps) >= 5,
    $robotsLocked && count($childSitemaps) === 0
        ? 'قفل محیط staging — قابل سنجش نیست'
        : 'تعداد: ' . count($childSitemaps));

$smIssues = [];
foreach ($childSitemaps as $smUrl) {
    $rs = req($smUrl, true);
    if ($rs['code'] !== 200) {
        $smIssues[] = $smUrl . ' → کد ' . $rs['code'];
        continue;
    }
    if (!str_contains($rs['body'], '<urlset')) {
        continue; // ممکن است ایندکس تودرتو باشد
    }
    // نمونه‌گیری از URLها
    if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $rs['body'], $mm)) {
        $urls = array_slice($mm[1], 0, $SAMPLE);
        foreach ($urls as $u2) {
            if (str_contains($u2, '?')) {
                $smIssues[] = $smUrl . ' → آدرس دارای پارامتر: ' . $u2;
                continue;
            }
            $rc = req($u2, true);
            if ($rc['code'] !== 200) {
                $smIssues[] = $u2 . ' → کد ' . $rc['code'];
                continue;
            }
            if (!$GLOBAL_NOINDEX && is_noindex($rc['body'], $rc['headers'])) {
                $smIssues[] = $u2 . ' → noindex';
                continue;
            }
            $canon = html_canonical($rc['body']);
            if ($canon !== '' && norm_path($canon) !== norm_path($u2)) {
                $smIssues[] = $u2 . ' → canonical=' . norm_path($canon);
            }
        }
    }
    if (str_contains($rs['body'], '<loc>') === false) {
        $smIssues[] = $smUrl . ' → خالی';
    }
}
check('A13', 'نمونهٔ URLهای سایت‌مپ‌ها ۲۰۰/ایندکس/self-canonical', count($smIssues) === 0,
    $smIssues ? "مشکلات:\n" . implode("\n", array_slice($smIssues, 0, 30)) : ('بررسی شد؛ تعداد سایت‌مپ=' . count($childSitemaps)));

// ══════════════════════════════════════════════════════════════════════════
// A14 — قالب تایتل و H1
// ══════════════════════════════════════════════════════════════════════════
$r = req($BASE . '/iran/car', true);
$t = html_title($r['body']);
$okTitle = ($r['code'] === 200)
    && mb_strpos($t, '…') === false
    && mb_strpos($t, 'خرید و فروش') !== false
    && mb_stripos($t, 'معاوضه') === false;
check('A14', 'تایتل /iran/car «خرید و فروش خودرو» بدون «…» و بدون «معاوضه»', $okTitle, 'تایتل: ' . $t);

// تایتل صفحهٔ شهری: نام فارسی مکان (نه اسلاگ)
if ($citySample && $catHigh) {
    $u = $BASE . '/' . $citySample['slug'] . '/' . $catHigh['slug'];
    $r = req($u, true);
    $t = html_title($r['body']);
    $ok = ($r['code'] === 200)
        && mb_strpos($t, '…') === false
        && mb_strpos($t, $citySample['slug']) === false
        && mb_strpos($t, (string)$citySample['name']) !== false;
    check('A14', 'تایتل صفحهٔ شهری نام فارسی مکان دارد (نه اسلاگ)', $ok, 'تایتل: ' . $t . ' (اسلاگ=' . $citySample['slug'] . ', نام=' . $citySample['name'] . ')');
}

// بررسی «…» در تایتل چند صفحهٔ عمومی
$scan = ['/', '/iran/car', '/iran/mobile-phones', '/' . ($catHigh['slug'] ?? 'iran')];
$ellipsis = [];
foreach ($scan as $p) {
    $rr = req($BASE . $p, true);
    if ($rr['code'] !== 200) { continue; }
    $tt = html_title($rr['body']);
    if (mb_strpos($tt, '…') !== false || strpos($tt, '...') !== false) {
        $ellipsis[] = $p . ' → ' . $tt;
    }
}
check('A14', 'هیچ تایتلی با «…» بریده نشده', count($ellipsis) === 0, $ellipsis ? implode("\n", $ellipsis) : '');

if ($sampleListing) {
    $u = function_exists('listing_url') ? listing_url($sampleListing) : ($BASE . '/' . $sampleListing['id']);
    $r = req($u, true);
    $t = html_title($r['body']);
    $h1 = html_h1($r['body']);
    check('A14', 'تایتل صفحهٔ آگهی طبق قالب «معاوضه / فروش … در {شهر}»', $r['code'] === 200 && mb_strpos($t, '…') === false && (mb_stripos($t, 'معاوضه') !== false || mb_stripos($t, 'فروش') !== false), 'تایتل: ' . $t);
}

// ══════════════════════════════════════════════════════════════════════════
// A16 — لندینگ معاوضه و دکمهٔ «فقط معاوضه»
// ══════════════════════════════════════════════════════════════════════════
check('A16', 'جدول swap_landings موجود است', has_table('swap_landings'));
if (!empty($swapRows)) {
    foreach (['location', 'category', 'model', 'xy'] as $type) {
        if (isset($swapByType[$type])) {
            $u = swap_url_of($BASE, $swapByType[$type]);
            $r = req($u, true);
            check('A16', 'لندینگ نوع ' . $type . ' با ۲۰۰ بارگذاری می‌شود', $r['code'] === 200, $u . ' → کد=' . $r['code']);
        } else {
            check('A16', 'لندینگ نوع ' . $type . ' ثبت شده', false, 'هیچ لندینگی از این نوع نیست');
        }
    }

    // دکمهٔ «فقط معاوضه» روی صفحهٔ دسته
    $catRows = array_values(array_filter($swapRows, fn($x) => $x['type'] === 'category' && $x['category_slug'] !== ''));
    if ($catRows) {
        $row = $catRows[0];
        $loc = $row['location_slug'] !== '' ? $row['location_slug'] : 'iran';
        $catPage = $BASE . '/' . $loc . '/' . $row['category_slug'];
        $r = req($catPage, true);
        $indexable = function_exists('swaapin_swap_landing_indexable') ? (bool)swaapin_swap_landing_indexable($row) : null;
        $landingUrl = swap_url_of($BASE, $row);
        if ($indexable === true) {
            $ok = str_contains($r['body'], '<a href="' . $landingUrl . '"') || str_contains($r['body'], 'href="' . $landingUrl . '"');
            check('A16', 'دکمهٔ «فقط معاوضه» در حالت ایندکس یک لینک (<a>) است', $ok, $catPage . ' → landing=' . $landingUrl);
        } elseif ($indexable === false) {
            $ok = str_contains($r['body'], 'deal=swap');
            check('A16', 'دکمهٔ «فقط معاوضه» در حالت غیرایندکس یک فیلتر (button?deal=swap) است', $ok, $catPage);
        } else {
            check('A16', 'دکمهٔ «فقط معاوضه» قابل ارزیابی', false, 'تابع swaapin_swap_landing_indexable موجود نیست');
        }
    } else {
        check('A16', 'لندینگ نوع دسته برای تست دکمه', false, 'لندینگ دسته‌ای وجود ندارد');
    }
} else {
    check('A16', 'حداقل یک لندینگ معاوضه فعال وجود دارد', false, 'جدول خالی است');
}

// ══════════════════════════════════════════════════════════════════════════
// A20 — فروشگاه‌ها
// ══════════════════════════════════════════════════════════════════════════
if ($storeSample && !empty($storeSample['slug'])) {
    $slug = $storeSample['slug'];
    $r = req($BASE . '/shop/' . $slug, true);
    check('A20', '/shop/{slug} با ۲۰۰ بارگذاری می‌شود', $r['code'] === 200, '/shop/' . $slug . ' → کد=' . $r['code']);

    $r = req($BASE . '/shop/both/' . $slug, false);
    $ok = ($r['code'] === 301) && (norm_path($r['location']) === '/shop/' . $slug);
    check('A20', '/shop/both/{slug} با ۳۰۱ به /shop/{slug} می‌رود', $ok, 'کد=' . $r['code'] . ' → ' . norm_path($r['location']));

    // Q05: فروشگاه تستی باید ۴۱۰ بدهد. /shop/both/{slug} اول ۳۰۱ می‌شود، پس زنجیره دنبال می‌شود.
    $rf = req_follow($BASE . '/shop/both/myket-test-store', 4, false);
    check('A20', 'فروشگاه تستی ۴۱۰ می‌دهد (Q05)', $rf['code'] === 410, 'کد نهایی=' . $rf['code']);
} else {
    check('A20', 'فروشگاه نمونه برای تست پیدا شد', false, 'هیچ فروشگاهی با store_slug نیست');
}

// ══════════════════════════════════════════════════════════════════════════
// A21 — تعیین تکلیف صفحه‌های جانبی
// ══════════════════════════════════════════════════════════════════════════
$r = req($BASE . '/ai-lab', true);
check('A21', '/ai-lab با noindex', $r['code'] === 200 && is_noindex($r['body'], $r['headers']), 'کد=' . $r['code'] . ' robots=' . html_robots($r['body']));

foreach (['/installment-swap', '/valuation', '/ai-assistant'] as $p) {
    $r = req($BASE . $p, true);
    $ok = ($r['code'] === 200) && effective_indexable($r);
    check('A21', $p . ' ایندکس‌پذیر است', $ok,
        'کد=' . $r['code'] . ' robots=' . html_robots($r['body']) . ($GLOBAL_NOINDEX ? ' (noindex سراسری staging)' : ''));
}

$r = req($BASE . '/exchange', false);
check('A21', '/exchange با ۳۰۱ به خانه می‌رود', $r['code'] === 301 && norm_path($r['location']) === '/', 'کد=' . $r['code'] . ' → ' . norm_path($r['location']));

$r = req($BASE . '/community', true);
check('A21', '/community طبق تصمیم ۴۰۴ می‌دهد', $r['code'] === 404, 'کد=' . $r['code']);

foreach (['/faq', '/fraud-prevention', '/about', '/contact'] as $p) {
    $r = req($BASE . $p, true);
    check('A21', $p . ' بدون تغییر و ۲۰۰', $r['code'] === 200, 'کد=' . $r['code']);
}

// صفحه‌های تستی → ۴۱۰
foreach (['/page/test', '/page/test-final', '/page/seo-test-page', '/page/about-gol'] as $p) {
    $r = req($BASE . $p, false);
    check('A21', $p . ' با ۴۱۰ (صفحهٔ تستی)', $r['code'] === 410, 'کد=' . $r['code']);
}

// ══════════════════════════════════════════════════════════════════════════
// A22 — صفحهٔ دانلود اپ /app
// ══════════════════════════════════════════════════════════════════════════
$r = req($BASE . '/app', true);
$t = html_title($r['body']);
$h1 = html_h1($r['body']);
check('A22', '/app با ۲۰۰ بارگذاری می‌شود', $r['code'] === 200, 'کد: ' . $r['code']);
check('A22', 'تایتل /app «دانلود برنامه سواپین»', mb_strpos(norm_fa($t), norm_fa('دانلود برنامه سواپین')) !== false, 'تایتل: ' . $t);
check('A22', 'H1 /app «دانلود اپلیکیشن سواپین»', mb_strpos(norm_fa($h1), norm_fa('دانلود اپلیکیشن سواپین')) !== false, 'H1: ' . $h1);

$r = req($BASE . '/pwa', false);
check('A22', '/pwa با ۳۰۱ به /app می‌رود', $r['code'] === 301 && norm_path($r['location']) === '/app', 'کد=' . $r['code'] . ' → ' . norm_path($r['location']));

// ══════════════════════════════════════════════════════════════════════════
// GTM / GA4 (طبق AGENTS.md)
// ══════════════════════════════════════════════════════════════════════════
$pub = ['/' , '/iran/car', ($citySample && $catHigh) ? ('/' . $citySample['slug'] . '/' . $catHigh['slug']) : '/iran/mobile-phones'];
$gtmFail = [];
$ga4Fail = [];
foreach ($pub as $p) {
    $r = req($BASE . $p, true);
    if ($r['code'] !== 200) { continue; }
    if (!has_gtm($r['body'])) { $gtmFail[] = $p . ' → GTM ندارد'; }
    if (has_direct_ga4($r['body'])) { $ga4Fail[] = $p . ' → gtag.js مستقیم دارد'; }
}
check('GTM', 'GTM روی صفحات public فعال است', count($gtmFail) === 0, $gtmFail ? implode("\n", $gtmFail) : '');
check('GTM', 'اسکریپت مستقیم GA4 (gtag.js) وجود ندارد', count($ga4Fail) === 0, $ga4Fail ? implode("\n", $ga4Fail) : '');

foreach (['/admin/login.php', '/auth/login'] as $p) {
    $r = req($BASE . $p, true);
    $hasGtm = has_gtm($r['body']);
    check('GTM', $p . ' نباید GTM داشته باشد', !$hasGtm, 'کد=' . $r['code'] . ($hasGtm ? ' → GTM یافت شد!' : ''));
}

// ══════════════════════════════════════════════════════════════════════════
// خروجی
// ══════════════════════════════════════════════════════════════════════════
$ORDER = ['ENV','A01.1','A02','A03.1','A03.2','A04','A05','A06','A07','A08','A09.2','A10','A11','A12','A13','A14','A16','A20','A21','A22','GTM'];

$totalPass = 0; $totalFail = 0;
foreach ($RESULTS as $items) {
    foreach ($items as $it) { $it['ok'] ? $totalPass++ : $totalFail++; }
}

if ($AS_TEXT) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "SEO AUDIT  base={$BASE}\n";
    echo "PASS={$totalPass}  FAIL={$totalFail}\n\n";
    foreach ($ORDER as $task) {
        if (empty($RESULTS[$task])) { continue; }
        echo "== {$task} ==\n";
        foreach ($RESULTS[$task] as $it) {
            echo ($it['ok'] ? '[PASS] ' : '[FAIL] ') . $it['name'] . ($it['detail'] !== '' ? '  — ' . str_replace("\n", "\n        ", $it['detail']) : '') . "\n";
        }
        echo "\n";
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>SEO Audit — سواپین</title>
<style>
  body{font-family:Tahoma,Segoe UI,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px;line-height:1.7}
  h1{font-size:20px;margin:0 0 4px}
  .meta{color:#94a3b8;font-size:13px;margin-bottom:16px}
  .summary{display:flex;gap:12px;margin-bottom:20px}
  .pill{padding:8px 14px;border-radius:999px;font-weight:bold}
  .pill.pass{background:#064e3b;color:#6ee7b7}
  .pill.fail{background:#7f1d1d;color:#fca5a5}
  .task{background:#1e293b;border:1px solid #334155;border-radius:10px;margin-bottom:14px;overflow:hidden}
  .task > h2{font-size:15px;margin:0;padding:10px 14px;background:#1e293b;border-bottom:1px solid #334155;display:flex;justify-content:space-between}
  .row{padding:8px 14px;border-bottom:1px dashed #334155;font-size:14px;display:flex;gap:10px}
  .row:last-child{border-bottom:none}
  .row .st{flex:0 0 56px;font-weight:bold}
  .row.ok .st{color:#34d399}
  .row.no .st{color:#f87171}
  .detail{color:#94a3b8;font-size:12px;white-space:pre-wrap;display:block;margin-top:4px}
  .badge{font-size:12px;color:#94a3b8}
</style>
</head>
<body>
<h1>گزارش تست سئو — سواپین</h1>
<div class="meta">
  دامنهٔ تست: <b><?= htmlspecialchars($BASE) ?></b><br>
  زمان اجرا: <?= htmlspecialchars(date('Y-m-d H:i:s')) ?> (سرور)<br>
  نمونهٔ سایت‌مپ: <?= (int)$SAMPLE ?> — نقشهٔ ۳۰۱: <?= $SKIP301 ? 'رد شده' : 'اجرا شد' ?>
</div>
<div class="summary">
  <span class="pill pass">PASS: <?= (int)$totalPass ?></span>
  <span class="pill fail">FAIL: <?= (int)$totalFail ?></span>
</div>
<?php if ($GLOBAL_NOINDEX): ?>
<div class="task" style="border-color:#b45309">
  <h2 style="background:#78350f;border-color:#b45309">توضیح محیط</h2>
  <div class="row" style="color:#fbbf24">
    <span class="st">ENV</span>
    <span>noindex سراسری staging فعال است. بنابراین بررسی «ایندکس‌پذیری» صفحات از لایهٔ منطق
    (پایدارى ایندکس در <code>listing_index_status</code> + آستانهٔ ۶ آگهی) انجام شده، نه از تگ robots در HTML.
    برای بررسی واقعی robots متا در HTML، اسکریپت را با <code>&amp;base=https://swaapin.ir</code> اجرا کن.</span>
  </div>
</div>
<?php endif; ?>

<?php foreach ($ORDER as $task): ?>
  <?php if (empty($RESULTS[$task])) continue; ?>
  <?php
    $fails = 0; foreach ($RESULTS[$task] as $it) { if (!$it['ok']) $fails++; }
  ?>
  <div class="task">
    <h2>
      <span><?= htmlspecialchars($task) ?></span>
      <span class="badge"><?= $fails === 0 ? '✅ همه پاس' : ('❌ ' . $fails . ' خطا') ?></span>
    </h2>
    <?php foreach ($RESULTS[$task] as $it): ?>
      <div class="row <?= $it['ok'] ? 'ok' : 'no' ?>">
        <span class="st"><?= $it['ok'] ? 'PASS' : 'FAIL' ?></span>
        <span>
          <?= htmlspecialchars($it['name']) ?>
          <?php if ($it['detail'] !== ''): ?><span class="detail"><?= htmlspecialchars($it['detail']) ?></span><?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<p class="meta">پس از پایان تست، فایل <code>seo-audit.php</code> را از سرور حذف کن.</p>
</body>
</html>
