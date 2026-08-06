<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$search = clean($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = clean($_POST['action'] ?? '');

    if ($userId && $action === 'toggle_store_active') {
        $target = DB::fetch('SELECT id, seller_type, role, name, store_name FROM users WHERE id = ?', [$userId]);
        if ($target && $target['role'] !== 'admin') {
            $newType = (($target['seller_type'] ?? 'personal') === 'store') ? 'personal' : 'store';
            DB::update('users', ['seller_type' => $newType], 'id = ?', [$userId]);
            admin_set_flash('نوع کاربر به «' . ($newType === 'store' ? 'فروشگاه' : 'شخصی') . '» تغییر کرد.');
        }
    }

    if ($userId && $action === 'delete_store') {
        $target = DB::fetch('SELECT id, seller_type, role, store_name FROM users WHERE id = ?', [$userId]);
        if ($target && $target['role'] !== 'admin' && (($target['seller_type'] ?? 'personal') === 'store' || !empty($target['store_name']))) {
            $updateData = [
                'seller_type' => 'personal',
                'store_name' => null,
                'store_slug' => null,
                'store_login' => null,
                'store_description' => null,
                'store_banner' => null,
                'store_address' => null,
                'store_phone' => null,
                'store_website' => null,
                'store_instagram' => null,
                'store_telegram' => null,
                'store_opening_hours' => null,
                'store_lat' => null,
                'store_lng' => null,
            ];
            $cols = db_table_columns('users');
            $cleanData = [];
            foreach ($updateData as $k => $v) {
                if (in_array($k, $cols, true)) {
                    $cleanData[$k] = $v;
                }
            }
            if (!empty($cleanData)) {
                DB::update('users', $cleanData, 'id = ?', [$userId]);
            }
            admin_set_flash('فروشگاه حذف شد و کاربر به حالت شخصی بازگشت.');
        }
    }

    header('Location: ' . APP_URL . '/admin/stores.php?q=' . urlencode($search));
    exit;
}

[$flash, $flashType] = admin_flash();
$storeCredsOnce = admin_take_store_credentials_once();

$hasSellerType = db_has_column('users', 'seller_type');
$hasStoreLogin = db_has_column('users', 'store_login');
$hasStoreCity = db_has_column('users', 'store_city');

$selectCols = "SELECT id, name, email, phone, is_active, created_at,
                      store_name, store_slug, store_phone";
if ($hasStoreCity) {
    $selectCols .= ", store_city";
}
if ($hasSellerType) {
    $selectCols .= ", seller_type";
}
if ($hasStoreLogin) {
    $selectCols .= ", store_login";
}
$selectCols .= ",
                      (SELECT COUNT(*) FROM listings WHERE user_id = users.id AND status = 'active') AS listings_count,
                      (SELECT COUNT(*) FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = users.id AND o.status = 'pending') AS pending_offers
                      FROM users
                      WHERE ";

if ($hasSellerType) {
    $whereClause = "(seller_type = 'store' OR store_name IS NOT NULL AND store_name != '')";
} else {
    $whereClause = "(store_name IS NOT NULL AND store_name != '')";
}

$params = [];
if ($search) {
    $whereClause .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR store_name LIKE ?)";
    $params = ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"];
}

$orderBy = " ORDER BY created_at DESC LIMIT 200";

$users = DB::fetchAll($selectCols . $whereClause . $orderBy, $params);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>
<?= admin_store_credentials_banner_html($storeCredsOnce) ?>

<div class="admin-header">
  <h1>فروشگاه‌ها</h1>
  <div style="display:flex;gap:var(--sp-2);align-items:center">
    <a href="<?= APP_URL ?>/admin/store_create_edit.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> ساخت فروشگاه جدید</a>
    <form method="GET" style="display:flex;gap:var(--sp-2)">
      <input type="search" class="form-control" name="q" value="<?= h($search) ?>" placeholder="جستجو نام، ایمیل، تلفن، فروشگاه…" style="min-width:240px">
      <button type="submit" class="btn btn-primary">جستجو</button>
    </form>
  </div>
</div>

<div class="card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>نام فروشگاه</th>
        <th>مالک</th>
        <th>ایمیل/تلفن</th>
        <th>تعداد آگهی</th>
        <th>درخواست در انتظار</th>
        <th>وضعیت</th>
        <th>اقدامات</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= $u['id'] ?></td>
        <td>
          <?php if (!empty($u['store_name'])): ?>
            <strong><?= h($u['store_name']) ?></strong>
            <?php if (!empty($u['store_slug'])): ?>
              <div class="fs-xs text-muted"><?= h($u['store_slug']) ?></div>
            <?php endif; ?>
            <?php if ($hasStoreLogin && !empty($u['store_login'])): ?>
              <div class="fs-xs text-muted" dir="ltr">ورود: <?= h($u['store_login']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= h($u['name']) ?></td>
        <td class="fs-xs">
          <div><?= h($u['email']) ?></div>
          <?php if (!empty($u['store_phone'])): ?>
            <div class="text-muted">فروشگاه: <?= h($u['store_phone']) ?></div>
          <?php elseif (!empty($u['phone'])): ?>
            <div class="text-muted"><?= h($u['phone']) ?></div>
          <?php endif; ?>
          <?php if ($hasStoreCity && !empty($u['store_city'])): ?>
            <div class="text-muted"><i class="bi bi-geo-alt"></i> <?= h($u['store_city']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= (int)$u['listings_count'] ?></td>
        <td>
          <?php $pending = (int)$u['pending_offers']; ?>
          <?php if ($pending > 0): ?>
            <span class="badge badge-warning"><?= $pending ?></span>
          <?php else: ?>
            <span class="text-muted">۰</span>
          <?php endif; ?>
        </td>
        <td>
          <?php
            $isStore = $hasSellerType ? (($u['seller_type'] ?? 'personal') === 'store') : !empty($u['store_name']);
            echo $isStore
                ? '<span class="badge badge-success"><i class="bi bi-shop"></i> فروشگاه</span>'
                : '<span class="badge badge-info"><i class="bi bi-person"></i> شخصی</span>';
          ?>
          <?php if (!$u['is_active']): ?>
            <div><span class="badge badge-danger mt-1">غیرفعال</span></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if (($u['role'] ?? 'user') !== 'admin'): ?>
            <a href="<?= APP_URL ?>/admin/store_create_edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-primary">ویرایش</a>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <input type="hidden" name="action" value="toggle_store_active">
              <button type="submit" class="btn btn-sm btn-outline">
                <?= $isStore ? 'تبدیل به شخصی' : 'تبدیل به فروشگاه' ?>
              </button>
            </form>
            <?php if ($isStore || !empty($u['store_name'])): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('آیا از حذف کامل اطلاعات فروشگاه اطمینان دارید؟ این عمل قابل بازگشت نیست.');">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <input type="hidden" name="action" value="delete_store">
                <button type="submit" class="btn btn-sm btn-danger">حذف فروشگاه</button>
              </form>
            <?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('فروشگاه‌ها');
render_admin_shell($admin, 'stores', $content);
render_admin_footer();
