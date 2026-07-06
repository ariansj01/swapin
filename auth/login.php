<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (auth_user()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

$error  = '';
$redir  = safe_redirect_path(clean($_GET['redirect'] ?? ''));
$step   = $_GET['step'] ?? 'phone';
$phone  = $_GET['phone'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($action === 'send_otp') {
        rate_limit_ip_or_fail('otp_request', 5, 900);
        $phoneRaw = clean($_POST['phone'] ?? '');
        
        if (!$phoneRaw || !preg_match('/^09[0-9]{9}$/', $phoneRaw)) {
            $error = 'لطفاً یک شماره تلفن معتبر وارد کنید (مثل 09123456789)';
        } else {
            // Convert to international format for DB
            $phoneIntl = '+' . preg_replace('/[^0-9]/', '', '98' . substr($phoneRaw, 1));
            
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::query('DELETE FROM otp_codes WHERE phone = ?', [$phoneIntl]);
            DB::insert('otp_codes', [
                'phone'      => $phoneIntl,
                'code'       => password_hash($code, PASSWORD_BCRYPT),
                'expires_at' => date('Y-m-d H:i:s', time() + OTP_EXPIRE),
            ]);
            
            if (send_otp_sms($phoneIntl, $code)) {
                $_SESSION['otp_phone_raw'] = $phoneRaw;
                $_SESSION['otp_phone_intl'] = $phoneIntl;
                header('Location: ?step=otp&phone=' . urlencode($phoneRaw) . ($redir ? '&redirect=' . urlencode($redir) : '')); exit;
            } else {
                DB::query('DELETE FROM otp_codes WHERE phone = ?', [$phoneIntl]);
                swapin_debug_log('otp-send-failed', [
                    'phone' => sms_mask_phone($phoneIntl),
                    'reason' => last_sms_error(),
                ]);
                $error = safe_sms_error(last_sms_error());
            }
        }
    } elseif ($action === 'verify_otp') {
        rate_limit_ip_or_fail('otp_verify', 15, 900);
        $phoneIntl = $_SESSION['otp_phone_intl'] ?? '';
        $code  = clean($_POST['code']  ?? '');
        
        if (!$phoneIntl) {
            header('Location: ?step=phone'); exit;
        }
        
        $row = DB::fetch(
            'SELECT * FROM otp_codes WHERE phone = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1',
            [$phoneIntl]
        );
        
        if ($row && password_verify($code, $row['code'])) {
            DB::query('UPDATE otp_codes SET used = 1 WHERE id = ?', [$row['id']]);
            
            $user = DB::fetch('SELECT id, name FROM users WHERE phone = ? AND is_active = 1', [$phoneIntl]);
            if ($user) {
                login_user($user['id']);
                unset($_SESSION['otp_phone_raw'], $_SESSION['otp_phone_intl']);
                $dest = $redir ? APP_URL . $redir : APP_URL . '/dashboard.php';
                header('Location: ' . $dest); exit;
            } else {
                // New user - redirect to complete profile
                $_SESSION['new_user_phone'] = $phoneIntl;
                header('Location: ' . APP_URL . '/auth/complete-profile.php' . ($redir ? '?redirect=' . urlencode($redir) : '')); exit;
            }
        } else {
            $error = 'کد نامعتبر یا منقضی شده است. لطفاً دوباره تلاش کنید.';
        }
    }
}

render_head('ورود / ثبت‌نام');
render_navbar(null);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:440px;margin:0 auto">
      <div class="card-body" style="padding:var(--sp-8)">

        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>ورود / ثبت‌نام</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">شماره تلفن خود را وارد کنید</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i> <?= h($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($step === 'phone'): ?>
        <!-- Phone Entry -->
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_otp">
          <div class="form-group">
            <label class="form-label">شماره تلفن</label>
            <input type="tel" class="form-control" name="phone" placeholder="09123456789"
                   autocomplete="tel" maxlength="11" pattern="09[0-9]{9}" required autofocus>
            <div class="form-hint">یک کد یکبار مصرف به این شماره ارسال می‌شود</div>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">ارسال کد</button>
        </form>
        <?php elseif ($step === 'otp'): ?>
        <!-- OTP Entry -->
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="verify_otp">
          <p class="mb-6" style="color:var(--text-secondary)">
            کد ۶ رقمی به <strong><?= h($phone) ?></strong> ارسال شد
          </p>
          <div class="form-group">
            <label class="form-label">کد تأیید</label>
            <input type="text" class="form-control" name="code" placeholder="000000"
                   maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                   style="font-size:1.5rem;letter-spacing:.3em;text-align:center" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">تأیید و ادامه</button>
          <p class="text-center fs-sm mt-6" style="color:var(--text-muted)">
            دریافت نکردید؟ <a href="?step=phone<?= $redir ? '&redirect=' . urlencode($redir) : '' ?>">ارسال مجدد کد</a>
          </p>
        </form>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
