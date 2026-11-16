<?php
/**
 * sitemap-shops.xml — صفحه‌های فروشگاه (سند معماری سئو — بخش ۷).
 * آدرس کانونیکال: /shop/{slug}
 * قانون A11: فقط فروشگاه‌های با حداقل ۶ آگهی فعال یا «ایندکس‌شده از تاریخ» منتشر می‌شوند.
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

$base    = rtrim(APP_URL, '/');
$indexed = swaapin_sitemap_indexed_paths();
$lastmod = swaapin_sitemap_store_lastmod();
$newly   = [];

$where = ['is_active = 1'];
if (db_has_column('users', 'seller_type')) {
    $where[] = '(seller_type = "store" OR (store_name IS NOT NULL AND store_name != ""))';
} else {
    $where[] = '(store_name IS NOT NULL AND store_name != "")';
}
$where[] = 'store_slug IS NOT NULL AND store_slug != ""';

try {
    $rows = DB::fetchAll('SELECT id, store_slug FROM users WHERE ' . implode(' AND ', $where));
} catch (Throwable $e) {
    swapin_debug_log('sitemap_shops_skip', ['msg' => $e->getMessage()]);
    $rows = [];
}

// شمارش آگهی‌های فعال هر فروشنده با یک کوئری تجمیعی (قانون A11).
$countByUser = [];
try {
    $countRows = DB::fetchAll(
        'SELECT l.user_id AS uid, COUNT(*) AS n FROM listings l WHERE ' . listing_public_sql('l')
        . ' AND l.user_id IS NOT NULL GROUP BY l.user_id'
    );
    foreach ($countRows as $r) {
        $countByUser[(int) $r['uid']] = (int) $r['n'];
    }
} catch (Throwable $e) {
    swapin_debug_log('sitemap_shops_counts_skip', ['msg' => $e->getMessage()]);
}

swaapin_sitemap_open();
foreach ($rows as $r) {
    $slug = normalize_shop_slug((string) ($r['store_slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $path  = '/shop/' . $slug;
    $count = $countByUser[(int) ($r['id'] ?? 0)] ?? 0;
    if ($count < SWAAPIN_MIN_LISTINGS_FOR_INDEX && !isset($indexed[$path])) {
        continue;
    }
    swaapin_sitemap_url($base . '/shop/' . rawurlencode($slug), '0.6', 'weekly', $lastmod);
    if (!isset($indexed[$path])) {
        $newly[] = $path;
    }
}
swaapin_sitemap_close();

swaapin_sitemap_mark($newly);
