<?php

function iran_city_coordinates(): array {
    static $coords = null;
    if ($coords !== null) {
        return $coords;
    }

    $path = __DIR__ . '/../data/iran_city_coords.json';
    if (!is_readable($path)) {
        $coords = [];
        return $coords;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    $coords = is_array($decoded) ? $decoded : [];

    return $coords;
}

function geo_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function find_nearby_iran_cities(float $lat, float $lng, int $limit = 8, float $maxKm = 100.0): array {
    $limit = max(1, min($limit, 20));
    $maxKm = max(10.0, min($maxKm, 250.0));

    $knownCoords = iran_city_coordinates();
    $allowed = array_flip(iran_cities());
    $distances = [];

    foreach ($knownCoords as $city => $pair) {
        if (!isset($allowed[$city]) || !is_array($pair) || count($pair) < 2) {
            continue;
        }

        $cityLat = (float)$pair[0];
        $cityLng = (float)$pair[1];
        $distance = geo_haversine_km($lat, $lng, $cityLat, $cityLng);

        if ($distance <= $maxKm) {
            $distances[] = [
                'city' => $city,
                'distance_km' => $distance,
            ];
        }
    }

    usort($distances, static fn(array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);

    if ($distances === []) {
        $fallback = [];
        foreach ($knownCoords as $city => $pair) {
            if (!isset($allowed[$city]) || !is_array($pair) || count($pair) < 2) {
                continue;
            }
            $fallback[] = [
                'city' => $city,
                'distance_km' => geo_haversine_km($lat, $lng, (float)$pair[0], (float)$pair[1]),
            ];
        }
        usort($fallback, static fn(array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);
        $distances = array_slice($fallback, 0, $limit);
    } else {
        $distances = array_slice($distances, 0, $limit);
    }

    return array_values(array_map(static fn(array $row): string => $row['city'], $distances));
}

function parse_nearby_cities_param(string $raw): array {
    if ($raw === '') {
        return [];
    }

    $allowed = array_flip(iran_cities());
    $cities = [];

    foreach (explode(',', $raw) as $city) {
        $city = trim($city);
        if ($city !== '' && isset($allowed[$city])) {
            $cities[] = $city;
        }
    }

    return array_values(array_unique($cities));
}

function format_nearby_cities_title(array $cities): string {
    if ($cities === []) {
        return 'همه آگهی‌ها';
    }

    if (count($cities) === 1) {
        return 'آگهی‌های شهر ' . $cities[0];
    }

    $shown = array_slice($cities, 0, 3);
    $title = 'آگهی‌های شهر ' . implode('، ', $shown);

    if (count($cities) > 3) {
        $title .= ' و حومه';
    }

    return $title;
}

function geo_coords_in_iran(float $lat, float $lng): bool {
    return $lat >= 24.0 && $lat <= 40.5 && $lng >= 44.0 && $lng <= 64.0;
}

function listing_has_geo_columns(): bool {
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $has = db_has_column('listings', 'latitude')
        && db_has_column('listings', 'longitude')
        && db_has_column('listings', 'neighborhood');
    return $has;
}

function get_city_center_coords(string $city): ?array {
    $coords = iran_city_coordinates();
    if (isset($coords[$city]) && is_array($coords[$city]) && count($coords[$city]) >= 2) {
        return [
            'lat' => (float)$coords[$city][0],
            'lng' => (float)$coords[$city][1],
        ];
    }
    return null;
}

/** Center for map UI — exact city coords, or Iran midpoint as fallback. */
function get_city_map_center(string $city): array {
    $center = get_city_center_coords($city);
    if ($center) {
        return $center + ['approximate' => false];
    }
    return [
        'lat' => 32.4279,
        'lng' => 53.6880,
        'approximate' => true,
    ];
}

function iran_city_neighborhoods(string $city): array {
    static $cache = null;
    if ($cache === null) {
        $path = __DIR__ . '/../data/iran_neighborhoods.json';
        $decoded = is_readable($path)
            ? json_decode((string)file_get_contents($path), true)
            : [];
        $cache = is_array($decoded) ? $decoded : [];
    }
    $rows = $cache[$city] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['name'])) {
            continue;
        }
        $out[] = [
            'name' => (string)$row['name'],
            'lat'  => (float)($row['lat'] ?? 0),
            'lng'  => (float)($row['lng'] ?? 0),
        ];
    }
    return $out;
}

