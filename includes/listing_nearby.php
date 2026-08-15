<?php
/**
 * Nearby listings module — distance calculation, map payload, swap candidate scoring.
 */
require_once __DIR__ . '/geo.php';

function listing_nearby_default_radius_km(): float {
    return defined('LISTING_NEARBY_DEFAULT_RADIUS_KM') ? (float) LISTING_NEARBY_DEFAULT_RADIUS_KM : 15.0;
}

function listing_nearby_max_radius_km(): float {
    return defined('LISTING_NEARBY_MAX_RADIUS_KM') ? (float) LISTING_NEARBY_MAX_RADIUS_KM : 50.0;
}

function listing_nearby_parse_radius(?float $radiusKm = null): float {
    $radius = $radiusKm ?? listing_nearby_default_radius_km();
    return max(1.0, min(listing_nearby_max_radius_km(), (float) $radius));
}

function listing_has_coordinates(array $listing): bool {
    return listing_has_geo_columns()
        && isset($listing['latitude'], $listing['longitude'])
        && $listing['latitude'] !== null
        && $listing['longitude'] !== null
        && is_finite((float) $listing['latitude'])
        && is_finite((float) $listing['longitude']);
}

function listing_nearby_distance_score(float $distanceKm, float $radiusKm): int {
    if ($radiusKm <= 0) {
        return 0;
    }
    $ratio = min(1.0, max(0.0, $distanceKm / $radiusKm));
    return (int) round((1.0 - $ratio) * 100);
}

/** @return array<string,mixed>|null */
function listing_nearby_load_source(int $listingId): ?array {
    $row = DB::fetch(
        'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
                c.name AS cat_name, c.slug AS cat_slug, c.parent_id AS cat_parent_id,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         WHERE l.id = ?',
        [$listingId]
    );
    if (!$row || !listing_has_coordinates($row)) {
        return null;
    }
    if ($row['status'] !== 'active' || ($row['review_status'] ?? 'approved') !== 'approved') {
        return null;
    }
    return $row;
}

function listing_nearby_format_distance(float $distanceKm): string {
    if ($distanceKm < 1.0) {
        return fmt_num((int) round($distanceKm * 1000)) . ' متر';
    }
    return fmt_num(round($distanceKm, 1)) . ' کیلومتر';
}

function listing_nearby_map_item(array $row, bool $isCurrent = false): array {
    $distance = isset($row['distance_km']) ? (float) $row['distance_km'] : 0.0;
    $listingId = (int) ($row['id'] ?? 0);
    $title = (string) ($row['title'] ?? '');
    $value = (float) ($row['estimated_value'] ?? 0);

    return [
        'listing_id'      => $listingId,
        'title'           => $title,
        'lat'             => (float) $row['latitude'],
        'lng'             => (float) $row['longitude'],
        'city'            => (string) ($row['city'] ?? ''),
        'neighborhood'    => (string) ($row['neighborhood'] ?? ''),
        'cat_name'        => (string) ($row['cat_name'] ?? ''),
        'thumb_url'       => !empty($row['thumb']) ? UPLOAD_URL . $row['thumb'] : '',
        'estimated_value' => $value,
        'value_fmt'       => $value > 0 ? fmt_credit($value) : '',
        'distance_km'     => round($distance, 2),
        'distance_fmt'    => $distance > 0 ? listing_nearby_format_distance($distance) : '',
        'url'             => APP_URL . '/listings/view?id=' . $listingId,
        'is_current'      => $isCurrent,
    ];
}

/**
 * Score how well a nearby listing could swap with the source listing.
 *
 * @return array<string,mixed>|null
 */
function listing_nearby_score_swap_candidate(array $source, array $candidate, float $distanceKm, float $radiusKm): ?array {
    if ((int) ($candidate['user_id'] ?? 0) === (int) ($source['user_id'] ?? 0)) {
        return null;
    }
    if (!in_array($candidate['listing_mode'] ?? 'swap', ['swap', 'both'], true)) {
        return null;
    }

    $theyWantSource = listing_wants_item($candidate, $source);
    $sourceWantsThem = listing_wants_item($source, $candidate);
    $loose = !$theyWantSource && !$sourceWantsThem && listing_loose_match($source, $candidate);
    if (!$theyWantSource && !$sourceWantsThem && !$loose) {
        return null;
    }

    $mutual = $theyWantSource && $sourceWantsThem;
    $scoreNeed = max(listing_wants_score($candidate, $source), listing_wants_score($source, $candidate));
    $scoreCat = listing_category_score($source, $candidate);
    $scoreValue = listing_value_score($source, $candidate);
    $scoreSuccess = listing_success_probability_score($candidate, $source, $mutual, $theyWantSource, $sourceWantsThem);

    $weightedScore = (int) round(
        $scoreNeed * 0.34 +
        $scoreCat * 0.22 +
        $scoreValue * 0.24 +
        $scoreSuccess * 0.20
    );
    if ($mutual) {
        $weightedScore = min(100, $weightedScore + 8);
    }
    $weightedScore = max(0, min(100, $weightedScore));

    if (!swap_match_passes_quality_filter(
        $weightedScore,
        $mutual,
        $theyWantSource,
        $sourceWantsThem,
        $scoreNeed,
        $scoreCat,
        $scoreValue
    )) {
        return null;
    }

    $distanceScore = listing_nearby_distance_score($distanceKm, $radiusKm);
    $combinedScore = (int) round($weightedScore * 0.82 + $distanceScore * 0.18);

    return array_merge($candidate, [
        'match_score'       => $combinedScore,
        'swap_score'        => $weightedScore,
        'distance_score'    => $distanceScore,
        'distance_km'       => round($distanceKm, 2),
        'distance_fmt'      => listing_nearby_format_distance($distanceKm),
        'mutual'            => $mutual,
        'they_want_source'  => $theyWantSource,
        'source_wants_them' => $sourceWantsThem,
        'score_need'        => $scoreNeed,
        'score_category'    => $scoreCat,
        'score_value'       => $scoreValue,
        'score_success'     => $scoreSuccess,
    ]);
}

