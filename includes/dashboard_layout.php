<?php
// User dashboard layout — navbar + sidebar shell for account pages

function render_dashboard_head(string $title = '', string $desc = ''): void {
    $t    = $title ? h($title) . ' — ' . APP_NAME : APP_NAME;
    $d    = $desc ? h($desc) : 'پنل کاربری سواپین';
    $url  = APP_URL;
    $csrf = h(csrf_token());
    $creditUnit = h(CREDIT_UNIT);
    echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{$csrf}">
<meta name="app-url" content="{$url}">
<meta name="credit-unit" content="{$creditUnit}">
<title>{$t}</title>
<meta name="description" content="{$d}">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="{$url}/src/css/main.css">
<link rel="stylesheet" href="{$url}/src/css/dashboard.css">
<link rel="icon" type="image/x-icon" href="{$url}/src/img/fav_icon/favicon.ico">
</head>
<body class="dashboard-body">
<a href="#main-content" class="skip-link">رفتن به محتوای اصلی</a>
HTML;
}

function dashboard_nav_counts(array $user): array {
    $uid = (int)$user['id'];
    return [
        'messages' => (int)(DB::fetch('SELECT COUNT(*) AS c FROM messages WHERE to_user_id = ? AND is_read = 0', [$uid])['c'] ?? 0),
        'offers'   => (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "pending"',
            [$uid]
        )['c'] ?? 0),
    ];
}

function render_dashboard_topbar(array $user): void {
    $url      = APP_URL;
    $logoUrl  = $url . '/src/img/swapin-light-png.png';
    $counts   = dashboard_nav_counts($user);
    $initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
    $credFmt  = number_format((float)$user['credit_balance'], 0) . ' ' . CREDIT_UNIT;
    $notifTotal = $counts['messages'] + $counts['offers'];
    $notifDot   = $notifTotal > 0 ? ' dash-topbar__icon-btn--dot' : '';
    $appName    = h(APP_NAME);
    $msgBadge   = $counts['messages'] > 0
        ? '<span class="dash-topbar__badge">' . $counts['messages'] . '</span>'
        : '';

    echo <<<HTML
<header class="dash-topbar" role="banner">
  <div class="dash-topbar__inner">
    <a href="{$url}/listings/create" class="dash-topbar__cta hide-mobile">
      <i class="bi bi-plus-circle"></i> ثبت آگهی
    </a>

    <form class="dash-topbar__search" action="{$url}/" method="GET" role="search">
      <i class="bi bi-search"></i>
      <input type="search" name="q" placeholder="جستجوی آگهی‌ها…" autocomplete="off" aria-label="جستجو">
    </form>

    <nav class="dash-topbar__nav" aria-label="میانبرهای کاربر">
      <a href="{$url}/profile" class="dash-topbar__nav-link" title="پروفایل">
        <span class="dash-topbar__avatar">{$initials}</span>
        <span class="hide-mobile">{$credFmt}</span>
      </a>
      <button type="button" class="dash-topbar__icon-btn{$notifDot}" id="notif-bell-btn" title="اعلان‌ها" aria-label="اعلان‌ها">
        <i class="bi bi-bell"></i>
      </button>
      <a href="{$url}/trades" class="dash-topbar__icon-btn" title="معاملات">
        <i class="bi bi-arrow-left-right"></i>
      </a>
      <a href="{$url}/listings/saved" class="dash-topbar__icon-btn" title="علاقه‌مندی‌ها">
        <i class="bi bi-heart"></i>
      </a>
      <a href="{$url}/messages" class="dash-topbar__icon-btn" title="پیام‌ها">
        <i class="bi bi-chat-dots"></i>{$msgBadge}
      </a>
    </nav>

    <a href="{$url}/" class="dash-topbar__brand">
      <img src="{$logoUrl}" alt="{$appName}" class="dash-topbar__logo">
    </a>

    <button type="button" class="dash-topbar__menu-btn" id="dash-sidebar-toggle" aria-label="منو">
      <i class="bi bi-list"></i>
    </button>
  </div>
</header>
HTML;
}

function render_dashboard_shell(array $user, string $active, string $content, array $navOverrides = []): void {
    $url    = APP_URL;
    $counts = dashboard_nav_counts($user);

    $nav = [
        'dashboard' => [$url . '/dashboard', 'داشبورد', 'bi-speedometer2', 0],
        'my'        => [$url . '/listings/my', 'آگهی‌های من', 'bi-grid', 0],
        'promote'   => [$url . '/listings/my', 'ارتقای آگهی', 'bi-rocket-takeoff', 0],
        'messages'  => [$url . '/messages', 'پیام‌ها', 'bi-chat-dots', $counts['messages']],
        'saved'     => [$url . '/listings/saved', 'علاقه‌مندی‌ها', 'bi-heart', 0],
        'trades'    => [$url . '/trades', 'معاملات', 'bi-shield-lock', $counts['offers']],
        'wallet'    => [$url . '/wallet', 'کیف پول', 'bi-wallet2', 0],
        'settings'  => [$url . '/profile/edit', 'تنظیمات', 'bi-gear', 0],
        'support'   => [$url . '/support', 'پشتیبانی', 'bi-headset', 0],
    ];

    foreach ($navOverrides as $key => $href) {
        if (isset($nav[$key])) {
            $nav[$key][0] = $href;
        }
    }

    echo '<div class="dash-sidebar-overlay" id="dash-sidebar-overlay" hidden></div>';
    echo '<div class="dash-layout">';
    echo '<aside class="dash-sidebar" id="dash-sidebar">';
    echo '<nav class="dash-sidebar__nav" aria-label="منوی پنل">';

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
    echo '<main class="dash-main" id="main-content">' . $content . '</main>';
    echo '<footer class="dash-footer">';
    echo '<span>© ' . date('Y') . ' ' . h(APP_NAME) . '</span>';
    echo "<a href=\"{$url}/terms\">قوانین</a>";
    echo "<a href=\"{$url}/support\">پشتیبانی</a>";
    echo '</footer></div></div>';
}

function render_dashboard_footer(array $extraScripts = []): void {
    $url = APP_URL;
    echo "<script src=\"{$url}/src/js/app.js\"></script>";
    foreach ($extraScripts as $script) {
        echo "<script src=\"{$url}/{$script}\"></script>";
    }
    echo '</body></html>';
}
