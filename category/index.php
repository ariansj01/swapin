<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/i18n.php';

try {
    swaapin_ensure_category_tree();
} catch (Throwable $e) {
    swapin_debug_log('category_idx_cat_tree_skip', ['msg' => $e->getMessage()]);
}

$redirectSlugs = trim((string)($_GET['redirect_slugs'] ?? ''));
$slugPath      = trim((string)($_GET['slug_path'] ?? ''));

if ($redirectSlugs !== '') {
    $parts = array_values(array_filter(explode('/', $redirectSlugs), 'strlen'));
    if (!empty($parts)) {
        $last = end($parts);
        $newUrl = category_url($last);
        if ($newUrl && strpos($newUrl, '/category/') === false) {
            header('Location: ' . $newUrl, true, 301);
            exit;
        }
        $slugPath = $redirectSlugs;
    }
}

$category = null;
$slug     = '';

if ($slugPath !== '') {
    $parts = array_values(array_filter(explode('/', $slugPath), 'strlen'));
    if (!empty($parts)) {
        $parts = array_map('rawurldecode', $parts);
        $category = get_category_by_slug_path($parts);
    }
}

if (!$category && isset($_GET['slug'])) {
    $slug = trim((string)$_GET['slug']);
    $category = DB::fetch(
        'SELECT * FROM categories WHERE slug = ? AND is_active = 1',
        [$slug]
    );
}

if (!$category) {
    http_response_code(404);
    exit;
}

$_GET['cat'] = $category['slug'];

require __DIR__ . '/../listings/all.php';
