<?php
/**
 * Smart nearby swap suggestions — rules + optional AI component scoring.
 *
 * Flow: Backend filter → AI components → validation → backend final_score → sort → frontend.
 * AI analyzes; backend decides match_score.
 */

function listing_swap_suggestions_limit(): int {
    return defined('LISTING_SWAP_SUGGESTIONS_LIMIT') ? (int) LISTING_SWAP_SUGGESTIONS_LIMIT : 6;
}

function listing_swap_ai_pool_limit(): int {
    return defined('LISTING_SWAP_AI_POOL_LIMIT') ? (int) LISTING_SWAP_AI_POOL_LIMIT : 12;
}

function listing_swap_ai_max_reasons(): int {
    return defined('LISTING_SWAP_AI_MAX_REASONS') ? (int) LISTING_SWAP_AI_MAX_REASONS : 5;
}

function listing_swap_ai_min_valid_matches(): int {
    return defined('LISTING_SWAP_AI_MIN_VALID_MATCHES') ? (int) LISTING_SWAP_AI_MIN_VALID_MATCHES : 2;
}

/** @return array{swap:float,location:float} */
function listing_swap_final_score_weights(): array {
    return [
        'swap'     => defined('LISTING_SWAP_FINAL_SWAP_WEIGHT') ? (float) LISTING_SWAP_FINAL_SWAP_WEIGHT : 0.82,
        'location' => defined('LISTING_SWAP_FINAL_LOCATION_WEIGHT') ? (float) LISTING_SWAP_FINAL_LOCATION_WEIGHT : 0.18,
    ];
}

function listing_swap_ai_clamp_score(mixed $value): ?int {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return max(0, min(100, (int) round((float) $value)));
}

function listing_swap_ai_compute_final_score(int $swapCompatibility, int $locationScore): int {
    $weights = listing_swap_final_score_weights();
    return max(0, min(100, (int) round(
        $swapCompatibility * $weights['swap'] + $locationScore * $weights['location']
    )));
}

/** Rule-based component scores from existing candidate scoring. */
function listing_swap_rule_components(array $candidate): array {
    $distanceKm = (float) ($candidate['distance_km'] ?? 0);
    $locationScore = (int) ($candidate['distance_score'] ?? 0);
    if ($locationScore <= 0 && $distanceKm > 0) {
        $locationScore = listing_nearby_distance_score($distanceKm, listing_nearby_parse_radius(null));
    }

    return [
        'swap_compatibility'     => max(0, min(100, (int) ($candidate['swap_score'] ?? $candidate['match_score'] ?? 0))),
        'value_compatibility'    => max(0, min(100, (int) ($candidate['score_value'] ?? 0))),
        'category_compatibility' => max(0, min(100, (int) ($candidate['score_category'] ?? 0))),
        'location_score'         => max(0, min(100, $locationScore)),
    ];
}

/**
 * Keep only AI reasons that align with data-backed rule reasons.
 *
 * @return list<string>
 */
function listing_swap_ai_filter_reasons(array $aiReasons, array $allowedReasons): array {
    if ($allowedReasons === []) {
        return [];
    }

    $max = listing_swap_ai_max_reasons();
    $out = [];

    foreach ($aiReasons as $reason) {
        $reason = trim((string) $reason);
        if ($reason === '') {
            continue;
        }

        foreach ($allowedReasons as $allowed) {
            if ($reason === $allowed
                || mb_strpos($allowed, $reason) !== false
                || mb_strpos($reason, $allowed) !== false) {
                if (!in_array($allowed, $out, true)) {
                    $out[] = $allowed;
                }
                break;
            }
        }

        if (count($out) >= $max) {
            break;
        }
    }

    return $out;
}

/**
 * Validate a single AI match item.
 *
 * @return array<string,mixed>|null
 */
