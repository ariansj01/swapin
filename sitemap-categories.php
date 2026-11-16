<?php
/**
 * sitemap-categories.xml — صفحه‌های مکان × دسته (سند معماری سئو — بخش ۷).
 * مثال: /tehran/apartment. فقط با حداقل ۶ آگهی فعال (شامل زیردسته‌ها) یا «ایندکس‌شده از تاریخ».
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

try {
    swaapin_ensure_location_tree();
    swaapin_ensure_category_tree();
} catch (Throwable $e) {
    swapin_debug_log('sitemap_categories_tree_skip', ['msg' => $e->getMessage()]);
}

$base     = rtrim(APP_URL, '/');
$indexed  = swaapin_sitemap_indexed_paths();
$lastmod  = swaapin_sitemap_listing_lastmod();
$cityCat  = swaapin_sitemap_city_category_counts();
$allCities = array_keys($cityCat['city']);
$newly    = [];

try {
    $locs = DB::fetchAll('SELECT id, parent_id, level, name, slug FROM locations WHERE is_active = 1');
    $cats = DB::fetchAll('SELECT id, slug FROM categories WHERE is_active = 1');
} catch (Throwable $e) {
    $locs = [];
    $cats = [];
}
$scopes = swaapin_sitemap_category_scopes($cats);

swaapin_sitemap_open();
foreach ($locs as $loc) {
    $locSlug = (string) ($loc['slug'] ?? '');
    if ($locSlug === '') {
        continue;
    }
    $cities = swaapin_sitemap_location_cities($loc, $allCities);
    if ($cities === []) {
        continue;
    }
    foreach ($cats as $cat) {
        $catId   = (int) ($cat['id'] ?? 0);
        $catSlug = (string) ($cat['slug'] ?? '');
        if ($catId <= 0 || $catSlug === '') {
            continue;
        }
        $path  = '/' . $locSlug . '/' . $catSlug;
        $count = swaapin_sitemap_sum_counts($cityCat, $cities, $scopes[$catId] ?? [$catId]);
        if ($count < SWAAPIN_MIN_LISTINGS_FOR_INDEX && !isset($indexed[$path])) {
            continue;
        }
        swaapin_sitemap_url($base . $path, '0.7', 'daily', $lastmod);
        if (!isset($indexed[$path])) {
            $newly[] = $path;
        }
    }
}
swaapin_sitemap_close();

swaapin_sitemap_mark($newly);
