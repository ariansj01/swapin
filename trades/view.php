<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = $user['id'];

$tradeId = (int)($_GET['id'] ?? 0);
if (!$tradeId) {
    header('Location: ' . APP_URL . '/trades.php');
    exit;
}

// Fetch trade
$trade = DB::fetch(
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
);

if (!$trade) {
    header('Location: ' . APP_URL . '/trades.php');
    exit;
}

$isA = (int)$trade['user_a_id'] === $uid;
$otherName = $isA ? $trade['user_b_name'] : $trade['user_a_name'];
$otherId = $isA ? $trade['user_b_id'] : $trade['user_a_id'];

// Calculate who owes what
$creditDiff = (float)($trade['credit_diff'] ?? 0);
if ($creditDiff > 0) {
    $userToPayId = $trade['user_b_id'];
    $amountToPay = $creditDiff;
} elseif ($creditDiff < 0) {
    $userToPayId = $trade['user_a_id'];
    $amountToPay = abs($creditDiff);
} else {
    $userToPayId = 0;
    $amountToPay = 0;
}
$iOwe = (int)$userToPayId === $uid;

// Get contract
$contract = get_trade_contract($tradeId);
if (!$contract) {
    create_trade_contract($tradeId);
    $contract = get_trade_contract($tradeId);
}

$bothPaid = $trade['fee_paid'] && $trade['diff_paid'];
$myReview = DB::fetch(
    'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
    [$tradeId, $uid]
);

