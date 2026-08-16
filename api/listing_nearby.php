<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/listing_nearby.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('listing_nearby', 120, 3600, true);

$listingId = (int) ($_GET['listing_id'] ?? 0);
$radiusKm  = isset($_GET['radius_km']) ? (float) $_GET['radius_km'] : null;
$limit     = min(50, max(1, (int) ($_GET['limit'] ?? 24)));
$sort      = isset($_GET['sort']) ? (string) $_GET['sort'] : null;
$smartRadius = !empty($_GET['smart_radius']);

if ($listingId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_listing_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = listing_nearby_fetch($listingId, $smartRadius ? null : $radiusKm, $limit, $sort, $smartRadius);

if (!$result['ok']) {
    $code = match ($result['error'] ?? '') {
        'listing_not_geolocated' => 404,
        default => 503,
    };
    http_response_code($code);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
