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
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store-offers.css?v=<?= @filemtime(__DIR__ . '/../src/css/store-offers.css') ?: time() ?>">

<div class="section-sm">
  <div class="so-page">
    <div class="so-head">
      <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" class="so-back"><i class="bi bi-arrow-right"></i> بازگشت</a>
      <h1 class="so-title">تأیید نهایی</h1>
      <p class="so-subtitle">پیشنهاد معاوضه مورد توافق قرار گرفت</p>
    </div>

    <?php if ($error): ?><div class="so-alert so-alert--error"><?= h($error) ?></div><?php endif; ?>

    <div class="so-card">
      <div class="so-swap-row">
        <div>
          <div class="so-card__label">کالای فروشگاه</div>
          <div class="so-item__title"><?= h($offer['store_listing_title']) ?></div>
        </div>
        <div class="so-swap-icon"><i class="bi bi-arrow-left-right"></i></div>
        <div>
          <div class="so-card__label">کالای شما</div>
          <div class="so-item__title"><?= h($offer['user_listing_title'] ?? '—') ?></div>
        </div>
      </div>
      <div class="so-cash-box" style="margin-top:16px">
        <div class="so-cash-row"><span>مبلغ تکمیلی</span><strong><?= fmt_credit((float) $offer['effective_credit']) ?></strong></div>
      </div>
    </div>

    <form method="POST">
      <?= csrf_field() ?>
      <div class="so-actions">
        <button type="submit" class="so-btn so-btn--gold"><i class="bi bi-handshake"></i> تأیید نهایی و شروع فرآیند معاوضه</button>
        <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= $offerId ?>" class="so-btn so-btn--outline">بازگشت</a>
      </div>
    </form>
  </div>
</div>

<?php render_footer(); ?>
