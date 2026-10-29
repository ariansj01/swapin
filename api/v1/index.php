<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/geo.php';
require_once __DIR__ . '/../../includes/sms.php';

/** Compact card used in GET /store/listings lists. */
function api_store_listing_card(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'status' => $row['status'] ?? null,
        'review_status' => $row['review_status'] ?? null,
        'city' => $row['city'] ?? null,
        'neighborhood' => $row['neighborhood'] ?? null,
        'category' => $row['cat_name'] ?? null,
        'category_slug' => $row['cat_slug'] ?? null,
        'estimated_value' => (int)($row['estimated_value'] ?? 0),
        'sell_price' => (float)($row['sell_price'] ?? 0),
        'listing_mode' => $row['listing_mode'] ?? null,
        'thumb' => api_image_url($row['thumb'] ?? null),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'url' => listing_url((int)$row['id']),
    ];
}

/** Full payload for a single store listing. */
function api_store_listing_detail(array $listing, array $images): array
{
    return [
        'id' => (int)$listing['id'],
        'title' => (string)$listing['title'],
        'description' => (string)($listing['description'] ?? ''),
        'status' => $listing['status'] ?? null,
        'review_status' => $listing['review_status'] ?? null,
        'review_note' => $listing['review_note'] ?? null,
        'category_id' => (int)($listing['category_id'] ?? 0),
        'category' => $listing['cat_name'] ?? null,
        'category_slug' => $listing['cat_slug'] ?? null,
        'condition' => $listing['condition'] ?? null,
        'listing_mode' => $listing['listing_mode'] ?? 'swap',
        'estimated_value' => (int)($listing['estimated_value'] ?? 0),
        'sell_price' => (float)($listing['sell_price'] ?? 0),
        'want_description' => $listing['want_in_return'] ?? '',
        'city' => $listing['city'] ?? null,
        'neighborhood' => $listing['neighborhood'] ?? null,
        'latitude' => isset($listing['latitude']) ? (float)$listing['latitude'] : null,
        'longitude' => isset($listing['longitude']) ? (float)$listing['longitude'] : null,
        'attributes' => listing_attrs_decode($listing['listing_attrs'] ?? null),
        'images' => $images,
        'created_at' => $listing['created_at'] ?? null,
        'updated_at' => $listing['updated_at'] ?? null,
        'url' => listing_url((int)$listing['id']),
    ];
}

/**
 * Normalises incoming image payloads (multipart "images[]" files and/or
 * "images_base64" strings) into a list of upload-ready file arrays.
 *
 * @return array{0:array<int,array>,1:array<int,string>} [files, tempFiles]
 */
function api_store_image_files(array $input): array
{
    $files = [];
    if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        foreach ($_FILES['images']['name'] as $i => $name) {
            if (!$name || ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error' => $_FILES['images']['error'][$i],
                'size' => $_FILES['images']['size'][$i],
            ];
        }
    }
    if ($files) {
        return [$files, []];
    }

    $temp = [];
    $chunks = $input['images_base64'] ?? [];
    if (is_string($chunks)) {
        $chunks = [$chunks];
    }
    if (is_array($chunks)) {
        foreach ($chunks as $chunk) {
            if (!is_string($chunk) || trim($chunk) === '') {
                continue;
            }
            $chunk = trim($chunk);
            if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $chunk)) {
                $chunk = substr($chunk, strpos($chunk, ',') + 1);
            }
            $binary = base64_decode(str_replace(' ', '+', $chunk), true);
            if ($binary === false || $binary === '') {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'swk');
            if ($tmp === false || @file_put_contents($tmp, $binary) === false) {
                if (is_string($tmp)) {
                    @unlink($tmp);
                }
                continue;
            }
            $temp[] = $tmp;
            $files[] = [
                'name' => 'upload.jpg',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($binary),
            ];
        }
    }
    return [$files, $temp];
}

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
        $provinces = iran_provinces();
        if (in_array($city, $provinces, true)) {
            $provinceCities = iran_cities_by_province($city);
            if (!empty($provinceCities)) {
                $ph = implode(',', array_fill(0, count($provinceCities), '?'));
                $where[] = "l.city IN ($ph)";
                $params = array_merge($params, $provinceCities);
            } else {
                $where[] = 'l.city LIKE ?';
                $params[] = "%{$city}%";
            }
        } else {
            $where[] = 'l.city LIKE ?';
            $params[] = "%{$city}%";
        }
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
            'share_url' => listing_url($listing),
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