function listing_swap_ai_validate_match_item(array $item, int $maxReasons): ?array {
    $listingId = (int) ($item['listing_id'] ?? 0);
    if ($listingId <= 0) {
        return null;
    }

    $swapCompatibility = listing_swap_ai_clamp_score($item['swap_compatibility'] ?? null);
    $valueCompatibility = listing_swap_ai_clamp_score($item['value_compatibility'] ?? null);
    $categoryCompatibility = listing_swap_ai_clamp_score($item['category_compatibility'] ?? null);
    $locationScore = listing_swap_ai_clamp_score($item['location_score'] ?? null);
    $confidence = listing_swap_ai_clamp_score($item['confidence'] ?? null);

    if ($swapCompatibility === null
        || $valueCompatibility === null
        || $categoryCompatibility === null
        || $locationScore === null
        || $confidence === null) {
        return null;
    }

    $reasons = $item['reasons'] ?? null;
    if (!is_array($reasons)) {
        return null;
    }

    $reasons = array_values(array_filter(array_map(static function ($r): ?string {
        $r = trim((string) $r);
        return $r !== '' ? $r : null;
    }, $reasons)));

    if (count($reasons) > $maxReasons) {
        $reasons = array_slice($reasons, 0, $maxReasons);
    }

    return [
        'listing_id'              => $listingId,
        'swap_compatibility'      => $swapCompatibility,
        'value_compatibility'     => $valueCompatibility,
        'category_compatibility'  => $categoryCompatibility,
        'location_score'          => $locationScore,
        'confidence'              => $confidence,
        'reasons'                 => $reasons,
    ];
}

/**
 * Validate full AI response for swap suggestions mode.
 *
 * @return list<array<string,mixed>>|null
 */
function listing_swap_ai_validate_response(?array $parsed): ?array {
    if (!$parsed || ($parsed['type'] ?? '') !== 'swap_suggestions') {
        return null;
    }

    $matches = $parsed['matches'] ?? null;
    if (!is_array($matches) || $matches === []) {
        return null;
    }

    $maxReasons = listing_swap_ai_max_reasons();
    $validated = [];

    foreach ($matches as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = listing_swap_ai_validate_match_item($item, $maxReasons);
        if ($row !== null) {
            $validated[] = $row;
        }
    }

    return $validated !== [] ? $validated : null;
}

function listing_swap_source_payload(array $source): array {
    return [
        'listing_id'      => (int) $source['id'],
        'title'           => mb_strimwidth((string) ($source['title'] ?? ''), 0, 120, '…'),
        'description'     => mb_strimwidth((string) ($source['description'] ?? ''), 0, 220, '…'),
        'category'        => category_label($source['cat_slug'] ?? '', $source['cat_name'] ?? ''),
        'condition'       => (string) ($source['condition'] ?? ''),
        'wanted_item'     => mb_strimwidth((string) ($source['want_in_return'] ?? ''), 0, 180, '…'),
        'estimated_value' => (float) ($source['estimated_value'] ?? 0),
        'city'            => (string) ($source['city'] ?? ''),
        'neighborhood'    => (string) ($source['neighborhood'] ?? ''),
    ];
}

function listing_swap_ai_instruction(int $limit): string {
    return 'Analyze swap fit between SOURCE and each candidate. Return JSON type swap_suggestions. '
        . 'Each match MUST include: listing_id, swap_compatibility (0-100), value_compatibility (0-100), '
        . 'category_compatibility (0-100), location_score (0-100), reasons (array of short Persian strings), '
        . 'confidence (0-100). Do NOT return score, final_score, or match_score. '
        . 'Only include reasons you can justify from the provided listing data; omit uncertain reasons. '
        . 'confidence reflects certainty about available data, not overall match quality. '
        . 'Max ' . $limit . ' items.';
}

/**
 * @return array{suggestions:list<array<string,mixed>>,source:string}|null
 */
function listing_swap_suggestions_from_ai(array $source, array $candidates, int $limit): ?array {
    if (!ai_is_configured() || $candidates === []) {
        return null;
    }

    $candidatePayload = array_map('listing_nearby_suggestion_payload', array_slice($candidates, 0, listing_swap_ai_pool_limit()));

    $payload = [
        'source_listing'     => listing_swap_source_payload($source),
        'candidate_listings' => $candidatePayload,
        'instruction'        => listing_swap_ai_instruction($limit),
    ];

    $result = ai_call('swap_suggestions', $payload);
    $parsed = ai_parse_json_response($result['parsed'] ?? null);
    $provider = $result['provider'] ?? 'ai';

    $validatedMatches = listing_swap_ai_validate_response($parsed);
    if ($validatedMatches === null) {
        return null;
    }

    $byId = [];
    foreach ($candidates as $c) {
        $byId[(int) $c['id']] = $c;
    }

    $out = [];
    $seen = [];

    foreach ($validatedMatches as $aiItem) {
        $lid = (int) $aiItem['listing_id'];
        if (!$lid || !isset($byId[$lid]) || isset($seen[$lid])) {
            continue;
        }
        $seen[$lid] = true;
        $candidate = $byId[$lid];

        $allowedReasons = listing_nearby_match_reasons($candidate, $source);
        $reasons = listing_swap_ai_filter_reasons($aiItem['reasons'], $allowedReasons);
        if ($reasons === []) {
            $reasons = $allowedReasons;
        }

        $finalScore = listing_swap_ai_compute_final_score(
            (int) $aiItem['swap_compatibility'],
            (int) $aiItem['location_score']
        );

        $candidate['match_score'] = $finalScore;

        $components = [
            'swap_compatibility'     => (int) $aiItem['swap_compatibility'],
            'value_compatibility'    => (int) $aiItem['value_compatibility'],
            'category_compatibility' => (int) $aiItem['category_compatibility'],
            'location_score'         => (int) $aiItem['location_score'],
            'confidence'             => (int) $aiItem['confidence'],
            'final_score'            => $finalScore,
        ];

        $reason = $reasons[0] ?? listing_nearby_rule_reason($candidate);

        $out[] = listing_nearby_format_suggestion(
            $candidate,
            $reason,
            $provider,
            $source,
            $components,
            $reasons
        );

        if (count($out) >= $limit) {
            break;
        }
    }

    if (count($out) < listing_swap_ai_min_valid_matches()) {
        return null;
    }

    usort($out, static fn(array $a, array $b): int => ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));

    return [
        'suggestions' => $out,
        'source'      => $provider,
    ];
}

