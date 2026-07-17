<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';

$user = require_auth();
$uid  = (int)$user['id'];

$tradeId = (int)($_GET['id'] ?? 0);
if (!$tradeId) {
    header('Location: ' . APP_URL . '/trades');
    exit;
}

function fetch_trade_room(int $tradeId, int $uid): ?array
{
    return DB::fetch(
        'SELECT t.*, ua.name AS user_a_name, ub.name AS user_b_name,
                la.title AS listing_a_title, lb.title AS listing_b_title,
                la.estimated_value AS listing_a_val, lb.estimated_value AS listing_b_val,
                (SELECT filename FROM listing_images WHERE listing_id = la.id AND is_primary = 1 LIMIT 1) AS img_a,
                (SELECT filename FROM listing_images WHERE listing_id = lb.id AND is_primary = 1 LIMIT 1) AS img_b
         FROM trades t
         JOIN users ua ON ua.id = t.user_a_id
         JOIN users ub ON ub.id = t.user_b_id
         JOIN listings la ON la.id = t.listing_a_id
         LEFT JOIN listings lb ON lb.id = t.listing_b_id
         WHERE t.id = ? AND (t.user_a_id = ? OR t.user_b_id = ?)',
        [$tradeId, $uid, $uid]
    ) ?: null;
}

$trade = fetch_trade_room($tradeId, $uid);
if (!$trade) {
    header('Location: ' . APP_URL . '/trades');
    exit;
}

$isA       = (int)$trade['user_a_id'] === $uid;
$otherName = $isA ? $trade['user_b_name'] : $trade['user_a_name'];
$otherId   = $isA ? (int)$trade['user_b_id'] : (int)$trade['user_a_id'];

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
$iOwe = $userToPayId === $uid;

$contract = get_trade_contract($tradeId);
if (!$contract) {
    create_trade_contract($tradeId);
    $contract = get_trade_contract($tradeId);
}

