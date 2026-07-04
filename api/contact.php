<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

rate_limit_ip_or_fail('contact_api', 10, 3600, true);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = csrf_token_from_request();
if ($csrfToken === '' && isset($input['_csrf'])) {
    $csrfToken = (string)$input['_csrf'];
}
if ($csrfToken === '' || !hash_equals(csrf_token(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
    exit;
}

$result = handle_contact_submission(
    $input['name'] ?? '',
    $input['email'] ?? '',
    $input['subject'] ?? '',
    $input['message'] ?? ''
);

if (isset($result['errors'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $result['errors']]);
    exit;
}

echo json_encode([
    'ok'        => true,
    'mail_sent' => !empty($result['mail_sent']),
    'message'   => !empty($result['mail_sent'])
        ? null
        : safe_mail_error($result['mail_error'] ?? null),
]);
