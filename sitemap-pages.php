<?php

require_once __DIR__.'/includes/config.php';

header('Content-Type: application/xml; charset=utf-8');

$pages = DB::fetchAll(
    'SELECT slug, updated_at 
     FROM content_pages
     WHERE status="published"
     AND index_status="index"'
);

echo '<?xml version="1.0" encoding="UTF-8"?>';

?>

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

<?php foreach($pages as $p): ?>

<url>
<loc><?= APP_URL ?>/page/<?= htmlspecialchars($p['slug']) ?></loc>
<lastmod><?= date('Y-m-d', strtotime($p['updated_at'])) ?></lastmod>
</url>

<?php endforeach; ?>

</urlset>
