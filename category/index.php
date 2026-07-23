<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$slug = trim($_GET['slug'] ?? '');

if (!$slug) {
    http_response_code(404);
    exit;
}

$category = DB::fetch(
    'SELECT * FROM categories WHERE slug = ? AND is_active = 1',
    [$slug]
);

if (!$category) {
    http_response_code(404);
    exit;
}

$_GET['cat'] = $slug;

require __DIR__ . '/../listings/all.php';