if ($path === 'store/listings' && $method === 'POST') {
    require_once __DIR__ . '/../../includes/iso.php';
    require_once __DIR__ . '/../../includes/ai_moderation.php';

    $store = store_api_auth();
    if (!$store) {
        api_error('invalid_api_key', 401, 'کلید API نامعتبر یا غیرفعال است.');
    }

    $input = api_body();

    $title = clean((string)($input['title'] ?? ''));
    $description = clean((string)($input['description'] ?? ''));
    $categoryId = (int)($input['category_id'] ?? 0);
    $condition = clean((string)($input['condition'] ?? 'good'));
    if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
        $condition = 'good';
    }
    $want = clean((string)($input['want_description'] ?? ''));
    $estimatedValue = max(0, (int)($input['estimated_value'] ?? 0));
    $sellPrice = max(0, (float)($input['sell_price'] ?? 0));
    $modeRaw = (string)($input['listing_mode'] ?? 'both');
    $listingMode = in_array($modeRaw, ['swap', 'sell', 'both'], true) ? $modeRaw : 'both';

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

    $location = listing_location_from_request($input);
    foreach (validate_listing_location($location) as $field => $msg) {
        $errors[$field] = $msg;
    }

    // Images: multipart "images[]" files, or base64 strings in "images_base64".
    $imageFiles = [];
    if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        foreach ($_FILES['images']['name'] as $i => $name) {
            if (!$name || ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $imageFiles[] = [
                'name' => $name,
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error' => $_FILES['images']['error'][$i],
                'size' => $_FILES['images']['size'][$i],
            ];
        }
    }

    $tempFiles = [];
    if (!$imageFiles) {
        $b64 = $input['images_base64'] ?? [];
        if (is_string($b64)) {
            $b64 = [$b64];
        }
        if (is_array($b64)) {
            foreach ($b64 as $chunk) {
                if (!is_string($chunk) || trim($chunk) === '') {
                    continue;
                }
                $chunk = trim($chunk);
                if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $chunk)) {
                    $chunk = substr($chunk, strpos($chunk, ',') + 1);
                }
                $binary = base64_decode(str_replace(' ', '+', $chunk), true);
                if ($binary === false || $binary === '') {
                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'swk');
                if ($tmp === false || @file_put_contents($tmp, $binary) === false) {
                    if (is_string($tmp)) {
                        @unlink($tmp);
                    }
                    continue;
                }
                $tempFiles[] = $tmp;
                $imageFiles[] = [
                    'name' => 'upload.jpg',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => strlen($binary),
                ];
            }
        }
    }

    if (!$imageFiles) {
        $errors['images'] = 'حداقل یک تصویر لازم است (images[] یا images_base64[]).';
    }

    if ($errors) {
        foreach ($tempFiles as $tmp) {
            @unlink($tmp);
        }
        api_json(['ok' => false, 'error' => 'validation', 'fields' => $errors], 422);
    }

    $provider = is_store_seller($store) ? user_provider_type($store) : 'normal_store';
    $listingAttrs = listing_attrs_from_input($provider, is_array($input) ? $input : []);
    $listingId = DB::insert('listings', array_merge([
        'user_id' => (int)$store['id'],
        'category_id' => $categoryId,
        'title' => $title,
        'description' => $description,
        'condition' => $condition,
        'estimated_value' => $estimatedValue,
        'want_in_return' => $want,
        'want_type' => 'any',
        'listing_mode' => $listingMode,
        'sell_price' => $sellPrice,
        'needs_inspection' => 0,
        'city' => $location['city'] ?: ($store['city'] ?? null),
        'status' => 'active',
        'review_status' => 'pending',
    ], listing_location_db_payload($location), listing_attrs_db_payload($listingAttrs)));

    $uploaded = 0;
    foreach ($imageFiles as $file) {
        if ($uploaded >= MAX_IMAGES) {
            break;
        }
        $filename = upload_image($file, 'listing');
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
    foreach ($tempFiles as $tmp) {
        @unlink($tmp);
    }

    if ($uploaded === 0) {
        DB::query('DELETE FROM listings WHERE id = ? AND user_id = ?', [$listingId, (int)$store['id']]);
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

    api_ok([
        'id' => (int)$listingId,
        'store_id' => (int)$store['id'],
        'url' => listing_url((int)$listingId),
    ], 201);
}

if ($path === 'store/listings' && $method === 'GET') {
    $store = store_api_auth();
    if (!$store) {
        api_error('invalid_api_key', 401, 'کلید API نامعتبر یا غیرفعال است.');
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = clean((string)($_GET['status'] ?? ''));
    $reviewStatus = clean((string)($_GET['review_status'] ?? ''));
    $search = clean((string)($_GET['q'] ?? ''));

    $where = ['l.user_id = ?'];
    $params = [(int)$store['id']];
    if ($status !== '') {
        $where[] = 'l.status = ?';
        $params[] = $status;
    } else {
        $where[] = 'l.status != "deleted"';
    }
    if ($reviewStatus !== '') {
        $where[] = 'l.review_status = ?';
        $params[] = $reviewStatus;
    }
    if ($search !== '') {
        $where[] = '(l.title LIKE ? OR l.description LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    $total = (int)(DB::fetch("SELECT COUNT(*) AS c FROM listings l {$sqlWhere}", $params)['c'] ?? 0);
    $rows = DB::fetchAll(
        "SELECT l.id, l.title, l.status, l.review_status, l.city, l.neighborhood,
                l.estimated_value, l.sell_price, l.listing_mode, l.created_at, l.updated_at,
                c.name AS cat_name, c.slug AS cat_slug,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN categories c ON c.id = l.category_id
         {$sqlWhere}
         ORDER BY l.updated_at DESC, l.id DESC
         LIMIT ? OFFSET ?",
        [...$params, $limit, $offset]
    );

    api_ok([
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'items' => array_map('api_store_listing_card', $rows),
    ]);
}

if (preg_match('#^store/listings/(\d+)$#', $path, $m)) {
    $store = store_api_auth();
    if (!$store) {
        api_error('invalid_api_key', 401, 'کلید API نامعتبر یا غیرفعال است.');
    }
    $storeId = (int)$store['id'];
    $listingId = (int)$m[1];

    // POST + X-HTTP-Method-Override (or _method field) supports PUT/PATCH/DELETE
    // from clients that cannot send those verbs directly (e.g. multipart uploads).
    $effectiveMethod = $method;
    if ($method === 'POST') {
        $override = strtoupper(trim((string)($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '')));
        if ($override === '') {
            $override = strtoupper(trim((string)($_POST['_method'] ?? '')));
        }
        if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            $effectiveMethod = $override;
        }
    }

    $listing = DB::fetch(
        'SELECT l.*, c.name AS cat_name, c.slug AS cat_slug
         FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.id = ? AND l.user_id = ?',
        [$listingId, $storeId]
    );
    if (!$listing || ($listing['status'] ?? '') === 'deleted') {
        api_error('not_found', 404, 'محصول یافت نشد.');
    }

    if ($effectiveMethod === 'GET') {
        $rows = DB::fetchAll(
            'SELECT id, filename, is_primary FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order',
            [$listingId]
        );
        $images = [];
        foreach ($rows as $img) {
            $images[] = [
                'id' => (int)$img['id'],
                'url' => api_image_url($img['filename']),
                'is_primary' => (bool)$img['is_primary'],
            ];
        }
        api_ok(['listing' => api_store_listing_detail($listing, $images)]);
    }

    if (in_array($effectiveMethod, ['PUT', 'PATCH'], true)) {
        require_once __DIR__ . '/../../includes/iso.php';
        require_once __DIR__ . '/../../includes/ai_moderation.php';

        $input = api_body();
        $errors = [];

        if (array_key_exists('title', $input)) {
            $title = clean((string)$input['title']);
            if (mb_strlen($title) < 5) {
                $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد';
            } elseif (mb_strlen($title) > 200) {
                $errors['title'] = 'عنوان باید کمتر از ۲۰۰ کاراکتر باشد';
            }
        } else {
            $title = (string)$listing['title'];
        }

        if (array_key_exists('description', $input)) {
            $description = clean((string)$input['description']);
            if (mb_strlen($description) < 20) {
                $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد';
            }
        } else {
            $description = (string)$listing['description'];
        }

        if (array_key_exists('category_id', $input)) {
            $categoryId = (int)$input['category_id'];
            if (!$categoryId || !wizard_validate_category_id($categoryId)) {
                $errors['category_id'] = 'دسته‌بندی نامعتبر است';
            }
        } else {
            $categoryId = (int)$listing['category_id'];
        }

        if (array_key_exists('condition', $input)) {
            $condition = clean((string)$input['condition']);
            if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
                $errors['condition'] = 'وضعیت نامعتبر است';
                $condition = (string)($listing['condition'] ?? 'good');
            }
        } else {
            $condition = (string)($listing['condition'] ?? 'good');
        }

        if (array_key_exists('listing_mode', $input)) {
            $modeRaw = clean((string)$input['listing_mode']);
            if (!in_array($modeRaw, ['swap', 'sell', 'both'], true)) {
                $errors['listing_mode'] = 'حالت آگهی نامعتبر است';
                $listingMode = (string)($listing['listing_mode'] ?? 'both');
            } else {
                $listingMode = $modeRaw;
            }
        } else {
            $listingMode = (string)($listing['listing_mode'] ?? 'both');
        }

        $estimatedValue = array_key_exists('estimated_value', $input)
            ? max(0, (int)$input['estimated_value'])
            : (int)($listing['estimated_value'] ?? 0);
        $sellPrice = array_key_exists('sell_price', $input)
            ? max(0, (float)$input['sell_price'])
            : (float)($listing['sell_price'] ?? 0);
        $want = array_key_exists('want_description', $input)
            ? clean((string)$input['want_description'])
            : (string)($listing['want_in_return'] ?? '');

        $location = listing_location_from_request([
            'city' => array_key_exists('city', $input) ? (string)$input['city'] : (string)($listing['city'] ?? ''),
            'neighborhood' => array_key_exists('neighborhood', $input) ? (string)$input['neighborhood'] : (string)($listing['neighborhood'] ?? ''),
            'latitude' => array_key_exists('latitude', $input) ? (string)$input['latitude'] : (string)($listing['latitude'] ?? ''),
            'longitude' => array_key_exists('longitude', $input) ? (string)$input['longitude'] : (string)($listing['longitude'] ?? ''),
        ]);
        foreach (validate_listing_location($location) as $field => $msg) {
            $errors[$field] = $msg;
        }

        $currentImages = DB::fetchAll(
            'SELECT id FROM listing_images WHERE listing_id = ?',
            [$listingId]
        );
        $existingIds = array_map(static fn ($r) => (int)$r['id'], $currentImages);

        $deleteIds = [];
        $rawDelete = $input['delete_image_ids'] ?? [];
        if (is_string($rawDelete)) {
            $rawDelete = preg_split('/[\s,]+/', $rawDelete) ?: [];
        }
        if (is_array($rawDelete)) {
            foreach ($rawDelete as $imgId) {
                $imgId = (int)$imgId;
                if ($imgId > 0 && in_array($imgId, $existingIds, true)) {
                    $deleteIds[$imgId] = $imgId;
                }
            }
        }
        $deleteIds = array_values($deleteIds);

        [$imageFiles, $tempFiles] = api_store_image_files($input);

        $finalCount = count($currentImages) - count($deleteIds) + count($imageFiles);
        if ($finalCount < 1) {
            $errors['images'] = 'حداقل یک تصویر الزامی است.';
        } elseif ($finalCount > MAX_IMAGES) {
            $errors['images'] = 'حداکثر ' . MAX_IMAGES . ' تصویر مجاز است.';
        }

        if ($errors) {
            foreach ($tempFiles as $tmp) {
                @unlink($tmp);
            }
            api_json(['ok' => false, 'error' => 'validation', 'fields' => $errors], 422);
        }

        foreach ($deleteIds as $imgId) {
            $img = DB::fetch('SELECT filename FROM listing_images WHERE id = ? AND listing_id = ?', [$imgId, $listingId]);
            if ($img) {
                $path = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $img['filename'];
                if (is_file($path)) {
                    @unlink($path);
                }
                DB::query('DELETE FROM listing_images WHERE id = ?', [$imgId]);
            }
        }

        $provider = is_store_seller($store) ? user_provider_type($store) : 'normal_store';
        $existingAttrs = listing_attrs_decode($listing['listing_attrs'] ?? null);
        $newAttrs = listing_attrs_from_input($provider, $input);
        $attrsPayload = $newAttrs ? listing_attrs_db_payload(array_merge($existingAttrs, $newAttrs)) : [];

        $reviewUpdate = [];
        if (($listing['review_status'] ?? '') === 'approved') {
            $reviewUpdate['review_status'] = 'pending';
            if (db_has_column('listings', 'review_note')) {
                $reviewUpdate['review_note'] = null;
            }
        }

        DB::update('listings', array_merge([
            'category_id' => $categoryId,
            'title' => $title,
            'description' => $description,
            'condition' => $condition,
            'estimated_value' => $estimatedValue,
            'want_in_return' => $want,
            'listing_mode' => $listingMode,
            'sell_price' => in_array($listingMode, ['sell', 'both'], true) ? $sellPrice : 0,
            'city' => $location['city'] ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], listing_location_db_payload($location), $attrsPayload, $reviewUpdate), 'id = ? AND user_id = ?', [$listingId, $storeId]);

        $imgCount = (int)(DB::fetch('SELECT COUNT(*) AS c FROM listing_images WHERE listing_id = ?', [$listingId])['c'] ?? 0);
        foreach ($imageFiles as $file) {
            if ($imgCount >= MAX_IMAGES) {
                break;
            }
            $filename = upload_image($file, 'listing');
            if ($filename) {
                DB::insert('listing_images', [
                    'listing_id' => $listingId,
                    'filename' => $filename,
                    'is_primary' => $imgCount === 0 ? 1 : 0,
                    'sort_order' => $imgCount,
                ]);
                $imgCount++;
            }
        }
        foreach ($tempFiles as $tmp) {
            @unlink($tmp);
        }

        if ($imgCount < 1) {
            api_error('upload_failed', 422, 'آپلود تصاویر ناموفق بود.');
        }

        $hasPrimary = DB::fetch('SELECT id FROM listing_images WHERE listing_id = ? AND is_primary = 1 LIMIT 1', [$listingId]);
        if (!$hasPrimary) {
            $first = DB::fetch('SELECT id FROM listing_images WHERE listing_id = ? ORDER BY sort_order, id LIMIT 1', [$listingId]);
            if ($first) {
                DB::query('UPDATE listing_images SET is_primary = 1 WHERE id = ?', [(int)$first['id']]);
            }
        }

        $listing = DB::fetch(
            'SELECT l.*, c.name AS cat_name, c.slug AS cat_slug
             FROM listings l JOIN categories c ON c.id = l.category_id
             WHERE l.id = ?',
            [$listingId]
        );
        $rows = DB::fetchAll(
            'SELECT id, filename, is_primary FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order',
            [$listingId]
        );
        $images = [];
        foreach ($rows as $img) {
            $images[] = [
                'id' => (int)$img['id'],
                'url' => api_image_url($img['filename']),
                'is_primary' => (bool)$img['is_primary'],
            ];
        }
        api_ok(['listing' => api_store_listing_detail($listing, $images)]);
    }

    if ($effectiveMethod === 'DELETE') {
        $set = ['status' => 'deleted', 'updated_at' => date('Y-m-d H:i:s')];
        if (db_has_column('listings', 'deleted_at')) {
            $set['deleted_at'] = date('Y-m-d H:i:s');
        }
        DB::update('listings', $set, 'id = ? AND user_id = ?', [$listingId, $storeId]);
        api_ok(['id' => $listingId, 'deleted' => true]);
    }

    api_error('method_not_allowed', 405, 'متد HTTP پشتیبانی نمی‌شود.');
}

api_error('not_found', 404, 'مسیر API یافت نشد.');
