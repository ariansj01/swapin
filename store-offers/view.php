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
    echo '<main class="ex-page"><div class="ex-container"><div class="ex-card"><div class="ex-empty"><div class="ex-empty__icon"><i class="bi bi-exclamation-circle"></i></div><h3 class="ex-empty__title">پیشنهاد یافت نشد</h3><p class="ex-empty__desc">این پیشنهاد ممکن است حذف شده یا به شما تعلق نداشته باشد.</p><a href="' . APP_URL . '/store-offers/" class="ex-btn ex-btn--primary" style="max-width:280px;margin:0 auto" data-navigate="' . APP_URL . '/store-offers/">بازگشت به لیست معاوضه‌ها</a></div></div></div></main>';
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

$statusRaw = $offer['status'] ?? '';
$statusClass = 'ex-status--info';
if (in_array($statusRaw, ['pending', 'negotiating'], true)) $statusClass = 'ex-status--pending';
elseif (in_array($statusRaw, ['accepted'], true)) $statusClass = 'ex-status--success';
elseif (in_array($statusRaw, ['rejected', 'cancelled'], true)) $statusClass = 'ex-status--rejected';
elseif ($statusRaw === 'counter_offered') $statusClass = 'ex-status--counter';
elseif (in_array($statusRaw, ['completed', 'finalized'], true)) $statusClass = 'ex-status--completed';

$storeValue = (float) ($offer['store_listing_value'] ?? 0);
$userValue = (float) ($offer['user_listing_value'] ?? 0);

