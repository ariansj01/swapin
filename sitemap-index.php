<?php
/**
 * sitemap.xml — sitemap index (سند معماری سئو — بخش ۷).
 * به سایت‌مپ جدای هر نوع صفحه لینک می‌دهد تا وضعیت ایندکس هر نوع در سرچ کنسول جدا دیده شود.
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

try {
    swaapin_ensure_category_tree();
} catch (Throwable $e) {
    swapin_debug_log('sitemap_index_cat_tree_skip', ['msg' => $e->getMessage()]);
}

$base      = rtrim(APP_URL, '/');
$listLast  = swaapin_sitemap_listing_lastmod();
$storeLast = swaapin_sitemap_store_lastmod();
$pageLast  = swaapin_sitemap_page_lastmod();

$children = [
    ['/sitemap-locations.xml',  $listLast],
    ['/sitemap-categories.xml', $listLast],
    ['/sitemap-brands.xml',     $listLast],
    ['/sitemap-swap.xml',       $listLast],
];

// آگهی‌ها: بالای ۵۰٬۰۰۰ آدرس به چند فایل تقسیم می‌شود.
$total = swaapin_sitemap_ads_total();
if ($total > SWAAPIN_SITEMAP_ADS_CHUNK) {
    $chunks = (int) ceil($total / SWAAPIN_SITEMAP_ADS_CHUNK);
    for ($i = 1; $i <= $chunks; $i++) {
        $children[] = ['/sitemap-ads-' . $i . '.xml', $listLast];
    }
} else {
    $children[] = ['/sitemap-ads.xml', $listLast];
}

$children[] = ['/sitemap-shops.xml', $storeLast];
$children[] = ['/sitemap-pages.xml', $pageLast];

swaapin_sitemap_open('sitemapindex');
foreach ($children as [$path, $last]) {
    swaapin_sitemap_index_entry($base . $path, $last);
}
// سایت‌مپ بلاگ (WordPress) جدا در ایندکس اعلام می‌شود.
swaapin_sitemap_index_entry($base . '/blog/sitemap_index.xml', null);
swaapin_sitemap_close('sitemapindex');
