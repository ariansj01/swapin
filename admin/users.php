<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$search = clean($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = clean($_POST['action'] ?? '');

    if ($userId && $action === 'toggle_active') {
        $target = DB::fetch('SELECT id, is_active, role, name FROM users WHERE id = ?', [$userId]);
        if ($target && $target['role'] !== 'admin') {
            admin_toggle_user_active($userId, !(bool)$target['is_active']);
            admin_set_flash($target['is_active'] ? 'کاربر غیرفعال شد.' : 'کاربر فعال شد.');
        }
    } elseif ($userId && $action === 'make_admin') {
        DB::update('users', ['role' => 'admin'], 'id = ? AND role != "admin"', [$userId]);
        admin_set_flash('نقش مدیر اضافه شد.');
    }
    header('Location: ' . APP_URL . '/admin/users.php?q=' . urlencode($search));
    exit;
}

[$flash, $flashType] = admin_flash();

$params = [];
$where  = '1=1';
if ($search) {
    $where .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $params = ["%{$search}%", "%{$search}%", "%{$search}%"];
}

$users = DB::fetchAll(
    "SELECT id, name, email, phone, role, is_active, kyc_status, credit_balance, created_at,
            (SELECT COUNT(*) FROM listings WHERE user_id = users.id AND status = 'active') AS listings_count
     FROM users WHERE {$where}
     ORDER BY created_at DESC LIMIT 100",
    $params
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <h1>مدیریت کاربران</h1>
  <form method="GET" style="display:flex;gap:var(--sp-2)">
    <input type="search" class="form-control" name="q" value="<?= h($search) ?>" placeholder="جستجو نام، ایمیل، تلفن…" style="min-width:240px">
    <button type="submit" class="btn btn-primary">جستجو</button>
  </form>
</div>

<div class="card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>نام</th>
        <th>ایمیل</th>
        <th>نقش</th>
        <th>KYC</th>
        <th>آگهی</th>
        <th>موجودی</th>
        <th>وضعیت</th>
        <th>اقدام</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= $u['id'] ?></td>
        <td><?= h($u['name']) ?></td>
        <td class="fs-xs"><?= h($u['email']) ?></td>
        <td><?= ($u['role'] ?? 'user') === 'admin' ? '<span class="badge badge-primary">مدیر</span>' : 'کاربر' ?></td>
        <td><span class="badge badge-<?= match($u['kyc_status'] ?? 'none') { 'approved'=>'success','pending'=>'warning','rejected'=>'danger', default=>'info' } ?>"><?= $u['kyc_status'] ?? 'none' ?></span></td>
        <td><?= (int)$u['listings_count'] ?></td>
        <td><?= number_format((float)$u['credit_balance'], 0) ?></td>
        <td><?= $u['is_active'] ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>' ?></td>
        <td>
          <?php if (($u['role'] ?? 'user') !== 'admin'): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <input type="hidden" name="action" value="toggle_active">
            <button type="submit" class="btn btn-sm btn-outline"><?= $u['is_active'] ? 'غیرفعال' : 'فعال' ?></button>
          </form>
          <?php if ($u['kyc_status'] === 'pending'): ?>
          <a href="<?= APP_URL ?>/admin/kyc.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-accent">KYC</a>
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
render_admin_head('کاربران');
render_admin_shell($admin, 'users', $content);
render_admin_footer();
