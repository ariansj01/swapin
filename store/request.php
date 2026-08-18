<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$error = '';
$success = false;
$blockReason = user_store_request_block_reason($user);

$fields = store_fields_from_input($_POST ?? []);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($fields['store_phone'] === '' && !empty($user['phone'])) {
        $fields['store_phone'] = preg_replace('/^\+98/', '0', (string)$user['phone']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('store_request_form', 5, 3600);

    if ($blockReason !== null) {
        $error = $blockReason;
    } else {
        $result = create_store_request((int)$user['id'], $_POST, $_FILES['store_banner'] ?? null);
        if (!$result['ok']) {
            $error = $result['error'] ?? 'ثبت درخواست ناموفق بود.';
            $fields = store_fields_from_input($_POST);
        } else {
            $success = true;
        }
    }
}

$myRequests = [];
if (db_has_table('store_requests')) {
    $myRequests = DB::fetchAll(
        'SELECT id, status, store_name, admin_note, created_at, reviewed_at
         FROM store_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
        [(int)$user['id']]
    );
}
$statusLabels = store_request_status_labels();

render_head('ثبت درخواست فروشگاه | ' . APP_NAME, 'درخواست فعال‌سازی حساب فروشگاه در سواَپین — اطلاعات فروشگاه خود را ارسال کنید.', [
    'canonical' => APP_URL . '/store/request',
]);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container" style="max-width:760px">

    <div style="text-align:center;padding:var(--sp-6) 0 var(--sp-5)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg,var(--primary),var(--accent));margin-bottom:var(--sp-3)">
        <i class="bi bi-shop" style="font-size:1.75rem;color:#fff"></i>
      </div>
      <h1 style="font-size:1.75rem;margin:0 0 var(--sp-2)">ثبت درخواست فروشگاه</h1>
      <p style="color:var(--text-muted);line-height:1.8;margin:0">
        اطلاعات فروشگاه خود را وارد کنید. پس از بررسی توسط تیم سواَپین، حساب فروشگاهی شما فعال می‌شود.
      </p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5">
      <i class="bi bi-check-circle-fill"></i>
      درخواست شما با موفقیت ثبت شد. پس از بررسی، از طریق پیامک یا تماس با شما هماهنگ می‌شود.
    </div>
    <?php endif; ?>

    <?php if ($blockReason !== null && !$success): ?>
    <div class="alert alert-info mb-5">
      <i class="bi bi-info-circle"></i> <?= h($blockReason) ?>
      <?php if (user_has_active_store($user) && !empty($user['store_slug'])): ?>
      <div style="margin-top:10px">
        <a href="<?= APP_URL ?>/shop/<?= h($user['store_slug']) ?>" class="btn btn-outline btn-sm">مشاهده صفحه فروشگاه</a>
        <a href="<?= APP_URL ?>/store" class="btn btn-primary btn-sm">پنل فروشگاه</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($blockReason === null && !$success): ?>
    <form method="POST" enctype="multipart/form-data" class="card">
      <div class="card-body" style="padding:var(--sp-6)">
        <?= csrf_field() ?>

        <div class="form-group">
          <label class="form-label">نوع کسب‌وکار <span style="color:var(--danger)">*</span></label>
          <?= render_provider_type_select('provider_type', $fields['provider_type'] ?? 'normal_store') ?>
        </div>

        <div class="form-group">
          <label class="form-label">نام فروشگاه <span style="color:var(--danger)">*</span></label>
          <input type="text" class="form-control" name="store_name" required maxlength="120"
                 value="<?= h($fields['store_name']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">توضیحات فروشگاه</label>
          <textarea class="form-control" name="store_description" rows="4"><?= h($fields['store_description']) ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">بنر فروشگاه</label>
          <input type="file" class="form-control" name="store_banner" accept="image/*">
          <small class="fs-sm text-muted">JPG, PNG, WebP — حداکثر ۵ مگابایت</small>
        </div>

        <div class="form-group">
          <label class="form-label">آدرس فروشگاه</label>
          <input type="text" class="form-control" name="store_address" value="<?= h($fields['store_address']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">تلفن فروشگاه <span style="color:var(--danger)">*</span></label>
          <input type="tel" class="form-control" name="store_phone" required dir="ltr"
                 placeholder="09123456789" value="<?= h($fields['store_phone']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">وب‌سایت</label>
          <input type="url" class="form-control" name="store_website" dir="ltr" placeholder="https://example.com"
                 value="<?= h($fields['store_website']) ?>">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
          <div class="form-group">
            <label class="form-label">اینستاگرام</label>
            <input type="text" class="form-control" name="store_instagram" placeholder="@username"
                   value="<?= h($fields['store_instagram']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">تلگرام</label>
            <input type="text" class="form-control" name="store_telegram" placeholder="@username"
                   value="<?= h($fields['store_telegram']) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">ساعات کاری</label>
          <input type="text" class="form-control" name="store_opening_hours" placeholder="شنبه تا پنجشنبه ۹ تا ۱۸"
                 value="<?= h($fields['store_opening_hours']) ?>">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
          <div class="form-group">
            <label class="form-label">عرض جغرافیایی</label>
            <input type="text" class="form-control" name="store_lat" dir="ltr" placeholder="35.6892"
                   value="<?= h($fields['store_lat']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">طول جغرافیایی</label>
            <input type="text" class="form-control" name="store_lng" dir="ltr" placeholder="51.3890"
                   value="<?= h($fields['store_lng']) ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">
          <i class="bi bi-send"></i> ارسال درخواست
        </button>
      </div>
    </form>
    <?php endif; ?>

    <?php if (!empty($myRequests)): ?>
    <div class="card mt-6">
      <div class="card-header"><h2 style="margin:0;font-size:1rem">درخواست‌های قبلی شما</h2></div>
      <table class="table" style="margin:0">
        <thead>
          <tr>
            <th>فروشگاه</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myRequests as $req): ?>
          <tr>
            <td><?= h($req['store_name']) ?></td>
            <td><?= h($statusLabels[$req['status']] ?? $req['status']) ?></td>
            <td class="fs-sm text-muted"><?= h(timeago($req['created_at'])) ?></td>
          </tr>
          <?php if (($req['status'] ?? '') === 'rejected' && !empty($req['admin_note'])): ?>
          <tr>
            <td colspan="3" class="fs-sm text-muted">دلیل رد: <?= h($req['admin_note']) ?></td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
</main>

<?php render_footer(); ?>