/**
 * @return array{ok:bool,suggestions?:array,source?:string,radius_km?:float,error?:string}
 */
function listing_swap_suggestions_fetch(int $listingId, ?float $radiusKm = null, ?int $limit = null): array {
    if (!listing_has_geo_columns()) {
        return ['ok' => false, 'error' => 'geo_unavailable'];
    }

    $source = listing_nearby_load_source($listingId);
    if (!$source) {
        return ['ok' => false, 'error' => 'listing_not_geolocated'];
    }

    if (($source['listing_mode'] ?? 'swap') === 'sell') {
        return ['ok' => true, 'suggestions' => [], 'source' => 'empty', 'radius_km' => listing_nearby_parse_radius($radiusKm)];
    }

    $radiusKm = listing_nearby_parse_radius($radiusKm);
    $limit = max(1, min($limit ?? listing_swap_suggestions_limit(), 10));

    $candidates = listing_nearby_swap_candidates($source, $radiusKm, listing_swap_ai_pool_limit() + 8);
    if ($candidates === []) {
        return [
            'ok'          => true,
            'suggestions' => [],
            'source'      => 'empty',
            'radius_km'   => $radiusKm,
        ];
    }

    $aiResult = listing_swap_suggestions_from_ai($source, $candidates, $limit);
    if ($aiResult) {
        return [
            'ok'          => true,
            'suggestions' => $aiResult['suggestions'],
            'source'      => ai_public_mode($aiResult['source']),
            'radius_km'   => $radiusKm,
        ];
    }

    return [
        'ok'          => true,
        'suggestions' => listing_nearby_suggestions_from_rules($candidates, $limit, $source),
        'source'      => 'rules',
        'radius_km'   => $radiusKm,
    ];
}

function listing_swap_suggestions_cached(int $listingId, ?float $radiusKm = null, bool $refresh = false, ?int $limit = null): array {
    $radiusKm = listing_nearby_parse_radius($radiusKm);
    $limit = max(1, min($limit ?? listing_swap_suggestions_limit(), 10));
    $cacheKey = $listingId . ':' . $radiusKm . ':' . $limit;

    if (!isset($_SESSION['_listing_swap_cache'])) {
        $_SESSION['_listing_swap_cache'] = [];
    }

    $cached = $_SESSION['_listing_swap_cache'][$cacheKey] ?? null;
    if (!$refresh && is_array($cached) && ($cached['at'] ?? 0) > time() - 900) {
        $data = $cached['data'];
        $user = auth_user();
        if ($user && !empty($data['suggestions'])) {
            listing_swap_feedback_register_displayed(
                (int) $user['id'],
                $listingId,
                $data['suggestions'],
                (string) ($data['source'] ?? 'rules')
            );
        }
        return $data;
    }

    $data = listing_swap_suggestions_fetch($listingId, $radiusKm, $limit);
    $_SESSION['_listing_swap_cache'][$cacheKey] = ['at' => time(), 'data' => $data];

    $user = auth_user();
    if ($user && !empty($data['suggestions'])) {
        listing_swap_feedback_register_displayed(
            (int) $user['id'],
            $listingId,
            $data['suggestions'],
            (string) ($data['source'] ?? 'rules')
        );
    }

    return $data;
}

function listing_swap_sanitize_suggestion(array $row): array {
    unset($row['source']);
    return $row;
}
