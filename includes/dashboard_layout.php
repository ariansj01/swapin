<?php
// User panel sidebar — uses standard site header/footer from layout.php

function render_panel_styles(): void {
    echo '<link rel="stylesheet" href="' . APP_URL . '/src/css/dashboard.css">' . "\n";
}

function panel_nav_counts(array $user): array {
    $uid = (int)$user['id'];
    return [
        'messages' => (int)(DB::fetch('SELECT COUNT(*) AS c FROM messages WHERE to_user_id = ? AND is_read = 0', [$uid])['c'] ?? 0),
        'offers'   => (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "pending"',
            [$uid]
        )['c'] ?? 0),
    ];
}

function render_user_panel_open(array $user, string $active, array $navOverrides = []): void {
    $url    = APP_URL;
    $counts = panel_nav_counts($user);
    $latestPromotableListing = DB::fetch(
        'SELECT id FROM listings WHERE user_id = ? AND status = "active" ORDER BY updated_at DESC, id DESC LIMIT 1',
        [(int)$user['id']]
    );
    $promoteHref = $latestPromotableListing
        ? $url . '/listings/promote.php?id=' . (int)$latestPromotableListing['id']
        : $url . '/listings/my.php';

    $nav = [
        'dashboard' => [$url . '/dashboard', 'داشبورد', 'bi-speedometer2', 0],
        'my'        => [$url . '/listings/my', 'آگهی‌های من', 'bi-grid', 0],
        'promote'   => [$promoteHref, 'ارتقای آگهی', 'bi-rocket-takeoff', 0],
        'messages'  => [$url . '/messages', 'پیام‌ها', 'bi-chat-dots', $counts['messages']],
        'saved'     => [$url . '/listings/saved', 'علاقه‌مندی‌ها', 'bi-heart', 0],
        'trades'    => [$url . '/trades', 'معاملات', 'bi-shield-lock', $counts['offers']],
        'wallet'    => [$url . '/wallet', 'کیف پول', 'bi-wallet2', 0],
        'subscription' => [$url . '/subscription', 'اشتراک', 'bi-gem', 0],
        'settings'  => [$url . '/profile/edit', 'تنظیمات', 'bi-gear', 0],
        'support'   => [$url . '/support', 'پشتیبانی', 'bi-headset', 0],
    ];

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
            ? '<span class="dash-sidebar__badge">' . $badge . '</span>'
            : '';
        echo "<a href=\"{$href}\" class=\"dash-sidebar__link{$cls}\">";
        echo "<i class=\"bi {$icon}\"></i><span>{$label}</span>{$badgeHtml}</a>";
    }

    echo '</nav>';
    echo '<div class="dash-sidebar__pro">';
    echo '<div class="dash-sidebar__pro-icon"><i class="bi bi-gem"></i></div>';
    echo '<strong>اشتراک حرفه‌ای</strong>';
    echo '<p>آگهی نامحدود + گزارش پیشرفته</p>';
    echo "<a href=\"{$url}/subscription\" class=\"dash-sidebar__pro-btn\">مشاهده پلن‌ها</a>";
    echo '</div></aside>';

    echo '<div class="dash-main-wrap">';
    echo '<button type="button" class="dash-sidebar-mobile-toggle" id="dash-sidebar-toggle" aria-label="باز کردن منوی پنل">';
    echo '<i class="bi bi-layout-sidebar-inset"></i> منوی پنل';
    echo '</button>';
    echo '<main class="dash-main" id="main-content">';
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
