<?php
/**
 * sitemap-ads.xml — آگهی‌های فعال و تأییدشده (سند معماری سئو — بخش ۷).
 * بالای ۵۰٬۰۰۰ آدرس به چند فایل تقسیم می‌شود: sitemap-ads-1.xml، sitemap-ads-2.xml، …
 * آگهی منقضی/فروخته‌شده/معاوضه‌شده (Q17) خارج است چون فقط status=active و review_status=approved می‌آید.
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

$chunk  = max(1, (int) ($_GET['chunk'] ?? 1));
$limit  = SWAAPIN_SITEMAP_ADS_CHUNK;
$offset = ($chunk - 1) * $limit;

try {
    $rows = DB::fetchAll(
        'SELECT id, title, updated_at FROM listings
         WHERE status = "active" AND review_status = "approved"
         ORDER BY id ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
} catch (Throwable $e) {
    swapin_debug_log('sitemap_ads_skip', ['msg' => $e->getMessage()]);
    $rows = [];
}

swaapin_sitemap_open();
foreach ($rows as $l) {
    swaapin_sitemap_url(
        listing_url($l),
        '0.8',
        'weekly',
        swaapin_sitemap_fmt_date($l['updated_at'] ?? null)
    );
}
swaapin_sitemap_close();