$success  = '';
$error    = '';
$myReview = DB::fetch(
    'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
    [$tradeId, $uid]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');
    
    if ($action === 'select_payment_method') {
        $paymentMethod = clean($_POST['payment_method'] ?? '');
        if (in_array($paymentMethod, ['in_person', 'bnpl', 'cash'], true)) {
            DB::update('trades', [
                'selected_payment_method' => $paymentMethod,
            ], 'id = ?', [$tradeId]);
            $success = 'روش پرداخت با موفقیت انتخاب شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        }
    } elseif ($action === 'select_shipping_method') {
        $shippingMethod = clean($_POST['shipping_method'] ?? '');
        if (in_array($shippingMethod, ['courier', 'post', 'swapin_secure'], true)) {
            DB::update('trades', [
                'selected_shipping_method' => $shippingMethod,
            ], 'id = ?', [$tradeId]);
            $success = 'روش ارسال با موفقیت انتخاب شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        }
    }

    if ($action === 'send_message') {
        $body = clean($_POST['body'] ?? '');
        if ($body !== '') {
            DB::insert('secure_room_messages', [
                'trade_id' => $tradeId,
                'user_id'  => $uid,
                'type'     => 'text',
                'body'     => $body,
            ]);
            $success = 'پیام ارسال شد.';
        }
    } elseif ($action === 'pay_fee' && !trade_user_fee_paid($trade, $isA)) {
        $result = pay_trade_user_fee($tradeId, $uid);
        if (isset($result['error'])) {
            if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                $_SESSION['error'] = $result['error'];
                header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                exit;
            }
            $error = $result['error'];
        } elseif (!empty($result['already_paid'])) {
            $success = 'کارمزد شما قبلاً پرداخت شده است.';
        } else {
            $success = 'کارمزد شما با موفقیت پرداخت شد.';
        }
    } elseif ($action === 'pay_diff' && !(int)$trade['diff_paid']) {
        $selectedPaymentMethod = $trade['selected_payment_method'] ?? '';
        if (empty($selectedPaymentMethod)) {
            $error = 'لطفاً ابتدا روش پرداخت را انتخاب کنید.';
        } elseif ($selectedPaymentMethod === 'in_person') {
            // Payment in person, just mark it as paid without deducting from wallet
            DB::query('UPDATE trades SET diff_paid = 1, step = 4 WHERE id = ?', [$tradeId]);
            $success = 'روش پرداخت در محل انتخاب شد. اختلاف قیمت هنگام تحویل کالا پرداخت خواهد شد.';
        } else {
            // Cash or BNPL, deduct from wallet
            $myBalance = (float)($user['credit_balance'] ?? 0);
            if ($myBalance < $amountToPay) {
                $_SESSION['error'] = 'موجودی کیف پول شما کافی نیست. مبلغ موردنیاز: ' . fmt_credit($amountToPay - $myBalance);
            } else {
                escrow_hold($tradeId, $uid, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
                DB::query('UPDATE trades SET diff_paid = 1, step = 4 WHERE id = ?', [$tradeId]);
                $success = 'اختلاف قیمت با موفقیت پرداخت شد.';
            }
        }
    } elseif ($action === 'sign_contract') {
        if (!trade_fees_fully_paid($trade)) {
            $error = 'ابتدا هر دو طرف باید کارمزد خود را پرداخت کنند.';
        } elseif (sign_trade_contract($tradeId, $uid)) {
            $success = 'قرارداد با موفقیت امضا شد.';
            if (contract_fully_signed($tradeId)) {
                DB::query('UPDATE trades SET step = 5 WHERE id = ?', [$tradeId]);
            }
        } else { 
            $error = 'امضای قرارداد ممکن نشد.';
        }
    } elseif ($action === 'set_shipping') {
        $shippingDateInput = clean($_POST['shipping_date'] ?? '');
        $shippingTime      = clean($_POST['shipping_time'] ?? '');
        $shippingMethod    = $trade['selected_shipping_method'] ?? '';
        $shippingDate      = parse_shipping_date_input($shippingDateInput);

        if (!$shippingDate) {
            $error = 'تاریخ شمسی را به‌درستی وارد کنید (مثال: ۱۴۰۴/۰۴/۲۳).';
        } elseif (!$shippingTime) {
            $error = 'ساعت ارسال را وارد کنید.';
        } elseif (!in_array($shippingMethod, ['courier', 'post', 'swapin_secure'], true)) {
            $error = 'ابتدا روش ارسال را از پایین صفحه انتخاب کنید.';
        } else {
            if ($isA) {
                DB::update('trades', [
                    'user_a_shipping_date'   => $shippingDate,
                    'user_a_shipping_time'   => $shippingTime,
                    'user_a_shipping_method' => $shippingMethod,
                ], 'id = ?', [$tradeId]);
            } else {
                DB::update('trades', [
                    'user_b_shipping_date'   => $shippingDate,
                    'user_b_shipping_time'   => $shippingTime,
                    'user_b_shipping_method' => $shippingMethod,
                ], 'id = ?', [$tradeId]);
            }
            $success = 'زمان ارسال ثبت شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
            $aReady = !empty($trade['user_a_shipping_date']) && !empty($trade['user_a_shipping_time']);
            $bReady = !empty($trade['user_b_shipping_date']) && !empty($trade['user_b_shipping_time']);
            $shippingSelected = !empty($trade['selected_shipping_method']);
            if ($aReady && $bReady && $shippingSelected) {
                DB::query('UPDATE trades SET step = 6 WHERE id = ?', [$tradeId]);
            }
        }
    } elseif ($action === 'mark_shipped') {
        if ($isA) {
            DB::update('trades', ['user_a_delivered' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_delivered' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'وضعیت ارسال شما ثبت شد.';
        $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        if (!empty($trade['user_a_delivered']) && !empty($trade['user_b_delivered'])) {
            DB::query('UPDATE trades SET step = 7 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'save_tracking') {
        $code = clean($_POST['tracking_code'] ?? '');
        if ($code === '') {
            $error = 'کد رهگیری را وارد کنید.';
        } elseif ($isA) {
            DB::update('trades', ['tracking_code_a' => $code], 'id = ?', [$tradeId]);
            $success = 'کد رهگیری شما ثبت شد.';
        } else {
            DB::update('trades', ['tracking_code_b' => $code], 'id = ?', [$tradeId]);
            $success = 'کد رهگیری شما ثبت شد.';
        }
    } elseif ($action === 'confirm_received') {
        if ($isA) {
            DB::update('trades', ['user_a_received' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_received' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'دریافت کالا تایید شد.';
        $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
        if (!empty($trade['user_a_received']) && !empty($trade['user_b_received'])) {
            DB::query('UPDATE trades SET step = 9 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'confirm_trade') {
        if (!contract_fully_signed($tradeId)) {
            $error = 'ابتدا باید قرارداد توسط هر دو طرف امضا شود.';
        } else {
            if ($isA) {
                DB::query('UPDATE trades SET status = "user_a_confirmed" WHERE id = ? AND status = "in_progress"', [$tradeId]);
            } else {
                DB::query('UPDATE trades SET status = "user_b_confirmed" WHERE id = ? AND status = "user_a_confirmed"', [$tradeId]);
            }

            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]) ?: $trade;
            if (($trade['status'] ?? '') === 'user_b_confirmed') {
                $result = complete_trade($tradeId);
                if (isset($result['error'])) {
                    $_SESSION['error'] = $result['error'];
                    if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                        header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                        exit;
                    }
                    header('Location: ' . APP_URL . '/trades?trade=' . $tradeId);
                    exit;
                }
                DB::query('UPDATE trades SET step = 10 WHERE id = ?', [$tradeId]);
                $success = 'معامله نهایی شد و سپرده آزاد شد.';
            } else {
                $success = 'تایید شما ثبت شد. در انتظار تایید طرف مقابل.';
            }
        }
    }

    $trade    = fetch_trade_room($tradeId, $uid) ?? $trade;
    $contract = get_trade_contract($tradeId) ?? $contract;
    $myReview = DB::fetch(
        'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
        [$tradeId, $uid]
    );
}

$messages = DB::fetchAll(
    'SELECT m.*, u.name AS user_name
     FROM secure_room_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.trade_id = ?
     ORDER BY m.created_at ASC',
    [$tradeId]
);

DB::query(
    'UPDATE secure_room_messages SET is_read = 1 WHERE trade_id = ? AND user_id != ?',
    [$tradeId, $uid]
);

function get_step_status(int $stepId, array $trade, array $contract, bool $hasReview): string
{
    $feePaid        = trade_fees_fully_paid($trade);
    $diffPaid       = !empty($trade['diff_paid']);
    $contractSigned = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady  = trade_shipping_fully_scheduled($trade);
    $shipped        = !empty($trade['user_a_delivered']) && !empty($trade['user_b_delivered']);
    $trackingReady  = !empty($trade['tracking_code_a']) && !empty($trade['tracking_code_b']);
    $received       = !empty($trade['user_a_received']) && !empty($trade['user_b_received']);
    $completed      = ($trade['status'] ?? '') === 'completed';

    if ($stepId === 1 || $stepId === 2) return 'done';
    if ($stepId === 3) return $feePaid ? 'done' : 'current';
    if ($stepId === 4) return $contractSigned ? 'done' : ($feePaid ? 'current' : 'locked');
    if ($stepId === 5) {
        global $amountToPay;
        if ($amountToPay <= 0) return 'done';
        return $diffPaid ? 'done' : ($contractSigned ? 'current' : 'locked');
    }
    if ($stepId === 6) return $shippingReady ? 'done' : ($contractSigned ? 'current' : 'locked');
    if ($stepId === 7) return $received ? 'done' : ($shippingReady ? 'current' : 'locked');
    if ($stepId === 8) return ($completed && $hasReview) ? 'done' : ($received ? 'current' : 'locked');
    return 'locked';
}

function get_trade_flow_tab(array $trade, array $contract, bool $hasReview): string
{
    $feePaid        = trade_fees_fully_paid($trade);
    $diffPaid       = !empty($trade['diff_paid']);
    $contractSigned = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady  = trade_shipping_fully_scheduled($trade);
    $trackingReady  = !empty($trade['tracking_code_a']) && !empty($trade['tracking_code_b']);
    $received       = !empty($trade['user_a_received']) && !empty($trade['user_b_received']);
    $completed      = ($trade['status'] ?? '') === 'completed';

    global $amountToPay;

    if (!$feePaid) {
        return 'fee';
    }
    if (!$contractSigned) {
        return 'contract';
    }
    if ($amountToPay > 0 && !$diffPaid) {
        return 'diff';
    }
    if (!$shippingReady || !$trackingReady) {
        return 'shipping';
    }
    if (!$received) {
        return 'details';
    }
    if (!$hasReview) {
        return 'final';
    }
    return 'chat';
}

function get_step_datetime(int $stepId, array $trade, array $contract, bool $hasReview): ?string
{
    $createdAt = $trade['created_at'] ?? null;

    if ($stepId === 1 || $stepId === 2) {
        return $createdAt;
    }

    if ($stepId === 3 && trade_fees_fully_paid($trade)) {
        return $trade['updated_at'] ?? $createdAt;
    }

    if ($stepId === 4 && !empty($trade['contract_signed_at'])) {
        return $trade['contract_signed_at'];
    }

    if ($stepId === 5) {
        global $amountToPay;
        if ($amountToPay <= 0) {
            return null;
        }
        if (!empty($trade['diff_paid'])) {
            return $trade['updated_at'] ?? $createdAt;
        }
    }

    if ($stepId === 6) {
        $shippingDate = $trade['user_a_shipping_date'] ?? $trade['user_b_shipping_date'] ?? null;
        $shippingTime = $trade['user_a_shipping_time'] ?? $trade['user_b_shipping_time'] ?? null;
        if ($shippingDate) {
            return trim($shippingDate . ' ' . ($shippingTime ?: '00:00:00'));
        }
    }

    if ($stepId === 7 && !empty($trade['user_a_received']) && !empty($trade['user_b_received'])) {
        return $trade['updated_at'] ?? $createdAt;
    }

    if ($stepId === 8 && ($trade['status'] ?? '') === 'completed') {
        if ($hasReview) {
            return $trade['completed_at'] ?? $trade['updated_at'] ?? $createdAt;
        }
    }

    return null;
}

$hasReview       = (bool)$myReview;
$contractSigned  = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
$shippingReady   = trade_shipping_fully_scheduled($trade);
$myFeePaid       = trade_user_fee_paid($trade, $isA);
$theirFeePaid    = trade_user_fee_paid($trade, !$isA);
$feesFullyPaid   = trade_fees_fully_paid($trade);
$deliveredByMe   = $isA ? !empty($trade['user_a_delivered']) : !empty($trade['user_b_delivered']);
$deliveredByThem = $isA ? !empty($trade['user_b_delivered']) : !empty($trade['user_a_delivered']);
$trackingMine    = $isA ? (string)($trade['tracking_code_a'] ?? '') : (string)($trade['tracking_code_b'] ?? '');
$trackingTheirs  = $isA ? (string)($trade['tracking_code_b'] ?? '') : (string)($trade['tracking_code_a'] ?? '');
$receivedMine    = $isA ? !empty($trade['user_a_received']) : !empty($trade['user_b_received']);
$receivedTheirs  = $isA ? !empty($trade['user_b_received']) : !empty($trade['user_a_received']);
$receivedAll     = !empty($trade['user_a_received']) && !empty($trade['user_b_received']);
$completedTrade  = ($trade['status'] ?? '') === 'completed';

$feeA = (float)($trade['listing_a_val'] ?? 0) * PLATFORM_FEE_RATE;
$feeB = (float)($trade['listing_b_val'] ?? 0) * PLATFORM_FEE_RATE;
$myFee = $isA ? $feeA : $feeB;

$allowedTabs = ['chat', 'fee', 'contract', 'diff', 'shipping', 'details', 'final'];
$recommendedTab = get_trade_flow_tab($trade, $contract, $hasReview);
if (isset($_GET['accepted'])) {
    $recommendedTab = trade_fees_fully_paid($trade) ? $recommendedTab : 'fee';
}
$requestedTab = clean($_GET['tab'] ?? $recommendedTab);
$tab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : $recommendedTab;

$myProduct = $isA ? [
    'title' => $trade['listing_a_title'],
    'img'   => !empty($trade['img_a']) ? UPLOAD_URL . $trade['img_a'] : '',
    'val'   => $trade['listing_a_val'] ?? 0,
] : [
    'title' => $trade['listing_b_title'] ?: 'در حال تسویه با اعتبار',
    'img'   => !empty($trade['img_b']) ? UPLOAD_URL . $trade['img_b'] : '',
    'val'   => $trade['listing_b_val'] ?? 0,
];

$otherProduct = $isA ? [
    'title' => $trade['listing_b_title'] ?: 'تسویه اعتباری',
    'img'   => !empty($trade['img_b']) ? UPLOAD_URL . $trade['img_b'] : '',
    'val'   => $trade['listing_b_val'] ?? 0,
] : [
    'title' => $trade['listing_a_title'],
    'img'   => !empty($trade['img_a']) ? UPLOAD_URL . $trade['img_a'] : '',
    'val'   => $trade['listing_a_val'] ?? 0,
];

$summaryShippingMethod = $shippingReady
    ? shipping_label((string)($isA ? ($trade['user_a_shipping_method'] ?? '') : ($trade['user_b_shipping_method'] ?? '')))
        . ' / ' . shipping_label((string)($isA ? ($trade['user_b_shipping_method'] ?? '') : ($trade['user_a_shipping_method'] ?? '')))
    : 'در انتظار انتخاب زمان و روش ارسال';
$summaryPaymentMethod = $amountToPay > 0
    ? 'کیف پول امن سواپین / نگهداری امانی'
    : 'بدون اختلاف قیمت';

$tabLabels = [
    'chat'     => 'چت',
    'fee'      => 'پرداخت کارمزد',
    'contract' => 'تایید قرارداد',
    'diff'     => 'پرداخت اختلاف قیمت',
    'shipping' => 'ارسال',
    'details'  => 'دریافت',
    'final'    => 'تایید نهایی و ثبت نظر',
];

// Timeline items mapped to tabs
$timelineItems = [
    ['id' => 1, 'title' => 'ثبت پیشنهاد', 'tab' => 'chat', 'date' => null],
    ['id' => 2, 'title' => 'پذیرش پیشنهاد', 'tab' => 'chat', 'date' => null],
    ['id' => 3, 'title' => 'پرداخت کارمزد', 'tab' => 'fee', 'date' => null],
    ['id' => 4, 'title' => 'تایید قرارداد', 'tab' => 'contract', 'date' => null],
    ['id' => 5, 'title' => 'پرداخت اختلاف قیمت', 'tab' => 'diff', 'date' => null],
    ['id' => 6, 'title' => 'ارسال', 'tab' => 'shipping', 'date' => null],
    ['id' => 7, 'title' => 'دریافت', 'tab' => 'details', 'date' => null],
    ['id' => 8, 'title' => 'تایید نهایی و ثبت نظر', 'tab' => 'final', 'date' => null],
];

// Fetch all user's trades for dropdown
$allTrades = DB::fetchAll(
    'SELECT t.id, t.status,
        ua.name as user_a_name, ub.name as user_b_name,
        la.title as listing_a_title, lb.title as listing_b_title
     FROM trades t
     JOIN users ua ON ua.id = t.user_a_id
     JOIN users ub ON ub.id = t.user_b_id
     JOIN listings la ON la.id = t.listing_a_id
     LEFT JOIN listings lb ON lb.id = t.listing_b_id
     WHERE t.user_a_id = ? OR t.user_b_id = ?
     ORDER BY t.created_at DESC',
    [$uid, $uid]
);

// Fetch received offers for modal
$receivedOffers = DB::fetchAll(
    'SELECT o.*, l.title AS listing_title, u.name AS from_name, u.avatar AS from_avatar,
        ol.title AS offer_listing_title,
        (SELECT filename FROM listing_images WHERE listing_id=ol.id AND is_primary=1 LIMIT 1) AS offer_listing_thumb
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = o.from_user_id
     LEFT JOIN listings ol ON ol.id = o.offer_listing_id
     WHERE l.user_id = ? AND o.status = "pending"
     ORDER BY o.created_at DESC',
    [$uid]
);

render_head('اتاق امن معامله #' . $tradeId, 'اتاق امن معامله و مدیریت مرحله‌به‌مرحله تبادل', [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
echo '<link rel="stylesheet" href="' . APP_URL . '/src/css/secure-trade.css">' . "\n";
render_navbar($user);
render_user_panel_open($user, 'trades');
?>

<div class="trade-room">
  <!-- New Header -->
  <header class="trade-room__hero">
    <div class="trade-room__hero-left">
      <a href="<?= APP_URL ?>/trades" class="trade-room__back">
        <i class="bi bi-arrow-right"></i>
        بازگشت
      </a>
    </div>
    <div class="trade-room__hero-center">
      <h1>اتاق امن معامله #<?= $tradeId ?></h1>
      <p>لطفاً مراحل معامله را فقط از طریق این اتاق دنبال کنید.</p>
    </div>
    <div class="trade-room__hero-right">
      <!-- Trade Selector Dropdown -->
      <div class="trade-room__dropdown">
        <select class="trade-room__select" onchange="window.location.href='?id='+this.value">
          <?php foreach ($allTrades as $t):
            $otherName = ((int)$t['user_a_id'] === $uid) ? $t['user_b_name'] : $t['user_a_name'];
            $listingTitle = ((int)$t['user_a_id'] === $uid) ? $t['listing_a_title'] : $t['listing_b_title'];
            $listingTitle = $listingTitle ?? 'تسویه اعتباری';
          ?>
            <option value="<?= $t['id'] ?>" <?= $t['id'] == $tradeId ? 'selected' : '' ?>>
              معامله با <?= h($otherName) ?> - <?= h(mb_substr($listingTitle, 0, 30)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Offers Button -->
      <button type="button" class="trade-room__hero-btn" id="openOffersModal">
        <i class="bi bi-inbox"></i>
        پیشنهادها
        <?php
          $pendingCount = 0;
          foreach ($receivedOffers as $o) {
            if ($o['status'] === 'pending') $pendingCount++;
          }
          if ($pendingCount > 0):
        ?>
          <span class="trade-room__badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </button>

      <a href="<?= APP_URL ?>/fraud-prevention" class="trade-room__hero-btn">
        <i class="bi bi-question-circle"></i>
        راهنمای معامله
      </a>
    </div>
  </header>

  <!-- Parties Header moved up -->
  <section class="trade-room__card" style="margin-bottom: var(--sp-4);display: flex;justify-content: center;">
    <div class="trade-room__parties" style="display: grid; grid-template-columns: 37% 15% 30%; gap: 20px;align-items: center;width: 100%;">
      <div class="trade-room__party" style="display: flex; align-items: center; justify-content: center; gap: 12px;">
        <div class="trade-room__avatar" style="margin: 0;"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
        <div style="text-align: right;">
          <div class="trade-room__party-name">شما</div>
          <div class="trade-room__party-meta"><?= h($user['city'] ?? 'شهر نامشخص') ?></div>
        </div>
      </div>

      <section class="trade-room__status-banner">
        <i class="bi bi-arrow-left-right" style="font-size: 37px; color: #081B45;"></i>
        <span>معامله در حال انجام</span>
      </section>

      <div class="trade-room__party" style="display: flex; align-items: center; justify-content: center; gap: 12px;">
        <div class="trade-room__avatar" style="margin: 0;"><?= h(mb_substr($otherName, 0, 1)) ?></div>
        <div style="text-align: left;">
          <div class="trade-room__party-name"><?= h($otherName) ?></div>
          <div class="trade-room__party-meta">طرف مقابل</div>
        </div>
      </div>
    </div>
  </section>

  <!-- Mobile Steps Button -->
  <div class="trade-room__mobile-steps-btn-container">
    <button type="button" class="trade-room__mobile-steps-btn" id="openMobileStepsModal">
      <i class="bi bi-list-check"></i>
      مراحل معامله
    </button>
  </div>

  <!-- Alerts -->
  <?php if (isset($_GET['accepted'])): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle"></i>
    پیشنهاد پذیرفته شد! لطفاً مراحل معامله را از همین اتاق امن دنبال کنید.
  </div>
  <?php endif; ?>

  <?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger">
    <i class="bi bi-exclamation-circle"></i>
    <?= h($_SESSION['error']) ?>
  </div>
  <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle"></i>
    <?= h($success) ?>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-danger">
    <i class="bi bi-exclamation-circle"></i>
    <?= h($error) ?>
  </div>
  <?php endif; ?>

  <div class="trade-room__layout">
    <!-- Right Column: Timeline (next to dashboard sidebar) -->
    <aside class="trade-room__timeline-column">
      <section class="trade-room__timeline-card">
        <h3 class="trade-room__card-title">
          <!-- <i class="bi bi-list-check"></i> -->
          وضعیت معامله
        </h3>
        <div class="trade-room__timeline">
          <?php foreach ($timelineItems as $item): ?>
            <?php $stepStatus = get_step_status($item['id'], $trade, $contract, $hasReview); ?>
            <?php $itemDate = get_step_datetime($item['id'], $trade, $contract, $hasReview); ?>
            <?php if ($stepStatus === 'current'): ?>
            <a href="?id=<?= $tradeId ?>&tab=<?= h($item['tab']) ?>" class="trade-room__timeline-item trade-room__timeline-item--<?= $stepStatus ?>" style="text-decoration: none; display: block;">
            <?php else: ?>
            <div class="trade-room__timeline-item trade-room__timeline-item--<?= $stepStatus ?> trade-room__timeline-item--readonly">
            <?php endif; ?>
              <div class="trade-room__timeline-dot"></div>
              <div class="trade-room__timeline-body">
                <div class="trade-room__timeline-title">
                  <?= h($item['title']) ?>
                </div>
                <?php if ($itemDate): ?>
                  <div class="trade-room__timeline-note"><?= persian_datetime($itemDate) ?></div>
                <?php endif; ?>
              </div>
            <?php if ($stepStatus === 'current'): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="trade-room__timeline-card trade-room__support">
        <h3 class="trade-room__card-title">
          <i class="bi bi-headset"></i>
          نیاز به کمک دارید؟
        </h3>
        <p class="trade-room__muted">اگر در هر مرحله ابهام یا اختلافی داشتید، از پشتیبانی کمک بگیرید.</p>
        <div class="trade-room__cta-row">
          <a href="<?= APP_URL ?>/support" class="btn btn-primary w-100">تماس با پشتیبانی</a>
        </div>
      </section>
    </aside>

    <!-- Center Column: Main Content -->
    <div class="trade-room__content">

      <!-- Tabs -->
      <?php $tabIcons = [
        'chat'     => 'bi-chat-dots',
        'fee'      => 'bi-credit-card',
        'contract' => 'bi-file-earmark-text',
        'diff'     => 'bi-cash',
        'shipping' => 'bi-truck',
        'details'  => 'bi-box-seam',
        'final'    => 'bi-check-circle',
      ]; ?>
      <nav class="trade-room__tabs" aria-label="مراحل معامله">
        <?php foreach ($tabLabels as $tabKey => $label): ?>
          <a href="?id=<?= $tradeId ?>&tab=<?= h($tabKey) ?>" class="trade-room__tab <?= $tab === $tabKey ? 'trade-room__tab--active' : '' ?>">
            <i class="bi <?= $tabIcons[$tabKey] ?? 'bi-circle' ?> trade-room__tab-icon"></i>
            <span><?= h($label) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <!-- Tab Content -->
      <section class="trade-room__panel">
        <?php if ($tab === 'chat'): ?>
          <h3 class="trade-room__card-title trade-room__card-title--center">
            <i class="bi bi-lock"></i>
            گفتگوی امن
          </h3>
          <p class="trade-room__muted trade-room__muted--center">تمام پیام‌ها در محیط امن ذخیره می‌شوند و مبنای پیگیری معامله هستند.</p>

          <div class="trade-room__chat-list" id="trade-room-chat">
            <?php if (empty($messages)): ?>
              <div class="trade-room__chat-empty">هنوز پیامی ثبت نشده است.</div>
            <?php else: ?>
              <?php foreach ($messages as $msg): ?>
                <?php $isMine = (int)$msg['user_id'] === $uid; ?>
                <div class="trade-room__chat-item <?= $isMine ? 'trade-room__chat-item--mine' : 'trade-room__chat-item--other' ?>">
                  <?php if (!$isMine): ?>
                    <div class="trade-room__chat-avatar"><?= h(mb_substr($msg['user_name'], 0, 1)) ?></div>
                  <?php endif; ?>
                  <div class="trade-room__chat-bubble">
                    <div><?= h($msg['body']) ?></div>
                    <div class="trade-room__chat-meta"><?= h($msg['user_name']) ?> · <?= persian_date($msg['created_at']) ?></div>
                  </div>
                  <?php if ($isMine): ?>
                    <div class="trade-room__chat-avatar"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="trade-room__composer">
            <div class="trade-room__composer-tools">
              <button type="button" class="trade-room__tool-btn" title="فایل"><i class="bi bi-paperclip"></i></button>
              <button type="button" class="trade-room__tool-btn" title="گالری"><i class="bi bi-image"></i></button>
              <button type="button" class="trade-room__tool-btn" title="ویس"><i class="bi bi-mic"></i></button>
            </div>
            <form method="POST" class="trade-room__composer-input">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="send_message">
              <input type="text" name="body" placeholder="پیام خود را بنویسید..." autocomplete="off" required>
            </form>
            <button type="button" class="trade-room__send" onclick="this.parentNode.querySelector('form').submit()">
              <i class="bi bi-send"></i>
            </button>
          </div>

        <?php elseif ($tab === 'fee'): ?>
          <h3 class="trade-room__card-title"> 
            <i class="bi bi-credit-card"></i>
            پرداخت کارمزد
          </h3>
          <div class="trade-room__action">
            <h4>وضعیت کارمزد پلتفرم</h4>
            <p>هر طرف باید کارمزد خودش را جداگانه پرداخت کند.</p>
            <div class="trade-room__meta-box" style="margin: var(--sp-4) 0;">
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_a_name']) ?> (کارمزد: <?= fmt_credit($feeA) ?>)</span>
                <span class="<?= trade_user_fee_paid($trade, true) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= trade_user_fee_paid($trade, true) ? 'پرداخت شده' : 'در انتظار' ?>
                </span>
              </div>
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_b_name']) ?> (کارمزد: <?= fmt_credit($feeB) ?>)</span>
                <span class="<?= trade_user_fee_paid($trade, false) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= trade_user_fee_paid($trade, false) ? 'پرداخت شده' : 'در انتظار' ?>
                </span>
              </div>
            </div>
            <?php if ($feesFullyPaid): ?>
              <span class="trade-room__pill trade-room__pill--success">کارمزد هر دو طرف پرداخت شد</span>
            <?php elseif (!$myFeePaid): ?>
              <div class="trade-room__notice">کارمزد شما: <strong><?= fmt_credit($myFee) ?></strong></div>
              <form method="POST" class="trade-room__cta-row">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_fee">
                <button type="submit" class="btn btn-primary w-100">
                  <i class="bi bi-check-circle"></i> پرداخت کارمزد
                </button>
              </form>
            <?php else: ?>
              <span class="trade-room__pill trade-room__pill--success">کارمزد شما پرداخت شده است</span>
              <div class="trade-room__notice">در انتظار پرداخت کارمزد توسط طرف مقابل هستیم.</div>
            <?php endif; ?>
          </div>

        <?php elseif ($tab === 'contract'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-file-earmark-text"></i>
            تایید قرارداد
          </h3>
          <?php if (!$feesFullyPaid): ?>
            <div class="trade-room__notice">ابتدا از تب «پرداخت کارمزد» هر دو طرف باید کارمزد خود را پرداخت کنند.</div>
          <?php else: ?>
          <div class="trade-room__action">
            <h4>امضای قرارداد</h4>
            <p>هر دو طرف باید قرارداد را امضا کنند تا تب ارسال فعال شود.</p>
            <div class="trade-room__meta-box">
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_a_name']) ?></span>
                <span class="<?= !empty($contract['user_a_signed']) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= !empty($contract['user_a_signed']) ? 'امضا شده' : 'در انتظار امضا' ?>
                </span>
              </div>
              <div class="trade-room__meta-line">
                <span><?= h($trade['user_b_name']) ?></span>
                <span class="<?= !empty($contract['user_b_signed']) ? 'trade-room__pill trade-room__pill--success' : 'trade-room__pill trade-room__pill--warning' ?>">
                  <?= !empty($contract['user_b_signed']) ? 'امضا شده' : 'در انتظار امضا' ?>
                </span>
              </div>
            </div>
            <?php if (($isA && empty($contract['user_a_signed'])) || (!$isA && empty($contract['user_b_signed']))): ?>
              <div class="trade-room__cta-row">
                <form method="POST" class="w-100">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="sign_contract">
                  <button type="submit" class="btn btn-primary w-100">امضای قرارداد</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        <?php elseif ($tab === 'diff'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-cash"></i>
            پرداخت اختلاف قیمت
          </h3>
          <div class="trade-room__action">
            <h4>اختلاف قیمت</h4>
            <?php if ($amountToPay <= 0): ?>
              <p>برای این معامله اختلاف قیمت در نظر گرفته نشده است.</p>
              <span class="trade-room__pill trade-room__pill--success">نیازی نیست</span>
            <?php elseif ($trade['diff_paid']): ?>
              <p>مابه‌التفاوت این معامله با موفقیت در امانت سواپین ثبت شده است.</p>
              <span class="trade-room__pill trade-room__pill--success">پرداخت شده</span>
            <?php elseif ($iOwe): ?>
              <p>شما باید مبلغ <strong><?= fmt_credit($amountToPay) ?></strong> را برای ادامه معامله پرداخت کنید.</p>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_diff">
                <button type="submit" class="btn btn-accent w-100">پرداخت اختلاف قیمت</button>
              </form>
            <?php else: ?>
              <p>در حال انتظار برای پرداخت اختلاف قیمت توسط طرف مقابل هستیم.</p>
              <span class="trade-room__pill trade-room__pill--warning">در انتظار</span>
            <?php endif; ?>
          </div>

        <?php elseif ($tab === 'shipping'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-truck"></i>
            ارسال
          </h3>

          <?php if (!$contractSigned): ?>
            <div class="trade-room__notice">ابتدا از تب «تایید قرارداد» امضای هر دو طرف را تکمیل کنید.</div>
          <?php else: ?>
            <div class="trade-room__stack">
              <div class="trade-room__grid">
                <div class="trade-room__action">
                  <h4>زمان و روش ارسال شما</h4>
                  <?php
                    $myDate   = $isA ? ($trade['user_a_shipping_date'] ?? '') : ($trade['user_b_shipping_date'] ?? '');
                    $myTime   = $isA ? ($trade['user_a_shipping_time'] ?? '') : ($trade['user_b_shipping_time'] ?? '');
                    $myMethod = $isA ? ($trade['user_a_shipping_method'] ?? '') : ($trade['user_b_shipping_method'] ?? '');
                  ?>
                  <?php if ($myDate && $myTime && $myMethod): ?>
                    <p>اطلاعات ارسال شما ثبت شده است.</p>
                    <span class="trade-room__pill trade-room__pill--success"><?= persian_date($myDate) ?> · <?= h(substr($myTime, 0, 5)) ?></span>
                    <div class="fs-sm mt-2" style="color:var(--text-muted)">روش: <?= h(shipping_label($myMethod)) ?></div>
                  <?php else: ?>
                    <form method="POST" class="trade-room__stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_shipping">
                        <div class="form-group">
                            <label class="form-label">تاریخ ارسال (شمسی)</label>
                            <input type="text" name="shipping_date" class="form-control jalali-date-input"
                                   data-jdp data-jdp-only-date placeholder="۱۴۰۴/۰۴/۲۳" autocomplete="off" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ساعت ارسال</label>
                            <input type="time" name="shipping_time" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">ثبت زمان ارسال</button>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="trade-room__action">
                  <h4>زمان و روش ارسال طرف مقابل</h4>
                  <?php
                    $theirDate   = $isA ? ($trade['user_b_shipping_date'] ?? '') : ($trade['user_a_shipping_date'] ?? '');
                    $theirTime   = $isA ? ($trade['user_b_shipping_time'] ?? '') : ($trade['user_a_shipping_time'] ?? '');
                    $theirMethod = $isA ? ($trade['user_b_shipping_method'] ?? '') : ($trade['user_a_shipping_method'] ?? '');
                  ?>
                  <?php if ($theirDate && $theirTime && $theirMethod): ?>
                    <p>اطلاعات ارسال طرف مقابل مشخص شده است.</p>
                    <span class="trade-room__pill trade-room__pill--success"><?= persian_date($theirDate) ?> · <?= h(substr($theirTime, 0, 5)) ?></span>
                    <div class="fs-sm mt-2" style="color:var(--text-muted)">روش: <?= h(shipping_label($theirMethod)) ?></div>
                  <?php else: ?>
                    <div class="trade-room__notice">هنوز طرف مقابل زمان و روش ارسال خودش را ثبت نکرده است.</div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="trade-room__grid">
                <div class="trade-room__action">
                  <h4>وضعیت ارسال شما</h4>
                  <?php if (!$shippingReady): ?>
                    <div class="trade-room__notice">بعد از ثبت زمان ارسال توسط هر دو طرف، این مرحله فعال می‌شود.</div>
                  <?php elseif ($deliveredByMe): ?>
                    <span class="trade-room__pill trade-room__pill--success">ارسال شما ثبت شده است</span>
                  <?php else: ?>
                    <p>بعد از تحویل بسته به پست یا پیک، ارسال را ثبت کنید.</p>
                    <form method="POST">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="mark_shipped">
                      <button type="submit" class="btn btn-primary">کالا را ارسال کردم</button>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="trade-room__action">
                  <h4>وضعیت ارسال طرف مقابل</h4>
                  <?php if ($deliveredByThem): ?>
                    <span class="trade-room__pill trade-room__pill--success">ارسال طرف مقابل ثبت شده است</span>
                  <?php else: ?>
                    <div class="trade-room__notice">هنوز ارسال طرف مقابل ثبت نشده است.</div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="trade-room__grid">
                <div class="trade-room__action">
                  <h4>کد رهگیری شما</h4>
                  <?php if ($trackingMine !== ''): ?>
                    <span class="trade-room__pill trade-room__pill--success"><?= h($trackingMine) ?></span>
                  <?php elseif (!$deliveredByMe): ?>
                    <div class="trade-room__notice">بعد از ثبت ارسال، کد رهگیری شما اینجا ثبت می‌شود.</div>
                  <?php else: ?>
                    <form method="POST" class="trade-room__stack">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="save_tracking">
                      <input type="text" name="tracking_code" class="form-control" placeholder="کد رهگیری را وارد کنید" required>
                      <button type="submit" class="btn btn-primary">ثبت کد رهگیری</button>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="trade-room__action">
                  <h4>کد رهگیری طرف مقابل</h4>
                  <?php if ($trackingTheirs !== ''): ?>
                    <span class="trade-room__pill trade-room__pill--success"><?= h($trackingTheirs) ?></span>
                  <?php else: ?>
                    <div class="trade-room__notice">در انتظار ثبت کد رهگیری توسط طرف مقابل.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

        <?php elseif ($tab === 'details'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-box-seam"></i>
            دریافت
          </h3>

          <?php if ($trackingMine === '' || $trackingTheirs === ''): ?>
            <div class="trade-room__notice">ابتدا از تب «ارسال» کد رهگیری هر دو طرف را ثبت کنید.</div>
          <?php else: ?>
            <div class="trade-room__grid">
              <div class="trade-room__action">
                <h4>تایید دریافت شما</h4>
                <?php if ($receivedMine): ?>
                  <span class="trade-room__pill trade-room__pill--success">دریافت شما تایید شده است</span>
                <?php else: ?>
                  <p>اگر کالا را بدون مشکل تحویل گرفته‌اید، این مرحله را تایید کنید.</p>
                  <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm_received">
                    <button type="submit" class="btn btn-primary">کالا را دریافت کردم</button>
                  </form>
                <?php endif; ?>
              </div>

              <div class="trade-room__action">
                <h4>تایید دریافت طرف مقابل</h4>
                <?php if ($receivedTheirs): ?>
                  <span class="trade-room__pill trade-room__pill--success">طرف مقابل هم دریافت را تایید کرده است</span>
                <?php else: ?>
                  <div class="trade-room__notice">هنوز طرف مقابل دریافت کالا را تایید نکرده است.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        <?php elseif ($tab === 'final'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-check-circle"></i>
            تایید نهایی و ثبت نظر
          </h3>

          <?php if (!$receivedAll): ?>
            <div class="trade-room__notice">ابتدا از تب «دریافت» تایید دریافت توسط هر دو طرف را تکمیل کنید.</div>
          <?php elseif (!$completedTrade): ?>
            <div class="trade-room__action" style="margin-bottom: 20px;">
              <h4>تایید نهایی معامله</h4>
              <p>با تایید نهایی، معامله بسته می‌شود و سپرده آزاد خواهد شد.</p>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_trade">
                <button type="submit" class="btn btn-accent">تایید نهایی معامله</button>
              </form>
            </div>
          <?php elseif ($hasReview): ?>
            <div class="trade-room__notice">امتیاز شما قبلاً ثبت شده است. ممنون از بازخوردتان.</div>
          <?php else: ?>
            <form method="POST" action="<?= APP_URL ?>/api/review.php" class="trade-room__stack" style="max-width:520px;">
              <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
              <input type="hidden" name="to_user_id" value="<?= $otherId ?>">

              <div>
                <label class="form-label">امتیاز به معامله</label>
                <select name="trade_rating" class="form-control" required>
                  <option value="5">⭐⭐⭐⭐⭐ 5</option>
                  <option value="4">⭐⭐⭐⭐ 4</option>
                  <option value="3">⭐⭐⭐ 3</option>
                  <option value="2">⭐⭐ 2</option>
                  <option value="1">⭐ 1</option>
                </select>
              </div>

              <div>
                <label class="form-label">امتیاز به طرف مقابل</label>
                <select name="user_rating" class="form-control" required>
                  <option value="5">⭐⭐⭐⭐⭐ 5</option>
                  <option value="4">⭐⭐⭐⭐ 4</option>
                  <option value="3">⭐⭐⭐ 3</option>
                  <option value="2">⭐⭐ 2</option>
                  <option value="1">⭐ 1</option>
                </select>
              </div>

              <div>
                <label class="form-label">نظر شما</label>
                <textarea name="comment" rows="4" class="form-control" placeholder="توضیح کوتاه درباره تجربه معامله"></textarea>
              </div>

              <button type="submit" class="btn btn-primary">ثبت امتیاز</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>

    <!-- Left Column: Details (30% width) -->
    <aside class="trade-room__details-column">
      <section class="trade-room__card trade-room__card--compact">
        <h3 class="trade-room__card-title">
          <!-- <i class="bi bi-receipt"></i> -->
          جزئیات معامله
        </h3>

        <!-- Products -->
        <div class="trade-room__product-card" style="margin-top: 41px;">
          <?php if ($myProduct['img']): ?>
            <img src="<?= h($myProduct['img']) ?>" alt="<?= h($myProduct['title']) ?>" class="trade-room__product-thumb">
          <?php else: ?>
            <div class="trade-room__product-thumb"><i class="bi bi-image"></i></div>
          <?php endif; ?>
          <div>
            <div class="trade-room__product-title"><?= h($myProduct['title']) ?></div>
            <div class="trade-room__product-meta">کالای شما · <?= $myProduct['val'] ? fmt_credit((float)$myProduct['val']) : 'بدون ارزش‌گذاری' ?></div>
          </div>
        </div>

        <div class="trade-room__swap-divider">
          <span class="trade-room__swap-divider-icon"><i class="bi bi-arrow-left-right"></i></span>
        </div>

        <div class="trade-room__product-card">
          <?php if ($otherProduct['img']): ?>
            <img src="<?= h($otherProduct['img']) ?>" alt="<?= h($otherProduct['title']) ?>" class="trade-room__product-thumb">
          <?php else: ?>
            <div class="trade-room__product-thumb"><i class="bi bi-image"></i></div>
          <?php endif; ?>
          <div>
            <div class="trade-room__product-title"><?= h($otherProduct['title']) ?></div>
            <div class="trade-room__product-meta">کالای طرف مقابل · <?= $otherProduct['val'] ? fmt_credit((float)$otherProduct['val']) : 'بدون ارزش‌گذاری' ?></div>
          </div>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
           اختلاف قیمت
        </h4>
         <div class="trade-room__summary-line">
          <span class="trade-room__summary-label"></span>
          <span class="trade-room__summary-value"><?= $amountToPay > 0 ? fmt_credit($amountToPay) : 'بدون اختلاف' ?></span>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
          کارمزد سواپین(هرطرف)
        </h4>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_a_name']) ?></span>
          <span class="trade-room__summary-value"><?= fmt_credit($feeA) ?></span>
        </div>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_b_name']) ?></span>
          <span class="trade-room__summary-value"><?= fmt_credit($feeB) ?></span>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
         وضعیت پرداخت کارمزد
        </h4>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_a_name']) ?></span>
          <span class="trade-room__pill <?= trade_user_fee_paid($trade, true) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
            <?= trade_user_fee_paid($trade, true) ? 'پرداخت شده' : 'در انتظار' ?>
          </span>
        </div>
        <div class="trade-room__meta-line trade-room__meta-line--compact" style="direction: ltr;">
          <span><?= h($trade['user_b_name']) ?></span>
          <span class="trade-room__pill <?= trade_user_fee_paid($trade, false) ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
            <?= trade_user_fee_paid($trade, false) ? 'پرداخت شده' : 'در انتظار' ?>
          </span>
        </div>
      </section>

      <section class="trade-room__summary-card trade-room__summary-card--wide">
        <h4 class="trade-room__summary-head">
          <i class="bi bi-cash-stack"></i> وضعیت پرداخت اختلاف قیمت
        </h4>
        <?php if ($amountToPay <= 0): ?>
          <div class="trade-room__summary-note">برای این معامله اختلاف قیمت محاسبه نشده است.</div>
        <?php elseif ($trade['diff_paid']): ?>
          <div class="trade-room__summary-note" style="color: #16a34a;">اختلاف قیمت از قبل پرداخت شده است.</div>
        <?php else: ?>
          <div class="trade-room__summary-note">هنوز اختلاف قیمت پرداخت نشده است.</div>
        <?php endif; ?>
      </section>
    </aside>
  </div>
  <?php
    // Determine if payment method can be changed
    $feePaid = trade_fees_fully_paid($trade);
    $diffPaid = !empty($trade['diff_paid']);
    $contractSigned = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady = trade_shipping_fully_scheduled($trade);
    $canChangePayment = !$diffPaid && $contractSigned && $amountToPay > 0;
    $canChangeShipping = !$shippingReady && $contractSigned;
  ?>
  <div class="box-trade-room__layout">
    <form method="POST" class="pay-metod" id="paymentMethodForm" <?= !$canChangePayment ? 'data-disabled="true"' : '' ?>>
      <input type="hidden" name="action" value="select_payment_method">
      <?= csrf_field() ?>
      <h3 class="box-trade-room__title">تسویه اختلاف قیمت</h3>
      <div class="box-trade-room__cards">
        <label class="box-trade-room__card <?= ($trade['selected_payment_method'] ?? '') === 'in_person' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="payment_method" value="in_person" class="box-trade-room__radio" <?= ($trade['selected_payment_method'] ?? '') === 'in_person' ? 'checked' : '' ?> <?= !$canChangePayment ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-handbag"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پرداخت در محل</h4>
            <p>هنگام تحویل کالا</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= ($trade['selected_payment_method'] ?? '') === 'bnpl' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="payment_method" value="bnpl" class="box-trade-room__radio" <?= ($trade['selected_payment_method'] ?? '') === 'bnpl' ? 'checked' : '' ?> <?= !$canChangePayment ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-calendar-check"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پرداخت اقساط BNPL</h4>
            <p>پرداخت در 4 قسط</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= ($trade['selected_payment_method'] ?? '') === 'cash' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="payment_method" value="cash" class="box-trade-room__radio" <?= ($trade['selected_payment_method'] ?? '') === 'cash' ? 'checked' : '' ?> <?= !$canChangePayment ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-wallet2"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پرداخت نقدی</h4>
            <p>کم کردن از کیف پول</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
      </div>
    </form>
    <form method="POST" class="send-metod" id="shippingMethodForm" <?= !$canChangeShipping ? 'data-disabled="true"' : '' ?>>
      <input type="hidden" name="action" value="select_shipping_method">
      <?= csrf_field() ?>
      <h3 class="box-trade-room__title">روش ارسال</h3>
      <div class="box-trade-room__cards">
        <label class="box-trade-room__card <?= ($trade['selected_shipping_method'] ?? '') === 'courier' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="shipping_method" value="courier" class="box-trade-room__radio" <?= ($trade['selected_shipping_method'] ?? '') === 'courier' ? 'checked' : '' ?> <?= !$canChangeShipping ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-motorcycle"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پیک فوری</h4>
            <p>ارسال سریع درون شهری</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= ($trade['selected_shipping_method'] ?? '') === 'post' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="shipping_method" value="post" class="box-trade-room__radio" <?= ($trade['selected_shipping_method'] ?? '') === 'post' ? 'checked' : '' ?> <?= !$canChangeShipping ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-envelope-paper"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>پست پیشتاز</h4>
            <p>ارسال به سراسر کشور</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
        <label class="box-trade-room__card <?= ($trade['selected_shipping_method'] ?? '') === 'swapin_secure' ? 'box-trade-room__card--selected' : '' ?>">
          <input type="radio" name="shipping_method" value="swapin_secure" class="box-trade-room__radio" <?= ($trade['selected_shipping_method'] ?? '') === 'swapin_secure' ? 'checked' : '' ?> <?= !$canChangeShipping ? 'disabled' : '' ?>>
          <div class="box-trade-room__card-icon">
            <i class="bi bi-shield-check"></i>
          </div>
          <div class="box-trade-room__card-content">
            <h4>تحویل حضوری(ارسال در محل)</h4>
            <p>تحویل مستقیم در محل</p>
          </div>
          <div class="box-trade-room__card-check">
            <i class="bi bi-check-circle-fill"></i>
          </div>
        </label>
      </div>
    </form>
  </div>
  
  <script>
    // Auto-submit when payment method is selected
    document.getElementById('paymentMethodForm').addEventListener('change', function() {
      if (this.hasAttribute('data-disabled') && this.getAttribute('data-disabled') === 'true') {
        return;
      }
      this.submit();
    });
    
    // Auto-submit when shipping method is selected
    document.getElementById('shippingMethodForm').addEventListener('change', function() {
      if (this.hasAttribute('data-disabled') && this.getAttribute('data-disabled') === 'true') {
        return;
      }
      this.submit();
    });
  </script>
</div>

<!-- Offers Modal -->
<div class="trade-room__modal-overlay" id="offersModal">
  <div class="trade-room__modal trade-room__modal--full">
    <div class="trade-room__modal-header">
      <h2 class="trade-room__modal-title">پیشنهادها</h2>
      <button type="button" class="trade-room__modal-close" data-close-modal="offersModal">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="trade-room__modal-body">
      <?php if (empty($receivedOffers)): ?>
        <div class="trade-room__chat-empty">هنوز پیشنهادی نیست</div>
      <?php else: ?>
        <div class="trade-room__offers-grid">
        <?php foreach ($receivedOffers as $offer):
          $statusColors = ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', 'cancelled' => 'info', 'completed' => 'success'];
          $statusColor = $statusColors[$offer['status']] ?? 'info';
        ?>
          <div class="trade-room__card trade-room__offer-card">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap;">
              <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3);">
                  <?= avatar_html($offer['from_avatar'] ?? null, $offer['from_name'], 'md') ?>
                  <div>
                    <div style="font-weight:700;font-size:1.0625rem"><?= h($offer['from_name']) ?></div>
                    <?php
                      $avgRating = DB::fetch('SELECT AVG(rating) as avg_r FROM reviews WHERE to_user_id = ?', [$offer['from_user_id']])['avg_r'] ?? 0;
                      if ($avgRating > 0):
                    ?>
                    <div class="fs-xs" style="color:var(--accent-dark)">
                      <i class="bi bi-star-fill"></i> <?= number_format((float)$avgRating, 1) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <span class="trade-room__pill trade-room__pill--<?= $statusColor ?> fs-xs" style="margin-inline-start:auto"><?= offer_status_label($offer['status']) ?></span>
                </div>

                <div class="fs-sm mb-2" style="color:var(--text-muted)">
                  برای: <strong><?= h($offer['listing_title'] ?? '') ?></strong>
                </div>

                <?php if ($offer['offer_listing_title'] || (float)$offer['offer_credit'] > 0): ?>
                  <div style="background:rgba(0,174,239,.04);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-4);margin-bottom:var(--sp-3);">
                    <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-2)">پیشنهاد:</div>
                    <?php if ($offer['offer_listing_title']): ?>
                      <div style="display:flex;align-items:center;gap:var(--sp-3);">
                        <?php if ($offer['offer_listing_thumb'] ?? false): ?>
                          <img src="<?= UPLOAD_URL . h($offer['offer_listing_thumb']) ?>" alt="<?= h($offer['offer_listing_title']) ?>" style="width:60px;height:60px;border-radius:var(--radius-md);object-fit:cover">
                        <?php endif; ?>
                        <div style="font-weight:600"><i class="bi bi-box"></i> <?= h($offer['offer_listing_title']) ?></div>
                      </div>
                    <?php endif; ?>
                    <?php if ((float)$offer['offer_credit'] > 0): ?>
                      <div class="fs-md mt-2" style="color:var(--primary);font-weight:700">
                        <i class="bi bi-wallet2"></i> + <?= fmt_credit((float)$offer['offer_credit']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($offer['message']): ?>
                  <div style="background:var(--bg);border-radius:var(--radius-md);padding:var(--sp-4);font-size:.9375rem;color:var(--text-secondary)">
                    <div class="fs-xs mb-2" style="color:var(--text-muted)">پیام پیشنهاد‌دهنده:</div>
                    "<?= h($offer['message']) ?>"
                  </div>
                <?php endif; ?>

                <div class="fs-xs mt-4" style="color:var(--text-muted)">
                  <i class="bi bi-clock"></i> <?= persian_datetime($offer['created_at']) ?>
                </div>
              </div>

              <?php if ($offer['status'] === 'pending'): ?>
                <div class="trade-room__offer-actions">
                  <form method="POST" class="mb-3" action="<?= APP_URL ?>/trades">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="accept">
                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                    <div class="form-group">
                      <label class="form-label">پیام پذیرش:</label>
                      <textarea name="message" class="form-control" rows="2" required placeholder="مثلاً: سلام! پیشنهاد شما را می‌پذیرم."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                      <i class="bi bi-check-lg"></i> پذیرش
                    </button>
                  </form>
                  <form method="POST" action="<?= APP_URL ?>/trades">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                    <div class="form-group">
                      <label class="form-label">پیام رد:</label>
                      <textarea name="message" class="form-control" rows="2" required placeholder="مثلاً: متشکرم، اما این بار نمی‌تونم."></textarea>
                    </div>
                    <button type="submit" class="btn btn-ghost w-100" style="color:var(--danger)">
                      <i class="bi bi-x-lg"></i> رد
                    </button>
                  </form>
                </div>
              <?php elseif ($offer['status'] === 'accepted'): ?>
                <?php
                  $relatedTrade = DB::fetch('SELECT id FROM trades WHERE offer_id = ? LIMIT 1', [$offer['id']]);
                  if ($relatedTrade):
                ?>
                  <div style="width:100%">
                    <a href="<?= APP_URL ?>/trades/view.php?id=<?= (int)$relatedTrade['id'] ?>" class="btn btn-primary w-100">
                      <i class="bi bi-shield-lock"></i> ورود به اتاق معامله
                    </a>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Mobile Steps Modal -->
<div class="trade-room__modal-overlay" id="mobileStepsModal">
  <div class="trade-room__modal trade-room__modal--full">
    <div class="trade-room__modal-header">
      <h2 class="trade-room__modal-title">مراحل معامله</h2>
      <button type="button" class="trade-room__modal-close" data-close-modal="mobileStepsModal">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="trade-room__modal-body">
      <div class="trade-room__mobile-steps-list">
        <?php foreach ($timelineItems as $index => $item):
          $stepStatus = get_step_status($item['id'], $trade, $contract, $hasReview);
          $isCurrent = $stepStatus === 'current';
          $stepClass = 'trade-room__mobile-step';
          if ($stepStatus === 'done') {
            $stepClass .= ' trade-room__mobile-step--done trade-room__mobile-step--readonly';
          } elseif ($isCurrent) {
            $stepClass .= ' trade-room__mobile-step--active';
          } else {
            $stepClass .= ' trade-room__mobile-step--readonly';
          }
        ?>
          <button type="button" class="<?= $stepClass ?>" data-step-tab="<?= h($item['tab']) ?>" <?= $isCurrent ? '' : 'disabled' ?>>
            <div class="trade-room__mobile-step-dot">
              <?php if ($stepStatus === 'done'): ?>
                <i class="bi bi-check-lg"></i>
              <?php else: ?>
                <?= $index + 1 ?>
              <?php endif; ?>
            </div>
            <div>
              <div style="font-weight:800;"><?= h($item['title']) ?></div>
              <?php $itemDate = get_step_datetime($item['id'], $trade, $contract, $hasReview); ?>
              <?php if ($itemDate): ?>
                <div class="trade-room__muted" style="font-size:0.75rem;margin-top:2px;"><?= persian_datetime($itemDate) ?></div>
              <?php endif; ?>
            </div>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Step Detail Modal -->
<div class="trade-room__modal-overlay" id="mobileStepDetailModal">
  <div class="trade-room__modal trade-room__modal--full">
    <div class="trade-room__modal-header">
      <h2 class="trade-room__modal-title">مرحله معامله</h2>
      <button type="button" class="trade-room__modal-close" data-close-modal="mobileStepDetailModal">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="trade-room__modal-body">
      <div id="mobileStepDetailContent"></div>
      <div style="margin-top:18px;text-align:right;">
        <button type="button" class="btn btn-outline" data-close-modal="mobileStepDetailModal">بستن</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Modal handlers
  function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.add('trade-room__modal-overlay--open');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.remove('trade-room__modal-overlay--open');
      document.body.style.overflow = '';
    }
  }

  // Open buttons
  const openOffersBtn = document.getElementById('openOffersModal');
  if (openOffersBtn) {
    openOffersBtn.addEventListener('click', () => openModal('offersModal'));
  }

  const openMobileStepsBtn = document.getElementById('openMobileStepsModal');
  if (openMobileStepsBtn) {
    openMobileStepsBtn.addEventListener('click', () => openModal('mobileStepsModal'));
  }

  document.querySelectorAll('.trade-room__mobile-step[data-step-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.getAttribute('data-step-tab');
      if (!tab) return;
      const params = new URLSearchParams(window.location.search);
      params.set('tab', tab);
      params.set('mobile_step', '1');
      window.location.search = params.toString();
    });
  });

  const mobileStepDetailModal = document.getElementById('mobileStepDetailModal');
  const mobileStepDetailContent = document.getElementById('mobileStepDetailContent');
  if (mobileStepDetailModal && mobileStepDetailContent) {
    const params = new URLSearchParams(window.location.search);
    if (params.get('mobile_step') === '1') {
      const panel = document.querySelector('.trade-room__panel');
      if (panel) {
        mobileStepDetailContent.innerHTML = panel.innerHTML;
      }
      openModal('mobileStepDetailModal');
      params.delete('mobile_step');
      window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }
  }

  // Close buttons
  document.querySelectorAll('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      closeModal(e.currentTarget.getAttribute('data-close-modal'));
    });
  });

  // Close on overlay click
  document.querySelectorAll('.trade-room__modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        closeModal(overlay.id);
      }
    });
  });
});
</script>

<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>

<!-- Persian (Jalali) Date Picker -->
<link rel="stylesheet" href="https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<script src="https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof jalaliDatepicker !== 'undefined') {
    jalaliDatepicker.startWatch({
      minDate: 'today',
      time: false,
      autoHide: true,
      separatorChars: { date: '/' },
      zIndex: 9999,
    });
  }
});
</script>
<?php render_footer(); ?>
