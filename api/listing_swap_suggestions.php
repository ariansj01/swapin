<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/listing_nearby.php';
require_once __DIR__ . '/../includes/listing_swap_suggestions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('listing_swap_suggestions', 60, 3600, true);

$listingId = (int) ($_GET['listing_id'] ?? 0);
$radiusKm  = isset($_GET['radius_km']) ? (float) $_GET['radius_km'] : null;
$limit     = min(10, max(1, (int) ($_GET['limit'] ?? listing_swap_suggestions_limit())));
$refresh   = !empty($_GET['refresh']);

if ($listingId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_listing_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = listing_swap_suggestions_cached($listingId, $radiusKm, $refresh, $limit);

if (!$result['ok']) {
    $code = match ($result['error'] ?? '') {
        'listing_not_geolocated' => 404,
        default => 503,
    };
    http_response_code($code);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$result['suggestions'] = array_map('listing_swap_sanitize_suggestion', $result['suggestions'] ?? []);
$result['source'] = ai_public_mode($result['source'] ?? 'rules');

$user = auth_user();
if ($user && !empty($result['suggestions'])) {
    $sourceListing = listing_nearby_load_source($listingId);
    if ($sourceListing && (int) $sourceListing['user_id'] === (int) $user['id']) {
        $result['suggestions'] = listing_swap_offer_enrich_suggestions(
            $result['suggestions'],
            (int) $user['id'],
            $listingId,
            $sourceListing
        );
        $result['offer_context'] = [
            'source_listing_id'   => $listingId,
            'source_title'        => (string) ($sourceListing['title'] ?? ''),
            'can_send_offer'      => listing_swap_offer_listing_swappable($sourceListing),
            'default_message'     => listing_swap_offer_default_message(),
        ];
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
