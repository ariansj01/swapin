<?php
// includes/layout.php — shared header/footer helpers
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/seo.php';

function render_head(string $title = '', string $desc = '', array $seo = []): void {
    $t         = $title ? h($title) . ' — ' . APP_NAME : APP_NAME . ' — بازار تعویض هوشمند';
    $d         = $desc  ? h($desc)  : 'کالا و خدمات را مستقیم در سواپین مبادله کنید — بازار تعویض هوشمند.';
    $url       = APP_URL;
    $canonical = h($seo['canonical'] ?? seo_canonical());
    $ogImage   = h($seo['og_image'] ?? LOGO_URL);
    $ogType    = h($seo['og_type'] ?? 'website');
    $robots    = h($seo['robots'] ?? 'index, follow');
    $ogTitle   = h($seo['og_title'] ?? ($title ?: APP_NAME . ' — بازار تعویض هوشمند'));
    $favicon   = $url . '/src/img/logo.png';
    $appName    = h(APP_NAME);
    $creditUnit = h(CREDIT_UNIT);
    $csrf       = h(csrf_token());

    $keywords = '';
    if (!empty($seo['keywords'])) {
        $keywords = '<meta name="keywords" content="' . h($seo['keywords']) . '">' . "\n";
    }

    $jsonLd = '';
    if (!empty($seo['json_ld'])) {
        $blocks = $seo['json_ld'];
        if (isset($blocks['@context'])) {
            $blocks = [$blocks];
        }
        foreach ($blocks as $block) {
            $jsonLd .= '<script type="application/ld+json">'
                . json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "</script>\n";
        }
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{$csrf}">
<title>{$t}</title>
<meta name="description" content="{$d}">
<meta name="robots" content="{$robots}">
<meta name="app-url" content="{$url}">
<meta name="credit-unit" content="{$creditUnit}">
<link rel="canonical" href="{$canonical}">
<meta property="og:locale" content="fa_IR">
<meta property="og:site_name" content="{$appName}">
<meta property="og:title" content="{$ogTitle}">
<meta property="og:description" content="{$d}">
<meta property="og:url" content="{$canonical}">
<meta property="og:type" content="{$ogType}">
<meta property="og:image" content="{$ogImage}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$ogTitle}">
<meta name="twitter:description" content="{$d}">
<meta name="twitter:image" content="{$ogImage}">
<meta name="theme-color" content="#0a2540">
{$keywords}{$jsonLd}<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="{$url}/src/css/main.css">
<link rel="icon" type="image/x-icon" href="{$url}/src/img/fav_icon/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="{$url}/src/img/fav_icon/web-app-manifest-512x512.png">
<link rel="icon" type="image/png" sizes="16x16" href="{$url}/src/img/fav_icon/web-app-manifest-192x192.png">
<link rel="apple-touch-icon" href="{$url}/src/img/fav_icon/apple-touch-icon.png">

<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-S0RG4SWX8K"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-S0RG4SWX8K');
</script>
<link rel="manifest" href="{$url}/src/img/fav_icon/site.webmanifest">
</head>
<body>
<a href="#main-content" class="skip-link">رفتن به محتوای اصلی</a>
HTML;
}

function render_navbar(?array $user = null): void {
    $url        = APP_URL;
    $logoUrl    = APP_URL . '/src/img/swapin-light-png.png';
    $appName    = APP_NAME;
    $creditUnit = h(CREDIT_UNIT);
    $unread     = 0;
    $pendOffers = 0;
    if ($user) {
        $unread     = (int)(DB::fetch('SELECT COUNT(*) as c FROM messages WHERE to_user_id = ? AND is_read = 0', [$user['id']])['c'] ?? 0);
        $pendOffers = (int)(DB::fetch(
            'SELECT COUNT(*) as c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "pending"',
            [$user['id']]
        )['c'] ?? 0);
    }
    $notifTotal = $unread + $pendOffers;
    $notifClass = $notifTotal > 0 ? 'notif-dot' : '';
    $initials   = $user ? mb_strtoupper(mb_substr($user['name'], 0, 1)) : '';
    $loggedIn   = $user !== null;
    $GLOBALS['_nav_user'] = $user;

    $navItems = [
        // ['/', 'خانه', 'bi-house', ''],
        // ['/#listings', 'کالاها', 'bi-grid', ''],
        ['/listings/create', 'ثبت کالا', 'bi-plus-circle', ''],
        ['/trades', 'پیشنهادها', 'bi-inbox', ''],
        ['/wallet', 'کیف پول', 'bi-wallet2', ''],
        ['/ai/chat', 'دستیار AI', 'bi-stars', 'navbar-nav__link--ai'],
        ['/about', 'درباره ما', 'bi-question-circle', ''],
        ['/contact', 'تماس با ما', 'bi-envelope', ''],
        ['/support', 'پشتیبانی', 'bi-headset', ''],
        ['/fraud-prevention', 'راهنمای امنیت', 'bi-shield-exclamation', ''],
    ];

    echo <<<HTML
<header class="site-header" role="banner">
<nav class="navbar" aria-label="ناوبری اصلی">
  <div class="navbar-inner">
    <button type="button" class="navbar-hamburger" id="nav-hamburger" aria-label="منو">
      <i class="bi bi-list"></i>
    </button>

    <a href="{$url}/" class="navbar-brand">
      <img src="{$logoUrl}" alt="{$appName}" class="brand-logo">
    </a>

    <div class="navbar-nav hide-mobile">
HTML;
    foreach ($navItems as [$href, $label, $icon, $extraClass]) {
        $fullHref = str_starts_with($href, '/#') ? $url . $href : $url . $href;
        $cls = 'navbar-nav__link' . ($extraClass ? ' ' . $extraClass : '');
        echo "<a href=\"{$fullHref}\" class=\"{$cls}\"><i class=\"bi {$icon}\"></i> {$label}</a>";
    }
    echo <<<HTML
    </div>

    <!-- <div class="navbar-search hide-mobile">
      <i class="bi bi-search search-icon"></i>
      <input type="search" id="global-search" placeholder="جستجوی آگهی‌ها…" autocomplete="off">
    </div> -->

    <div class="navbar-actions">
HTML;
    if ($loggedIn) {
        $credFmt = number_format((float)$user['credit_balance'], 0);
        if (is_store_seller($user)) {
            echo <<<HTML
      <a href="{$url}/store" class="btn btn-outline hide-mobile">
        <i class="bi bi-shop"></i> فروشگاه
      </a>
HTML;
        }
        echo <<<HTML
      <button type="button" class="btn btn-ghost btn-icon {$notifClass}" id="notif-bell-btn" title="اعلان‌ها" aria-label="اعلان‌ها">
        <i class="bi bi-bell"></i>
      </button>
      <div class="dropdown hide-mobile">
        <button class="btn btn-ghost d-flex align-center gap-2" id="user-menu-btn">
          <span class="avatar avatar-sm">{$initials}</span>
          <span class="hide-mobile" style="font-size:.875rem;font-weight:600">{$credFmt} {$creditUnit}</span>
          <i class="bi bi-chevron-down" style="font-size:.75rem"></i>
        </button>
        <div class="dropdown-menu" id="user-menu">
          <a href="{$url}/dashboard" class="dropdown-item"><i class="bi bi-speedometer2"></i> داشبورد</a>
HTML;
        if (is_store_seller($user)) {
            echo <<<HTML
          <a href="{$url}/store" class="dropdown-item"><i class="bi bi-shop"></i> پنل فروشگاه</a>
HTML;
        }
        echo <<<HTML
          <a href="{$url}/listings/my" class="dropdown-item"><i class="bi bi-grid"></i> آگهی‌های من</a>
          <a href="{$url}/listings/saved" class="dropdown-item"><i class="bi bi-heart"></i> علاقه‌مندی‌ها</a>
          <a href="{$url}/support" class="dropdown-item"><i class="bi bi-headset"></i> پشتیبانی</a>
          <a href="{$url}/trades" class="dropdown-item"><i class="bi bi-arrow-left-right"></i> معاملات من</a>
          <a href="{$url}/wallet" class="dropdown-item"><i class="bi bi-wallet2"></i> کیف پول</a>
          <a href="{$url}/subscription" class="dropdown-item"><i class="bi bi-gem"></i> اشتراک</a>
          <a href="{$url}/profile/edit" class="dropdown-item"><i class="bi bi-shield-check"></i> احراز هویت</a>
          <div class="dropdown-divider"></div>
          <a href="{$url}/profile" class="dropdown-item"><i class="bi bi-person"></i> پروفایل</a>
HTML;
        $logoutCsrf = csrf_field();
        echo <<<HTML
          <form method="POST" action="{$url}/auth/logout" style="margin:0">
            {$logoutCsrf}
            <button type="submit" class="dropdown-item" style="color:var(--danger);width:100%;border:0;background:none;text-align:inherit;cursor:pointer;font:inherit">
              <i class="bi bi-box-arrow-right"></i> خروج
            </button>
          </form>
        </div>
      </div>
HTML;
    } else {
        echo <<<HTML
      <button type="button" class="btn btn-ghost btn-icon {$notifClass}" id="notif-bell-btn" title="اعلان‌ها" aria-label="اعلان‌ها">
        <i class="bi bi-bell"></i>
      </button>
      <a href="{$url}/auth/login" class="btn btn-accent">ورود / ثبت‌نام</a>
HTML;
    }
    echo <<<HTML
    </div>
  </div>
</nav>
</header>

<div class="mobile-nav-overlay" id="mobile-nav-overlay"></div>
<aside class="mobile-drawer" id="mobile-drawer">
  <div class="mobile-drawer__header">
    <strong>منو</strong>
    <button type="button" id="nav-hamburger-close" aria-label="بستن"><i class="bi bi-x-lg"></i></button>
  </div>
  <nav class="mobile-drawer__nav">
HTML;
    foreach ($navItems as [$href, $label, $icon, $extraClass]) {
        $fullHref = str_starts_with($href, '/#') ? $url . $href : $url . $href;
        echo "<a href=\"{$fullHref}\" class=\"mobile-drawer__link\"><i class=\"bi {$icon}\"></i> {$label}</a>";
    }
    if (!$loggedIn) {
        echo "<a href=\"{$url}/auth/login\" class=\"mobile-drawer__link\"><i class=\"bi bi-box-arrow-in-right\"></i> ورود / ثبت‌نام</a>";
    }
    echo <<<HTML
  </nav>
</aside>

<div id="toast-container"></div>
HTML;
    render_notifications_modal($user);
    render_search_modal();
}

function render_search_modal() {
    $url = APP_URL;
    $searchValue = isset($_GET['q']) ? h($_GET['q']) : '';
    echo <<<HTML
<div class="modal-overlay" id="search-modal">
  <div class="modal-box search-modal-box">
    <div class="modal-header">
      <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-search"></i> جستجوی آگهی‌ها</h3>
      <button type="button" class="modal-close" id="search-modal-close" aria-label="بستن">&times;</button>
    </div>
    <div class="modal-body search-modal-body">
      <form id="search-modal-form" action="{$url}/" method="get">
        <div style="position:relative">
          <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted)" aria-hidden="true"></i>
          <input type="search" class="form-control" style="padding-left:40px;height:48px;font-size:1rem"
                 id="search-modal-input" name="q" placeholder="جستجوی آگهی‌ها…"
                 value="{$searchValue}" autocomplete="off" autofocus>
        </div>
      </form>
    </div>
  </div>
</div>
HTML;
}

