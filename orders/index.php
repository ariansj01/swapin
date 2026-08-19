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
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/orders.css?v=<?= @filemtime(__DIR__ . '/../src/css/orders.css') ?: time() ?>">
<style>
  .ord-tabs{display:flex;gap:8px;padding:8px;background:#F3F4F6;border-radius:16px;margin-bottom:24px;flex-wrap:wrap;}
  .ord-tab{flex:1;min-width:120px;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:12px;color:#6B7280;font-weight:700;font-size:.92rem;cursor:pointer;text-decoration:none;transition:all .15s ease;border:none;background:transparent;}
  .ord-tab:hover{background:#fff;color:var(--dash-navy);}
  .ord-tab.is-active{background:#fff;color:var(--primary,#2563eb);box-shadow:0 1px 2px rgba(16,24,40,.06);}
  .ord-tab__count{font-size:.75rem;padding:2px 10px;border-radius:999px;background:#E5E7EB;color:#374151;font-weight:800;}
  .ord-tab.is-active .ord-tab__count{background:rgba(37,99,235,.1);color:var(--primary,#2563eb);}

  .empty-state{padding:60px 20px;text-align:center;}
  .empty-state i{font-size:56px;color:#D1D5DB;margin-bottom:16px;}
  .empty-state h3{font-size:1.15rem;margin-bottom:8px;}
  .empty-state p{color:var(--text-muted);margin-bottom:24px;}

  @media(max-width:640px){
    .ord-tab{padding:10px 12px;font-size:.85rem;min-width:0;}
    .ord-tab__count{display:none;}
  }
</style>

<div class="section-sm">
  <div class="container-md">
    <div class="order-page-head">
      <a href="<?= APP_URL ?>/dashboard.php" class="order-back"><i class="bi bi-arrow-right"></i> بازگشت به داشبورد</a>
      <h1>سفارش‌های خرید من</h1>
    </div>

    <div class="ord-tabs" role="tablist" aria-label="فیلتر سفارش‌ها">
      <?php foreach ($tabs as $t): ?>
        <a href="?tab=<?= h($t['key']) ?>"
           class="ord-tab <?= $tab === $t['key'] ? 'is-active' : '' ?>"
           role="tab" aria-selected="<?= $tab === $t['key'] ? 'true' : 'false' ?>">
          <i class="bi <?= $t['icon'] ?>"></i>
          <span><?= h($t['label']) ?></span>
          <span class="ord-tab__count"><?= persian_digits((string)($counts[$t['key']] ?? 0)) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($orders)): ?>
    <div class="empty-state">
      <i class="bi bi-bag"></i>
      <h3>
        <?php if ($tab === 'pending'): ?>
          سفارش در حال انجامی ندارید
        <?php elseif ($tab === 'done'): ?>
          هنوز سفارش تکمیل‌شده‌ای ثبت نکرده‌اید
        <?php else: ?>
          هنوز سفارشی ندارید
        <?php endif; ?>
      </h3>
      <p>از فروشگاه‌ها می‌توانید به‌صورت نقدی خرید کنید و وضعیت ارسال را اینجا پیگیری کنید.</p>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/" class="btn btn-primary"><i class="bi bi-grid"></i> مرور فروشگاه‌ها</a>
        <?php if ($tab !== 'all'): ?>
          <a href="?tab=all" class="btn btn-outline"><i class="bi bi-list"></i> نمایش همه سفارش‌ها</a>
        <?php endif; ?>
      </div>
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
              <?php if (!empty($o['shipping_cost']) && (float)$o['shipping_cost'] > 0): ?>
                <div class="order-muted" style="margin-top:2px;">
                  <i class="bi bi-truck"></i> ارسال: <?= fmt_credit((float)$o['shipping_cost']) ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="order-list-item__side">
            <span class="order-status-pill order-status-pill--<?= h($o['status']) ?>"><?= h(store_order_status_label($o['status'])) ?></span>
            <strong>
              <?php
                $total = (float)$o['amount'] + (float)($o['shipping_cost'] ?? 0);
                echo fmt_credit($total);
              ?>
            </strong>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>