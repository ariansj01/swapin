<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
[$flash, $flashType] = admin_flash();
$counts = admin_pending_counts();

$recentListings = DB::fetchAll(
    'SELECT l.id, l.title, l.created_at, u.name AS seller_name
     FROM listings l JOIN users u ON u.id = l.user_id
     WHERE l.review_status = "pending" AND l.status = "active"
     ORDER BY l.created_at ASC LIMIT 8'
);

$recentKyc = DB::fetchAll(
    'SELECT id, name, email, kyc_status, created_at FROM users
     WHERE kyc_status = "pending" ORDER BY updated_at ASC LIMIT 8'
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1>داشبورد مدیریت</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">خلاصه کارهای در انتظار بررسی</p>
  </div>
</div>

<div class="admin-stats">
  <a href="<?= APP_URL ?>/admin/listings.php" class="admin-stat" style="text-decoration:none;color:inherit">
    <div class="admin-stat__value"><?= $counts['listings'] ?></div>
    <div class="admin-stat__label">آگهی در انتظار تأیید</div>
  </a>
  <a href="<?= APP_URL ?>/admin/kyc.php" class="admin-stat" style="text-decoration:none;color:inherit">
    <div class="admin-stat__value"><?= $counts['kyc'] ?></div>
    <div class="admin-stat__label">احراز هویت</div>
  </a>
  <a href="<?= APP_URL ?>/admin/inspections.php" class="admin-stat" style="text-decoration:none;color:inherit">
    <div class="admin-stat__value"><?= $counts['inspections'] ?></div>
    <div class="admin-stat__label">بازرسی کارشناسی</div>
  </a>
  <a href="<?= APP_URL ?>/admin/disputes.php" class="admin-stat" style="text-decoration:none;color:inherit">
    <div class="admin-stat__value"><?= $counts['disputes'] ?></div>
    <div class="admin-stat__label">اختلافات باز</div>
  </a>
  <div class="admin-stat">
    <div class="admin-stat__value"><?= $counts['users'] ?></div>
    <div class="admin-stat__label">کاربران فعال</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6)">
  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">آگهی‌های در انتظار</h3></div>
    <?php if (empty($recentListings)): ?>
    <div class="card-body fs-sm" style="color:var(--text-muted)">موردی نیست ✓</div>
    <?php else: ?>
    <table class="admin-table">
      <?php foreach ($recentListings as $l): ?>
      <tr>
        <td><a href="<?= APP_URL ?>/admin/listings.php?id=<?= $l['id'] ?>"><?= h(mb_strimwidth($l['title'], 0, 40, '…')) ?></a></td>
        <td class="fs-xs" style="color:var(--text-muted)"><?= h($l['seller_name']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">احراز هویت در انتظار</h3></div>
    <?php if (empty($recentKyc)): ?>
    <div class="card-body fs-sm" style="color:var(--text-muted)">موردی نیست ✓</div>
    <?php else: ?>
    <table class="admin-table">
      <?php foreach ($recentKyc as $u): ?>
      <tr>
        <td><a href="<?= APP_URL ?>/admin/kyc.php?id=<?= $u['id'] ?>"><?= h($u['name']) ?></a></td>
        <td class="fs-xs" style="color:var(--text-muted)"><?= h($u['email']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
render_admin_head('داشبورد');
render_admin_shell($admin, 'index', $content);
render_admin_footer();
