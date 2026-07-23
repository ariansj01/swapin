<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$id    = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $reqId  = (int)($_POST['request_id'] ?? 0);
    $action = clean($_POST['action'] ?? '');
    $report = clean($_POST['report'] ?? '');

    if ($reqId && $action === 'resolve') {
        $result = clean($_POST['result'] ?? '');
        if (admin_resolve_inspection($reqId, $result, $report)) {
            admin_set_flash('نتیجه بازرسی ثبت شد.');
        } else {
            admin_set_flash('خطا در ثبت نتیجه.', 'error');
        }
    } elseif ($reqId && $action === 'schedule') {
        DB::update('inspection_requests', ['status' => 'scheduled'], 'id = ?', [$reqId]);
        admin_set_flash('درخواست زمان‌بندی شد.');
    }
    header('Location: ' . APP_URL . '/admin/inspections.php' . ($reqId ? "?id=$reqId" : ''));
    exit;
}

[$flash, $flashType] = admin_flash();

$detail = $id ? DB::fetch(
    'SELECT ir.*, l.title AS listing_title, u.name AS user_name, u.email
     FROM inspection_requests ir
     JOIN listings l ON l.id = ir.listing_id
     JOIN users u ON u.id = ir.user_id
     WHERE ir.id = ?',
    [$id]
) : null;

$list = DB::fetchAll(
    'SELECT ir.id, ir.status, ir.result, ir.created_at, l.title, u.name AS user_name
     FROM inspection_requests ir
     JOIN listings l ON l.id = ir.listing_id
     JOIN users u ON u.id = ir.user_id
     ORDER BY FIELD(ir.status,"pending","scheduled","done","cancelled"), ir.created_at DESC
     LIMIT 100'
);

$statusLabels = ['pending' => 'در انتظار', 'scheduled' => 'زمان‌بندی', 'done' => 'انجام‌شده', 'cancelled' => 'لغو'];
$resultLabels = ['passed' => 'قبول', 'failed' => 'رد', 'conditional' => 'مشروط'];

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header"><h1>بازرسی کارشناسی</h1></div>

<?php if ($detail): ?>
<div class="admin-detail-grid mb-6">
  <div class="card">
    <div class="card-header"><h3 style="margin:0"><?= h($detail['listing_title']) ?></h3></div>
    <div class="card-body fs-sm" style="display:grid;gap:var(--sp-2)">
      <div><strong>درخواست‌کننده:</strong> <?= h($detail['user_name']) ?> (<?= h($detail['email']) ?>)</div>
      <div><strong>وضعیت:</strong> <?= $statusLabels[$detail['status']] ?? $detail['status'] ?></div>
      <div><strong>نوع:</strong> <?= h($detail['type']) ?></div>
      <div><strong>هزینه:</strong> <?= number_format((float)$detail['price'], 0) ?> تومان</div>
      <div><strong>تاریخ:</strong> <?= persian_datetime($detail['created_at']) ?></div>
      <?php if ($detail['report']): ?>
      <div><strong>گزارش:</strong> <?= h($detail['report']) ?></div>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$detail['listing_id'] ?>" target="_blank" class="btn btn-sm btn-outline mt-2">مشاهده آگهی</a>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">ثبت نتیجه</h3></div>
    <div class="card-body">
      <?php if ($detail['status'] !== 'done'): ?>
      <?php if ($detail['status'] === 'pending'): ?>
      <form method="POST" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="schedule">
        <button type="submit" class="btn btn-outline w-100">زمان‌بندی بازرسی</button>
      </form>
      <?php endif; ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="resolve">
        <div class="form-group">
          <label class="form-label">نتیجه</label>
          <select name="result" class="form-control" required>
            <option value="passed">قبول — کالا مطابق است</option>
            <option value="conditional">مشروط — با توضیح</option>
            <option value="failed">رد — مغایرت</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">گزارش کارشناس</label>
          <textarea class="form-control" name="report" rows="4" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-100">ثبت نتیجه</button>
      </form>
      <?php else: ?>
      <p>نتیجه: <strong><?= $resultLabels[$detail['result']] ?? $detail['result'] ?></strong></p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead><tr><th>آگهی</th><th>کاربر</th><th>وضعیت</th><th>نتیجه</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($list as $r): ?>
      <tr>
        <td><?= h(mb_strimwidth($r['title'], 0, 40, '…')) ?></td>
        <td><?= h($r['user_name']) ?></td>
        <td><?= $statusLabels[$r['status']] ?? $r['status'] ?></td>
        <td><?= $r['result'] ? ($resultLabels[$r['result']] ?? $r['result']) : '—' ?></td>
        <td class="fs-xs"><?= persian_date($r['created_at']) ?></td>
        <td><a href="?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline">بررسی</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('بازرسی');
render_admin_shell($admin, 'inspections', $content);
render_admin_footer();
