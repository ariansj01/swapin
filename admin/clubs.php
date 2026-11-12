<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$admin = require_admin();
admin_require_permission('clubs');
clubs_ensure_tables();

$id = (int)($_GET['id'] ?? 0);
$statusFilter = clean($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');
    $clubId = (int)($_POST['club_id'] ?? 0);
    $note = trim((string)($_POST['admin_note'] ?? ''));

    if ($action === 'approve' && $clubId > 0) {
        if (clubs_set_status($clubId, 'approved', (int)$admin['id'], $note)) {
            admin_set_flash('کلاب تأیید شد.');
        } else {
            admin_set_flash('تأیید کلاب ناموفق بود.', 'error');
        }
        header('Location: ' . APP_URL . '/admin/clubs.php?status=approved');
        exit;
    }
    if ($action === 'reject' && $clubId > 0) {
        if (clubs_set_status($clubId, 'rejected', (int)$admin['id'], $note)) {
            admin_set_flash('کلاب رد شد.');
        } else {
            admin_set_flash('رد کلاب ناموفق بود.', 'error');
        }
        header('Location: ' . APP_URL . '/admin/clubs.php?id=' . $clubId);
        exit;
    }
    if ($action === 'save_rules' && $clubId > 0) {
        $rules = trim((string)($_POST['rules'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if (mb_strlen($rules) > 5000) {
            admin_set_flash('متن قوانین خیلی بلند است.', 'error');
        } else {
            clubs_update_content($clubId, $rules, $description);
            admin_set_flash('قوانین و توضیحات کلاب ذخیره شد.');
        }
        header('Location: ' . APP_URL . '/admin/clubs.php?id=' . $clubId . '&status=' . urlencode($statusFilter));
        exit;
    }
}

[$flash, $flashType] = admin_flash();
$statusLabels = clubs_status_labels();

$detail = null;
if ($id > 0) {
    $detail = DB::fetch(
        'SELECT cl.*, u.name AS user_name, u.phone AS user_phone, c.name AS category_name
         FROM clubs cl
         JOIN users u ON u.id = cl.owner_id
         LEFT JOIN categories c ON c.id = cl.category_id
         WHERE cl.id = ?',
        [$id]
    );
}

$where = '1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND cl.status = ?';
    $params[] = $statusFilter;
}

$rows = DB::fetchAll(
    "SELECT cl.*, u.name AS user_name, c.name AS category_name
     FROM clubs cl
     JOIN users u ON u.id = cl.owner_id
     LEFT JOIN categories c ON c.id = cl.category_id
     WHERE {$where}
     ORDER BY FIELD(cl.status,'pending','approved','rejected','archived'), cl.created_at DESC
     LIMIT 100",
    $params
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <h1>کلاب‌ها</h1>
</div>

<div class="admin-tabs mb-5">
  <?php foreach (['pending' => 'در انتظار', 'approved' => 'تأیید شده', 'rejected' => 'رد شده', 'all' => 'همه'] as $key => $label): ?>
  <a href="?status=<?= h($key) ?>" class="admin-tab<?= $statusFilter === $key ? ' admin-tab--active' : '' ?>">
    <?= h($label) ?>
    <?php if ($key === 'pending' && ($cnt = clubs_pending_count()) > 0): ?>
    <span class="admin-nav__badge"><?= $cnt ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($detail): ?>
<div class="card mb-6" style="padding:24px">
  <h2 style="margin:0 0 16px;font-size:1.1rem"><?= h($detail['name']) ?></h2>
  <div class="fs-sm" style="line-height:2;color:var(--text-muted)">
    <div><strong>سازنده:</strong> <?= h($detail['user_name']) ?></div>
    <div><strong>تلفن:</strong> <?= h($detail['user_phone'] ?? '—') ?></div>
    <div><strong>دسته‌بندی:</strong> <?= h($detail['category_name'] ?? '—') ?></div>
    <div><strong>حریم خصوصی:</strong> <?= h(clubs_privacy_labels()[$detail['privacy']] ?? $detail['privacy']) ?></div>
    <div><strong>تأیید عضویت:</strong> <?= !empty($detail['require_approval']) ? 'فعال' : 'غیرفعال' ?></div>
    <div><strong>وضعیت:</strong> <?= h($statusLabels[$detail['status']] ?? $detail['status']) ?></div>
    <div><strong>تاریخ:</strong> <?= h($detail['created_at']) ?></div>
  </div>

  <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">
    <?php if (!empty($detail['cover_image'])): ?>
    <div>
      <div class="fs-sm text-muted">کاور</div>
      <img src="<?= h(UPLOAD_URL . $detail['cover_image']) ?>" alt="" style="max-height:120px;border-radius:8px;border:1px solid var(--border)">
    </div>
    <?php endif; ?>
    <?php if (!empty($detail['avatar_image'])): ?>
    <div>
      <div class="fs-sm text-muted">پروفایل</div>
      <img src="<?= h(UPLOAD_URL . $detail['avatar_image']) ?>" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--border)">
    </div>
    <?php endif; ?>
  </div>

  <form method="POST" class="mt-4">
    <?= csrf_field() ?>
    <input type="hidden" name="club_id" value="<?= (int)$detail['id'] ?>">
    <input type="hidden" name="action" value="save_rules">
    <div class="form-group">
      <label class="form-label">درباره کلاب</label>
      <textarea class="form-control" name="description" rows="3" maxlength="500"><?= h((string)($detail['description'] ?? '')) ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">قوانین کلاب (تب قوانین در صفحه کلاب)</label>
      <textarea class="form-control" name="rules" rows="8" maxlength="5000" placeholder="قوانین نمایش‌داده‌شده در تب قوانین را اینجا وارد کنید."><?= h((string)($detail['rules'] ?? '')) ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">ذخیره قوانین</button>
    <?php if (!empty($detail['slug'])): ?>
    <a class="btn btn-outline" href="<?= APP_URL ?>/clubs/<?= h($detail['slug']) ?>?tab=rules" target="_blank">مشاهده تب قوانین</a>
    <?php endif; ?>
  </form>

  <?php if (!empty($detail['admin_note'])): ?>
  <div class="alert alert-warning mt-4"><strong>یادداشت ادمین:</strong> <?= h($detail['admin_note']) ?></div>
  <?php endif; ?>

  <?php if (($detail['status'] ?? '') === 'pending'): ?>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="club_id" value="<?= (int)$detail['id'] ?>">
      <input type="hidden" name="action" value="approve">
      <button type="submit" class="btn btn-primary" onclick="return confirm('کلاب تأیید شود؟')">تأیید کلاب</button>
    </form>
    <form method="POST" style="flex:1;min-width:280px">
      <?= csrf_field() ?>
      <input type="hidden" name="club_id" value="<?= (int)$detail['id'] ?>">
      <input type="hidden" name="action" value="reject">
      <div style="display:flex;gap:8px">
        <input type="text" class="form-control" name="admin_note" placeholder="دلیل رد (اختیاری)">
        <button type="submit" class="btn btn-danger">رد</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" style="overflow:auto">
  <table class="admin-table">
    <thead>
      <tr>
        <th>نام</th>
        <th>سازنده</th>
        <th>دسته</th>
        <th>حریم</th>
        <th>وضعیت</th>
        <th>تاریخ</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
      <tr><td colspan="7" class="text-muted">موردی یافت نشد.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h($row['name']) ?></td>
        <td><?= h($row['user_name']) ?></td>
        <td><?= h($row['category_name'] ?? '—') ?></td>
        <td><?= h(clubs_privacy_labels()[$row['privacy']] ?? $row['privacy']) ?></td>
        <td><?= h($statusLabels[$row['status']] ?? $row['status']) ?></td>
        <td><?= h($row['created_at']) ?></td>
        <td><a href="?id=<?= (int)$row['id'] ?>&status=<?= h($statusFilter) ?>">مشاهده</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('کلاب‌ها');
render_admin_shell($admin, 'clubs', $content);
render_admin_footer();
