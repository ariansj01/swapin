<?php
/** Cash store purchase orders (Digikala-like tracking). */

function store_orders_enabled(): bool {
    return db_has_table('store_orders');
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
        'SELECT l.*, u.seller_type, u.store_name
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

    $recipientName = clean($shipping['recipient_name'] ?? '');
    $recipientPhone = clean($shipping['recipient_phone'] ?? '');
    $shippingAddress = clean($shipping['shipping_address'] ?? '');
    $shippingCity = clean($shipping['shipping_city'] ?? '');
    $postalCode = clean($shipping['postal_code'] ?? '');
    $buyerNote = clean($shipping['buyer_note'] ?? '');

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

    $amount = (int)round((float)$listing['sell_price']);
    if ($amount <= 0) {
        return ['error' => 'قیمت فروش محصول نامعتبر است.'];
    }

    $orderCode = generate_store_order_code();
    $orderId = DB::insert('store_orders', [
        'order_code'       => $orderCode,
        'listing_id'       => $listingId,
        'buyer_id'         => $buyerId,
        'seller_id'        => (int)$listing['user_id'],
        'amount'           => $amount,
        'status'           => 'pending_payment',
        'recipient_name'   => $recipientName,
        'recipient_phone'  => $recipientPhone,
        'shipping_address' => $shippingAddress,
        'shipping_city'    => $shippingCity,
        'postal_code'      => $postalCode ?: null,
        'buyer_note'       => $buyerNote ?: null,
    ]);

    require_once __DIR__ . '/sep_payment.php';
    $resNum = SEPPayment::generateResNum();
    $meta = json_encode([
        'order_id'   => $orderId,
        'listing_id' => $listingId,
        'order_code' => $orderCode,
    ], JSON_UNESCAPED_UNICODE);

    $paymentId = DB::insert('payments', [
        'user_id' => $buyerId,
        'type'    => 'store_purchase',
        'amount'  => $amount,
        'res_num' => $resNum,
        'status'  => 'pending',
        'meta'    => $meta,
    ]);
    DB::update('store_orders', ['payment_id' => $paymentId], 'id = ?', [$orderId]);

    $redirectUrl = APP_URL . '/payment_callback';
    $tokenResult = SEPPayment::getToken($amount, $resNum, $redirectUrl, $buyer['phone'] ?? null);
    if (!$tokenResult || empty($tokenResult['token'])) {
        DB::update('store_orders', ['status' => 'canceled'], 'id = ?', [$orderId]);
        return ['error' => 'خطا در اتصال به درگاه پرداخت.'];
    }

    return [
        'order_id'   => $orderId,
        'payment_id' => $paymentId,
        'token'      => $tokenResult['token'],
        'order_code' => $orderCode,
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
