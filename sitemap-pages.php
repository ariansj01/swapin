<?php
/**
 * sitemap-pages.xml — صفحه‌های ثابت (سند معماری سئو — بخش ۷).
 * شامل خانه، /valuation، /app، /ai-assistant، /installment-swap، /shops و صفحه‌های CMS ایندکس‌پذیر.
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

$base = rtrim(APP_URL, '/');

// صفحه‌های ثابت (بدون پارامتر، self-canonical، ایندکس‌پذیر).
$pages = [
    ['/',                 '1.0', 'daily'],
    ['/about',            '0.6', 'monthly'],
    ['/contact',          '0.5', 'monthly'],
    ['/fraud-prevention', '0.6', 'monthly'],
    ['/faq',              '0.7', 'monthly'],
    ['/ai-assistant',     '0.8', 'monthly'],
    ['/app',              '0.7', 'monthly'],
    ['/valuation',        '0.8', 'weekly'],
    ['/installment-swap', '0.8', 'weekly'],
    ['/shops',            '0.8', 'daily'],
    ['/shops/online',     '0.8', 'daily'],
    ['/shops/physical',   '0.8', 'daily'],
];

// صفحه‌های CMS ایندکس‌پذیر (بدون صفحه‌های تستی/قدیمی).
$excludedSlugs    = ['test-final', 'about-gol', 'seo-test-page', 'test', 'ai-assistant'];
$slugPlaceholders = implode(',', array_fill(0, count($excludedSlugs), '?'));
try {
    $cmsPages = DB::fetchAll(
        'SELECT slug, updated_at FROM content_pages
         WHERE status = "published" AND index_status = "index"
         AND slug NOT IN (' . $slugPlaceholders . ')',
        $excludedSlugs
    );
} catch (Throwable $e) {
    $cmsPages = [];
}

swaapin_sitemap_open();
$pageLastmod = swaapin_sitemap_page_lastmod();
foreach ($pages as [$path, $priority, $changefreq]) {
    swaapin_sitemap_url($base . $path, $priority, $changefreq, $pageLastmod);
}
foreach ($cmsPages as $p) {
    $slug = (string) ($p['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    swaapin_sitemap_url(
        $base . '/page/' . rawurlencode($slug),
        '0.6',
        'monthly',
        swaapin_sitemap_fmt_date($p['updated_at'] ?? null)
    );
}
swaapin_sitemap_close();
