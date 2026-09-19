<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/geo.php';
require_once __DIR__ . '/../../includes/sms.php';

$path = api_path();
$method = api_method();
$segments = $path === '' ? [] : explode('/', $path);

if ($method === 'OPTIONS') {
    api_ok();
}

if ($path === '' || $path === 'health') {
    api_ok(['name' => 'swaapin-mobile', 'version' => '1']);
}

if ($path === 'auth/otp/send' && $method === 'POST') {
    rate_limit_ip_or_fail('mobile_otp_request', 8, 600, true);
    $body = api_body();
    $intl = api_normalize_phone((string)($body['phone'] ?? ''));
    if (!$intl) {
        api_error('invalid_phone', 422, 'لطفاً یک شماره تلفن معتبر وارد کنید (مثل 09123456789)');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    DB::query('DELETE FROM otp_codes WHERE phone = ?', [$intl]);
    DB::query(
        'INSERT INTO otp_codes (phone, code, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())',
        [$intl, password_hash($code, PASSWORD_BCRYPT), OTP_EXPIRE]
    );
    $smsSent = send_otp_sms($intl, $code);
    $payload = [
        'phone' => api_phone_local($intl),
        'expires_in' => OTP_EXPIRE,
        'sms_sent' => (bool)$smsSent,
    ];
    if (!$smsSent && !app_is_production()) {
        $payload['dev_code'] = $code;
        $payload['message'] = 'ارسال پیامک ناموفق بود. کد تأیید (فقط محیط توسعه): ' . $code;
    } elseif (!$smsSent) {
        $payload['message'] = function_exists('safe_sms_error') ? safe_sms_error(last_sms_error()) : 'ارسال پیامک ناموفق بود.';
    }
    api_ok($payload);
}

if ($path === 'auth/otp/verify' && $method === 'POST') {
    rate_limit_ip_or_fail('mobile_otp_verify', 15, 900, true);
    $body = api_body();
    $intl = api_normalize_phone((string)($body['phone'] ?? ''));
    $code = preg_replace('/\D+/', '', normalize_digits((string)($body['code'] ?? ''))) ?? '';
    if (!$intl || strlen($code) !== 6) {
        api_error('invalid_input', 422, 'شماره یا کد تأیید نامعتبر است.');
    }
    $row = DB::fetch(
        'SELECT * FROM otp_codes WHERE phone = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1',
        [$intl]
    );
    if (!$row || !password_verify($code, $row['code'])) {
        api_error('invalid_otp', 422, 'کد نامعتبر یا منقضی شده است.');
    }
    DB::query('UPDATE otp_codes SET used = 1 WHERE id = ?', [$row['id']]);

    $user = DB::fetch('SELECT * FROM users WHERE phone = ? AND is_active = 1', [$intl]);
    $isNew = false;
    if ($user) {
        if (empty($user['phone_verified_at'])) {
            DB::update('users', ['phone_verified_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$user['id']]);
            $user['phone_verified_at'] = date('Y-m-d H:i:s');
        }
    } else {
        $uid = register_phone_user($intl);
        notify_profile_completion($uid);
        $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$uid]);
        $isNew = true;
    }

    $token = api_issue_token((int)$user['id'], (string)($body['device'] ?? 'android'));
    api_ok([
        'token' => $token,
        'is_new' => $isNew,
        'user' => api_user_public($user),
    ]);
}

if ($path === 'auth/logout' && $method === 'POST') {
    $raw = api_bearer_token();
    if ($raw) {
        DB::query('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $raw)]);
    }
    api_ok();
}

if ($path === 'me' && $method === 'GET') {
    $user = api_require_user();
    api_ok(['user' => api_user_public($user)]);
}

if ($path === 'me' && in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
    $user = api_require_user();
    $body = api_body();
    $name = trim(clean((string)($body['name'] ?? $user['name'] ?? '')));
    $city = trim(clean((string)($body['city'] ?? $user['city'] ?? '')));
    if (mb_strlen($name) < 2) {
        api_error('invalid_name', 422, 'نام باید حداقل ۲ کاراکتر باشد.');
    }
    if ($city !== '' && !in_array($city, iran_cities(), true)) {
        api_error('invalid_city', 422, 'شهر معتبر نیست.');
    }
    DB::update('users', ['name' => $name, 'city' => $city !== '' ? $city : null], 'id = ?', [(int)$user['id']]);
    $user = DB::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
    if (user_profile_is_complete($user)) {
        dismiss_profile_completion_notifications((int)$user['id']);
    }
    api_ok(['user' => api_user_public($user)]);
}

if ($path === 'cities' && $method === 'GET') {
    api_ok(['cities' => iran_cities()]);
}

if ($path === 'categories' && $method === 'GET') {
    $rows = DB::fetchAll(
        'SELECT id, name, slug, parent_id, icon FROM categories WHERE is_active = 1 ORDER BY sort_order, id'
    );
    $items = array_map(static fn ($r) => [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'slug' => $r['slug'],
        'parent_id' => $r['parent_id'] ? (int)$r['parent_id'] : null,
        'icon' => $r['icon'] ?? null,
    ], $rows);
    api_ok(['items' => $items]);
}

if ($path === 'nearby-cities' && $method === 'GET') {
    rate_limit_ip_or_fail('nearby_cities', 120, 3600, true);
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
        api_error('invalid_coords', 422, 'مختصات نامعتبر است.');
    }
    if (!geo_coords_in_iran($lat, $lng)) {
        api_error('coords_out_of_range', 422, 'موقعیت خارج از ایران است.');
    }
    $cities = find_nearby_iran_cities($lat, $lng);
    if ($cities === []) {
        api_error('no_cities_found', 404, 'شهر نزدیکی یافت نشد.');
    }
    api_ok(['cities' => $cities, 'nearest' => $cities[0]]);
}

if ($path === 'listings' && $method === 'GET') {
    $search = clean($_GET['q'] ?? '');
    $catSlug = clean($_GET['cat'] ?? '');
    $city = clean($_GET['city'] ?? '');
    $nearby = clean($_GET['nearby_cities'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(30, max(1, (int)($_GET['limit'] ?? 12)));
    $offset = ($page - 1) * $limit;

    $category = $catSlug ? DB::fetch('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [$catSlug]) : null;
    $where = [listing_public_sql('l'), 'l.listing_mode != "sell"'];
    $params = [];
    if ($search) {
        $where[] = '(l.title LIKE ? OR l.description LIKE ? OR l.want_in_return LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    if ($category) {
        $where[] = '(l.category_id = ? OR c.parent_id = ?)';
        $params[] = (int)$category['id'];
        $params[] = (int)$category['id'];
    }
    if ($nearby !== '') {
        $cities = array_values(array_filter(array_map('trim', explode(',', $nearby))));
        if ($cities) {
            $ph = implode(',', array_fill(0, count($cities), '?'));
            $where[] = "l.city IN ($ph)";
            $params = array_merge($params, $cities);
        }
    } elseif ($city) {
        $where[] = 'l.city LIKE ?';
        $params[] = "%{$city}%";
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);
    $total = (int)(DB::fetch(
        "SELECT COUNT(*) AS c FROM listings l JOIN categories c ON c.id = l.category_id {$sqlWhere}",
        $params
    )['c'] ?? 0);
    $rows = DB::fetchAll(
        "SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
                c.name AS cat_name, c.slug AS cat_slug,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         {$sqlWhere}
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT ? OFFSET ?",
        [...$params, $limit, $offset]
    );
    api_ok([
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'items' => array_map('api_listing_card', $rows),
    ]);
}

if ($path === 'listings/mine' && $method === 'GET') {
    $user = api_require_user();
    $rows = DB::fetchAll(
        "SELECT l.*, c.name AS cat_name, c.slug AS cat_slug,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.user_id = ?
         ORDER BY l.updated_at DESC, l.id DESC
         LIMIT 50",
        [(int)$user['id']]
    );
    $items = [];
    foreach ($rows as $row) {
        $card = api_listing_card($row + ['seller_name' => $user['name']]);
        $card['status'] = $row['status'] ?? null;
        $card['review_status'] = $row['review_status'] ?? null;
        $items[] = $card;
    }
    api_ok(['items' => $items]);
}

if (preg_match('#^listings/(\d+)$#', $path, $m) && $method === 'GET') {
    $id = (int)$m[1];
    $listing = DB::fetch(
        "SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
                u.avatar AS seller_avatar, u.rating_count AS seller_rating_count,
                c.name AS cat_name, c.slug AS cat_slug
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         WHERE l.id = ?",
        [$id]
    );
    if (!$listing) {
        api_error('not_found', 404, 'آگهی یافت نشد.');
    }
    $viewer = api_auth_user();
    $isOwner = $viewer && (int)$viewer['id'] === (int)$listing['user_id'];
    if (($listing['status'] ?? '') !== 'active' && !$isOwner) {
        api_error('not_found', 404, 'آگهی یافت نشد.');
    }
    $images = DB::fetchAll(
        'SELECT filename, is_primary FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order',
        [$id]
    );
    $saved = false;
    if ($viewer) {
        $saved = (bool)DB::fetch(
            'SELECT 1 FROM saved_listings WHERE user_id = ? AND listing_id = ?',
            [(int)$viewer['id'], $id]
        );
    }
    $card = api_listing_card($listing);
    api_ok([
        'listing' => $card + [
            'description' => (string)$listing['description'],
            'condition' => $listing['condition'] ?? null,
            'latitude' => isset($listing['latitude']) ? (float)$listing['latitude'] : null,
            'longitude' => isset($listing['longitude']) ? (float)$listing['longitude'] : null,
            'seller' => [
                'id' => (int)$listing['user_id'],
                'name' => $listing['seller_name'],
                'city' => $listing['seller_city'],
                'rating' => (float)($listing['seller_rating'] ?? 0),
                'rating_count' => (int)($listing['seller_rating_count'] ?? 0),
                'avatar' => api_image_url($listing['seller_avatar'] ?? null),
            ],
            'images' => array_values(array_filter(array_map(
                static fn ($img) => api_image_url($img['filename'] ?? null),
                $images
            ))),
            'saved' => $saved,
            'is_owner' => (bool)$isOwner,
            'share_url' => APP_URL . '/listings/view?id=' . $id,
        ],
    ]);
}

if (preg_match('#^listings/(\d+)/save$#', $path, $m) && $method === 'POST') {
    $user = api_require_user();
    $listingId = (int)$m[1];
    $listing = DB::fetch(
        'SELECT id FROM listings WHERE id = ? AND status = "active" AND review_status = "approved"',
        [$listingId]
    );
    if (!$listing) {
        api_error('not_found', 404, 'آگهی یافت نشد.');
    }
    $existing = DB::fetch(
        'SELECT 1 FROM saved_listings WHERE user_id = ? AND listing_id = ?',
        [(int)$user['id'], $listingId]
    );
    if ($existing) {
        DB::query('DELETE FROM saved_listings WHERE user_id = ? AND listing_id = ?', [(int)$user['id'], $listingId]);
        api_ok(['saved' => false]);
    }
    DB::query('INSERT IGNORE INTO saved_listings (user_id, listing_id) VALUES (?, ?)', [(int)$user['id'], $listingId]);
    api_ok(['saved' => true]);
}

if ($path === 'listings' && $method === 'POST') {
    require_once __DIR__ . '/../../includes/iso.php';
    require_once __DIR__ . '/../../includes/ai_moderation.php';
    $user = api_require_user();
    $kycErr = kyc_check_listing_action($user);
    if ($kycErr) {
        api_error('profile_incomplete', 403, $kycErr);
    }
    $title = clean($_POST['title'] ?? '');
    $description = clean($_POST['description'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $condition = clean($_POST['condition'] ?? 'good');
    $want = clean($_POST['want_description'] ?? '');
    $customValue = (int)($_POST['custom_value'] ?? 0);
    $sellPrice = (float)($_POST['sell_price'] ?? 0);
    $errors = [];
    if (mb_strlen($title) < 5) {
        $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد';
    }
    if (!$categoryId || !wizard_validate_category_id($categoryId)) {
        $errors['category_id'] = 'دسته‌بندی نامعتبر است';
    }
    if (mb_strlen($description) < 20) {
        $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد';
    }
    $location = listing_location_from_request($_POST);
    foreach (validate_listing_location($location) as $field => $msg) {
        $errors[$field] = $msg;
    }
    $hasImage = false;
    if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        foreach ($_FILES['images']['name'] as $i => $name) {
            if ($name && ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $hasImage = true;
                break;
            }
        }
    }
    if (!$hasImage) {
        $errors['images'] = 'حداقل یک تصویر الزامی است.';
    }
    if ($errors) {
        api_json(['ok' => false, 'error' => 'validation', 'fields' => $errors], 422);
    }
    $provider = is_store_seller($user) ? user_provider_type($user) : 'normal_store';
    $listingAttrs = listing_attrs_from_input($provider, $_POST);
    $listingId = DB::insert('listings', array_merge([
        'user_id' => $user['id'],
        'category_id' => $categoryId,
        'title' => $title,
        'description' => $description,
        'condition' => $condition,
        'estimated_value' => $customValue ?: 0,
        'want_in_return' => $want,
        'want_type' => 'any',
        'listing_mode' => 'both',
        'sell_price' => $sellPrice,
        'needs_inspection' => 0,
        'city' => $location['city'] ?: null,
        'status' => 'active',
        'review_status' => 'pending',
    ], listing_location_db_payload($location), listing_attrs_db_payload($listingAttrs)));

    $uploaded = 0;
    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        if ($uploaded >= MAX_IMAGES) {
            break;
        }
        if (empty($tmp) || ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $filename = upload_image([
            'name' => $_FILES['images']['name'][$i],
            'tmp_name' => $tmp,
            'error' => $_FILES['images']['error'][$i],
            'size' => $_FILES['images']['size'][$i],
        ], 'listing');
        if ($filename) {
            DB::insert('listing_images', [
                'listing_id' => $listingId,
                'filename' => $filename,
                'is_primary' => $uploaded === 0 ? 1 : 0,
                'sort_order' => $uploaded,
            ]);
            $uploaded++;
        }
    }
    if ($uploaded === 0) {
        DB::query('DELETE FROM listings WHERE id = ? AND user_id = ?', [$listingId, $user['id']]);
        api_error('upload_failed', 422, 'آپلود تصاویر ناموفق بود.');
    }
    try {
        if (function_exists('iso_process_new_listing_matches')) {
            iso_process_new_listing_matches((int)$listingId);
        }
    } catch (Throwable) {
    }
    try {
        if (function_exists('ai_mod_review_listing')) {
            ai_mod_review_listing((int)$listingId);
        }
    } catch (Throwable) {
    }
    api_ok(['id' => (int)$listingId], 201);
}

if ($path === 'notifications' && $method === 'GET') {
    $user = api_require_user();
    $uid = (int)$user['id'];
    $items = [];
    $pendingOffers = DB::fetchAll(
        'SELECT o.id, o.created_at, o.listing_id, l.title AS listing_title, u.name AS from_name
         FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         JOIN users u ON u.id = o.from_user_id
         WHERE l.user_id = ? AND o.status = "pending"
         ORDER BY o.created_at DESC LIMIT 12',
        [$uid]
    );
    foreach ($pendingOffers as $o) {
        $items[] = [
            'type' => 'offer',
            'id' => 'offer-' . (int)$o['id'],
            'title' => 'پیشنهاد معاوضه جدید',
            'body' => $o['from_name'] . ' برای «' . mb_strimwidth($o['listing_title'], 0, 40, '…') . '» پیشنهاد داد',
            'time' => $o['created_at'],
            'time_ago' => timeago($o['created_at']),
            'url' => '/trades',
        ];
    }
    if (db_has_table('notifications')) {
        foreach (fetch_user_db_notifications($uid, 20) as $n) {
            $items[] = [
                'type' => $n['type'] ?? 'notification',
                'id' => (int)$n['id'],
                'title' => $n['title'],
                'body' => $n['body'] ?? '',
                'time' => $n['created_at'],
                'time_ago' => timeago($n['created_at']),
                'url' => $n['link'] ?: '/search/saved',
                'is_read' => (int)($n['is_read'] ?? 0),
            ];
        }
    }
    usort($items, static fn ($a, $b) => strtotime($b['time'] ?? 'now') <=> strtotime($a['time'] ?? 'now'));
    api_ok(['total' => count($items), 'items' => array_slice($items, 0, 20)]);
}

if ($path === 'trades' && $method === 'GET') {
    $user = api_require_user();
    $uid = (int)$user['id'];
    $rows = DB::fetchAll(
        "SELECT t.*,
                la.title AS listing_a_title,
                lb.title AS listing_b_title,
                (SELECT filename FROM listing_images WHERE listing_id = t.listing_a_id AND is_primary = 1 LIMIT 1) AS img_a,
                (SELECT filename FROM listing_images WHERE listing_id = t.listing_b_id AND is_primary = 1 LIMIT 1) AS img_b
         FROM trades t
         LEFT JOIN listings la ON la.id = t.listing_a_id
         LEFT JOIN listings lb ON lb.id = t.listing_b_id
         WHERE t.user_a_id = ? OR t.user_b_id = ?
         ORDER BY t.updated_at DESC, t.id DESC
         LIMIT 40",
        [$uid, $uid]
    );
    $items = [];
    foreach ($rows as $t) {
        $items[] = [
            'id' => (int)$t['id'],
            'status' => $t['status'] ?? '',
            'listing_a_title' => $t['listing_a_title'] ?? '',
            'listing_b_title' => $t['listing_b_title'] ?? '',
            'thumb' => api_image_url($t['img_a'] ?? $t['img_b'] ?? null),
            'updated_at' => $t['updated_at'] ?? $t['created_at'] ?? null,
        ];
    }
    $pending = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "pending"',
        [$uid]
    )['c'] ?? 0);
    api_ok(['items' => $items, 'pending_offers' => $pending]);
}

api_error('not_found', 404, 'مسیر API یافت نشد.');
