<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$offerId = (int) ($_GET['id'] ?? 0);
$error = '';

$offer = store_swap_offer_fetch($offerId);
if (!$offer || !store_swap_offer_can_access($offer, $user)) {
    header('Location: ' . APP_URL . '/store-offers/');
    exit;
}

if (($offer['status'] ?? '') !== 'accepted') {
    header('Location: ' . APP_URL . '/store-offers/view.php?id=' . $offerId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $result = store_swap_offer_finalize($offerId, $user);
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: ' . APP_URL . '/store-offers/complete.php?id=' . $offerId . '&trade=' . (int) $result['trade_id']);
        exit;
    }
}

render_head('تأیید نهایی معاوضه', '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">

<div class="ex-page">
  <div class="ex-container">

    <div class="ex-header">
      <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" class="ex-header__back">
        <i class="bi bi-arrow-right"></i>
        بازگشت به پیشنهاد
      </a>
      <h1 class="ex-header__title">تأیید نهایی معاوضه</h1>
      <p class="ex-header__subtitle">پیشنهاد معاوضه مورد توافق قرار گرفت. برای شروع رسمی فرآیند، تایید کنید.</p>
    </div>

    <div class="ex-stepper">
      <div class="ex-stepper__progress" style="width:66%"></div>
      <div class="ex-step is-done">
        <div class="ex-step__circle"><i class="bi bi-check2" style="font-size:1.125rem"></i></div>
        <div class="ex-step__label">پیشنهاد اولیه</div>
      </div>
      <div class="ex-step is-done">
        <div class="ex-step__circle"><i class="bi bi-check2" style="font-size:1.125rem"></i></div>
        <div class="ex-step__label">توافق طرفین</div>
      </div>
      <div class="ex-step is-active">
        <div class="ex-step__circle">۳</div>
        <div class="ex-step__label">شروع معامله</div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="ex-alert ex-alert--danger">
      <i class="bi bi-exclamation-circle-fill ex-alert__icon"></i>
      <?= h($error) ?>
    </div>
    <?php endif; ?>

    <div class="ex-card">
      <div class="ex-trade-summary">
        <div class="ex-trade-party">
          <div class="ex-trade-party__avatar">
            <i class="bi bi-person-fill"></i>
          </div>
          <div class="ex-trade-party__name">شما</div>
          <div class="ex-trade-party__meta">فروشنده کالای شما</div>
        </div>
        <div class="ex-trade-divider">
          <div class="ex-trade-divider__icon">
            <i class="bi bi-handshake-fill"></i>
          </div>
          <div class="ex-trade-divider__label">معامله رسمی</div>
        </div>
        <div class="ex-trade-party">
          <div class="ex-trade-party__avatar" style="background:var(--ex-gold-dark);color:#fff">
            <i class="bi bi-shop"></i>
          </div>
          <div class="ex-trade-party__name"><?= h($offer['store_name'] ?? 'فروشگاه') ?></div>
          <div class="ex-trade-party__meta">فروشگاه معتبر</div>
        </div>
      </div>

      <div class="ex-card__divider"></div>

      <div class="ex-swap-grid">
        <div class="ex-swap-col">
          <div class="ex-swap-col__label">کالای شما</div>
          <?php if (!empty($offer['user_thumb'])): ?>
          <img src="<?= UPLOAD_URL . h($offer['user_thumb']) ?>" alt="" class="ex-swap-col__thumb">
          <?php else: ?>
          <div class="ex-swap-col__thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ex-muted);font-size:1.75rem">
            <i class="bi bi-box"></i>
          </div>
          <?php endif; ?>
          <div class="ex-swap-col__title"><?= h($offer['user_listing_title'] ?? '—') ?></div>
          <div class="ex-swap-col__price"><?= fmt_credit((float) ($offer['user_listing_value'] ?? 0)) ?></div>
        </div>
        <div class="ex-swap-icon">
          <div class="ex-swap-icon__wrapper">
            <i class="bi bi-arrow-left-right"></i>
          </div>
        </div>
        <div class="ex-swap-col">
          <div class="ex-swap-col__label">محصول فروشگاه</div>
          <?php if (!empty($offer['store_thumb'])): ?>
          <img src="<?= UPLOAD_URL . h($offer['store_thumb']) ?>" alt="" class="ex-swap-col__thumb">
          <?php else: ?>
          <div class="ex-swap-col__thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ex-muted);font-size:1.75rem">
            <i class="bi bi-box"></i>
          </div>
          <?php endif; ?>
          <div class="ex-swap-col__title"><?= h($offer['store_listing_title']) ?></div>
          <div class="ex-swap-col__price"><?= fmt_credit((float) ($offer['store_listing_value'] ?? 0)) ?></div>
        </div>
      </div>

      <div class="ex-cash-box ex-cash-box--highlight" style="margin-top:20px">
        <div class="ex-cash-total" style="border-top:none;padding-top:0;margin-top:0">
          <span><i class="bi bi-wallet2" style="margin-left:6px"></i>مبلغ تکمیلی قابل پرداخت</span>
          <strong><?= fmt_credit((float) $offer['effective_credit']) ?></strong>
        </div>
      </div>

      <div class="ex-alert ex-alert--info" style="margin-top:16px;margin-bottom:0">
        <i class="bi bi-shield-check ex-alert__icon"></i>
        با کلیک روی «تأیید و شروع»، معامله به صورت رسمی ثبت می‌شود و شما وارد محیط امن «اتاق معامله» می‌شوید. از این لحظه به بعد، تمامی مراحل زیر نظر پشتیبانی سواپین انجام می‌شود.
      </div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>

      <div class="ex-sticky-bar ex-visible-mobile">
        <div class="ex-sticky-bar__inner">
          <div class="ex-sticky-bar__row">
            <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" class="ex-btn ex-btn--outline" data-navigate="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>">
              بازگشت
            </a>
            <button type="submit" class="ex-btn ex-btn--cta ex-btn--lg">
              <i class="bi bi-handshake-fill"></i>
              تأیید و شروع معامله
            </button>
          </div>
        </div>
      </div>

      <div class="ex-actions ex-actions--row-sm ex-hidden-mobile">
        <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" class="ex-btn ex-btn--outline" data-navigate="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" style="flex:1">
          <i class="bi bi-arrow-right"></i>
          بازگشت
        </a>
        <button type="submit" class="ex-btn ex-btn--cta ex-btn--lg" style="flex:2">
          <i class="bi bi-handshake-fill"></i>
          تأیید نهایی و شروع فرآیند معاوضه
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
</script>

<?php render_footer(); ?>