function render_notifications_modal(?array $user = null): void {
    $url      = APP_URL;
    $loggedIn = $user !== null;
    echo <<<HTML
<div class="modal-overlay" id="notif-modal">
  <div class="modal-box notif-modal-box">
    <div class="modal-header">
      <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-bell"></i> اعلان‌ها</h3>
      <button type="button" class="modal-close" id="notif-modal-close" aria-label="بستن">&times;</button>
    </div>
    <div class="modal-body notif-modal-body" id="notif-modal-body">
HTML;
    if (!$loggedIn) {
        echo <<<HTML
      <div class="notif-empty">
        <i class="bi bi-bell"></i>
        <p>برای مشاهده اعلان‌ها وارد حساب خود شوید.</p>
        <a href="{$url}/auth/login" class="btn btn-accent btn-sm">ورود / ثبت‌نام</a>
      </div>
HTML;
    } else {
        echo <<<HTML
      <div class="notif-loading" id="notif-loading" aria-live="polite" aria-busy="true">
HTML;
        require_once __DIR__ . '/skeleton.php';
        echo skeleton_notif_items(4);
        echo <<<HTML
      </div>
      <div id="notif-list" style="display:none"></div>
HTML;
    }
    echo <<<HTML
    </div>
HTML;
    if ($loggedIn) {
        echo <<<HTML
    <div class="modal-footer">
      <a href="{$url}/messages" class="btn btn-ghost btn-sm">همه پیام‌ها</a>
      <a href="{$url}/listings/offers" class="btn btn-primary btn-sm">پیشنهادهای دریافتی</a>
    </div>
HTML;
    }
    echo <<<HTML
  </div>
</div>
HTML;
}

