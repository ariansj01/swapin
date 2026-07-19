<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

if (auth_user()) {
    header('Location: ' . APP_URL . '/dashboard'); exit;
}

$isGoogleUser = (isset($_GET['google_login']) && $_GET['google_login'] === '1' && isset($_SESSION['google_user_id_for_phone_verification']));
$googleUserId = 0;
$currentUser  = null;

if (!$isGoogleUser) {
    // This page is specifically for Google users to verify phone
    header('Location: ' . APP_URL . '/auth/login'); exit;
}

$googleUserId = (int) $_SESSION['google_user_id_for_phone_verification'];
$currentUser = DB::fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$googleUserId]);

if (!$currentUser) {
    // User not found, something is wrong, redirect to login
    unset($_SESSION['google_user_id_for_phone_verification']);
    header('Location: ' . APP_URL . '/auth/login'); exit;
}

// If Google user already has a phone, and it's verified, redirect to complete-profile
if (!empty($currentUser['phone']) && !empty($currentUser['phone_verified_at'])) {
    $_SESSION['google_user_id_for_profile_completion'] = $googleUserId; // Pass to complete-profile
    unset($_SESSION['google_user_id_for_phone_verification']);
    header('Location: ' . APP_URL . '/auth/complete-profile?google_login=1'); exit;
}

$phone      = clean($_POST['phone'] ?? '');
$otp        = clean($_POST['otp'] ?? '');
$action     = clean($_POST['action'] ?? '');
$errors     = [];
$showOtpForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    if ($action === 'send_otp') {
        // Validate phone number
        if (!is_valid_phone($phone)) {
            $errors['phone'] = 'لطفاً یک شماره تلفن معتبر وارد کنید.';
        }

        if (empty($errors)) {
            // Check if phone already exists for another user (excluding current Google user)
            $existingUser = DB::fetch('SELECT id FROM users WHERE phone = ? AND id != ?', [$phone, $googleUserId]);
            if ($existingUser) {
                $errors['phone'] = 'این شماره تلفن قبلاً ثبت شده است.';
            }
        }

        if (empty($errors)) {
            // Send OTP
            $_SESSION['otp_phone_raw'] = $phone;
            $_SESSION['otp_phone_intl'] = $phone; // Assuming national format for now
            
            // Generate a random 6-digit OTP
            $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['last_otp_send'] = time();
            
            if (send_otp_sms($phone, $otpCode)) {
                $showOtpForm = true;
            } else {
                $errors['phone'] = safe_sms_error(last_sms_error());
            }
        }
    } elseif ($action === 'verify_otp') {
        if (empty($_SESSION['otp_phone_raw']) || empty($_SESSION['otp_code'])) {
            $errors['otp'] = 'لطفاً ابتدا کد تأیید را درخواست کنید.';
            $showOtpForm = false; // Go back to phone input
        } elseif (time() - ($_SESSION['last_otp_send'] ?? 0) > OTP_EXPIRE) {
            $errors['otp'] = 'کد تأیید منقضی شده است. لطفاً دوباره درخواست کنید.';
            unset($_SESSION['otp_code'], $_SESSION['last_otp_send']);
            $showOtpForm = false; // Go back to phone input
        } elseif ($otp !== $_SESSION['otp_code']) {
            $errors['otp'] = 'کد تأیید اشتباه است.';
            $showOtpForm = true; // Stay on OTP form
        } else {
            // OTP is correct, update user's phone and verified_at
            DB::update('users', [
                'phone'             => $_SESSION['otp_phone_raw'],
                'phone_verified_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$googleUserId]);

            // Clear OTP session data
            unset($_SESSION['otp_code'], $_SESSION['last_otp_send'], $_SESSION['otp_phone_raw'], $_SESSION['otp_phone_intl']);

            // Redirect to complete profile to fill city, etc.
            $_SESSION['google_user_id_for_profile_completion'] = $googleUserId;
            unset($_SESSION['google_user_id_for_phone_verification']);
            header('Location: ' . APP_URL . '/auth/complete-profile?google_login=1');
            exit;
        }
    }
}

render_head('تأیید شماره تلفن | سواَپین', 'تأیید شماره تلفن برای تکمیل پروفایل گوگل');
render_navbar(null);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:480px;margin:0 auto">
      <div class="card-body" style="padding:var(--sp-8)">

        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>تأیید شماره تلفن</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">لطفاً شماره تلفن خود را برای تکمیل پروفایل وارد کنید.</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i>
          <div>لطفاً خطاهای زیر را برطرف کنید.</div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>

          <?php if (!$showOtpForm): // Show phone input form ?>
          <input type="hidden" name="action" value="send_otp">
          <div class="form-group">
            <label class="form-label" for="phone">شماره تلفن <span class="required">*</span></label>
            <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                   id="phone" name="phone" value="<?= h($phone) ?>"
                   placeholder="مثال: 09123456789" autocomplete="tel" required autofocus>
            <?php if (isset($errors['phone'])): ?>
            <div class="invalid-feedback"><?= h($errors['phone']) ?></div>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">ارسال کد تأیید</button>
          <?php else: // Show OTP verification form ?>
          <input type="hidden" name="action" value="verify_otp">
          <div class="form-group">
            <label class="form-label" for="otp">کد تأیید <span class="required">*</span></label>
            <input type="text" class="form-control <?= isset($errors['otp']) ? 'is-invalid' : '' ?>"
                   id="otp" name="otp" value="<?= h($otp) ?>"
                   placeholder="کد ۶ رقمی" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>
            <?php if (isset($errors['otp'])): ?>
            <div class="invalid-feedback"><?= h($errors['otp']) ?></div>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">تأیید شماره تلفن</button>
          <p class="text-center mt-3 fs-xs" style="color:var(--text-muted)">
            کد به شماره <strong><?= h($_SESSION['otp_phone_raw'] ?? '') ?></strong> ارسال شد. اگر کدی دریافت نکردید، <a href="#" onclick="event.preventDefault(); window.location.reload();">دوباره ارسال کنید</a>.
          </p>
          <?php endif; ?>
        </form>

      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>