render_head('پیشنهاد #' . $offerId, '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">

<div class="ex-page ex-page--wide">
  <div class="ex-container" style="max-width:960px">

    <div class="ex-header">
      <a href="<?= $isBuyer ? APP_URL . '/store-offers/' : APP_URL . '/store/?tab=requests&subtab=swap-requests' ?>" class="ex-header__back">
        <i class="bi bi-arrow-right"></i>
        بازگشت
      </a>
      <div class="ex-header__row">
        <div>
          <h1 class="ex-header__title">پیشنهاد معاوضه #<?= $offerId ?></h1>
          <p class="ex-header__subtitle">
            <i class="bi bi-shop-window" style="margin-left:4px"></i>
            <?= h($offer['store_name'] ?? $offer['store_user_name'] ?? 'فروشگاه') ?>
            <span style="margin:0 8px;color:var(--ex-border)">·</span>
            <i class="bi bi-clock" style="margin-left:4px"></i>
            <?= timeago($offer['created_at']) ?>
          </p>
        </div>
        <div class="ex-header__status <?= $statusClass ?>">
          <span class="ex-status__dot"></span>
          <?= h($offer['status_label']) ?>
        </div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="ex-alert ex-alert--danger">
      <i class="bi bi-exclamation-circle-fill ex-alert__icon"></i>
      <?= h($error) ?>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="ex-alert ex-alert--success">
      <i class="bi bi-check-circle-fill ex-alert__icon"></i>
      <?= h($success) ?>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['accepted'])): ?>
    <div class="ex-alert ex-alert--success">
      <i class="bi bi-check-circle-fill ex-alert__icon"></i>
      پیشنهاد پذیرفته شد. منتظر تأیید نهایی کاربر باشید.
    </div>
    <?php endif; ?>

    <div class="ex-card">
      <div class="ex-swap-grid">
        <div class="ex-swap-col">
          <div class="ex-swap-col__label">
            <i class="bi bi-person" style="margin-left:4px"></i>
            کالای <?= $isBuyer ? 'شما' : 'مشتری' ?>
          </div>
          <?php if (!empty($offer['user_thumb'])): ?>
          <img src="<?= UPLOAD_URL . h($offer['user_thumb']) ?>" alt="" class="ex-swap-col__thumb">
          <?php else: ?>
          <div class="ex-swap-col__thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ex-muted);font-size:1.75rem">
            <i class="bi bi-box"></i>
          </div>
          <?php endif; ?>
          <div class="ex-swap-col__title"><?= h($offer['user_listing_title'] ?? '—') ?></div>
          <div class="ex-swap-col__price"><?= fmt_credit($userValue) ?></div>
        </div>
        <div class="ex-swap-icon">
          <div class="ex-swap-icon__wrapper">
            <i class="bi bi-arrow-left-right"></i>
          </div>
        </div>
        <div class="ex-swap-col">
          <div class="ex-swap-col__label">
            <i class="bi bi-shop" style="margin-left:4px"></i>
            محصول فروشگاه
          </div>
          <?php if (!empty($offer['store_thumb'])): ?>
          <img src="<?= UPLOAD_URL . h($offer['store_thumb']) ?>" alt="" class="ex-swap-col__thumb">
          <?php else: ?>
          <div class="ex-swap-col__thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ex-muted);font-size:1.75rem">
            <i class="bi bi-box"></i>
          </div>
          <?php endif; ?>
          <div class="ex-swap-col__title"><?= h($offer['store_listing_title']) ?></div>
          <div class="ex-swap-col__price"><?= fmt_credit($storeValue) ?></div>
        </div>
      </div>

      <div class="ex-cash-box" style="margin-top:20px">
        <div class="ex-cash-row">
          <span><i class="bi bi-wallet2" style="margin-left:6px"></i>مبلغ تکمیلی <?= ($offer['status'] ?? '') === 'counter_offered' ? '(پیشنهاد فروشگاه)' : '' ?></span>
          <strong style="font-size:1.125rem;color:var(--ex-navy)">
            <?= fmt_credit((float) $offer['effective_credit']) ?>
          </strong>
        </div>
        <?php if ((float) ($offer['counter_offer_credit'] ?? 0) > 0 && ($offer['status'] ?? '') === 'counter_offered'): ?>
        <div class="ex-cash-row">
          <span style="color:var(--ex-warning);font-weight:700">
            <i class="bi bi-lightning-charge" style="margin-left:4px"></i>
            مبلغ پیشنهادی جدید فروشگاه
          </span>
          <strong style="color:var(--ex-gold-dark);font-size:1.0625rem">
            <?= fmt_credit((float) ($offer['counter_offer_credit'] ?? 0)) ?>
          </strong>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isBuyer && ($offer['status'] ?? '') === 'accepted'): ?>
    <div class="ex-sticky-bar ex-visible-mobile">
      <div class="ex-sticky-bar__inner">
        <a href="<?= APP_URL . '/store-offers/confirm.php?id=' . $offerId ?>" class="ex-btn ex-btn--cta ex-btn--lg" data-navigate="<?= APP_URL . '/store-offers/confirm.php?id=' . $offerId ?>">
          <i class="bi bi-hand-thumbs-up-fill"></i>
          تأیید نهایی و شروع معاوضه
        </a>
      </div>
    </div>

    <div class="ex-card ex-hidden-mobile" style="background:linear-gradient(135deg,rgba(16,185,129,.06) 0%,rgba(59,130,246,.04) 100%);border-color:rgba(16,185,129,.3)">
      <div class="ex-flex-between">
        <div>
          <div class="ex-card__title" style="margin-bottom:4px"><i class="bi bi-check-circle-fill" style="color:var(--ex-success);margin-left:6px"></i>پیشنهاد تایید شد!</div>
          <p class="ex-card__subtitle" style="margin-bottom:0">فروشگاه پیشنهاد شما را پذیرفته است. برای ادامه فرآیند، روی دکمه زیر کلیک کنید.</p>
        </div>
        <a href="<?= APP_URL . '/store-offers/confirm.php?id=' . $offerId ?>" class="ex-btn ex-btn--cta" style="width:auto;min-width:260px" data-navigate="<?= APP_URL . '/store-offers/confirm.php?id=' . $offerId ?>">
          <i class="bi bi-hand-thumbs-up-fill"></i>
          تأیید نهایی و شروع
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isBuyer && ($offer['status'] ?? '') === 'counter_offered'): ?>
    <div class="ex-counter">
      <div class="ex-counter__icon">
        <i class="bi bi-lightning-charge-fill"></i>
      </div>
      <div class="ex-counter__title">پیشنهاد جدید فروشگاه</div>
      <p style="color:var(--ex-muted);font-size:.875rem;margin:0 0 8px">
        فروشگاه مبلغ تکمیلی جدیدی پیشنهاد داده است:
      </p>
      <div class="ex-counter__amount">
        <?= fmt_credit((float) ($offer['counter_offer_credit'] ?? 0)) ?>
      </div>
      <?php if (!empty($offer['counter_offer_message'])): ?>
      <div style="background:#fff;border-radius:var(--ex-radius-sm);padding:12px 14px;margin-bottom:16px;border:1px solid #FDE68A;font-size:.875rem;color:var(--ex-text);line-height:1.6">
        <i class="bi bi-chat-square-text" style="margin-left:6px;color:var(--ex-gold-dark)"></i>
        <?= nl2br(h($offer['counter_offer_message'])) ?>
      </div>
      <?php endif; ?>

      <div class="ex-sticky-bar ex-visible-mobile" style="position:static;box-shadow:none;padding:0;margin-top:16px;border:none;z-index:auto">
        <div class="ex-sticky-bar__inner" style="max-width:100%">
          <div class="ex-sticky-bar__row">
            <form method="POST" style="flex:1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="accept_counter">
              <button type="submit" class="ex-btn ex-btn--cta ex-btn--lg" style="width:100%">
                <i class="bi bi-check-lg"></i>
                قبول پیشنهاد
              </button>
            </form>
            <button type="button" class="ex-btn ex-btn--danger" id="rejectBtnMobile" style="flex:1">
              <i class="bi bi-x-lg"></i>
              رد پیشنهاد
            </button>
          </div>
        </div>
      </div>

      <div class="ex-action-grid ex-hidden-mobile" style="grid-template-columns:1fr 1fr;margin-top:16px">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="accept_counter">
          <button type="submit" class="ex-btn ex-btn--cta" style="width:100%">
            <i class="bi bi-check-lg"></i>
            قبول پیشنهاد جدید
          </button>
        </form>
        <button type="button" class="ex-btn ex-btn--danger" id="rejectBtn">
          <i class="bi bi-x-lg"></i>
          رد پیشنهاد
        </button>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isStore && in_array($offer['status'], ['pending', 'negotiating'], true)): ?>
    <div class="ex-card">
      <div class="ex-card__label" style="margin-bottom:16px"><i class="bi bi-gear-fill" style="margin-left:6px;color:var(--ex-gold-dark)"></i>اقدامات فروشگاه</div>

      <form method="POST" style="margin-bottom:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="store_accept">
        <div class="ex-form-group" style="margin-bottom:12px">
          <label class="ex-form-label">پیام پذیرش (اختیاری)</label>
          <textarea name="message" class="form-control" rows="2" placeholder="مثلاً: آماده ارسال کالا هستم..."
                    style="padding:12px 14px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit;font-size:.9375rem"></textarea>
        </div>
        <button type="submit" class="ex-btn ex-btn--cta" style="width:100%">
          <i class="bi bi-check-circle-fill"></i>
          پذیرش پیشنهاد مشتری
        </button>
      </form>

      <div style="border-top:1px solid var(--ex-border-soft);padding-top:20px;margin-top:8px">
        <div class="ex-card__label" style="margin-bottom:12px;color:var(--ex-warning);font-weight:700">
          <i class="bi bi-lightning-charge" style="margin-left:4px"></i>
          یا پیشنهاد مبلغ جدید (Counter Offer)
        </div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="counter">
          <div class="ex-form-group">
            <label class="ex-form-label">مابه‌التفاوت پیشنهادی (تومان)</label>
            <div class="ex-input-amount">
              <input type="text" name="counter_cash" class="form-control" inputmode="numeric" placeholder="مثلاً ۳,۰۰۰,۰۰۰"
                     style="padding:14px 16px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-size:1rem;font-weight:700;font-family:inherit">
              <div class="ex-input-amount__suffix">تومان</div>
            </div>
          </div>
          <div class="ex-form-group">
            <label class="ex-form-label">توضیح پیشنهاد جدید</label>
            <textarea name="counter_message" class="form-control" rows="2" placeholder="دلیل تغییر مبلغ را توضیح دهید..."
                      style="padding:12px 14px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit;font-size:.9375rem"></textarea>
          </div>
          <button type="submit" class="ex-btn ex-btn--outline" style="width:100%">
            <i class="bi bi-send"></i>
            ارسال پیشنهاد جدید
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="ex-chat">
      <div class="ex-chat__header">
        <i class="bi bi-chat-dots-fill ex-chat__icon"></i>
        <h3 class="ex-chat__title">گفت‌وگو درباره معاوضه</h3>
        <div style="margin-right:auto;font-size:.75rem;color:var(--ex-muted)">
          <i class="bi bi-circle-fill" style="color:var(--ex-success);font-size:.5rem;margin-left:4px"></i>
          آنلاین
        </div>
      </div>
      <div class="ex-chat__body" id="ex-chat-body">
        <?php if (!$messages): ?>
        <div style="text-align:center;padding:24px 12px;color:var(--ex-muted);font-size:.875rem">
          <i class="bi bi-chat-square-text" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.5"></i>
          هنوز پیامی ارسال نشده است.
        </div>
        <?php else: ?>
        <?php foreach ($messages as $msg):
          $mine = (int) $msg['user_id'] === (int) $user['id'];
          $isStoreMsg = ($msg['seller_type'] ?? '') === 'store';
          $who = $isStoreMsg ? ($msg['store_name'] ?: 'فروشگاه') : ($msg['user_name'] ?: 'کاربر');
          $cls = 'ex-msg';
          if (($msg['type'] ?? '') === 'system') {
              $cls .= ' ex-msg--system';
          } elseif (($msg['type'] ?? '') === 'counter_offer') {
              $cls .= ' ex-msg--counter';
          } elseif ($mine) {
              $cls .= ' ex-msg--mine';
          } else {
              $cls .= ' ex-msg--theirs';
          }
        ?>
        <div class="<?= $cls ?>">
          <?php if (($msg['type'] ?? '') !== 'system'): ?>
          <div class="ex-msg__sender"><?= h($who) ?></div>
          <?php endif; ?>
          <?= nl2br(h($msg['body'])) ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($canChat): ?>
      <form method="POST" class="ex-chat__input">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_message">
        <textarea name="body" rows="2" placeholder="پیام خود را بنویسید..." required style="resize:vertical"></textarea>
        <button type="submit" class="ex-chat__send" aria-label="ارسال پیام">
          <i class="bi bi-send-fill"></i>
        </button>
      </form>
      <?php else: ?>
      <div style="padding:14px 20px;text-align:center;background:var(--ex-bg);border-top:1px solid var(--ex-border);font-size:.8125rem;color:var(--ex-muted)">
        <i class="bi bi-lock-fill" style="margin-left:6px"></i>
        گفت‌وگو برای این پیشنهاد بسته شده است.
      </div>
      <?php endif; ?>
    </div>

    <?php if ($canChat && !$isStore && ($offer['status'] ?? '') !== 'counter_offered'): ?>
    <div style="margin-top:16px">
      <button type="button" class="ex-btn ex-btn--outline" id="cancelOfferBtn" style="color:var(--ex-danger);border-color:#FECACA">
        <i class="bi bi-x-circle"></i>
        لغو / رد پیشنهاد
      </button>
    </div>
    <?php endif; ?>

  </div>
