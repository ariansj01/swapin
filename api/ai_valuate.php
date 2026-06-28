<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

$title       = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$condition   = clean($_POST['condition'] ?? 'good');
$categoryId  = (int)($_POST['category_id'] ?? 0);

if (mb_strlen($title) < 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'title_too_short']);
    exit;
}

$cat = $categoryId
    ? DB::fetch('SELECT name, slug FROM categories WHERE id = ?', [$categoryId])
    : null;

$listing = [
    'title'           => $title,
    'description'     => $description,
    'condition'       => $condition,
    'category_label'  => $cat ? category_label($cat['slug'], $cat['name']) : 'عمومی',
    'demand_level'    => ai_demand_level($categoryId),
];

$similar = ai_fetch_similar_listings($categoryId);
$result  = ai_price_listing($listing, $similar);

if (!$result) {
    $result = ai_price_listing_fallback($listing);
}

echo json_encode(array_merge(['ok' => true], $result), JSON_UNESCAPED_UNICODE);
