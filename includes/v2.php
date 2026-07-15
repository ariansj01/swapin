<?php
// Business Plan v2 — feature helpers (requires migration_v2.sql)

const BUMP_PRICE_KBC    = ['bump' => 50, 'feature' => 150];
const BUMP_DURATION_H   = ['bump' => 24, 'feature' => 72];
const INSPECTION_KBC    = 300;
const FREE_LISTING_MAX  = 5;

function validate_national_id(string $nid): bool {
    if (!preg_match('/^\d{10}$/', $nid)) return false;
    $check = (int)$nid[9];
    $sum   = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nid[$i] * (10 - $i);
    }
    $rem = $sum % 11;
    return ($rem < 2 && $check === $rem) || ($rem >= 2 && $check === 11 - $rem);
}

function submit_kyc(int $userId, array $data): array {
    $errors = [];
    $nid    = clean($data['national_id'] ?? '');
    $bank   = clean($data['bank_account'] ?? '');
    $type   = clean($data['seller_type'] ?? 'personal');
    $store  = clean($data['store_name'] ?? '');

    if (!$nid || !validate_national_id($nid)) {
        $errors['national_id'] = 'کد ملی معتبر ۱۰ رقمی وارد کنید';
    }
    if (!$bank || !preg_match('/^IR?\d{24}$|^(\d{10,30})$/', str_replace([' ', '-'], '', $bank))) {
        $errors['bank_account'] = 'شماره شبا یا حساب بانکی معتبر وارد کنید';
    }
    if (!in_array($type, ['personal', 'store'], true)) {
        $errors['seller_type'] = 'نوع فروشنده نامعتبر است';
    }
    if ($type === 'store' && mb_strlen($store) < 2) {
        $errors['store_name'] = 'نام فروشگاه الزامی است';
    }
    if (empty($data['id_card_image'])) {
        $errors['id_card_image'] = 'تصویر کارت ملی الزامی است';
    }

    if (empty($errors)) {
        DB::update('users', [
            'national_id'   => $nid,
            'bank_account'  => str_replace([' ', '-'], '', $bank),
            'id_card_image' => $data['id_card_image'],
            'seller_type'   => $type,
            'store_name'    => $type === 'store' ? $store : null,
            'kyc_status'    => 'pending',
        ], 'id = ?', [$userId]);
    }
    return $errors;
}

function user_kyc_approved(array $user): bool {
    return ($user['kyc_status'] ?? 'none') === 'approved';
}

function listing_is_featured(array $l): bool {
    return !empty($l['featured_until']) && strtotime($l['featured_until']) > time();
}

function listing_is_bumped(array $l): bool {
    return !empty($l['bump_until']) && strtotime($l['bump_until']) > time();
}

function listing_promotion_badges_html(array $l): string {
    $html = '';
    if (listing_is_featured($l)) {
        $html .= '<span class="listing-promo-badge listing-promo-badge--featured"><i class="bi bi-star-fill"></i> آگهی ویژه</span>';
    }
    if (listing_is_bumped($l)) {
        $html .= '<span class="listing-promo-badge listing-promo-badge--bumped"><i class="bi bi-arrow-up-circle-fill"></i> بالا برده</span>';
    }
    return $html;
}

function get_active_subscription(array $user): ?array {
    $plan = $user['subscription_plan'] ?? 'none';
    if ($plan === 'none') return null;
    if (!empty($user['subscription_until']) && strtotime($user['subscription_until']) < time()) {
        return null;
    }
    return DB::fetch('SELECT * FROM subscription_plans WHERE slug = ?', [$plan]);
}

function is_store_seller(array $user): bool {
    return ($user['seller_type'] ?? 'personal') === 'store';
}

function get_listing_limit(array $user): int {
    $sub  = get_active_subscription($user);
    $base = $sub ? (int)$sub['listings_max'] : FREE_LISTING_MAX;
    return is_store_seller($user) ? $base + STORE_LISTING_BONUS : $base;
}

function can_create_listing(array $user): bool {
    return can_create_listing_count($user) < get_listing_limit($user);
}

function can_create_listing_count(array $user): int {
    return (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = "active"',
        [$user['id']]
    )['c'] ?? 0);
}

function escrow_hold(int $tradeId, int $userId, float $amount, string $note = ''): void {
    if ($amount <= 0) return;
    $trade = DB::fetch('SELECT listing_a_id, listing_b_id, user_a_id, user_b_id FROM trades WHERE id = ?', [$tradeId]);
    credit_transact($userId, 'trade_debit', -$amount, $note ?: "نگهداری امانی معامله #$tradeId", [
        'ref_type'   => 'trade',
        'ref_id'     => $tradeId,
        'trade_id'   => $tradeId,
        'listing_id' => $trade ? wallet_listing_for_trade_user($trade, $userId) : null,
    ]);
    DB::insert('escrow_transactions', [
        'trade_id' => $tradeId,
        'user_id'  => $userId,
        'amount'   => $amount,
        'type'     => 'hold',
        'note'     => $note ?: 'مبلغ در حساب امانی نگهداری شد',
    ]);
    DB::update('trades', [
        'escrow_status' => 'held',
        'escrow_amount' => $amount,
    ], 'id = ?', [$tradeId]);
}

