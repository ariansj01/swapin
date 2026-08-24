<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/store_orders.php';

$user = require_auth();
$uid  = (int)$user['id'];

$allowedTabs = ['all', 'pending', 'done'];
$tab = clean($_GET['tab'] ?? 'all');
if (!in_array($tab, $allowedTabs, true)) $tab = 'all';

$statusWhere = '';
$params = [$uid];
if ($tab === 'pending') {
    $statusWhere = ' AND o.status IN ("pending_payment","paid","preparing","shipped")';
} elseif ($tab === 'done') {
    $statusWhere = ' AND o.status IN ("delivered","canceled")';
}

$orders = store_orders_enabled()
    ? DB::fetchAll(
        'SELECT o.*, l.title AS listing_title,
                (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS listing_thumb,
                us.store_name, us.name AS seller_name
         FROM store_orders o
         JOIN listings l ON l.id = o.listing_id
         JOIN users us ON us.id = o.seller_id
         WHERE o.buyer_id = ? AND o.status != "pending_payment"' . $statusWhere . '
         ORDER BY o.created_at DESC',
        $params
    )
    : [];

$counts = ['all' => 0, 'pending' => 0, 'done' => 0];
if (store_orders_enabled()) {
    $allRow = DB::fetch('SELECT COUNT(*) AS c FROM store_orders WHERE buyer_id = ? AND status != "pending_payment"', [$uid]);
    $counts['all'] = (int)($allRow['c'] ?? 0);
    $pendRow = DB::fetch('SELECT COUNT(*) AS c FROM store_orders WHERE buyer_id = ? AND status IN ("pending_payment","paid","preparing","shipped")', [$uid]);
    $counts['pending'] = (int)($pendRow['c'] ?? 0);
    $counts['done']    = max(0, $counts['all'] - $counts['pending']);
}

$tabs = [
    ['key' => 'all',     'label' => 'همه سفارش‌ها', 'icon' => 'bi-bag'],
    ['key' => 'pending', 'label' => 'در حال انجام', 'icon' => 'bi-hourglass-split'],
    ['key' => 'done',    'label' => 'تکمیل‌شده',    'icon' => 'bi-check2-circle'],
];

render_head('سفارش‌های من');
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">

<div class="ex-page">
  <div class="ex-container">
    <div class="ex-header">
      <a href="<?= APP_URL ?>/dashboard.php" class="ex-header__back">
        <i class="bi bi-arrow-right"></i> بازگشت به داشبورد
      </a>
      <div class="ex-header__row">
        <div>
          <h1 class="ex-header__title">سفارش‌های خرید</h1>
          <p class="ex-header__subtitle">تاریخچه و وضعیت سفارش‌های نقدی شما در سواَپین.</p>
        </div>
      </div>
    </div>

    <div class="ex-tabs" role="tablist">
      <?php foreach ($tabs as $t): ?>
        <a href="?tab=<?= h($t['key']) ?>"
           class="ex-tab <?= $tab === $t['key'] ? 'is-active' : '' ?>"
           role="tab" aria-selected="<?= $tab === $t['key'] ? 'true' : 'false' ?>">
          <i class="bi <?= $t['icon'] ?>" style="margin-left: 6px;"></i>
          <span><?= h($t['label']) ?></span>
          <span class="ex-chip <?= $tab === $t['key'] ? 'ex-chip--navy' : '' ?>" style="margin-right: 6px; padding: 2px 8px; font-size: 0.7rem;">
            <?= persian_digits((string)($counts[$t['key']] ?? 0)) ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($orders)): ?>
      <div class="ex-empty">
        <div class="ex-empty__icon"><i class="bi bi-bag"></i></div>
        <h3 class="ex-empty__title">
          <?php if ($tab === 'pending'): ?>
            سفارش در حال انجامی ندارید
          <?php elseif ($tab === 'done'): ?>
            هنوز سفارش تکمیل‌شده‌ای ثبت نکرده‌اید
          <?php else: ?>
            هنوز سفارشی ندارید
          <?php endif; ?>
        </h3>
        <p class="ex-empty__desc">از فروشگاه‌ها می‌توانید به‌صورت نقدی خرید کنید و وضعیت ارسال را اینجا پیگیری کنید.</p>
        <div class="ex-actions ex-actions--row" style="justify-content: center;">
          <a href="<?= APP_URL ?>/" class="ex-btn ex-btn--primary" style="width: auto;"><i class="bi bi-grid"></i> مرور فروشگاه‌ها</a>
          <?php if ($tab !== 'all'): ?>
            <a href="?tab=all" class="ex-btn ex-btn--outline" style="width: auto;"><i class="bi bi-list"></i> نمایش همه</a>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="ex-offer-list">
        <?php foreach ($orders as $o):
          $statusClass = 'ex-status--info';
          if (in_array($o['status'], ['paid', 'preparing', 'shipped'])) $statusClass = 'ex-status--pending';
          if (in_array($o['status'], ['delivered', 'completed'])) $statusClass = 'ex-status--success';
          if ($o['status'] === 'canceled') $statusClass = 'ex-status--rejected';
        ?>
          <a href="<?= APP_URL ?>/orders/view.php?id=<?= (int)$o['id'] ?>" class="ex-offer-item">
            <?php if (!empty($o['listing_thumb'])): ?>
              <img src="<?= UPLOAD_URL . h($o['listing_thumb']) ?>" alt="" class="ex-offer-item__thumb">
            <?php else: ?>
              <div class="ex-offer-item__thumb ex-product__thumb--empty"><i class="bi bi-image"></i></div>
            <?php endif; ?>
            <div class="ex-offer-item__content">
              <div class="ex-offer-item__title"><?= h($o['listing_title']) ?></div>
              <div class="ex-offer-item__meta">
                <span><i class="bi bi-shop"></i> <?= h($o['store_name'] ?: $o['seller_name']) ?></span>
                <span><i class="bi bi-hash"></i> <?= h($o['order_code']) ?></span>
              </div>
              <div class="ex-offer-item__meta">
                <span><i class="bi bi-calendar3"></i> <?= timeago($o['created_at']) ?></span>
                <span class="ex-product__price" style="font-size: 0.875rem; margin-right: 8px;">
                  <?php
                    $total = (float)$o['amount'] + (float)($o['shipping_cost'] ?? 0);
                    echo fmt_credit($total);
                  ?>
                </span>
              </div>
            </div>
            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
              <span class="ex-header__status <?= $statusClass ?>" style="padding: 4px 12px; font-size: 0.7rem;">
                <span class="ex-status__dot"></span>
                <?= h(store_order_status_label($o['status'])) ?>
              </span>
              <i class="bi bi-chevron-left ex-offer-item__arrow"></i>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>