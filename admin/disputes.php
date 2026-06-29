<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$id    = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $disputeId = (int)($_POST['dispute_id'] ?? 0);
    $action    = clean($_POST['action'] ?? '');
    $note      = clean($_POST['note'] ?? '');

    if ($disputeId && in_array($action, ['resolved_a', 'resolved_b', 'dismissed', 'reviewing'], true)) {
        admin_resolve_dispute($disputeId, $action, $note);
        admin_set_flash('اختلاف به‌روزرسانی شد.');
    }
    header('Location: ' . APP_URL . '/admin/disputes.php' . ($disputeId ? "?id=$disputeId" : ''));
    exit;
}

[$flash, $flashType] = admin_flash();

$reasonLabels = [
    'wrong_item' => 'کالای اشتباه', 'damaged' => 'آسیب‌دیده',
    'missing' => 'کسری', 'fraud' => 'کلاهبرداری', 'other' => 'سایر',
];
$statusLabels = [
    'open' => 'باز', 'reviewing' => 'در حال بررسی',
    'resolved_a' => 'نفع کاربر A', 'resolved_b' => 'نفع کاربر B', 'dismissed' => 'رد شکایت',
];

$detail = $id ? DB::fetch(
    'SELECT d.*, t.id AS trade_num,
            fa.name AS filed_name, fb.name AS against_name
     FROM disputes d
     JOIN trades t ON t.id = d.trade_id
     JOIN users fa ON fa.id = d.filed_by
     JOIN users fb ON fb.id = d.against
     WHERE d.id = ?',
    [$id]
) : null;

$list = DB::fetchAll(
    'SELECT d.id, d.status, d.reason, d.created_at, fa.name AS filed_name, fb.name AS against_name
     FROM disputes d
     JOIN users fa ON fa.id = d.filed_by
     JOIN users fb ON fb.id = d.against
     ORDER BY FIELD(d.status,"open","reviewing","resolved_a","resolved_b","dismissed"), d.created_at DESC
     LIMIT 100'
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header"><h1>اختلافات معاملات</h1></div>

<?php if ($detail): ?>
<div class="admin-detail-grid mb-6">
  <div class="card">
    <div class="card-header"><h3 style="margin:0">اختلاف #<?= $detail['id'] ?> — معامله #<?= $detail['trade_num'] ?></h3></div>
    <div class="card-body fs-sm" style="display:grid;gap:var(--sp-2)">
      <div><strong>ثبت‌کننده:</strong> <?= h($detail['filed_name']) ?></div>
      <div><strong>طرف مقابل:</strong> <?= h($detail['against_name']) ?></div>
      <div><strong>دلیل:</strong> <?= $reasonLabels[$detail['reason']] ?? $detail['reason'] ?></div>
      <div><strong>وضعیت:</strong> <?= $statusLabels[$detail['status']] ?? $detail['status'] ?></div>
      <div style="margin-top:var(--sp-2)"><strong>توضیحات:</strong><br><?= nl2br(h($detail['description'])) ?></div>
      <?php if ($detail['evidence']): ?>
      <div><a href="<?= UPLOAD_URL . h($detail['evidence']) ?>" target="_blank">مشاهده مدرک</a></div>
      <?php endif; ?>
      <?php if ($detail['admin_note']): ?>
      <div><strong>یادداشت مدیر:</strong> <?= h($detail['admin_note']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">حل اختلاف</h3></div>
    <div class="card-body">
      <?php if (in_array($detail['status'], ['open', 'reviewing'], true)): ?>
      <form method="POST">
        <input type="hidden" name="dispute_id" value="<?= $id ?>">
        <div class="form-group">
          <label class="form-label">یادداشت مدیر</label>
          <textarea class="form-control" name="note" rows="3"></textarea>
        </div>
        <div class="admin-actions" style="flex-direction:column">
          <button type="submit" name="action" value="reviewing" class="btn btn-outline w-100">علامت «در حال بررسی»</button>
          <button type="submit" name="action" value="resolved_a" class="btn btn-success w-100">نفع <?= h($detail['filed_name']) ?></button>
          <button type="submit" name="action" value="resolved_b" class="btn btn-accent w-100">نفع <?= h($detail['against_name']) ?></button>
          <button type="submit" name="action" value="dismissed" class="btn btn-danger w-100" onclick="return confirm('شکایت رد شود؟')">رد شکایت</button>
        </div>
      </form>
      <?php else: ?>
      <p class="fs-sm" style="color:var(--text-muted)">این اختلاف بسته شده است.</p>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/trades.php?trade=<?= (int)$detail['trade_id'] ?>" class="btn btn-ghost w-100 mt-3" target="_blank">مشاهده معامله</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead><tr><th>#</th><th>ثبت‌کننده</th><th>طرف</th><th>دلیل</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($list as $d): ?>
      <tr>
        <td><?= $d['id'] ?></td>
        <td><?= h($d['filed_name']) ?></td>
        <td><?= h($d['against_name']) ?></td>
        <td><?= $reasonLabels[$d['reason']] ?? $d['reason'] ?></td>
        <td><span class="badge badge-<?= in_array($d['status'], ['open','reviewing']) ? 'warning' : 'info' ?>"><?= $statusLabels[$d['status']] ?? $d['status'] ?></span></td>
        <td class="fs-xs"><?= persian_date($d['created_at']) ?></td>
        <td><a href="?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline">بررسی</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('اختلافات');
render_admin_shell($admin, 'disputes', $content);
render_admin_footer();
