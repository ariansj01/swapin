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

rate_limit_ip_or_fail('listing_location', 180, 3600, true);

$action = clean($_GET['action'] ?? '');
$city   = clean($_GET['city'] ?? '');

if ($action === 'city_center') {
    if ($city === '' || !in_array($city, iran_cities(), true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_city'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $center = get_city_map_center($city);

    echo json_encode([
        'ok'            => true,
        'city'          => $city,
        'lat'           => $center['lat'],
        'lng'           => $center['lng'],
        'approximate'   => !empty($center['approximate']),
        'neighborhoods' => iran_city_neighborhood_names($city),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'neighborhoods') {
    if ($city === '' || !in_array($city, iran_cities(), true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_city'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'            => true,
        'city'          => $city,
        'neighborhoods' => iran_city_neighborhood_names($city),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'detect') {
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

    if ($city === '' || !in_array($city, iran_cities(), true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_city'], JSON_UNESCAPED_UNICODE);
        exit;
    }

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

    $neighborhood = detect_neighborhood_by_coords($lat, $lng, $city);

    echo json_encode([
        'ok'            => true,
        'city'          => $city,
        'lat'           => $lat,
        'lng'           => $lng,
        'neighborhood'  => $neighborhood,
        'neighborhoods' => iran_city_neighborhood_names($city),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action'], JSON_UNESCAPED_UNICODE);
