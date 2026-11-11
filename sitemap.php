<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/seo.php';

header('Content-Type: application/xml; charset=utf-8');

$base = rtrim(APP_URL, '/');
$now  = date('c');

try {
    swaapin_ensure_category_tree();
} catch (Throwable $e) {
    swapin_debug_log('sitemap_cat_tree_skip', ['msg' => $e->getMessage()]);
}

$urls = [
    ['loc' => $base . '/',              'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => $base . '/about',     'priority' => '0.6', 'changefreq' => 'monthly'],
    ['loc' => $base . '/contact',   'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => $base . '/fraud-prevention', 'priority' => '0.6', 'changefreq' => 'monthly'],
    ['loc' => $base . '/faq',       'priority' => '0.7', 'changefreq' => 'monthly'],
    ['loc' => $base . '/ai-assistant', 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['loc' => $base . '/app',        'priority' => '0.7', 'changefreq' => 'monthly'],
    ['loc' => $base . '/valuation',  'priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => $base . '/ai/chat',   'priority' => '0.5', 'changefreq' => 'weekly'],
    ['loc' => $base . '/shops',          'priority' => '0.8', 'changefreq' => 'daily'],
    ['loc' => $base . '/shops/online',   'priority' => '0.8', 'changefreq' => 'daily'],
    ['loc' => $base . '/shops/physical', 'priority' => '0.8', 'changefreq' => 'daily'],
    ['loc' => $base . '/iran',       'priority' => '0.9', 'changefreq' => 'daily'],
];

$cats = DB::fetchAll('SELECT id, slug, parent_id FROM categories WHERE is_active = 1 ORDER BY parent_id IS NULL DESC, parent_id ASC, sort_order, id');
$parentCount = [];
foreach ($cats as $c) {
    $pid = (int)($c['parent_id'] ?? 0);
    if ($pid > 0) {
        $parentCount[$pid] = ($parentCount[$pid] ?? 0) + 1;
    }
}
foreach ($cats as $cat) {
    $catUrl = category_url($cat['slug']);
    $pid = (int)($cat['parent_id'] ?? 0);
    $hasChildren = !empty($parentCount[(int)$cat['id']]);
    if ($pid === 0) {
        $priority = '0.9';
    } elseif ($hasChildren) {
        $priority = '0.8';
    } else {
        $priority = '0.7';
    }
    $urls[] = [
        'loc'        => $catUrl,
        'priority'   => $priority,
        'changefreq' => 'daily',
    ];
}

// صفحات مکان (سند معماری سئو — بخش ۳ و ۷): فقط مکان‌های با حداقل ۶ آگهی فعال
// یا مکان‌هایی که قبلاً یک‌بار ایندکس شده‌اند (پس از ایندکس، حذف نمی‌شوند).
try {
    swaapin_ensure_location_tree();
    $indexedPaths = seo_indexed_paths();
    $locRows = DB::fetchAll('SELECT id, parent_id, level, name, slug FROM locations WHERE is_active = 1');
    $newlyIndexed = [];
    foreach ($locRows as $loc) {
        $locSlug = (string) ($loc['slug'] ?? '');
        if ($locSlug === '') {
            continue;
        }
        $path  = '/' . $locSlug;
        $count = swaapin_location_active_listing_count($loc);
        if ($count < SWAAPIN_MIN_LISTINGS_FOR_INDEX && !isset($indexedPaths[$path])) {
            continue;
        }
        $urls[] = [
            'loc'        => $base . $path,
            'priority'   => (($loc['level'] ?? '') === 'country') ? '0.9' : '0.7',
            'changefreq' => 'daily',
        ];
        if (!isset($indexedPaths[$path])) {
            $newlyIndexed[] = $path;
        }
    }
    if ($newlyIndexed !== []) {
        seo_mark_pages_indexed($newlyIndexed);
    }
} catch (Throwable $e) {
    swapin_debug_log('sitemap_locations_skip', ['msg' => $e->getMessage()]);
}

// صفحات برند/مدل (سند — بخش ۱، ۲ و ۷): فقط با حداقل ۶ آگهی فعال یا صفحات قبلاً ایندکس‌شده
try {
    if (function_exists('swaapin_ensure_catalog_table')) {
        swaapin_ensure_catalog_table();
    }
    if (function_exists('swaapin_catalog_table_exists') && swaapin_catalog_table_exists()) {
        $indexedPaths = seo_indexed_paths();
        $countrySlug  = 'iran';
        $catSlugById  = [];
        foreach (DB::fetchAll('SELECT id, slug FROM categories WHERE is_active = 1') as $cr) {
            $catSlugById[(int) $cr['id']] = (string) $cr['slug'];
        }
        // همگام‌سازی گره‌های کاتالوگ از آگهی‌های موجود تا صفحات برند/مدل در سایتمپ دیده شوند
        if (function_exists('swaapin_catalog_sync_category')) {
            foreach (array_keys($catSlugById) as $syncCatId) {
                swaapin_catalog_sync_category((int) $syncCatId);
            }
        }
        $catIdsWithBrand = DB::fetchAll("SELECT DISTINCT category_id FROM catalog_nodes WHERE level = 'brand' AND is_active = 1");
        $newlyIndexed    = [];
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
                if ($bCount >= SWAAPIN_MIN_LISTINGS_FOR_INDEX || isset($indexedPaths[$brandPath])) {
                    $urls[] = ['loc' => $base . $brandPath, 'priority' => '0.6', 'changefreq' => 'daily'];
                    if (!isset($indexedPaths[$brandPath])) {
                        $newlyIndexed[] = $brandPath;
                    }
                }
                $models = DB::fetchAll(
                    "SELECT * FROM catalog_nodes WHERE category_id = ? AND parent_id = ? AND level = 'model' AND is_active = 1",
                    [$catId, (int) $brand['id']]
                );
                foreach ($models as $model) {
                    $modelPath = $brandPath . '/' . $model['slug'];
                    $mCount    = swaapin_catalog_active_listing_count([$brand, $model], $catId);
                    if ($mCount >= SWAAPIN_MIN_LISTINGS_FOR_INDEX || isset($indexedPaths[$modelPath])) {
                        $urls[] = ['loc' => $base . $modelPath, 'priority' => '0.6', 'changefreq' => 'daily'];
                        if (!isset($indexedPaths[$modelPath])) {
                            $newlyIndexed[] = $modelPath;
                        }
                    }
                }
            }
        }
        if ($newlyIndexed !== []) {
            seo_mark_pages_indexed($newlyIndexed);
        }
    }
} catch (Throwable $e) {
    swapin_debug_log('sitemap_catalog_skip', ['msg' => $e->getMessage()]);
}

// لندینگ‌های معاوضه (سند — بخش ۴ و ۷): /list/swap و /list/swap-{category} با حداقل ۶ آگهی
try {
    $indexedPaths = seo_indexed_paths();
    $swapCats = DB::fetchAll('SELECT id, slug FROM categories WHERE is_active = 1');
    $swapTargets = [['token' => 'swap', 'catId' => 0]];
    foreach ($swapCats as $sc) {
        $swapTargets[] = ['token' => 'swap-' . (string) $sc['slug'], 'catId' => (int) $sc['id']];
    }
    $newlyIndexed = [];
    foreach ($swapTargets as $st) {
        $path  = '/list/' . $st['token'];
        $where = [listing_public_sql('l'), 'l.listing_mode != "sell"'];
        $args  = [];
        if ($st['catId'] > 0) {
            $scope = swaapin_category_descendant_ids((int) $st['catId']);
            $where[] = 'l.category_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            foreach ($scope as $sid) {
                $args[] = (int) $sid;
            }
        }
        $row   = DB::fetch('SELECT COUNT(*) AS c FROM listings l WHERE ' . implode(' AND ', $where), $args);
        $count = (int) ($row['c'] ?? 0);
        if ($count >= SWAAPIN_MIN_LISTINGS_FOR_INDEX || isset($indexedPaths[$path])) {
            $urls[] = ['loc' => $base . $path, 'priority' => '0.7', 'changefreq' => 'daily'];
            if (!isset($indexedPaths[$path])) {
                $newlyIndexed[] = $path;
            }
        }
    }
    if ($newlyIndexed !== []) {
        seo_mark_pages_indexed($newlyIndexed);
    }
} catch (Throwable $e) {
    swapin_debug_log('sitemap_swap_skip', ['msg' => $e->getMessage()]);
}

$listings = DB::fetchAll(
    'SELECT id, title, updated_at FROM listings WHERE status = "active" AND review_status = "approved" ORDER BY updated_at DESC LIMIT 5000'
);
foreach ($listings as $l) {
    $urls[] = [
        'loc'        => listing_url($l),
        'priority'   => '0.8',
        'changefreq' => 'weekly',
        'lastmod'    => !empty($l['updated_at']) ? date('c', strtotime($l['updated_at'])) : $now,
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    if (!empty($u['lastmod'])) {
        echo '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1) . "</lastmod>\n";
    }
    echo '    <changefreq>' . htmlspecialchars($u['changefreq'], ENT_XML1) . "</changefreq>\n";
    echo '    <priority>' . htmlspecialchars($u['priority'], ENT_XML1) . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>';