function escrow_release(int $tradeId): void {
    $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
    if (!$trade || $trade['escrow_status'] !== 'held') return;

    $amount = (float)$trade['escrow_amount'];
    if ($amount > 0) {
        credit_transact((int)$trade['user_a_id'], 'trade_credit', $amount,
            "آزادسازی امانی معامله #$tradeId", [
                'ref_type'   => 'trade',
                'ref_id'     => $tradeId,
                'trade_id'   => $tradeId,
                'listing_id' => wallet_listing_for_trade_user($trade, (int)$trade['user_a_id']),
            ]);
        DB::insert('escrow_transactions', [
            'trade_id' => $tradeId,
            'user_id'  => $trade['user_a_id'],
            'amount'   => $amount,
            'type'     => 'release',
            'note'     => 'آزادسازی پس از تکمیل معامله',
        ]);
    }
    DB::update('trades', ['escrow_status' => 'released'], 'id = ?', [$tradeId]);
}

function escrow_refund(int $tradeId): void {
    $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
    if (!$trade || $trade['escrow_status'] !== 'held') return;

    $amount = (float)$trade['escrow_amount'];
    if ($amount > 0) {
        credit_transact((int)$trade['user_b_id'], 'trade_credit', $amount,
            "بازگشت امانی معامله #$tradeId", [
                'ref_type'   => 'trade',
                'ref_id'     => $tradeId,
                'trade_id'   => $tradeId,
                'listing_id' => wallet_listing_for_trade_user($trade, (int)$trade['user_b_id']),
            ]);
        DB::insert('escrow_transactions', [
            'trade_id' => $tradeId,
            'user_id'  => $trade['user_b_id'],
            'amount'   => $amount,
            'type'     => 'refund',
            'note'     => 'بازگشت وجه به‌دلیل اختلاف/لغو',
        ]);
    }
    DB::update('trades', ['escrow_status' => 'refunded'], 'id = ?', [$tradeId]);
}

function create_trade_contract(int $tradeId): int {
    $existing = DB::fetch('SELECT id FROM trade_contracts WHERE trade_id = ?', [$tradeId]);
    if ($existing) return (int)$existing['id'];

    $trade = DB::fetch(
        'SELECT t.*, la.title AS la_title, lb.title AS lb_title
         FROM trades t
         JOIN listings la ON la.id = t.listing_a_id
         LEFT JOIN listings lb ON lb.id = t.listing_b_id
         WHERE t.id = ?',
        [$tradeId]
    );
    if (!$trade) return 0;

    $terms = "قرارداد دیجیتال معامله #{$tradeId}\n";
    $terms .= "طرف اول: {$trade['la_title']}\n";
    if ($trade['lb_title']) $terms .= "طرف دوم: {$trade['lb_title']}\n";
    if ((float)$trade['credit_diff'] > 0) {
        $terms .= "مابه‌التفاوت اعتبار: " . fmt_credit((float)$trade['credit_diff']) . "\n";
    }
    $terms .= "هر دو طرف متعهد به تحویل کالا/خدمت مطابق توضیحات و تأیید دریافت در پلتفرم هستند.";

    return DB::insert('trade_contracts', [
        'trade_id'        => $tradeId,
        'user_a_id'       => $trade['user_a_id'],
        'user_b_id'       => $trade['user_b_id'],
        'listing_a_title' => $trade['la_title'],
        'listing_b_title' => $trade['lb_title'],
        'diff_amount'     => $trade['credit_diff'] ?? 0,
        'bnpl_months'     => $trade['bnpl_months'] ?? 0,
        'terms'           => $terms,
    ]);
}

