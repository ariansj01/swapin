<?php
/**
 * Direct swap offers from AI/rule suggestions — uses existing trade_offers table.
 *
 * Mapping: listing_id = target, offer_listing_id = source, from_user_id = sender.
 */

function listing_swap_offer_message_max_length(): int {
    return defined('LISTING_SWAP_OFFER_MESSAGE_MAX') ? (int) LISTING_SWAP_OFFER_MESSAGE_MAX : 500;
}

function listing_swap_offer_default_message(): string {
    return 'من این آگهی را دارم و علاقه‌مند به معاوضه با آگهی شما هستم.';
}

function listing_swap_offer_status_label(string $status): string {
    return match ($status) {
        'pending'   => 'در انتظار پاسخ',
        'accepted'  => 'پذیرفته‌شده',
        'rejected'  => 'رد شده',
        'cancelled' => 'لغو شده',
        default     => $status,
    };
}

function listing_swap_offer_listing_swappable(?array $listing): bool {
    if (!$listing) {
        return false;
    }
    if (($listing['status'] ?? '') !== 'active') {
        return false;
    }
    if (($listing['review_status'] ?? '') !== 'approved') {
        return false;
    }

    return in_array($listing['listing_mode'] ?? 'swap', ['swap', 'both'], true);
}

/** @return array<string,mixed> */
function listing_swap_offer_read_request_body(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Resolve source listing from swap-suggestion session context.
 *
 * @return array{source_listing_id:int}|null
 */
function listing_swap_offer_resolve_context(int $userId, int $targetListingId): ?array {
    $displayed = listing_swap_feedback_find_displayed($userId, $targetListingId);
    if (!$displayed) {
        return null;
    }

    $sourceListingId = (int) ($displayed['source_listing_id'] ?? 0);
    if ($sourceListingId <= 0) {
        return null;
    }

    return ['source_listing_id' => $sourceListingId];
}

/**
 * Pending swap offers keyed by target listing id.
 *
 * @param list<int> $targetListingIds
 * @return array<int,string>
 */
function listing_swap_offer_pending_map(int $userId, int $sourceListingId, array $targetListingIds): array {
    $targetListingIds = array_values(array_unique(array_filter(array_map('intval', $targetListingIds))));
    if ($targetListingIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($targetListingIds), '?'));
    $params = array_merge([$userId, $sourceListingId], $targetListingIds);

    $rows = DB::fetchAll(
        "SELECT listing_id, status
         FROM trade_offers
         WHERE from_user_id = ?
           AND offer_listing_id = ?
           AND offer_type = 'swap'
           AND status = 'pending'
           AND listing_id IN ({$placeholders})",
        $params
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['listing_id']] = (string) $row['status'];
    }

    return $map;
}

/**
 * @param list<array<string,mixed>> $suggestions
 * @return list<array<string,mixed>>
 */
function listing_swap_offer_enrich_suggestions(array $suggestions, int $userId, int $sourceListingId, array $sourceListing): array {
    $sourceEligible = listing_swap_offer_listing_swappable($sourceListing);
    $targetIds = array_map(static fn(array $item): int => (int) ($item['listing_id'] ?? 0), $suggestions);
    $pendingMap = $sourceEligible
        ? listing_swap_offer_pending_map($userId, $sourceListingId, $targetIds)
        : [];

    $targetsById = [];
    if ($targetIds !== []) {
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $rows = DB::fetchAll(
            "SELECT l.*, u.id AS owner_user_id
             FROM listings l
             JOIN users u ON u.id = l.user_id
             WHERE l.id IN ({$placeholders})",
            $targetIds
        );
        foreach ($rows as $row) {
            $targetsById[(int) $row['id']] = $row;
        }
    }

    foreach ($suggestions as &$item) {
        $targetId = (int) ($item['listing_id'] ?? 0);
        $target = $targetsById[$targetId] ?? null;
        $offerStatus = $pendingMap[$targetId] ?? null;

        $targetEligible = $target
            && listing_swap_offer_listing_swappable($target)
            && (int) ($target['owner_user_id'] ?? 0) !== $userId;

        $item['can_send_offer'] = $sourceEligible && $targetEligible && $offerStatus === null;
        if ($offerStatus !== null) {
            $item['offer_status'] = $offerStatus;
            $item['offer_status_label'] = listing_swap_offer_status_label($offerStatus);
        }
    }
    unset($item);

    return $suggestions;
}

