<?php
require_once __DIR__ . '/../includes/config.php';

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
    'ok'         => true,
    'mail_sent'  => !empty($result['mail_sent']),
    'mail_error' => $result['mail_error'] ?? null,
    'via'        => $result['via'] ?? contact_mail_mode(),
]);
