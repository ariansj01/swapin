<?php
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/xml; charset=utf-8');

$base = rtrim(APP_URL, '/');
$now  = date('c');

$urls = [
    ['loc' => $base . '/',              'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => $base . '/about',     'priority' => '0.6', 'changefreq' => 'monthly'],
    ['loc' => $base . '/contact',   'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => $base . '/fraud-prevention', 'priority' => '0.6', 'changefreq' => 'monthly'],
    ['loc' => $base . '/support', 'priority' => '0.5', 'changefreq' => 'weekly'],
    ['loc' => $base . '/ai/chat',   'priority' => '0.5', 'changefreq' => 'weekly'],
];

$cats = DB::fetchAll('SELECT slug FROM categories WHERE parent_id IS NULL AND is_active = 1');
foreach ($cats as $cat) {
    $urls[] = [
        'loc'        => $base . '/category/' . rawurlencode($cat['slug']),
        'priority'   => '0.7',
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
