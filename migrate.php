<?php
require_once __DIR__ . '/includes/config.php';

$isAdmin = false;
try {
    $user = require_auth();
    $uid  = (int)($user['id'] ?? 0);
    if ($uid > 0) {
        $role = DB::fetch("SHOW COLUMNS FROM `users` LIKE 'role'");
        if ($role) {
            $roleRow = DB::fetch('SELECT `role` FROM `users` WHERE id = ?', [$uid]);
            $isAdmin = ($roleRow && ($roleRow['role'] ?? '') === 'admin');
        }
    }
} catch (Throwable $e) {
    $isAdmin = false;
}

$checks = [
    'users.kyc_status'          => ['ok' => false, 'label' => 'ستون kyc_status در جدول users'],
    'users.seller_type'         => ['ok' => false, 'label' => 'ستون seller_type در جدول users'],
    'users.store_name'          => ['ok' => false, 'label' => 'ستون store_name در جدول users'],
    'users.subscription_plan'   => ['ok' => false, 'label' => 'ستون subscription_plan در جدول users'],
    'users.role'                => ['ok' => false, 'label' => 'ستون role در جدول users'],
    'listings.listing_mode'     => ['ok' => false, 'label' => 'ستون listing_mode در جدول listings'],
    'listings.sell_price'       => ['ok' => false, 'label' => 'ستون sell_price در جدول listings'],
    'listings.review_status'    => ['ok' => false, 'label' => 'ستون review_status در جدول listings'],
    'wallet_transactions'       => ['ok' => false, 'label' => 'جدول wallet_transactions'],
    'inspection_requests'       => ['ok' => false, 'label' => 'جدول inspection_requests'],
    'disputes'                  => ['ok' => false, 'label' => 'جدول disputes'],
    'support_tickets'           => ['ok' => false, 'label' => 'جدول support_tickets'],
    'support_messages'          => ['ok' => false, 'label' => 'جدول support_messages'],
    'error_reports'             => ['ok' => false, 'label' => 'جدول error_reports'],
];

function run_all_migration_checks(array &$checks): void {
    try {
        $usersCols = db_table_columns('users');
        $checks['users.kyc_status']['ok']        = in_array('kyc_status', $usersCols);
        $checks['users.seller_type']['ok']       = in_array('seller_type', $usersCols);
        $checks['users.store_name']['ok']        = in_array('store_name', $usersCols);
        $checks['users.subscription_plan']['ok'] = in_array('subscription_plan', $usersCols);
        $checks['users.role']['ok']              = in_array('role', $usersCols);
    } catch (Throwable $e) {}

    try {
        $listingsCols = db_table_columns('listings');
        $checks['listings.listing_mode']['ok']  = in_array('listing_mode', $listingsCols);
        $checks['listings.sell_price']['ok']    = in_array('sell_price', $listingsCols);
        $checks['listings.review_status']['ok'] = in_array('review_status', $listingsCols);
    } catch (Throwable $e) {}

    try { $checks['wallet_transactions']['ok'] = db_has_table('wallet_transactions'); } catch (Throwable $e) {}
    try { $checks['inspection_requests']['ok'] = db_has_table('inspection_requests'); } catch (Throwable $e) {}
    try { $checks['disputes']['ok'] = db_has_table('disputes'); } catch (Throwable $e) {}
    try { $checks['support_tickets']['ok'] = db_has_table('support_tickets'); } catch (Throwable $e) {}
    try { $checks['support_messages']['ok'] = db_has_table('support_messages'); } catch (Throwable $e) {}
    try { $checks['error_reports']['ok'] = db_has_table('error_reports'); } catch (Throwable $e) {}
}

run_all_migration_checks($checks);

$applied = [];
$errors  = [];
$ranMigration = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $ranMigration = true;

    $migrationFiles = [
        __DIR__ . '/migration_2026_07_12_swaapin_v3.php',
        __DIR__ . '/migration_add_shipping_status.php',
        __DIR__ . '/migration_add_trade_rating.php',
    ];

    foreach ($migrationFiles as $file) {
        if (file_exists($file)) {
            try {
                require_once $file;
                $applied[] = basename($file) . ' اجرا شد.';
            } catch (Throwable $e) {
                $errors[] = basename($file) . ': ' . $e->getMessage();
            }
        }
    }

    run_all_migration_checks($checks);
}

$allOk = true;
foreach ($checks as $c) { if (!$c['ok']) { $allOk = false; break; } }

