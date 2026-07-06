<?php
// Admin panel layout helpers

function render_admin_head(string $title = ''): void {
    $t    = $title ? h($title) . ' — پنل مدیریت' : 'پنل مدیریت — ' . APP_NAME;
    $url  = APP_URL;
    $csrf = h(csrf_token());
    echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{$csrf}">
<title>{$t}</title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="{$url}/src/css/main.css">
<link rel="stylesheet" href="{$url}/src/css/admin.css">
</head>
<body class="admin-body">
HTML;
}

function admin_check_migration_needed(): bool {
    $needsMigration = false;
    
    // Check for v2 migration
    if (!db_has_column('users', 'kyc_status')) $needsMigration = true;
    if (!db_has_column('listings', 'listing_mode')) $needsMigration = true;
    if (!db_has_table('disputes')) $needsMigration = true;
    if (!db_has_table('inspection_requests')) $needsMigration = true;
    
    // Check for admin migration
    if (!db_has_column('users', 'role')) $needsMigration = true;
    if (!db_has_column('listings', 'review_status')) $needsMigration = true;
    
    // Check for support migration
    if (!db_has_table('support_tickets')) $needsMigration = true;
    
    return $needsMigration;
}

function render_admin_shell(array $admin, string $active, string $content): void {
    $url    = APP_URL;
    $counts = admin_pending_counts();
    $name   = h($admin['name']);
    
    $migrationWarning = '';
    if (admin_check_migration_needed()) {
        $migrateUrl = $url . '/migrate.php';
        $migrationWarning = <<<HTML
        <div class="alert alert-warning mb-5" style="margin: var(--sp-4) var(--sp-6) 0">
            <i class="bi bi-exclamation-triangle"></i>
            Migrationها اجرا نشده‌اند! لطفاً
            <a href="{$migrateUrl}" target="_blank" style="font-weight: bold">این صفحه</a>
            را باز کنید تا جداول و ستون‌های لازم ایجاد شوند.
        </div>
        HTML;
    }

    $nav = [
        'index'       => ['/', 'داشبورد', 'bi-speedometer2', 0],
        'listings'    => ['/listings.php', 'آگهی‌ها', 'bi-grid', $counts['listings']],
        'kyc'         => ['/kyc.php', 'احراز هویت', 'bi-person-badge', $counts['kyc']],
        'inspections' => ['/inspections.php', 'بازرسی', 'bi-search', $counts['inspections']],
        'disputes'    => ['/disputes.php', 'اختلافات', 'bi-exclamation-triangle', $counts['disputes']],
        'tickets'     => ['/tickets.php', 'پشتیبانی', 'bi-headset', $counts['tickets']],
        'users'       => ['/users.php', 'کاربران', 'bi-people', 0],
    ];

    echo '<div class="admin-layout">';
    echo '<aside class="admin-sidebar">';
    echo '<div class="admin-sidebar__brand"><i class="bi bi-shield-lock"></i> پنل مدیریت</div>';
    echo '<nav class="admin-nav">';
    foreach ($nav as $key => [$href, $label, $icon, $badge]) {
        $cls = $active === $key ? ' admin-nav__link--active' : '';
        $badgeHtml = $badge > 0 ? '<span class="admin-nav__badge">' . $badge . '</span>' : '';
        echo "<a href=\"{$url}/admin{$href}\" class=\"admin-nav__link{$cls}\"><i class=\"bi {$icon}\"></i> {$label}{$badgeHtml}</a>";
    }
    echo '</nav>';
    echo '<div class="admin-sidebar__foot">';
    echo "<div class=\"admin-sidebar__user\"><i class=\"bi bi-person-circle\"></i> {$name}</div>";
    echo "<a href=\"{$url}/\" class=\"admin-nav__link\"><i class=\"bi bi-house\"></i> سایت</a>";
    $logoutCsrf = csrf_field();
    echo "<form method=\"POST\" action=\"{$url}/admin/logout.php\" style=\"margin:0\">{$logoutCsrf}";
    echo "<button type=\"submit\" class=\"admin-nav__link\" style=\"width:100%;border:0;background:none;cursor:pointer;font:inherit;text-align:inherit\">";
    echo "<i class=\"bi bi-box-arrow-left\"></i> خروج</button></form>";
    echo '</div></aside>';
    echo '<main class="admin-main" id="main-content">' . $migrationWarning . $content . '</main>';
    echo '</div>';
}

function render_admin_footer(): void {
    $url = APP_URL;
    echo "<script src=\"{$url}/src/js/app.js\"></script></body></html>";
}

function admin_flash(): array {
    $msg = $_SESSION['admin_flash'] ?? '';
    $type = $_SESSION['admin_flash_type'] ?? 'success';
    unset($_SESSION['admin_flash'], $_SESSION['admin_flash_type']);
    return [$msg, $type];
}

function admin_set_flash(string $msg, string $type = 'success'): void {
    $_SESSION['admin_flash'] = $msg;
    $_SESSION['admin_flash_type'] = $type;
}

function admin_alert_html(string $msg, string $type = 'success'): string {
    if ($msg === '') return '';
    $cls = $type === 'error' ? 'alert-danger' : 'alert-success';
    return '<div class="alert ' . $cls . ' mb-5"><i class="bi bi-info-circle"></i> ' . h($msg) . '</div>';
}
