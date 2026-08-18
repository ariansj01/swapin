<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$offerId = (int) ($_GET['id'] ?? 0);
$error = '';
$success = '';

$offer = store_swap_offer_fetch($offerId);
if (!$offer || !store_swap_offer_can_access($offer, $user)) {
    http_response_code(404);
    render_head('پیشنهاد یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main class="section"><div class="container"><div class="empty-state"><h1>پیشنهاد یافت نشد</h1></div></div></main>';
    render_footer();
    exit;
}

$isStore = store_swap_offer_is_store($offer, $user);
$isBuyer = (int) $user['id'] === (int) $offer['from_user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($action === 'send_message') {
        $result = store_swap_offer_send_message($offerId, $user, clean($_POST['body'] ?? ''));
        $error = $result['error'] ?? '';
    } elseif ($action === 'accept_counter' && $isBuyer) {
        $result = store_swap_offer_accept_counter($offerId, $user);
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            header('Location: ' . APP_URL . '/store-offers/confirm.php?id=' . $offerId);
            exit;
        }
    } elseif ($action === 'reject') {
        $result = store_swap_offer_reject($offerId, $user, clean($_POST['reason'] ?? ''));
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $success = 'پیشنهاد رد شد.';
        }
    } elseif ($action === 'counter' && $isStore) {
        $cash = (float) preg_replace('/[^\d.]/', '', (string) ($_POST['counter_cash'] ?? '0'));
        $result = store_swap_offer_send_counter($offerId, $user, $cash, clean($_POST['counter_message'] ?? ''));
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $success = 'پیشنهاد جدید ارسال شد.';
        }
    } elseif ($action === 'store_accept' && $isStore) {
        $result = store_swap_offer_store_accept($offerId, $user, clean($_POST['message'] ?? ''));
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            header('Location: ' . APP_URL . '/store-offers/view.php?id=' . $offerId . '&accepted=1');
            exit;
        }
    }

    $offer = store_swap_offer_fetch($offerId);
}

$messages = store_swap_offer_messages($offerId);
$canChat = !in_array($offer['status'], ['rejected', 'cancelled', 'completed'], true);

