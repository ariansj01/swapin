<?php
/**
 * کتابخانه مشترک تولید سایت‌مپ‌های تفکیک‌شده (سند معماری سئو — بخش ۷).
 * قاعده: فقط آدرس‌های ۲۰۰، ایندکس‌پذیر (بدون noindex)، self-canonical و بدون پارامتر.
 */

if (!defined('SWAAPIN_SITEMAP_ADS_CHUNK')) {
    // حداکثر آدرس در هر فایل سایت‌مپ آگهی‌ها (سند: بالای ۵۰٬۰۰۰ به چند فایل تقسیم شود)
    define('SWAAPIN_SITEMAP_ADS_CHUNK', 50000);
}

/** بارگذاری وابستگی‌ها. config.php بقیهٔ includes لازم را می‌آورد؛ seo.php جدا بارگذاری می‌شود. */
function swaapin_sitemap_boot(): void
{
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/seo.php';
}

/** شروع خروجی XML (urlset یا sitemapindex). */
function swaapin_sitemap_open(string $root = 'urlset'): void
{
    if (!headers_sent()) {
        header('Content-Type: application/xml; charset=utf-8');
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<' . $root . ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
}

/** پایان خروجی XML. */
function swaapin_sitemap_close(string $root = 'urlset'): void
{
    echo '</' . $root . '>';
}

/**
 * آیا این آدرس مجاز به ورود به سایت‌مپ است؟
 * فقط URLهای ۲۰۰/ایندکس‌پذیر/self-canonical/بدون پارامتر. مسیرهای دارای کاراکتر
 * کاما (خواه خام «،» خواه انکودشدهٔ «%D8%8C» که به اشتباه به انتهای برخی مسیرها
 * می‌چسبید) هرگز کانونیکال نیستند و از XML حذف می‌شوند.
 */
function swaapin_sitemap_url_allowed(string $loc): bool
{
    if (trim($loc) === '') {
        return false;
    }
    if (str_contains($loc, '%D8%8C') || str_contains($loc, '،')) {
        return false;
    }
    return true;
}

/** چاپ یک <url> با lastmod واقعی (اختیاری). */
function swaapin_sitemap_url(string $loc, string $priority = '0.7', string $changefreq = 'daily', ?string $lastmod = null): void
{
    if (!swaapin_sitemap_url_allowed($loc)) {
        return;
    }
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    if ($lastmod !== null && $lastmod !== '') {
        echo '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1) . "</lastmod>\n";
    }
    echo '    <changefreq>' . htmlspecialchars($changefreq, ENT_XML1) . "</changefreq>\n";
    echo '    <priority>' . htmlspecialchars($priority, ENT_XML1) . "</priority>\n";
    echo "  </url>\n";
}

/** چاپ یک عضو <sitemap> در سایتمپ ایندکس. */
function swaapin_sitemap_index_entry(string $loc, ?string $lastmod = null): void
{
    echo "  <sitemap>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    if ($lastmod !== null && $lastmod !== '') {
        echo '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1) . "</lastmod>\n";
    }
    echo "  </sitemap>\n";
}

/** مسیرهای ثبت‌شده به‌عنوان ایندکس‌شده: path => indexed_at. */
function swaapin_sitemap_indexed_paths(): array
{
    return function_exists('seo_indexed_paths') ? seo_indexed_paths() : [];
}

/** ثبت مسیرهای تازه‌واجدشرط به‌عنوان «ایندکس‌شده از تاریخ». */
function swaapin_sitemap_mark(array $paths): void
{
    if ($paths !== [] && function_exists('seo_mark_pages_indexed')) {
        seo_mark_pages_indexed(array_values(array_unique($paths)));
    }
}

/** تبدیل تاریخ دیتابیس به قالب W3C (lastmod)؛ null اگر نامعتبر باشد. */
function swaapin_sitemap_fmt_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? date('c', $ts) : null;
}

