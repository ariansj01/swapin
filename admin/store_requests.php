<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$id = (int)($_GET['id'] ?? 0);
$statusFilter = clean($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');
    $requestId = (int)($_POST['request_id'] ?? 0);

    if ($action === 'approve' && $requestId > 0) {
        $result = approve_store_request($requestId, (int)$admin['id']);
        if (!$result['ok']) {
            admin_set_flash($result['error'] ?? 'تأیید ناموفق بود.', 'error');
        } else {
            if (!empty($result['login']) && !empty($result['password'])) {
                admin_set_store_credentials_once(
                    (int)$result['user_id'],
                    $result['login'],
                    $result['password'],
                    (string)($result['store_name'] ?? '')
                );
            }
            admin_set_flash('فروشگاه با موفقیت ثبت شد.');
        }
        header('Location: ' . APP_URL . '/admin/store_requests.php?status=approved');
        exit;
    }

    if ($action === 'reject' && $requestId > 0) {
        $note = trim((string)($_POST['admin_note'] ?? ''));
        if (reject_store_request($requestId, (int)$admin['id'], $note)) {
            admin_set_flash('درخواست رد شد.');
        } else {
            admin_set_flash('رد درخواست ناموفق بود.', 'error');
        }
        header('Location: ' . APP_URL . '/admin/store_requests.php?id=' . $requestId);
        exit;
    }
}

[$flash, $flashType] = admin_flash();
$storeCredsOnce = admin_take_store_credentials_once();
$statusLabels = store_request_status_labels();

$detail = null;
if ($id > 0) {
    $detail = DB::fetch(
        'SELECT r.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
         FROM store_requests r
         JOIN users u ON u.id = r.user_id
         WHERE r.id = ?',
        [$id]
    );
}

$where = '1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND r.status = ?';
    $params[] = $statusFilter;
}