function render_mobile_bottom_nav(?array $user = null): void {
    $url      = APP_URL;
    $user     = $user ?? ($GLOBALS['_nav_user'] ?? null);
    $profile  = $user ? $url . '/profile' : $url . '/auth/login';
    $messages = $user ? $url . '/messages' : $url . '/auth/login';
    echo <<<HTML
<nav class="mobile-bottom-nav" aria-label="ناوبری موبایل">
  <a href="{$url}/" class="mobile-bottom-nav__item">
    <i class="bi bi-house"></i>
    <span>خانه</span>
  </a>
  <a href="{$url}/#listings" class="mobile-bottom-nav__item" id="mobile-search-link">
    <i class="bi bi-search"></i>
    <span>جستجو</span>
  </a>
  <a href="{$url}/listings/create" class="mobile-bottom-nav__item mobile-bottom-nav__item--fab">
    <span class="mobile-bottom-nav__fab"><i class="bi bi-plus-lg"></i></span>
    <span>ثبت کالا</span>
  </a>
  <a href="{$messages}" class="mobile-bottom-nav__item">
    <i class="bi bi-chat-dots"></i>
    <span>پیام‌ها</span>
  </a>
  <a href="{$profile}" class="mobile-bottom-nav__item">
    <i class="bi bi-person"></i>
    <span>پروفایل</span>
  </a>
</nav>
HTML;
}

