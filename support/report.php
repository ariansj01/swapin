<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';

$user    = auth_user();
$success = false;
$errors  = [];
$vals    = ['message' => '', 'steps' => '', 'page_url' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('error_report', 10, 3600);
    $vals['message']  = $_POST['message'] ?? '';
    $vals['steps']    = $_POST['steps'] ?? '';
    $vals['page_url'] = $_POST['page_url'] ?? '';

    $result = submit_error_report(
        $user ? (int)$user['id'] : null,
        $vals['message'],
        $vals['steps'],
        $vals['page_url']
    );

    if (isset($result['errors'])) {
        $errors = $result['errors'];
    } else {
        $success = true;
        $vals = ['message' => '', 'steps' => '', 'page_url' => ''];
    }
} else {
    $vals['page_url'] = clean($_GET['url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
}

render_head('گزارش خطا | سواَپین', 'گزارش مشکلات فنی سایت به تیم سواَپین', [
    'canonical' => APP_URL . '/support/report',
]);
render_panel_styles();
render_navbar($user);
if ($user) {
    render_user_panel_open($user, 'support');
}
?>

<?php if ($user): ?>
  <div class="dash-panel">
    <?php render_panel_page_header('گزارش خطا', 'جزئیات مشکل فنی را برای تیم فنی بفرستید', APP_URL . '/support', 'بازگشت به پشتیبانی'); ?>
<?php else: ?>
<main id="main-content" class="section-sm">
  <div class="container-sm">

    <nav style="font-size:.875rem;margin-bottom:var(--sp-4)">
      <a href="<?= APP_URL ?>/support"><i class="bi bi-arrow-right"></i> بازگشت به پشتیبانی</a>
    </nav>
<?php endif; ?>

    <div style="text-align:center;padding:var(--sp-4) 0 var(--sp-5)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:var(--danger);margin-bottom:var(--sp-3)">
        <i class="bi bi-bug" style="font-size:1.5rem;color:#fff"></i>
      </div>
      <h1 style="font-size:1.75rem;margin:0 0 var(--sp-2)">گزارش خطا</h1>
      <p style="color:var(--text-muted)">باگ، خطای نمایش یا مشکل فنی دیدید؟ جزئیات را بنویسید تا برطرف کنیم.</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-6">
      <i class="bi bi-check-circle-fill"></i>
      <div><strong>گزارش ثبت شد!</strong> از همکاری شما سپاسگزاریم. تیم فنی بررسی می‌کند.</div>
    </div>
    <?php endif; ?>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-6)">
        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="page_url">آدرس صفحه (اختیاری)</label>
            <input type="url" id="page_url" name="page_url" class="form-control"
                   value="<?= h($vals['page_url']) ?>" placeholder="https://…" dir="ltr">
          </div>
          <div class="form-group">
            <label class="form-label" for="message">چه مشکلی پیش آمد؟ <span class="required">*</span></label>
            <textarea id="message" name="message" rows="4" class="form-control <?= isset($errors['message']) ? 'is-invalid' : '' ?>"
                      placeholder="مثلاً: دکمه ثبت آگهی کار نمی‌کند…" required><?= h($vals['message']) ?></textarea>
            <?php if (isset($errors['message'])): ?><div class="invalid-feedback"><?= h($errors['message']) ?></div><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label" for="steps">مراحل تکرار (اختیاری)</label>
            <textarea id="steps" name="steps" rows="4" class="form-control"
                      placeholder="۱. وارد شدم&#10;۲. روی … کلیک کردم&#10;۳. خطا دیدم"><?= h($vals['steps']) ?></textarea>
          </div>
          <?php if (!$user): ?>
          <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle"></i> برای پیگیری بهتر، <a href="<?= APP_URL ?>/auth/login">وارد شوید</a>.
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> ارسال گزارش</button>
        </form>
      </div>
    </div>

  </div>
<?php if ($user): ?>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php else: ?>
</main>
<?php endif; ?>

<?php render_footer(); ?>