function sign_trade_contract(int $tradeId, int $userId): bool {
    $contract = DB::fetch('SELECT * FROM trade_contracts WHERE trade_id = ?', [$tradeId]);
    if (!$contract) return false;

    if ((int)$userId === (int)$contract['user_a_id']) {
        DB::update('trade_contracts', [
            'user_a_signed'    => 1,
            'user_a_signed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$contract['id']]);
    } elseif ((int)$userId === (int)$contract['user_b_id']) {
        DB::update('trade_contracts', [
            'user_b_signed'    => 1,
            'user_b_signed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$contract['id']]);
    } else {
        return false;
    }

    $updated = DB::fetch('SELECT * FROM trade_contracts WHERE id = ?', [$contract['id']]);
    if ($updated['user_a_signed'] && $updated['user_b_signed']) {
        DB::update('trades', ['contract_signed_at' => date('Y-m-d H:i:s')], 'id = ?', [$tradeId]);
    }
    return true;
}

function contract_fully_signed(int $tradeId): bool {
    $c = DB::fetch('SELECT user_a_signed, user_b_signed FROM trade_contracts WHERE trade_id = ?', [$tradeId]);
    return $c && $c['user_a_signed'] && $c['user_b_signed'];
}

function get_trade_contract(int $tradeId): ?array {
    return DB::fetch('SELECT * FROM trade_contracts WHERE trade_id = ?', [$tradeId]);
}

function accept_trade_offer(int $offerId, int $ownerId, string $message): array {
    $offer = DB::fetch(
        'SELECT o.*, l.user_id AS listing_owner, l.title AS listing_title, l.estimated_value AS listing_a_value,
                ol.estimated_value AS listing_b_value
         FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         LEFT JOIN listings ol ON ol.id = o.offer_listing_id
         WHERE o.id = ? AND l.user_id = ? AND o.status = "pending"',
        [$offerId, $ownerId]
    );
    if (!$offer) {
        return ['error' => 'پیشنهاد یافت نشد یا دسترسی ندارید.'];
    }

    $existing = DB::fetch('SELECT id FROM trades WHERE offer_id = ?', [$offerId]);
    if ($existing) {
        return ['trade_id' => (int)$existing['id']];
    }

    $valueA     = (float)($offer['listing_a_value'] ?? 0);
    $valueB     = (float)($offer['listing_b_value'] ?? 0);
    $creditDiff = $valueA - ($valueB + (float)$offer['offer_credit']);

    DB::query('UPDATE trade_offers SET status = "accepted" WHERE id = ?', [$offerId]);

    $tradeId = DB::insert('trades', [
        'offer_id'     => $offerId,
        'user_a_id'    => $ownerId,
        'user_b_id'    => $offer['from_user_id'],
        'listing_a_id' => $offer['listing_id'],
        'listing_b_id' => $offer['offer_listing_id'] ?: null,
        'credit_diff'  => $creditDiff,
        'status'       => 'in_progress',
        'step'         => 1,
        'fee_paid'     => 0,
    ]);

    if ($message !== '') {
        DB::insert('secure_room_messages', [
            'trade_id' => $tradeId,
            'user_id'  => $ownerId,
            'type'     => 'text',
            'body'     => $message,
        ]);
        DB::insert('messages', [
            'thread_id'    => 'trade_' . $tradeId,
            'from_user_id' => $ownerId,
            'to_user_id'   => $offer['from_user_id'],
            'offer_id'     => $offerId,
            'body'         => $message,
        ]);
    }

    return ['trade_id' => $tradeId];
}

function trade_user_fee_amount(array $trade, bool $isUserA): float {
    $value = $isUserA
        ? (float)($trade['listing_a_val'] ?? $trade['listing_a_value'] ?? 0)
        : (float)($trade['listing_b_val'] ?? $trade['listing_b_value'] ?? 0);
    return $value * PLATFORM_FEE_RATE;
}

function trade_user_fee_paid(array $trade, bool $isUserA): bool {
    $col = $isUserA ? 'user_a_fee_paid' : 'user_b_fee_paid';
    if (!empty($trade[$col])) {
        return true;
    }
    // معاملات قدیمی: قبل از ستون‌های جداگانه فقط fee_paid داشتند
    return !empty($trade['fee_paid'])
        && empty($trade['user_a_fee_paid'])
        && empty($trade['user_b_fee_paid']);
}

function trade_fees_fully_paid(array $trade): bool {
    return trade_user_fee_paid($trade, true) && trade_user_fee_paid($trade, false);
}

function trade_shipping_fully_scheduled(array $trade): bool {
    $aReady = !empty($trade['user_a_shipping_date'])
        && !empty($trade['user_a_shipping_time']);
    $bReady = !empty($trade['user_b_shipping_date'])
        && !empty($trade['user_b_shipping_time']);
    $shippingSelected = !empty($trade['selected_shipping_method']);
    return $aReady && $bReady && $shippingSelected;
}

function maybe_advance_trade_after_fees(int $tradeId): void {
    $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
    if (!$trade || !trade_fees_fully_paid($trade)) {
        return;
    }

    if (empty($trade['fee_paid'])) {
        DB::query('UPDATE trades SET fee_paid = 1, step = 2 WHERE id = ?', [$tradeId]);
    }

    if (!empty($trade['diff_paid'])) {
        create_trade_contract($tradeId);
        return;
    }

    $creditDiff = (float)($trade['credit_diff'] ?? 0);
    if ($creditDiff > 0) {
        $userToPayId = (int)$trade['user_b_id'];
        $amountToPay = $creditDiff;
    } elseif ($creditDiff < 0) {
        $userToPayId = (int)$trade['user_a_id'];
        $amountToPay = abs($creditDiff);
    } else {
        $userToPayId = 0;
        $amountToPay = 0;
    }

    if ($userToPayId && $amountToPay > 0) {
        $payerUser = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userToPayId]);
        if ((float)($payerUser['credit_balance'] ?? 0) >= $amountToPay) {
            escrow_hold($tradeId, $userToPayId, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
            DB::query('UPDATE trades SET diff_paid = 1, step = 3 WHERE id = ?', [$tradeId]);
        }
    } else {
        DB::query('UPDATE trades SET diff_paid = 1, step = 3 WHERE id = ?', [$tradeId]);
    }

    create_trade_contract($tradeId);
}

function pay_trade_user_fee(int $tradeId, int $userId): array {
    $trade = DB::fetch(
        'SELECT t.*, la.estimated_value AS listing_a_value, lb.estimated_value AS listing_b_value
         FROM trades t
         JOIN listings la ON la.id = t.listing_a_id
         LEFT JOIN listings lb ON lb.id = t.listing_b_id
         WHERE t.id = ?',
        [$tradeId]
    );
    if (!$trade) {
        return ['error' => 'معامله یافت نشد.'];
    }

    $isUserA = (int)$trade['user_a_id'] === $userId;
    $isUserB = (int)$trade['user_b_id'] === $userId;
    if (!$isUserA && !$isUserB) {
        return ['error' => 'دسترسی به این معامله ندارید.'];
    }

    if (trade_user_fee_paid($trade, $isUserA)) {
        return ['ok' => true, 'already_paid' => true];
    }

    $fee = trade_user_fee_amount($trade, $isUserA);

    if ($fee <= 0) {
        if ($isUserA) {
            DB::query('UPDATE trades SET user_a_fee_paid = 1 WHERE id = ?', [$tradeId]);
        } else {
            DB::query('UPDATE trades SET user_b_fee_paid = 1 WHERE id = ?', [$tradeId]);
        }
        maybe_advance_trade_after_fees($tradeId);
        return ['ok' => true];
    }

    $payer = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userId]);
    $balance = (float)($payer['credit_balance'] ?? 0);

    if ($balance < $fee) {
        return [
            'error'           => 'موجودی کیف پول شما برای پرداخت کارمزد کافی نیست. لطفاً ' . fmt_credit($fee - $balance) . ' به کیف پول خود اضافه کنید.',
            'user_id'         => $userId,
            'required_amount' => $fee - $balance,
        ];
    }

    credit_transact($userId, 'fee', -$fee, 'کارمزد پلتفرم برای معامله #' . $tradeId, [
        'ref_type' => 'trade',
        'ref_id'   => $tradeId,
        'trade_id' => $tradeId,
    ]);

    if ($isUserA) {
        DB::query('UPDATE trades SET user_a_fee_paid = 1 WHERE id = ?', [$tradeId]);
    } else {
        DB::query('UPDATE trades SET user_b_fee_paid = 1 WHERE id = ?', [$tradeId]);
    }

    maybe_advance_trade_after_fees($tradeId);

    return ['ok' => true];
}

/** @deprecated Use pay_trade_user_fee() — kept for backward compatibility */
function pay_trade_platform_fee(int $tradeId): array {
    $trade = DB::fetch('SELECT user_a_id, user_b_id FROM trades WHERE id = ?', [$tradeId]);
    if (!$trade) {
        return ['error' => 'معامله یافت نشد.'];
    }
    $resultA = pay_trade_user_fee($tradeId, (int)$trade['user_a_id']);
    if (isset($resultA['error'])) {
        return $resultA;
    }
    return pay_trade_user_fee($tradeId, (int)$trade['user_b_id']);
}

function request_bnpl(int $tradeId, int $userId, float $amount, int $months = 3): array {
    $months = max(3, min(12, $months));
    if ($amount <= 0) return ['error' => 'مبلغ BNPL باید بیشتر از صفر باشد'];

    $existing = DB::fetch(
        'SELECT id FROM bnpl_requests WHERE trade_id = ? AND user_id = ? AND status IN ("pending","approved","active")',
        [$tradeId, $userId]
    );
    if ($existing) return ['error' => 'درخواست BNPL قبلاً ثبت شده'];

    $monthly = (int)ceil($amount / $months * 1.05);
    $id = DB::insert('bnpl_requests', [
        'trade_id'    => $tradeId,
        'user_id'     => $userId,
        'amount'      => $amount,
        'months'      => $months,
        'monthly_fee' => $monthly,
        'status'      => 'pending',
    ]);
    return ['success' => true, 'id' => $id, 'monthly_fee' => $monthly];
}

function approve_bnpl(int $requestId): void {
    $req = DB::fetch('SELECT * FROM bnpl_requests WHERE id = ?', [$requestId]);
    if (!$req || $req['status'] !== 'pending') return;

    DB::update('bnpl_requests', [
        'status'      => 'active',
        'approved_at' => date('Y-m-d H:i:s'),
        'lendtech_ref'=> 'LT-' . strtoupper(substr(md5((string)$requestId), 0, 8)),
    ], 'id = ?', [$requestId]);

    DB::update('trades', [
        'bnpl_active' => 1,
        'bnpl_months' => $req['months'],
    ], 'id = ?', [$req['trade_id']]);
}

function subscribe_to_plan(int $userId, string $planSlug, int $months = 1): array {
    $plan = DB::fetch('SELECT * FROM subscription_plans WHERE slug = ?', [$planSlug]);
    if (!$plan) return ['error' => 'پلن نامعتبر'];

    $months = max(1, min(12, $months));
    $user   = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    $cost   = (float)$plan['price_month'] * $months;

    if ((float)$user['credit_balance'] < $cost) {
        return ['error' => 'موجودی ' . CREDIT_UNIT . ' کافی نیست — نیاز: ' . fmt_credit($cost)];
    }

    $starts = date('Y-m-d H:i:s');
    $ends   = date('Y-m-d H:i:s', strtotime("+{$months} months"));

    $orderId = DB::insert('subscription_orders', [
        'user_id'     => $userId,
        'plan'        => $planSlug,
        'months'      => $months,
        'amount_paid' => $cost,
        'starts_at'   => $starts,
        'ends_at'     => $ends,
        'status'      => 'active',
    ]);

    credit_transact($userId, 'fee', -$cost, "اشتراک {$plan['name']} ({$months} ماه)", [
        'ref_type' => 'subscription_order',
        'ref_id'   => $orderId,
    ]);

    DB::update('users', [
        'subscription_plan'  => $planSlug,
        'subscription_until' => $ends,
    ], 'id = ?', [$userId]);

    return ['success' => true, 'ends_at' => $ends, 'plan' => $plan['name']];
}

function promote_listing(int $listingId, int $userId, string $type): array {
    if (!isset(BUMP_PRICE_KBC[$type])) return ['error' => 'نوع ارتقا نامعتبر'];

    $listing = DB::fetch(
        'SELECT * FROM listings WHERE id = ? AND user_id = ? AND status = "active"',
        [$listingId, $userId]
    );
    if (!$listing) return ['error' => 'آگهی یافت نشد'];

    $price = BUMP_PRICE_KBC[$type];
    $hours = BUMP_DURATION_H[$type];
    $user  = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);

    if ($price > 0 && (float)$user['credit_balance'] < $price) {
        return ['error' => 'موجودی ' . CREDIT_UNIT . ' کافی نیست — نیاز: ' . fmt_credit($price)];
    }

    $ends = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    $bumpId = DB::insert('listing_bumps', [
        'listing_id'  => $listingId,
        'user_id'     => $userId,
        'type'        => $type,
        'duration_h'  => $hours,
        'amount_paid' => $price,
        'starts_at'   => date('Y-m-d H:i:s'),
        'ends_at'     => $ends,
    ]);

    if ($price > 0) {
        credit_transact($userId, 'fee', -$price, ucfirst($type) . " listing #$listingId", [
            'ref_type'   => 'listing_bump',
            'ref_id'     => $bumpId,
            'listing_id' => $listingId,
        ]);
    }

    DB::update('listings',
        $type === 'feature' ? ['featured_until' => $ends] : ['bump_until' => $ends],
        'id = ?',
        [$listingId]
    );

    return ['success' => true, 'ends_at' => $ends, 'type' => $type];
}

