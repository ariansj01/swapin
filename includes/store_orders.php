<?php
/** Cash store purchase orders (Digikala-like tracking). */

function store_orders_enabled(): bool {
    return db_has_table('store_orders');
}

function iran_provinces(): array {
    return [
        'آذربایجان شرقی','آذربایجان غربی','اردبیل','اصفهان','البرز','ایلام','بوشهر','تهران','چهارمحال و بختیاری',
        'خراسان جنوبی','خراسان رضوی','خراسان شمالی','خوزستان','زنجان','سمنان','سیستان و بلوچستان','فارس','قزوین',
        'قم','کردستان','کرمان','کرمانشاه','کهگیلویه و بویراحمد','گلستان','گیلان','لرستان','مازندران','مرکزی',
        'هرمزگان','همدان','یزد'
    ];
}

function iran_cities_by_province(string $province): array {
    $map = [
        'تهران' => ['تهران','شهریار','ملارد','اسلام‌شهر','کرج','قائم‌شهر','شهریار','پاکدشت','رباط‌کریم','نسیم‌شهر','بهارستان'],
        'اصفهان' => ['اصفهان','کاشان','نجف‌آباد','شاهین‌شهر','فلاورجان','خمینی‌شهر','مبارکه','بروجن','نایین','تودشک'],
        'فارس' => ['شیراز','مرودشت','سروستان','جهرم','فسا','لار','داراب','کازرون','آباده','فراشبند'],
        'خراسان رضوی' => ['مشهد','نیشابور','سبزوار','تربت‌جام','کاشمر','تایباد','مردهک','قوچان','چناران','گناباد'],
        'خوزستان' => ['اهواز','اهواز','بندرماهشهر','آبادان','کریم‌آباد','بهبهان','شوش','اندیمشک','هویزه','خرمشهر'],
        'گیلان' => ['رشت','بندرانزلی','لاهیجان','چابکسار','آستارا','رودسر','رودبار','مساله','فومن','صومعه‌سرا'],
        'مازندران' => ['ساری','بابل','نوشهر','تنکابن','بندرگز','چالوس','کلاردشت','قائم‌شهر','جویبار','آمل'],
        'البرز' => ['کرج','نظرآباد','محمدشهر','کمال‌شهر','هشتگرد','آبیک','ساوجبلاغ','طالقان'],
        'قم' => ['قم','جعفرآباد','کهک'],
        'سمنان' => ['سمنان','دامغان','شاهرود','گرمسار','مهدیشهر','بسطام'],
        'زنجان' => ['زنجان','خرم‌دره','قیدار','ابهر','ماهو'],
        'گلستان' => ['گرگان','بندرترکمن','گنبدکاووس','علی‌آباد','کلاله','مینودشت'],
        'آذربایجان شرقی' => ['تبریز','مراغه','میانه','مرند','بناب','ملکان','سهند','شبستر'],
        'آذربایجان غربی' => ['ارومیه','خوی','مهاباد','بوکان','سردشت','نقاده','ماکو','میاندوآب'],
        'اردبیل' => ['اردبیل','پارس‌آباد','مغان','کلیبر','نمین','سرعین'],
        'هرمزگان' => ['بندرعباس','قشم','کیش','میناب','یرند','بندرلنگه','جاسک','سیریک'],
        'بوشهر' => ['بوشهر','برازجان','جم','دشتی','دیر','گناوه','کنگان'],
        'کرمان' => ['کرمان','رفسنجان','سیرجان','زرند','کوهبنان','بافت','شهربابک'],
        'یزد' => ['یزد','میبد','اشکذر','مهریز','طبس','اردکان'],
        'لرستان' => ['خرم‌آباد','بروجرد','دلفان','الیگودرز','کوهدشت','نورآباد'],
        'کردستان' => ['سنندج','ساوه‌قزوین'?: 'ساوه','مریوان','بانه','دیواندره','قروه'],
        'کرمانشاه' => ['کرمانشاه','اسلام‌آبادغرب','کنگاور','سرپل‌ذهاب','سنقر','صحنه'],
        'همدان' => ['همدان','ملایر','نهاوند','تویسرکان','کبودراهنگ','رزن'],
        'قزوین' => ['قزوین','الوند','تاکستان','بلداجی','محمدیه','آبیک'],
        'مرکزی' => ['اراک','ساوه','محلات','آشتیان','شازند','خمین'],
        'ایلام' => ['ایلام','دهلران','ایوان','مهران','آبدانان','دره‌شهر'],
        'چهارمحال و بختیاری' => ['شهرکرد','بروجن','سبزکوه','فارسان','کوهرنگ'],
        'کهگیلویه و بویراحمد' => ['یاسوج','دهدشت','لیکک','گچساران'],
        'خراسان شمالی' => ['بجنورد','شیروان','اسفراین','تربت‌حیدریه','گرمه'],
        'خراسان جنوبی' => ['بیرجند','قائنات','طبس‌مسینا','سربیشه','نهبندان'],
        'سیستان و بلوچستان' => ['زاهدان','زابل','چابهار','کنارک','ایرانشهر','سراوان','نیک‌شهر'],
    ];
    return $map[$province] ?? [];
}

