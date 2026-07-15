<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (auth_user()) {
    header('Location: ' . APP_URL . '/'); exit;
}

$error         = '';
$redir         = safe_redirect_path(clean($_GET['redirect'] ?? ''));
$step          = $_GET['step'] ?? 'phone';
$phone         = $_GET['phone'] ?? '';
$cooldownSecs  = 0;

// Rate limits: 3 requests then 10min ban (600s), and 90s cooldown between requests
define('MAX_OTP_REQUESTS', 3);
define('OTP_BAN_SECONDS', 600);
define('OTP_COOLDOWN_SECONDS', 90);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($action === 'send_otp') {
        // First check overall limit (3 requests / 10 min)
        // $overallStatus = rate_limit_ip_status('otp_request', MAX_OTP_REQUESTS, OTP_BAN_SECONDS);
        // if (!$overallStatus['allowed']) {
        //     $error = 'تعداد درخواست‌های شما بیش از حد زیاد است! لطفاً ' . max(1, ceil($overallStatus['retry_after'] / 60)) . ' دقیقه دیگر دوباره تلاش کنید.';
        // } else {
            // Then check cooldown
            // if (isset($_SESSION['last_otp_send']) && (time() - (int)$_SESSION['last_otp_send'] < OTP_COOLDOWN_SECONDS)) {
            //     $cooldownSecs = OTP_COOLDOWN_SECONDS - (time() - (int)$_SESSION['last_otp_send']);
            //     $error = 'لطفاً ' . $cooldownSecs . ' ثانیه دیگر دوباره تلاش کنید!';
            // } else {
                $phoneRaw = preg_replace('/\D+/', '', normalize_digits(clean($_POST['phone'] ?? '')));
                
                if (!$phoneRaw || !preg_match('/^09[0-9]{9}$/', $phoneRaw)) {
                    $error = 'لطفاً یک شماره تلفن معتبر وارد کنید (مثل 09123456789)';
                } else {
                    // Convert to international format for DB
                    $phoneIntl = '+' . preg_replace('/[^0-9]/', '', '98' . substr($phoneRaw, 1));
                    
                    // Increment overall rate limit
                    // rate_limit_ip('otp_request', MAX_OTP_REQUESTS, OTP_BAN_SECONDS);
                    
                    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    DB::query('DELETE FROM otp_codes WHERE phone = ?', [$phoneIntl]);
                    
                    // Use DB DATE_ADD() to avoid time zone mismatches!
                    DB::query(
                        "INSERT INTO otp_codes (phone, code, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())",
                        [$phoneIntl, password_hash($code, PASSWORD_BCRYPT), OTP_EXPIRE]
                    );
                    
                    swapin_debug_log('otp-code-generated', [
                        'phone' => sms_mask_phone($phoneIntl),
                        'code' => $code,
                    ]);
                    
                    $smsSent = send_otp_sms($phoneIntl, $code);
                    $_SESSION['otp_phone_raw'] = $phoneRaw;
                    $_SESSION['otp_phone_intl'] = $phoneIntl;
                    $_SESSION['last_otp_send'] = time();
                    
                    if (!$smsSent) {
                        swapin_debug_log('otp-send-failed', [
                            'phone' => sms_mask_phone($phoneIntl),
                            'reason' => last_sms_error(),
                        ]);
                        
                        // In dev mode, show OTP code on page directly
                        if (!app_is_production()) {
                            $error = "ارسال پیامک با خطا مواجه شد. کد تأیید شما: <strong>{$code}</strong> (این پیام فقط در حالت توسعه نمایش داده می‌شود)";
                        } else {
                            $error = safe_sms_error(last_sms_error());
                        }
                    }
                    
                    // Always go to OTP step, even if SMS failed (dev fallback)
                    header('Location: ?step=otp&phone=' . urlencode($phoneRaw) . ($redir ? '&redirect=' . urlencode($redir) : '')); exit;
                }
            // }
        // }
    } elseif ($action === 'verify_otp') {
        rate_limit_ip_or_fail('otp_verify', 15, 900);
        $phoneIntl = $_SESSION['otp_phone_intl'] ?? '';
        $code  = preg_replace('/\D+/', '', normalize_digits(clean($_POST['code']  ?? '')));
        
        if (!$phoneIntl) {
            header('Location: ?step=phone'); exit;
        }
        
        // Deep debug log - list ALL OTP codes for this phone to check DB state
        $allRows = DB::fetchAll('SELECT * FROM otp_codes WHERE phone = ? ORDER BY created_at DESC', [$phoneIntl]);
        swapin_debug_log('otp-verify-all-rows', [
            'phone_from_session' => $phoneIntl,
            'masked_phone' => sms_mask_phone($phoneIntl),
            'all_rows_count' => count($allRows),
            'all_rows' => array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'phone_db' => $r['phone'],
                    'used' => $r['used'],
                    'expires_at' => $r['expires_at'],
                    'expired' => strtotime($r['expires_at']) <= time(),
                    'created_at' => $r['created_at'],
                ];
            }, $allRows)
        ]);
        
        $row = DB::fetch(
            'SELECT * FROM otp_codes WHERE phone = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1',
            [$phoneIntl]
        );
        
        if ($row && password_verify($code, $row['code'])) {
            swapin_debug_log('otp-verify-success', [
                'phone' => sms_mask_phone($phoneIntl),
            ]);
            DB::query('UPDATE otp_codes SET used = 1 WHERE id = ?', [$row['id']]);
            
            $user = DB::fetch('SELECT id, name FROM users WHERE phone = ? AND is_active = 1', [$phoneIntl]);
            if ($user) {
                login_user($user['id']);
                unset($_SESSION['otp_phone_raw'], $_SESSION['otp_phone_intl'], $_SESSION['last_otp_send']);
                $dest = $redir ? APP_URL . $redir : APP_URL . '/';
                header('Location: ' . $dest); exit;
            } else {
                // New user - redirect to complete profile
                $_SESSION['new_user_phone'] = $phoneIntl;
                unset($_SESSION['last_otp_send']);
                header('Location: ' . APP_URL . '/auth/complete-profile' . ($redir ? '?redirect=' . urlencode($redir) : '')); exit;
            }
        } else {
            swapin_debug_log('otp-verify-failed', [
                'phone' => sms_mask_phone($phoneIntl),
                'has_active_row' => (bool) $row,
                'code_length' => strlen($code),
                'code_entered' => $code,
            ]);
            $error = 'کد نامعتبر یا منقضی شده است. لطفاً دوباره تلاش کنید.';
        }
    }
}