/** @return array<string,mixed> */
function listing_swap_offer_format_row(array $offer, array $source, array $target, ?array $sender = null): array {
    $sender = $sender ?? DB::fetch('SELECT id, name, avatar, city FROM users WHERE id = ?', [(int) $offer['from_user_id']]);

    return [
        'id'                 => (int) $offer['id'],
        'status'             => (string) $offer['status'],
        'status_label'       => listing_swap_offer_status_label((string) $offer['status']),
        'message'            => (string) ($offer['message'] ?? ''),
        'created_at'         => (string) ($offer['created_at'] ?? ''),
        'updated_at'         => (string) ($offer['updated_at'] ?? ''),
        'sender'             => [
            'user_id' => (int) ($sender['id'] ?? $offer['from_user_id']),
            'name'    => (string) ($sender['name'] ?? ''),
            'avatar'  => (string) ($sender['avatar'] ?? ''),
            'city'    => (string) ($sender['city'] ?? ''),
        ],
        'source_listing'     => [
            'listing_id' => (int) $source['id'],
            'title'      => (string) ($source['title'] ?? ''),
        ],
        'target_listing'     => [
            'listing_id' => (int) $target['id'],
            'title'      => (string) ($target['title'] ?? ''),
        ],
        'receiver_id'        => (int) ($target['user_id'] ?? $target['owner_user_id'] ?? 0),
    ];
}

/**
 * Create a pending swap offer.
 *
 * @return array{ok:bool,offer?:array,message_user?:string,error?:string,message?:string}
 */
function listing_swap_offer_create(int $userId, int $targetListingId, string $message): array {
    if ($targetListingId <= 0) {
        return ['ok' => false, 'error' => 'invalid_target_listing_id'];
    }

    $message = trim($message);
    $maxLen = listing_swap_offer_message_max_length();
    if ($message === '') {
        return ['ok' => false, 'error' => 'message_required'];
    }
    if (mb_strlen($message) > $maxLen) {
        return ['ok' => false, 'error' => 'message_too_long'];
    }

    $ctx = listing_swap_offer_resolve_context($userId, $targetListingId);
    if (!$ctx) {
        return ['ok' => false, 'error' => 'suggestion_context_not_found'];
    }

    $sourceListingId = (int) $ctx['source_listing_id'];

    $source = DB::fetch('SELECT * FROM listings WHERE id = ?', [$sourceListingId]);
    if (!$source || (int) $source['user_id'] !== $userId) {
        return ['ok' => false, 'error' => 'source_not_owned'];
    }

    $target = DB::fetch(
        'SELECT l.*, u.id AS owner_user_id
         FROM listings l
         JOIN users u ON u.id = l.user_id
         WHERE l.id = ?',
        [$targetListingId]
    );
    if (!$target) {
        return ['ok' => false, 'error' => 'target_not_found'];
    }

    if ((int) $target['owner_user_id'] === $userId) {
        return ['ok' => false, 'error' => 'cannot_offer_own_listing'];
    }

    if (!listing_swap_offer_listing_swappable($source)) {
        return ['ok' => false, 'error' => 'source_not_active'];
    }

    if (!listing_swap_offer_listing_swappable($target)) {
        return ['ok' => false, 'error' => 'target_not_active'];
    }

    $duplicate = DB::fetch(
        'SELECT id FROM trade_offers
         WHERE listing_id = ? AND from_user_id = ? AND offer_listing_id = ? AND status = "pending"
         LIMIT 1',
        [$targetListingId, $userId, $sourceListingId]
    );
    if ($duplicate) {
        return ['ok' => false, 'error' => 'duplicate_pending_offer'];
    }

    $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return ['ok' => false, 'error' => 'login_required'];
    }

    $tradeValue = kyc_trade_value_from_listings($source, $target);
    if ($kycErr = kyc_check_trade($user, $tradeValue)) {
        return ['ok' => false, 'error' => 'kyc_blocked', 'message' => $kycErr];
    }

    DB::insert('trade_offers', [
        'listing_id'       => $targetListingId,
        'from_user_id'     => $userId,
        'offer_listing_id' => $sourceListingId,
        'offer_type'       => 'swap',
        'offer_credit'     => 0,
        'message'          => $message,
        'status'           => 'pending',
    ]);

    $offerId = (int) DB::lastId();
    $offer = DB::fetch('SELECT * FROM trade_offers WHERE id = ?', [$offerId]);

    DB::insert('messages', [
        'thread_id'    => 'offer_' . uniqid(),
        'from_user_id' => $userId,
        'to_user_id'   => (int) $target['owner_user_id'],
        'offer_id'     => $offerId,
        'body'         => $message,
    ]);

    return [
        'ok'           => true,
        'message_user' => 'پیشنهاد معاوضه ارسال شد.',
        'offer'        => listing_swap_offer_format_row($offer, $source, $target, $user),
    ];
}

