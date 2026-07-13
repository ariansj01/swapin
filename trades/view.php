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
    } elseif ($action === 'pay_fee' && !(int)$trade['fee_paid']) {
        if (!$isA) {
            $error = 'فقط صاحب آگهی می‌تواند پرداخت کارمزد را آغاز کند.';
        } else {
            $result = pay_trade_platform_fee($tradeId);
            if (isset($result['error'])) {
                if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                    $_SESSION['error'] = $result['error'];
                    header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                    exit;
                }
                $error = $result['error'];
            } else {
                $success = 'کارمزد پلتفرم با موفقیت پرداخت شد.';
            }
        }
    } elseif ($action === 'pay_diff' && !(int)$trade['diff_paid']) {
        $myBalance = (float)($user['credit_balance'] ?? 0);
        if ($myBalance < $amountToPay) {
            $_SESSION['error'] = 'موجودی کیف پول شما کافی نیست. مبلغ موردنیاز: ' . fmt_credit($amountToPay - $myBalance);
        } else {
            escrow_hold($tradeId, $uid, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
            DB::query('UPDATE trades SET diff_paid = 1, step = 4 WHERE id = ?', [$tradeId]);
            $success = 'اختلاف قیمت با موفقیت پرداخت شد.';
        }
    } elseif ($action === 'sign_contract') {
        if (sign_trade_contract($tradeId, $uid)) {
            $success = 'قرارداد با موفقیت امضا شد.';
            if (contract_fully_signed($tradeId)) {
                DB::query('UPDATE trades SET step = 5 WHERE id = ?', [$tradeId]);
            }
        } else {
            $error = 'امضای قرارداد ممکن نشد.';
        }
    } elseif ($action === 'set_shipping') {
        $shippingDate = clean($_POST['shipping_date'] ?? '');
        $shippingTime = clean($_POST['shipping_time'] ?? '');
        if ($shippingDate && $shippingTime) {
            if ($isA) {
                DB::update('trades', [
                    'user_a_shipping_date' => $shippingDate,
                    'user_a_shipping_time' => $shippingTime,
                ], 'id = ?', [$tradeId]);
            } else {
                DB::update('trades', [
                    'user_b_shipping_date' => $shippingDate,
                    'user_b_shipping_time' => $shippingTime,
                ], 'id = ?', [$tradeId]);
            }
            $success = 'زمان ارسال ثبت شد.';
            $trade = fetch_trade_room($tradeId, $uid) ?? $trade;
            if (!empty($trade['user_a_shipping_date']) && !empty($trade['user_b_shipping_date'])) {
                DB::query('UPDATE trades SET step = 6 WHERE id = ?', [$tradeId]);
            }
        } else {
            $error = 'تاریخ و ساعت ارسال را کامل وارد کنید.';
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
    $feePaid        = !empty($trade['fee_paid']);
    $diffPaid       = !empty($trade['diff_paid']);
    $contractSigned = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady  = !empty($trade['user_a_shipping_date']) && !empty($trade['user_b_shipping_date']);
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
    $feePaid        = !empty($trade['fee_paid']);
    $diffPaid       = !empty($trade['diff_paid']);
    $contractSigned = !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady  = !empty($trade['user_a_shipping_date']) && !empty($trade['user_b_shipping_date']);
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

    if ($stepId === 3 && !empty($trade['fee_paid'])) {
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
$shippingReady   = !empty($trade['user_a_shipping_date']) && !empty($trade['user_b_shipping_date']);
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
    ? 'ارسال هماهنگ شده بین طرفین'
    : 'در انتظار انتخاب زمان ارسال';
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
    
    <div class="trade-room__hero-center">
      <h1>اتاق امن معامله #<?= $tradeId ?></h1>
      <p>لطفاً مراحل معامله را فقط از طریق این اتاق دنبال کنید.</p>
    </div>
    <div class="trade-room__hero-right">
      <a href="#" class="trade-room__hero-btn">
        <i class="bi bi-question-circle"></i>
        راهنمای معامله
      </a>
      <a href="#" class="trade-room__hero-btn">
        <i class="bi bi-exclamation-triangle"></i>
        گزارش مشکل
      </a>
    </div>
    <!-- <div class="trade-room__hero-right">
      <a href="<?= APP_URL ?>/trades" class="trade-room__back">
        <i class="bi bi-arrow-right"></i>
        بازگشت
      </a>
      <div class="trade-room__hero-user">
        <div class="trade-room__avatar"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
        <div>
          <div class="trade-room__hero-user-name"><?= h($user['name']) ?></div>
          <div class="trade-room__hero-user-meta">حساب کاربری شما</div>
        </div>
      </div>
    </div> -->
  </header>

  <!-- Parties Header moved up -->
  <section class="trade-room__card" style="margin-bottom: var(--sp-4);">
    <div class="trade-room__parties" style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 20px; align-items: center;">
      <div class="trade-room__party" style="text-align: right;">
        <div class="trade-room__avatar" style="margin-right: 0;"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
        <div class="trade-room__party-name">شما</div>
        <div class="trade-room__party-meta"><?= h($user['city'] ?? 'شهر نامشخص') ?></div>
      </div>

      <section class="trade-room__status-banner">
        <i class="bi bi-arrow-left-right"></i>
        <span>معامله در حال انجام</span>
      </section>

      <div class="trade-room__party" style="text-align: left;">
        <div class="trade-room__avatar" style="margin-left: 0;"><?= h(mb_substr($otherName, 0, 1)) ?></div>
        <div class="trade-room__party-name"><?= h($otherName) ?></div>
        <div class="trade-room__party-meta">طرف مقابل</div>
      </div>
    </div>
  </section>

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
          <i class="bi bi-list-check"></i>
          وضعیت معامله
        </h3>
        <div class="trade-room__timeline">
          <?php foreach ($timelineItems as $item): ?>
            <?php $stepStatus = get_step_status($item['id'], $trade, $contract, $hasReview); ?>
            <?php $itemDate = get_step_datetime($item['id'], $trade, $contract, $hasReview); ?>
            <a href="?id=<?= $tradeId ?>&tab=<?= h($item['tab']) ?>" class="trade-room__timeline-item trade-room__timeline-item--<?= $stepStatus ?>" style="text-decoration: none; display: block;">
              <div class="trade-room__timeline-dot"></div>
              <div class="trade-room__timeline-body">
                <div class="trade-room__timeline-title">
                  <?= h($item['title']) ?>
                </div>
                <?php if ($itemDate): ?>
                  <div class="trade-room__timeline-note"><?= persian_datetime($itemDate) ?></div>
                <?php endif; ?>
              </div>
            </a>
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
      <nav class="trade-room__tabs" aria-label="مراحل معامله">
        <?php foreach ($tabLabels as $tabKey => $label): ?>
          <a href="?id=<?= $tradeId ?>&tab=<?= h($tabKey) ?>" class="trade-room__tab <?= $tab === $tabKey ? 'trade-room__tab--active' : '' ?>">
            <?= h($label) ?>
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
            <?php if ($trade['fee_paid']): ?>
              <p>کارمزد پلتفرم با موفقیت پرداخت شده است.</p>
              <span class="trade-room__pill trade-room__pill--success">کارمزد پرداخت شده</span>
            <?php else: ?>
              <p>برای ادامه معامله، کارمزد پلتفرم باید پرداخت شود.</p>
              <div class="trade-room__meta-box" style="margin: var(--sp-4) 0;">
                <div class="trade-room__meta-line">
                  <span>کارمزد شما</span>
                  <strong><?= fmt_credit($myFee) ?></strong>
                </div>
                <div class="trade-room__meta-line">
                  <span>کارمزد طرف مقابل</span>
                  <strong><?= fmt_credit($isA ? $feeB : $feeA) ?></strong>
                </div>
              </div>
              <?php if ($isA): ?>
                <div class="trade-room__notice">با تایید، کارمزد هر دو طرف از کیف پول کسر می‌شود.</div>
                <form method="POST" class="trade-room__cta-row">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="pay_fee">
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-check-circle"></i> تایید و پرداخت کارمزد
                  </button>
                </form>
              <?php else: ?>
                <div class="trade-room__notice">در انتظار پرداخت کارمزد توسط صاحب آگهی هستیم.</div>
                <span class="trade-room__pill trade-room__pill--warning">در انتظار پرداخت</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>

        <?php elseif ($tab === 'contract'): ?>
          <h3 class="trade-room__card-title">
            <i class="bi bi-file-earmark-text"></i>
            تایید قرارداد
          </h3>
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
                  <h4>زمان ارسال شما</h4>
                  <?php $myDate = $isA ? ($trade['user_a_shipping_date'] ?? '') : ($trade['user_b_shipping_date'] ?? ''); ?>
                  <?php $myTime = $isA ? ($trade['user_a_shipping_time'] ?? '') : ($trade['user_b_shipping_time'] ?? ''); ?>
                  <?php if ($myDate && $myTime): ?>
                    <p>برای ارسال شما زمان ثبت شده است.</p>
                    <span class="trade-room__pill trade-room__pill--success"><?= h($myDate) ?> · <?= h($myTime) ?></span>
                  <?php else: ?>
                    <form method="POST" class="trade-room__stack">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_shipping">
                      <input type="date" name="shipping_date" class="form-control" required>
                      <input type="time" name="shipping_time" class="form-control" required>
                      <button type="submit" class="btn btn-primary">ثبت زمان ارسال</button>
                    </form>
                  <?php endif; ?>
                </div>

                <div class="trade-room__action">
                  <h4>زمان ارسال طرف مقابل</h4>
                  <?php $theirDate = $isA ? ($trade['user_b_shipping_date'] ?? '') : ($trade['user_a_shipping_date'] ?? ''); ?>
                  <?php $theirTime = $isA ? ($trade['user_b_shipping_time'] ?? '') : ($trade['user_a_shipping_time'] ?? ''); ?>
                  <?php if ($theirDate && $theirTime): ?>
                    <p>زمان ارسال طرف مقابل مشخص شده است.</p>
                    <span class="trade-room__pill trade-room__pill--success"><?= h($theirDate) ?> · <?= h($theirTime) ?></span>
                  <?php else: ?>
                    <div class="trade-room__notice">هنوز طرف مقابل زمان ارسال خودش را ثبت نکرده است.</div>
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
      <section class="trade-room__card">
        <h3 class="trade-room__card-title">
          <i class="bi bi-receipt"></i>
          جزئیات معامله
        </h3>

        <!-- Products -->
        <div class="trade-room__product-card">
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

      <!-- Price Difference -->
      <section class="trade-room__card">
        <h3 class="trade-room__card-title">
          <i class="bi bi-cash"></i>
          اختلاف قیمت
        </h3>
        <div class="st-diff">
          <div class="st-diff__amount"><?= $amountToPay > 0 ? fmt_credit($amountToPay) : 'ندارد' ?></div>
          <div class="st-diff__label">اختلاف قیمت</div>
        </div>
        <div class="trade-room__subcard">
          <div class="trade-room__subcard-title">وضعیت تسویه اختلاف قیمت</div>
          <div class="st-status-row">
            <span class="st-status-row__label">اختلاف قیمت</span>
            <span class="trade-room__pill <?= $trade['diff_paid'] ? 'trade-room__pill--success' : 'trade-room__pill--warning' ?>">
              <?= $trade['diff_paid'] ? 'پرداخت شده' : 'در انتظار' ?>
            </span>
          </div>
        </div>
      </section>

      <section class="trade-room__card">
        <h3 class="trade-room__card-title">
          <i class="bi bi-gear-wide-connected"></i>
          ارسال و تسویه
        </h3>
        <div class="trade-room__subcard">
          <div class="trade-room__subcard-title">روش ارسال</div>
          <div class="trade-room__muted" style="margin-bottom:8px;"><?= h($summaryShippingMethod) ?></div>
          <div class="trade-room__pill trade-room__pill--warning" style="font-size:.75rem;">پیک فوری</div>
        </div>
        <div class="trade-room__subcard">
          <div class="trade-room__subcard-title">روش تسویه</div>
          <div class="trade-room__muted" style="margin-bottom:8px;"><?= h($summaryPaymentMethod) ?></div>
          <div class="trade-room__pill trade-room__pill--success" style="font-size:.75rem;">کیف پول امن</div>
        </div>
      </section>
    </aside>
  </div>
</div>

<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>

<!-- Persian (Jalali) Date Picker (Vanilla JS) -->
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.4/dist/jalaali.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/fa.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Initialize Flatpickr with Persian locale
  const dateInputs = document.querySelectorAll('input[name="shipping_date"]');
  dateInputs.forEach(function(input) {
    flatpickr(input, {
      locale: 'fa',
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'j F Y',
      altInputClass: 'form-control'
    });
  });
});
</script>
<?php render_footer(); ?>
