<?php
/**
 * User → Store swap offer flow (extends trade_offers + store_offer_messages).
 */

function store_swap_flow_enabled(): bool {
    return db_has_column('trade_offers', 'flow_type')
        && db_has_table('store_offer_messages');
}

function store_swap_active_statuses(): array {
    return ['pending', 'negotiating', 'counter_offered', 'accepted'];
}

function store_swap_terminal_statuses(): array {
    return ['rejected', 'cancelled', 'completed'];
}

function store_swap_offer_status_label(string $status): string {
    return match ($status) {
        'pending'         => 'در انتظار پاسخ فروشگاه',
        'negotiating'     => 'در حال مذاکره',
        'counter_offered' => 'پیشنهاد جدید فروشگاه',
        'accepted'        => 'توافق اولیه — در انتظار تأیید نهایی',
        'rejected'        => 'رد شده',
        'cancelled'       => 'لغو شده',
        'completed'       => 'تکمیل‌شده',
        default           => offer_status_label($status),
    };
}

function listing_is_store_listing(array $listing): bool {
    if (!empty($listing['seller_type'])) {
        return ($listing['seller_type'] ?? '') === 'store';
    }
    $seller = DB::fetch('SELECT seller_type FROM users WHERE id = ?', [(int)($listing['user_id'] ?? 0)]);

    return $seller && ($seller['seller_type'] ?? '') === 'store';
}

function listing_is_store_swappable(array $listing): bool {
    if (!store_swap_flow_enabled()) {
        return false;
    }
    if (($listing['status'] ?? '') !== 'active') {
        return false;
    }
    if (($listing['review_status'] ?? 'approved') !== 'approved') {
        return false;
    }
    if (!listing_is_store_listing($listing)) {
        return false;
    }
    $mode = $listing['listing_mode'] ?? 'swap';

    return in_array($mode, ['swap', 'both'], true);
}

function store_swap_validate_cash(float $cash, float $storeValue, float $userValue): ?string {
    if ($cash < 0) {
        return 'مبلغ تکمیلی نمی‌تواند منفی باشد.';
    }
    if ($cash > 500_000_000) {
        return 'مبلغ تکمیلی بیش از حد مجاز است.';
    }
    $gap = max(0, $storeValue - $userValue);
    if ($cash > 0 && $gap > 0 && $cash < $gap * 0.5) {
        return 'مبلغ تکمیلی برای این اختلاف ارزش بسیار کم است.';
    }

    return null;
}

function store_swap_effective_credit(array $offer): float {
    if (in_array($offer['status'] ?? '', ['counter_offered', 'accepted', 'completed'], true)
        && isset($offer['counter_offer_credit']) && $offer['counter_offer_credit'] !== null) {
        return (float) $offer['counter_offer_credit'];
    }

    return (float) ($offer['offer_credit'] ?? 0);
}

/** @return array<string,mixed>|null */
function store_swap_offer_fetch(int $offerId): ?array {
    if (!store_swap_flow_enabled()) {
        return null;
    }
    $row = DB::fetch(
        'SELECT o.*,
                sl.title AS store_listing_title, sl.estimated_value AS store_listing_value, sl.sell_price AS store_sell_price,
                ul.title AS user_listing_title, ul.estimated_value AS user_listing_value,
                su.id AS store_user_id, su.name AS store_user_name, su.store_name, su.store_slug, su.avatar AS store_avatar,
                fu.name AS from_user_name, fu.avatar AS from_user_avatar,
                (SELECT filename FROM listing_images WHERE listing_id = o.listing_id AND is_primary = 1 LIMIT 1) AS store_thumb,
                (SELECT filename FROM listing_images WHERE listing_id = o.offer_listing_id AND is_primary = 1 LIMIT 1) AS user_thumb,
                cs.name AS user_cat_name
         FROM trade_offers o
         JOIN listings sl ON sl.id = o.listing_id
         JOIN users su ON su.id = sl.user_id
         JOIN users fu ON fu.id = o.from_user_id
         LEFT JOIN listings ul ON ul.id = o.offer_listing_id
         LEFT JOIN categories cs ON cs.id = ul.category_id
         WHERE o.id = ? AND o.flow_type = "user_to_store"',
        [$offerId]
    );
    if (!$row) {
        return null;
    }
    $row['effective_credit'] = store_swap_effective_credit($row);
    $row['status_label'] = store_swap_offer_status_label((string) $row['status']);

    return $row;
}

