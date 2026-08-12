<?php
/** Saved searches + smart alert matching. */

function saved_searches_enabled(): bool {
    return db_has_table('saved_searches');
}

function saved_search_normalize_filters(array $data): array {
    $need = trim((string)($data['need_text'] ?? $data['q'] ?? ''));
    $city = trim(clean($data['city'] ?? ''));
    $categoryId = (int)($data['category_id'] ?? 0) ?: null;
    $priceMin = max(0, (int)($data['price_min'] ?? 0)) ?: null;
    $priceMax = max(0, (int)($data['price_max'] ?? 0)) ?: null;
    $wantType = clean($data['want_type'] ?? '');
    if ($wantType && !in_array($wantType, ['item', 'service', 'credit', 'any'], true)) {
        $wantType = '';
    }
    if ($wantType === 'any') {
        $wantType = '';
    }

    $keywords = trim((string)($data['keywords'] ?? ''));
    if (!$keywords && $need) {
        $keywords = saved_search_extract_keywords($need);
    }

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        $title = mb_strimwidth($need ?: ($keywords ?: 'جستجوی ذخیره‌شده'), 0, 80, '…');
    }

    return [
        'title'       => $title,
        'need_text'   => $need,
        'keywords'    => $keywords,
        'city'        => $city ?: null,
        'category_id' => $categoryId,
        'price_min'   => $priceMin,
        'price_max'   => $priceMax,
        'want_type'   => $wantType ?: null,
        'filters_json'=> json_encode([
            'need_text'   => $need,
            'keywords'    => $keywords,
            'city'        => $city,
            'category_id' => $categoryId,
            'price_min'   => $priceMin,
            'price_max'   => $priceMax,
            'want_type'   => $wantType,
        ], JSON_UNESCAPED_UNICODE),
    ];
}

function saved_search_extract_keywords(string $text): string {
    $stop = ['برای', 'میخوام', 'می‌خوام', 'میخواهم', 'یک', 'عدد', 'نو', 'دست', 'دوم', 'در', 'با', 'و', 'یا', 'که', 'از', 'به'];
    $words = preg_split('/\s+/u', mb_strtolower(trim($text))) ?: [];
    $out = [];
    foreach ($words as $w) {
        $w = trim($w, '،,.');
        if (mb_strlen($w) < 2 || in_array($w, $stop, true)) {
            continue;
        }
        $out[] = $w;
        if (count($out) >= 8) {
            break;
        }
    }
    return implode(' ', $out);
}

function create_saved_search(int $userId, array $data): array {
    if (!saved_searches_enabled()) {
        return ['error' => 'سیستم جستجوی ذخیره‌شده فعال نیست.'];
    }

    $filters = saved_search_normalize_filters($data);
    if (mb_strlen($filters['need_text']) < 3 && mb_strlen($filters['keywords']) < 2) {
        return ['error' => 'متن نیازمندی یا کلیدواژه را وارد کنید.'];
    }

    $count = (int)(DB::fetch('SELECT COUNT(*) AS c FROM saved_searches WHERE user_id = ?', [$userId])['c'] ?? 0);
    if ($count >= 20) {
        return ['error' => 'حداکثر ۲۰ جستجو می‌توانید ذخیره کنید.'];
    }

    $id = DB::insert('saved_searches', [
        'user_id'       => $userId,
        'title'         => $filters['title'],
        'need_text'     => $filters['need_text'],
        'keywords'      => $filters['keywords'],
        'city'          => $filters['city'],
        'category_id'   => $filters['category_id'],
        'price_min'     => $filters['price_min'],
        'price_max'     => $filters['price_max'],
        'want_type'     => $filters['want_type'],
        'filters_json'  => $filters['filters_json'],
        'alert_enabled' => !empty($data['alert_enabled']) ? 1 : 1,
    ]);

    return ['ok' => true, 'id' => $id];
}

function delete_saved_search(int $userId, int $searchId): bool {
    if (!saved_searches_enabled()) {
        return false;
    }
    DB::query('DELETE FROM saved_searches WHERE id = ? AND user_id = ?', [$searchId, $userId]);
    return true;
}

function toggle_saved_search_alert(int $userId, int $searchId, bool $enabled): bool {
    if (!saved_searches_enabled()) {
        return false;
    }
    DB::update('saved_searches', ['alert_enabled' => $enabled ? 1 : 0], 'id = ? AND user_id = ?', [$searchId, $userId]);
    return true;
}

function fetch_user_saved_searches(int $userId): array {
    if (!saved_searches_enabled()) {
        return [];
    }
    return DB::fetchAll(
        'SELECT s.*,
                c.name AS cat_name, c.slug AS cat_slug,
                (SELECT COUNT(*) FROM saved_search_hits h WHERE h.saved_search_id = s.id) AS hit_count
         FROM saved_searches s
         LEFT JOIN categories c ON c.id = s.category_id
         WHERE s.user_id = ?
         ORDER BY s.created_at DESC',
        [$userId]
    );
}

