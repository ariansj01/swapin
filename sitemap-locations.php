<?php
/**
 * sitemap-locations.xml — صفحه‌های مکان (سند معماری سئو — بخش ۳ و ۷).
 * فقط مکان‌های با حداقل ۶ آگهی فعال یا مکان‌هایی که قبلاً «ایندکس‌شده از تاریخ» دارند.
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

try {
    swaapin_ensure_location_tree();
} catch (Throwable $e) {
    swapin_debug_log('sitemap_locations_tree_skip', ['msg' => $e->getMessage()]);
}

$base    = rtrim(APP_URL, '/');
$indexed = swaapin_sitemap_indexed_paths();
$lastmod = swaapin_sitemap_listing_lastmod();
$newly   = [];

try {
    $locs = DB::fetchAll('SELECT id, parent_id, level, name, slug FROM locations WHERE is_active = 1');
} catch (Throwable $e) {
    $locs = [];
}

swaapin_sitemap_open();
foreach ($locs as $loc) {
    $slug = (string) ($loc['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $path  = '/' . $slug;
    $count = swaapin_location_active_listing_count($loc);
    if ($count < SWAAPIN_MIN_LISTINGS_FOR_INDEX && !isset($indexed[$path])) {
        continue;
    }
    swaapin_sitemap_url(
        $base . $path,
        (($loc['level'] ?? '') === 'country') ? '0.9' : '0.7',
        'daily',
        $lastmod
    );
    if (!isset($indexed[$path])) {
        $newly[] = $path;
    }
}
swaapin_sitemap_close();

swaapin_sitemap_mark($newly);
