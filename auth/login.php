<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (auth_user()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

$error  = '';
$tab    = $_GET['tab'] ?? 'email';
$redir  = safe_redirect_path(clean($_GET['redirect'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $method = clean($_POST['method'] ?? 'email');

    if ($method === 'email') {
        rate_limit_ip_or_fail('login_email', 20, 900);
        $email = clean($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        $user = DB::fetch('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        if ($user && password_verify($pass, $user['password_hash'])) {
            login_user($user['id']);
            $dest = $redir ? APP_URL . $redir : APP_URL . '/dashboard.php';
            header('Location: ' . $dest); exit;
        }
        $error = 'ایمیل یا رمز عبور نادرست است.';

    } elseif ($method === 'otp_request') {
        rate_limit_ip_or_fail('otp_request', 5, 900);
        $phone = clean($_POST['phone'] ?? '');
        $user  = DB::fetch('SELECT id FROM users WHERE phone = ? AND is_active = 1', [$phone]);
        if (!$user) {
            $error = 'حسابی با این شماره تلفن یافت نشد.';
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::query('DELETE FROM otp_codes WHERE phone = ?', [$phone]);
            DB::insert('otp_codes', [
                'phone'      => $phone,
                'code'       => password_hash($code, PASSWORD_BCRYPT),
                'expires_at' => date('Y-m-d H:i:s', time() + OTP_EXPIRE),
            ]);
            if (send_otp_sms($phone, $code)) {
                $_SESSION['otp_phone'] = $phone;
                header('Location: ?tab=otp_verify&phone=' . urlencode($phone)); exit;
            }

            DB::query('DELETE FROM otp_codes WHERE phone = ?', [$phone]);
            swapin_debug_log('otp-send-failed', [
                'phone' => sms_mask_phone($phone),
                'reason' => last_sms_error(),
            ]);
            $error = safe_sms_error(last_sms_error());
        }

    } elseif ($method === 'otp_verify') {
        rate_limit_ip_or_fail('otp_verify', 15, 900);
        $phone = clean($_POST['phone'] ?? '');
        $code  = clean($_POST['code']  ?? '');
        $row   = DB::fetch(
            'SELECT * FROM otp_codes WHERE phone = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1',
            [$phone]
        );
        if ($row && password_verify($code, $row['code'])) {
            DB::query('UPDATE otp_codes SET used = 1 WHERE id = ?', [$row['id']]);
            $user = DB::fetch('SELECT id FROM users WHERE phone = ? AND is_active = 1', [$phone]);
            if ($user) {
                login_user($user['id']);
                header('Location: ' . APP_URL . '/dashboard.php'); exit;
            }
        }
        $error = 'کد نامعتبر یا منقضی شده است. لطفاً دوباره تلاش کنید.';
    }
}

render_head('ورود');
render_navbar(null);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:440px;margin:0 auto">
      <div class="card-body" style="padding:var(--sp-8)">

        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>خوش آمدید</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">ورود به حساب <?= APP_NAME ?></p>
        </div>

        <!-- Tabs -->
        <div class="tabs mb-6">
          <button class="tab-btn <?= ($tab === 'email') ? 'active' : '' ?>" onclick="switchTab('email')">
            <i class="bi bi-envelope"></i> ایمیل
          </button>
          <button class="tab-btn <?= ($tab !== 'email') ? 'active' : '' ?>" onclick="switchTab('otp')">
            <i class="bi bi-phone"></i> کد یکبار مصرف
          </button>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger mb-5">
          <i class="bi bi-exclamation-circle"></i> <?= h($error) ?>
        </div>
        <?php endif; ?>

        <!-- Email / Password Tab -->
        <div class="tab-panel <?= ($tab === 'email') ? 'active' : '' ?>" id="tab-email">
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="method" value="email">
            <div class="form-group">
              <label class="form-label">آدرس ایمیل</label>
              <input type="email" class="form-control" name="email" placeholder="you@example.com"
                     value="<?= h($_POST['email'] ?? '') ?>" autocomplete="email" required>
            </div>
            <div class="form-group">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--sp-2)">
                <label class="form-label" style="margin:0">رمز عبور</label>
                <a href="<?= APP_URL ?>/auth/forgot.php" class="fs-sm">رمز عبور را فراموش کردید؟</a>
              </div>
              <div style="position:relative">
                <input type="password" class="form-control" name="password" id="login-pass"
                       placeholder="رمز عبور شما" autocomplete="current-password" required>
                <button type="button" class="btn btn-ghost btn-icon btn-sm"
                        onclick="togglePass('login-pass','ep-icon')"
                        style="position:absolute;left:6px;top:50%;transform:translateY(-50%);color:var(--text-muted)">
                  <i class="bi bi-eye" id="ep-icon"></i>
                </button>
              </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg">ورود</button>
          </form>
        </div>

        <!-- OTP Tab -->
        <div class="tab-panel <?= ($tab !== 'email') ? 'active' : '' ?>" id="tab-otp">
          <?php if (isset($_GET['phone']) && $tab === 'otp_verify'): ?>
          <!-- OTP Code Entry -->
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="method" value="otp_verify">
            <input type="hidden" name="phone" value="<?= h($_GET['phone'] ?? '') ?>">
            <p class="mb-5" style="color:var(--text-secondary)">
              کد ۶ رقمی به <strong><?= h($_GET['phone'] ?? '') ?></strong> ارسال شد
            </p>
            <div class="form-group">
              <label class="form-label">کد تأیید</label>
              <input type="text" class="form-control" name="code" placeholder="000000"
                     maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                     style="font-size:1.5rem;letter-spacing:.3em;text-align:center" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg">تأیید و ورود</button>
            <p class="text-center fs-sm mt-4" style="color:var(--text-muted)">
              دریافت نکردید؟ <a href="?tab=otp">ارسال مجدد کد</a>
            </p>
          </form>
          <?php else: ?>
          <!-- Phone Number Entry -->
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="method" value="otp_request">
            <div class="form-group">
              <label class="form-label">شماره تلفن</label>
              <input type="tel" class="form-control" name="phone" placeholder="+989123456789"
                     autocomplete="tel" required>
              <div class="form-hint">یک کد یکبار مصرف به این شماره ارسال می‌شود</div>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg">ارسال کد</button>
          </form>
          <?php endif; ?>
        </div>

        <p class="text-center fs-sm mt-6" style="color:var(--text-muted)">
          حساب کاربری ندارید؟ <a href="<?= APP_URL ?>/auth/register.php" style="font-weight:600">ثبت‌نام رایگان</a>
        </p>

      </div>
    </div>
  </div>
</div>

<script>
function switchTab(t) {
  document.getElementById('tab-email').classList.toggle('active', t === 'email');
  document.getElementById('tab-otp').classList.toggle('active', t !== 'email');
  document.querySelectorAll('.tab-btn').forEach((b, i) => b.classList.toggle('active', i === (t === 'email' ? 0 : 1)));
}
function togglePass(id, iconId) {
  const inp  = document.getElementById(id);
  const icon = document.getElementById(iconId);
  inp.type   = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>

<?php render_footer(); ?>