render_head('پیشنهاد #' . $offerId, '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store-offers.css?v=<?= @filemtime(__DIR__ . '/../src/css/store-offers.css') ?: time() ?>">

<div class="section-sm">
  <div class="so-page">
    <div class="so-head">
      <a href="<?= $isBuyer ? APP_URL . '/store-offers/' : APP_URL . '/store/?tab=requests&subtab=swap-requests' ?>" class="so-back"><i class="bi bi-arrow-right"></i> بازگشت</a>
      <h1 class="so-title">پیشنهاد #<?= $offerId ?></h1>
      <span class="so-status"><i class="bi bi-circle-fill" style="font-size:.5rem"></i> <?= h($offer['status_label']) ?></span>
    </div>

    <?php if ($error): ?><div class="so-alert so-alert--error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="so-alert so-alert--info"><?= h($success) ?></div><?php endif; ?>
    <?php if (isset($_GET['accepted'])): ?><div class="so-alert so-alert--info">پیشنهاد پذیرفته شد. منتظر تأیید نهایی کاربر باشید.</div><?php endif; ?>

    <div class="so-card">
      <div class="so-swap-row">
        <div>
          <div class="so-card__label">کالای فروشگاه</div>
          <?php if ($offer['store_thumb']): ?>
          <img src="<?= UPLOAD_URL . h($offer['store_thumb']) ?>" alt="" class="so-item__thumb" style="margin-bottom:8px">
          <?php endif; ?>
          <div class="so-item__title"><?= h($offer['store_listing_title']) ?></div>
          <div class="so-item__price"><?= fmt_credit((float) $offer['store_listing_value']) ?></div>
        </div>
        <div class="so-swap-icon"><i class="bi bi-arrow-left-right"></i></div>
        <div>
          <div class="so-card__label">کالای <?= $isBuyer ? 'شما' : 'مشتری' ?></div>
          <?php if ($offer['user_thumb']): ?>
          <img src="<?= UPLOAD_URL . h($offer['user_thumb']) ?>" alt="" class="so-item__thumb" style="margin-bottom:8px">
          <?php endif; ?>
          <div class="so-item__title"><?= h($offer['user_listing_title'] ?? '—') ?></div>
          <div class="so-item__price"><?= fmt_credit((float) ($offer['user_listing_value'] ?? 0)) ?></div>
        </div>
      </div>
      <div class="so-cash-box" style="margin-top:16px">
        <div class="so-cash-row">
          <span>مبلغ تکمیلی<?= ($offer['status'] ?? '') === 'counter_offered' ? ' (پیشنهاد فروشگاه)' : '' ?></span>
          <strong><?= fmt_credit((float) $offer['effective_credit']) ?></strong>
        </div>
      </div>
    </div>

    <?php if ($isBuyer && ($offer['status'] ?? '') === 'accepted'): ?>
    <div class="so-actions">
      <a href="<?= APP_URL ?>/store-offers/confirm.php?id=<?= $offerId ?>" class="so-btn so-btn--gold">تأیید نهایی و شروع فرآیند معاوضه</a>
    </div>
    <?php endif; ?>

    <?php if ($isBuyer && ($offer['status'] ?? '') === 'counter_offered'): ?>
    <div class="so-card">
      <div class="so-card__label">پیشنهاد جدید فروشگاه</div>
      <p>مابه‌التفاوت پیشنهادی: <strong><?= fmt_credit((float) ($offer['counter_offer_credit'] ?? 0)) ?></strong></p>
      <div class="so-actions">
        <form method="POST" style="display:contents">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="accept_counter">
          <button type="submit" class="so-btn so-btn--gold">قبول پیشنهاد</button>
        </form>
        <form method="POST" style="display:contents">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reject">
          <button type="submit" class="so-btn so-btn--danger">رد پیشنهاد</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isStore && in_array($offer['status'], ['pending', 'negotiating'], true)): ?>
    <div class="so-card">
      <div class="so-card__label">اقدام فروشگاه</div>
      <form method="POST" class="mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="store_accept">
        <textarea name="message" class="form-control mb-2" rows="2" placeholder="پیام پذیرش (اختیاری)"></textarea>
        <button type="submit" class="so-btn so-btn--gold">پذیرش پیشنهاد</button>
      </form>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="counter">
        <label class="so-input-label">پیشنهاد جدید — مابه‌التفاوت (تومان)</label>
        <input type="text" name="counter_cash" class="form-control mb-2" inputmode="numeric" placeholder="مثلاً ۳,۰۰۰,۰۰۰">
        <textarea name="counter_message" class="form-control mb-2" rows="2" placeholder="توضیح پیشنهاد جدید"></textarea>
        <button type="submit" class="so-btn so-btn--outline">ارسال پیشنهاد جدید</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="so-card">
      <h2 class="so-title" style="font-size:1rem;margin-bottom:12px"><i class="bi bi-chat-dots"></i> گفت‌وگو درباره معاوضه</h2>
      <div class="so-chat" id="so-chat">
        <?php foreach ($messages as $msg):
          $mine = (int) $msg['user_id'] === (int) $user['id'];
          $isStoreMsg = ($msg['seller_type'] ?? '') === 'store';
          $who = $isStoreMsg ? ($msg['store_name'] ?: 'فروشگاه') : ($msg['user_name'] ?: 'کاربر');
          $cls = 'so-msg';
          if (($msg['type'] ?? '') === 'system') {
              $cls .= ' so-msg--system';
          } elseif (($msg['type'] ?? '') === 'counter_offer') {
              $cls .= ' so-msg--counter';
          } elseif ($mine) {
              $cls .= ' so-msg--mine';
          } else {
              $cls .= ' so-msg--theirs';
          }
        ?>
        <div class="<?= $cls ?>">
          <?php if (($msg['type'] ?? '') !== 'system'): ?>
          <div class="so-msg__who"><?= h($who) ?></div>
          <?php endif; ?>
          <?= nl2br(h($msg['body'])) ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($canChat): ?>
      <form method="POST" class="so-chat-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_message">
        <textarea name="body" rows="2" placeholder="پیام خود را بنویسید..." required></textarea>
        <button type="submit" class="so-btn so-btn--primary" style="width:auto;padding:10px 16px"><i class="bi bi-send"></i></button>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($canChat && !$isStore && ($offer['status'] ?? '') !== 'counter_offered'): ?>
    <form method="POST" onsubmit="return confirm('پیشنهاد رد شود؟')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <button type="submit" class="so-btn so-btn--danger">لغو / رد پیشنهاد</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>document.getElementById('so-chat')?.scrollTo(0, 99999);</script>
<?php render_footer(); ?>
