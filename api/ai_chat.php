<?php
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

$result = ai_chat_respond($message, $history, $user);

echo json_encode([
    'ok'      => true,
    'type'    => 'chat',
    'message' => $result['message'],
    'source'  => groq_is_configured() ? 'groq' : 'fallback',
], JSON_UNESCAPED_UNICODE);
