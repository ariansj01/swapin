<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';

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
    ['loc' => $base . '/ai/chat',   'priority' => '0.5', 'changefreq' => 'weekly'],
    ['loc' => $base . '/shops',          'priority' => '0.8', 'changefreq' => 'daily'],
    ['loc' => $base . '/shops/online',   'priority' => '0.8', 'changefreq' => 'daily'],
    ['loc' => $base . '/shops/physical', 'priority' => '0.8', 'changefreq' => 'daily'],
    ['loc' => $base . '/listings/all',   'priority' => '0.7', 'changefreq' => 'daily'],
    ['loc' => $base . '/trades',       'priority' => '0.5', 'changefreq' => 'weekly'],
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

$listings = DB::fetchAll(
    'SELECT id, updated_at FROM listings WHERE status = "active" AND review_status = "approved" ORDER BY updated_at DESC LIMIT 5000'
);
foreach ($listings as $l) {
    $urls[] = [
        'loc'        => $base . '/listings/view?id=' . (int)$l['id'],
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
