<?php
/** Store profile + registration request helpers. */

function provider_type_labels(): array {
    return [
        'normal_store' => 'فروشگاه عادی',
        'real_estate'  => 'مشاور / آژانس املاک',
        'car_dealer'   => 'نمایشگاه خودرو',
        'corporate'    => 'کسب‌وکار شرکتی',
    ];
}

function normalize_provider_type(?string $type): string {
    $labels = provider_type_labels();
    return isset($labels[$type ?? '']) ? (string)$type : 'normal_store';
}

function user_provider_type(array $user): string {
    return normalize_provider_type($user['provider_type'] ?? 'normal_store');
}

function store_type_labels(): array {
    return [
        'both'     => 'هر دو (آنلاین و حضوری)',
        'online'   => 'آنلاین',
        'physical' => 'حضوری',
    ];
}

function normalize_store_type(?string $type): string {
    $labels = store_type_labels();
    return isset($labels[$type ?? '']) ? (string)$type : 'both';
}

function user_store_type(array $user): string {
    return normalize_store_type($user['store_type'] ?? 'both');
}

function render_store_type_select(string $name, string $selected): string {
    $labels = store_type_labels();
    $out = '<select class="form-control" id="' . htmlspecialchars($name) . '" name="' . htmlspecialchars($name) . '">';
    foreach ($labels as $value => $label) {
        $sel = $value === $selected ? 'selected' : '';
        $out .= '<option value="' . htmlspecialchars($value) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    $out .= '</select>';
    return $out;
}

/** @return array<string, array{id:string,icon:string,label:string,show:bool,badge?:int}> */
function store_panel_tabs(string $providerType, int $requestBadge = 0, int $orderBadge = 0): array {
    $tabs = [
        'dashboard'     => ['id' => 'dashboard',     'icon' => 'bi-speedometer2',    'label' => 'داشبورد اصلی',      'show' => true],
        'products'      => ['id' => 'products',      'icon' => 'bi-box-seam',        'label' => 'محصولات',           'show' => true],
        'requests'      => ['id' => 'requests',      'icon' => 'bi-inbox',           'label' => 'درخواست‌ها',        'show' => true, 'badge' => $requestBadge],
        'orders'        => ['id' => 'orders',        'icon' => 'bi-bag-check',       'label' => 'سفارش‌های فروش',    'show' => true, 'badge' => $orderBadge],
        'messages'      => ['id' => 'messages',      'icon' => 'bi-chat-dots',       'label' => 'پیام‌ها',           'show' => true],
        'management'    => ['id' => 'management',    'icon' => 'bi-gear',            'label' => 'مدیریت فروشگاه',    'show' => true],
        'categories'    => ['id' => 'categories',    'icon' => 'bi-grid-3x3-gap',    'label' => 'دسته‌بندی‌ها',      'show' => true],
        'reports'       => ['id' => 'reports',       'icon' => 'bi-graph-up-arrow',  'label' => 'گزارش‌ها',          'show' => true],
        'notifications' => ['id' => 'notifications', 'icon' => 'bi-bell',            'label' => 'اعلان‌ها',          'show' => true],
        'settings'      => ['id' => 'settings',      'icon' => 'bi-sliders',         'label' => 'تنظیمات',           'show' => true],
    ];

    switch (normalize_provider_type($providerType)) {
        case 'real_estate':
            $tabs['products']['label'] = 'املاک';
            $tabs['products']['icon'] = 'bi-building';
            $tabs['requests']['label'] = 'پیشنهادهای معاوضه';
            $tabs['orders']['show'] = false;
            $tabs['messages']['label'] = 'مشتریان';
            $tabs['management']['show'] = false;
            $tabs['categories']['show'] = false;
            $tabs['notifications']['show'] = false;
            break;
        case 'car_dealer':
            $tabs['products']['label'] = 'خودروها';
            $tabs['products']['icon'] = 'bi-car-front';
            $tabs['requests']['label'] = 'پیشنهادهای معاوضه';
            $tabs['messages']['label'] = 'مشتریان';
            $tabs['management']['show'] = false;
            $tabs['categories']['show'] = false;
            $tabs['notifications']['show'] = false;
            break;
        case 'corporate':
            break;
        default:
            $tabs['orders']['label'] = 'سفارش‌ها';
            break;
    }

    return array_values(array_filter($tabs, fn($t) => $t['show']));
}

/** @return array<string, string> */
function store_panel_copy(string $providerType): array {
    switch (normalize_provider_type($providerType)) {
        case 'real_estate':
            return [
                'panel_title'       => 'پنل املاک',
                'panel_subtitle'    => 'مدیریت املاک، مشتریان، پیشنهادهای معاوضه و گزارش‌ها',
                'add_item'          => 'افزودن ملک جدید',
                'item_singular'     => 'ملک',
                'items_plural'      => 'املاک',
                'active_items'      => 'املاک فعال',
                'inventory_value'   => 'ارزش کل موجودی',
                'swap_offers'       => 'پیشنهادهای معاوضه',
                'buy_offers'        => 'درخواست‌های خرید',
                'completed_trades'  => 'معاملات',
                'show_buy_offers'   => '0',
            ];
        case 'car_dealer':
            return [
                'panel_title'       => 'پنل نمایشگاه خودرو',
                'panel_subtitle'    => 'مدیریت خودروها، موجودی، مشتریان و پیشنهادهای معاوضه',
                'add_item'          => 'افزودن خودرو جدید',
                'item_singular'     => 'خودرو',
                'items_plural'      => 'خودروها',
                'active_items'      => 'خودروهای موجود',
                'inventory_value'   => 'ارزش کل موجودی',
                'swap_offers'       => 'پیشنهادهای معاوضه',
                'buy_offers'        => 'درخواست‌های خرید',
                'completed_trades'  => 'معاملات',
                'show_buy_offers'   => '0',
            ];
        case 'corporate':
            return [
                'panel_title'       => 'پنل کسب‌وکار',
                'panel_subtitle'    => 'مدیریت محصولات، سفارش‌ها، مشتریان و گزارش‌ها',
                'add_item'          => 'افزودن محصول جدید',
                'item_singular'     => 'محصول',
                'items_plural'      => 'محصولات',
                'active_items'      => 'محصولات',
                'inventory_value'   => 'ارزش کل موجودی',
                'swap_offers'       => 'درخواست‌های معاوضه',
                'buy_offers'        => 'سفارش‌ها',
                'completed_trades'  => 'فروش',
                'show_buy_offers'   => '1',
            ];
        default:
            return [
                'panel_title'       => 'پنل فروشگاه',
                'panel_subtitle'    => 'مدیریت کامل فروشگاه، محصولات، درخواست‌ها و آمار فروش',
                'add_item'          => 'افزودن محصول جدید',
                'item_singular'     => 'محصول',
                'items_plural'      => 'محصولات',
                'active_items'      => 'محصولات',
                'inventory_value'   => 'ارزش کل موجودی',
                'swap_offers'       => 'درخواست‌های معاوضه',
                'buy_offers'        => 'سفارش‌ها',
                'completed_trades'  => 'فروش',
                'show_buy_offers'   => '1',
            ];
    }
}

/** @return array<string, array{label:string,type:string,placeholder?:string}> */
function listing_type_field_defs(string $providerType): array {
    switch (normalize_provider_type($providerType)) {
        case 'real_estate':
            return [
                'area_sqm'     => ['label' => 'متراژ (متر مربع)', 'type' => 'number', 'placeholder' => 'مثلاً ۱۲۰'],
                'rooms'        => ['label' => 'تعداد اتاق', 'type' => 'number', 'placeholder' => 'مثلاً ۳'],
                'parking'      => ['label' => 'پارکینگ', 'type' => 'text', 'placeholder' => 'دارد / ندارد'],
                'floor'        => ['label' => 'طبقه', 'type' => 'text', 'placeholder' => 'مثلاً ۴'],
                'build_year'   => ['label' => 'سال ساخت', 'type' => 'number', 'placeholder' => 'مثلاً ۱۳۹۸'],
            ];
        case 'car_dealer':
            return [
                'brand'        => ['label' => 'برند', 'type' => 'text', 'placeholder' => 'مثلاً تویوتا'],
                'model'        => ['label' => 'مدل', 'type' => 'text', 'placeholder' => 'مثلاً کرولا'],
                'car_year'     => ['label' => 'سال', 'type' => 'number', 'placeholder' => 'مثلاً ۱۴۰۱'],
                'mileage_km'   => ['label' => 'کارکرد (کیلومتر)', 'type' => 'number', 'placeholder' => 'مثلاً ۴۵۰۰۰'],
                'color'        => ['label' => 'رنگ', 'type' => 'text', 'placeholder' => 'مثلاً سفید'],
            ];
        default:
            return [];
    }
}

/** @return array<string, string> */
function listing_attrs_from_input(string $providerType, array $input): array {
    $attrs = [];
    foreach (listing_type_field_defs($providerType) as $key => $def) {
        $val = trim((string)($input['listing_attr_' . $key] ?? ''));
        if ($val !== '') {
            $attrs[$key] = clean($val);
        }
    }
    return $attrs;
}

/** @return array<string, mixed> */
function listing_attrs_decode(?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<string, mixed> */
function listing_attrs_db_payload(array $attrs): array {
    if (!db_has_column('listings', 'listing_attrs')) {
        return [];
    }
    return ['listing_attrs' => empty($attrs) ? null : json_encode($attrs, JSON_UNESCAPED_UNICODE)];
}

function render_listing_type_fields(string $providerType, array $values = [], string $cssPrefix = 'store'): string {
    $defs = listing_type_field_defs($providerType);
    if (empty($defs)) {
        return '';
    }

    $html = '<div class="' . h($cssPrefix) . '-type-fields" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:16px">';
    foreach ($defs as $key => $def) {
        $name = 'listing_attr_' . $key;
        $val = h((string)($values[$key] ?? ''));
        $labelClass = match ($cssPrefix) {
            'wizard' => 'wizard-form-label',
            'form'   => 'form-label',
            default  => $cssPrefix . '-form-label',
        };
        $inputClass = match ($cssPrefix) {
            'wizard' => 'wizard-form-input',
            'form'   => 'form-control',
            default  => $cssPrefix . '-form-input',
        };
        $groupClass = match ($cssPrefix) {
            'wizard' => 'wizard-form-group',
            'form'   => 'form-group',
            default  => $cssPrefix . '-form-group',
        };
        $html .= '<div class="' . h($groupClass) . '">';
        $html .= '<label class="' . h($labelClass) . '">' . h($def['label']) . '</label>';
        $html .= '<input type="' . h($def['type']) . '" class="' . h($inputClass) . '" name="' . h($name) . '"';
        if (!empty($def['placeholder'])) {
            $html .= ' placeholder="' . h($def['placeholder']) . '"';
        }
        $html .= ' value="' . $val . '">';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function render_provider_type_select(string $name, string $selected = 'normal_store', string $inputClass = 'form-control'): string {
    $html = '<select name="' . h($name) . '" class="' . h($inputClass) . '" required>';
    foreach (provider_type_labels() as $value => $label) {
        $html .= '<option value="' . h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function store_request_status_labels(): array {
    return [
        'pending'  => 'در انتظار بررسی',
        'approved' => 'تأیید شده',
        'rejected' => 'رد شده',
    ];
}
  
function store_request_pending_count(): int {
    if (!db_has_table('store_requests')) {
        return 0;
    }
    try {
        return (int)(DB::fetch('SELECT COUNT(*) AS c FROM store_requests WHERE status = "pending"')['c'] ?? 0);
    } catch (Throwable) {
        return 0;
    }
}

function user_has_active_store(array $user): bool {
    if (is_store_seller($user)) {
        return true;
    }
    return trim((string)($user['store_name'] ?? '')) !== '';
}

function user_store_request_block_reason(array $user): ?string {
    if (($user['role'] ?? 'user') === 'admin') {
        return 'حساب ادمین نمی‌تواند درخواست فروشگاه ثبت کند.';
    }
    if (user_has_active_store($user)) {
        return 'شما قبلاً حساب فروشگاهی دارید.';
    }
    if (!db_has_table('store_requests')) {
        return null;
    }
    $pending = DB::fetch(
        'SELECT id FROM store_requests WHERE user_id = ? AND status = "pending" LIMIT 1',
        [(int)$user['id']]
    );
    if ($pending) {
        return 'درخواست قبلی شما در انتظار بررسی است.';
    }
    return null;
}

/** @return array<string, mixed> */
function store_fields_from_input(array $input): array {
    return [
        'store_name'          => trim((string)($input['store_name'] ?? '')),
        'provider_type'       => normalize_provider_type($input['provider_type'] ?? 'normal_store'),
        'store_type'          => normalize_store_type($input['store_type'] ?? 'both'),
        'store_description'   => trim((string)($input['store_description'] ?? '')),
        'store_address'       => trim((string)($input['store_address'] ?? '')),
        'store_phone'         => trim((string)($input['store_phone'] ?? '')),
        'store_website'       => trim((string)($input['store_website'] ?? '')),
        'store_instagram'     => trim((string)($input['store_instagram'] ?? '')),
        'store_telegram'      => trim((string)($input['store_telegram'] ?? '')),
        'store_opening_hours' => trim((string)($input['store_opening_hours'] ?? '')),
        'store_lat'           => trim((string)($input['store_lat'] ?? '')),
        'store_lng'           => trim((string)($input['store_lng'] ?? '')),
    ];
}

function generate_store_slug(string $storeName, int $userId, ?string $currentSlug = null): string {
    $slugBase = trim($storeName) ?: ('store-' . $userId);
    $slug = preg_replace('/[^a-zA-Z0-9_\-آ-ی۰-۹]+/u', '-', $slugBase);
    $slug = trim((string)$slug, '-');
    $slug = mb_strtolower($slug, 'UTF-8');
    if ($slug === '') {
        $slug = 'store-' . $userId;
    }
    $finalSlug = $slug;
    $suffix = 1;
    while (true) {
        $exists = DB::fetch('SELECT id FROM users WHERE store_slug = ? AND id != ?', [$finalSlug, $userId]);
        if (!$exists || $currentSlug === $finalSlug) {
            break;
        }
        $finalSlug = $slug . '-' . (++$suffix);
        if ($suffix > 1000) {
            break;
        }
    }
    return $finalSlug;
}

/**
 * @param array<string, mixed> $fields
 * @return array{ok:bool,error?:string,slug?:string,store_name?:string}
 */
function save_store_profile(int $userId, array $fields, ?array $bannerFile = null, ?array $existingUser = null): array {
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'شناسه کاربر معتبر نیست.'];
    }

    $user = DB::fetch('SELECT id, role FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return ['ok' => false, 'error' => 'کاربر یافت نشد.'];
    }
    if (($user['role'] ?? 'user') === 'admin') {
        return ['ok' => false, 'error' => 'نمی‌توان برای کاربر ادمین فروشگاه تعریف کرد.'];
    }

    $storeName = trim((string)($fields['store_name'] ?? ''));
    if ($storeName === '') {
        return ['ok' => false, 'error' => 'نام فروشگاه الزامی است.'];
    }

    if ($existingUser === null) {
        $existingUser = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]) ?: [];
    }

    $updateData = [];
    $cleanName = clean($storeName);

    if (db_has_column('users', 'seller_type')) {
        $updateData['seller_type'] = 'store';
    }
    if (db_has_column('users', 'provider_type')) {
        $updateData['provider_type'] = normalize_provider_type($fields['provider_type'] ?? 'normal_store');
    }
    if (db_has_column('users', 'store_type')) {
        $updateData['store_type'] = normalize_store_type($fields['store_type'] ?? 'both');
    }
    if (db_has_column('users', 'store_name')) {
        $updateData['store_name'] = $cleanName;
    }
    if (db_has_column('users', 'store_description')) {
        $updateData['store_description'] = clean((string)($fields['store_description'] ?? ''));
    }
    if (db_has_column('users', 'store_address')) {
        $updateData['store_address'] = clean((string)($fields['store_address'] ?? ''));
    }
    if (db_has_column('users', 'store_phone')) {
        $updateData['store_phone'] = clean((string)($fields['store_phone'] ?? ''));
    }
    if (db_has_column('users', 'store_website')) {
        $updateData['store_website'] = clean((string)($fields['store_website'] ?? ''));
    }
    if (db_has_column('users', 'store_instagram')) {
        $updateData['store_instagram'] = clean((string)($fields['store_instagram'] ?? ''));
    }
    if (db_has_column('users', 'store_telegram')) {
        $updateData['store_telegram'] = clean((string)($fields['store_telegram'] ?? ''));
    }
    if (db_has_column('users', 'store_opening_hours')) {
        $updateData['store_opening_hours'] = clean((string)($fields['store_opening_hours'] ?? ''));
    }
    if (db_has_column('users', 'store_lat')) {
        $lat = trim((string)($fields['store_lat'] ?? ''));
        $updateData['store_lat'] = $lat !== '' ? (float)$lat : null;
    }
    if (db_has_column('users', 'store_lng')) {
        $lng = trim((string)($fields['store_lng'] ?? ''));
        $updateData['store_lng'] = $lng !== '' ? (float)$lng : null;
    }

    if (db_has_column('users', 'store_slug')) {
        $currentSlug = (string)($existingUser['store_slug'] ?? '');
        $currentStoreName = (string)($existingUser['store_name'] ?? '');
        if ($currentSlug === '' || $currentStoreName !== $storeName) {
            $updateData['store_slug'] = generate_store_slug($storeName, $userId, $currentSlug !== '' ? $currentSlug : null);
        }
    }

    if ($bannerFile !== null && is_array($bannerFile) && db_has_column('users', 'store_banner')) {
        if (($bannerFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = upload_image($bannerFile, 'store');
            if ($uploaded !== null) {
                $updateData['store_banner'] = $uploaded;
            }
        }
    } elseif (!empty($fields['store_banner']) && db_has_column('users', 'store_banner')) {
        $updateData['store_banner'] = (string)$fields['store_banner'];
    }

    if (!empty($updateData)) {
        DB::update('users', $updateData, 'id = ?', [$userId]);
    }

    $after = DB::fetch('SELECT store_slug, store_name FROM users WHERE id = ?', [$userId]);

    return [
        'ok'         => true,
        'slug'       => (string)($after['store_slug'] ?? ''),
        'store_name' => (string)($after['store_name'] ?? $cleanName),
    ];
}

/**
 * @return array{ok:bool,error?:string,request_id?:int}
 */
function create_store_request(int $userId, array $input, ?array $bannerFile = null): array {
    if (!db_has_table('store_requests')) {
        return ['ok' => false, 'error' => 'سیستم درخواست فروشگاه هنوز آماده نیست.'];
    }

    $user = DB::fetch('SELECT * FROM users WHERE id = ? AND is_active = 1', [$userId]);
    if (!$user) {
        return ['ok' => false, 'error' => 'کاربر یافت نشد.'];
    }

    $block = user_store_request_block_reason($user);
    if ($block !== null) {
        return ['ok' => false, 'error' => $block];
    }

    $fields = store_fields_from_input($input);
    if ($fields['store_name'] === '') {
        return ['ok' => false, 'error' => 'نام فروشگاه الزامی است.'];
    }
    if ($fields['store_phone'] === '') {
        return ['ok' => false, 'error' => 'تلفن فروشگاه الزامی است.'];
    }

    $bannerName = null;
    if ($bannerFile !== null && is_array($bannerFile) && ($bannerFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $bannerName = upload_image($bannerFile, 'store');
    }

    $requestData = [
        'user_id'             => $userId,
        'status'              => 'pending',
        'store_name'          => clean($fields['store_name']),
        'store_description'   => clean($fields['store_description']),
        'store_banner'        => $bannerName,
        'store_address'       => clean($fields['store_address']),
        'store_phone'         => clean($fields['store_phone']),
        'store_website'       => clean($fields['store_website']),
        'store_instagram'     => clean($fields['store_instagram']),
        'store_telegram'      => clean($fields['store_telegram']),
        'store_opening_hours' => clean($fields['store_opening_hours']),
        'store_lat'           => $fields['store_lat'] !== '' ? (float)$fields['store_lat'] : null,
        'store_lng'           => $fields['store_lng'] !== '' ? (float)$fields['store_lng'] : null,
    ];
    if (db_has_column('store_requests', 'provider_type')) {
        $requestData['provider_type'] = $fields['provider_type'];
    }
    if (db_has_column('store_requests', 'store_type')) {
        $requestData['store_type'] = $fields['store_type'];
    }

    $requestId = DB::insert('store_requests', $requestData);

    return ['ok' => true, 'request_id' => (int)$requestId];
}

/**
 * @return array{ok:bool,error?:string,user_id?:int,login?:string,password?:string,store_name?:string,slug?:string}
 */
function approve_store_request(int $requestId, int $adminId): array {
    if (!db_has_table('store_requests')) {
        return ['ok' => false, 'error' => 'جدول درخواست‌ها وجود ندارد.'];
    }

    $req = DB::fetch('SELECT * FROM store_requests WHERE id = ?', [$requestId]);
    if (!$req) {
        return ['ok' => false, 'error' => 'درخواست یافت نشد.'];
    }
    if (($req['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'error' => 'این درخواست قبلاً بررسی شده است.'];
    }

    $userId = (int)$req['user_id'];
    $fields = [
        'store_name'          => $req['store_name'],
        'provider_type'       => $req['provider_type'] ?? 'normal_store',
        'store_type'          => $req['store_type'] ?? 'both',
        'store_description'   => $req['store_description'] ?? '',
        'store_address'       => $req['store_address'] ?? '',
        'store_phone'         => $req['store_phone'] ?? '',
        'store_website'       => $req['store_website'] ?? '',
        'store_instagram'     => $req['store_instagram'] ?? '',
        'store_telegram'      => $req['store_telegram'] ?? '',
        'store_opening_hours' => $req['store_opening_hours'] ?? '',
        'store_lat'           => $req['store_lat'] ?? '',
        'store_lng'           => $req['store_lng'] ?? '',
        'store_banner'        => $req['store_banner'] ?? null,
    ];

    $saved = save_store_profile($userId, $fields, null);
    if (!$saved['ok']) {
        return ['ok' => false, 'error' => $saved['error'] ?? 'خطا در ثبت فروشگاه.'];
    }

    $issued = issue_store_panel_password($userId, $saved['slug'] ?? null);

    DB::update('store_requests', [
        'status'      => 'approved',
        'reviewed_by' => $adminId,
        'reviewed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$requestId]);

    return [
        'ok'         => true,
        'user_id'    => $userId,
        'store_name' => $saved['store_name'] ?? '',
        'slug'       => $saved['slug'] ?? '',
        'login'      => $issued['login'] ?? '',
        'password'   => $issued['password'] ?? '',
    ];
}

function reject_store_request(int $requestId, int $adminId, string $note = ''): bool {
    if (!db_has_table('store_requests')) {
        return false;
    }
    $req = DB::fetch('SELECT id, status FROM store_requests WHERE id = ?', [$requestId]);
    if (!$req || ($req['status'] ?? '') !== 'pending') {
        return false;
    }
    DB::update('store_requests', [
        'status'      => 'rejected',
        'admin_note'  => clean($note) ?: null,
        'reviewed_by' => $adminId,
        'reviewed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$requestId]);
    return true;
}

function normalize_shop_slug(string $slug): string {
    $slug = rawurldecode(trim($slug));
    $slug = preg_replace('/[\x00-\x1F\x7F]+/u', '', $slug) ?? '';
    return trim($slug);
}

/** @return array<int, array{month_key:string,views:int,requests:int}> */
function store_monthly_chart_data(int $userId): array {
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts    = strtotime(date('Y-m-01') . " -$i months");
        $start = date('Y-m-01 00:00:00', $ts);
        $end   = date('Y-m-t 23:59:59', $ts);

        $offers = (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM trade_offers o
             JOIN listings l ON l.id = o.listing_id
             WHERE l.user_id = ? AND o.created_at BETWEEN ? AND ?',
            [$userId, $start, $end]
        )['c'] ?? 0);

        $messages = (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM messages m
             JOIN listings l ON l.user_id = ?
             WHERE m.to_user_id = ? AND m.created_at BETWEEN ? AND ?',
            [$userId, $userId, $start, $end]
        )['c'] ?? 0);

        $months[] = [
            'month_key' => date('Y-m', $ts),
            'views'     => max($offers * 3, $messages),
            'requests'  => $offers,
        ];
    }
    return $months;
}

/** @return array<string, mixed> */
function store_reports_stats(int $userId): array {
    $totals = DB::fetch(
        'SELECT COALESCE(SUM(l.views), 0) AS total_views,
                COUNT(DISTINCT o.id) AS total_offers
         FROM listings l
         LEFT JOIN trade_offers o ON o.listing_id = l.id
         WHERE l.user_id = ?',
        [$userId]
    ) ?: ['total_views' => 0, 'total_offers' => 0];

    $totalViews  = (int)($totals['total_views'] ?? 0);
    $totalOffers = (int)($totals['total_offers'] ?? 0);
    $conversion  = $totalViews > 0 ? round(($totalOffers / $totalViews) * 100, 1) : 0.0;

    $topViewed = DB::fetch(
        'SELECT title, views FROM listings WHERE user_id = ? AND status != "deleted" ORDER BY views DESC LIMIT 1',
        [$userId]
    );

    $topSwap = DB::fetch(
        'SELECT l.title, COUNT(o.id) AS offer_count
         FROM listings l
         JOIN trade_offers o ON o.listing_id = l.id
         WHERE l.user_id = ? AND o.status IN ("pending","accepted")
           AND (o.offer_type IS NULL OR o.offer_type IN ("item","swap","message"))
         GROUP BY l.id
         ORDER BY offer_count DESC
         LIMIT 1',
        [$userId]
    );

    $contacts = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM messages WHERE to_user_id = ?',
        [$userId]
    )['c'] ?? 0);

    $thisMonth = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         WHERE l.user_id = ? AND o.created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")',
        [$userId]
    )['c'] ?? 0);

    $lastMonth = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         WHERE l.user_id = ?
           AND o.created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), "%Y-%m-01")
           AND o.created_at < DATE_FORMAT(NOW(), "%Y-%m-01")',
        [$userId]
    )['c'] ?? 0);

    $growth = 0.0;
    if ($lastMonth > 0) {
        $growth = round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
    } elseif ($thisMonth > 0) {
        $growth = 100.0;
    }

    return [
        'total_views'      => $totalViews,
        'total_offers'     => $totalOffers,
        'conversion_rate'  => $conversion,
        'top_product'      => (string)($topViewed['title'] ?? '—'),
        'top_swap_product' => (string)($topSwap['title'] ?? '—'),
        'contact_count'    => $contacts,
        'growth_percent'   => $growth,
    ];
}