function listing_nearby_rule_reason(array $match): string {
    if (!empty($match['mutual'])) {
        return 'هر دو طرف دقیقاً آنچه طرف دیگر می‌خواهد را در «نیازمند» ذکر کرده‌اند.';
    }
    if (!empty($match['they_want_source'])) {
        return 'صاحب این آگهی احتمالاً به دنبال کالای شماست — گزینه مناسب برای معاوضه.';
    }
    if (!empty($match['source_wants_them'])) {
        return 'این آگهی با چیزی که صاحب آگهی فعلی می‌خواهد هم‌خوانی دارد.';
    }
    $score = (int) ($match['swap_score'] ?? $match['match_score'] ?? 0);
    if ($score >= 65) {
        return 'هم‌خوانی دسته و ارزش — پیشنهاد اولیه برای معاوضه در نزدیکی شما.';
    }
    return 'هم‌خوانی اولیه دسته یا کلیدواژه — ارزش بررسی برای معاوضه.';
}

/**
 * @return array{ok:bool,source?:array,center?:array,radius_km?:float,listings?:array,total?:int,error?:string}
 */
function listing_nearby_fetch(int $listingId, ?float $radiusKm = null, int $limit = 24): array {
    if (!listing_has_geo_columns()) {
        return ['ok' => false, 'error' => 'geo_unavailable'];
    }

    $source = listing_nearby_load_source($listingId);
    if (!$source) {
        return ['ok' => false, 'error' => 'listing_not_geolocated'];
    }

    $radiusKm = listing_nearby_parse_radius($radiusKm);
    $limit = max(1, min($limit, 50));

    $lat = (float) $source['latitude'];
    $lng = (float) $source['longitude'];

    $rows = find_nearby_listings($lat, $lng, $radiusKm, $limit + 5, $listingId);

    $listings = [];
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $listingId) {
            continue;
        }
        $listings[] = listing_nearby_map_item($row);
        if (count($listings) >= $limit) {
            break;
        }
    }

    return [
        'ok'        => true,
        'source'    => listing_nearby_map_item($source, true),
        'center'    => ['lat' => $lat, 'lng' => $lng],
        'radius_km' => $radiusKm,
        'listings'  => $listings,
        'total'     => count($listings),
    ];
}

/** @return list<array<string,mixed>> */
function listing_nearby_swap_candidates(array $source, float $radiusKm, int $poolLimit = 40): array {
    $lat = (float) $source['latitude'];
    $lng = (float) $source['longitude'];
    $rows = find_nearby_listings($lat, $lng, $radiusKm, $poolLimit + 5, (int) $source['id']);

    $scored = [];
    foreach ($rows as $row) {
        $distanceKm = (float) ($row['distance_km'] ?? 0);
        $match = listing_nearby_score_swap_candidate($source, $row, $distanceKm, $radiusKm);
        if ($match) {
            $scored[] = $match;
        }
    }

    usort($scored, static fn(array $a, array $b): int => ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));

    return $scored;
}

function listing_nearby_suggestion_payload(array $row): array {
    return [
        'listing_id'      => (int) ($row['id'] ?? 0),
        'title'           => mb_strimwidth((string) ($row['title'] ?? ''), 0, 120, '…'),
        'category'        => category_label($row['cat_slug'] ?? '', $row['cat_name'] ?? ''),
        'condition'       => (string) ($row['condition'] ?? ''),
        'wanted_item'     => mb_strimwidth((string) ($row['want_in_return'] ?? ''), 0, 180, '…'),
        'estimated_value' => (float) ($row['estimated_value'] ?? 0),
        'city'            => (string) ($row['city'] ?? ''),
        'neighborhood'    => (string) ($row['neighborhood'] ?? ''),
        'distance_km'     => round((float) ($row['distance_km'] ?? 0), 1),
        'rule_score'      => (int) ($row['swap_score'] ?? $row['match_score'] ?? 0),
        'mutual'          => !empty($row['mutual']),
    ];
}

function listing_nearby_format_suggestion(array $row, string $reason, string $source = 'rules'): array {
    $listingId = (int) ($row['id'] ?? 0);
    $value = (float) ($row['estimated_value'] ?? 0);

    return [
        'listing_id'      => $listingId,
        'title'           => (string) ($row['title'] ?? ''),
        'seller_name'     => (string) ($row['seller_name'] ?? ''),
        'thumb'           => $row['thumb'] ?? null,
        'cat_name'        => (string) ($row['cat_name'] ?? ''),
        'want_in_return'  => (string) ($row['want_in_return'] ?? ''),
        'estimated_value' => $value,
        'value_fmt'       => $value > 0 ? fmt_credit($value) : '',
        'match_score'     => max(0, min(100, (int) ($row['match_score'] ?? 0))),
        'distance_km'     => round((float) ($row['distance_km'] ?? 0), 2),
        'distance_fmt'    => listing_nearby_format_distance((float) ($row['distance_km'] ?? 0)),
        'reason'          => $reason,
        'mutual'          => !empty($row['mutual']),
        'url'             => APP_URL . '/listings/view?id=' . $listingId,
        'source'          => $source,
    ];
}

function listing_nearby_suggestions_from_rules(array $candidates, int $limit): array {
    $out = [];
    foreach ($candidates as $c) {
        $out[] = listing_nearby_format_suggestion($c, listing_nearby_rule_reason($c), 'rules');
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