function request_expert_inspection(int $listingId, int $userId, ?int $tradeId = null, string $type = 'self_request'): array {
    $listing = DB::fetch('SELECT * FROM listings WHERE id = ?', [$listingId]);
    if (!$listing) return ['error' => 'آگهی یافت نشد'];

    $user = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userId]);
    if ((float)$user['credit_balance'] < INSPECTION_KBC) {
        return ['error' => 'موجودی ' . CREDIT_UNIT . ' کافی نیست — هزینه بازرسی: ' . fmt_credit(INSPECTION_KBC)];
    }

    $id = DB::insert('inspection_requests', [
        'listing_id' => $listingId,
        'user_id'    => $userId,
        'trade_id'   => $tradeId,
        'type'       => $type,
        'status'     => 'pending',
        'price'      => INSPECTION_KBC * 1000,
    ]);

    credit_transact($userId, 'fee', -INSPECTION_KBC, "بازرسی کارشناس آگهی #$listingId", [
        'ref_type'   => 'inspection_request',
        'ref_id'     => $id,
        'listing_id' => $listingId,
    ]);

    DB::update('listings', [
        'needs_inspection'   => 1,
        'inspection_status'  => 'requested',
    ], 'id = ?', [$listingId]);

    return ['success' => true, 'id' => $id];
}