function render_footer(): void {
    $url      = APP_URL;
    $appName  = APP_NAME;
    $logoUrl  = $url . '/src/img/swapin-light-png.png';
    $enamadUrl = $url . '/src/img/enamad.png';
    $user     = $GLOBALS['_nav_user'] ?? null;
    render_mobile_bottom_nav($user);
    echo <<<HTML
<footer class="site-footer">
  <div class="container">
    <dl class="site-footer__stats">
      <div class="site-footer__stat">
        <i class="bi bi-emoji-smile site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dt class="site-footer__stat-label">رضایت کاربران</dt>
          <dd class="site-footer__stat-value">۹۸٪</dd>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-arrow-left-right site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dt class="site-footer__stat-label">مبادله موفق</dt>
          <dd class="site-footer__stat-value">۴۵,۰۰۰+</dd>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-box-seam site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dt class="site-footer__stat-label">کالای ثبت‌شده</dt>
          <dd class="site-footer__stat-value">۱۲۰,۰۰۰+</dd>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-people site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dt class="site-footer__stat-label">کاربر فعال</dt>
          <dd class="site-footer__stat-value">۲۵۰,۰۰۰+</dd>
        </div>
      </div>
    </dl>

    <div class="site-footer__main">
      <div class="site-footer__col">
        <h3 class="site-footer__heading">تماس با ما</h3>
        <ul class="site-footer__contact-list">
          <li>
            <i class="bi bi-telephone" aria-hidden="true"></i>
            <span dir="ltr">+98 998 153 4269</span>
          </li>
          <li>
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <a href="mailto:info@swaapin.ir">info@swaapin.ir</a>
          </li>
          <li>
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <span>مرکز نواوری اکباتان</span>
          </li>
        </ul>
      </div>

      <div class="site-footer__col">
        <h3 class="site-footer__heading">نمادهای اعتماد</h3>
        <div class="site-footer__trust">
          <a referrerpolicy="origin" target="_blank" href="https://trustseal.enamad.ir/?id=755927&amp;Code=Io4wGYGFQ4YQdD53jiYDAKvPgKHr8sGM"><img referrerpolicy="origin" src="https://trustseal.enamad.ir/logo.aspx?id=755927&amp;Code=Io4wGYGFQ4YQdD53jiYDAKvPgKHr8sGM" alt="نماد اعتماد الکترونیکی" style="cursor:pointer" code="Io4wGYGFQ4YQdD53jiYDAKvPgKHr8sGM"></a>
        </div>
      </div>

      <div class="site-footer__col">
        <h3 class="site-footer__heading">لینک‌های مفید</h3>
        <ul class="site-footer__links">
          <li><a href="{$url}/about">درباره ما</a></li>
          <li><a href="{$url}/contact">تماس با ما</a></li>
          <li><a href="{$url}/support">پشتیبانی</a></li>
          <li><a href="{$url}/fraud-prevention">راهنمای امنیت</a></li>
          <li><a href="{$url}/blog">بلاگ</a></li>
          <li><a href="{$url}/about">قوانین و مقررات</a></li>
          <li><a href="{$url}/about">حریم خصوصی</a></li>
        </ul>
      </div>

      <div class="site-footer__col site-footer__col--brand">
        <a href="{$url}/" class="site-footer__brand">
          <img src="{$logoUrl}" alt="{$appName}" class="site-footer__logo">
        </a>
        <p class="site-footer__tagline">بزرگترین پلتفرم مبادله کالا با کالا در ایران</p>
        <div class="site-footer__social">
          <a href="#" class="site-footer__social-link" aria-label="اینستاگرام"><i class="bi bi-instagram"></i></a>
          <a href="#" class="site-footer__social-link" aria-label="تلگرام"><i class="bi bi-telegram"></i></a>
          <a href="#" class="site-footer__social-link" aria-label="توییتر"><i class="bi bi-twitter-x"></i></a>
          <a href="#" class="site-footer__social-link" aria-label="لینکدین"><i class="bi bi-linkedin"></i></a>
        </div>
      </div>
    </div>

    <p class="site-footer__copy">تمامی حقوق این وبسایت متعلق به سواپین می‌باشد.</p>
  </div>
</footer>
HTML;
    render_support_widget($user);
    echo <<<HTML
<script src="{$url}/src/js/app.js"></script>
</body>
</html>
HTML;
}