/** تعداد کل آگهی‌های عمومی (فعال + تأییدشده). */
function swaapin_sitemap_ads_total(): int
{
    try {
        $row = DB::fetch('SELECT COUNT(*) AS c FROM listings l WHERE ' . listing_public_sql('l'));
        return (int) ($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** آخرین به‌روزرسانی آگهی‌های عمومی (lastmod واقعی صفحات وابسته به آگهی). */
function swaapin_sitemap_listing_lastmod(): ?string
{
    try {
        $row = DB::fetch('SELECT MAX(l.updated_at) AS m FROM listings l WHERE ' . listing_public_sql('l'));
        return swaapin_sitemap_fmt_date($row['m'] ?? null);
    } catch (Throwable $e) {
        return null;
    }
}

/** آخرین به‌روزرسانی فروشگاه‌ها. */
function swaapin_sitemap_store_lastmod(): ?string
{
    try {
        $col = db_has_column('users', 'updated_at') ? 'updated_at' : (db_has_column('users', 'created_at') ? 'created_at' : null);
        if ($col === null) {
            return null;
        }
        $row = DB::fetch(
            'SELECT MAX(' . $col . ') AS m FROM users
             WHERE is_active = 1 AND store_slug IS NOT NULL AND store_slug != ""'
            . (function_exists('store_not_blocked_sql') ? ' AND ' . store_not_blocked_sql('store_slug') : '')
        );
        return swaapin_sitemap_fmt_date($row['m'] ?? null);
    } catch (Throwable $e) {
        return null;
    }
}

/** آخرین به‌روزرسانی صفحه‌های ثابت. */
function swaapin_sitemap_page_lastmod(): ?string
{
    if (!db_has_table('content_pages') || !db_has_column('content_pages', 'updated_at')) {
        return null;
    }
    try {
        $row = DB::fetch('SELECT MAX(updated_at) AS m FROM content_pages WHERE status = "published"');
        return swaapin_sitemap_fmt_date($row['m'] ?? null);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * شمارش آگهی‌های فعال به تفکیک (شهر × دسته) با یک کوئری مشترک.
 * خروجی: ['city' => [cityName => [catId => n]]]
 */
function swaapin_sitemap_city_category_counts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = ['city' => []];
    try {
        $rows = DB::fetchAll(
            'SELECT l.city AS c, l.category_id AS cat, COUNT(*) AS n FROM listings l WHERE '
            . listing_public_sql('l') . ' AND l.listing_mode != "sell"'
            . ' AND l.city <> "" AND l.category_id IS NOT NULL GROUP BY l.city, l.category_id'
        );
        foreach ($rows as $r) {
            $out['city'][(string) $r['c']][(int) $r['cat']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('sitemap_city_cat_counts_skip', ['msg' => $e->getMessage()]);
        }
    }
    return $cache = $out;
}

/** فهرست نام شهرهای تحت پوشش یک مکان (سطح country همه شهرها را پوشش می‌دهد). */
function swaapin_sitemap_location_cities(array $loc, array $allCities): array
{
    if ((string) ($loc['level'] ?? '') === 'country') {
        return $allCities;
    }
    if (!function_exists('swaapin_location_filter')) {
        return [];
    }
    $filter = swaapin_location_filter($loc);
    return (array) ($filter['cities'] ?? []);
}

/** جمع تعداد آگهی برای مجموعه‌ای از شهرها و مجموعه‌ای از دسته‌ها. */
function swaapin_sitemap_sum_counts(array $cityCat, array $cities, array $catIds): int
{
    if ($cities === [] || $catIds === []) {
        return 0;
    }
    $catSet = array_flip($catIds);
    $sum    = 0;
    foreach ($cities as $city) {
        if (empty($cityCat[$city])) {
            continue;
        }
        foreach ($cityCat[$city] as $catId => $n) {
            if (isset($catSet[$catId])) {
                $sum += (int) $n;
            }
        }
    }
    return $sum;
}

/**
 * تعداد آگهی‌های فعال یک استان به تفکیک شهر برای مجموعه دسته‌ها (فقط شهرهای دارای آگهی).
 * ورودی «cityCat» ساختار swaapin_sitemap_city_category_counts است؛ برای قاعدهٔ ضد-تکرار
 * صفحهٔ استان+دسته (توابع seo_province_duplicate_blocked) استفاده می‌شود.
 */
function swaapin_sitemap_province_city_counts(array $cityCat, array $cities, array $catIds): array
{
    $byCity = $cityCat['city'] ?? [];
    if ($cities === [] || $catIds === [] || $byCity === []) {
        return [];
    }
    $scope = array_flip(array_map('intval', $catIds));
    $counts = [];
    foreach ($cities as $cityName) {
        $byCat = $byCity[(string) $cityName] ?? [];
        $sum = 0;
        foreach ($byCat as $cid => $n) {
            if (isset($scope[(int) $cid])) {
                $sum += (int) $n;
            }
        }
        if ($sum > 0) {
            $counts[] = $sum;
        }
    }
    return $counts;
}

/** نگاشت شناسهٔ دسته → مجموعهٔ شناسه‌های خود و زیردسته‌ها (برای شمارش تجمعی). */
function swaapin_sitemap_category_scopes(array $cats): array
{
    $scopes = [];
    foreach ($cats as $cat) {
        $id = (int) ($cat['id'] ?? 0);
        if ($id > 0) {
            $scopes[$id] = function_exists('swaapin_category_descendant_ids')
                ? swaapin_category_descendant_ids($id)
                : [$id];
        }
    }
    return $scopes;
}