function listing_matches_saved_search(array $listing, array $search): bool {
    if ((int)($listing['user_id'] ?? 0) === (int)($search['user_id'] ?? 0)) {
        return false;
    }

    if (!empty($search['city'])) {
        $listingCity = trim((string)($listing['city'] ?? ''));
        if ($listingCity === '' || mb_stripos($listingCity, (string)$search['city']) === false) {
            return false;
        }
    }

    if (!empty($search['category_id'])) {
        $catId = (int)$listing['category_id'];
        $searchCat = (int)$search['category_id'];
        $parentId = (int)($listing['cat_parent_id'] ?? 0);
        if ($catId !== $searchCat && $parentId !== $searchCat) {
            return false;
        }
    }

    if (!empty($search['price_min']) && (float)($listing['estimated_value'] ?? 0) < (float)$search['price_min']) {
        return false;
    }
    if (!empty($search['price_max']) && (float)($listing['estimated_value'] ?? 0) > (float)$search['price_max']) {
        return false;
    }

    if (!empty($search['want_type']) && ($listing['want_type'] ?? '') !== $search['want_type']) {
        return false;
    }

    $haystack = mb_strtolower(
        ($listing['title'] ?? '') . ' ' .
        ($listing['description'] ?? '') . ' ' .
        ($listing['want_in_return'] ?? '') . ' ' .
        ($listing['cat_name'] ?? '')
    );

    $needleParts = [];
    foreach ([$search['need_text'] ?? '', $search['keywords'] ?? ''] as $part) {
        foreach (preg_split('/\s+/u', mb_strtolower(trim((string)$part))) ?: [] as $w) {
            $w = trim($w, '،,.');
            if (mb_strlen($w) >= 2) {
                $needleParts[] = $w;
            }
        }
    }
    $needleParts = array_values(array_unique($needleParts));
    if (empty($needleParts)) {
        return false;
    }

    $hits = 0;
    foreach ($needleParts as $w) {
        if (mb_strpos($haystack, $w) !== false) {
            $hits++;
        }
    }

    return $hits >= min(2, count($needleParts));
}

function notify_saved_search_match(int $userId, array $search, array $listing): void {
    if (!db_has_table('notifications')) {
        return;
    }

    $title = 'کالای جدید مطابق جستجوی شما';
    $body  = '«' . mb_strimwidth($listing['title'] ?? '', 0, 50, '…') . '»'
        . (!empty($search['city']) ? ' در ' . $search['city'] : '')
        . ' — جستجو: ' . mb_strimwidth($search['title'] ?? '', 0, 40, '…');

    DB::insert('notifications', [
        'user_id' => $userId,
        'type'    => 'saved_search',
        'title'   => $title,
        'body'    => $body,
        'link'    => APP_URL . '/listings/view?id=' . (int)$listing['id'],
        'meta_json' => json_encode([
            'saved_search_id' => (int)$search['id'],
            'listing_id'      => (int)$listing['id'],
        ], JSON_UNESCAPED_UNICODE),
        'is_read' => 0,
    ]);
}

function process_saved_search_alerts_for_listing(int $listingId): void {
    if (!saved_searches_enabled() || !db_has_table('saved_search_hits')) {
        return;
    }

    $listing = DB::fetch(
        'SELECT l.*, c.name AS cat_name, c.parent_id AS cat_parent_id
         FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.id = ? AND l.status = "active" AND l.review_status = "approved"',
        [$listingId]
    );
    if (!$listing) {
        return;
    }

    $searches = DB::fetchAll(
        'SELECT * FROM saved_searches WHERE alert_enabled = 1'
    );

    foreach ($searches as $search) {
        if (!listing_matches_saved_search($listing, $search)) {
            continue;
        }

        $exists = DB::fetch(
            'SELECT id FROM saved_search_hits WHERE saved_search_id = ? AND listing_id = ? LIMIT 1',
            [(int)$search['id'], $listingId]
        );
        if ($exists) {
            continue;
        }

        DB::insert('saved_search_hits', [
            'saved_search_id' => (int)$search['id'],
            'listing_id'      => $listingId,
        ]);

        notify_saved_search_match((int)$search['user_id'], $search, $listing);

        DB::update('saved_searches', [
            'last_notified_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int)$search['id']]);
    }
}

function fetch_user_db_notifications(int $userId, int $limit = 15): array {
    if (!db_has_table('notifications')) {
        return [];
    }
    return DB::fetchAll(
        'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?',
        [$userId, $limit]
    );
}

function mark_notifications_read(int $userId, ?array $ids = null): void {
    if (!db_has_table('notifications')) {
        return;
    }
    if ($ids) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            DB::query("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($ph)", array_merge([$userId], $ids));
        }
        return;
    }
    DB::update('notifications', ['is_read' => 1], 'user_id = ? AND is_read = 0', [$userId]);
}
