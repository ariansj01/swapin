<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/user_chat.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid    = (int)$user['id'];
$convId = (int)($_GET['conversation_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail(true);
    rate_limit_ip_or_fail('user_chat_send', 60, 60, true);

    $raw   = file_get_contents('php://input');
    $input = json_decode((string)$raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $convId = (int)($input['conversation_id'] ?? $convId);
    $body   = (string)($input['body'] ?? '');

    $res = user_chat_send($convId, $uid, $body);
    if (!empty($res['error'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $res['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'      => true,
        'message' => [
            'id'   => (int)$res['id'],
            'body' => $body,
            'date' => persian_date($res['created_at']),
            'mine' => true,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── دریافت پیام‌های جدید ──────────────────────────────────────
if ($convId <= 0 || !user_chat_conversation($convId, $uid)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'conversation_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$afterId  = (int)($_GET['after_id'] ?? 0);
$messages = user_chat_messages($convId, $afterId, 100);
user_chat_mark_read($convId, $uid);

$out = [];
foreach ($messages as $m) {
    $out[] = [
        'id'   => (int)$m['id'],
        'body' => (string)$m['body'],
        'date' => persian_date($m['created_at']),
        'mine' => (int)$m['sender_id'] === $uid,
    ];
}

echo json_encode(['ok' => true, 'messages' => $out], JSON_UNESCAPED_UNICODE);
