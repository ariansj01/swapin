<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = (int)$user['id'];

$orders = store_orders_enabled()
    ? DB::fetchAll(
        'SELECT o.*, l.title AS listing_title,
                (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS listing_thumb,
                us.store_name, us.name AS seller_name
         FROM store_orders o
         JOIN listings l ON l.id = o.listing_id
         JOIN users us ON us.id = o.seller_id
         WHERE o.buyer_id = ? AND o.status != "pending_payment"
         ORDER BY o.created_at DESC',
        [$uid]
    )
    : [];

render_head('سفارش‌های من');
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/orders.css?v=<?= @filemtime(__DIR__ . '/../src/css/orders.css') ?: time() ?>">

<div class="section-sm">
  <div class="container-md">
    <div class="order-page-head">
      <a href="<?= APP_URL ?>/dashboard.php" class="order-back"><i class="bi bi-arrow-right"></i> بازگشت به داشبورد</a>
      <h1>سفارش‌های خرید من</h1>
    </div>

    <?php if (empty($orders)): ?>
    <div class="empty-state">
      <i class="bi bi-bag"></i>
      <h3>هنوز سفارشی ندارید</h3>
      <p>از فروشگاه‌ها می‌توانید به‌صورت نقدی خرید کنید و وضعیت ارسال را اینجا پیگیری کنید.</p>
      <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور فروشگاه‌ها</a>
    </div>
    <?php else: ?>
    <div class="order-list">
      <?php foreach ($orders as $o): ?>
      <a href="<?= APP_URL ?>/orders/view.php?id=<?= (int)$o['id'] ?>" class="card order-list-item">
        <div class="card-body">
          <div class="order-list-item__main">
            <?php if (!empty($o['listing_thumb'])): ?>
            <img src="<?= UPLOAD_URL . h($o['listing_thumb']) ?>" alt="" class="order-product__thumb">
            <?php endif; ?>
            <div>
              <div class="order-product__title"><?= h($o['listing_title']) ?></div>
              <div class="order-product__store"><?= h($o['store_name'] ?: $o['seller_name']) ?></div>
              <div class="order-muted">کد: <?= h($o['order_code']) ?> — <?= timeago($o['created_at']) ?></div>
            </div>
          </div>
          <div class="order-list-item__side">
            <span class="order-status-pill order-status-pill--<?= h($o['status']) ?>"><?= h(store_order_status_label($o['status'])) ?></span>
            <strong><?= fmt_credit((float)$o['amount']) ?></strong>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
