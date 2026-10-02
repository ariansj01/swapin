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

// Must go through ai_call('pricing', …) — never wrap pricing inside mode:chat.
$result = ai_price_listing($listing, $similar);
$fallbackUsed = false;
$provider = is_array($result) ? ($result['ai_source'] ?? null) : null;

if (!$result) {
    $fallbackUsed = true;
    $result = ai_price_listing_fallback($listing);
    $provider = null;
}

$debug = [
    'fallback_used'   => $fallbackUsed,
    'similar_count'   => count($similar),
    'category_id'     => $categoryId,
    'demand_level'    => $demandLevel,
];

$logFile = ai_log_dir() . DIRECTORY_SEPARATOR . 'ai_errors.log';
if (is_readable($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        $debug['recent_logs'] = array_slice($lines, -30);
    }
}

$response = array_merge(['ok' => true], ai_sanitize_pricing_for_client($result));
$response['provider'] = $provider && $provider !== 'fallback' ? $provider : null;
$response['fallback'] = $fallbackUsed;
$response['debug']    = $debug;

echo json_encode($response, JSON_UNESCAPED_UNICODE);
