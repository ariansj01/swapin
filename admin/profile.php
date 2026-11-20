<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

// هر مدیری به حساب خودش دسترسی دارد — بدون نیاز به مجوز بخش
$admin = require_admin();
[$flash, $flashType] = admin_flash();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $name  = clean($_POST['name'] ?? '');
        $email = strtolower(clean($_POST['email'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', normalize_digits(clean($_POST['phone'] ?? '')));

        if (mb_strlen($name) < 2) {
            $errors['name'] = 'نام باید حداقل ۲ کاراکتر باشد.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'ایمیل وارد شده معتبر نیست.';
        } elseif (DB::fetch('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $admin['id']])) {
            $errors['email'] = 'این ایمیل قبلاً توسط کاربر دیگری ثبت شده است.';
        }

        if ($phone !== '') {
            if (!is_valid_phone($phone)) {
                $errors['phone'] = 'شماره تلفن باید با ۰۹ شروع شده و ۱۱ رقم باشد.';
            } elseif (DB::fetch('SELECT id FROM users WHERE phone = ? AND id != ?', [$phone, $admin['id']])) {
                $errors['phone'] = 'این شماره تلفن قبلاً ثبت شده است.';
            }
        }

        if (!$errors) {
            DB::update('users', [
                'name'  => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
            ], 'id = ?', [$admin['id']]);
            admin_set_flash('اطلاعات حساب شما به‌روزرسانی شد.');
            header('Location: ' . APP_URL . '/admin/profile.php');
            exit;
        }
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, (string)$admin['password_hash'])) {
            $errors['current_password'] = 'رمز عبور فعلی درست نیست.';
        }
        if (mb_strlen($new) < 8) {
            $errors['new_password'] = 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.';
        } elseif ($new !== $confirm) {
            $errors['confirm_password'] = 'تکرار رمز عبور جدید مطابقت ندارد.';
        }

        if (!$errors) {
            DB::update('users', [
                'password_hash' => password_hash($new, PASSWORD_BCRYPT),
            ], 'id = ?', [$admin['id']]);
            admin_set_flash('رمز عبور شما تغییر کرد.');
            header('Location: ' . APP_URL . '/admin/profile.php');
            exit;
        }
    }
}

$vals = [
    'name'  => $_POST['name']  ?? $admin['name'],
    'email' => $_POST['email'] ?? $admin['email'],
    'phone' => $_POST['phone'] ?? ($admin['phone'] ?? ''),
];

ob_start();
?>
<div class="admin-header mb-5">
  <div>
    <h1 class="admin-title">پروفایل من</h1>
    <p class="text-muted">ویرایش اطلاعات حساب کاربری مدیر</p>
  </div>
</div>

<?= admin_alert_html($flash, $flashType) ?>

<?php if ($errors): ?>
<div class="alert alert-danger mb-5">
  <i class="bi bi-exclamation-circle"></i>
  لطفاً خطاهای مشخص‌شده را برطرف کنید.
</div>
<?php endif; ?>

<div class="admin-detail-grid">

  <!-- اطلاعات حساب -->
  <div class="card">
    <div class="card-body">
      <h3 style="margin:0 0 var(--sp-5);font-size:1rem"><i class="bi bi-person-gear"></i> اطلاعات حساب</h3>
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">

        <div class="form-group">
          <label class="form-label">نام و نام خانوادگی</label>
          <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                 value="<?= h($vals['name']) ?>" required>
          <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= h($errors['name']) ?></div><?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">ایمیل</label>
          <input type="email" name="email" dir="ltr" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                 value="<?= h($vals['email']) ?>" required>
          <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= h($errors['email']) ?></div><?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">شماره تلفن</label>
          <input type="tel" name="phone" dir="ltr" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                 value="<?= h($vals['phone']) ?>" placeholder="09123456789">
          <?php if (isset($errors['phone'])): ?><div class="invalid-feedback"><?= h($errors['phone']) ?></div><?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره تغییرات</button>
      </form>
    </div>
  </div>

  <!-- تغییر رمز عبور -->
  <div class="card">
    <div class="card-body">
      <h3 style="margin:0 0 var(--sp-5);font-size:1rem"><i class="bi bi-shield-lock"></i> تغییر رمز عبور</h3>
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">

        <div class="form-group">
          <label class="form-label">رمز عبور فعلی</label>
          <input type="password" name="current_password" class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" autocomplete="current-password" required>
          <?php if (isset($errors['current_password'])): ?><div class="invalid-feedback"><?= h($errors['current_password']) ?></div><?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">رمز عبور جدید</label>
          <input type="password" name="new_password" class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>" autocomplete="new-password" required>
          <?php if (isset($errors['new_password'])): ?><div class="invalid-feedback"><?= h($errors['new_password']) ?></div><?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">تکرار رمز عبور جدید</label>
          <input type="password" name="confirm_password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" autocomplete="new-password" required>
          <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?= h($errors['confirm_password']) ?></div><?php endif; ?>
        </div>

        <button type="submit" class="btn btn-outline"><i class="bi bi-key"></i> تغییر رمز عبور</button>
      </form>
    </div>
  </div>

</div>

<?php
$content = ob_get_clean();
render_admin_head('پروفایل من');
render_admin_shell($admin, 'profile', $content);
render_admin_footer();
?>