$pageTitle = 'اجرای Migration دیتابیس';
$homeUrl = APP_URL . '/';
$dashUrl = APP_URL . '/dashboard';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= h($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/fonts.css?v=1">
<link rel="stylesheet" href="<?= APP_URL ?>/src/vendor/bootstrap-icons/bootstrap-icons.css?v=1">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Vazirmatn', 'IRANSans', Tahoma, sans-serif;
        background: #f7f8fa;
        min-height: 100vh;
        padding: 40px 16px;
        color: #1f2937;
    }
    .container { max-width: 820px; margin: 0 auto; }
    .card {
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.06);
        overflow: hidden;
    }
    .card-head {
        padding: 28px 32px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
        color: #fff;
    }
    .card-head h1 { font-size: 22px; font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
    .card-head p { opacity: 0.9; font-size: 14px; }
    .card-body { padding: 28px 32px; }
    .status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; margin-bottom: 24px; }
    .status-item {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 14px;
        background: #f9fafb;
        border-radius: 12px;
        font-size: 14px;
        border: 1px solid #e5e7eb;
    }
    .status-item.ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    .status-item.notok { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
    .status-dot {
        width: 18px; height: 18px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 11px; color: #fff; flex-shrink: 0;
    }
    .status-item.ok .status-dot { background: #10b981; }
    .status-item.notok .status-dot { background: #ef4444; }

    .summary {
        padding: 16px; border-radius: 14px;
        margin-bottom: 20px;
        display: flex; align-items: center; gap: 12px;
        font-size: 15px;
    }
    .summary.allok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .summary.notok { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
    .summary i { font-size: 22px; flex-shrink: 0; }

    .actions { display: flex; gap: 12px; flex-wrap: wrap; }
    .btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 12px 20px;
        border-radius: 12px;
        border: none;
        font: inherit;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s;
        font-size: 14px;
    }
    .btn-primary { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
    .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(99,102,241,0.3); }
    .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-outline {
        background: #fff;
        color: #374151;
        border: 1px solid #d1d5db;
    }
    .btn-outline:hover { background: #f9fafb; }

    .results { margin-top: 24px; }
    .result-block {
        padding: 16px;
        border-radius: 14px;
        margin-bottom: 12px;
        font-size: 14px;
    }
    .result-block.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .result-block.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .result-block h4 { font-size: 14px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
    .result-block ul { padding-inline-start: 20px; }
    .result-block li { margin-bottom: 4px; }

    .warn-box {
        margin-top: 20px;
        padding: 16px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 14px;
        font-size: 13px;
        color: #92400e;
        line-height: 1.8;
    }
    .warn-box strong { display: block; margin-bottom: 4px; font-size: 14px; }

    @media (max-width: 640px) {
        .status-grid { grid-template-columns: 1fr; }
        .card-head, .card-body { padding: 20px 18px; }
    }
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-head">
            <h1><i class="bi bi-database-gear"></i> <?= h($pageTitle) ?></h1>
            <p>بررسی و ایجاد جداول و ستون‌های مورد نیاز سیستم سواپین</p>
        </div>
        <div class="card-body">

            <div class="summary <?= $allOk ? 'allok' : 'notok' ?>">
                <i class="bi <?= $allOk ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                <div>
                    <?= $allOk
                        ? '<strong>همه migrationها انجام شده‌اند.</strong> سیستم آماده استفاده است.'
                        : '<strong>بخشی از ساختار دیتابیس کامل نیست.</strong> برای رفع مشکل دکمه «اجرای Migration» را بزنید.' ?>
                </div>
            </div>

            <h3 style="font-size:15px; margin-bottom:14px; color:#374151">وضعیت جدول‌ها و ستون‌ها:</h3>
            <div class="status-grid">
                <?php foreach ($checks as $key => $c): ?>
                <div class="status-item <?= $c['ok'] ? 'ok' : 'notok' ?>">
                    <span class="status-dot"><i class="bi <?= $c['ok'] ? 'bi-check-lg' : 'bi-x-lg' ?>"></i></span>
                    <span><?= h($c['label']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($ranMigration): ?>
            <div class="results">
                <?php if (!empty($applied)): ?>
                <div class="result-block success">
                    <h4><i class="bi bi-check2-circle"></i> نتیجه موفق:</h4>
                    <ul><?php foreach ($applied as $a): ?><li><?= h($a) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                <div class="result-block error">
                    <h4><i class="bi bi-x-circle"></i> خطاهایی رخ داد:</h4>
                    <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <?php if (empty($applied) && empty($errors)): ?>
                <div class="result-block success">
                    <h4><i class="bi bi-info-circle"></i> Migrationها از قبل در bootstrap (config.php) اجرا شده‌اند.</h4>
                    <p>کافی است یک بار صفحه داشبورد را رفرش کنید تا هشدار از بین برود.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="actions">
                <?php if (!$allOk || !$ranMigration): ?>
                <form method="POST" style="display:inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary" <?= $ranMigration && $allOk ? 'disabled' : '' ?>>
                        <i class="bi bi-play-circle-fill"></i>
                        <?= $ranMigration && !$allOk ? 'اجرای دوباره Migration' : 'اجرای Migration' ?>
                    </button>
                </form>
                <?php endif; ?>
                <a href="<?= h($dashUrl) ?>" class="btn btn-outline"><i class="bi bi-speedometer2"></i> داشبورد</a>
                <a href="<?= h($homeUrl) ?>" class="btn btn-outline"><i class="bi bi-house"></i> صفحه اصلی</a>
            </div>

            <div class="warn-box">
                <strong><i class="bi bi-info-circle"></i> نکته مهم:</strong>
                بعد از آپلود روی سرور، کافی است این صفحه یک بار باز شود تا جداول و ستون‌های کمکی به صورت خودکار ایجاد شوند.
                همچنین در هر بار لود سایت، فایل includes/config.php بخشی از migrationها را به صورت خودکار اجرا می‌کند (bootstrap migration).
                <?php if (!$isAdmin): ?>
                    <br><strong style="color:#991b1b"><i class="bi bi-shield-exclamation"></i> توجه:</strong>
                    شما به عنوان ادمین وارد نشده‌اید. برای اجرای کامل توصیه می‌شود با اکانت ادمین لاگین کنید.
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</body>
</html>
