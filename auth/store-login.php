<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$redir = safe_redirect_path(clean($_GET['redirect'] ?? '/store'));
if ($redir === '' || !str_starts_with($redir, '/store')) {
    $redir = '/store';
}

$user = auth_user();
if ($user && store_panel_account($user)) {
    header('Location: ' . APP_URL . $redir);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('store_panel_login', 8, 900);

    $login = trim((string)($_POST['login'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    $found = find_user_by_store_panel_login($login);
    if (!$found || !store_panel_account($found)) {
        $error = 'نام کاربری یا رمز عبور اشتباه است، یا این حساب فروشگاه فعال ندارد.';
    } elseif (empty($found['password_hash']) || !password_verify($pass, $found['password_hash'])) {
        $error = 'نام کاربری یا رمز عبور اشتباه است، یا این حساب فروشگاه فعال ندارد.';
    } else {
        login_user((int)$found['id']);
        header('Location: ' . APP_URL . $redir);
        exit;
    }
}

render_head('ورود پنل فروشگاه | ' . APP_NAME, 'ورود مالکان فروشگاه به پنل مدیریت فروشگاه', [
    'canonical' => APP_URL . '/auth/store-login',
    'robots'    => 'noindex, follow',
]);
?>

<div style="min-height:calc(100vh - 120px);display:flex;align-items:center;justify-content:center;padding:var(--sp-8) var(--sp-4)">
  <div class="card" style="max-width:440px;width:100%;box-shadow:var(--shadow-lg)">
    <div class="card-body" style="padding:var(--sp-8)">
      <div class="text-center mb-6">
        <div style="width:72px;height:72px;margin:0 auto var(--sp-4);border-radius:20px;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;color:#fff;font-size:2rem">
          <i class="bi bi-shop"></i>
        </div>
        <h1 style="font-size:1.35rem;margin:0 0 var(--sp-2)">ورود پنل فروشگاه</h1>
        <p class="fs-sm" style="color:var(--text-muted);margin:0">با نام کاربری و رمز دریافتی از پشتیبانی وارد شوید</p>
      </div>

      <?php if ($error !== ''): ?>
      <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">
        <?= csrf_field() ?>
        <div class="form-group">
          <label class="form-label">نام کاربری فروشگاه</label>
          <input type="text" class="form-control" name="login" dir="ltr" inputmode="latin"
                 value="<?= h($_POST['login'] ?? '') ?>" required autofocus autocapitalize="off" spellcheck="false">
        </div>
        <div class="form-group">
          <label class="form-label">رمز عبور</label>
          <input type="password" class="form-control" name="password" dir="ltr" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg">
          <i class="bi bi-box-arrow-in-right"></i> ورود به پنل فروشگاه
        </button>
      </form>

      <p class="text-center fs-sm mt-5" style="color:var(--text-muted);line-height:1.8">
        کاربر عادی هستید؟
        <a href="<?= APP_URL ?>/auth/login?redirect=<?= urlencode($redir) ?>">ورود با شماره موبایل</a><br>
        <a href="<?= APP_URL ?>/">بازگشت به صفحه اصلی</a>
      </p>
    </div>
  </div>
</div>

<?php render_footer(); ?>
