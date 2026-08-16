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

function listing_nearby_pool_limit(): int {
    return defined('LISTING_NEARBY_POOL_LIMIT') ? (int) LISTING_NEARBY_POOL_LIMIT : 60;
}

/** Nearby composite score weights — adjust here without changing structure. */
function listing_nearby_score_weights(): array {
    return [
        'relevance' => 0.50,
        'distance'  => 0.30,
        'freshness' => 0.20,
    ];
}

/** Relevance sub-score weights. */
function listing_nearby_relevance_weights(): array {
    return [
        'category' => 0.35,
        'want'     => 0.30,
        'text'     => 0.20,
        'mode'     => 0.15,
    ];
}

/**
 * Freshness buckets — see listing_freshness_buckets() in listing_freshness.php.
 */
function listing_nearby_freshness_buckets(): array {
    return listing_freshness_buckets();
}

function listing_nearby_freshness_score(?string $createdAt): int {
    return listing_freshness_score($createdAt);
}

function listing_nearby_text_stop_words(): array {
    return ['برای', 'با', 'یک', 'عدد', 'نو', 'خوب', 'عالی', 'مدل', 'اصل', 'و', 'در', 'از', 'به', 'که', 'این', 'آن', 'یا'];
}

function listing_nearby_significant_words(string $text): array {
    $text = mb_strtolower(trim($text));
    if ($text === '') {
        return [];
    }

    $words = preg_split('/[\s\/،,\+\-\|\.]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = listing_nearby_text_stop_words();

    return array_values(array_unique(array_filter(
        $words,
        static fn(string $w): bool => mb_strlen($w) >= 3 && !in_array($w, $stop, true)
    )));
}

function listing_nearby_listing_text_blob(array $listing): string {
    return mb_strtolower(trim(implode(' ', array_filter([
        (string) ($listing['title'] ?? ''),
        (string) ($listing['description'] ?? ''),
        (string) ($listing['want_in_return'] ?? ''),
        (string) ($listing['cat_name'] ?? ''),
    ]))));
}

function listing_nearby_text_relevance(array $source, array $candidate): int {
    $sourceWords = listing_nearby_significant_words(listing_nearby_listing_text_blob($source));
    if ($sourceWords === []) {
        return 25;
    }

    $candidateText = listing_nearby_listing_text_blob($candidate);
    $hits = 0;
    foreach ($sourceWords as $word) {
        if (mb_strpos($candidateText, $word) !== false) {
            $hits++;
        }
    }

    $ratio = $hits / count($sourceWords);
    return (int) round(min(100, 20 + $ratio * 80));
}

function listing_nearby_mode_relevance(array $source, array $candidate): int {
    $sourceMode = (string) ($source['listing_mode'] ?? 'swap');
    $candidateMode = (string) ($candidate['listing_mode'] ?? 'swap');

    if ($sourceMode === $candidateMode) {
        return 100;
    }
    if (in_array($sourceMode, ['swap', 'both'], true) && in_array($candidateMode, ['swap', 'both'], true)) {
        return 85;
    }

    return 45;
}

function listing_nearby_relevance_score(array $source, array $candidate): int {
    $weights = listing_nearby_relevance_weights();
    $scoreCat = listing_category_score($source, $candidate);
    $scoreWant = max(listing_wants_score($source, $candidate), listing_wants_score($candidate, $source));
    $scoreText = listing_nearby_text_relevance($source, $candidate);
    $scoreMode = listing_nearby_mode_relevance($source, $candidate);

    return max(0, min(100, (int) round(
        $scoreCat * $weights['category'] +
        $scoreWant * $weights['want'] +
        $scoreText * $weights['text'] +
        $scoreMode * $weights['mode']
    )));
}

/**
 * @return array{relevance_score:int,distance_score:int,freshness_score:int,nearby_score:int}
 */
function listing_nearby_compute_scores(array $source, array $candidate, float $distanceKm, float $radiusKm): array {
    $weights = listing_nearby_score_weights();
    $relevanceScore = listing_nearby_relevance_score($source, $candidate);
    $distanceScore = listing_nearby_distance_score($distanceKm, $radiusKm);
    $freshnessMeta = listing_freshness_meta($candidate);
    $freshnessScore = (int) $freshnessMeta['freshness_score'];

    $nearbyScore = (int) round(
        $relevanceScore * $weights['relevance'] +
        $distanceScore * $weights['distance'] +
        $freshnessScore * $weights['freshness']
    );

    return [
        'relevance_score' => max(0, min(100, $relevanceScore)),
        'distance_score'  => max(0, min(100, $distanceScore)),
        'freshness_score' => max(0, min(100, $freshnessScore)),
        'freshness_label' => (string) $freshnessMeta['freshness_label'],
        'nearby_score'    => max(0, min(100, $nearbyScore)),
    ];
}

function listing_nearby_parse_sort(?string $sort = null): string {
    return in_array($sort, ['distance', 'relevant'], true) ? $sort : 'distance';
}

function listing_nearby_sort_listings(array $listings, string $sort): array {
    if ($sort === 'relevant') {
        usort($listings, static function (array $a, array $b): int {
            $scoreCmp = ($b['nearby_score'] ?? 0) <=> ($a['nearby_score'] ?? 0);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            return ($a['distance_km'] ?? 0) <=> ($b['distance_km'] ?? 0);
        });
        return $listings;
    }

    usort($listings, static function (array $a, array $b): int {
        $distCmp = ($a['distance_km'] ?? 0) <=> ($b['distance_km'] ?? 0);
        if ($distCmp !== 0) {
            return $distCmp;
        }
        return ($b['nearby_score'] ?? 0) <=> ($a['nearby_score'] ?? 0);
    });

    return $listings;
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

function listing_nearby_location_fmt(array $row): string {
    $city = trim((string) ($row['city'] ?? ''));
    $hood = trim((string) ($row['neighborhood'] ?? ''));
    if ($city !== '' && $hood !== '') {
        return $city . ' — ' . $hood;
    }
    return $city !== '' ? $city : $hood;
}

function listing_nearby_relevance_hint(int $relevanceScore): string {
    if ($relevanceScore >= 75) {
        return 'هم‌خوانی بالا با این آگهی';
    }
    if ($relevanceScore >= 50) {
        return 'دسته یا محتوای مرتبط';
    }
    if ($relevanceScore >= 30) {
        return 'هم‌خوانی نسبی';
    }
    return 'در محدوده نزدیک';
}

function listing_nearby_map_item(array $row, bool $isCurrent = false, ?array $scores = null): array {
    $distance = isset($row['distance_km']) ? (float) $row['distance_km'] : 0.0;
    $listingId = (int) ($row['id'] ?? 0);
    $title = (string) ($row['title'] ?? '');
    $value = (float) ($row['estimated_value'] ?? 0);

    $item = [
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
        'location_fmt'    => listing_nearby_location_fmt($row),
        'url'             => APP_URL . '/listings/view?id=' . $listingId,
        'is_current'      => $isCurrent,
    ];

    if ($scores !== null) {
        $item['relevance_score'] = (int) ($scores['relevance_score'] ?? 0);
        $item['distance_score'] = (int) ($scores['distance_score'] ?? 0);
        $item['freshness_score'] = (int) ($scores['freshness_score'] ?? 0);
        $item['freshness_label'] = (string) ($scores['freshness_label'] ?? '');
        $item['nearby_score'] = (int) ($scores['nearby_score'] ?? 0);
        $item['nearby_score_fmt'] = fmt_num((int) ($scores['nearby_score'] ?? 0)) . '٪ مرتبط';
        $item['relevance_hint'] = listing_nearby_relevance_hint((int) ($scores['relevance_score'] ?? 0));
    }

    return $item;
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
 * Build human-readable match reasons from real scoring data.
 *
 * @return list<string>
 */
function listing_nearby_match_reasons(array $match, ?array $source = null): array {
    $reasons = [];

    if (!empty($match['mutual'])) {
        $reasons[] = 'معاوضه دوطرفه';
    }

    if (!empty($match['source_wants_them'])) {
        $reasons[] = 'مورد درخواستی آگهی شما را دارد';
    }

    if (!empty($match['they_want_source']) && empty($match['mutual'])) {
        $reasons[] = 'به دنبال این کالا هست';
    }

    $scoreValue = (int) ($match['score_value'] ?? 0);
    if ($scoreValue >= 58) {
        $reasons[] = 'ارزش نزدیک';
    }

    $scoreCat = (int) ($match['score_category'] ?? 0);
    if ($scoreCat >= 44) {
        $reasons[] = 'دسته‌بندی مناسب';
    }

    $sourceCity = trim((string) ($source['city'] ?? ''));
    $candidateCity = trim((string) ($match['city'] ?? ''));
    if ($sourceCity !== '' && $candidateCity !== '' && mb_strtolower($sourceCity) === mb_strtolower($candidateCity)) {
        $reasons[] = 'در همان شهر';
    }

    if ($reasons === []) {
        $reasons[] = 'هم‌خوانی اولیه برای معاوضه';
    }

    return array_values(array_unique($reasons));
}

/** Radius steps used for manual selection and smart-radius expansion. */
function listing_nearby_radius_steps(): array {
    return [5, 10, 15, 25, 50];
}

function listing_nearby_smart_thresholds(): array {
    return [
        'min_results'    => defined('LISTING_NEARBY_SMART_MIN_RESULTS') ? (int) LISTING_NEARBY_SMART_MIN_RESULTS : 3,
        'min_relevant'   => defined('LISTING_NEARBY_SMART_MIN_RELEVANT') ? (int) LISTING_NEARBY_SMART_MIN_RELEVANT : 2,
        'relevant_score' => defined('LISTING_NEARBY_SMART_RELEVANT_SCORE') ? (int) LISTING_NEARBY_SMART_RELEVANT_SCORE : 50,
    ];
}

function listing_nearby_is_relevant_candidate(array $scores, int $relevantThreshold): bool {
    return (int) ($scores['relevance_score'] ?? 0) >= $relevantThreshold;
}

/**
 * Count listings within a radius using pre-fetched rows.
 *
 * @return array{result_count:int,relevant_count:int}
 */
function listing_nearby_count_at_radius(array $source, array $rows, float $radiusKm, int $listingId, array $thresholds): array {
    $resultCount = 0;
    $relevantCount = 0;

    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $listingId) {
            continue;
        }
        $distanceKm = (float) ($row['distance_km'] ?? 0);
        if ($distanceKm > $radiusKm) {
            continue;
        }
        $scores = listing_nearby_compute_scores($source, $row, $distanceKm, $radiusKm);
        $resultCount++;
        if (listing_nearby_is_relevant_candidate($scores, $thresholds['relevant_score'])) {
            $relevantCount++;
        }
    }

    return [
        'result_count'   => $resultCount,
        'relevant_count' => $relevantCount,
    ];
}

function listing_nearby_has_sufficient_results(int $resultCount, int $relevantCount, array $thresholds): bool {
    return $resultCount >= $thresholds['min_results']
        && $relevantCount >= $thresholds['min_relevant'];
}

function listing_nearby_smart_radius_message(float $chosenRadius, float $startRadius, int $resultCount, int $relevantCount): string {
    $chosenFmt = fmt_num($chosenRadius);
    $startFmt = fmt_num($startRadius);

    if ($resultCount === 0) {
        return 'در شعاع ' . $chosenFmt . ' کیلومتر نیز آگهی مناسبی یافت نشد.';
    }

    if ($chosenRadius <= $startRadius) {
        return fmt_num($relevantCount) . ' آگهی مناسب در محدوده ' . $chosenFmt . ' کیلومتری پیدا شد.';
    }

    return 'در ' . $startFmt . ' کیلومتر نتیجه مناسبی پیدا نشد؛ محدوده را تا ' . $chosenFmt . ' کیلومتر افزایش دادیم.';
}

/**
 * Pick the best radius by expanding through predefined steps.
 *
 * @return array{radius_km:float,result_count:int,relevant_count:int,message:string,rows:list<array<string,mixed>>}
 */
function listing_nearby_resolve_smart_radius(array $source, int $listingId): array {
    $steps = listing_nearby_radius_steps();
    $thresholds = listing_nearby_smart_thresholds();
    $maxRadius = (float) end($steps);
    $startRadius = (float) $steps[0];

    $lat = (float) $source['latitude'];
    $lng = (float) $source['longitude'];
    $rows = find_nearby_listings($lat, $lng, $maxRadius, listing_nearby_pool_limit(), $listingId);

    $chosenRadius = $maxRadius;
    $counts = ['result_count' => 0, 'relevant_count' => 0];

    foreach ($steps as $radius) {
        $radius = (float) $radius;
        $counts = listing_nearby_count_at_radius($source, $rows, $radius, $listingId, $thresholds);
        $chosenRadius = $radius;

        if (listing_nearby_has_sufficient_results($counts['result_count'], $counts['relevant_count'], $thresholds)) {
            break;
        }
    }

    return [
        'radius_km'      => $chosenRadius,
        'result_count'   => $counts['result_count'],
        'relevant_count' => $counts['relevant_count'],
        'message'        => listing_nearby_smart_radius_message(
            $chosenRadius,
            $startRadius,
            $counts['result_count'],
            $counts['relevant_count']
        ),
        'rows'           => $rows,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function listing_nearby_build_listings(array $source, array $rows, float $radiusKm, int $listingId, int $limit, string $sort): array {
    $listings = [];

    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $listingId) {
            continue;
        }
        $distanceKm = (float) ($row['distance_km'] ?? 0);
        if ($distanceKm > $radiusKm) {
            continue;
        }
        $scores = listing_nearby_compute_scores($source, $row, $distanceKm, $radiusKm);
        $listings[] = listing_nearby_map_item($row, false, $scores);
    }

    $listings = listing_nearby_sort_listings($listings, $sort);

    return array_slice($listings, 0, $limit);
}

/**
 * @return array{ok:bool,source?:array,center?:array,radius_km?:float,sort?:string,smart_radius?:bool,result_count?:int,relevant_count?:int,message?:string,listings?:array,total?:int,error?:string}
 */
function listing_nearby_fetch(int $listingId, ?float $radiusKm = null, int $limit = 24, ?string $sort = null, bool $smartRadius = false): array {
    if (!listing_has_geo_columns()) {
        return ['ok' => false, 'error' => 'geo_unavailable'];
    }

    $source = listing_nearby_load_source($listingId);
    if (!$source) {
        return ['ok' => false, 'error' => 'listing_not_geolocated'];
    }

    $limit = max(1, min($limit, 50));
    $sort = listing_nearby_parse_sort($sort);

    $lat = (float) $source['latitude'];
    $lng = (float) $source['longitude'];

    $smartMeta = null;
    $rows = null;

    if ($smartRadius) {
        $smartMeta = listing_nearby_resolve_smart_radius($source, $listingId);
        $radiusKm = listing_nearby_parse_radius((float) $smartMeta['radius_km']);
        $rows = $smartMeta['rows'] ?? null;
    } else {
        $radiusKm = listing_nearby_parse_radius($radiusKm);
    }

    if ($rows === null) {
        $poolLimit = max($limit, min(listing_nearby_pool_limit(), $limit * 4));
        $rows = find_nearby_listings($lat, $lng, $radiusKm, $poolLimit, $listingId);
    }

    $listings = listing_nearby_build_listings($source, $rows, $radiusKm, $listingId, $limit, $sort);

    $result = [
        'ok'        => true,
        'source'    => listing_nearby_map_item($source, true),
        'center'    => ['lat' => $lat, 'lng' => $lng],
        'radius_km' => $radiusKm,
        'sort'      => $sort,
        'listings'  => $listings,
        'total'     => count($listings),
    ];

    if ($smartMeta) {
        $result['smart_radius'] = true;
        $result['result_count'] = $smartMeta['result_count'];
        $result['relevant_count'] = $smartMeta['relevant_count'];
        $result['message'] = $smartMeta['message'];
    }

    return $result;
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

function listing_nearby_format_suggestion(
    array $row,
    string $reason,
    string $sourceTag = 'rules',
    ?array $sourceListing = null,
    ?array $components = null,
    ?array $reasonsOverride = null
): array {
    $listingId = (int) ($row['id'] ?? 0);
    $value = (float) ($row['estimated_value'] ?? 0);
    $matchScore = max(0, min(100, (int) ($row['match_score'] ?? 0)));
    $distanceKm = round((float) ($row['distance_km'] ?? 0), 2);
    $reasons = $reasonsOverride ?? listing_nearby_match_reasons($row, $sourceListing);
    $freshness = listing_freshness_meta($row);

    $item = [
        'listing_id'      => $listingId,
        'title'           => (string) ($row['title'] ?? ''),
        'seller_name'     => (string) ($row['seller_name'] ?? ''),
        'thumb'           => $row['thumb'] ?? null,
        'cat_name'        => (string) ($row['cat_name'] ?? ''),
        'want_in_return'  => (string) ($row['want_in_return'] ?? ''),
        'estimated_value' => $value,
        'value_fmt'       => $value > 0 ? fmt_credit($value) : '',
        'match_score'     => $matchScore,
        'match_score_fmt' => fmt_num($matchScore) . '٪ مناسب برای معاوضه',
        'distance_km'     => $distanceKm,
        'distance_fmt'    => listing_nearby_format_distance((float) ($row['distance_km'] ?? 0)),
        'reason'          => $reason,
        'reasons'         => $reasons,
        'mutual'          => !empty($row['mutual']),
        'freshness_score' => (int) $freshness['freshness_score'],
        'freshness_label' => (string) $freshness['freshness_label'],
        'url'             => APP_URL . '/listings/view?id=' . $listingId,
        'source'          => $sourceTag,
    ];

    if ($components !== null) {
        $item['swap_compatibility'] = max(0, min(100, (int) ($components['swap_compatibility'] ?? 0)));
        $item['value_compatibility'] = max(0, min(100, (int) ($components['value_compatibility'] ?? 0)));
        $item['category_compatibility'] = max(0, min(100, (int) ($components['category_compatibility'] ?? 0)));
        $item['location_score'] = max(0, min(100, (int) ($components['location_score'] ?? 0)));
        if (isset($components['final_score'])) {
            $item['final_score'] = max(0, min(100, (int) $components['final_score']));
        }
        if (isset($components['confidence'])) {
            $item['confidence'] = max(0, min(100, (int) $components['confidence']));
        }
    }

    return $item;
}

function listing_nearby_suggestions_from_rules(array $candidates, int $limit, ?array $sourceListing = null): array {
    $out = [];
    foreach ($candidates as $c) {
        $components = [
            'swap_compatibility'     => max(0, min(100, (int) ($c['swap_score'] ?? $c['match_score'] ?? 0))),
            'value_compatibility'    => max(0, min(100, (int) ($c['score_value'] ?? 0))),
            'category_compatibility' => max(0, min(100, (int) ($c['score_category'] ?? 0))),
            'location_score'         => max(0, min(100, (int) ($c['distance_score'] ?? 0))),
            'final_score'            => max(0, min(100, (int) ($c['match_score'] ?? 0))),
        ];
        $out[] = listing_nearby_format_suggestion(
            $c,
            listing_nearby_rule_reason($c),
            'rules',
            $sourceListing,
            $components
        );
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
