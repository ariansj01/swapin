<?php
// User panel sidebar — uses standard site header/footer from layout.php

require_once __DIR__ . '/panel_nav.php';

function render_panel_styles(): void {
    echo '<link rel="stylesheet" href="' . APP_URL . '/src/css/dashboard.css">' . "\n";
}

function render_user_panel_open(array $user, string $active, array $navOverrides = []): void {
    $url  = APP_URL;
    $nav  = panel_nav_items($user);

    foreach ($navOverrides as $key => $href) {
        if (isset($nav[$key])) {
            $nav[$key][0] = $href;
        }
    }

    echo '<div class="dash-sidebar-overlay" id="dash-sidebar-overlay" hidden></div>';
    echo '<section class="user-panel section-sm">';
    echo '<div class="user-panel__inner">';
    echo '<aside class="dash-sidebar" id="dash-sidebar" aria-label="منوی پنل کاربری">';
    echo '<nav class="dash-sidebar__nav">';

    foreach ($nav as $key => [$href, $label, $icon, $badge]) {
        $cls = $active === $key ? ' dash-sidebar__link--active' : '';
        $badgeHtml = $badge > 0
            ? '<span class="dash-sidebar__badge">' . fmt_num($badge) . '</span>'
            : '';
        echo "<a href=\"{$href}\" class=\"dash-sidebar__link{$cls}\">";
        echo "<i class=\"bi {$icon}\"></i><span>{$label}</span>{$badgeHtml}</a>";
    }

    echo '</nav>';

    $logoutCsrf = csrf_field();
    echo <<<HTML
<form method="POST" action="{$url}/auth/logout" class="dash-sidebar__logout">
  {$logoutCsrf}
  <button type="submit" class="dash-sidebar__link dash-sidebar__link--logout">
    <i class="bi bi-box-arrow-right"></i><span>خروج</span>
  </button>
</form>
HTML;

    echo '<div class="dash-sidebar__pro">';
    echo '<div class="dash-sidebar__pro-icon"><i class="bi bi-gem"></i></div>';
    echo '<strong>اشتراک حرفه‌ای</strong>';
    echo '<p>آگهی بیشتر + گزارش پیشرفته</p>';
    echo "<a href=\"{$url}/subscription\" class=\"dash-sidebar__pro-btn\">مشاهده پلن‌ها</a>";
    echo '</div></aside>';

    echo '<div class="dash-main-wrap">';
    echo '<button type="button" class="dash-sidebar-mobile-toggle" id="dash-sidebar-toggle" aria-label="باز کردن منوی پنل">';
    echo '<i class="bi bi-layout-sidebar-inset"></i> منوی پنل';
    echo '</button>';
    echo '<main class="dash-main" id="main-content">';
}

function render_panel_page_header(string $title, string $subtitle = '', string|false $backUrl = '', string $backLabel = 'بازگشت'): void {
    $sub = $subtitle !== '' ? '<p class="dash-page-head__sub">' . h($subtitle) . '</p>' : '';
    $backHtml = '';
    if ($backUrl !== false) {
        $back = ($backUrl === '') ? APP_URL . '/dashboard' : $backUrl;
        $backHtml = '<a href="' . h($back) . '" class="dash-back-btn"><i class="bi bi-arrow-right"></i> ' . h($backLabel) . '</a>';
    }
    echo '<div class="dash-page-head"><div class="dash-page-head__start">';
    echo $backHtml;
    echo '<h1 class="dash-page-head__title">' . h($title) . '</h1>' . $sub;
    echo '</div></div>';
}

function render_user_panel_close(): void {
    echo '</main></div></div></section>';
}

function render_panel_scripts(array $extra = []): void {
    $url = APP_URL;
    echo "<script src=\"{$url}/src/js/panel.js\"></script>";
    foreach ($extra as $script) {
        echo "<script src=\"{$url}/{$script}\"></script>";
    }
}
