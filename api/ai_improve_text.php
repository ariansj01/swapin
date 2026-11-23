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
rate_limit_ip_or_fail('ai_improve_text', 60, 3600, true);

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

rate_limit_user_or_fail(
    'ai_improve_text',
    (int) $user['id'],
    ai_limit('IMPROVE_USER', 30),
    ai_window('IMPROVE_USER', 3600),
    true
);

$field = (string)($_POST['field'] ?? '');
if (!in_array($field, ['title', 'description'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_field']);
    exit;
}

$text          = trim(clean($_POST['text'] ?? ''));
$contextTitle  = trim(clean($_POST['title'] ?? ''));
$categoryLabel = trim(clean($_POST['category_label'] ?? ''));
$condition     = trim(clean($_POST['condition'] ?? 'good'));

if (mb_strlen($text) < 3) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'text_too_short']);
    exit;
}
$text = mb_substr($text, 0, 1500);

if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
    $condition = 'good';
}

$providers = ai_client_providers();
if (empty($providers)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'ai_not_configured']);
    exit;
}

// Server IP is often blocked (403) by Groq/OpenRouter; build the messages here
// and complete the request from the visitor browser (same as ai_chat/ai_valuate).
echo json_encode([
    'ok'          => true,
    'type'        => 'client_prepare',
    'messages'    => ai_improve_text_build_messages($field, $text, $contextTitle, $categoryLabel, $condition),
    'temperature' => 0.55,
    'max_tokens'  => $field === 'title' ? 200 : 900,
    'providers'   => $providers,
], JSON_UNESCAPED_UNICODE);
