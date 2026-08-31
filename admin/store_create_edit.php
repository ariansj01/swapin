<?php
@ini_set('upload_max_filesize', '128M');
@ini_set('post_max_size', '128M');
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '300');

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0) {
        $postMax = ini_get('post_max_size');
        $uploadMax = ini_get('upload_max_filesize');
        $error = 'حجم داده‌های ارسالی از محدودیت سرور بیشتر است. post_max_size=' . $postMax . ' ، upload_max_filesize=' . $uploadMax . ' — لطفاً عکس با حجم کمتر آپلود کنید.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
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

        $bannerDebug = [
            'has_column'      => db_has_column('users', 'store_banner'),
            'key_exists'      => isset($_FILES['store_banner']),
            'php_upload_max'  => ini_get('upload_max_filesize'),
            'php_post_max'    => ini_get('post_max_size'),
            'upload_dir'      => defined('UPLOAD_DIR') ? UPLOAD_DIR : '(not defined)',
        ];
        if (isset($_FILES['store_banner']) && db_has_column('users', 'store_banner')) {
            $bannerFile = $_FILES['store_banner'];
            if (is_array($bannerFile)) {
                $err = (int)($bannerFile['error'] ?? UPLOAD_ERR_NO_FILE);
                $bannerDebug['error_code'] = $err;
                $bannerDebug['size_bytes'] = (int)($bannerFile['size'] ?? 0);
                $bannerDebug['name']       = (string)($bannerFile['name'] ?? '');
                $bannerDebug['tmp_name']   = (string)($bannerFile['tmp_name'] ?? '');
                if ($bannerDebug['tmp_name'] !== '') {
                    $bannerDebug['tmp_is_file'] = is_file($bannerDebug['tmp_name']);
                    $bannerDebug['tmp_size']    = $bannerDebug['tmp_is_file'] ? @filesize($bannerDebug['tmp_name']) : false;
                }

                if ($err === UPLOAD_ERR_OK) {
                    $allowedMimes = [
                        'image/jpeg' => 'jpg',
                        'image/jpg'  => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        'image/gif'  => 'gif',
                        'image/x-png'=> 'png',
                        'image/x-jpeg'=> 'jpg',
                        'image/pjpeg'=> 'jpg',
                    ];
                    $verboseReason = null;

                    $maxSize = 5 * 1024 * 1024;
                    if ($bannerDebug['size_bytes'] > $maxSize) {
                        $verboseReason = 'حجم فایل (' . $bannerDebug['size_bytes'] . ' بایت) از محدودیت ۵ مگابایت بیشتر است.';
                    } elseif (empty($bannerDebug['tmp_name']) || !$bannerDebug['tmp_is_file']) {
                        $verboseReason = 'فایل موقت آپلود یافت نشد (tmp_name خالی یا وجود ندارد). ممکن است upload_tmp_dir در php.ini درست تنظیم نشده باشد.';
                    } else {
                        $infoSize = @getimagesize($bannerDebug['tmp_name']);
                        $bannerDebug['getimagesize'] = $infoSize ?: ['_failed' => true, 'error' => error_get_last()];

                        $magicBytesMime = null;
                        $magicHandle = @fopen($bannerDebug['tmp_name'], 'rb');
                        if ($magicHandle) {
                            $magicBytes = @fread($magicHandle, 16);
                            @fclose($magicHandle);
                            if ($magicBytes !== false && strlen($magicBytes) >= 4) {
                                if (substr($magicBytes, 0, 3) === "\xFF\xD8\xFF") {
                                    $magicBytesMime = 'image/jpeg';
                                } elseif (substr($magicBytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
                                    $magicBytesMime = 'image/png';
                                } elseif (substr($magicBytes, 0, 4) === 'RIFF' && strlen($magicBytes) >= 12 && substr($magicBytes, 8, 4) === 'WEBP') {
                                    $magicBytesMime = 'image/webp';
                                } elseif (substr($magicBytes, 0, 6) === 'GIF87a' || substr($magicBytes, 0, 6) === 'GIF89a') {
                                    $magicBytesMime = 'image/gif';
                                } elseif (substr($magicBytes, 0, 2) === 'BM') {
                                    $magicBytesMime = 'image/bmp';
                                }
                            }
                        }
                        $bannerDebug['magic_bytes_mime'] = $magicBytesMime;

                        if ($infoSize === false && $magicBytesMime === null) {
                            $verboseReason = 'getimagesize() نتوانست فایل را به‌عنوان عکس تشخیص دهد و magic bytes هم معتبر نبود — احتمالاً فایل خراب یا فرمت واقعی‌اش عکس نیست (حتی اگر پسوند png/jpg داشته باشد).';
                        } else {
                            $imageMime = $infoSize !== false && !empty($infoSize['mime']) ? strtolower((string)$infoSize['mime']) : null;
                            $bannerDebug['image_mime'] = $imageMime;

                            $finfoMime = null;
                            if (function_exists('finfo_open')) {
                                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                                if ($finfo) {
                                    $detected = @finfo_file($finfo, $bannerDebug['tmp_name']);
                                    if (is_string($detected) && $detected !== '') {
                                        $finfoMime = strtolower(trim($detected));
                                    }
                                    @finfo_close($finfo);
                                }
                            }
                            $bannerDebug['finfo_mime'] = $finfoMime;

                            $mimeContentMime = null;
                            if (function_exists('mime_content_type')) {
                                $detected = @mime_content_type($bannerDebug['tmp_name']);
                                if (is_string($detected) && $detected !== '') {
                                    $mimeContentMime = strtolower(trim($detected));
                                }
                            }
                            $bannerDebug['mime_content_type'] = $mimeContentMime;

                            $originalExt = strtolower((string)pathinfo($bannerDebug['name'], PATHINFO_EXTENSION));
                            $extMap = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif'];
                            $extFromName = $extMap[$originalExt] ?? null;

                            $normalizedMime = null;
                            $candidates = array_values(array_filter([$finfoMime, $mimeContentMime, $imageMime, $magicBytesMime]));
                            foreach ($candidates as $c) {
                                if (isset($allowedMimes[$c])) { $normalizedMime = $c; break; }
                            }

                            if ($normalizedMime === null && $infoSize !== false && $extFromName !== null) {
                                foreach (['image/' . $extFromName, 'image/x-' . $extFromName] as $probe) {
                                    if (isset($allowedMimes[$probe])) { $normalizedMime = $probe; break; }
                                }
                                if ($normalizedMime !== null) {
                                    $bannerDebug['mime_fallback'] = 'used_extension_because_getimagesize_ok';
                                }
                            }
                            if ($normalizedMime === null && $magicBytesMime !== null && $extFromName !== null) {
                                $bannerDebug['mime_fallback'] = 'magic_bytes_matched_but_not_in_allowed_list';
                            }

                            $bannerDebug['normalized_mime'] = $normalizedMime;
                            $bannerDebug['mime_candidates'] = $candidates;
                            $bannerDebug['original_ext'] = $originalExt;
                            $bannerDebug['ext_from_name'] = $extFromName;

                            if ($normalizedMime === null) {
                                $verboseReason = 'هیچ‌کدام از متدهای تشخیص MIME تایپ مجاز را برنگرداندند (حتی با magic bytes و fallback پسوند).'
                                               . ' finfo=' . ($finfoMime ?? 'NULL')
                                               . ' | mime_content_type=' . ($mimeContentMime ?? 'NULL')
                                               . ' | getimagesize[mime]=' . ($imageMime ?? 'NULL')
                                               . ' | magic_bytes=' . ($magicBytesMime ?? 'NULL')
                                               . ' | ext_from_name=' . ($extFromName ?? 'NULL')
                                               . ' — اگر مطمئنید فایل تصویر معتبر است، لطفاً با نرم‌افزار مبدل عکس (مثل Paint یا GIMP) دوباره با فرمت JPG یا PNG ذخیره کنید و آپلود کنید.';
                            }
                        }
                    }

                    if ($verboseReason === null && defined('UPLOAD_DIR')) {
                        $uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
                        $bannerDebug['upload_dir_exists']   = is_dir($uploadDir);
                        $bannerDebug['upload_dir_writable'] = is_writable($uploadDir);
                        $bannerDebug['upload_dir_realpath'] = @realpath($uploadDir);
                        $bannerDebug['upload_dir_perms']    = $bannerDebug['upload_dir_exists'] ? @decoct(@fileperms($uploadDir) & 0777) : null;
                        if (!$bannerDebug['upload_dir_exists']) {
                            if (!@mkdir($uploadDir, 0775, true)) {
                                $lastErr = error_get_last();
                                $verboseReason = 'پوشه مقصد uploads در مسیر ' . htmlspecialchars($uploadDir) . ' وجود ندارد و تلاش برای ساخت آن هم ناموفق بود (' . ($lastErr['message'] ?? 'نامشخص') . ').';
                            } else {
                                $bannerDebug['upload_dir_created_now'] = true;
                                $bannerDebug['upload_dir_writable'] = is_writable($uploadDir);
                            }
                        }
                        if ($verboseReason === null && !$bannerDebug['upload_dir_writable']) {
                            $probeFile = $uploadDir . 'write_test_' . uniqid() . '.tmp';
                            $probeWrite = @file_put_contents($probeFile, 'test');
                            if ($probeWrite !== false) {
                                @unlink($probeFile);
                                $bannerDebug['upload_dir_writable'] = true;
                                $bannerDebug['write_probe'] = 'passed (is_writable was lying but actual write worked)';
                            } else {
                                $lastErr = error_get_last();
                                $bannerDebug['write_probe_error'] = $lastErr;
                                $verboseReason = 'پوشه ' . htmlspecialchars($uploadDir) . ' قابل نوشتن نیست — تلاش برای نوشتن فایل تست ناموفق بود (' . ($lastErr['message'] ?? 'نامشخص') . '). دسترسی chmod/chown را بررسی کنید (معمولاً باید مالک www-data و دسترسی ۰۷۷۵ باشد).';
                            }
                        }
                    }

                    if ($verboseReason === null && defined('UPLOAD_DIR')) {
                        $uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
                        $prefix = 'store';
                        $extMap = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif'];
                        $originalExt = strtolower((string)pathinfo($bannerDebug['name'], PATHINFO_EXTENSION));
                        $ext = $allowedMimes[$normalizedMime] ?? ($extMap[$originalExt] ?? 'png');
                        $testFileName = $prefix . '_diag_' . uniqid('', true) . '_' . time() . '.' . $ext;
                        $testDest = $uploadDir . $testFileName;
                        $bannerDebug['move_dest'] = $testDest;
                        $bannerDebug['tmp_uploaded_is_uploaded_file'] = is_uploaded_file($bannerDebug['tmp_name']);
                        $clearLast = error_get_last();
                        $moved = false;
                        if ($bannerDebug['tmp_uploaded_is_uploaded_file']) {
                            $moved = @move_uploaded_file($bannerDebug['tmp_name'], $testDest);
                        }
                        if (!$moved && $bannerDebug['tmp_is_file']) {
                            $copyResult = @copy($bannerDebug['tmp_name'], $testDest);
                            if ($copyResult) {
                                @chmod($testDest, 0644);
                                $bannerDebug['move_fallback'] = 'copy_because_move_uploaded_failed';
                                $moved = true;
                            } else {
                                $bannerDebug['copy_fallback_error'] = error_get_last();
                            }
                        }
                        $bannerDebug['move_uploaded_file'] = $moved;
                        if (!$moved) {
                            $lastErr = error_get_last();
                            $bannerDebug['move_error'] = $lastErr;
                            $freeDisk = @disk_free_space($uploadDir);
                            $bannerDebug['disk_free_bytes'] = $freeDisk;
                            $verboseReason = 'move_uploaded_file() و هم copy() نتوانستند فایل را به مقصد منتقل کنند.'
                                           . ' (مسیر مقصد: ' . htmlspecialchars($testDest) . ')'
                                           . ' | is_uploaded_file=' . ($bannerDebug['tmp_uploaded_is_uploaded_file'] ? 'true' : 'false')
                                           . ' | فضای خالی دیسک: ' . ($freeDisk !== false ? number_format($freeDisk / 1048576, 1) . ' مگابایت' : 'نامشخص')
                                           . ' — جزئیات خطا آخر: ' . ($lastErr['message'] ?? '(نامشخص)');
                        } else {
                            @chmod($testDest, 0644);
                            $bannerDebug['move_saved_as'] = $testFileName;
                        }
                    }

                    if ($verboseReason === null && isset($testFileName)) {
                        $bannerDebug['upload_result'] = $testFileName;
                        $updateData['store_banner'] = $testFileName;
                    } else {
                        $uploaded = null;
                        $bannerDebug['upload_result'] = null;
                        $bannerDebug['verbose_reason'] = $verboseReason;
                        $error = 'آپلود بنر ناموفق بود. جزئیات دیاگ: ' . $verboseReason
                               . ' — نام فایل: ' . ($bannerDebug['name'] ?: '—')
                               . '، حجم بایت: ' . ($bannerDebug['size_bytes'] ?: '0');
                    }
                } elseif ($err !== UPLOAD_ERR_NO_FILE) {
                    $phpErrMap = [
                        UPLOAD_ERR_INI_SIZE   => 'حجم بنر از حد مجاز PHP (' . $bannerDebug['php_upload_max'] . ') بیشتر است.',
                        UPLOAD_ERR_FORM_SIZE  => 'حجم بنر از حد مجاز فرم بیشتر است.',
                        UPLOAD_ERR_PARTIAL    => 'آپلود بنر ناقص انجام شد.',
                        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت آپلود در سرور یافت نشد.',
                        UPLOAD_ERR_CANT_WRITE => 'سرور نمی‌تواند فایل بنر را روی دیسک بنویسد (دسترسی پوشه uploads/ را بررسی کنید).',
                        UPLOAD_ERR_EXTENSION  => 'آپلود بنر توسط یک اکستنشن PHP متوقف شد.',
                    ];
                    $error = ($phpErrMap[$err] ?? 'خطایی هنگام آپلود بنر رخ داد (کد PHP: ' . $err . ')')
                           . ' — نام فایل: ' . ($bannerDebug['name'] ?: '—')
                           . '، حجم بایت: ' . ($bannerDebug['size_bytes'] ?: '0');
                }
            }
        } elseif (isset($_FILES['store_banner']) && !db_has_column('users', 'store_banner')) {
            $error = 'ستون store_banner در جدول users وجود ندارد. ابتدا migration را اجرا کنید.';
        } else {
            $bannerDebug['skip_reason'] = 'file_upload_empty_or_column_missing';
        }
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('admin-store-banner-save', $bannerDebug);
        }

        if ($error === '') {
            if (!empty($updateData)) {
                $rowsAffected = DB::update('users', $updateData, 'id = ?', [$userId]);
                if (function_exists('swapin_debug_log')) {
                    swapin_debug_log('admin-store-update', [
                        'user_id'        => $userId,
                        'update_keys'    => array_keys($updateData),
                        'rows_affected'  => $rowsAffected,
                    ]);
                }
            }
            $bannerAfter = DB::fetch('SELECT store_banner FROM users WHERE id = ?', [$userId]);
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('admin-store-banner-after-save', [
                    'user_id'         => $userId,
                    'store_banner_db' => $bannerAfter['store_banner'] ?? null,
                ]);
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

            $successMsg = 'فروشگاه با موفقیت ذخیره شد.';
            if (!empty($bannerAfter['store_banner'])) {
                $successMsg .= ' (بنر ذخیره شد: ' . $bannerAfter['store_banner'] . ')';
            }
            admin_set_flash($successMsg);
            header('Location: ' . APP_URL . '/admin/stores.php');
            exit;
        }
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
<input type="hidden" name="MAX_FILE_SIZE" value="134217728">

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
<small style="color:#888">فرمت‌های مجاز: JPG, PNG, WebP, GIF — حداکثر ۱۲۸ مگابایت (ترجیحاً زیر ۵ مگابایت)</small>
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
