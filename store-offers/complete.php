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
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store-offers.css?v=<?= @filemtime(__DIR__ . '/../src/css/store-offers.css') ?: time() ?>">

<div class="section-sm">
  <div class="so-page">
    <div class="so-success">
      <div class="so-success__icon"><i class="bi bi-gift"></i></div>
      <h1 class="so-success__title">معاوضه تکمیل شد!</h1>
      <p class="so-subtitle">فرآیند معاوضه با فروشگاه آغاز شد. جزئیات معامله در اتاق امن در دسترس است.</p>

      <div class="so-meta-list">
        <div class="so-meta-row"><span>شماره پیشنهاد</span><strong>#<?= $offerId ?></strong></div>
        <?php if ($tradeId): ?>
        <div class="so-meta-row"><span>شماره معامله</span><strong>#<?= $tradeId ?></strong></div>
        <?php endif; ?>
        <div class="so-meta-row"><span>فروشگاه</span><strong><?= h($offer['store_name'] ?? '') ?></strong></div>
        <div class="so-meta-row"><span>مبلغ تکمیلی</span><strong><?= fmt_credit((float) $offer['effective_credit']) ?></strong></div>
      </div>

      <div class="so-actions">
        <?php if ($tradeId): ?>
        <a href="<?= APP_URL ?>/trades/view.php?id=<?= $tradeId ?>" class="so-btn so-btn--primary">ورود به اتاق معامله</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/store-offers/" class="so-btn so-btn--outline">معاوضه‌های من</a>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
