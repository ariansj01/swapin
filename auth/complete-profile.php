<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (auth_user()) {
    header('Location: ' . APP_URL . '/dashboard'); exit;
}

$isGoogleUser = (isset($_GET['google_login']) && $_GET['google_login'] === '1' && isset($_SESSION['google_user_id_for_profile_completion']));

$googleUserId = 0;
$currentUser  = null;

if ($isGoogleUser) {
    $googleUserId = (int) $_SESSION['google_user_id_for_profile_completion'];
    $currentUser = DB::fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$googleUserId]);

    if (!$currentUser) {
        // User not found, something is wrong, redirect to login
        header('Location: ' . APP_URL . '/auth/login'); exit;
    }

    // If a Google user already has phone and city, they don't need to complete the profile anymore
    if (!empty($currentUser['phone']) && !empty($currentUser['city'])) {
        unset($_SESSION['google_user_id_for_profile_completion']);
        header('Location: ' . APP_URL . '/'); exit;
    }

    // Pre-fill values for Google user
    $vals['name']  = $currentUser['name'] ?? '';
    $vals['email'] = $currentUser['email'] ?? '';
    $vals['city']  = $currentUser['city'] ?? '';
    $vals['phone'] = $currentUser['phone'] ?? ''; // Pre-fill phone for Google user
} elseif (!isset($_SESSION['new_user_phone'])) {
    // Non-Google user, but no phone in session (means not coming from OTP verification)
    header('Location: ' . APP_URL . '/auth/login'); exit;
}

$phoneIntl = $_SESSION['new_user_phone'] ?? ''; // Will be empty for Google users, populated for phone users
$redir  = safe_redirect_path(clean($_GET['redirect'] ?? ''));
$errors = [];
$vals   = array_merge(['name' => '', 'email' => '', 'city' => '', 'phone' => ''], $vals); // Ensure phone is in vals

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('complete_profile', 5, 3600);

    $vals['name']  = clean($_POST['name']  ?? '');
    $vals['email'] = clean($_POST['email'] ?? '');
    $vals['city']  = clean($_POST['city']  ?? '');

    if (!$isGoogleUser) {
        $pass          = $_POST['password']         ?? '';
        $passConf      = $_POST['password_confirm'] ?? '';
    }

    // Validate
    if (!$vals['name'] || mb_strlen($vals['name']) < 2)
        $errors['name'] = 'نام کامل الزامی است (حداقل ۲ کاراکتر)';

    if (!$vals['email'])
        $errors['email'] = 'آدرس ایمیل الزامی است';
    elseif (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'لطفاً یک آدرس ایمیل معتبر وارد کنید';

    if (!$vals['city'] || !in_array($vals['city'], iran_cities(), true))
        $errors['city'] = 'لطفاً شهر را از فهرست انتخاب کنید';

    // Check email uniqueness
    if ($vals['email'] && !isset($errors['email'])) {
        $emailCheckQuery = 'SELECT id FROM users WHERE email = ?';
        $emailCheckParams = [$vals['email']];
        if ($isGoogleUser && $currentUser) {
            $emailCheckQuery .= ' AND id != ?';
            $emailCheckParams[] = $currentUser['id'];
        }
        if (DB::fetch($emailCheckQuery, $emailCheckParams))
            $errors['email'] = 'این ایمیل قبلاً ثبت شده است';
    }

    if (!$isGoogleUser) {
        if (strlen($pass) < 8)
            $errors['password'] = 'رمز عبور باید حداقل ۸ کاراکتر باشد';

        if ($pass !== $passConf)
            $errors['password_confirm'] = 'رمزهای عبور یکسان نیستند';
    }

    if (empty($errors)) {
        if ($isGoogleUser && $currentUser) {
            // Update existing Google user
            DB::update('users', [
                'name'               => $vals['name'],
                'email'              => $vals['email'],
                'city'               => $vals['city'],
            ], 'id = ?', [$currentUser['id']]);

            unset($_SESSION['google_user_id_for_profile_completion']);
            login_user((int) $currentUser['id']);
            $dest = $redir ? APP_URL . $redir : APP_URL . '/';
        } else {
            // Create new phone user
            $uid = DB::insert('users', [
                'name'               => $vals['name'],
                'email'              => $vals['email'],
                'phone'              => $phoneIntl,
                'city'               => $vals['city'],
                'password_hash'      => password_hash($pass, PASSWORD_BCRYPT),
                'verification_level' => 1,
                'credit_balance'     => 0,
            ]);
            login_user($uid);
            unset($_SESSION['new_user_phone']);
            $dest = $redir ? APP_URL . $redir : APP_URL . '/?welcome=1';
        }
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
            <label class="form-label" for="email">آدرس ایمیل <span class="required">*</span></label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   id="email" name="email" value="<?= h($vals['email']) ?>"
                   placeholder="you@example.com" autocomplete="email" required <?= $isGoogleUser ? 'readonly' : '' ?>>
            <?php if (isset($errors['email'])): ?>
            <div class="invalid-feedback"><?= h($errors['email']) ?></div>
            <?php endif; ?>
          </div>

          <?php if ($isGoogleUser): // Phone number for Google users only ?>
          <div class="form-group">
            <label class="form-label" for="phone">شماره تلفن <span class="required">*</span></label>
            <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                   id="phone" name="phone" value="<?= h($vals['phone']) ?>"
                   placeholder="مثال: 09123456789" autocomplete="tel" required readonly>
            <?php if (isset($errors['phone'])): ?>
            <div class="invalid-feedback"><?= h($errors['phone']) ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label" for="city">شهر <span class="required">*</span></label>
            <select id="city" name="city" class="form-control <?= isset($errors['city']) ? 'is-invalid' : '' ?>" required>
              <option value="">انتخاب شهر</option>
              <?= render_city_options($vals['city']) ?>
            </select>
            <?php if (isset($errors['city'])): ?>
            <div class="invalid-feedback"><?= h($errors['city']) ?></div>
            <?php endif; ?>
          </div>

          <?php if (!$isGoogleUser): // Password fields for non-Google users only ?>
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
          <?php endif; ?>

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
