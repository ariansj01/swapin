<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$userId = (int)$user['id'];
$error = '';
$successStep = 0; // 1 = ثبت مرحله اول، 2 = ثبت مرحله دوم
$blockReason = user_store_request_block_reason($user);

$latestRequest = store_latest_request($userId);
$needsDocs     = store_request_needs_documents($latestRequest);
$docsAwaiting  = store_request_docs_awaiting_review($latestRequest);
$docsVerified  = $latestRequest && ($latestRequest['docs_status'] ?? '') === 'verified';

$fields = store_fields_from_input($_POST ?? []);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($fields['store_phone'] === '' && !empty($user['phone'])) {
        $fields['store_phone'] = preg_replace('/^\+98/', '0', (string)$user['phone']);
    }
}

$docsFields = [
    'seller_national_id'  => (string)($latestRequest['seller_national_id'] ?? ''),
    'seller_phone'        => (string)($latestRequest['seller_phone'] ?? ($user['phone'] ? preg_replace('/^\+98/', '0', (string)$user['phone']) : '')),
    'seller_landline'     => (string)($latestRequest['seller_landline'] ?? ''),
    'seller_address'      => (string)($latestRequest['seller_address'] ?? ''),
    'seller_postal_code'  => (string)($latestRequest['seller_postal_code'] ?? ''),
    'seller_bank_account' => (string)($latestRequest['seller_bank_account'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $formStep = $_POST['form_step'] ?? 'step1';

    if ($formStep === 'step2') {
        rate_limit_ip_or_fail('store_request_docs', 10, 3600);
        $result = save_store_request_documents($userId, $_POST, $_FILES);
        if (!$result['ok']) {
            $error = $result['error'] ?? 'ثبت اطلاعات فروشنده ناموفق بود.';
        } else {
            $successStep = 2;
            $latestRequest = store_latest_request($userId);
            $needsDocs    = store_request_needs_documents($latestRequest);
            $docsAwaiting = store_request_docs_awaiting_review($latestRequest);
            $docsVerified = $latestRequest && ($latestRequest['docs_status'] ?? '') === 'verified';
        }
        foreach ($docsFields as $k => $_) {
            if (isset($_POST[$k])) {
                $docsFields[$k] = trim((string)$_POST[$k]);
            }
        }
    } else {
        rate_limit_ip_or_fail('store_request_form', 5, 3600);
        if ($blockReason !== null) {
            $error = $blockReason;
        } else {
            $result = create_store_request($userId, $_POST, $_FILES['store_banner'] ?? null);
            if (!$result['ok']) {
                $error = $result['error'] ?? 'ثبت درخواست ناموفق بود.';
                $fields = store_fields_from_input($_POST);
            } else {
                $successStep = 1;
                $latestRequest = store_latest_request($userId);
            }
        }
    }
}

$myRequests = [];
if (db_has_table('store_requests')) {
    $myRequests = DB::fetchAll(
        'SELECT id, status, docs_status, store_name, build_type, admin_note, docs_note, created_at, reviewed_at
         FROM store_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
        [$userId]
    );
}
$statusLabels = store_request_status_labels();
$docsLabels   = store_request_docs_status_labels();
$buildLabels  = store_build_type_labels();

// وضعیت گام‌ها برای نمایش استپر
$step1Done   = $latestRequest !== null && in_array($latestRequest['status'] ?? '', ['pending', 'approved'], true);
$step2Done   = $docsVerified;
$step2Active = $needsDocs || $docsAwaiting;

$stepCircle = static function (bool $done, bool $active, string $num): string {
    $bg = 'var(--bg-muted,#eef1f6)';
    $fg = 'var(--text-muted)';
    if ($done) { $bg = 'var(--success,#16a34a)'; $fg = '#fff'; }
    elseif ($active) { $bg = 'var(--primary)'; $fg = '#fff'; }
    return '<span style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;background:' . $bg . ';color:' . $fg . '">'
        . ($done ? '<i class="bi bi-check-lg"></i>' : $num) . '</span>';
};

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
        در دو مرحله فروشگاه خود را ثبت کنید: ابتدا اطلاعات فروشگاه، پس از تأیید تیم سواَپین، اطلاعات فروشنده و مدارک.
      </p>
    </div>

    <!-- استپر دو مرحله‌ای -->
    <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:var(--sp-5);flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px">
        <?= $stepCircle($step1Done, !$step1Done, '۱') ?>
        <span class="fs-sm" style="font-weight:<?= !$step1Done ? '800' : '600' ?>">اطلاعات فروشگاه</span>
      </div>
      <div style="width:56px;height:2px;background:var(--border,#e5e7eb)"></div>
      <div style="display:flex;align-items:center;gap:8px;opacity:<?= ($needsDocs || $step2Active || $step2Done) ? '1' : '0.5' ?>">
        <?= $stepCircle($step2Done, $needsDocs, '۲') ?>
        <span class="fs-sm" style="font-weight:<?= $needsDocs ? '800' : '600' ?>">اطلاعات فروشنده</span>
      </div>
    </div>

    <?php if ($successStep === 1): ?>
    <div class="alert alert-success mb-5">
      <i class="bi bi-check-circle-fill"></i>
      مرحله اول ثبت شد. درخواست شما در انتظار بررسی است؛ پس از تأیید، فرم مرحله دوم (اطلاعات فروشنده) در همین صفحه فعال می‌شود.
    </div>
    <?php elseif ($successStep === 2): ?>
    <div class="alert alert-success mb-5">
      <i class="bi bi-check-circle-fill"></i>
      اطلاعات فروشنده ثبت شد و در انتظار بررسی است. نتیجه از طریق پیامک به شما اطلاع داده می‌شود.
    </div>
    <?php endif; ?>

    <?php if ($blockReason !== null && $successStep === 0 && !$needsDocs && !$docsVerified): ?>
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

    <?php if ($docsAwaiting && $successStep === 0): ?>
    <div class="alert alert-info mb-5">
      <i class="bi bi-hourglass-split"></i> مدارک فروشندگی شما ارسال شده و در انتظار بررسی تیم سواَپین است.
      <?php if (!empty($latestRequest['docs_note'])): ?>
      <div class="fs-sm mt-2">یادداشت: <?= h($latestRequest['docs_note']) ?></div>
      <?php endif; ?>
    </div>
    <?php elseif ($docsVerified && $successStep === 0): ?>
    <div class="alert alert-success mb-5">
      <i class="bi bi-patch-check-fill"></i> اطلاعات فروشندگی شما تأیید شده است. فروشگاه شما فعال است.
      <?php if (!empty($user['store_slug'])): ?>
      <div style="margin-top:10px">
        <a href="<?= APP_URL ?>/shop/<?= h($user['store_slug']) ?>" class="btn btn-outline btn-sm">مشاهده صفحه فروشگاه</a>
        <a href="<?= APP_URL ?>/store" class="btn btn-primary btn-sm">پنل فروشگاه</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ===== مرحله ۱: اطلاعات فروشگاه ===== -->
    <?php if ($blockReason === null && $successStep !== 1 && !$needsDocs): ?>
    <form method="POST" enctype="multipart/form-data" class="card">
      <div class="card-body" style="padding:var(--sp-6)">
        <?= csrf_field() ?>
        <input type="hidden" name="form_step" value="step1">

        <div class="form-group">
          <label class="form-label">نوع کسب‌وکار <span style="color:var(--danger)">*</span></label>
          <?= render_provider_type_select('provider_type', $fields['provider_type'] ?? 'normal_store') ?>
        </div>

        <div class="form-group">
          <label class="form-label">نوع فروشگاه <span style="color:var(--danger)">*</span></label>
          <?= render_store_build_type_select('build_type', $fields['build_type'] ?? 'coded') ?>
          <small class="fs-sm text-muted">فروشگاه شما به‌صورت کدنویسی اختصاصی ساخته می‌شود یا بر پایه وردپرس؟</small>
        </div>

        <div class="form-group">
          <label class="form-label">حالت فروشگاه <span style="color:var(--danger)">*</span></label>
          <?= render_store_type_select('store_type', $fields['store_type'] ?? 'both') ?>
          <small class="fs-sm text-muted">حضوری: فروشگاه فیزیکی دارد / آنلاین: فقط اینترنتی / هر دو: هر دو حالت فعال است</small>
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
          <i class="bi bi-send"></i> ارسال درخواست (مرحله ۱)
        </button>
      </div>
    </form>
    <?php endif; ?>

    <!-- ===== مرحله ۲: اطلاعات فروشنده ===== -->
    <?php if ($needsDocs): ?>
    <form method="POST" enctype="multipart/form-data" class="card">
      <div class="card-body" style="padding:var(--sp-6)">
        <?= csrf_field() ?>
        <input type="hidden" name="form_step" value="step2">

        <div class="alert alert-info mb-4">
          <i class="bi bi-patch-check"></i>
          درخواست فروشگاه شما تأیید شد. برای فعال‌سازی نهایی، اطلاعات و مدارک فروشنده را تکمیل کنید.
        </div>
        <?php if (($latestRequest['docs_status'] ?? '') === 'rejected'): ?>
        <div class="alert alert-warning mb-4">
          <i class="bi bi-exclamation-triangle"></i>
          مدارک قبلی رد شد<?= !empty($latestRequest['docs_note']) ? ': ' . h($latestRequest['docs_note']) : '' ?>. لطفاً اصلاح و دوباره ارسال کنید.
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label">کد ملی <span style="color:var(--danger)">*</span></label>
          <input type="text" class="form-control" name="seller_national_id" required dir="ltr"
                 inputmode="numeric" maxlength="10" pattern="\d{10}" placeholder="0123456789"
                 value="<?= h($docsFields['seller_national_id']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">تصویر کارت ملی <span style="color:var(--danger)">*</span></label>
          <?php if (!empty($latestRequest['seller_national_card'])): ?>
          <div class="mb-2">
            <img src="<?= h(store_request_doc_url((int)$latestRequest['id'], 'national_card')) ?>" alt="کارت ملی"
                 style="max-height:90px;border-radius:var(--radius-sm);border:1px solid var(--border)">
          </div>
          <?php endif; ?>
          <input type="file" class="form-control" name="national_card" accept="image/*"
                 <?= empty($latestRequest['seller_national_card']) ? 'required' : '' ?>>
          <small class="fs-sm text-muted">JPG, PNG, WebP — حداکثر ۵ مگابایت</small>
        </div>

        <div class="form-group">
          <label class="form-label">تصویر شناسنامه <span style="color:var(--danger)">*</span></label>
          <?php if (!empty($latestRequest['seller_birth_cert'])): ?>
          <div class="mb-2">
            <img src="<?= h(store_request_doc_url((int)$latestRequest['id'], 'birth_cert')) ?>" alt="شناسنامه"
                 style="max-height:90px;border-radius:var(--radius-sm);border:1px solid var(--border)">
          </div>
          <?php endif; ?>
          <input type="file" class="form-control" name="birth_cert" accept="image/*"
                 <?= empty($latestRequest['seller_birth_cert']) ? 'required' : '' ?>>
        </div>

        <div class="form-group">
          <label class="form-label">آدرس <span style="color:var(--danger)">*</span></label>
          <input type="text" class="form-control" name="seller_address" required maxlength="255"
                 value="<?= h($docsFields['seller_address']) ?>">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
          <div class="form-group">
            <label class="form-label">تلفن همراه <span style="color:var(--danger)">*</span></label>
            <input type="tel" class="form-control" name="seller_phone" required dir="ltr"
                   placeholder="09123456789" value="<?= h($docsFields['seller_phone']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">تلفن ثابت</label>
            <input type="tel" class="form-control" name="seller_landline" dir="ltr"
                   placeholder="02112345678" value="<?= h($docsFields['seller_landline']) ?>">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
          <div class="form-group">
            <label class="form-label">کد پستی</label>
            <input type="text" class="form-control" name="seller_postal_code" dir="ltr"
                   inputmode="numeric" maxlength="10" placeholder="1234567890"
                   value="<?= h($docsFields['seller_postal_code']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">شماره شبا / حساب بانکی</label>
            <input type="text" class="form-control" name="seller_bank_account" dir="ltr"
                   placeholder="IR120000000000000000000000" value="<?= h($docsFields['seller_bank_account']) ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">
          <i class="bi bi-shield-check"></i> ثبت اطلاعات فروشنده (مرحله ۲)
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
            <th>نوع فروشگاه</th>
            <th>وضعیت</th>
            <th>مدارک</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myRequests as $req): ?>
          <tr>
            <td><?= h($req['store_name']) ?></td>
            <td class="fs-sm"><?= h($buildLabels[$req['build_type'] ?? 'coded'] ?? '—') ?></td>
            <td><?= h($statusLabels[$req['status']] ?? $req['status']) ?></td>
            <td class="fs-sm"><?= h($docsLabels[$req['docs_status'] ?? 'pending'] ?? '—') ?></td>
            <td class="fs-sm text-muted"><?= h(timeago($req['created_at'])) ?></td>
          </tr>
          <?php if (($req['status'] ?? '') === 'rejected' && !empty($req['admin_note'])): ?>
          <tr>
            <td colspan="5" class="fs-sm text-muted">دلیل رد: <?= h($req['admin_note']) ?></td>
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
