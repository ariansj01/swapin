<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (auth_user()) {
    header('Location: ' . APP_URL . '/dashboard'); exit;
}

if (!isset($_SESSION['new_user_phone'])) {
    header('Location: ' . APP_URL . '/auth/login'); exit;
}

$phoneIntl = $_SESSION['new_user_phone'];
$redir  = safe_redirect_path(clean($_GET['redirect'] ?? ''));
$errors = [];
$vals   = ['name' => '', 'email' => '', 'city' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('complete_profile', 5, 3600);

    $vals['name']  = clean($_POST['name']  ?? '');
    $vals['email'] = clean($_POST['email'] ?? '');
    $vals['city']  = clean($_POST['city']  ?? '');
    $pass          = $_POST['password']         ?? '';
    $passConf      = $_POST['password_confirm'] ?? '';

    // Validate
    if (!$vals['name'] || mb_strlen($vals['name']) < 2)
        $errors['name'] = 'نام کامل الزامی است (حداقل ۲ کاراکتر)';

    if ($vals['email'] && !filter_var($vals['email'], FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'لطفاً یک آدرس ایمیل معتبر وارد کنید';

    // Check email uniqueness if provided
    if ($vals['email']) {
        if (DB::fetch('SELECT id FROM users WHERE email = ?', [$vals['email']]))
            $errors['email'] = 'این ایمیل قبلاً ثبت شده است';
    }

    if (strlen($pass) < 8)
        $errors['password'] = 'رمز عبور باید حداقل ۸ کاراکتر باشد';

    if ($pass !== $passConf)
        $errors['password_confirm'] = 'رمزهای عبور یکسان نیستند';

    if (empty($errors)) {
        // Create user
        $uid = DB::insert('users', [
            'name'               => $vals['name'],
            'email'              => $vals['email'] ?: null,
            'phone'              => $phoneIntl,
            'city'               => $vals['city'] ?: null,
            'password_hash'      => password_hash($pass, PASSWORD_BCRYPT),
            'verification_level' => $vals['email'] ? 1 : 0,
            'credit_balance'     => 0,
        ]);
        
        // Give welcome bonus
        credit_transact($uid, 'deposit', WELCOME_BONUS, 'پاداش خوش‌آمدگویی', ['ref_type' => 'none']);
        
        login_user($uid);
        unset($_SESSION['new_user_phone']);
        $dest = $redir ? APP_URL . $redir : APP_URL . '/dashboard?welcome=1';
        header('Location: ' . $dest); exit;
    }
}

render_head('تکمیل پروفایل | سواَپین', 'تکمیل اطلاعات پروفایل برای ورود به سواَپین', [
    'canonical' => APP_URL . '/auth/complete-profile',
]);
render_navbar(null);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:480px;margin:0 auto">
      <div class="card-body" style="padding:var(--sp-8)">

        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>تکمیل پروفایل</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">اطلاعات خود را تکمیل کنید</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i>
          <div>لطفاً خطاهای زیر را برطرف کنید.</div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="name">نام کامل <span class="required">*</span></label>
            <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                   id="name" name="name" value="<?= h($vals['name']) ?>"
                   placeholder="نام و نام خانوادگی" autocomplete="name" required autofocus>
            <?php if (isset($errors['name'])): ?>
            <div class="invalid-feedback"><?= h($errors['name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="email">آدرس ایمیل (اختیاری)</label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   id="email" name="email" value="<?= h($vals['email']) ?>"
                   placeholder="you@example.com" autocomplete="email">
            <?php if (isset($errors['email'])): ?>
            <div class="invalid-feedback"><?= h($errors['email']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="city">شهر</label>
            <input type="text" class="form-control"
                   id="city" name="city" value="<?= h($vals['city']) ?>"
                   placeholder="شهر شما (اختیاری)" autocomplete="address-level2">
          </div>

          <div class="form-group">
            <label class="form-label" for="password">رمز عبور <span class="required">*</span></label>
            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                   id="password" name="password"
                   placeholder="حداقل ۸ کاراکتر" autocomplete="new-password" required>
            <?php if (isset($errors['password'])): ?>
            <div class="invalid-feedback"><?= h($errors['password']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="password_confirm">تکرار رمز عبور <span class="required">*</span></label>
            <input type="password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                   id="password_confirm" name="password_confirm"
                   placeholder="رمز عبور را دوباره وارد کنید" autocomplete="new-password" required>
            <?php if (isset($errors['password_confirm'])): ?>
            <div class="invalid-feedback"><?= h($errors['password_confirm']) ?></div>
            <?php endif; ?>
          </div>

          <p class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-5)">
            با تکمیل پروفایل، <a href="#">شرایط استفاده</a> و <a href="#">سیاست حریم خصوصی</a> را می‌پذیرید.
          </p>

          <button type="submit" class="btn btn-primary w-100 btn-lg">تکمیل و ورود</button>
        </form>

      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