function shipping_method_list(): array {
    return [
        ['key' => 'post',      'label' => 'پست پیشتاز',   'eta' => '۲ تا ۳ روز کاری', 'default_cost' => 50000,   'free_threshold' => 5000000],
        ['key' => 'tipax',     'label' => 'تیپاکس',        'eta' => '۳ تا ۵ روز کاری', 'default_cost' => 120000,  'free_threshold' => 10000000],
        ['key' => 'courier',   'label' => 'پیک شهری',     'eta' => '۱ تا ۲ روز کاری', 'default_cost' => 80000,   'free_threshold' => 7000000],
        ['key' => 'in_person', 'label' => 'تحویل حضوری',  'eta' => 'هماهنگ با فروشنده', 'default_cost' => 0,     'free_threshold' => 0],
    ];
}

function calculate_shipping_cost(string $method, float $orderAmount, ?float $sellerLat = null, ?float $sellerLng = null, ?string $province = null): int {
    foreach (shipping_method_list() as $m) {
        if ($m['key'] === $method) {
            $threshold = (float)$m['free_threshold'];
            if ($threshold > 0 && $orderAmount >= $threshold) {
                return 0;
            }
            return (int)$m['default_cost'];
        }
    }
    return 0;
}

function user_addresses(int $userId): array {
    if (!db_has_table('user_addresses')) return [];
    return DB::fetchAll(
        'SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC',
        [$userId]
    );
}

function user_default_address(int $userId): ?array {
    $all = user_addresses($userId);
    if (!$all) return null;
    foreach ($all as $a) { if (!empty($a['is_default'])) return $a; }
    return $all[0] ?? null;
}

function save_user_address(int $userId, array $data, bool $asDefault = false): int {
    if (!db_has_table('user_addresses')) {
        throw new RuntimeException('جدول آدرس‌ها ایجاد نشده است.');
    }
    $recipientName  = clean($data['recipient_name']  ?? '');
    $recipientPhone = clean($data['recipient_phone'] ?? '');
    $province       = clean($data['province']       ?? '');
    $city           = clean($data['city']           ?? '');
    $address        = clean($data['address']        ?? '');
    $postalCode     = clean($data['postal_code']    ?? '');
    $title          = clean($data['title']          ?? '');
    if (mb_strlen($recipientName) < 3) throw new Exception('نام گیرنده کوتاه است.');
    if (!preg_match('/^09\d{9}$/', preg_replace('/\D/','',$recipientPhone))) throw new Exception('شماره موبایل گیرنده معتبر نیست.');
    if (mb_strlen($city) < 2) throw new Exception('شهر را انتخاب کنید.');
    if (mb_strlen($address) < 10) throw new Exception('آدرس کوتاه است.');

    if ($asDefault) {
        DB::query('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?', [$userId]);
    }
    $id = DB::insert('user_addresses', [
        'user_id'         => $userId,
        'title'           => $title ?: null,
        'recipient_name'  => $recipientName,
        'recipient_phone' => $recipientPhone,
        'province'        => $province ?: null,
        'city'            => $city,
        'address'         => $address,
        'postal_code'     => $postalCode ?: null,
        'is_default'      => $asDefault ? 1 : (user_addresses($userId) ? 0 : 1),
    ]);
    return $id;
}

function listing_can_cash_buy(array $listing, ?array $buyer = null): bool {
    if (!store_orders_enabled()) {
        return false;
    }
    if (($listing['status'] ?? '') !== 'active') {
        return false;
    }
    if (($listing['review_status'] ?? 'approved') !== 'approved') {
        return false;
    }
    $mode = $listing['listing_mode'] ?? 'swap';
    if ($mode === 'swap') {
        return false;
    }
    if ((float)($listing['sell_price'] ?? 0) <= 0) {
        return false;
    }
    $seller = DB::fetch('SELECT seller_type FROM users WHERE id = ?', [(int)$listing['user_id']]);
    if (!$seller || ($seller['seller_type'] ?? '') !== 'store') {
        return false;
    }
    if ($buyer && (int)$buyer['id'] === (int)$listing['user_id']) {
        return false;
    }
    $activeOrder = DB::fetch(
        'SELECT id FROM store_orders WHERE listing_id = ? AND status NOT IN ("canceled","delivered") LIMIT 1',
        [(int)$listing['id']]
    );
    return !$activeOrder;
}