function render_support_widget(?array $user = null): void {
    $url = APP_URL;
    $loggedIn = $user !== null;
    $supportHref = $loggedIn ? $url . '/support/index.php' : $url . '/auth/login.php?redirect=' . urlencode('/support/index.php');
    $reportHref  = $url . '/support/report.php';
    $fraudHref   = $url . '/fraud-prevention.php';

    echo <<<HTML
<div class="support-widget" id="support-widget">
  <button type="button" class="support-widget__toggle" id="support-widget-toggle" aria-label="پشتیبانی" aria-expanded="false">
    <i class="bi bi-headset"></i>
  </button>
  <div class="support-widget__menu" id="support-widget-menu" hidden>
    <div class="support-widget__title"><i class="bi bi-headset"></i> پشتیبانی</div>
    <a href="{$supportHref}" class="support-widget__link"><i class="bi bi-ticket-perforated"></i> ثبت تیکت</a>
    <a href="{$reportHref}" class="support-widget__link"><i class="bi bi-bug"></i> گزارش خطا</a>
    <!-- <a href="{$fraudHref}" class="support-widget__link"><i class="bi bi-shield-exclamation"></i> راهنمای کلاهبرداری</a> -->
  </div>
</div>
HTML;
}

function render_categories_strip(?int $active = null): void {
    $cats = DB::fetchAll('SELECT * FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order');
    $url  = APP_URL;
    echo '<div class="category-strip">';
    $cls  = $active === null ? ' active' : '';
    echo "<a href='{$url}/' class='cat-pill{$cls}'><i class='bi bi-grid'></i> همه</a>";
    foreach ($cats as $c) {
        $cls = $active == $c['id'] ? ' active' : '';
        echo "<a href='{$url}/?cat={$c['slug']}' class='cat-pill{$cls}'><i class='{$c['icon']}'></i> " . category_label($c['slug'], $c['name']) . "</a>";
    }
    echo '</div>';
}