function listing_mode_label(string $mode): string {
    return match ($mode) {
        'sell' => 'فروش',
        'both' => 'تعویض + فروش',
        default => 'تعویض',
    };
}

function kyc_status_label(string $status): string {
    return match ($status) {
        'pending'  => 'در انتظار بررسی',
        'approved' => 'تأیید شده',
        'rejected' => 'رد شده',
        default    => 'ثبت نشده',
    };
}

// ─── Platform fee & trade completion ─────────────────────────────────────

function complete_trade(int $tradeId): array {
    $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
    if (!$trade) return ['error' => 'معامله یافت نشد'];

    DB::query('UPDATE trades SET status = "completed", completed_at = NOW() WHERE id = ?', [$tradeId]);

    $fee = 0.0;
    // Check for sufficient funds for fees before proceeding
    if ($trade['escrow_status'] === 'held') {
        $listingA = DB::fetch('SELECT estimated_value FROM listings WHERE id = ?', [$trade['listing_a_id']]);
        $listingB = $trade['listing_b_id'] ? DB::fetch('SELECT estimated_value FROM listings WHERE id = ?', [$trade['listing_b_id']]) : null;
        
        $valueA = (float)($listingA['estimated_value'] ?? 0);
        $valueB = (float)($listingB['estimated_value'] ?? 0);
        
        $feeA = $valueA > 0 ? max(1, (int)round($valueA * PLATFORM_FEE_RATE)) : 0;
        $feeB = $valueB > 0 ? max(1, (int)round($valueB * PLATFORM_FEE_RATE)) : 0;

        $userA = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [(int)$trade['user_a_id']]);
        $userB = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [(int)$trade['user_b_id']]);

        if ($feeA > 0 && (float)($userA['credit_balance'] ?? 0) < $feeA) {
            return ['error' => 'موجودی شما برای پرداخت کارمزد پلتفرم کافی نیست. نیاز به ' . fmt_credit($feeA - (float)($userA['credit_balance'] ?? 0)) . ' دارید.', 'required_amount' => $feeA - (float)($userA['credit_balance'] ?? 0), 'user_id' => (int)$trade['user_a_id']];
        }
        if ($feeB > 0 && (float)($userB['credit_balance'] ?? 0) < $feeB) {
            return ['error' => 'موجودی طرف مقابل برای پرداخت کارمزد پلتفرم کافی نیست. نیاز به ' . fmt_credit($feeB - (float)($userB['credit_balance'] ?? 0)) . ' دارید.', 'required_amount' => $feeB - (float)($userB['credit_balance'] ?? 0), 'user_id' => (int)$trade['user_b_id']];
        }

        $fee = escrow_release_with_fee($tradeId);
    }

    credit_transact((int)$trade['user_a_id'], 'trade_credit', 10, 'پاداش تکمیل معامله #' . $tradeId, [
        'ref_type'   => 'trade',
        'ref_id'     => $tradeId,
        'trade_id'   => $tradeId,
        'listing_id' => wallet_listing_for_trade_user($trade, (int)$trade['user_a_id']),
    ]);
    credit_transact((int)$trade['user_b_id'], 'trade_credit', 10, 'پاداش تکمیل معامله #' . $tradeId, [
        'ref_type'   => 'trade',
        'ref_id'     => $tradeId,
        'trade_id'   => $tradeId,
        'listing_id' => wallet_listing_for_trade_user($trade, (int)$trade['user_b_id']),
    ]);

    return ['success' => true, 'fee' => $fee];
}

