<?php
/**
 * sitemap-swap.xml — لندینگ‌های معاوضه (سند معماری سئو — بخش ۴ و ۷).
 * مثال‌ها: /tehran/list/swap، /tehran/list/swap-real-estate، /iran/list/swap-car-pride
 *
 * کارت A16.2 — بند ۳: لندینگ فقط از لیست کیورد تأییدشده ساخته می‌شود، نه از ترکیب
 * خودکار فیلترها. بنابراین سایتمپ فقط ردیف‌های فعال و ایندکس‌پذیر جدول
 * `swap_landings` را منتشر می‌کند (حداقل ۶ آگهی معاوضه‌ای یا «ایندکس‌شده از تاریخ»).
 */
require_once __DIR__ . '/includes/sitemap_lib.php';
swaapin_sitemap_boot();

$indexed = swaapin_sitemap_indexed_paths();
$lastmod = swaapin_sitemap_listing_lastmod();
$newly   = [];

$rows = function_exists('swaapin_swap_landings_active') ? swaapin_swap_landings_active() : [];

swaapin_sitemap_open();
foreach ($rows as $row) {
    if (!function_exists('swaapin_swap_landing_indexable') || !swaapin_swap_landing_indexable($row)) {
        continue;
    }
    $url  = swaapin_swap_landing_url($row);
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '') {
        continue;
    }
    swaapin_sitemap_url($url, '0.7', 'daily', $lastmod);
    if (!isset($indexed[$path])) {
        $newly[] = $path;
    }
}
swaapin_sitemap_close();

swaapin_sitemap_mark($newly);
