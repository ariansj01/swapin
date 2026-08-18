<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$isGoogleUser = (
    isset($_GET['google_login'])
    && $_GET['google_login'] === '1'
    && isset($_SESSION['google_user_id_for_profile_completion'])
);

if (!$isGoogleUser) {
    header('Location: ' . APP_URL . '/auth/login');
    exit;
}

$googleUserId = (int) $_SESSION['google_user_id_for_profile_completion'];
$currentUser = DB::fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$googleUserId]);

if (!$currentUser) {
    header('Location: ' . APP_URL . '/auth/login');
    exit;
}

if (!empty($currentUser['phone_verified_at'])) {
    unset($_SESSION['google_user_id_for_profile_completion']);
    login_user($googleUserId);
    if (!user_profile_is_complete($currentUser)) {
        notify_profile_completion($googleUserId);
    }
    header('Location: ' . APP_URL . '/?welcome=1');
    exit;
}

$vals = [
    'phone' => $currentUser['phone'] ?? '',
];
$errors = [];
$showOtpForm = false;

if (!empty($vals['phone'])) {
    $showOtpForm = true;
    $_SESSION['otp_phone_raw'] = $vals['phone'];
    $_SESSION['last_otp_send'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('complete_profile', 5, 3600);

    $action = clean($_POST['action'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $otp = clean($_POST['otp'] ?? '');

    if ($action === 'send_otp') {
        if (!is_valid_phone($phone)) {
            $errors['phone'] = 'لطفاً یک شماره تلفن معتبر وارد کنید.';
        } else {
            $existingUser = DB::fetch('SELECT id FROM users WHERE phone = ? AND id != ?', [$phone, $googleUserId]);
            if ($existingUser) {
                $errors['phone'] = 'این شماره تلفن قبلاً ثبت شده است.';
            }
        }

        if (empty($errors)) {
            $_SESSION['otp_phone_raw'] = $phone;
            $_SESSION['otp_phone_intl'] = $phone;

            $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_code'] = $otpCode;
            $_SESSION['last_otp_send'] = time();

            if (send_otp_sms($phone, $otpCode)) {
                $showOtpForm = true;
                $vals['phone'] = $phone;
            } else {
                $errors['phone'] = safe_sms_error(last_sms_error());
            }
        }
    } elseif ($action === 'verify_otp') {
        if (empty($_SESSION['otp_phone_raw']) || empty($_SESSION['otp_code'])) {
            $errors['otp'] = 'لطفاً ابتدا کد تأیید را درخواست کنید.';
            $showOtpForm = false;
        } elseif (time() - ($_SESSION['last_otp_send'] ?? 0) > OTP_EXPIRE) {
            $errors['otp'] = 'کد تأیید منقضی شده است. لطفاً دوباره درخواست کنید.';
            unset($_SESSION['otp_code'], $_SESSION['last_otp_send']);
            $showOtpForm = false;
        } elseif ($otp !== $_SESSION['otp_code']) {
            $errors['otp'] = 'کد تأیید اشتباه است.';
            $showOtpForm = true;
        } else {
            DB::update('users', [
                'phone'             => $_SESSION['otp_phone_raw'],
                'phone_verified_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$googleUserId]);

            unset(
                $_SESSION['otp_code'],
                $_SESSION['last_otp_send'],
                $_SESSION['otp_phone_raw'],
                $_SESSION['otp_phone_intl'],
                $_SESSION['google_user_id_for_profile_completion']
            );

            login_user($googleUserId);
            notify_profile_completion($googleUserId);
            header('Location: ' . APP_URL . '/?welcome=1');
            exit;
        }
    }
}

render_head('تأیید شماره موبایل | سواَپین', 'تأیید شماره موبایل برای ورود به سواَپین', [
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
          <h2>تأیید شماره موبایل</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">برای ادامه، شماره موبایل خود را تأیید کنید</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i>
          <div>لطفاً خطاهای زیر را برطرف کنید.</div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>
          <?php if (!$showOtpForm): ?>
          <input type="hidden" name="action" value="send_otp">
          <div class="form-group">
            <label class="form-label" for="phone">شماره تلفن <span class="required">*</span></label>
            <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                   id="phone" name="phone" value="<?= h($vals['phone']) ?>"
                   placeholder="مثال: 09123456789" autocomplete="tel" required autofocus>
            <?php if (isset($errors['phone'])): ?>
            <div class="invalid-feedback"><?= h($errors['phone']) ?></div>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">ارسال کد تأیید</button>
          <?php else: ?>
          <input type="hidden" name="action" value="verify_otp">
          <input type="hidden" name="otp" value="">
          <div class="form-group">
            <label class="form-label">کد تأیید <span class="required">*</span></label>
            <div class="otp-group" data-target="otp" data-mode="digits" id="cpOtpGroup">
              <?php for ($i = 0; $i < 6; $i++): ?>
                <input
                  type="text"
                  class="otp-group__digit <?= isset($errors['otp']) ? 'is-invalid' : '' ?>"
                  maxlength="1"
                  inputmode="numeric"
                  pattern="[0-9]"
                  aria-label="رقم <?= ($i + 1) ?>"
                  autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                  <?php if ($i === 0) echo 'autofocus'; ?>
                >
              <?php endfor; ?>
            </div>
            <?php if (isset($errors['otp'])): ?>
            <div class="invalid-feedback text-center mt-2 d-block"><?= h($errors['otp']) ?></div>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">تأیید و ورود</button>
          <p class="text-center mt-3 fs-xs" style="color:var(--text-muted)">
            کد به شماره <strong><?= h($_SESSION['otp_phone_raw'] ?? '') ?></strong> ارسال شد.
          </p>
          <?php endif; ?>
        </form>

      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