function escrow_release_with_fee(int $tradeId): float {
    $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
    if (!$trade) return 0.0;

    // Get both listings' estimated values
    $listingA = DB::fetch('SELECT estimated_value FROM listings WHERE id = ?', [$trade['listing_a_id']]);
    $listingB = $trade['listing_b_id'] ? DB::fetch('SELECT estimated_value FROM listings WHERE id = ?', [$trade['listing_b_id']]) : null;
    
    $valueA = (float)($listingA['estimated_value'] ?? 0);
    $valueB = (float)($listingB['estimated_value'] ?? 0);
    
    // Calculate 1% fee from each user
    $feeA = $valueA > 0 ? max(1, (int)round($valueA * PLATFORM_FEE_RATE)) : 0;
    $feeB = $valueB > 0 ? max(1, (int)round($valueB * PLATFORM_FEE_RATE)) : 0;
    $totalFee = $feeA + $feeB;

    // Charge fees from both users
    if ($feeA > 0) {
        credit_transact(
            (int)$trade['user_a_id'],
            'fee',
            -$feeA,
            'کارمزد پلتفرم ' . (int)(PLATFORM_FEE_RATE * 100) . '٪ — معامله #' . $tradeId,
            [
                'ref_type'   => 'trade',
                'ref_id'     => $tradeId,
                'trade_id'   => $tradeId,
                'listing_id' => $trade['listing_a_id'],
            ]
        );
        DB::insert('escrow_transactions', [
            'trade_id' => $tradeId,
            'user_id'  => $trade['user_a_id'],
            'amount'   => $feeA,
            'type'     => 'fee_deduct',
            'note'     => 'کارمزد پلتفرم ' . (int)(PLATFORM_FEE_RATE * 100) . '٪ — معامله #' . $tradeId,
        ]);
    }
    
    if ($feeB > 0) {
        credit_transact(
            (int)$trade['user_b_id'],
            'fee',
            -$feeB,
            'کارمزد پلتفرم ' . (int)(PLATFORM_FEE_RATE * 100) . '٪ — معامله #' . $tradeId,
            [
                'ref_type'   => 'trade',
                'ref_id'     => $tradeId,
                'trade_id'   => $tradeId,
                'listing_id' => $trade['listing_b_id'],
            ]
        );
        DB::insert('escrow_transactions', [
            'trade_id' => $tradeId,
            'user_id'  => $trade['user_b_id'],
            'amount'   => $feeB,
            'type'     => 'fee_deduct',
            'note'     => 'کارمزد پلتفرم ' . (int)(PLATFORM_FEE_RATE * 100) . '٪ — معامله #' . $tradeId,
        ]);
    }

    // Release any remaining escrow amount to the correct user
    if ($trade['escrow_status'] === 'held' && (float)$trade['escrow_amount'] > 0) {
        $escrowAmount = (float)$trade['escrow_amount'];
        
        // Determine who should receive the escrow
        if ((float)$trade['credit_diff'] > 0) {
            // User A should receive (escrow was from user B)
            credit_transact(
                (int)$trade['user_a_id'],
                'trade_credit',
                $escrowAmount,
                'دریافت معامله #' . $tradeId,
                [
                    'ref_type'   => 'trade',
                    'ref_id'     => $tradeId,
                    'trade_id'   => $tradeId,
                    'listing_id' => $trade['listing_a_id'],
                ]
            );
        } elseif ((float)$trade['credit_diff'] < 0) {
            // User B should receive (escrow was from user A)
            credit_transact(
                (int)$trade['user_b_id'],
                'trade_credit',
                $escrowAmount,
                'دریافت معامله #' . $tradeId,
                [
                    'ref_type'   => 'trade',
                    'ref_id'     => $tradeId,
                    'trade_id'   => $tradeId,
                    'listing_id' => $trade['listing_b_id'],
                ]
            );
        }
        
        DB::insert('escrow_transactions', [
            'trade_id' => $tradeId,
            'user_id'  => (float)$trade['credit_diff'] > 0 ? $trade['user_a_id'] : $trade['user_b_id'],
            'amount'   => $escrowAmount,
            'type'     => 'release',
            'note'     => 'آزادسازی پس از تکمیل معامله',
        ]);
        
        DB::update('trades', ['escrow_status' => 'released'], 'id = ?', [$tradeId]);
    } else {
        DB::update('trades', ['escrow_status' => 'released'], 'id = ?', [$tradeId]);
    }

    return $totalFee;
}

