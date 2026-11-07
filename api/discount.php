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
rate_limit_ip_or_fail('discount_validate', 60, 3600, true);

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$code   = (string)($_POST['code'] ?? '');
$amount = (int)($_POST['amount'] ?? 0);

$result = discount_code_validate($code, $amount);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'message' => $result['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'       => true,
    'code'     => (string)$result['row']['code'],
    'type'     => (string)$result['row']['type'],
    'discount' => (int)$result['discount'],
    'final'    => (int)$result['final'],
    'message'  => discount_code_description($result['row']),
], JSON_UNESCAPED_UNICODE);
