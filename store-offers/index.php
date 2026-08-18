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
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store-offers.css?v=<?= @filemtime(__DIR__ . '/../src/css/store-offers.css') ?: time() ?>">

<div class="section-sm">
  <div class="so-page" style="max-width:800px">
    <div class="so-head">
      <h1 class="so-title">معاوضه‌های من</h1>
      <p class="so-subtitle">پیشنهادهای معاوضه با فروشگاه‌ها</p>
    </div>

    <div class="so-tabs">
      <a href="?tab=all" class="so-tab<?= $tab === 'all' ? ' is-active' : '' ?>">همه</a>
      <a href="?tab=pending" class="so-tab<?= $tab === 'pending' ? ' is-active' : '' ?>">در انتظار</a>
      <a href="?tab=completed" class="so-tab<?= $tab === 'completed' ? ' is-active' : '' ?>">تکمیل شده</a>
    </div>

    <?php if (!$offers): ?>
    <div class="so-card so-alert--info">
      <p><i class="bi bi-inbox"></i> هنوز پیشنهاد معاوضه‌ای با فروشگاه ثبت نکرده‌اید.</p>
      <a href="<?= APP_URL ?>/shops" class="so-btn so-btn--primary" style="margin-top:12px">مرور فروشگاه‌ها</a>
    </div>
    <?php else: ?>
    <?php foreach ($offers as $o): ?>
    <a href="<?= APP_URL ?>/store-offers/view.php?id=<?= (int) $o['id'] ?>" class="so-offer-list-item">
      <div style="display:flex;gap:12px;align-items:center">
        <?php if (!empty($o['store_thumb'])): ?>
        <img src="<?= UPLOAD_URL . h($o['store_thumb']) ?>" alt="" class="so-item__thumb" style="width:56px;height:56px">
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div class="so-item__title"><?= h($o['store_listing_title']) ?></div>
          <div class="so-item__meta">
            <?= h($o['store_name'] ?? '') ?> · <?= h($o['user_listing_title'] ?? '') ?>
            <?php if ((float) ($o['effective_credit'] ?? 0) > 0): ?>
            · +<?= fmt_credit((float) $o['effective_credit']) ?>
            <?php endif; ?>
          </div>
          <div style="margin-top:6px">
            <span class="so-status" style="font-size:.75rem;padding:4px 10px"><?= h($o['status_label']) ?></span>
            <span class="so-item__meta" style="margin-inline-start:8px"><?= timeago($o['created_at']) ?></span>
          </div>
        </div>
        <i class="bi bi-chevron-left so-item__meta"></i>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
