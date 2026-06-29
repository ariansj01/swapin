<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$id    = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = clean($_POST['action'] ?? '');
    $note   = clean($_POST['note'] ?? '');

    if ($userId && $action === 'approve') {
        admin_approve_kyc($userId, $note);
        admin_set_flash('احراز هویت کاربر تأیید شد.');
    } elseif ($userId && $action === 'reject') {
        if (mb_strlen($note) < 5) {
            admin_set_flash('دلیل رد را بنویسید.', 'error');
        } else {
            admin_reject_kyc($userId, $note);
            admin_set_flash('احراز هویت رد شد.');
        }
    }
    header('Location: ' . APP_URL . '/admin/kyc.php' . ($userId ? "?id=$userId" : ''));
    exit;
}

[$flash, $flashType] = admin_flash();

$kycUser = $id ? DB::fetch('SELECT * FROM users WHERE id = ?', [$id]) : null;

$list = DB::fetchAll(
    'SELECT id, name, email, phone, kyc_status, seller_type, store_name, national_id, created_at, updated_at
     FROM users WHERE kyc_status IN ("pending","approved","rejected")
     ORDER BY FIELD(kyc_status,"pending","rejected","approved"), updated_at DESC LIMIT 100'
);

$kycLabels = ['none' => 'ثبت نشده', 'pending' => 'در انتظار', 'approved' => 'تأیید', 'rejected' => 'رد'];

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <h1>احراز هویت (KYC)</h1>
</div>

<?php if ($kycUser && in_array($kycUser['kyc_status'], ['pending','approved','rejected'], true)): ?>
<div class="admin-detail-grid mb-6">
  <div class="card">
    <div class="card-header"><h3 style="margin:0"><?= h($kycUser['name']) ?></h3></div>
    <div class="card-body fs-sm" style="display:grid;gap:var(--sp-2)">
      <div><strong>ایمیل:</strong> <?= h($kycUser['email']) ?></div>
      <div><strong>تلفن:</strong> <?= h($kycUser['phone']) ?></div>
      <div><strong>کد ملی:</strong> <?= h($kycUser['national_id'] ?: '—') ?></div>
      <div><strong>شبا/حساب:</strong> <?= h($kycUser['bank_account'] ?: '—') ?></div>
      <div><strong>نوع:</strong> <?= ($kycUser['seller_type'] ?? '') === 'store' ? 'فروشگاه — ' . h($kycUser['store_name'] ?? '') : 'شخصی' ?></div>
      <div><strong>وضعیت:</strong> <?= $kycLabels[$kycUser['kyc_status']] ?? $kycUser['kyc_status'] ?></div>
      <?php if ($kycUser['kyc_note']): ?>
      <div><strong>یادداشت:</strong> <?= h($kycUser['kyc_note']) ?></div>
      <?php endif; ?>
      <?php if ($kycUser['id_card_image']): ?>
      <div style="margin-top:var(--sp-3)">
        <strong>تصویر کارت ملی:</strong><br>
        <a href="<?= UPLOAD_URL . h($kycUser['id_card_image']) ?>" target="_blank">
          <img src="<?= UPLOAD_URL . h($kycUser['id_card_image']) ?>" alt="کارت ملی" style="max-width:100%;max-height:320px;margin-top:var(--sp-2);border-radius:var(--radius-md);border:1px solid var(--border)">
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">تصمیم</h3></div>
    <div class="card-body">
      <?php if ($kycUser['kyc_status'] === 'pending'): ?>
      <form method="POST" class="mb-4">
        <input type="hidden" name="user_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-lg"></i> تأیید هویت</button>
      </form>
      <form method="POST">
        <input type="hidden" name="user_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="reject">
        <div class="form-group">
          <label class="form-label">دلیل رد</label>
          <textarea class="form-control" name="note" rows="3" required></textarea>
        </div>
        <button type="submit" class="btn btn-danger w-100">رد درخواست</button>
      </form>
      <?php else: ?>
      <p class="fs-sm" style="color:var(--text-muted)">وضعیت: <?= $kycLabels[$kycUser['kyc_status']] ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead>
      <tr><th>نام</th><th>ایمیل</th><th>نوع</th><th>وضعیت</th><th>تاریخ</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($list as $u): ?>
      <tr>
        <td><?= h($u['name']) ?></td>
        <td><?= h($u['email']) ?></td>
        <td><?= ($u['seller_type'] ?? '') === 'store' ? 'فروشگاه' : 'شخصی' ?></td>
        <td><span class="badge badge-<?= match($u['kyc_status']) { 'pending'=>'warning','approved'=>'success','rejected'=>'danger', default=>'info' } ?>"><?= $kycLabels[$u['kyc_status']] ?? $u['kyc_status'] ?></span></td>
        <td class="fs-xs"><?= persian_date($u['updated_at'] ?? $u['created_at']) ?></td>
        <td><a href="?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline">بررسی</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('احراز هویت');
render_admin_shell($admin, 'kyc', $content);
render_admin_footer();
