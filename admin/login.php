<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (auth_admin()) {
    header('Location: ' . APP_URL . '/admin/'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $user = DB::fetch('SELECT * FROM users WHERE email = ? AND is_active = 1 AND role = "admin"', [$email]);
    if ($user && password_verify($pass, $user['password_hash'])) {
        login_user((int)$user['id']);
        header('Location: ' . APP_URL . '/admin/'); exit;
    }
    $error = 'ایمیل یا رمز عبور اشتباه است، یا دسترسی ادمین ندارید.';
}

render_head('ورود مدیر', '', ['robots' => 'noindex, nofollow']);
?>

<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:var(--sp-8);background:var(--bg)">
  <div class="card" style="max-width:420px;width:100%">
    <div class="card-body" style="padding:var(--sp-8)">
      <div class="text-center mb-6">
        <i class="bi bi-shield-lock" style="font-size:2.5rem;color:var(--primary)"></i>
        <h2 style="margin-top:var(--sp-3)">پنل مدیریت</h2>
        <p class="fs-sm" style="color:var(--text-muted)">ورود مدیران <?= APP_NAME ?></p>
      </div>

      <?php if ($error): ?>
      <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label">ایمیل مدیر</label>
          <input type="email" class="form-control" name="email" value="<?= h($_POST['email'] ?? ADMIN_EMAIL) ?>" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">رمز عبور</label>
          <input type="password" class="form-control" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg">ورود به پنل</button>
      </form>

      <p class="text-center fs-sm mt-5" style="color:var(--text-muted)">
        <a href="<?= APP_URL ?>/">بازگشت به سایت</a>
      </p>
    </div>
  </div>
</div>

<?php render_footer(); ?>
