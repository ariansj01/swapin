<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user   = require_auth();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean($_POST['action'] ?? 'profile');

    if ($action === 'profile') {
        $name = clean($_POST['name'] ?? '');
        $city = clean($_POST['city'] ?? '');
        $bio  = clean($_POST['bio'] ?? '');

        if (mb_strlen($name) < 2) $errors['name'] = 'نام باید حداقل ۲ کاراکتر باشد';
        if (empty($errors)) {
            DB::update('users', [
                'name' => $name,
                'city' => $city ?: null,
                'bio'  => $bio ?: null,
            ], 'id = ?', [$user['id']]);
            $success = 'پروفایل به‌روزرسانی شد.';
            $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$user['id']]);
        }
    }

    if ($action === 'kyc') {
        $idCard = $user['id_card_image'];
        if (!empty($_FILES['id_card_image']['name'])) {
            $uploaded = upload_image($_FILES['id_card_image'], 'kyc');
            if ($uploaded) $idCard = $uploaded;
        }

        $kycErrors = submit_kyc($user['id'], [
            'national_id'   => $_POST['national_id'] ?? '',
            'bank_account'  => $_POST['bank_account'] ?? '',
            'seller_type'   => $_POST['seller_type'] ?? 'personal',
            'store_name'    => $_POST['store_name'] ?? '',
            'id_card_image' => $idCard,
        ]);
        $errors = array_merge($errors, $kycErrors);
        if (empty($errors)) {
            $success = 'مدارک KYC ارسال شد — در انتظار بررسی.';
            $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$user['id']]);
        }
    }
}

render_head('ویرایش پروفایل و KYC');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/profile.php" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به پروفایل
      </a>
      <h2 class="mt-3">پروفایل و KYC</h2>
      <p style="color:var(--text-muted)">برای فروش و دریافت پرداخت، احراز هویت را تکمیل کنید</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>

    <!-- KYC Status -->
    <div class="card mb-6">
      <div class="card-body d-flex align-center gap-4" style="flex-wrap:wrap">
        <div style="font-size:2rem;color:var(--primary-light)"><i class="bi bi-shield-check"></i></div>
        <div style="flex:1">
          <div class="fw-700">وضعیت KYC</div>
          <div class="fs-sm" style="color:var(--text-muted)">
            <?php
            $kyc = $user['kyc_status'] ?? 'none';
            $kycBadge = match($kyc) {
                'approved' => 'success',
                'pending'  => 'warning',
                'rejected' => 'danger',
                default    => 'info',
            };
            ?>
            <span class="badge badge-<?= $kycBadge ?>"><?= h(kyc_status_label($kyc)) ?></span>
          </div>
        </div>
        <?php if ($kyc === 'approved'): ?>
        <span class="badge badge-success"><i class="bi bi-patch-check-fill"></i> فروشنده تأیید‌شده</span>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-6);align-items:start">

      <!-- Profile -->
      <div class="card">
        <div class="card-header"><h3 style="margin:0;font-size:1rem">پروفایل پایه</h3></div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="profile">
            <div class="form-group">
              <label class="form-label">نام کامل</label>
              <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                     value="<?= h($user['name']) ?>" required>
              <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= h($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="form-group">
              <label class="form-label">شهر</label>
              <input type="text" name="city" class="form-control" value="<?= h($user['city'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">بیو</label>
              <textarea name="bio" class="form-control" rows="3"><?= h($user['bio'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره پروفایل</button>
          </form>
        </div>
      </div>

      <!-- KYC -->
      <div class="card">
        <div class="card-header"><h3 style="margin:0;font-size:1rem">احراز هویت (KYC)</h3></div>
        <div class="card-body">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="kyc">

            <div class="form-group">
              <label class="form-label">کد ملی <span class="required">*</span></label>
              <input type="text" name="national_id" class="form-control <?= isset($errors['national_id']) ? 'is-invalid' : '' ?>"
                     value="<?= h($user['national_id'] ?? '') ?>" maxlength="10" pattern="\d{10}" placeholder="0123456789">
              <?php if (isset($errors['national_id'])): ?><div class="invalid-feedback"><?= h($errors['national_id']) ?></div><?php endif; ?>
            </div>

            <div class="form-group">
              <label class="form-label">حساب بانکی / شبا <span class="required">*</span></label>
              <input type="text" name="bank_account" class="form-control <?= isset($errors['bank_account']) ? 'is-invalid' : '' ?>"
                     value="<?= h($user['bank_account'] ?? '') ?>" placeholder="IR120000000000000000000000">
              <?php if (isset($errors['bank_account'])): ?><div class="invalid-feedback"><?= h($errors['bank_account']) ?></div><?php endif; ?>
            </div>

            <div class="form-group">
              <label class="form-label">نوع فروشنده</label>
              <select name="seller_type" class="form-control" id="seller-type">
                <option value="personal" <?= ($user['seller_type'] ?? 'personal') === 'personal' ? 'selected' : '' ?>>شخصی</option>
                <option value="store" <?= ($user['seller_type'] ?? '') === 'store' ? 'selected' : '' ?>>فروشگاه / کسب‌وکار</option>
              </select>
            </div>

            <div class="form-group" id="store-name-group" style="<?= ($user['seller_type'] ?? '') === 'store' ? '' : 'display:none' ?>">
              <label class="form-label">نام فروشگاه</label>
              <input type="text" name="store_name" class="form-control" value="<?= h($user['store_name'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label class="form-label">تصویر کارت ملی <span class="required">*</span></label>
              <?php if (!empty($user['id_card_image'])): ?>
              <div class="mb-2">
                <img src="<?= UPLOAD_URL . h($user['id_card_image']) ?>" alt="کارت ملی" style="max-height:80px;border-radius:var(--radius-sm);border:1px solid var(--border)">
              </div>
              <?php endif; ?>
              <input type="file" name="id_card_image" class="form-control" accept="image/*" <?= empty($user['id_card_image']) ? 'required' : '' ?>>
              <?php if (isset($errors['id_card_image'])): ?><div class="invalid-feedback"><?= h($errors['id_card_image']) ?></div><?php endif; ?>
            </div>

            <button type="submit" class="btn btn-accent w-100" <?= $kyc === 'pending' ? 'disabled' : '' ?>>
              <i class="bi bi-shield-check"></i>
              <?= $kyc === 'none' ? 'ارسال مدارک KYC' : 'به‌روزرسانی مدارک KYC' ?>
            </button>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
document.getElementById('seller-type').addEventListener('change', function() {
  document.getElementById('store-name-group').style.display = this.value === 'store' ? '' : 'none';
});
</script>

<?php render_footer(); ?>
