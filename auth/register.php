<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

// Redirect if logged in
if (auth_user()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

$errors = [];
$vals   = ['name' => '', 'email' => '', 'phone' => '', 'city' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vals['name']  = clean($_POST['name']  ?? '');
    $vals['email'] = clean($_POST['email'] ?? '');
    $vals['phone'] = clean($_POST['phone'] ?? '');
    $vals['city']  = clean($_POST['city']  ?? '');
    $pass          = $_POST['password']         ?? '';
    $passConf      = $_POST['password_confirm'] ?? '';

    // Validate
    if (!$vals['name'] || mb_strlen($vals['name']) < 2)
        $errors['name'] = 'نام کامل الزامی است (حداقل ۲ کاراکتر)';

    if (!$vals['email'] || !filter_var($vals['email'], FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'لطفاً یک آدرس ایمیل معتبر وارد کنید';

    if (!$vals['phone'] || !preg_match('/^\+?[0-9]{8,15}$/', $vals['phone']))
        $errors['phone'] = 'لطفاً یک شماره تلفن معتبر وارد کنید';

    if (strlen($pass) < 8)
        $errors['password'] = 'رمز عبور باید حداقل ۸ کاراکتر باشد';

    if ($pass !== $passConf)
        $errors['password_confirm'] = 'رمزهای عبور یکسان نیستند';

    if (empty($errors)) {
        // Uniqueness check
        if (DB::fetch('SELECT id FROM users WHERE email = ?', [$vals['email']]))
            $errors['email'] = 'این ایمیل قبلاً ثبت شده است';
        if (DB::fetch('SELECT id FROM users WHERE phone = ?', [$vals['phone']]))
            $errors['phone'] = 'این شماره تلفن قبلاً ثبت شده است';
    }

    if (empty($errors)) {
        $uid = DB::insert('users', [
            'name'               => $vals['name'],
            'email'              => $vals['email'],
            'phone'              => $vals['phone'],
            'city'               => $vals['city'] ?: null,
            'password_hash'      => password_hash($pass, PASSWORD_BCRYPT),
            'verification_level' => 1, // email registered
            'credit_balance'     => WELCOME_BONUS,
        ]);
        DB::insert('wallet_transactions', [
            'user_id'       => $uid,
            'type'          => 'deposit',
            'amount'        => WELCOME_BONUS,
            'balance_after' => WELCOME_BONUS,
            'note'          => 'پاداش خوش‌آمدگویی',
        ]);
        login_user($uid);
        header('Location: ' . APP_URL . '/dashboard.php?welcome=1'); exit;
    }
}

render_head('ایجاد حساب');
render_navbar(null);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:480px;margin:0 auto">
      <div class="card-body" style="padding:var(--sp-8)">

        <!-- Header -->
        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>حساب کاربری بسازید</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">با عضویت، <?= number_format(WELCOME_BONUS, 0) ?> <?= CREDIT_UNIT ?> پاداش خوش‌آمدگویی دریافت کنید</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i>
          <div>لطفاً خطاهای زیر را برطرف کنید.</div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate id="register-form">
          <div class="form-group">
            <label class="form-label" for="name">نام کامل <span class="required">*</span></label>
            <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                   id="name" name="name" value="<?= h($vals['name']) ?>"
                   placeholder="نام و نام خانوادگی" autocomplete="name" required>
            <?php if (isset($errors['name'])): ?>
            <div class="invalid-feedback"><?= h($errors['name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="email">آدرس ایمیل <span class="required">*</span></label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   id="email" name="email" value="<?= h($vals['email']) ?>"
                   placeholder="you@example.com" autocomplete="email" required>
            <?php if (isset($errors['email'])): ?>
            <div class="invalid-feedback"><?= h($errors['email']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="phone">شماره تلفن <span class="required">*</span></label>
            <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                   id="phone" name="phone" value="<?= h($vals['phone']) ?>"
                   placeholder="+989123456789" autocomplete="tel" required>
            <?php if (isset($errors['phone'])): ?>
            <div class="invalid-feedback"><?= h($errors['phone']) ?></div>
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
            <div style="position:relative">
              <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                     id="password" name="password"
                     placeholder="حداقل ۸ کاراکتر" autocomplete="new-password" required>
              <button type="button" class="btn btn-ghost btn-icon btn-sm" id="toggle-pass"
                      style="position:absolute;left:6px;top:50%;transform:translateY(-50%);color:var(--text-muted)">
                <i class="bi bi-eye" id="pass-icon"></i>
              </button>
            </div>
            <?php if (isset($errors['password'])): ?>
            <div class="invalid-feedback"><?= h($errors['password']) ?></div>
            <?php endif; ?>
            <div class="form-hint">
              <span id="pass-strength"></span>
            </div>
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
            پس از ثبت‌نام، برای فروش و دریافت پرداخت <a href="<?= APP_URL ?>/profile/edit.php">احراز هویت (KYC)</a> را تکمیل کنید.
          </p>

          <p class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-5)">
            با ثبت‌نام، <a href="#">شرایط استفاده</a> و <a href="#">سیاست حریم خصوصی</a> را می‌پذیرید.
          </p>

          <button type="submit" class="btn btn-primary w-100 btn-lg" id="submit-btn">
            <span id="btn-text">ایجاد حساب</span>
            <span id="btn-spinner" class="spinner" style="display:none;width:18px;height:18px;border-width:2px;border-color:rgba(255,255,255,.3);border-top-color:#fff"></span>
          </button>
        </form>

        <p class="text-center fs-sm mt-6" style="color:var(--text-muted)">
          قبلاً حساب دارید؟ <a href="<?= APP_URL ?>/auth/login.php" style="font-weight:600">ورود</a>
        </p>

      </div>
    </div>
  </div>
</div>

<script>
// Password toggle
document.getElementById('toggle-pass').addEventListener('click', function() {
  const inp  = document.getElementById('password');
  const icon = document.getElementById('pass-icon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'bi bi-eye';
  }
});

// Password strength
document.getElementById('password').addEventListener('input', function() {
  const v = this.value;
  const el = document.getElementById('pass-strength');
  let score = 0;
  if (v.length >= 8) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const labels = ['', '<span style="color:var(--danger)">ضعیف</span>', '<span style="color:var(--warning)">متوسط</span>', '<span style="color:var(--info)">خوب</span>', '<span style="color:var(--success)">قوی</span>'];
  el.innerHTML = v ? 'قدرت: ' + (labels[score] || '') : '';
});

// Loading state
document.getElementById('register-form').addEventListener('submit', function() {
  document.getElementById('btn-text').style.display = 'none';
  document.getElementById('btn-spinner').style.display = 'inline-block';
  document.getElementById('submit-btn').disabled = true;
});
</script>

<?php render_footer(); ?>
