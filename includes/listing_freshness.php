<?php
/**
 * Listing freshness — publish date based scoring (edit does not reset).
 */

function listing_has_published_at_column(): bool {
    static $has = null;
    if ($has === null) {
        $has = in_array('published_at', db_table_columns('listings'), true);
    }
    return $has;
}

/**
 * Reference date for freshness — published_at when set, else created_at.
 */
function listing_published_at(array $row): ?string {
    if (listing_has_published_at_column() && !empty($row['published_at'])) {
        return (string) $row['published_at'];
    }
    $created = $row['created_at'] ?? null;
    return $created !== null && $created !== '' ? (string) $created : null;
}

/**
 * Freshness score buckets — adjust scores here (or via config constants).
 *
 * @return list<array{max_days:?int,score:int}>
 */
function listing_freshness_buckets(): array {
    return [
        ['max_days' => 0, 'score' => defined('LISTING_FRESHNESS_SCORE_TODAY') ? (int) LISTING_FRESHNESS_SCORE_TODAY : 100],
        ['max_days' => 3, 'score' => defined('LISTING_FRESHNESS_SCORE_3D') ? (int) LISTING_FRESHNESS_SCORE_3D : 85],
        ['max_days' => 7, 'score' => defined('LISTING_FRESHNESS_SCORE_7D') ? (int) LISTING_FRESHNESS_SCORE_7D : 65],
        ['max_days' => null, 'score' => defined('LISTING_FRESHNESS_SCORE_OLD') ? (int) LISTING_FRESHNESS_SCORE_OLD : 40],
    ];
}

function listing_freshness_age_days(?string $referenceDate): int {
    if (!$referenceDate) {
        return 999;
    }

    try {
        $published = new DateTimeImmutable($referenceDate);
        $today = new DateTimeImmutable('today');
        return max(0, (int) $published->diff($today)->days);
    } catch (Exception) {
        return 999;
    }
}

function listing_freshness_score(?string $referenceDate): int {
    $days = listing_freshness_age_days($referenceDate);
    $buckets = listing_freshness_buckets();

    foreach ($buckets as $bucket) {
        if ($bucket['max_days'] === null || $days <= $bucket['max_days']) {
            return (int) $bucket['score'];
        }
    }

    return (int) $buckets[count($buckets) - 1]['score'];
}

function listing_freshness_label_for_days(int $days): string {
    if ($days <= 0) {
        return 'امروز';
    }
    if ($days <= 6) {
        return fmt_num($days) . ' روز پیش';
    }
    return 'قدیمی';
}

/**
 * @return array{freshness_score:int,freshness_label:string,freshness_days:int}
 */
function listing_freshness_meta(array $row): array {
    $reference = listing_published_at($row);
    $days = listing_freshness_age_days($reference);

    return [
        'freshness_score' => listing_freshness_score($reference),
        'freshness_label' => listing_freshness_label_for_days($days),
        'freshness_days'  => $days,
    ];
}

/** Set published_at on first public activation; never overwrite on edit. */
function listing_ensure_published_at(int $listingId, ?string $when = null): void {
    if ($listingId <= 0 || !listing_has_published_at_column()) {
        return;
    }

    $when = $when ?? date('Y-m-d H:i:s');
    DB::query(
        'UPDATE listings SET published_at = ? WHERE id = ? AND published_at IS NULL',
        [$when, $listingId]
    );
}

/** @return array<string,mixed> */
function listing_published_at_db_value(?string $when = null): array {
    if (!listing_has_published_at_column()) {
        return [];
    }
    return ['published_at' => $when ?? date('Y-m-d H:i:s')];
}