function store_swap_offer_can_access(array $offer, array $user): bool {
    $uid = (int) $user['id'];
    if ($uid === (int) ($offer['from_user_id'] ?? 0)) {
        return true;
    }
    if ($uid === (int) ($offer['store_user_id'] ?? 0)) {
        return true;
    }

    return is_admin_user($user);
}

function store_swap_offer_is_store(array $offer, array $user): bool {
    return (int) $user['id'] === (int) ($offer['store_user_id'] ?? 0);
}

function store_swap_offer_user_has_active(int $userId, int $storeListingId): bool {
    $statuses = store_swap_active_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    return (bool) DB::fetch(
        "SELECT id FROM trade_offers
         WHERE listing_id = ? AND from_user_id = ? AND flow_type = 'user_to_store'
           AND status IN ({$placeholders}) LIMIT 1",
        array_merge([$storeListingId, $userId], $statuses)
    );
}

/** @return list<array<string,mixed>> */
function store_swap_offer_messages(int $offerId): array {
    return DB::fetchAll(
        'SELECT m.*, u.name AS user_name, u.store_name, u.seller_type, u.avatar
         FROM store_offer_messages m
         JOIN users u ON u.id = m.user_id
         WHERE m.offer_id = ?
         ORDER BY m.created_at ASC, m.id ASC',
        [$offerId]
    );
}

