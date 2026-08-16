<?php
/**
 * Swap suggestion feedback — collect user signals without affecting ranking (yet).
 */

function listing_swap_feedback_reasons(): array {
    return [
        'value_mismatch'      => 'ارزش نامناسب',
        'wrong_category'      => 'دسته‌بندی اشتباه',
        'wrong_item'          => 'مورد معاوضه اشتباه',
        'distance_too_far'    => 'فاصله زیاد',
        'listing_unavailable' => 'آگهی دیگر موجود نیست',
        'other'               => 'دلیل دیگر',
    ];
}

function listing_swap_feedback_display_ttl(): int {
    return 900;
}

/** @return array<string,mixed> */
function listing_swap_feedback_snapshot_from_suggestion(array $item, string $sourceTag): array {
    $snapshot = [
        'match_score'       => max(0, min(100, (int) ($item['match_score'] ?? 0))),
        'suggestion_source'   => mb_substr(trim($sourceTag), 0, 32),
    ];

    foreach (['swap_compatibility', 'value_compatibility', 'category_compatibility', 'location_score', 'confidence'] as $field) {
        if (isset($item[$field]) && is_numeric($item[$field])) {
            $snapshot[$field] = max(0, min(100, (int) $item[$field]));
        }
    }

    return $snapshot;
}

/**
 * Remember suggestions shown to a logged-in user (session, short TTL).
 */
function listing_swap_feedback_register_displayed(int $userId, int $sourceListingId, array $suggestions, string $sourceTag): void {
    if ($userId <= 0 || $sourceListingId <= 0 || $suggestions === []) {
        return;
    }

    if (!isset($_SESSION['_listing_swap_displayed'])) {
        $_SESSION['_listing_swap_displayed'] = [];
    }

    $key = $userId . ':' . $sourceListingId;
    $items = [];

    foreach ($suggestions as $item) {
        if (!is_array($item)) {
            continue;
        }
        $suggestedId = (int) ($item['listing_id'] ?? 0);
        if ($suggestedId <= 0) {
            continue;
        }
        $items[$suggestedId] = listing_swap_feedback_snapshot_from_suggestion($item, $sourceTag);
    }

    if ($items === []) {
        return;
    }

    $_SESSION['_listing_swap_displayed'][$key] = [
        'at'    => time(),
        'items' => $items,
    ];
}

/**
 * Find a displayed suggestion snapshot for this user.
 *
 * @return array<string,mixed>|null
 */
function listing_swap_feedback_find_displayed(int $userId, int $suggestedListingId): ?array {
    $displayed = $_SESSION['_listing_swap_displayed'] ?? [];
    if (!is_array($displayed)) {
        return null;
    }

    $ttl = listing_swap_feedback_display_ttl();
    $now = time();

    foreach ($displayed as $key => $entry) {
        if (!is_array($entry) || ($entry['at'] ?? 0) < $now - $ttl) {
            continue;
        }
        if (!str_starts_with((string) $key, $userId . ':')) {
            continue;
        }
        $parts = explode(':', (string) $key, 2);
        $sourceListingId = (int) ($parts[1] ?? 0);
        if ($sourceListingId <= 0) {
            continue;
        }
        $items = $entry['items'] ?? [];
        if (!is_array($items) || !isset($items[$suggestedListingId])) {
            continue;
        }

        return array_merge($items[$suggestedListingId], [
            'source_listing_id' => $sourceListingId,
        ]);
    }

    return null;
}

function listing_swap_feedback_parse_reason(?string $reason): ?string {
    if ($reason === null || trim($reason) === '') {
        return null;
    }
    $reason = trim($reason);
    $allowed = listing_swap_feedback_reasons();
    return array_key_exists($reason, $allowed) ? $reason : null;
}

function listing_swap_feedback_parse_input(?string $feedback): ?string {
    $feedback = strtolower(trim((string) $feedback));
    return in_array($feedback, ['positive', 'negative'], true) ? $feedback : null;
}

/**
 * @return array{ok:bool,updated?:bool,error?:string}
 */
function listing_swap_feedback_save(int $userId, int $suggestedListingId, string $feedback, ?string $reason = null): array {
    if ($userId <= 0 || $suggestedListingId <= 0) {
        return ['ok' => false, 'error' => 'invalid_input'];
    }

    $feedback = listing_swap_feedback_parse_input($feedback);
    if ($feedback === null) {
        return ['ok' => false, 'error' => 'invalid_feedback'];
    }

    if ($feedback === 'positive') {
        $reason = null;
    } else {
        $reason = listing_swap_feedback_parse_reason($reason);
    }

    $displayed = listing_swap_feedback_find_displayed($userId, $suggestedListingId);
    if ($displayed === null) {
        return ['ok' => false, 'error' => 'suggestion_not_displayed'];
    }

    $sourceListingId = (int) ($displayed['source_listing_id'] ?? 0);
    if ($sourceListingId <= 0) {
        return ['ok' => false, 'error' => 'suggestion_not_displayed'];
    }

    $source = DB::fetch(
        'SELECT id, user_id, status FROM listings WHERE id = ?',
        [$sourceListingId]
    );
    $suggested = DB::fetch(
        'SELECT id, status FROM listings WHERE id = ?',
        [$suggestedListingId]
    );

    if (!$source || !$suggested) {
        return ['ok' => false, 'error' => 'listing_not_found'];
    }

    if ($sourceListingId === $suggestedListingId) {
        return ['ok' => false, 'error' => 'invalid_pair'];
    }

    $row = [
        'user_id'              => $userId,
        'source_listing_id'    => $sourceListingId,
        'suggested_listing_id' => $suggestedListingId,
        'feedback'             => $feedback,
        'reason'               => $reason,
        'match_score'          => max(0, min(100, (int) ($displayed['match_score'] ?? 0))),
        'suggestion_source'    => mb_substr((string) ($displayed['suggestion_source'] ?? 'rules'), 0, 32),
        'swap_compatibility'     => isset($displayed['swap_compatibility']) ? (int) $displayed['swap_compatibility'] : null,
        'value_compatibility'    => isset($displayed['value_compatibility']) ? (int) $displayed['value_compatibility'] : null,
        'category_compatibility' => isset($displayed['category_compatibility']) ? (int) $displayed['category_compatibility'] : null,
        'location_score'         => isset($displayed['location_score']) ? (int) $displayed['location_score'] : null,
        'confidence'             => isset($displayed['confidence']) ? (int) $displayed['confidence'] : null,
    ];

    $row = db_filter_row('listing_swap_feedback', $row);

    $existing = DB::fetch(
        'SELECT id FROM listing_swap_feedback WHERE user_id = ? AND source_listing_id = ? AND suggested_listing_id = ?',
        [$userId, $sourceListingId, $suggestedListingId]
    );

    if ($existing) {
        DB::update('listing_swap_feedback', $row, 'id = ?', [(int) $existing['id']]);
        return ['ok' => true, 'updated' => true];
    }

    DB::insert('listing_swap_feedback', $row);
    return ['ok' => true, 'updated' => false];
}

/** @return array<string,mixed> */
function listing_swap_feedback_read_request_body(): array {
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}
