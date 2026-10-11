<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/admin.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

if ($action === 'start') {
    rate_limit_ip_or_fail('live_chat_start', 5, 300, true);

    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? '');
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
        exit;
    }

    $result = live_chat_start($input['name'] ?? '', $input['phone'] ?? '');
    if (isset($result['errors'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $result['errors']]);
        exit;
    }
    echo json_encode(['ok' => true, 'token' => $result['token']]);
    exit;
}

if ($action === 'send') {
    $token = (string)($input['token'] ?? '');
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_token']);
        exit;
    }
    $result = live_chat_send_message($token, $input['body'] ?? '');
    if (isset($result['error'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['error']]);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'messages') {
    $token   = (string)($input['token'] ?? '');
    $afterId = (int)($input['after_id'] ?? 0);
    if ($token === '') {
        echo json_encode(['messages' => []]);
        exit;
    }
    $messages = live_chat_get_messages($token, $afterId);
    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

/* ── Admin actions ─────────────────────────────────────────────────────── */

$admin = auth_admin();

if (str_starts_with($action, 'admin_') && !$admin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if ($action === 'admin_list') {
    $chats = live_chat_admin_list();
    echo json_encode(['ok' => true, 'chats' => $chats]);
    exit;
}

if ($action === 'admin_messages') {
    $chatId  = (int)($input['chat_id'] ?? 0);
    $afterId = (int)($input['after_id'] ?? 0);
    if ($chatId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_chat_id']);
        exit;
    }
    $detail = live_chat_admin_get($chatId);
    if (!$detail) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    $messages = $detail['messages'];
    if ($afterId > 0) {
        $messages = array_values(array_filter($messages, fn($m) => (int)$m['id'] > $afterId));
    }
    echo json_encode([
        'ok'       => true,
        'chat'     => $detail['chat'],
        'messages' => $messages,
    ]);
    exit;
}

if ($action === 'admin_reply') {
    $chatId = (int)($input['chat_id'] ?? 0);
    $body   = (string)($input['body'] ?? '');
    if ($chatId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_chat_id']);
        exit;
    }
    $result = live_chat_admin_reply($chatId, (int)$admin['id'], $body);
    if (isset($result['error'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['error']]);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'admin_close') {
    $chatId = (int)($input['chat_id'] ?? 0);
    if ($chatId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_chat_id']);
        exit;
    }
    live_chat_admin_close($chatId);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action']);