function iran_city_neighborhood_names(string $city): array {
    return array_values(array_map(
        static fn(array $row): string => $row['name'],
        iran_city_neighborhoods($city)
    ));
}

function detect_neighborhood_by_coords(float $lat, float $lng, string $city): string {
    $neighborhoods = iran_city_neighborhoods($city);
    if ($neighborhoods === []) {
        return 'مرکز شهر';
    }

    $nearest = $neighborhoods[0]['name'];
    $minDist = PHP_FLOAT_MAX;
    foreach ($neighborhoods as $row) {
        $distance = geo_haversine_km($lat, $lng, $row['lat'], $row['lng']);
        if ($distance < $minDist) {
            $minDist = $distance;
            $nearest = $row['name'];
        }
    }
    return $nearest;
}

function listing_location_from_request(array $input): array {
    $city = trim((string)($input['city'] ?? ''));
    $neighborhood = trim((string)($input['neighborhood'] ?? ''));
    $latRaw = trim((string)($input['latitude'] ?? ''));
    $lngRaw = trim((string)($input['longitude'] ?? ''));

    return [
        'city'         => $city,
        'neighborhood' => $neighborhood,
        'latitude'     => $latRaw !== '' ? (float)$latRaw : null,
        'longitude'    => $lngRaw !== '' ? (float)$lngRaw : null,
    ];
}

function validate_listing_location(array $location): array { 
    $errors = [];
    $city = $location['city'] ?? '';
    $neighborhood = $location['neighborhood'] ?? '';
    $lat = $location['latitude'] ?? null;
    $lng = $location['longitude'] ?? null;

    if ($city === '') {
        $errors['city'] = 'انتخاب شهر الزامی است.';
    } elseif (!in_array($city, iran_cities(), true)) {
        $errors['city'] = 'لطفاً شهر را از فهرست انتخاب کنید.';
    }

    if ($lat === null || $lng === null || !is_finite((float)$lat) || !is_finite((float)$lng)) {
        $errors['location'] = 'انتخاب موقعیت روی نقشه الزامی است.';
    } elseif (!geo_coords_in_iran((float)$lat, (float)$lng)) {
        $errors['location'] = 'موقعیت انتخاب‌شده خارج از محدوده ایران است.';
    }

    if ($neighborhood === '') {
        $errors['neighborhood'] = 'انتخاب محله الزامی است.';
    } elseif (mb_strlen($neighborhood) > 120) {
        $errors['neighborhood'] = 'نام محله خیلی طولانی است.';
    } else {
        $allowed = iran_city_neighborhood_names($city);
        if ($allowed !== [] && !in_array($neighborhood, $allowed, true)) {
            $errors['neighborhood'] = 'لطفاً محله را از فهرست همان شهر انتخاب کنید.';
        }
    }

    return $errors;
}

function listing_location_db_payload(array $location): array {
    if (!listing_has_geo_columns()) {
        return [];
    }
    return [
        'latitude'     => $location['latitude'],
        'longitude'    => $location['longitude'],
        'neighborhood' => $location['neighborhood'] ?: null,
    ];
}