</div>

<div class="ex-modal-backdrop" id="rejectModal" role="dialog" aria-modal="true">
  <div class="ex-modal" style="max-width:480px">
    <div class="ex-modal__header">
      <h3 class="ex-modal__title"><i class="bi bi-x-circle" style="color:var(--ex-danger);margin-left:6px"></i>رد پیشنهاد</h3>
      <button type="button" class="ex-modal__close" id="rejectModalClose" aria-label="بستن">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <div class="ex-form-group">
        <label class="ex-form-label">دلیل رد (اختیاری)</label>
        <textarea name="reason" class="form-control" rows="3" placeholder="دلیل خود را بنویسید..."
                  style="padding:12px 14px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit;font-size:.9375rem"></textarea>
      </div>
      <div class="ex-actions" style="margin-top:8px">
        <button type="submit" class="ex-btn ex-btn--danger">
          <i class="bi bi-check-lg"></i>
          تایید رد پیشنهاد
        </button>
        <button type="button" class="ex-btn ex-btn--outline" id="rejectModalCancel">
          انصراف
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('[data-navigate]').forEach(function (el) {
  el.addEventListener('click', function (e) {
    const url = el.getAttribute('data-navigate');
    if (!url) return;
    e.preventDefault();
    window.location.href = url;
  });
});

var chatBody = document.getElementById('ex-chat-body');
if (chatBody) chatBody.scrollTop = chatBody.scrollHeight;

var rejectModal = document.getElementById('rejectModal');
var rejectBtns = [document.getElementById('rejectBtn'), document.getElementById('rejectBtnMobile'), document.getElementById('cancelOfferBtn')];
var rejectCloses = [document.getElementById('rejectModalClose'), document.getElementById('rejectModalCancel')];

rejectBtns.forEach(function(btn) {
  btn?.addEventListener('click', function() { rejectModal.classList.add('is-open'); });
});
rejectCloses.forEach(function(btn) {
  btn?.addEventListener('click', function() { rejectModal.classList.remove('is-open'); });
});
rejectModal?.addEventListener('click', function(e) { if (e.target === rejectModal) rejectModal.classList.remove('is-open'); });
</script>

<?php render_footer(); ?>
