<?php
/**
 * sitemap-brands.xml — صفحه‌های برند و مدل (سند معماری سئو — بخش ۱، ۲ و ۷).
 * مثال: /iran/car/peugeot/206. فقط با حداقل ۶ آگهی فعال یا «ایندکس‌شده از تاریخ».
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

$base    = rtrim(APP_URL, '/');
$indexed = swaapin_sitemap_indexed_paths();
$lastmod = swaapin_sitemap_listing_lastmod();
$newly   = [];

swaapin_sitemap_open();

try {
    if (function_exists('swaapin_ensure_catalog_table')) {
        swaapin_ensure_catalog_table();
    }
    if (function_exists('swaapin_catalog_table_exists') && swaapin_catalog_table_exists()) {
        $countrySlug = 'iran';
        $catSlugById = [];
        foreach (DB::fetchAll('SELECT id, slug FROM categories WHERE is_active = 1') as $cr) {
            $catSlugById[(int) $cr['id']] = (string) $cr['slug'];
        }
        // همگام‌سازی گره‌های کاتالوگ از آگهی‌های موجود تا صفحات برند/مدل دیده شوند.
        if (function_exists('swaapin_catalog_sync_category')) {
            foreach (array_keys($catSlugById) as $syncCatId) {
                swaapin_catalog_sync_category((int) $syncCatId);
            }
        }
        $catIdsWithBrand = DB::fetchAll(
            "SELECT DISTINCT category_id FROM catalog_nodes WHERE level = 'brand' AND is_active = 1"
        );
        foreach ($catIdsWithBrand as $rc) {
            $catId = (int) $rc['category_id'];
            if (!isset($catSlugById[$catId])) {
                continue;
            }
            $brands = DB::fetchAll(
                "SELECT * FROM catalog_nodes WHERE category_id = ? AND parent_id = 0 AND level = 'brand' AND is_active = 1",
                [$catId]
            );
            foreach ($brands as $brand) {
                $brandPath = '/' . $countrySlug . '/' . $catSlugById[$catId] . '/' . $brand['slug'];
                $bCount    = swaapin_catalog_active_listing_count([$brand], $catId);
                if ($bCount >= SWAAPIN_MIN_LISTINGS_FOR_INDEX || isset($indexed[$brandPath])) {
                    swaapin_sitemap_url($base . $brandPath, '0.6', 'daily', $lastmod);
                    if (!isset($indexed[$brandPath])) {
                        $newly[] = $brandPath;
                    }
                }
                $models = DB::fetchAll(
                    "SELECT * FROM catalog_nodes WHERE category_id = ? AND parent_id = ? AND level = 'model' AND is_active = 1",
                    [$catId, (int) $brand['id']]
                );
                foreach ($models as $model) {
                    $modelPath = $brandPath . '/' . $model['slug'];
                    $mCount    = swaapin_catalog_active_listing_count([$brand, $model], $catId);
                    if ($mCount >= SWAAPIN_MIN_LISTINGS_FOR_INDEX || isset($indexed[$modelPath])) {
                        swaapin_sitemap_url($base . $modelPath, '0.6', 'daily', $lastmod);
                        if (!isset($indexed[$modelPath])) {
                            $newly[] = $modelPath;
                        }
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    swapin_debug_log('sitemap_brands_skip', ['msg' => $e->getMessage()]);
}

swaapin_sitemap_close();

swaapin_sitemap_mark($newly);
