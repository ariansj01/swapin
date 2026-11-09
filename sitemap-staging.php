<?php

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap>
    <loc><?= htmlspecialchars(APP_URL . '/sitemap.php', ENT_XML1) ?></loc>
  </sitemap>
</sitemapindex>