function listing_location_enqueue_assets(): void {
    $cssPath = __DIR__ . '/../src/css/listing-location.css';
    $jsPath = __DIR__ . '/../src/js/listing-location.js';
    $cssVer = is_readable($cssPath) ? filemtime($cssPath) : time();
    $jsVer  = is_readable($jsPath)  ? filemtime($jsPath)  : time();

    echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">\n';
    echo '<link rel="stylesheet" href="' . APP_URL . '/src/css/listing-wizard.css">\n';
    echo '<link rel="stylesheet" href="' . APP_URL . '/src/css/listing-location.css?v=' . $cssVer . '">\n';
    echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>\n';
    echo '<script src="' . APP_URL . '/src/js/listing-location.js?v=' . $jsVer . '"></script>\n';
}

/**
 * Render city select and include the picker fragment for forms that use the listing-location UI.
 * Expects $vals array with keys: city, latitude, longitude, neighborhood and $errors array.
 */
function listing_location_render_picker(array $vals, array $errors = []): void {
    $city = $vals['city'] ?? '';
    $prefix = 'iso';

    // City select
    echo '<div class="form-group">';
    echo '<label class="form-label" for="' . h($prefix) . '-city">شهر *</label>';
    echo '<select name="city" id="' . h($prefix) . '-city" class="form-control">';
    echo '<option value="">انتخاب شهر</option>' . render_city_options($city);
    echo '</select>';
    if (!empty($errors['city'])) {
        echo '<div class="invalid-feedback d-block">' . h($errors['city']) . '</div>';
    }
    echo '</div>';

    // Picker include (will render hidden inputs, map container and neighborhood controls)
    $picker = [
        'prefix' => $prefix,
        'city' => $city,
        'latitude' => $vals['latitude'] ?? '',
        'longitude' => $vals['longitude'] ?? '',
        'neighborhood' => $vals['neighborhood'] ?? '',
        'errors' => $errors,
        'city_select_id' => $prefix . '-city',
        'control_class' => 'form-control',
        'label_class' => 'form-label',
        'input_class' => 'form-control',
    ];
    include __DIR__ . '/listing_location_picker.php';
}

/**
 * Output inline JS to ensure pickers are initialized and hook a simple validation handler.
 */
function listing_location_render_picker_inline_js(): void {
    echo "<script>\n";
    echo "(function(){ try { if (typeof initAllListingLocationPickers === 'function') initAllListingLocationPickers(); } catch(e){} })();\n";
    echo "</script>\n";
}

function find_nearby_listings(float $lat, float $lng, float $radiusKm = 10.0, int $limit = 24, ?int $excludeId = null): array {
    if (!listing_has_geo_columns()) {
        return [];
    }

    $limit = max(1, min($limit, 100));
    $radiusKm = max(0.5, min($radiusKm, 100.0));

    $latDelta = $radiusKm / 111.0;
    $lngDelta = $radiusKm / (111.0 * max(cos(deg2rad($lat)), 0.2));

    $params = [
        $lat, $lng, $lat,
        $lat - $latDelta, $lat + $latDelta, $lng - $lngDelta, $lng + $lngDelta,
        $lat, $lng, $lat, $radiusKm,
    ];

    $excludeSql = '';
    if ($excludeId) {
        $excludeSql = ' AND l.id != ?';
        $params[] = $excludeId;
    }

    $params[] = $limit;

    $distanceSql = '(6371 * acos(LEAST(1, GREATEST(-1,
        cos(radians(?)) * cos(radians(l.latitude)) * cos(radians(l.longitude) - radians(?))
        + sin(radians(?)) * sin(radians(l.latitude))
    ))))';

    return DB::fetchAll(
        "SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
                c.name AS cat_name, c.slug AS cat_slug, c.parent_id AS cat_parent_id,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb,
                {$distanceSql} AS distance_km
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         WHERE " . listing_public_sql('l') . "
           AND l.listing_mode != 'sell'
           AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL
           AND l.latitude BETWEEN ? AND ?
           AND l.longitude BETWEEN ? AND ?
           AND {$distanceSql} <= ?
           {$excludeSql}
         ORDER BY distance_km ASC, l.created_at DESC
         LIMIT ?",
        $params
    );
}
