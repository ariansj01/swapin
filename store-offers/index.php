<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid = (int) $user['id'];
$tab = clean($_GET['tab'] ?? 'all');
if (!in_array($tab, ['all', 'pending', 'completed'], true)) {
    $tab = 'all';
}

$filter = $tab === 'all' ? null : $tab;
$offers = store_swap_offer_list_for_user($uid, $filter);

render_head('معاوضه‌های من با فروشگاه', '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">

<div class="ex-page">
  <div class="ex-container">

    <div class="ex-header">
      <a href="<?= APP_URL ?>/dashboard" class="ex-header__back">
        <i class="bi bi-arrow-right"></i>
        بازگشت به داشبورد
      </a>
      <div class="ex-header__row">
        <div>
          <h1 class="ex-header__title">معاوضه‌های من</h1>
          <p class="ex-header__subtitle">پیشنهادهای معاوضه فعال و گذشته با فروشگاه‌ها</p>
        </div>
        <a href="<?= APP_URL ?>/shops" class="ex-btn ex-btn--swap ex-btn--sm" data-navigate="<?= APP_URL ?>/shops" style="width:auto">
          <i class="bi bi-plus-lg"></i>
          معاوضه جدید
        </a>
      </div>
    </div>

    <div class="ex-tabs">
      <a href="?tab=all" class="ex-tab<?= $tab === 'all' ? ' is-active' : '' ?>">همه</a>
      <a href="?tab=pending" class="ex-tab<?= $tab === 'pending' ? ' is-active' : '' ?>">در حال انجام</a>
      <a href="?tab=completed" class="ex-tab<?= $tab === 'completed' ? ' is-active' : '' ?>">تکمیل شده</a>
    </div>

    <?php if (!$offers): ?>
    <div class="ex-card">
      <div class="ex-empty">
        <div class="ex-empty__icon">
          <i class="bi bi-inbox"></i>
        </div>
        <h3 class="ex-empty__title">هنوز پیشنهاد معاوضه‌ای ثبت نکرده‌اید</h3>
        <p class="ex-empty__desc">می‌توانید از میان فروشگاه‌های معتبر سواپین، کالای مورد نظر را پیدا کرده و پیشنهاد معاوضه ارسال کنید.</p>
        <a href="<?= APP_URL ?>/shops" class="ex-btn ex-btn--primary" style="max-width:320px;margin:0 auto" data-navigate="<?= APP_URL ?>/shops">
          <i class="bi bi-shop-window"></i>
          مرور فروشگاه‌ها
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="ex-offer-list">
      <?php foreach ($offers as $o):
        $statusClass = 'ex-status--info';
        $statusRaw = $o['status'] ?? '';
        if (in_array($statusRaw, ['pending', 'negotiating'], true)) $statusClass = 'ex-status--pending';
        elseif (in_array($statusRaw, ['accepted'], true)) $statusClass = 'ex-status--success';
        elseif (in_array($statusRaw, ['rejected', 'cancelled'], true)) $statusClass = 'ex-status--rejected';
        elseif ($statusRaw === 'counter_offered') $statusClass = 'ex-status--counter';
        elseif (in_array($statusRaw, ['completed', 'finalized'], true)) $statusClass = 'ex-status--completed';
      ?>
      <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= (int) $o['id'] ?>" class="ex-offer-item" data-navigate="<?= APP_URL ?>/store-offers/view.php?id=<?= (int) $o['id'] ?>">
        <?php if (!empty($o['store_thumb'])): ?>
        <img src="<?= UPLOAD_URL . h($o['store_thumb']) ?>" alt="" class="ex-offer-item__thumb">
        <?php else: ?>
        <div class="ex-offer-item__thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ex-muted);font-size:1.5rem">
          <i class="bi bi-box-seam"></i>
        </div>
        <?php endif; ?>
        <div class="ex-offer-item__content">
          <div class="ex-offer-item__title"><?= h($o['store_listing_title']) ?></div>
          <div class="ex-offer-item__meta">
            <span><?= h($o['store_name'] ?? 'فروشگاه') ?></span>
            <?php if (!empty($o['user_listing_title'])): ?>
            <span>تبدیل با: <?= h($o['user_listing_title']) ?></span>
            <?php endif; ?>
            <?php if ((float) ($o['effective_credit'] ?? 0) > 0): ?>
            <span>مبلغ تکمیلی: <?= fmt_credit((float) $o['effective_credit']) ?></span>
            <?php endif; ?>
            <span><?= timeago($o['created_at']) ?></span>
          </div>
          <div style="margin-top:8px">
            <span class="ex-header__status <?= $statusClass ?>" style="padding:4px 10px;font-size:.75rem">
              <span class="ex-status__dot"></span>
              <?= h($o['status_label']) ?>
            </span>
          </div>
        </div>
        <i class="bi bi-chevron-left ex-offer-item__arrow"></i>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

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
