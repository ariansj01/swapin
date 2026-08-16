<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    rate_limit_ip_or_fail('listing_swap_offers_read', 120, 3600, true);
    rate_limit_user_or_fail('listing_swap_offers_read', $userId, 60, 3600, true);

    $direction = (string) ($_GET['direction'] ?? 'received');
    if ($direction === 'received') {
        $offers = listing_swap_offer_list_received($userId);
    } elseif ($direction === 'sent') {
        $sourceListingId = isset($_GET['source_listing_id']) ? (int) $_GET['source_listing_id'] : null;
        if ($sourceListingId !== null && $sourceListingId > 0) {
            $owned = DB::fetch(
                'SELECT id FROM listings WHERE id = ? AND user_id = ?',
                [$sourceListingId, $userId]
            );
            if (!$owned) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'source_not_owned'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $offers = listing_swap_offer_list_sent($userId, $sourceListingId);
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_direction'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'offers' => $offers], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_verify_or_fail(true);

rate_limit_ip_or_fail('listing_swap_offers', 30, 3600, true);
rate_limit_user_or_fail('listing_swap_offers', $userId, 15, 3600, true);

$body = listing_swap_offer_read_request_body();
$targetListingId = (int) ($body['target_listing_id'] ?? 0);
$message = isset($body['message']) ? (string) $body['message'] : listing_swap_offer_default_message();

$result = listing_swap_offer_create($userId, $targetListingId, $message);

if (!$result['ok']) {
    $code = match ($result['error'] ?? '') {
        'source_not_owned', 'cannot_offer_own_listing', 'suggestion_context_not_found' => 403,
        'login_required' => 401,
        'invalid_target_listing_id', 'message_required', 'message_too_long' => 422,
        'duplicate_pending_offer' => 409,
        'target_not_found' => 404,
        'source_not_active', 'target_not_active' => 422,
        'kyc_blocked' => 403,
        default => 400,
    };
    http_response_code($code);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'           => true,
    'message_user' => $result['message_user'],
    'offer'        => $result['offer'],
], JSON_UNESCAPED_UNICODE);