// ─── Swap Score ───────────────────────────────────────────────────────────

function swap_score_label(int $score): string {
    return match (true) {
        $score >= 80 => 'عالی',
        $score >= 60 => 'خوب',
        $score >= 40 => 'متوسط',
        default      => 'تازه‌کار',
    };
}

function compute_swap_score(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];

    $user = DB::fetch(
        'SELECT kyc_status, rating, rating_count FROM users WHERE id = ?',
        [$userId]
    ) ?: ['kyc_status' => 'none', 'rating' => 0];

    $breakdown = [];
    $score     = 0;

    $kycPts = ($user['kyc_status'] ?? '') === 'approved' ? 20 : 0;
    $breakdown['kyc'] = ['label' => 'احراز هویت', 'points' => $kycPts, 'max' => 20];
    $score += $kycPts;

    $trades   = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM trades WHERE (user_a_id = ? OR user_b_id = ?) AND status = "completed"',
        [$userId, $userId]
    )['c'] ?? 0);
    $tradePts = min(10, $trades) * 3;
    $breakdown['trades'] = ['label' => 'معاملات موفق', 'points' => $tradePts, 'max' => 30, 'count' => $trades];
    $score += $tradePts;

    $rating    = (float)($user['rating'] ?? 0);
    $ratingPts = $rating > 0 ? (int)round(($rating / 5) * 30) : 0;
    $breakdown['rating'] = ['label' => 'رضایت کاربران', 'points' => $ratingPts, 'max' => 30];
    $score += $ratingPts;

    $openDisputes = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM disputes WHERE against = ? AND status IN ("open","reviewing")',
        [$userId]
    )['c'] ?? 0);
    $trustPts = $openDisputes === 0 ? 20 : max(0, 20 - $openDisputes * 10);
    $breakdown['trust'] = ['label' => 'بدون شکایت فعال', 'points' => $trustPts, 'max' => 20];
    $score += $trustPts;

    $score = min(100, $score);
    $cache[$userId] = [
        'score'     => $score,
        'breakdown' => $breakdown,
        'label'     => swap_score_label($score),
    ];
    return $cache[$userId];
}

// ─── Match engine (swap / multi-swap) ─────────────────────────────────────

