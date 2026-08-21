<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$offerId = (int) ($_GET['id'] ?? 0);
$tradeId = (int) ($_GET['trade'] ?? 0);

$offer = store_swap_offer_fetch($offerId);
if (!$offer || (int) $offer['from_user_id'] !== (int) $user['id']) {
    header('Location: ' . APP_URL . '/store-offers/');
    exit;
}

render_head('معاوضه تکمیل شد', '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">

<div class="ex-page">
  <div class="ex-container">

    <div class="ex-hero">
      <div class="ex-hero__icon ex-hero__icon--gift">
        <i class="bi bi-gift-fill"></i>
      </div>
      <h1 class="ex-hero__title">معاوضه با موفقیت آغاز شد!</h1>
      <p class="ex-hero__subtitle">
        معامله شما در سیستم سواپین به صورت رسمی ثبت شد.
        <br>
        حالا می‌توانید از طریق «اتاق امن معامله» در روند ارسال و دریافت کالا شرکت کنید.
      </p>

      <div class="ex-meta-list">
        <div class="ex-meta-row">
          <span><i class="bi bi-hash" style="margin-left:6px"></i>شماره پیشنهاد</span>
          <strong>#<?= $offerId ?></strong>
        </div>
        <?php if ($tradeId): ?>
        <div class="ex-meta-row">
          <span><i class="bi bi-diagram-3-fill" style="margin-left:6px"></i>شماره معامله</span>
          <strong style="color:var(--ex-gold-dark);font-size:1.125rem">#<?= $tradeId ?></strong>
        </div>
        <?php endif; ?>
        <div class="ex-meta-row">
          <span><i class="bi bi-shop-window" style="margin-left:6px"></i>فروشگاه طرف معامله</span>
          <strong><?= h($offer['store_name'] ?? 'فروشگاه') ?></strong>
        </div>
        <div class="ex-meta-row">
          <span><i class="bi bi-arrow-left-right" style="margin-left:6px"></i>محصول فروشگاه</span>
          <strong><?= h($offer['store_listing_title']) ?></strong>
        </div>
        <div class="ex-meta-row">
          <span><i class="bi bi-cash-stack" style="margin-left:6px"></i>مبلغ تکمیلی توافق‌شده</span>
          <strong><?= fmt_credit((float) $offer['effective_credit']) ?></strong>
        </div>
      </div>

      <div class="ex-header__status ex-status--success" style="justify-content:center;margin:16px auto 0">
        <span class="ex-status__dot"></span>
        معامله در حال انجام
      </div>

      <div class="ex-actions" style="margin-top:28px">
        <?php if ($tradeId): ?>
        <a href="<?= APP_URL ?>/trades/view.php?id=<?= $tradeId ?>" class="ex-btn ex-btn--cta ex-btn--lg" data-navigate="<?= APP_URL ?>/trades/view.php?id=<?= $tradeId ?>">
          <i class="bi bi-door-open-fill"></i>
          ورود به اتاق امن معامله
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/store-offers/" class="ex-btn ex-btn--primary" data-navigate="<?= APP_URL ?>/store-offers/">
          <i class="bi bi-list-ul"></i>
          مشاهده همه معاوضه‌های من
        </a>
        <a href="<?= APP_URL ?>/shops" class="ex-btn ex-btn--swap" data-navigate="<?= APP_URL ?>/shops">
          <i class="bi bi-arrow-left-right"></i>
          ادامه گشت‌وگذار و معاوضه‌های دیگر
        </a>
      </div>

      <div class="ex-alert ex-alert--info" style="margin-top:28px;text-align:right;max-width:560px;margin-right:auto;margin-left:auto">
        <i class="bi bi-info-circle-fill ex-alert__icon"></i>
        تیم پشتیبانی سواپین در تمام مراحل معامله همراه شماست. در صورت بروز هرگونه سوال یا مشکل، از طریق اتاق معامله تیکت پشتیبانی ثبت کنید.
      </div>
    </div>

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