/**
 * Incoming swap offers for listings owned by the user.
 *
 * @return list<array<string,mixed>>
 */
function listing_swap_offer_list_received(int $userId): array {
    $rows = DB::fetchAll(
        'SELECT o.*,
                sl.title AS source_title, sl.id AS source_listing_id,
                tl.title AS target_title, tl.id AS target_listing_id,
                su.id AS sender_id, su.name AS sender_name, su.avatar AS sender_avatar, su.city AS sender_city
         FROM trade_offers o
         JOIN listings tl ON tl.id = o.listing_id
         JOIN listings sl ON sl.id = o.offer_listing_id
         JOIN users su ON su.id = o.from_user_id
         WHERE tl.user_id = ?
           AND o.offer_type = "swap"
           AND o.status = "pending"
         ORDER BY o.created_at DESC
         LIMIT 50',
        [$userId]
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'           => (int) $row['id'],
            'status'       => (string) $row['status'],
            'status_label' => listing_swap_offer_status_label((string) $row['status']),
            'message'      => (string) ($row['message'] ?? ''),
            'created_at'   => (string) ($row['created_at'] ?? ''),
            'updated_at'   => (string) ($row['updated_at'] ?? ''),
            'sender'       => [
                'user_id' => (int) $row['sender_id'],
                'name'    => (string) $row['sender_name'],
                'avatar'  => (string) ($row['sender_avatar'] ?? ''),
                'city'    => (string) ($row['sender_city'] ?? ''),
            ],
            'source_listing' => [
                'listing_id' => (int) $row['source_listing_id'],
                'title'      => (string) $row['source_title'],
            ],
            'target_listing' => [
                'listing_id' => (int) $row['target_listing_id'],
                'title'      => (string) $row['target_title'],
            ],
        ];
    }

    return $out;
}

/**
 * Outgoing swap offers sent by the user (optionally for one source listing).
 *
 * @return list<array<string,mixed>>
 */
function listing_swap_offer_list_sent(int $userId, ?int $sourceListingId = null): array {
    $params = [$userId];
    $sourceFilter = '';
    if ($sourceListingId !== null && $sourceListingId > 0) {
        $sourceFilter = ' AND o.offer_listing_id = ?';
        $params[] = $sourceListingId;
    }

    $rows = DB::fetchAll(
        "SELECT o.*,
                sl.title AS source_title, sl.id AS source_listing_id,
                tl.title AS target_title, tl.id AS target_listing_id
         FROM trade_offers o
         JOIN listings sl ON sl.id = o.offer_listing_id
         JOIN listings tl ON tl.id = o.listing_id
         WHERE o.from_user_id = ?
           AND o.offer_type = 'swap'
           {$sourceFilter}
         ORDER BY o.created_at DESC
         LIMIT 50",
        $params
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'           => (int) $row['id'],
            'status'       => (string) $row['status'],
            'status_label' => listing_swap_offer_status_label((string) $row['status']),
            'message'      => (string) ($row['message'] ?? ''),
            'created_at'   => (string) ($row['created_at'] ?? ''),
            'updated_at'   => (string) ($row['updated_at'] ?? ''),
            'source_listing' => [
                'listing_id' => (int) $row['source_listing_id'],
                'title'      => (string) $row['source_title'],
            ],
            'target_listing' => [
                'listing_id' => (int) $row['target_listing_id'],
                'title'      => (string) $row['target_title'],
            ],
        ];
    }

    return $out;
}