// Handle ALL actions here
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($action === 'send_message') {
        $body = clean($_POST['body'] ?? '');
        if ($body !== '') {
            DB::insert('secure_room_messages', [
                'trade_id' => $tradeId,
                'user_id' => $uid,
                'type' => 'text',
                'body' => $body,
            ]);
            $success = 'پیام ارسال شد.';
        }
    } elseif ($action === 'pay_diff' && !$trade['diff_paid']) {
        $myBalance = (float)($user['credit_balance'] ?? 0);
        if ($myBalance < $amountToPay) {
            $_SESSION['error'] = 'موجودی کیف پول شما کافی نیست — نیاز: ' . fmt_credit($amountToPay - $myBalance);
        } else {
            escrow_hold($tradeId, $uid, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
            DB::query('UPDATE trades SET diff_paid = 1, step = 4 WHERE id = ?', [$tradeId]);
            $success = 'مابه‌التفاوت با موفقیت پرداخت شد!';
        }
    } elseif ($action === 'sign_contract') {
        if (sign_trade_contract($tradeId, $uid)) {
            $success = 'قرارداد با موفقیت امضا شد!';
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
                DB::update('trades', ['user_a_shipping_date' => $shippingDate, 'user_a_shipping_time' => $shippingTime], 'id = ?', [$tradeId]);
            } else {
                DB::update('trades', ['user_b_shipping_date' => $shippingDate, 'user_b_shipping_time' => $shippingTime], 'id = ?', [$tradeId]);
            }
            $success = 'زمان ارسال ثبت شد!';
            // If both set dates are set, move to step 6
            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
            if ($trade['user_a_shipping_date'] && $trade['user_b_shipping_date']) {
                DB::query('UPDATE trades SET step = 6 WHERE id = ?', [$tradeId]);
            }
        }
    } elseif ($action === 'mark_shipped') {
        if ($isA) {
            DB::update('trades', ['user_a_delivered' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_delivered' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'ارسال کالا ثبت شد.';
        $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
        if (($trade['user_a_delivered'] ?? 0) && ($trade['user_b_delivered'] ?? 0)) {
            DB::query('UPDATE trades SET step = 7 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'save_tracking') {
        $code = clean($_POST['tracking_code'] ?? '');
        if ($code === '') {
            $error = 'کد رهگیری را وارد کنید.';
        } elseif ($isA) {
            DB::update('trades', ['tracking_code_a' => $code], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['tracking_code_b' => $code], 'id = ?', [$tradeId]);
        }
        if ($error === '') {
            $success = 'کد رهگیری ثبت شد!';
            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
            if (($trade['tracking_code_a'] ?? '') !== '' && ($trade['tracking_code_b'] ?? '') !== '') {
                DB::query('UPDATE trades SET step = 8 WHERE id = ?', [$tradeId]);
            }
        }
    } elseif ($action === 'confirm_received') {
        if ($isA) {
            DB::update('trades', ['user_a_received' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_received' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'دریافت کالا تایید شد!';
        $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
        if (($trade['user_a_received'] ?? 0) && ($trade['user_b_received'] ?? 0)) {
            DB::query('UPDATE trades SET step = 9 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'confirm_trade') {
        if (!contract_fully_signed($tradeId)) {
            $error = 'اول باید قرارداد امضا بشه.';
        } else {
            $isA = (int)$trade['user_a_id'] === $uid;
            if ($isA) {
                DB::query('UPDATE trades SET status = "user_a_confirmed" WHERE id = ? AND status = "in_progress"', [$tradeId]);
            } else {
                DB::query('UPDATE trades SET status = "user_b_confirmed" WHERE id = ? AND status = "user_a_confirmed"', [$tradeId]);
            }

            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);

            if ($trade['status'] === 'user_b_confirmed') {
                $result = complete_trade($tradeId);
                if (isset($result['error'])) {
                    $_SESSION['error'] = $result['error'];
                    if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                        header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                        exit;
                    } else {
                        header('Location: ' . APP_URL . '/trades.php?trade=' . $tradeId);
                        exit;
                    }
                }
                DB::query('UPDATE trades SET step = 10 WHERE id = ?', [$tradeId]);
                $success = 'معامله تکمیل شد! سپرده آزاد شد!';
            } else {
                $success = 'تایید ثبت شد! در انتظار طرف مقابل.';
            }
        }
    }

    // Refresh trade after any action
    $trade = DB::fetch(
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
    );
    $contract = get_trade_contract($tradeId) ?? $contract;
    $myReview = DB::fetch(
        'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
        [$tradeId, $uid]
    );
}

// Fetch messages
$messages = DB::fetchAll(
    'SELECT m.*, u.name AS user_name
     FROM secure_room_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.trade_id = ?
     ORDER BY m.created_at ASC',
    [$tradeId]
);

// Mark messages as read
DB::query(
    'UPDATE secure_room_messages SET is_read = 1 WHERE trade_id = ? AND user_id != ?',
    [$tradeId, $uid]
);

function get_step_status(int $stepId, array $trade, array $contract, bool $hasReview): string
{
    $feePaid = (bool)($trade['fee_paid'] ?? 0);
    $diffPaid = (bool)($trade['diff_paid'] ?? 0);
    $contractSigned = !empty($contract) && !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady = !empty($trade['user_a_shipping_date']) && !empty($trade['user_b_shipping_date']);
    $shipped = !empty($trade['user_a_delivered']) && !empty($trade['user_b_delivered']);
    $trackingReady = !empty($trade['tracking_code_a']) && !empty($trade['tracking_code_b']);
    $received = !empty($trade['user_a_received']) && !empty($trade['user_b_received']);
    $completed = ($trade['status'] ?? '') === 'completed';

    if ($stepId === 1 || $stepId === 2) return 'completed';
    if ($stepId === 3) return $feePaid ? 'completed' : 'current';
    if ($stepId === 4) return $diffPaid ? 'completed' : ($feePaid ? 'current' : 'locked');
    if ($stepId === 5) return $contractSigned ? 'completed' : (($feePaid && $diffPaid) ? 'current' : 'locked');
    if ($stepId === 6) return $shippingReady ? 'completed' : ($contractSigned ? 'current' : 'locked');
    if ($stepId === 7) return $shipped ? 'completed' : ($shippingReady ? 'current' : 'locked');
    if ($stepId === 8) return $trackingReady ? 'completed' : ($shipped ? 'current' : 'locked');
    if ($stepId === 9) return $received ? 'completed' : ($trackingReady ? 'current' : 'locked');
    if ($stepId === 10) return $completed ? 'completed' : ($received ? 'current' : 'locked');
    if ($stepId === 11) return $hasReview ? 'completed' : ($completed ? 'current' : 'locked');
    if ($stepId === 12) return ($completed && $hasReview) ? 'completed' : 'locked';
    return 'pending';
}

$tab = clean($_GET['tab'] ?? 'chat');

// Setup product images and values
$myProduct = $isA ? [
    'title' => $trade['listing_a_title'],
    'img' => $trade['img_a'] ? (UPLOAD_URL . $trade['img_a']) : '',
    'val' => $trade['listing_a_val']
] : [
    'title' => $trade['listing_b_title'],
    'img' => $trade['img_b'] ? (UPLOAD_URL . $trade['img_b']) : '',
    'val' => $trade['listing_b_val']
];
$otherProduct = $isA ? [
    'title' => $trade['listing_b_title'],
    'img' => $trade['img_b'] ? (UPLOAD_URL . $trade['img_b']) : '',
    'val' => $trade['listing_b_val']
] : [
    'title' => $trade['listing_a_title'],
    'img' => $trade['img_a'] ? (UPLOAD_URL . $trade['img_a']) : '',
    'val' => $trade['listing_a_val']
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>اتاق امن معامله #<?= $tradeId ?> — سواپین</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/src/css/secure-trade.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/src/css/main.css">
</head>
<body class="secure-trade">

<!-- Top Bar -->
<div class="st-topbar">
  <div class="st-topbar__inner">
    <div class="st-topbar__left">
      <a href="<?= APP_URL ?>" class="st-topbar__brand">
        <img src="<?= APP_URL ?>/src/img/swapin-dark-png.png" alt="Swapin">
      </a>
      <div class="st-topbar__title-block">
        <div class="st-topbar__title">
          اتاق امن معامله
          <span class="st-topbar__title-badge">#<?= $tradeId ?></span>
        </div>
        <div class="st-topbar__subtitle">لطفاً مراحل معامله را فقط از طریق این اتاق دنبال کنید.</div>
      </div>
    </div>
    <div class="st-topbar__right">
      <a href="<?= APP_URL ?>/support" class="st-topbar__btn st-topbar__btn--ghost">
        <i class="bi bi-question-circle"></i> راهنمای معامله
      </a>
      <a href="<?= APP_URL ?>/support" class="st-topbar__btn st-topbar__btn--primary">
        <i class="bi bi-exclamation-triangle"></i> گزارش مشکل
      </a>
    </div>
  </div>
</div>

<!-- Main Layout -->
<div class="st-main">

  <!-- Right Sidebar -->
  <aside class="st-sidebar">
    <div class="st-sidebar__brand">
      <img src="<?= APP_URL ?>/src/img/swapin-dark-png.png" alt="Swapin" class="st-sidebar__logo">
    </div>
    <a href="<?= APP_URL ?>/listings/create.php" class="st-sidebar__btn">
      <i class="bi bi-plus-circle"></i> ثبت آگهی
    </a>
    <nav class="st-sidebar__nav">
      <a href="<?= APP_URL ?>/dashboard.php" class="st-sidebar__nav-link">
        <i class="bi bi-speedometer2"></i> داشبورد
      </a>
      <a href="<?= APP_URL ?>/listings/my.php" class="st-sidebar__nav-link">
        <i class="bi bi-grid"></i> آگهی‌های من
      </a>
      <a href="<?= APP_URL ?>/listings/offers.php" class="st-sidebar__nav-link">
        <i class="bi bi-send"></i> پیشنهادها
      </a>
      <a href="<?= APP_URL ?>/messages.php" class="st-sidebar__nav-link">
        <i class="bi bi-chat-dots"></i> پیام‌ها
      </a>
      <a href="<?= APP_URL ?>/listings/saved.php" class="st-sidebar__nav-link">
        <i class="bi bi-heart"></i> علاقه‌مندی
      </a>
      <a href="<?= APP_URL ?>/trades.php" class="st-sidebar__nav-link st-sidebar__nav-link--active">
        <i class="bi bi-shield-lock"></i> اتاق‌های معامله
      </a>
      <a href="<?= APP_URL ?>/wallet.php" class="st-sidebar__nav-link">
        <i class="bi bi-wallet2"></i> کیف پول
      </a>
      <a href="<?= APP_URL ?>/profile/edit.php" class="st-sidebar__nav-link">
        <i class="bi bi-gear"></i> تنظیمات
      </a>
      <a href="<?= APP_URL ?>/support" class="st-sidebar__nav-link">
        <i class="bi bi-headset"></i> پشتیبانی
      </a>
    </nav>
    <div class="st-sidebar__pro">
      <div class="st-sidebar__pro-icon"><i class="bi bi-gem"></i></div>
      <div class="st-sidebar__pro-title">اشتراک حرفه‌ای</div>
      <div class="st-sidebar__pro-desc">آگهی نامحدود + گزارش پیشرفته</div>
      <a href="<?= APP_URL ?>/subscription.php" class="st-sidebar__pro-cta">مشاهده پلن‌ها</a>
    </div>
  </aside>

  <!-- Center Column -->
  <div class="st-center">

    <!-- Trade Header (Buyer ↔ Seller) -->
    <div class="st-trade-header">
      <div class="st-party">
        <div class="st-party__avatar"><?= mb_substr($user['name'], 0, 1) ?></div>
        <div class="st-party__name">شما</div>
        <div class="st-party__meta"><?= $user['city'] ?? 'شهر نامشخص' ?></div>
      </div>

      <div class="st-exchange-badge">
        <div class="st-exchange-badge__arrow"><i class="bi bi-arrow-left-right"></i></div>
        <div class="st-exchange-badge__status">معامله در حال انجام</div>
      </div>

      <div class="st-party">
        <div class="st-party__avatar"><?= mb_substr($otherName, 0, 1) ?></div>
        <div class="st-party__name"><?= h($otherName) ?></div>
        <div class="st-party__meta">طرف مقابل</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="st-tabs">
      <button class="st-tab <?= $tab === 'chat' ? 'st-tab--active' : '' ?>" onclick="location.href='?id=<?= $tradeId ?>&tab=chat'">
        <i class="bi bi-chat-dots"></i> ارتباط
      </button>
      <button class="st-tab <?= $tab === 'shipping' ? 'st-tab--active' : '' ?>" onclick="location.href='?id=<?= $tradeId ?>&tab=shipping'">
        <i class="bi bi-truck"></i> ارسال
      </button>
      <button class="st-tab <?= $tab === 'contract' ? 'st-tab--active' : '' ?>" onclick="location.href='?id=<?= $tradeId ?>&tab=contract'">
        <i class="bi bi-file-text"></i> قرارداد
      </button>
      <button class="st-tab <?= $tab === 'details' ? 'st-tab--active' : '' ?>" onclick="location.href='?id=<?= $tradeId ?>&tab=details'">
        <i class="bi bi-box-seam"></i> تحویل
      </button>
      <button class="st-tab <?= $tab === 'status' ? 'st-tab--active' : '' ?>" onclick="location.href='?id=<?= $tradeId ?>&tab=status'">
        <i class="bi bi-check-circle"></i> وضعیت معامله
      </button>
      <button class="st-tab <?= $tab === 'rating' ? 'st-tab--active' : '' ?>" onclick="location.href='?id=<?= $tradeId ?>&tab=rating'">
        <i class="bi bi-star"></i> امتیازدهی
      </button>
    </div>

    <!-- Tab Content -->
    <?php if ($tab === 'chat'): ?>
      <div class="st-chat">
        <div class="st-chat__header">
          <div class="st-chat__header-left">
            <div class="st-chat__header-icon"><i class="bi bi-lock"></i></div>
            <div>
              <div class="st-chat__header-title">گفتگوی امن</div>
              <div class="st-chat__header-desc">تمام پیام‌ها در محیط امن ذخیره می‌شوند.</div>
            </div>
          </div>
        </div>
        <div class="st-chat__messages" id="chat-messages">
          <?php if (empty($messages)): ?>
            <div class="st-chat__empty">
              <i class="bi bi-chat-dots"></i>
              <p>هنوز پیامی ارسال نشده است.</p>
            </div>
          <?php else: foreach ($messages as $msg):
            $isMe = (int)$msg['user_id'] === $uid;
          ?>
            <div class="st-message <?= $isMe ? 'st-message--user' : 'st-message--other' ?>">
              <?php if (!$isMe): ?>
                <div class="st-message__avatar"><?= mb_substr($msg['user_name'], 0, 1) ?></div>
              <?php endif; ?>
              <div class="st-message__bubble">
                <div class="st-message__text"><?= h($msg['body']) ?></div>
                <div class="st-message__meta">
                  <span><?= persian_date($msg['created_at']) ?></span>
                  <?php if ($isMe): ?>
                    <i class="bi bi-check-all"></i>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($isMe): ?>
                <div class="st-message__avatar"><?= mb_substr($user['name'], 0, 1) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div class="st-chat__input">
          <div class="st-chat__attach">
            <button class="st-chat__attach-btn" title="فایل"><i class="bi bi-paperclip"></i></button>
            <button class="st-chat__attach-btn" title="گالری"><i class="bi bi-image"></i></button>
            <button class="st-chat__attach-btn" title="ویس"><i class="bi bi-mic"></i></button>
          </div>
          <form method="POST" class="st-chat__input-wrap" style="flex: 1; display: flex; align-items: center; gap: 10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_message">
            <input type="text" name="body" placeholder="پیام خود را بنویسید..." autocomplete="off">
          </form>
          <button class="st-chat__send-btn" onclick="this.closest('.st-chat__input').querySelector('form').submit();">
            <i class="bi bi-send"></i>
          </button>
        </div>
      </div>
    <?php else: ?>
      <div class="st-card" style="padding: 30px;">
        <h4 style="margin-bottom: 16px; color: #081B45; font-weight: 800;">
          <i class="bi bi-tools"></i> بخش در حال توسعه
        </h4>
        <p style="color: #6b7280;">این بخش به‌زودی کامل می‌شود. لطفاً از بخش گفتگو استفاده کنید.</p>
      </div>
    <?php endif; ?>

  </div>

  <!-- Left Column: Summary Cards -->
  <div class="st-left">

    <!-- Product Swap -->
    <div class="st-card">
      <div class="st-card__title"><i class="bi bi-arrow-left-right"></i> کالاهای معامله</div>
      <div class="st-product-swap">
        <div class="st-product">
          <?php if ($myProduct['img']): ?>
            <img src="<?= h($myProduct['img']) ?>" class="st-product__img" alt="">
          <?php else: ?>
            <div class="st-product__img"><i class="bi bi-image" style="opacity: .3; font-size: 1.5rem;"></i></div>
          <?php endif; ?>
          <div class="st-product__info">
            <div class="st-product__name"><?= h($myProduct['title']) ?></div>
            <div class="st-product__price">
              <?php if ($myProduct['val']): ?>
                ~<?= fmt_credit($myProduct['val']) ?>
              <?php else: ?>
                قیمت ثبت نشده
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="st-swap-arrow"><i class="bi bi-arrow-down-up"></i></div>
        <div class="st-product">
          <?php if ($otherProduct['img']): ?>
            <img src="<?= h($otherProduct['img']) ?>" class="st-product__img" alt="">
          <?php else: ?>
            <div class="st-product__img"><i class="bi bi-image" style="opacity: .3; font-size: 1.5rem;"></i></div>
          <?php endif; ?>
          <div class="st-product__info">
            <div class="st-product__name"><?= h($otherProduct['title']) ?></div>
            <div class="st-product__price">
              <?php if ($otherProduct['val']): ?>
                ~<?= fmt_credit($otherProduct['val']) ?>
              <?php else: ?>
                قیمت ثبت نشده
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Price Difference -->
    <?php if ($amountToPay > 0): ?>
      <div class="st-card st-diff">
        <div class="st-diff__amount"><?= fmt_credit($amountToPay) ?></div>
        <div class="st-diff__label"><?= $iOwe ? 'مبلغی که شما باید پرداخت کنید' : 'مبلغی که شما دریافت می‌کنید' ?></div>
      </div>
    <?php endif; ?>

    <!-- Fees + Diff Status -->
    <div class="st-card">
      <div class="st-card__title"><i class="bi bi-cash-coin"></i> وضعیت پرداخت‌ها</div>
      <div class="st-status-row">
        <span class="st-status-row__label">شما (کارمزد)</span>
        <span class="st-status-badge st-status-badge--<?= $trade['fee_paid'] ? 'paid' : 'pending' ?>">
          <?= $trade['fee_paid'] ? 'پرداخت شده' : 'پرداخت نشده' ?>
        </span>
      </div>
      <div class="st-status-row">
        <span class="st-status-row__label">طرف مقابل (کارمزد)</span>
        <span class="st-status-badge st-status-badge--<?= $trade['fee_paid'] ? 'paid' : 'pending' ?>">
          <?= $trade['fee_paid'] ? 'پرداخت شده' : 'در انتظار' ?>
        </span>
      </div>
      <?php if ($amountToPay > 0): ?>
      <div class="st-status-row">
        <span class="st-status-row__label">مابه‌التفاوت</span>
        <span class="st-status-badge st-status-badge--<?= $trade['diff_paid'] ? 'paid' : 'pending' ?>">
          <?= $trade['diff_paid'] ? 'پرداخت شده' : 'پرداخت نشده' ?>
        </span>
      </div>
      <?php endif; ?>
      <button class="st-details-btn">مشاهده جزئیات معامله</button>
    </div>

  </div>

</div>

<!-- Right Column: Timeline + Support (In main for desktop, separate for mobile; we place below for now for ease of layout with grid swap) -->
<div class="st-main" style="padding-top:0; margin-top:-24px;">
  <div style="grid-column:3;"></div>
  <div class="st-right" style="margin-top:-24px;">

    <!-- Timeline -->
    <div class="st-card st-timeline-card">
      <div class="st-timeline-card__title"><i class="bi bi-list-check"></i> وضعیت معامله</div>
      <div class="st-timeline">
        <?php
        $timelineSteps = [
            1 => 'پیشنهاد ثبت شد',
            2 => 'پیشنهاد پذیرفته شد',
            3 => 'کارمزد پرداخت شد',
            4 => 'مابه‌التفاوت پرداخت شد',
            5 => 'کالا آماده ارسال',
            6 => 'کالا ارسال شد',
            7 => 'کالا تحویل داده شد',
            8 => 'تایید نهایی',
            9 => 'پایان معامله'
        ];
        // Calculate current step:
        $currentStep = null;
        foreach (array_reverse($timelineSteps, true) as $id => $label) {
            if (get_step_status($id, $trade, $contract, (bool)$myReview) === 'current') {
                $currentStep = $id; break;
            } elseif (get_step_status($id, $trade, $contract, (bool)$myReview) === 'completed' && !$currentStep) {
                $currentStep = $id + 1;
            }
        }
        if (!$currentStep) $currentStep = 3;
        ?>
        <?php foreach ($timelineSteps as $id => $label):
          $status = get_step_status($id, $trade, $contract, (bool)$myReview);
          $class = '';
          if ($status === 'completed') $class = 'st-timeline-item--done';
          if ($id === $currentStep) $class = 'st-timeline-item--current';
        ?>
          <div class="st-timeline-item <?= $class ?>">
            <div class="st-timeline-item__dot"></div>
            <div class="st-timeline-item__text">
              <i class="bi bi-check"></i> <?= $label ?>
              <?php if ($id === $currentStep): ?>
                <span class="st-timeline-item__badge">در حال انجام</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Support Card -->
    <div class="st-card st-support-card">
      <div class="st-support-card__icon"><i class="bi bi-headset"></i></div>
      <div class="st-support-card__title">نیاز به کمک دارید؟</div>
      <div class="st-support-card__desc">تیم پشتیبانی سواپین آماده پاسخگویی است.</div>
      <a href="<?= APP_URL ?>/support" class="st-support-card__btn">تماس با پشتیبانی</a>
    </div>

  </div>
</div>

<!-- Bottom Section: Shipping & Payment Methods -->
<div class="st-bottom">
  <div>
    <div class="st-section-title">روش ارسال</div>
    <div class="st-option-cards">
      <div class="st-option st-option--active">
        <div style="display:flex; justify-content: space-between; align-items: start; width:100%; gap:10px;">
          <div class="st-option__icon"><i class="bi bi-lightning-charge"></i></div>
          <span class="st-option__tag">پیشنهاد ویژه</span>
        </div>
        <div class="st-option__name">پیک فوری</div>
        <div class="st-option__meta">تحویل کمتر از ۲ ساعت</div>
      </div>
      <div class="st-option">
        <div class="st-option__icon"><i class="bi bi-box-seam"></i></div>
        <div class="st-option__name">پست پیشتاز</div>
        <div class="st-option__meta">تحویل ۳ تا ۵ روز کاری</div>
      </div>
      <div class="st-option">
        <div class="st-option__icon"><i class="bi bi-shield-check"></i></div>
        <div class="st-option__name">ارسال امن سواپین</div>
        <div class="st-option__meta">با بیمه کامل</div>
      </div>
    </div>
  </div>
  <div>
    <div class="st-section-title">روش پرداخت اختلاف</div>
    <div class="st-option-cards">
      <div class="st-option st-option--active">
        <div class="st-option__icon"><i class="bi bi-cash-stack"></i></div>
        <div class="st-option__name">پرداخت نقدی</div>
        <div class="st-option__meta">از کیف پول شما</div>
      </div>
      <div class="st-option">
        <div class="st-option__icon"><i class="bi bi-calendar-check"></i></div>
        <div class="st-option__name">پرداخت اقساطی (BNPL)</div>
        <div class="st-option__meta">در ۳ قسط</div>
      </div>
      <div class="st-option">
        <div class="st-option__icon"><i class="bi bi-handbag"></i></div>
        <div class="st-option__name">پرداخت هنگام تحویل</div>
        <div class="st-option__meta">به صورت حضوری</div>
      </div>
    </div>
  </div>
</div>

<?php if ($success): ?>
  <div style="position:fixed; top:100px; right:20px; z-index: 300; background:#dcfce7; color:#16a34a; padding:12px 20px; border-radius:12px; font-weight:800; box-shadow:0 8px 24px rgba(0,0,0,.08);">
    <i class="bi bi-check-circle"></i> <?= h($success) ?>
  </div>
<?php endif; ?>
<?php if ($error || isset($_SESSION['error'])): ?>
  <div style="position:fixed; top:100px; right:20px; z-index: 300; background:#fee2e2; color:#dc2626; padding:12px 20px; border-radius:12px; font-weight:800; box-shadow:0 8px 24px rgba(0,0,0,.08);">
    <i class="bi bi-exclamation-circle"></i> <?= h($error ?: $_SESSION['error']) ?>
  </div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<script>
  // Scroll chat to bottom on load
  const chat = document.getElementById('chat-messages');
  if (chat) chat.scrollTop = chat.scrollHeight;
</script>

</body>
</html>