function generate_store_order_code(): string {
    do {
        $code = 'SO' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $exists = DB::fetch('SELECT id FROM store_orders WHERE order_code = ? LIMIT 1', [$code]);
    } while ($exists);
    return $code;
}

function store_order_status_label(string $status): string {
    return match ($status) {
        'pending_payment' => 'در انتظار پرداخت',
        'paid'            => 'پرداخت شده',
        'preparing'       => 'در حال آماده‌سازی',
        'shipped'         => 'ارسال شده',
        'delivered'       => 'تحویل شده',
        'canceled'        => 'لغو شده',
        default           => $status,
    };
}

function store_order_timeline(array $order): array {
    $status = $order['status'] ?? 'pending_payment';
    $rank = match ($status) {
        'pending_payment' => 0,
        'paid'            => 1,
        'preparing'       => 2,
        'shipped'         => 3,
        'delivered'       => 4,
        'canceled'        => -1,
        default           => 0,
    };

    $steps = [
        [
            'key'   => 'paid',
            'title' => 'ثبت سفارش و پرداخت',
            'desc'  => 'پرداخت شما با موفقیت ثبت شد.',
            'time'  => $order['paid_at'] ?? null,
            'done'  => $rank >= 1,
            'active'=> $rank === 1,
        ],
        [
            'key'   => 'preparing',
            'title' => 'آماده‌سازی توسط فروشگاه',
            'desc'  => 'فروشگاه در حال آماده‌سازی کالا برای ارسال است.',
            'time'  => ($rank >= 2 && !empty($order['paid_at'])) ? $order['paid_at'] : null,
            'done'  => $rank >= 2,
            'active'=> $rank === 2,
        ],
        [
            'key'   => 'shipped',
            'title' => 'ارسال سفارش',
            'desc'  => !empty($order['shipping_method'])
                ? 'روش ارسال: ' . shipping_label((string)$order['shipping_method'])
                    . (!empty($order['tracking_code']) ? ' — کد رهگیری: ' . $order['tracking_code'] : '')
                : 'کالا توسط فروشگاه ارسال می‌شود.',
            'time'  => $order['shipped_at'] ?? null,
            'done'  => $rank >= 3,
            'active'=> $rank === 3,
        ],
        [
            'key'   => 'delivered',
            'title' => 'تحویل به خریدار',
            'desc'  => 'سفارش با موفقیت تحویل داده شد.',
            'time'  => $order['delivered_at'] ?? null,
            'done'  => $rank >= 4,
            'active'=> $rank === 4,
        ],
    ];

    if ($rank === -1) {
        return [[
            'key'   => 'canceled',
            'title' => 'سفارش لغو شد',
            'desc'  => 'این سفارش لغو شده است.',
            'time'  => $order['updated_at'] ?? null,
            'done'  => true,
            'active'=> true,
        ]];
    }

    return $steps;
}