function store_swap_offer_add_message(int $offerId, int $userId, string $body, string $type = 'text', ?array $meta = null): int {
    return DB::insert('store_offer_messages', [
        'offer_id' => $offerId,
        'user_id'  => $userId,
        'type'     => $type,
        'body'     => $body,
        'meta'     => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function store_swap_offer_mark_negotiating(int $offerId): void {
    DB::query(
        'UPDATE trade_offers SET status = "negotiating", updated_at = NOW()
         WHERE id = ? AND status = "pending"',
        [$offerId]
    );
}

/** @return array{offer_id?:int,error?:string} */
function store_swap_offer_create(int $userId, int $storeListingId, int $userListingId, float $cashDiff, ?string $message = null): array {
    if (!store_swap_flow_enabled()) {
        return ['error' => 'سیستم معاوضه با فروشگاه فعال نیست.'];
    }

    $storeListing = DB::fetch(
        'SELECT l.*, u.seller_type, u.store_name
         FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?',
        [$storeListingId]
    );
    if (!$storeListing || !listing_is_store_swappable($storeListing)) {
        return ['error' => 'این محصول برای معاوضه با فروشگاه در دسترس نیست.'];
    }
    if ((int) $storeListing['user_id'] === $userId) {
        return ['error' => 'نمی‌توانید برای محصول خودتان پیشنهاد بدهید.'];
    }
    if (store_swap_offer_user_has_active($userId, $storeListingId)) {
        return ['error' => 'شما از قبل یک پیشنهاد فعال برای این محصول دارید.'];
    }

    $userListing = DB::fetch(
        'SELECT l.*, c.name AS cat_name
         FROM listings l LEFT JOIN categories c ON c.id = l.category_id
         WHERE l.id = ? AND l.user_id = ? AND l.status = "active" AND l.review_status = "approved"',
        [$userListingId, $userId]
    );
    if (!$userListing) {
        return ['error' => 'کالای انتخاب‌شده معتبر نیست یا متعلق به شما نیست.'];
    }

    $storeValue = (float) ($storeListing['estimated_value'] ?: $storeListing['sell_price'] ?: 0);
    $userValue  = (float) ($userListing['estimated_value'] ?? 0);
    if ($err = store_swap_validate_cash($cashDiff, $storeValue, $userValue)) {
        return ['error' => $err];
    }

    $tradeValue = kyc_trade_value_from_listings($storeListing, $userListing);
    $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($user && ($kycErr = kyc_check_trade($user, $tradeValue))) {
        return ['error' => $kycErr];
    }

    $pdo = DB::pdo();
    try {
        $pdo->beginTransaction();

        DB::insert('trade_offers', [
            'listing_id'       => $storeListingId,
            'from_user_id'     => $userId,
            'offer_listing_id' => $userListingId,
            'offer_type'       => 'swap',
            'flow_type'        => 'user_to_store',
            'offer_credit'     => $cashDiff,
            'message'          => $message ?: null,
            'status'           => 'pending',
        ]);
        $offerId = DB::lastId();

        $intro = $message ?: sprintf(
            'پیشنهاد معاوضه: %s + %s در ازای %s',
            $userListing['title'],
            $cashDiff > 0 ? fmt_credit($cashDiff, true) : 'بدون مبلغ تکمیلی',
            $storeListing['title']
        );
        store_swap_offer_add_message($offerId, $userId, $intro, 'text');

        DB::insert('messages', [
            'thread_id'    => 'store_offer_' . $offerId,
            'from_user_id' => $userId,
            'to_user_id'   => (int) $storeListing['user_id'],
            'offer_id'     => $offerId,
            'body'         => 'پیشنهاد معاوضه جدید برای «' . $storeListing['title'] . '»',
        ]);

        $pdo->commit();

        return ['offer_id' => $offerId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        swapin_debug_log('store-swap-offer-create', ['msg' => $e->getMessage()]);

        return ['error' => 'ثبت پیشنهاد ممکن نشد. لطفاً دوباره تلاش کنید.'];
    }
}

/** @return array{listing_id?:int,error?:string} */
function store_swap_quick_listing_create(int $userId, array $input, ?array $file = null): array {
    $title       = clean($input['title'] ?? '');
    $description = clean($input['description'] ?? '');
    $categoryId  = (int) ($input['category_id'] ?? 0);
    $value       = max(0, (float) preg_replace('/[^\d.]/', '', (string) ($input['estimated_value'] ?? '0')));

    $errors = validate_listing_content([
        'title'       => $title,
        'description' => $description,
    ]);
    if (mb_strlen($title) < 5) {
        $errors[] = 'عنوان باید حداقل ۵ کاراکتر باشد.';
    }
    if (!$categoryId) {
        $errors[] = 'دسته‌بندی را انتخاب کنید.';
    }
    if ($value <= 0) {
        $errors[] = 'ارزش تقریبی را وارد کنید.';
    }
    if (!empty($errors)) {
        return ['error' => implode(' ', $errors)];
    }
    if (!can_create_listing(DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]) ?: ['id' => $userId])) {
        return ['error' => 'سقف ثبت آگهی شما تکمیل شده است.'];
    }

    $user = DB::fetch('SELECT city FROM users WHERE id = ?', [$userId]);
    $listingId = DB::insert('listings', [
        'user_id'         => $userId,
        'category_id'     => $categoryId,
        'title'           => $title,
        'description'     => $description,
        'condition'       => clean($input['condition'] ?? 'good'),
        'estimated_value' => $value,
        'want_in_return'  => 'معاوضه با فروشگاه',
        'want_type'       => 'item',
        'listing_mode'    => 'swap',
        'city'            => $user['city'] ?? null,
        'status'          => 'active',
        'review_status'   => 'approved',
    ]);

    if ($file && !empty($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $filename = upload_image($file, 'listing');
        if ($filename) {
            DB::insert('listing_images', [
                'listing_id' => $listingId,
                'filename'   => $filename,
                'is_primary' => 1,
                'sort_order' => 0,
            ]);
        }
    }

    return ['listing_id' => $listingId];
}

/** @return array{ok?:bool,error?:string} */
function store_swap_offer_send_message(int $offerId, array $user, string $body): array {
    $offer = store_swap_offer_fetch($offerId);
    if (!$offer || !store_swap_offer_can_access($offer, $user)) {
        return ['error' => 'دسترسی به این پیشنهاد ندارید.'];
    }
    if (in_array($offer['status'], store_swap_terminal_statuses(), true)) {
        return ['error' => 'این پیشنهاد بسته شده است.'];
    }
    $body = trim($body);
    if ($body === '') {
        return ['error' => 'پیام خالی است.'];
    }
    if (mb_strlen($body) > 1000) {
        return ['error' => 'پیام بیش از حد طولانی است.'];
    }

    store_swap_offer_add_message($offerId, (int) $user['id'], $body, 'text');
    store_swap_offer_mark_negotiating($offerId);
    DB::query('UPDATE trade_offers SET updated_at = NOW() WHERE id = ?', [$offerId]);

    return ['ok' => true];
}

/** @return array{ok?:bool,error?:string} */
function store_swap_offer_send_counter(int $offerId, array $storeUser, float $cashDiff, ?string $message = null): array {
    $offer = store_swap_offer_fetch($offerId);
    if (!$offer || !store_swap_offer_is_store($offer, $storeUser)) {
        return ['error' => 'فقط فروشگاه می‌تواند پیشنهاد جدید بدهد.'];
    }
    if (!in_array($offer['status'], ['pending', 'negotiating', 'counter_offered'], true)) {
        return ['error' => 'در این وضعیت امکان پیشنهاد جدید نیست.'];
    }

    $storeValue = (float) ($offer['store_listing_value'] ?? 0);
    $userValue  = (float) ($offer['user_listing_value'] ?? 0);
    if ($err = store_swap_validate_cash($cashDiff, $storeValue, $userValue)) {
        return ['error' => $err];
    }

    DB::update('trade_offers', [
        'counter_offer_credit' => $cashDiff,
        'status'               => 'counter_offered',
    ], 'id = ?', [$offerId]);
    DB::query('UPDATE trade_offers SET updated_at = NOW() WHERE id = ?', [$offerId]);

    $body = $message ?: ('پیشنهاد جدید فروشگاه — مابه‌التفاوت: ' . fmt_credit($cashDiff, true));
    store_swap_offer_add_message($offerId, (int) $storeUser['id'], $body, 'counter_offer', [
        'cash_difference' => $cashDiff,
    ]);

    DB::insert('messages', [
        'thread_id'    => 'store_offer_' . $offerId,
        'from_user_id' => (int) $storeUser['id'],
        'to_user_id'   => (int) $offer['from_user_id'],
        'offer_id'     => $offerId,
        'body'         => 'فروشگاه پیشنهاد جدید برای معاوضه ارسال کرد.',
    ]);

    return ['ok' => true];
}

/** @return array{ok?:bool,error?:string} */
function store_swap_offer_accept_counter(int $offerId, array $user): array {
    $offer = store_swap_offer_fetch($offerId);
    if (!$offer || (int) $user['id'] !== (int) $offer['from_user_id']) {
        return ['error' => 'دسترسی ندارید.'];
    }
    if (($offer['status'] ?? '') !== 'counter_offered') {
        return ['error' => 'پیشنهاد جدیدی برای پذیرش وجود ندارد.'];
    }

    DB::query('UPDATE trade_offers SET status = "accepted", updated_at = NOW() WHERE id = ?', [$offerId]);
    store_swap_offer_add_message($offerId, (int) $user['id'], 'پیشنهاد فروشگاه را پذیرفتم.', 'system');

    return ['ok' => true];
}

/** @return array{ok?:bool,error?:string} */
function store_swap_offer_store_accept(int $offerId, array $storeUser, ?string $message = null): array {
    $offer = store_swap_offer_fetch($offerId);
    if (!$offer || !store_swap_offer_is_store($offer, $storeUser)) {
        return ['error' => 'دسترسی ندارید.'];
    }
    if (!in_array($offer['status'], ['pending', 'negotiating'], true)) {
        return ['error' => 'در این وضعیت امکان پذیرش مستقیم نیست.'];
    }

    DB::query('UPDATE trade_offers SET status = "accepted", updated_at = NOW() WHERE id = ?', [$offerId]);
    store_swap_offer_add_message(
        $offerId,
        (int) $storeUser['id'],
        $message ?: 'فروشگاه پیشنهاد شما را پذیرفت.',
        'system'
    );

    return ['ok' => true];
}

/** @return array{ok?:bool,error?:string} */
function store_swap_offer_reject(int $offerId, array $user, ?string $reason = null): array {
    $offer = store_swap_offer_fetch($offerId);
    if (!$offer || !store_swap_offer_can_access($offer, $user)) {
        return ['error' => 'دسترسی ندارید.'];
    }
    if (in_array($offer['status'], ['completed', 'rejected'], true)) {
        return ['error' => 'این پیشنهاد قبلاً بسته شده است.'];
    }

    DB::query('UPDATE trade_offers SET status = "rejected", updated_at = NOW() WHERE id = ?', [$offerId]);
    $body = $reason ?: 'پیشنهاد رد شد.';
    store_swap_offer_add_message($offerId, (int) $user['id'], $body, 'system');

    $toUserId = store_swap_offer_is_store($offer, $user)
        ? (int) $offer['from_user_id']
        : (int) $offer['store_user_id'];
    DB::insert('messages', [
        'thread_id'    => 'store_offer_reject_' . $offerId,
        'from_user_id' => (int) $user['id'],
        'to_user_id'   => $toUserId,
        'offer_id'     => $offerId,
        'body'         => $body,
    ]);

    return ['ok' => true];
}

/** @return array{trade_id?:int,error?:string} */
function store_swap_offer_finalize(int $offerId, array $user): array {
    $offer = store_swap_offer_fetch($offerId);
    if (!$offer) {
        return ['error' => 'پیشنهاد یافت نشد.'];
    }
    $uid = (int) $user['id'];
    if ($uid !== (int) $offer['from_user_id'] && $uid !== (int) $offer['store_user_id']) {
        return ['error' => 'دسترسی ندارید.'];
    }
    if (($offer['status'] ?? '') !== 'accepted') {
        return ['error' => 'ابتدا باید روی شرایط توافق شود.'];
    }

    $credit = store_swap_effective_credit($offer);
    if ($credit !== (float) ($offer['offer_credit'] ?? 0)) {
        DB::query('UPDATE trade_offers SET offer_credit = ? WHERE id = ?', [$credit, $offerId]);
    }

    $result = accept_trade_offer($offerId, (int) $offer['store_user_id'], 'معاوضه با فروشگاه تأیید نهایی شد.');
    if (isset($result['error'])) {
        return $result;
    }

    DB::query('UPDATE trade_offers SET status = "completed", updated_at = NOW() WHERE id = ?', [$offerId]);
    store_swap_offer_add_message($offerId, $uid, 'فرآیند معاوضه با فروشگاه آغاز شد.', 'system');

    return $result;
}

/** @return list<array<string,mixed>> */
function store_swap_offer_list_for_user(int $userId, ?string $filter = null): array {
    if (!store_swap_flow_enabled()) {
        return [];
    }

    $sql = 'SELECT o.*,
                   sl.title AS store_listing_title,
                   ul.title AS user_listing_title,
                   su.store_name,
                   (SELECT filename FROM listing_images WHERE listing_id = o.listing_id AND is_primary = 1 LIMIT 1) AS store_thumb
            FROM trade_offers o
            JOIN listings sl ON sl.id = o.listing_id
            LEFT JOIN listings ul ON ul.id = o.offer_listing_id
            JOIN users su ON su.id = sl.user_id
            WHERE o.flow_type = "user_to_store" AND o.from_user_id = ?';
    $params = [$userId];

    if ($filter === 'pending') {
        $active = store_swap_active_statuses();
        $placeholders = implode(',', array_fill(0, count($active), '?'));
        $sql .= " AND o.status IN ({$placeholders})";
        $params = array_merge($params, $active);
    } elseif ($filter === 'completed') {
        $sql .= ' AND o.status IN ("completed","accepted")';
    }

    $sql .= ' ORDER BY o.created_at DESC LIMIT 100';

    $rows = DB::fetchAll($sql, $params);
    foreach ($rows as &$row) {
        $row['effective_credit'] = store_swap_effective_credit($row);
        $row['status_label'] = store_swap_offer_status_label((string) $row['status']);
    }
    unset($row);

    return $rows;
}

/** @return list<array<string,mixed>> */
function store_swap_offer_list_for_store(int $storeUserId, ?string $filter = null): array {
    if (!store_swap_flow_enabled()) {
        return [];
    }

    $sql = 'SELECT o.*,
                   sl.title AS store_listing_title, sl.estimated_value AS store_listing_value,
                   ul.title AS user_listing_title, ul.estimated_value AS user_listing_value,
                   fu.name AS from_user_name,
                   (SELECT filename FROM listing_images WHERE listing_id = o.offer_listing_id AND is_primary = 1 LIMIT 1) AS user_thumb
            FROM trade_offers o
            JOIN listings sl ON sl.id = o.listing_id
            JOIN users fu ON fu.id = o.from_user_id
            LEFT JOIN listings ul ON ul.id = o.offer_listing_id
            WHERE o.flow_type = "user_to_store" AND sl.user_id = ?';
    $params = [$storeUserId];

    if ($filter === 'pending') {
        $active = ['pending', 'negotiating', 'counter_offered'];
        $placeholders = implode(',', array_fill(0, count($active), '?'));
        $sql .= " AND o.status IN ({$placeholders})";
        $params = array_merge($params, $active);
    } elseif ($filter === 'accepted') {
        $sql .= ' AND o.status = "accepted"';
    }

    $sql .= ' ORDER BY o.created_at DESC LIMIT 100';

    return DB::fetchAll($sql, $params);
}
