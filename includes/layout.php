<?php
// includes/layout.php — shared header/footer helpers
require_once __DIR__ . '/config.php';

function render_head(string $title = '', string $desc = ''): void {
    $t   = $title ? h($title) . ' — ' . APP_NAME : APP_NAME . ' — بازار تعویض هوشمند';
    $d   = $desc  ? h($desc)  : 'کالا و خدمات را مستقیم در سواپین مبادله کنید — بازار تعویض هوشمند.';
    $url = APP_URL;
    echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$t}</title>
<meta name="description" content="{$d}">
<meta name="app-url" content="{$url}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="{$url}/src/css/main.css">
</head>
<body>
HTML;
}

function render_navbar(?array $user = null): void {
    $url        = APP_URL;
    $logoUrl    = APP_URL . '/src/img/swapin-light-png.png';
    $appName    = APP_NAME;
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
        ['/listings/create.php', 'ثبت کالا', 'bi-plus-circle', ''],
        ['/trades.php', 'پیشنهادها', 'bi-inbox', ''],
        ['/wallet.php', 'کیف پول', 'bi-wallet2', ''],
        ['/ai/chat.php', 'دستیار AI', 'bi-stars', 'navbar-nav__link--ai'],
        ['/about.php', 'راهنما', 'bi-question-circle', ''],
    ];

    echo <<<HTML
<nav class="navbar">
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
      <a href="{$url}/store/index.php" class="btn btn-outline hide-mobile">
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
          <span class="hide-mobile" style="font-size:.875rem;font-weight:600">{$credFmt} SWP</span>
          <i class="bi bi-chevron-down" style="font-size:.75rem"></i>
        </button>
        <div class="dropdown-menu" id="user-menu">
          <a href="{$url}/dashboard.php" class="dropdown-item"><i class="bi bi-speedometer2"></i> داشبورد</a>
HTML;
        if (is_store_seller($user)) {
            echo <<<HTML
          <a href="{$url}/store/index.php" class="dropdown-item"><i class="bi bi-shop"></i> پنل فروشگاه</a>
HTML;
        }
        echo <<<HTML
          <a href="{$url}/listings/my.php" class="dropdown-item"><i class="bi bi-grid"></i> آگهی‌های من</a>
          <a href="{$url}/trades.php" class="dropdown-item"><i class="bi bi-arrow-left-right"></i> معاملات من</a>
          <a href="{$url}/wallet.php" class="dropdown-item"><i class="bi bi-wallet2"></i> کیف پول</a>
          <a href="{$url}/subscription.php" class="dropdown-item"><i class="bi bi-gem"></i> اشتراک</a>
          <a href="{$url}/profile/edit.php" class="dropdown-item"><i class="bi bi-shield-check"></i> احراز هویت</a>
          <div class="dropdown-divider"></div>
          <a href="{$url}/profile.php" class="dropdown-item"><i class="bi bi-person"></i> پروفایل</a>
          <a href="{$url}/auth/logout.php" class="dropdown-item" style="color:var(--danger)"><i class="bi bi-box-arrow-right"></i> خروج</a>
        </div>
      </div>
HTML;
    } else {
        echo <<<HTML
      <button type="button" class="btn btn-ghost btn-icon {$notifClass}" id="notif-bell-btn" title="اعلان‌ها" aria-label="اعلان‌ها">
        <i class="bi bi-bell"></i>
      </button>
      <a href="{$url}/auth/login.php" class="btn btn-outline hide-mobile">ورود</a>
      <a href="{$url}/auth/register.php" class="btn btn-accent hide-mobile">ثبت‌نام</a>
      <a href="{$url}/auth/login.php" class="btn btn-accent hide-desktop btn-sm">ورود</a>
HTML;
    }
    echo <<<HTML
    </div>
  </div>
</nav>

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
        echo "<a href=\"{$url}/auth/login.php\" class=\"mobile-drawer__link\"><i class=\"bi bi-box-arrow-in-right\"></i> ورود / ثبت‌نام</a>";
    }
    echo <<<HTML
  </nav>
</aside>

<div id="toast-container"></div>
HTML;
    render_notifications_modal($user);
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
        <a href="{$url}/auth/login.php" class="btn btn-accent btn-sm">ورود / ثبت‌نام</a>
      </div>
HTML;
    } else {
        echo <<<HTML
      <div class="notif-loading" id="notif-loading">
        <span class="spinner"></span>
        <span>در حال بارگذاری…</span>
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
      <a href="{$url}/messages.php" class="btn btn-ghost btn-sm">همه پیام‌ها</a>
      <a href="{$url}/listings/offers.php" class="btn btn-primary btn-sm">پیشنهادهای دریافتی</a>
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
    $profile  = $user ? $url . '/profile.php' : $url . '/auth/login.php';
    $messages = $user ? $url . '/messages.php' : $url . '/auth/login.php';
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
  <a href="{$url}/listings/create.php" class="mobile-bottom-nav__item mobile-bottom-nav__item--fab">
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
    <div class="site-footer__stats">
      <div class="site-footer__stat">
        <i class="bi bi-emoji-smile site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <span class="site-footer__stat-value">۹۸٪</span>
          <span class="site-footer__stat-label">رضایت کاربران</span>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-arrow-left-right site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <span class="site-footer__stat-value">۴۵,۰۰۰+</span>
          <span class="site-footer__stat-label">مبادله موفق</span>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-box-seam site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <span class="site-footer__stat-value">۱۲۰,۰۰۰+</span>
          <span class="site-footer__stat-label">کالای ثبت‌شده</span>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-people site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <span class="site-footer__stat-value">۲۵۰,۰۰۰+</span>
          <span class="site-footer__stat-label">کاربر فعال</span>
        </div>
      </div>
    </div>

    <div class="site-footer__main">
      <div class="site-footer__col">
        <h3 class="site-footer__heading">تماس با ما</h3>
        <ul class="site-footer__contact-list">
          <li>
            <i class="bi bi-telephone" aria-hidden="true"></i>
            <a href="tel:+982191012345">۰۲۱-۹۱۰۱۲۳۴۵</a>
          </li>
          <li>
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <a href="mailto:info@swapin.ir">info@swapin.ir</a>
          </li>
          <li>
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <span>تهران، خیابان آزادی</span>
          </li>
        </ul>
      </div>

      <div class="site-footer__col">
        <h3 class="site-footer__heading">نمادهای اعتماد</h3>
        <div class="site-footer__trust">
          <img src="{$enamadUrl}" alt="نماد اعتماد الکترونیکی (اینماد)" class="site-footer__trust-badge" width="72" height="88">
        </div>
      </div>

      <div class="site-footer__col">
        <h3 class="site-footer__heading">لینک‌های مفید</h3>
        <ul class="site-footer__links">
          <li><a href="{$url}/about.php">درباره ما</a></li>
          <li><a href="{$url}/contact.php">تماس با ما</a></li>
          <li><a href="{$url}/about.php">قوانین و مقررات</a></li>
          <li><a href="{$url}/about.php">حریم خصوصی</a></li>
          <li><a href="{$url}/about.php">سوالات متداول</a></li>
        </ul>
      </div>

      <div class="site-footer__col site-footer__col--brand">
        <a href="{$url}/" class="site-footer__brand">
          <img src="{$logoUrl}" alt="{$appName}" class="site-footer__logo">
        </a>
        <p class="site-footer__tagline">سواپین: بزرگترین پلتفرم مبادله کالا به کالا در ایران. کمتر بخر، بیشتر مبادله کن.</p>
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
<script src="{$url}/src/js/app.js"></script>
</body>
</html>
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
