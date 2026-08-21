<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$offerId = (int) ($_GET['id'] ?? 0);
$offer = store_swap_offer_fetch($offerId);

if (!$offer || (int) $offer['from_user_id'] !== (int) $user['id']) {
    header('Location: ' . APP_URL . '/store-offers/');
    exit;
}

render_head('پیشنهاد ارسال شد', '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">

<div class="ex-page">
  <div class="ex-container">

    <div class="ex-hero">
      <div class="ex-hero__icon ex-hero__icon--send">
        <i class="bi bi-send-check-fill"></i>
      </div>
      <h1 class="ex-hero__title">پیشنهاد شما ارسال شد!</h1>
      <p class="ex-hero__subtitle">فروشگاه در حال بررسی پیشنهاد شماست. پاسخ را از طریق همین صفحه یا پیام‌ها دریافت خواهید کرد.</p>

      <div class="ex-meta-list">
        <div class="ex-meta-row">
          <span><i class="bi bi-hash" style="margin-left:6px"></i>شماره پیشنهاد</span>
          <strong>#<?= (int) $offer['id'] ?></strong>
        </div>
        <div class="ex-meta-row">
          <span><i class="bi bi-shop" style="margin-left:6px"></i>فروشگاه</span>
          <strong><?= h($offer['store_name'] ?? $offer['store_user_name'] ?? 'فروشگاه') ?></strong>
        </div>
        <div class="ex-meta-row">
          <span><i class="bi bi-clock" style="margin-left:6px"></i>زمان ارسال</span>
          <strong><?= timeago($offer['created_at']) ?></strong>
        </div>
        <div class="ex-meta-row">
          <span><i class="bi bi-cash-stack" style="margin-left:6px"></i>مبلغ تکمیلی</span>
          <strong><?= fmt_credit((float) $offer['effective_credit']) ?></strong>
        </div>
      </div>

      <div class="ex-header__status ex-status--pending" style="justify-content:center;margin:8px auto 0;">
        <span class="ex-status__dot"></span>
        <?= h($offer['status_label']) ?>
      </div>

      <div class="ex-actions">
        <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" class="ex-btn ex-btn--primary ex-btn--lg" data-navigate="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>">
          <i class="bi bi-chat-dots-fill"></i>
          مشاهده و پیگیری پیشنهاد
        </a>
        <a href="<?= APP_URL ?>/store-offers/" class="ex-btn ex-btn--outline" data-navigate="<?= APP_URL ?>/store-offers/">
          <i class="bi bi-list-ul"></i>
          مشاهده همه معاوضه‌های من
        </a>
        <a href="<?= APP_URL ?>/shops" class="ex-btn ex-btn--swap" data-navigate="<?= APP_URL ?>/shops">
          <i class="bi bi-arrow-left-right"></i>
          مرور فروشگاه‌های دیگر
        </a>
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
