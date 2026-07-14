<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';

$user   = require_auth();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $result = create_support_ticket(
        (int)$user['id'],
        $_POST['subject'] ?? '',
        clean($_POST['category'] ?? 'other'),
        $_POST['body'] ?? ''
    );
    if (isset($result['errors'])) {
        $errors = $result['errors'];
    } else {
        header('Location: ' . APP_URL . '/support/view?id=' . $result['ticket_id'] . '&created=1');
        exit;
    }
}

$tickets = DB::fetchAll(
    'SELECT t.*, (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS msg_count
     FROM support_tickets t
     WHERE t.user_id = ?
     ORDER BY t.updated_at DESC',
    [$user['id']]
);

$catLabels = support_category_labels();
$statusLabels = support_status_labels();

render_head('پشتیبانی سواَپین | تیکت‌ها', 'ثبت تیکت و پیگیری درخواست‌های پشتیبانی سواَپین.', [
    'canonical' => APP_URL . '/support',
]);
render_panel_styles();
render_navbar($user);
render_user_panel_open($user, 'support');
?>

  <div class="dash-panel">
    <?php render_panel_page_header('پشتیبانی', 'ثبت تیکت، گزارش خطا و پیگیری پاسخ‌ها'); ?>
    <div class="dash-page-head__actions" style="justify-content:flex-end;margin-bottom:24px">
      <a href="<?= APP_URL ?>/dashboard" class="btn btn-outline btn-sm">
        <i class="bi bi-arrow-right"></i> بازگشت
      </a>
    </div>

    <div style="text-align:center;padding:var(--sp-6) 0 var(--sp-5)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:var(--primary);margin-bottom:var(--sp-3)">
        <i class="bi bi-headset" style="font-size:1.5rem;color:#fff"></i>
      </div>
      <h1 style="font-size:1.75rem;margin:0 0 var(--sp-2)">پشتیبانی</h1>
      <p style="color:var(--text-muted)">سؤال یا مشکلی دارید؟ تیکت ثبت کنید — معمولاً ظرف ۲۴ ساعت پاسخ می‌دهیم.</p>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4);margin-bottom:var(--sp-6)">
      <a href="<?= APP_URL ?>/support/report" class="card" style="text-decoration:none;color:inherit">
        <div class="card-body" style="text-align:center;padding:var(--sp-5)">
          <i class="bi bi-bug" style="font-size:1.5rem;color:var(--danger);display:block;margin-bottom:var(--sp-2)"></i>
          <strong>گزارش خطا</strong>
          <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-2) 0 0">مشکل فنی در سایت؟ به ما بگویید.</p>
        </div>
      </a>
      <a href="<?= APP_URL ?>/fraud-prevention" class="card" style="text-decoration:none;color:inherit">
        <div class="card-body" style="text-align:center;padding:var(--sp-5)">
          <i class="bi bi-shield-exclamation" style="font-size:1.5rem;color:var(--warning);display:block;margin-bottom:var(--sp-2)"></i>
          <strong>راهنمای امنیت</strong>
          <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-2) 0 0">کلاهبرداری را بشناسید و جلوگیری کنید.</p>
        </div>
      </a>
    </div>

    <div class="card mb-6">
      <div class="card-header"><h2 style="margin:0;font-size:1.125rem"><i class="bi bi-plus-circle"></i> ثبت تیکت جدید</h2></div>
      <div class="card-body">
        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="category">دسته‌بندی</label>
            <select id="category" name="category" class="form-control">
              <?php foreach ($catLabels as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($_POST['category'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="subject">موضوع <span class="required">*</span></label>
            <input type="text" id="subject" name="subject" class="form-control <?= isset($errors['subject']) ? 'is-invalid' : '' ?>"
                   value="<?= h($_POST['subject'] ?? '') ?>" placeholder="خلاصه مشکل یا سؤال" required>
            <?php if (isset($errors['subject'])): ?><div class="invalid-feedback"><?= h($errors['subject']) ?></div><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label" for="body">توضیحات <span class="required">*</span></label>
            <textarea id="body" name="body" rows="5" class="form-control <?= isset($errors['body']) ? 'is-invalid' : '' ?>"
                      placeholder="جزئیات را بنویسید…" required><?= h($_POST['body'] ?? '') ?></textarea>
            <?php if (isset($errors['body'])): ?><div class="invalid-feedback"><?= h($errors['body']) ?></div><?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> ارسال تیکت</button>
        </form>
      </div>
    </div>

    <h2 style="font-size:1.125rem;margin-bottom:var(--sp-4)"><i class="bi bi-ticket-perforated"></i> تیکت‌های من</h2>

    <?php if (empty($tickets)): ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <p>هنوز تیکتی ثبت نکرده‌اید.</p>
    </div>
    <?php else: ?>
    <div class="card">
      <table class="admin-table" style="width:100%">
        <thead>
          <tr><th>#</th><th>موضوع</th><th>دسته</th><th>وضعیت</th><th>آخرین به‌روزرسانی</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $t): ?>
          <tr>
            <td><?= $t['id'] ?></td>
            <td><?= h(mb_strimwidth($t['subject'], 0, 40, '…')) ?></td>
            <td><?= h($catLabels[$t['category']] ?? $t['category']) ?></td>
            <td><span class="badge badge-<?= $t['status'] === 'open' ? 'warning' : ($t['status'] === 'answered' ? 'success' : 'info') ?>"><?= h($statusLabels[$t['status']] ?? $t['status']) ?></span></td>
            <td class="fs-xs"><?= timeago($t['updated_at']) ?></td>
            <td><a href="<?= APP_URL ?>/support/view?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline">مشاهده</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