$requests = DB::fetchAll(
    "SELECT r.*, u.name AS user_name, u.phone AS user_phone
     FROM store_requests r
     JOIN users u ON u.id = r.user_id
     WHERE {$where}
     ORDER BY FIELD(r.status,'pending','approved','rejected'), r.created_at DESC
     LIMIT 100",
    $params
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>
<?= admin_store_credentials_banner_html($storeCredsOnce) ?>

<div class="admin-header">
  <h1>درخواست‌های فروشگاه</h1>
  <a href="<?= APP_URL ?>/admin/stores.php" class="btn btn-outline"><i class="bi bi-shop"></i> لیست فروشگاه‌ها</a>
</div>

<div class="admin-tabs mb-5">
  <?php foreach (['pending' => 'در انتظار', 'approved' => 'تأیید شده', 'rejected' => 'رد شده', 'all' => 'همه'] as $key => $label): ?>
  <a href="?status=<?= h($key) ?>" class="admin-tab<?= $statusFilter === $key ? ' admin-tab--active' : '' ?>">
    <?= h($label) ?>
    <?php if ($key === 'pending' && ($counts = store_request_pending_count()) > 0): ?>
    <span class="admin-nav__badge"><?= $counts ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($detail): ?>
<div class="admin-detail-grid mb-6">
  <div class="card" style="padding:24px">
    <h2 style="margin:0 0 16px;font-size:1.1rem"><?= h($detail['store_name']) ?></h2>
    <div class="fs-sm" style="line-height:2;color:var(--text-muted)">
      <div><strong>نوع کسب‌وکار:</strong> <?= h(provider_type_labels()[normalize_provider_type($detail['provider_type'] ?? 'normal_store')] ?? '—') ?></div>
      <div><strong>درخواست‌دهنده:</strong> <?= h($detail['user_name']) ?></div>
      <div><strong>ایمیل:</strong> <?= h($detail['user_email'] ?? '—') ?></div>
      <div><strong>تلفن کاربر:</strong> <?= h($detail['user_phone'] ?? '—') ?></div>
      <div><strong>تلفن فروشگاه:</strong> <?= h($detail['store_phone'] ?? '—') ?></div>
      <div><strong>وضعیت:</strong> <?= h($statusLabels[$detail['status']] ?? $detail['status']) ?></div>
      <div><strong>تاریخ:</strong> <?= h($detail['created_at']) ?></div>
    </div>

    <?php if (!empty($detail['store_banner'])): ?>
    <div style="margin:16px 0">
      <img src="<?= h(UPLOAD_URL . $detail['store_banner']) ?>" alt="بنر" style="max-height:140px;border-radius:8px;border:1px solid var(--border)">
    </div>
    <?php endif; ?>

    <?php if (!empty($detail['store_description'])): ?>
    <p style="line-height:1.8"><?= nl2br(h($detail['store_description'])) ?></p>
    <?php endif; ?>

    <dl class="fs-sm" style="display:grid;grid-template-columns:120px 1fr;gap:8px;margin-top:16px">
      <dt>آدرس</dt><dd><?= h($detail['store_address'] ?: '—') ?></dd>
      <dt>وب‌سایت</dt><dd><?= h($detail['store_website'] ?: '—') ?></dd>
      <dt>اینستاگرام</dt><dd><?= h($detail['store_instagram'] ?: '—') ?></dd>
      <dt>تلگرام</dt><dd><?= h($detail['store_telegram'] ?: '—') ?></dd>
      <dt>ساعات کاری</dt><dd><?= h($detail['store_opening_hours'] ?: '—') ?></dd>
      <dt>موقعیت</dt><dd><?= h(trim(($detail['store_lat'] ?? '') . ', ' . ($detail['store_lng'] ?? ''), ', ') ?: '—') ?></dd>
    </dl>

    <?php if (!empty($detail['admin_note'])): ?>
    <div class="alert alert-warning mt-4"><strong>یادداشت ادمین:</strong> <?= h($detail['admin_note']) ?></div>
    <?php endif; ?>

    <?php if (($detail['status'] ?? '') === 'pending'): ?>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px">
      <form method="POST" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-primary" onclick="return confirm('فروشگاه با این اطلاعات ثبت شود؟')">
          <i class="bi bi-check-lg"></i> تأیید و ثبت فروشگاه
        </button>
      </form>
      <form method="POST" style="flex:1;min-width:280px">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>">
        <input type="hidden" name="action" value="reject">
        <div style="display:flex;gap:8px">
          <input type="text" class="form-control" name="admin_note" placeholder="دلیل رد (اختیاری)">
          <button type="submit" class="btn btn-danger">رد</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:20px">
    <h3 style="margin:0 0 12px;font-size:1rem">اقدامات</h3>
    <a href="<?= APP_URL ?>/admin/store_create_edit.php?id=<?= (int)$detail['user_id'] ?>" class="btn btn-outline w-100 mb-2">ویرایش کاربر / فروشگاه</a>
    <a href="?status=<?= h($statusFilter) ?>" class="btn btn-secondary w-100">بازگشت به لیست</a>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>نوع</th>
        <th>فروشگاه</th>
        <th>کاربر</th>
        <th>تلفن</th>
        <th>وضعیت</th>
        <th>تاریخ</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($requests)): ?>
      <tr><td colspan="8" class="text-muted">درخواستی یافت نشد.</td></tr>
      <?php endif; ?>
      <?php foreach ($requests as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td class="fs-xs"><?= h(provider_type_labels()[normalize_provider_type($r['provider_type'] ?? 'normal_store')] ?? '—') ?></td>
        <td><strong><?= h($r['store_name']) ?></strong></td>
        <td><?= h($r['user_name']) ?></td>
        <td class="fs-xs"><?= h($r['store_phone'] ?: $r['user_phone']) ?></td>
        <td><?= h($statusLabels[$r['status']] ?? $r['status']) ?></td>
        <td class="fs-xs text-muted"><?= h(timeago($r['created_at'])) ?></td>
        <td><a href="?id=<?= (int)$r['id'] ?>&status=<?= h($statusFilter) ?>" class="btn btn-sm btn-outline">مشاهده</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('درخواست فروشگاه');
render_admin_shell($admin, 'store_requests', $content);
render_admin_footer();
