<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('nearby_cities', 120, 3600, true);

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_coords'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!geo_coords_in_iran($lat, $lng)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'coords_out_of_range'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cities = find_nearby_iran_cities($lat, $lng);

if ($cities === []) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'no_cities_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'      => true,
    'cities'  => $cities,
    'nearest' => $cities[0],
], JSON_UNESCAPED_UNICODE);
