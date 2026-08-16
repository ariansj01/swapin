<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_verify_or_fail(true);

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('listing_swap_feedback', 60, 3600, true);
rate_limit_user_or_fail('listing_swap_feedback', (int) $user['id'], 30, 3600, true);

$body = listing_swap_feedback_read_request_body();
$suggestedListingId = (int) ($body['suggested_listing_id'] ?? 0);
$feedback = (string) ($body['feedback'] ?? '');
$reason = isset($body['reason']) ? (string) $body['reason'] : null;

if ($suggestedListingId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_suggested_listing_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = listing_swap_feedback_save((int) $user['id'], $suggestedListingId, $feedback, $reason);

if (!$result['ok']) {
    $code = match ($result['error'] ?? '') {
        'suggestion_not_displayed', 'listing_not_found', 'invalid_pair' => 403,
        'invalid_feedback', 'invalid_input', 'invalid_suggested_listing_id' => 422,
        default => 400,
    };
    http_response_code($code);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'      => true,
    'updated' => !empty($result['updated']),
], JSON_UNESCAPED_UNICODE);