function fetch_store_order(int $orderId, int $userId): ?array {
    if (!store_orders_enabled()) {
        return null;
    }
    return DB::fetch(
        'SELECT o.*, l.title AS listing_title, l.sell_price,
                (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS listing_thumb,
                ub.name AS buyer_name, ub.phone AS buyer_phone,
                us.name AS seller_name, us.store_name, us.store_slug
         FROM store_orders o
         JOIN listings l ON l.id = o.listing_id
         JOIN users ub ON ub.id = o.buyer_id
         JOIN users us ON us.id = o.seller_id
         WHERE o.id = ? AND (o.buyer_id = ? OR o.seller_id = ?)',
        [$orderId, $userId, $userId]
    ) ?: null;
}

function create_store_order_checkout(int $listingId, int $buyerId, array $shipping): array {
    if (!store_orders_enabled()) {
        return ['error' => 'سیستم سفارش فروشگاه فعال نیست.'];
    }

    $listing = DB::fetch(
        'SELECT l.*, u.seller_type, u.store_name, u.store_lat, u.store_lng
         FROM listings l JOIN users u ON u.id = l.user_id
         WHERE l.id = ?',
        [$listingId]
    );
    if (!$listing) {
        return ['error' => 'محصول یافت نشد.'];
    }

    $buyer = DB::fetch('SELECT * FROM users WHERE id = ?', [$buyerId]);
    if (!$buyer) {
        return ['error' => 'کاربر یافت نشد.'];
    }
    if (!listing_can_cash_buy($listing, $buyer)) {
        return ['error' => 'این محصول برای خرید نقدی در دسترس نیست.'];
    }

    $recipientName   = clean($shipping['recipient_name']   ?? '');
    $recipientPhone  = clean($shipping['recipient_phone']  ?? '');
    $shippingAddress = clean($shipping['shipping_address'] ?? '');
    $shippingProvince= clean($shipping['shipping_province'] ?? $shipping['province'] ?? '');
    $shippingCity    = clean($shipping['shipping_city']    ?? $shipping['city'] ?? '');
    $postalCode      = clean($shipping['postal_code']      ?? '');
    $buyerNote       = clean($shipping['buyer_note']       ?? '');
    $shippingMethod  = clean($shipping['shipping_method']  ?? '');

    if (mb_strlen($recipientName) < 3) {
        return ['error' => 'نام گیرنده را وارد کنید.'];
    }
    if (!preg_match('/^09\d{9}$/', preg_replace('/\D/', '', $recipientPhone))) {
        return ['error' => 'شماره موبایل گیرنده معتبر نیست.'];
    }
    if (mb_strlen($shippingAddress) < 10) {
        return ['error' => 'آدرس کامل ارسال را وارد کنید.'];
    }
    if (mb_strlen($shippingCity) < 2) {
        return ['error' => 'شهر را وارد کنید.'];
    }

    $amount       = (int)round((float)$listing['sell_price']);
    $shippingCost = 0;
    if ($shippingMethod) {
        $shippingCost = calculate_shipping_cost(
            $shippingMethod,
            (float)$amount,
            $listing['store_lat'] ?? null,
            $listing['store_lng'] ?? null,
            $shippingProvince ?: null
        );
    }
    $totalAmount  = $amount + $shippingCost;
    if ($totalAmount <= 0) {
        return ['error' => 'قیمت فروش محصول نامعتبر است.'];
    }

    // Save user address if requested
    if (!empty($shipping['save_address'])) {
        try {
            save_user_address($buyerId, [
                'title'           => clean($shipping['address_title'] ?? ''),
                'recipient_name'  => $recipientName,
                'recipient_phone' => $recipientPhone,
                'province'        => $shippingProvince,
                'city'            => $shippingCity,
                'address'         => $shippingAddress,
                'postal_code'     => $postalCode,
            ], !empty($shipping['set_default_address']));
        } catch (Throwable $e) {
            // non-fatal: just log
            swapin_debug_log('checkout_save_address_failed', ['msg' => $e->getMessage()]);
        }
    }

    $orderCode = generate_store_order_code();
    $orderId = DB::insert('store_orders', [
        'order_code'        => $orderCode,
        'listing_id'        => $listingId,
        'buyer_id'          => $buyerId,
        'seller_id'         => (int)$listing['user_id'],
        'amount'            => $amount,
        'shipping_cost'     => $shippingCost,
        'status'            => 'pending_payment',
        'recipient_name'    => $recipientName,
        'recipient_phone'   => $recipientPhone,
        'shipping_address'  => $shippingAddress,
        'shipping_province' => $shippingProvince ?: null,
        'shipping_city'     => $shippingCity,
        'postal_code'       => $postalCode ?: null,
        'shipping_method'   => $shippingMethod ?: null,
        'buyer_note'        => $buyerNote ?: null,
    ]);

    require_once __DIR__ . '/sep_payment.php';
    $resNum = SEPPayment::generateResNum();
    $meta = json_encode([
        'order_id'      => $orderId,
        'listing_id'    => $listingId,
        'order_code'    => $orderCode,
        'amount'        => $amount,
        'shipping_cost' => $shippingCost,
        'total_amount'  => $totalAmount,
    ], JSON_UNESCAPED_UNICODE);

    $paymentId = DB::insert('payments', [
        'user_id' => $buyerId,
        'type'    => 'store_purchase',
        'amount'  => $totalAmount,
        'res_num' => $resNum,
        'status'  => 'pending',
        'meta'    => $meta,
    ]);
    DB::update('store_orders', ['payment_id' => $paymentId], 'id = ?', [$orderId]);

    $redirectUrl = APP_URL . '/payment_callback';
    $tokenResult = SEPPayment::getToken($totalAmount, $resNum, $redirectUrl, $buyer['phone'] ?? null);
    if (!$tokenResult || empty($tokenResult['token'])) {
        DB::update('store_orders', ['status' => 'canceled'], 'id = ?', [$orderId]);
        return ['error' => 'خطا در اتصال به درگاه پرداخت.'];
    }

    return [
        'order_id'      => $orderId,
        'payment_id'    => $paymentId,
        'token'         => $tokenResult['token'],
        'order_code'    => $orderCode,
        'amount'        => $amount,
        'shipping_cost' => $shippingCost,
        'total_amount'  => $totalAmount,
    ];
}

function finalize_store_order_payment(int $paymentId): void {
    if (!store_orders_enabled()) {
        throw new Exception('جدول سفارش‌ها موجود نیست');
    }
    $payment = DB::fetch('SELECT * FROM payments WHERE id = ? LIMIT 1', [$paymentId]);
    if (!$payment || $payment['type'] !== 'store_purchase') {
        throw new Exception('پرداخت سفارش یافت نشد');
    }
    $meta = json_decode($payment['meta'] ?? '', true) ?: [];
    $orderId = (int)($meta['order_id'] ?? 0);
    if (!$orderId) {
        throw new Exception('شناسه سفارش در پرداخت یافت نشد');
    }

    $order = DB::fetch('SELECT * FROM store_orders WHERE id = ? LIMIT 1 FOR UPDATE', [$orderId]);
    if (!$order) {
        throw new Exception('سفارش یافت نشد');
    }
    if ($order['status'] !== 'pending_payment') {
        return;
    }

    DB::update('store_orders', [
        'status'  => 'preparing',
        'paid_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$orderId]);

    DB::update('listings', ['status' => 'traded'], 'id = ? AND status = "active"', [(int)$order['listing_id']]);
}

function update_store_order_by_seller(int $orderId, int $sellerId, string $action, array $data = []): array {
    $order = DB::fetch('SELECT * FROM store_orders WHERE id = ? AND seller_id = ?', [$orderId, $sellerId]);
    if (!$order) {
        return ['error' => 'سفارش یافت نشد.'];
    }

    if ($action === 'ship') {
        if (!in_array($order['status'], ['paid', 'preparing'], true)) {
            return ['error' => 'این سفارش در وضعیت قابل ارسال نیست.'];
        }
        $method = clean($data['shipping_method'] ?? '');
        $tracking = clean($data['tracking_code'] ?? '');
        $note = clean($data['seller_note'] ?? '');
        if (!in_array($method, ['post', 'tipax', 'courier', 'in_person'], true)) {
            return ['error' => 'روش ارسال را انتخاب کنید.'];
        }
        if ($method !== 'in_person' && mb_strlen($tracking) < 4) {
            return ['error' => 'کد رهگیری را وارد کنید.'];
        }
        DB::update('store_orders', [
            'status'          => 'shipped',
            'shipping_method' => $method,
            'tracking_code'   => $tracking ?: null,
            'seller_note'     => $note ?: null,
            'shipped_at'      => date('Y-m-d H:i:s'),
        ], 'id = ?', [$orderId]);
        return ['success' => true];
    }

    if ($action === 'deliver') {
        if ($order['status'] !== 'shipped') {
            return ['error' => 'ابتدا سفارش باید ارسال شود.'];
        }
        DB::update('store_orders', [
            'status'       => 'delivered',
            'delivered_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$orderId]);
        return ['success' => true];
    }

    if ($action === 'preparing') {
        if (!in_array($order['status'], ['paid', 'preparing'], true)) {
            return ['error' => 'وضعیت سفارش قابل تغییر نیست.'];
        }
        DB::update('store_orders', ['status' => 'preparing'], 'id = ?', [$orderId]);
        return ['success' => true];
    }

    return ['error' => 'عملیات نامعتبر است.'];
}

function buyer_mark_order_delivered(int $orderId, int $buyerId): array {
    $order = DB::fetch('SELECT * FROM store_orders WHERE id = ? AND buyer_id = ?', [$orderId, $buyerId]);
    if (!$order) {
        return ['error' => 'سفارش یافت نشد.'];
    }
    if ($order['status'] !== 'shipped') {
        return ['error' => 'هنوز سفارش ارسال نشده است.'];
    }
    DB::update('store_orders', [
        'status'       => 'delivered',
        'delivered_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$orderId]);
    return ['success' => true];
}