render_head('ورود / ثبت‌نام | سواَپین', 'ورود یا ثبت‌نام در سواَپین با شماره تلفن', [
    'canonical' => APP_URL . '/auth/login',
]);
render_navbar(null);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:440px;margin:0 auto;min-height:440px;">
      <div class="card-body" style="padding:var(--sp-8)">

        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>ورود / ثبت‌نام</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">
            <?php if ($step === 'phone'): ?>
              شماره تلفن خود را وارد کنید
            <?php else: ?>
              کد تأیید را وارد کنید
            <?php endif; ?>
          </p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i> 
          <?php if (strpos($error, 'کد تأیید شما:') !== false): ?>
            <!-- Allow HTML for dev OTP display message -->
            <?= $error ?>
          <?php else: ?>
            <?= h($error) ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($step === 'phone'): ?>
        <!-- Phone Entry -->
        <form method="POST" id="phoneForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_otp">
          <div class="form-group">
            <label class="form-label">شماره تلفن</label>
            <input type="tel" class="form-control login-input-tall" name="phone" placeholder="09123456789"
                   autocomplete="tel" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" required autofocus
                   value="<?= isset($_POST['phone']) ? h($_POST['phone']) : '' ?>">
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg" id="sendBtn">ارسال کد</button>
        </form>
        <?php elseif ($step === 'otp'): ?>
        <!-- OTP Entry -->
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="verify_otp">
          <div class="form-group">
            <label class="form-label">کد تأیید</label>
            <input type="text" class="form-control login-input-tall login-code-input" name="code" placeholder="000000"
                   inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code"
                   required autofocus>
            <p class="form-hint">
              کد ۶ رقمی به <strong><?= h($phone) ?></strong> ارسال شد
            </p>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">تأیید و ادامه</button>
          <p class="text-center fs-sm mt-6" style="color:var(--text-muted)" id="resendArea">
            دریافت نکردید؟
            <a href="?step=phone<?= $redir ? '&redirect=' . urlencode($redir) : '' ?>" id="resendLink">ارسال مجدد کد</a>
          </p>
        </form>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
<?php if ($step === 'otp' && isset($_SESSION['last_otp_send'])): ?>
    let cooldownSeconds = <?= OTP_COOLDOWN_SECONDS - (time() - (int)$_SESSION['last_otp_send']) ?>;
    const resendLink = document.getElementById('resendLink');
    const resendArea = document.getElementById('resendArea');

    function updateCooldown() {
        if (cooldownSeconds <= 0) {
            resendLink.textContent = 'ارسال مجدد کد';
            resendLink.style.pointerEvents = 'auto';
            resendLink.style.opacity = '1';
            resendLink.style.color = 'var(--primary)';
            return;
        }

        resendLink.textContent = `ارسال مجدد (${cooldownSeconds} ثانیه)`;
        resendLink.style.pointerEvents = 'none';
        resendLink.style.opacity = '0.6';
        cooldownSeconds--;
        setTimeout(updateCooldown, 1000);
    }

    updateCooldown();
<?php endif; ?>

    function normalizeDigits(value) {
        return value.replace(/[۰-۹٠-٩]/g, function (digit) {
            const map = {
                '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
                '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
                '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
                '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
            };
            return map[digit] || digit;
        });
    }

    const phoneInput = document.querySelector('input[name="phone"]');
    const codeInput = document.querySelector('input[name="code"]');

    if (phoneInput) {
        phoneInput.addEventListener('input', function () {
            this.value = normalizeDigits(this.value).replace(/\D+/g, '').slice(0, 11);
        });
    }

    if (codeInput) {
        codeInput.addEventListener('input', function () {
            this.value = normalizeDigits(this.value).replace(/\D+/g, '').slice(0, 6);
        });
    }
</script>

<?php render_footer(); ?>
