<?php
/**
 * Smart nearby swap suggestions — rules + optional AI ranking.
 */

function listing_swap_suggestions_limit(): int {
    return defined('LISTING_SWAP_SUGGESTIONS_LIMIT') ? (int) LISTING_SWAP_SUGGESTIONS_LIMIT : 6;
}

function listing_swap_ai_pool_limit(): int {
    return defined('LISTING_SWAP_AI_POOL_LIMIT') ? (int) LISTING_SWAP_AI_POOL_LIMIT : 12;
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

function listing_swap_suggestions_from_ai(array $source, array $candidates, int $limit): ?array {
    if (!ai_is_configured() || $candidates === []) {
        return null;
    }

    $candidatePayload = array_map('listing_nearby_suggestion_payload', array_slice($candidates, 0, listing_swap_ai_pool_limit()));

    $payload = [
        'source_listing'     => listing_swap_source_payload($source),
        'candidate_listings' => $candidatePayload,
        'instruction'        => 'Rank nearby swap candidates for the SOURCE listing. Prefer real swap fit (mutual wants, category/value alignment) over mere proximity. distance_km is secondary. Return JSON type matching with matches: [{listing_id, score (0-100), reason (1 Persian line)}]. Max ' . $limit . ' items.',
    ];

    $result = ai_call('matching', $payload);
    $parsed = ai_parse_json_response($result['parsed'] ?? null);
    $provider = $result['provider'] ?? 'ai';

    if (!$parsed || ($parsed['type'] ?? '') !== 'matching' || empty($parsed['matches'])) {
        return null;
    }

    $byId = [];
    foreach ($candidates as $c) {
        $byId[(int) $c['id']] = $c;
    }

    $out = [];
    $seen = [];
    foreach ($parsed['matches'] as $item) {
        $lid = (int) ($item['listing_id'] ?? 0);
        if (!$lid || !isset($byId[$lid]) || isset($seen[$lid])) {
            continue;
        }
        $seen[$lid] = true;
        $c = $byId[$lid];
        $c['match_score'] = max(
            (int) ($c['match_score'] ?? 0),
            (int) ($item['score'] ?? 0)
        );
        $reason = trim((string) ($item['reason'] ?? '')) ?: listing_nearby_rule_reason($c);
        $out[] = listing_nearby_format_suggestion($c, $reason, $provider);
        if (count($out) >= $limit) {
            break;
        }
    }

    if (count($out) < min(2, $limit)) {
        return null;
    }

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
        'suggestions' => listing_nearby_suggestions_from_rules($candidates, $limit),
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
        return $cached['data'];
    }

    $data = listing_swap_suggestions_fetch($listingId, $radiusKm, $limit);
    $_SESSION['_listing_swap_cache'][$cacheKey] = ['at' => time(), 'data' => $data];
    return $data;
}

function listing_swap_sanitize_suggestion(array $row): array {
    unset($row['source']);
    return $row;
}
