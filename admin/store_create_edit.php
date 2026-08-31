<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

$isEdit = isset($_GET['id']);
$storeUser = null;
$userId = 0;

if ($isEdit) {
    $userId = (int)$_GET['id'];
    if ($userId <= 0) {
        header('Location: ' . APP_URL . '/admin/stores.php');
        exit;
    }
    $storeUser = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$storeUser) {
        http_response_code(404);
        exit('کاربر یافت نشد');
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    if ($isEdit) {
        $userId = (int)$_GET['id'];
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
    }

    $storeName = trim((string)($_POST['store_name'] ?? ''));
    $storeType  = normalize_store_type($_POST['store_type'] ?? 'both');
    $storeDescription = trim((string)($_POST['store_description'] ?? ''));
    $storeAddress = trim((string)($_POST['store_address'] ?? ''));
    $storePhone = trim((string)($_POST['store_phone'] ?? ''));
    $storeWebsite = trim((string)($_POST['store_website'] ?? ''));
    $storeInstagram = trim((string)($_POST['store_instagram'] ?? ''));
    $storeTelegram = trim((string)($_POST['store_telegram'] ?? ''));
    $storeOpeningHours = trim((string)($_POST['store_opening_hours'] ?? ''));
    $storeLat = trim((string)($_POST['store_lat'] ?? ''));
    $storeLng = trim((string)($_POST['store_lng'] ?? ''));

    if ($userId <= 0) {
        $error = 'شناسه کاربر معتبر نیست.';
    }

    if ($error === '') {
        $user = DB::fetch('SELECT id, role FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            $error = 'کاربر با این شناسه وجود ندارد.';
        } elseif (($user['role'] ?? 'user') === 'admin') {
            $error = 'نمی‌توان برای کاربر ادمین فروشگاه تعریف کرد.';
        }
    }

    if ($error === '' && $storeName === '') {
        $error = 'نام فروشگاه الزامی است.';
    }

    if ($error === '') {
        $updateData = [];

        if (db_has_column('users', 'seller_type')) {
            $updateData['seller_type'] = 'store';
        }
        if (db_has_column('users', 'provider_type')) {
            $updateData['provider_type'] = normalize_provider_type($_POST['provider_type'] ?? 'normal_store');
        }
        if (db_has_column('users', 'store_type')) {
            $updateData['store_type'] = $storeType;
        }
        if (db_has_column('users', 'store_name')) {
            $updateData['store_name'] = clean($storeName);
        }
        if (db_has_column('users', 'store_description')) {
            $updateData['store_description'] = clean($storeDescription);
        }
        if (db_has_column('users', 'store_address')) {
            $updateData['store_address'] = clean($storeAddress);
        }
        if (db_has_column('users', 'store_phone')) {
            $updateData['store_phone'] = clean($storePhone);
        }
        if (db_has_column('users', 'store_website')) {
            $updateData['store_website'] = clean($storeWebsite);
        }
        if (db_has_column('users', 'store_instagram')) {
            $updateData['store_instagram'] = clean($storeInstagram);
        }
        if (db_has_column('users', 'store_telegram')) {
            $updateData['store_telegram'] = clean($storeTelegram);
        }
        if (db_has_column('users', 'store_opening_hours')) {
            $updateData['store_opening_hours'] = clean($storeOpeningHours);
        }
        if (db_has_column('users', 'store_lat')) {
            $updateData['store_lat'] = $storeLat !== '' ? (float)$storeLat : null;
        }
        if (db_has_column('users', 'store_lng')) {
            $updateData['store_lng'] = $storeLng !== '' ? (float)$storeLng : null;
        }

        if (db_has_column('users', 'store_slug')) {
            $currentSlug = $storeUser['store_slug'] ?? '';
            $currentStoreName = $storeUser['store_name'] ?? '';
            $shouldGenerateSlug = ($currentSlug === '' || $currentStoreName !== $storeName);
            if ($shouldGenerateSlug) {
                $slugBase = trim($storeName) ?: ('store-' . $userId);
                $slug = preg_replace('/[^a-zA-Z0-9_\-آ-ی۰-۹]+/u', '-', $slugBase);
                $slug = trim($slug, '-');
                $slug = mb_strtolower($slug, 'UTF-8');
                if (!$slug) {
                    $slug = 'store-' . $userId;
                }
                $finalSlug = $slug;
                $suffix = 1;
                while (true) {
                    $exists = DB::fetch('SELECT id FROM users WHERE store_slug = ? AND id != ?', [$finalSlug, $userId]);
                    if (!$exists) break;
                    $finalSlug = $slug . '-' . (++$suffix);
                    if ($suffix > 1000) break;
                }
                $updateData['store_slug'] = $finalSlug;
            }
        }

        if (isset($_FILES['store_banner']) && db_has_column('users', 'store_banner')) {
            $bannerFile = $_FILES['store_banner'];
            if (is_array($bannerFile)) {
                $err = (int)($bannerFile['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_OK) {
                    $uploaded = upload_image($bannerFile, 'store');
                    if ($uploaded !== null) {
                        $updateData['store_banner'] = $uploaded;
                    } else {
                        $error = 'آپلود بنر ناموفق بود. فرمت (JPG/PNG/WebP/GIF) یا حجم فایل (حداکثر ۵ مگابایت) را بررسی کنید.';
                    }
                } elseif ($err !== UPLOAD_ERR_NO_FILE) {
                    $phpErrMap = [
                        UPLOAD_ERR_INI_SIZE   => 'حجم بنر از حد مجاز PHP بیشتر است.',
                        UPLOAD_ERR_FORM_SIZE  => 'حجم بنر از حد مجاز فرم بیشتر است.',
                        UPLOAD_ERR_PARTIAL    => 'آپلود بنر ناقص انجام شد.',
                        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت آپلود در سرور یافت نشد.',
                        UPLOAD_ERR_CANT_WRITE => 'سرور نمی‌تواند فایل بنر را روی دیسک بنویسد.',
                        UPLOAD_ERR_EXTENSION  => 'آپلود بنر توسط یک اکستنشن PHP متوقف شد.',
                    ];
                    $error = $phpErrMap[$err] ?? 'خطایی هنگام آپلود بنر رخ داد (کد: ' . $err . ')';
                }
            }
        }

        if (!empty($updateData)) {
            DB::update('users', $updateData, 'id = ?', [$userId]);
        }

        $shouldIssuePassword = !$isEdit || !empty($_POST['reset_store_password']);
        $afterSave = DB::fetch('SELECT store_slug, store_name, store_login FROM users WHERE id = ?', [$userId]);
        if ($shouldIssuePassword) {
            $issued = issue_store_panel_password($userId, $afterSave['store_slug'] ?? null);
            if ($issued) {
                admin_set_store_credentials_once(
                    $userId,
                    $issued['login'],
                    $issued['password'],
                    (string)($afterSave['store_name'] ?? $storeName)
                );
            }
        } elseif ($afterSave) {
            ensure_user_store_login($userId, $afterSave['store_slug'] ?? null);
        }

        admin_set_flash('فروشگاه با موفقیت ذخیره شد.');
        header('Location: ' . APP_URL . '/admin/stores.php');
        exit;
    }
}

ob_start();

$pageTitle = $isEdit ? 'ویرایش فروشگاه' : 'ایجاد فروشگاه';

[$flashMsg, $flashType] = admin_flash();
if ($flashMsg !== '') {
    echo admin_alert_html($flashMsg, $flashType);
}
?>

<div class="admin-header">
<h1><?= h($pageTitle) ?></h1>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-danger mb-5"><i class="bi bi-exclamation-triangle"></i> <?= h($error) ?></div>
<?php endif; ?>

<form method="POST" class="card" style="padding:30px" enctype="multipart/form-data">
<?= csrf_field() ?>

<?php if (!$isEdit): ?>
<div class="form-group">
<label>انتخاب کاربر مالک فروشگاه <span style="color:red">*</span></label>
<select name="user_id" class="form-control" required>
<option value="">-- کاربر را انتخاب کنید --</option>
<?php
$users = DB::fetchAll('SELECT id, name, email, phone FROM users WHERE role != "admin" OR role IS NULL ORDER BY name LIMIT 500');
foreach ($users as $u):
    $label = trim((string)($u['name'] ?? ''));
    if ($label === '') $label = (string)($u['email'] ?? '');
    if ($label === '') $label = (string)($u['phone'] ?? '');
    $extra = [];
    if (!empty($u['email'])) $extra[] = $u['email'];
    if (!empty($u['phone'])) $extra[] = $u['phone'];
    $extraStr = $extra ? (' (' . implode(' - ', $extra) . ')') : '';
?>
<option value="<?= (int)$u['id'] ?>" <?= (isset($_POST['user_id']) && (int)$_POST['user_id'] === (int)$u['id']) ? 'selected' : '' ?>>
<?= h($label . $extraStr) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<?php else: ?>
<div class="form-group">
<label>مالک فروشگاه</label>
<?php
$ownerLabel = trim((string)($storeUser['name'] ?? ''));
if ($ownerLabel === '') $ownerLabel = (string)($storeUser['email'] ?? '');
if ($ownerLabel === '') $ownerLabel = (string)($storeUser['phone'] ?? '');
$ownerExtra = [];
if (!empty($storeUser['email'])) $ownerExtra[] = $storeUser['email'];
if (!empty($storeUser['phone'])) $ownerExtra[] = $storeUser['phone'];
$ownerExtraStr = $ownerExtra ? (' (' . implode(' - ', $ownerExtra) . ')') : '';
?>
<input class="form-control" value="<?= h($ownerLabel . $ownerExtraStr) ?>" disabled>
</div>
<?php endif; ?>

<div class="form-group">
<label>نوع کسب‌وکار <span style="color:red">*</span></label>
<?= render_provider_type_select('provider_type', $_POST['provider_type'] ?? ($storeUser['provider_type'] ?? 'normal_store')) ?>
</div>

<div class="form-group">
<label>نوع فروشگاه <span style="color:red">*</span></label>
<?= render_store_type_select('store_type', $_POST['store_type'] ?? ($storeUser['store_type'] ?? 'both')) ?>
<small style="color:#888">حضوری: فروشگاه فیزیکی / آنلاین: فقط اینترنتی / هر دو: هر دو حالت</small>
</div>

<div class="form-group">
<label>نام فروشگاه <span style="color:red">*</span></label>
<input class="form-control" name="store_name" required value="<?= h($_POST['store_name'] ?? ($storeUser['store_name'] ?? '')) ?>">
</div>

<div class="form-group">
<label>توضیحات فروشگاه</label>
<textarea class="form-control" name="store_description" rows="5"><?= h($_POST['store_description'] ?? ($storeUser['store_description'] ?? '')) ?></textarea>
</div>

<div class="form-group">
<label>بنر فروشگاه</label>
<?php if (!empty($storeUser['store_banner'])): ?>
<div style="margin-bottom:10px">
<img src="<?= h(UPLOAD_URL . $storeUser['store_banner']) ?>" alt="بنر فعلی" style="max-height:120px;border:1px solid #ddd;border-radius:6px;padding:4px">
</div>
<?php endif; ?>
<input type="file" name="store_banner" accept="image/*" class="form-control">
<small style="color:#888">فرمت‌های مجاز: JPG, PNG, WebP, GIF — حداکثر ۵ مگابایت</small>
</div>

<div class="form-group">
<label>آدرس فروشگاه</label>
<input class="form-control" name="store_address" value="<?= h($_POST['store_address'] ?? ($storeUser['store_address'] ?? '')) ?>">
</div>

<div class="form-group">
<label>تلفن فروشگاه</label>
<input class="form-control" name="store_phone" value="<?= h($_POST['store_phone'] ?? ($storeUser['store_phone'] ?? '')) ?>">
</div>

<div class="form-group">
<label>وب‌سایت فروشگاه</label>
<input class="form-control" name="store_website" placeholder="https://example.com" value="<?= h($_POST['store_website'] ?? ($storeUser['store_website'] ?? '')) ?>">
</div>

<div class="form-group">
<label>آیدی اینستاگرام</label>
<input class="form-control" name="store_instagram" placeholder="@username" value="<?= h($_POST['store_instagram'] ?? ($storeUser['store_instagram'] ?? '')) ?>">
</div>

<div class="form-group">
<label>آیدی تلگرام</label>
<input class="form-control" name="store_telegram" placeholder="@username" value="<?= h($_POST['store_telegram'] ?? ($storeUser['store_telegram'] ?? '')) ?>">
</div>

<div class="form-group">
<label>ساعات کاری</label>
<input class="form-control" name="store_opening_hours" placeholder="شنبه تا پنجشنبه ۹ تا ۱۸" value="<?= h($_POST['store_opening_hours'] ?? ($storeUser['store_opening_hours'] ?? '')) ?>">
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
<div class="form-group">
<label>عرض جغرافیایی (Latitude)</label>
<input class="form-control" name="store_lat" placeholder="مثلاً 35.6892" value="<?= h($_POST['store_lat'] ?? ($storeUser['store_lat'] ?? '')) ?>">
</div>
<div class="form-group">
<label>طول جغرافیایی (Longitude)</label>
<input class="form-control" name="store_lng" placeholder="مثلاً 51.3890" value="<?= h($_POST['store_lng'] ?? ($storeUser['store_lng'] ?? '')) ?>">
</div>
</div>

<hr style="margin:25px 0">

<?php if ($isEdit): ?>
<?php
$panelLogin = trim((string)($storeUser['store_login'] ?? ''));
if ($panelLogin === '' && !empty($storeUser['store_slug'])) {
    $panelLogin = store_login_from_slug((string)$storeUser['store_slug'], $userId);
}
?>
<div class="form-group" style="background:#f8f9fa;padding:16px;border-radius:8px;border:1px solid #dee2e6">
<label style="font-weight:700"><i class="bi bi-key"></i> ورود پنل فروشگاه</label>
<?php if ($panelLogin !== ''): ?>
<p class="fs-sm mb-2" style="margin-top:8px">نام کاربری فعلی: <code dir="ltr"><?= h($panelLogin) ?></code></p>
<?php endif; ?>
<p class="fs-sm text-muted mb-3">مالک فروشگاه از صفحه <a href="<?= h(APP_URL . '/auth/store-login') ?>" target="_blank">ورود فروشگاه‌ها</a> وارد پنل می‌شود.</p>
<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
<input type="checkbox" name="reset_store_password" value="1">
تولید رمز عبور جدید (پس از ذخیره، یک‌بار نمایش داده می‌شود)
</label>
</div>
<?php else: ?>
<div class="alert alert-info mb-0" style="margin-bottom:20px">
<i class="bi bi-info-circle"></i>
پس از ذخیره، <strong>نام کاربری و رمز عبور</strong> ورود به پنل فروشگاه به‌صورت یک‌باره نمایش داده می‌شود.
</div>
<?php endif; ?>

<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره فروشگاه</button>
<a href="<?= h(APP_URL . '/admin/stores.php') ?>" class="btn btn-secondary"><i class="bi bi-x-lg"></i> انصراف</a>
</div>

</form>

<?php
$content = ob_get_clean();

render_admin_head($pageTitle);
render_admin_shell($admin, 'stores', $content);
render_admin_footer();
