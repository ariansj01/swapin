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
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store-offers.css?v=<?= @filemtime(__DIR__ . '/../src/css/store-offers.css') ?: time() ?>">

<div class="section-sm">
  <div class="so-page">
    <div class="so-success">
      <div class="so-success__icon"><i class="bi bi-send-check"></i></div>
      <h1 class="so-success__title">پیشنهاد شما ارسال شد!</h1>
      <p class="so-subtitle">فروشگاه در حال بررسی پیشنهاد شماست.</p>

      <div class="so-meta-list">
        <div class="so-meta-row"><span>شماره پیشنهاد</span><strong>#<?= (int) $offer['id'] ?></strong></div>
        <div class="so-meta-row"><span>فروشگاه</span><strong><?= h($offer['store_name'] ?? $offer['store_user_name']) ?></strong></div>
        <div class="so-meta-row"><span>زمان ارسال</span><strong><?= timeago($offer['created_at']) ?></strong></div>
      </div>

      <div class="so-status"><i class="bi bi-hourglass-split"></i> <?= h($offer['status_label']) ?></div>

      <div class="so-actions" style="margin-top:28px">
        <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" class="so-btn so-btn--primary">مشاهده پیشنهاد من</a>
        <a href="<?= APP_URL ?>/store-offers/" class="so-btn so-btn--outline">معاوضه‌های من</a>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
