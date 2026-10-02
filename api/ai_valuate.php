<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

csrf_verify_or_fail(true);
rate_limit_ip_or_fail('ai_valuate', 40, 3600, true);

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

rate_limit_user_or_fail(
    'ai_valuate',
    (int) $user['id'],
    ai_limit('VALUATE_USER', 3),
    ai_window('VALUATE_USER', 900),
    true
);

$title       = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$condition   = clean($_POST['condition'] ?? 'good');
$categoryId  = (int)($_POST['category_id'] ?? 0);

if (mb_strlen($title) < 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'title_too_short']);
    exit;
}

if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
    $condition = 'good';
}

$cat = $categoryId
    ? DB::fetch('SELECT name, slug FROM categories WHERE id = ?', [$categoryId])
    : null;

$categoryLabel = $cat ? category_label($cat['slug'], $cat['name']) : 'عمومی';
$demandLevel   = ai_demand_level($categoryId);
$similar       = $categoryId > 0 ? ai_fetch_similar_listings($categoryId, 6) : [];

$listing = [
    'title'           => $title,
    'description'     => $description,
    'condition'       => $condition,
    'category_id'     => $categoryId,
    'category_label'  => $categoryLabel,
    'demand_level'    => $demandLevel,
];

$geminiMessages = ai_pricing_build_gemini_messages($listing, $similar);
$providers      = ai_pricing_client_providers($geminiMessages);
if (empty($providers)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'ai_not_configured']);
    exit;
}

// Server IP is often blocked (403) by Groq/OpenRouter; same as chat:
// prepare mode:pricing messages here, complete from the visitor browser.
$fallback = ai_sanitize_pricing_for_client(ai_price_listing_fallback($listing));

echo json_encode([
    'ok'          => true,
    'type'        => 'client_prepare',
    'messages'    => ai_pricing_build_messages($listing, $similar),
    'temperature' => 0.10,
    'max_tokens'  => 2048,
    'providers'   => $providers,
    'fallback'    => $fallback,
    'meta'        => [
        'similar_count' => count($similar),
        'category_id'   => $categoryId,
        'demand_level'  => $demandLevel,
    ],
], JSON_UNESCAPED_UNICODE);
