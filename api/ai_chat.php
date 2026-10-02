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
rate_limit_ip_or_fail('ai_chat', 60, 3600, true);

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

rate_limit_user_or_fail(
    'ai_chat',
    (int) $user['id'],
    ai_limit('CHAT_USER', 30),
    ai_window('CHAT_USER', 3600),
    true
);

$message = trim(clean($_POST['message'] ?? ''));
if (mb_strlen($message) < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_message']);
    exit;
}
if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message_too_long']);
    exit;
}

$historyRaw = $_POST['history'] ?? '[]';
$history    = json_decode($historyRaw, true);
if (!is_array($history)) {
    $history = [];
}

$providers = ai_client_providers();
if (empty($providers)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'ai_not_configured']);
    exit;
}

$isPricing = str_contains($message, '"mode":"pricing"') || str_contains($message, 'pricing');

echo json_encode([
    'ok'               => true,
    'type'             => 'client_prepare',
    'messages'         => ai_chat_build_messages($message, $history, $user),
    'temperature'      => 0.35,
    'max_tokens'       => $isPricing ? 500 : 1200,
    'providers'        => $providers,
    'fallback_message' => ai_chat_fallback($message),
], JSON_UNESCAPED_UNICODE);
