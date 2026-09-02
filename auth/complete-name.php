<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();

$redir = safe_redirect_path(clean($_GET['redirect'] ?? ''));
$nameValue = trim((string)($user['name'] ?? ''));

if ($nameValue !== '') {
    $dest = $redir ? APP_URL . $redir : APP_URL . '/?welcome=1';
    header('Location: ' . $dest);
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('complete_name', 10, 3600);

    $fullName = trim(clean($_POST['full_name'] ?? ''));
    $nameLen = mb_strlen($fullName);

    if ($fullName === '') {
        $errors['full_name'] = 'لطفاً نام و نام خانوادگی خود را وارد کنید.';
    } elseif ($nameLen < 3) {
        $errors['full_name'] = 'نام و نام خانوادگی باید حداقل ۳ کاراکتر باشد.';
    } elseif ($nameLen > 100) {
        $errors['full_name'] = 'نام و نام خانوادگی نباید بیش از ۱۰۰ کاراکتر باشد.';
    }

    if (empty($errors)) {
        DB::update('users', [
            'name' => $fullName,
        ], 'id = ?', [(int)$user['id']]);

        $dest = $redir ? APP_URL . $redir : APP_URL . '/?welcome=1';
        header('Location: ' . $dest);
        exit;
    }
}

render_head('تکمیل اطلاعات | سواَپین', 'تکمیل اطلاعات کاربری در سواَپین', [
    'canonical' => APP_URL . '/auth/complete-name',
]);
render_navbar(null);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:480px;margin:0 auto">
      <div class="card-body" style="padding:var(--sp-8)">

        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>تکمیل اطلاعات</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">
            برای ادامه، نام و نام خانوادگی خود را وارد کنید
          </p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i>
          <div><?= h($errors['full_name'] ?? 'لطفاً خطاهای زیر را برطرف کنید.') ?></div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <?php if ($redir): ?>
          <input type="hidden" name="redirect" value="<?= h($redir) ?>">
          <?php endif; ?>
          <div class="form-group">
            <label class="form-label" for="full_name">
              نام و نام خانوادگی <span class="required">*</span>
            </label>
            <input
              type="text"
              class="form-control form-control-lg <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>"
              id="full_name"
              name="full_name"
              placeholder="مثال: علی رضایی"
              autocomplete="name"
              required
              autofocus
              maxlength="100"
              value="<?= isset($_POST['full_name']) ? h($_POST['full_name']) : '' ?>"
              style="font-size: 1rem;"
            >
            <?php if (isset($errors['full_name'])): ?>
            <div class="invalid-feedback"><?= h($errors['full_name']) ?></div>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg" style="margin-top: var(--sp-4);">
            ادامه
          </button>
        </form>

      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