function text_matches_want(string $want, string $title, string $catName = ''): bool {
    $wantLower  = mb_strtolower(trim($want));
    $titleLower = mb_strtolower(trim($title));
    $catLower   = mb_strtolower(trim($catName));

    if ($wantLower === '' || $titleLower === '') return false;
    if (mb_strpos($titleLower, $wantLower) !== false || mb_strpos($wantLower, $titleLower) !== false) {
        return true;
    }

    foreach (preg_split('/[\/،,\+\-\|]+/u', $want) ?: [] as $part) {
        $part = trim(mb_strtolower($part));
        if (mb_strlen($part) < 2) continue;
        if (mb_strpos($titleLower, $part) !== false) return true;
        if ($catLower && mb_strpos($catLower, $part) !== false) return true;
    }
    return false;
}

function listing_wants_item(array $listing, array $target): bool {
    if (empty($listing['want_in_return'])) return false;
    return text_matches_want(
        $listing['want_in_return'],
        $target['title'] ?? '',
        $target['cat_name'] ?? ''
    );
}

/** Loose match: same category or keyword overlap (for surfacing suggestions) */
function listing_loose_match(array $a, array $b): bool {
    if (listing_wants_item($a, $b) || listing_wants_item($b, $a)) return true;
    if (!empty($a['category_id']) && (int)$a['category_id'] === (int)($b['category_id'] ?? 0)) {
        return true;
    }
    $words = preg_split('/\s+/u', mb_strtolower($b['title'] ?? '')) ?: [];
    foreach ($words as $w) {
        if (mb_strlen($w) >= 3 && mb_strpos(mb_strtolower($a['want_in_return'] ?? ''), $w) !== false) {
            return true;
        }
    }
    return false;
}

function find_swap_matches(int $userId, int $limit = 6): array {
    $myListings = DB::fetchAll(
        'SELECT l.*, c.name AS cat_name FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.user_id = ? AND l.status = "active" AND l.listing_mode IN ("swap","both")',
        [$userId]
    );
    if (empty($myListings)) return [];

    $pool = DB::fetchAll(
        'SELECT l.*, u.name AS seller_name, c.name AS cat_name,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         WHERE l.status = "active" AND l.review_status = "approved" AND l.user_id != ? AND l.listing_mode IN ("swap","both")
         ORDER BY l.created_at DESC LIMIT 150',
        [$userId]
    );

    $matches = [];
    foreach ($myListings as $mine) {
        foreach ($pool as $c) {
            $theyWantMine = listing_wants_item($c, $mine);
            $iWantTheirs  = listing_wants_item($mine, $c);
            $loose        = !$theyWantMine && !$iWantTheirs && listing_loose_match($mine, $c);

            if (!$theyWantMine && !$iWantTheirs && !$loose) continue;

            if ($theyWantMine && $iWantTheirs) {
                $score = 100;
            } elseif ($theyWantMine || $iWantTheirs) {
                $score = 65;
            } else {
                $score = 45;
            }

            $matches[] = array_merge($c, [
                'match_score'      => $score,
                'match_listing_id' => $mine['id'],
                'match_title'      => $mine['title'],
                'mutual'           => $theyWantMine && $iWantTheirs,
            ]);
        }
    }

    usort($matches, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

    $seen = [];
    $out  = [];
    foreach ($matches as $m) {
        if (isset($seen[$m['id']])) continue;
        $seen[$m['id']] = true;
        $out[] = $m;
        if (count($out) >= $limit) break;
    }
    return $out;
}

function find_triangular_swaps(int $userId, int $limit = 4): array {
    $myListings = DB::fetchAll(
        'SELECT l.*, c.name AS cat_name FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.user_id = ? AND l.status = "active" AND l.listing_mode IN ("swap","both")',
        [$userId]
    );
    if (empty($myListings)) return [];

    $pool = DB::fetchAll(
        'SELECT l.*, u.name AS seller_name, c.name AS cat_name,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         WHERE l.status = "active" AND l.review_status = "approved" AND l.listing_mode IN ("swap","both")
         ORDER BY l.created_at DESC LIMIT 120'
    );

    $chains = [];
    foreach ($myListings as $mine) {
        foreach ($pool as $a) {
            if ((int)$a['user_id'] === $userId) continue;
            if (!listing_wants_item($a, $mine)) continue;

            foreach ($pool as $b) {
                if ($b['id'] === $a['id'] || (int)$b['user_id'] === $userId || (int)$b['user_id'] === (int)$a['user_id']) continue;
                if (!listing_wants_item($b, $a)) continue;

                foreach ($pool as $c) {
                    if ($c['id'] === $a['id'] || $c['id'] === $b['id']) continue;
                    if ((int)$c['user_id'] === $userId) continue;
                    if ((int)$c['user_id'] === (int)$a['user_id'] || (int)$c['user_id'] === (int)$b['user_id']) continue;
                    if (!listing_wants_item($c, $b)) continue;
                    if (!listing_wants_item($mine, $c)) continue;

                    $key = implode('-', [(int)$mine['id'], (int)$a['id'], (int)$b['id'], (int)$c['id']]);
                    $chains[$key] = [
                        'mine'  => $mine,
                        'chain' => [$a, $b, $c],
                    ];
                    if (count($chains) >= $limit) return array_values($chains);
                }
            }
        }
    }
    return array_values($chains);
}