/** @return array<int, array{rank:int,name:string,views:int,offers:int}> */
function store_top_products(int $userId, int $limit = 5): array {
    $rows = DB::fetchAll(
        'SELECT l.title, l.views,
                (SELECT COUNT(*) FROM trade_offers o WHERE o.listing_id = l.id) AS offers
         FROM listings l
         WHERE l.user_id = ? AND l.status != "deleted"
         ORDER BY l.views DESC, offers DESC
         LIMIT ' . (int)$limit,
        [$userId]
    );

    $out = [];
    $rank = 1;
    foreach ($rows as $row) {
        $out[] = [
            'rank'   => $rank++,
            'name'   => (string)$row['title'],
            'views'  => (int)($row['views'] ?? 0),
            'offers' => (int)($row['offers'] ?? 0),
        ];
    }
    return $out;
}

/** @return array<int, array{icon:string,name:string,count:int,color:string}> */
function store_category_breakdown(int $userId): array {
    $colors = ['gold', 'blue', 'navy', 'green', 'purple', 'orange', 'red'];
    $icons  = [
        'طلا' => 'bi-gem', 'جواهر' => 'bi-gem',
        'موبایل' => 'bi-phone', 'گوشی' => 'bi-phone',
        'لپ' => 'bi-laptop', 'کامپیوتر' => 'bi-laptop',
        'دوچرخه' => 'bi-bicycle',
        'دوربین' => 'bi-camera',
        'خودرو' => 'bi-car-front',
        'مبل' => 'bi-house', 'منزل' => 'bi-house',
    ];

    $rows = DB::fetchAll(
        'SELECT c.name, c.icon AS cat_icon, COUNT(l.id) AS cnt
         FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.user_id = ? AND l.status = "active"
         GROUP BY c.id
         ORDER BY cnt DESC
         LIMIT 7',
        [$userId]
    );

    $out = [];
    $i   = 0;
    foreach ($rows as $row) {
        $name = (string)$row['name'];
        $icon = !empty($row['cat_icon']) ? $row['cat_icon'] : 'bi-tag';
        foreach ($icons as $needle => $ic) {
            if (mb_strpos($name, $needle) !== false) {
                $icon = $ic;
                break;
            }
        }
        $out[] = [
            'icon'  => $icon,
            'name'  => $name,
            'count' => (int)$row['cnt'],
            'color' => $colors[$i % count($colors)],
        ];
        $i++;
    }

    $out[] = [
        'icon'  => 'bi-plus-lg',
        'name'  => 'افزودن دسته',
        'count' => null,
        'color' => 'empty',
    ];

    return $out;
}
