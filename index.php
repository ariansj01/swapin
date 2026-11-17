<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/content_manager.php';
require_once __DIR__ . '/includes/stories.php';
require_once __DIR__ . '/includes/banners.php';

// #region debug-point homepage-500-index-start
if (function_exists('swapin_debug_log')) {
    swapin_debug_log('index-start', [
        'uri' => $_SERVER['REQUEST_URI'] ?? '/',
        'query' => $_GET,
    ]);
}
// #endregion

$user = auth_user();

// ─── Filters ─────────────────────────────────────────────────────────────────
$search    = clean($_GET['q']         ?? '');
$catSlug   = clean($_GET['cat']       ?? '');
$city      = clean($_GET['city']      ?? '') ?: 'تهران';
$wantType = clean($_GET['want'] ?? '');
$wantCatId = (int)($_GET['want_category_id'] ?? 0);
$dealType = clean($_GET['deal_type'] ?? '');
$sort      = in_array($_GET['sort'] ?? '', ['new','old','value']) ? $_GET['sort'] : 'new';
$page      = max(1, (int)($_GET['page'] ?? 1));
$timeAgo   = in_array($_GET['time_ago'] ?? '', ['3h','12h','1d','3d','1w']) ? $_GET['time_ago'] : '';
$pmin      = isset($_GET['price_min']) ? (float)$_GET['price_min'] : 0;
$pmax      = isset($_GET['price_max']) ? (float)$_GET['price_max'] : 0;

// ─── نقشهٔ ۳۰۱ (بخش ۱۱): پارامترهای ?city= و ?cat= → آدرس تمیز /{location}[/{category}] ───
// آدرس‌های پارامتری «خالی» صفحهٔ اصلی (که canonical آن‌ها را حذف می‌کرد و هرگز ایندکس نمی‌شدند)
// در یک مرحله به مسیر کانونیکال ۳۰۱ می‌شوند. sort و time_ago طبق نقشه به مقصد منتقل نمی‌شوند.
// توجه: اگر یکی از پارامترهای واقعی فیلتر (مثل sort/want/price) حاضر باشد، صفحهٔ اصلی
// همان فیلتر زندهٔ خودش است و ریدایرکت نمی‌شود تا فرم فیلتر هوم خراب نشود. (فرم همیشه sort دارد.)
$homeFilterParams = ['want', 'want_category_id', 'condition', 'deal_type', 'price_min', 'price_max', 'sort', 'time_ago', 'page'];
$homeHasFilter = false;
foreach ($homeFilterParams as $hk) {
    $hv = $_GET[$hk] ?? '';
    if ($hv !== '' && $hv !== null && $hv !== '0' && $hv !== 0) {
        $homeHasFilter = true;
        break;
    }
}
if (!headers_sent() && !$homeHasFilter
    && ((string)($_GET['city'] ?? '') !== '' || (string)($_GET['cat'] ?? '') !== '')) {
    $homeCityName = trim((string)($_GET['city'] ?? ''));
    $homeLocRow   = null;
    if ($homeCityName !== '' && function_exists('swaapin_location_by_name')) {
        $homeLocRow = swaapin_location_by_name($homeCityName, 'city')
            ?? swaapin_location_by_name($homeCityName, 'province');
    }

    // نگاشت پارامتر cat طبق نقشه: اسلاگ جاری، آخرین سگمنت مسیر، سپس alias قدیمی
    $homeCatSlug = swaapin_resolve_category_slug((string)($_GET['cat'] ?? ''));

    if ($homeLocRow || $homeCatSlug !== null) {
        $homeBase = $homeLocRow
            ? rtrim(swaapin_location_url($homeLocRow), '/')
            : rtrim(APP_URL, '/') . '/iran';
        $homeTarget = $homeBase . ($homeCatSlug !== null ? '/' . rawurlencode($homeCatSlug) : '');

        $homeKeep = [];
        foreach (['q', 'want', 'want_category_id', 'condition', 'price_min', 'price_max', 'page'] as $hk) {
            $hv = $_GET[$hk] ?? '';
            if ($hv !== '' && $hv !== null && $hv !== '0' && $hv !== 0) {
                $homeKeep[$hk] = $hv;
            }
        }
        header('Location: ' . $homeTarget . ($homeKeep ? '?' . http_build_query($homeKeep) : ''), true, 301);
        exit;
    }
}

// resolve category
$category = $catSlug ? DB::fetch('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [$catSlug]) : null;
$catId    = $category['id'] ?? null;

// ─── Count ───────────────────────────────────────────────────────────────────
$homeExcludeStores = db_has_column('users', 'seller_type')
    ? '(u.seller_type != "store" AND (u.store_name IS NULL OR u.store_name = ""))'
    : '(u.store_name IS NULL OR u.store_name = "")';

$whereClauses = [listing_public_sql('l'), 'l.listing_mode != "sell"', $homeExcludeStores];
$params       = [];

if ($search) {
    $whereClauses[] = '(l.title LIKE ? OR l.description LIKE ? OR l.want_in_return LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($catId) {
    $whereClauses[] = '(l.category_id = ? OR c.parent_id = ?)';
    $params[] = $catId;
    $params[] = $catId;
}
if ($city) {
    $isProvince = in_array($city, iran_provinces(), true);
    // اگر ورودی هم‌نام یک شهر باشد (مثل «تهران») اولویت به شهر داده می‌شود، نه کل استان
    $isCityName = $isProvince ? (bool) swaapin_location_by_name($city, 'city') : false;
    if ($isProvince && !$isCityName) {
        $provinceCities = iran_cities_by_province($city);
        if (!empty($provinceCities)) {
            $placeholders = implode(',', array_fill(0, count($provinceCities), '?'));
            $whereClauses[] = "l.city IN ($placeholders)";
            foreach ($provinceCities as $pc) {
                $params[] = $pc;
            }
        } else {
            $whereClauses[] = 'l.city = ?';
            $params[] = $city;
        }
    } else {
        $whereClauses[] = 'l.city = ?';
        $params[] = $city;
    }
}
if ($wantType) {
    $whereClauses[] = 'l.want_type = ?';
    $params[] = $wantType;
}
if ($wantCatId > 0) {
    $whereClauses[] = 'l.want_category_id = ?';
    $params[] = $wantCatId;
}
if ($dealType) {
    $whereClauses[] = 'l.deal_type = ?';
    $params[] = $dealType;
}
if ($timeAgo) {
    $intervalMap = [
        '3h'  => '3 HOUR',
        '12h' => '12 HOUR',
        '1d'  => '1 DAY',
        '3d'  => '3 DAY',
        '1w'  => '7 DAY',
    ];
    $interval = $intervalMap[$timeAgo] ?? '3 HOUR';
    $whereClauses[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL {$interval})";
}
if ($pmin > 0) {
    $whereClauses[] = 'l.estimated_value >= ?';
    $params[] = $pmin;
}
if ($pmax > 0) {
    $whereClauses[] = 'l.estimated_value <= ?';
    $params[] = $pmax;
}

$where   = 'WHERE ' . implode(' AND ', $whereClauses);
$orderBy = match($sort) {
    'old'   => 'l.created_at ASC, l.id ASC',
    'value' => 'l.estimated_value DESC, l.id DESC',
    default => 'l.created_at DESC, l.id DESC',
};

// #region debug-point homepage-500-before-queries
if (function_exists('swapin_debug_log')) {
    swapin_debug_log('index-before-queries', [
        'search' => $search,
        'cat' => $catSlug,
        'city' => $city,
        'want' => $wantType,
        'sort' => $sort,
        'page' => $page,
    ]);
}
// #endregion

$totalRow = DB::fetch(
    "SELECT COUNT(*) AS c FROM listings l JOIN users u ON u.id = l.user_id JOIN categories c ON c.id = l.category_id $where",
    $params
);
$total = (int)($totalRow['c'] ?? 0);
$displayLimit = 20;
$pag   = paginate($total, $displayLimit, $page);

$listings = DB::fetchAll(
    "SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
            c.name AS cat_name, c.slug AS cat_slug,
            wc.name AS want_cat_name,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     LEFT JOIN categories wc ON wc.id = l.want_category_id
     {$where}
     ORDER BY {$orderBy}
     LIMIT ? OFFSET ?",
    [...$params, $displayLimit, $pag['offset']]
);
listing_card_prefetch($listings);

// ─── Premium / promoted listings (before filters on home page 1) ─────────────
$premiumListings = [];
if (!$search && !$catSlug && $page === 1) {
    $premiumWhere = [
        listing_public_sql('l'),
        'l.listing_mode != "sell"',
        $homeExcludeStores,
        '(l.featured_until > NOW() OR l.bump_until > NOW() OR l.vip_until > NOW())',
    ];
    $premiumOrderBy = '(l.vip_until > NOW()) DESC, (l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC, l.id DESC';
    $premiumListings = DB::fetchAll(
        "SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
                c.name AS cat_name, c.slug AS cat_slug,
                wc.name AS want_cat_name,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         LEFT JOIN categories wc ON wc.id = l.want_category_id
         WHERE " . implode(' AND ', $premiumWhere) . "
         ORDER BY {$premiumOrderBy}
         LIMIT 20"
    );
    listing_card_prefetch($premiumListings);
}

$cities = iran_cities();

// ─── Featured Stores (homepage, page 1, no text/category filters) ───────────────
$featuredStores = [];
$hStoresTypeCol = false;
$hStoresCityCol = false;
if (!$search && !$catSlug && $page === 1) {
    $hSellerType = db_has_column('users', 'seller_type');
    $hStoresCity  = db_has_column('users', 'store_city');
    $hStoresType  = db_has_column('users', 'store_type');
    $hCols = "id, name, store_name, store_slug, store_description, store_banner, avatar, rating, created_at";
    if ($hStoresType) $hCols .= ", store_type";
    if ($hStoresCity) $hCols .= ", store_city, city";
    else $hCols .= ", city";

    // Looser fallback query: always include stores with non-empty store_name/store_slug,
    // plus OR active vendor users even if their seller_type isn't set.
    $hWhereParts = ['is_active = 1'];
    $storePredicate = [];
    if ($hSellerType) {
        $storePredicate[] = 'seller_type = "store"';
    }
    $storePredicate[] = '(store_name IS NOT NULL AND store_name != "")';
    $storePredicate[] = '(store_slug IS NOT NULL AND store_slug != "")';
    $hWhereParts[] = '(' . implode(' OR ', $storePredicate) . ')';

    $hWhere = 'WHERE ' . implode(' AND ', $hWhereParts);
    $featuredStores = DB::fetchAll(
        "SELECT {$hCols},
                (SELECT COUNT(*) FROM listings WHERE user_id = users.id AND status = 'active' AND review_status = 'approved') AS listings_count
         FROM users
         {$hWhere}
         ORDER BY created_at DESC, id DESC
         LIMIT 10"
    );
    $hStoresTypeCol = $hStoresType;
    $hStoresCityCol = $hStoresCity;

    // Fallback: if no dedicated stores, pick top users with listings to never leave slider empty.
    if (empty($featuredStores)) {
        $fallbackCols = "id, name, store_name, store_slug, store_description, store_banner, avatar, rating, created_at";
        if ($hStoresType) $fallbackCols .= ", store_type";
        if ($hStoresCity) $fallbackCols .= ", store_city, city";
        else $fallbackCols .= ", city";
        $featuredStores = DB::fetchAll(
            "SELECT {$fallbackCols},
                    (SELECT COUNT(*) FROM listings WHERE user_id = users.id AND status = 'active' AND review_status = 'approved') AS listings_count
             FROM users
             WHERE is_active = 1
             ORDER BY created_at DESC, id DESC
             LIMIT 6"
        );
        // Generate safe slug & name for users that don't have store fields filled.
        foreach ($featuredStores as &$s) {
            if (empty($s['store_name'])) {
                $s['store_name'] = trim($s['name'] ?? 'فروشگاه');
            }
            if (empty($s['store_slug'])) {
                $s['store_slug'] = 'store-' . (int)$s['id'];
            }
            if (empty($s['store_banner'])) {
                $s['store_banner'] = null;
            }
            if ($hStoresType && empty($s['store_type'])) {
                $s['store_type'] = 'both';
            }
        }
        unset($s);
    }
}

$homeMetaTitle = swapin_content_get('home_meta_title');
$homeMetaDesc  = swapin_content_get('home_meta_desc');

render_head($homeMetaTitle, $homeMetaDesc, [
    'canonical' => APP_URL . '/',
    'og_type'   => 'website',
    'og_image'  => APP_URL . '/src/img/heropng.png',
    'keywords'  => 'مبادله کالا, تعویض کالا, بازار مبادله, سواَپین, معاوضه',
    'json_ld'   => [seo_json_ld_website(), seo_json_ld_site_navigation(), seo_json_ld_organization()],
]);
render_navbar($user);
?>

<?php if ($user && isset($_GET['welcome'])): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container d-flex align-center gap-3" style="flex-wrap:wrap">
    <i class="bi bi-stars"></i>
    <span><strong>به <?= APP_NAME ?> خوش آمدید!</strong> برای ثبت آگهی یا معامله، پروفایل خود را تکمیل کنید.</span>
    <?php if (!user_profile_is_complete($user)): ?>
    <a href="<?= APP_URL ?>/profile/edit" class="btn btn-accent btn-sm ms-auto">تکمیل پروفایل</a>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($user && !user_profile_is_complete($user)): ?>
<div class="alert alert-info" style="border-radius:0;border-left:0;border-right:0">
  <div class="container d-flex align-center gap-3" style="flex-wrap:wrap">
    <i class="bi bi-person-circle"></i>
    <span>برای ثبت آگهی یا معامله، <strong>نام و شهر</strong> خود را در پروفایل وارد کنید.</span>
    <a href="<?= APP_URL ?>/profile/edit" class="btn btn-accent btn-sm ms-auto">تکمیل پروفایل</a>
  </div>
</div>
<?php endif; ?>

<?php if (!$search && !$catSlug && $page === 1): ?>
<section class="hero hero--compact">
  <div class="container hero__inner" style="background: var(--gradient-brand);border-radius: 10px;margin-top: 13px;padding: 15px;">
    <div class="hero__visual">
      <picture>
        <source srcset="<?= APP_URL ?>/src/img/heropng.webp" type="image/webp">
        <img src="<?= APP_URL ?>/src/img/heropng.png" alt="مبادله هوشمند کالا در <?= APP_NAME ?>" class="hero__img" width="900" height="600" loading="eager" decoding="async">
      </picture>
    </div>
     <div class="hero__content">
      <h1 class="hero__title">
        <span class="hero__line">سواَپین، پلتفرم هوشمند</span>
        <span class="hero__line"><?= h(swapin_content_get('hero_title_line_2')) ?></span>
      </h1>
      <p class="hero__subtitle"><?= h(swapin_content_get('hero_subtitle_before')) ?> <span class="hero__gold"><?= h(swapin_content_get('hero_subtitle_highlight')) ?></span> کن</p>
      <div class="hero__actions">
        <a href="<?= app_url('listings/create') ?>" class="btn btn-accent btn-lg">
          <i class="bi bi-plus-circle"></i> <?= h(swapin_content_get('hero_primary_cta')) ?>
        </a>
        <a href="#listings" class="btn btn-hero-outline btn-lg">
          <i class="bi bi-search"></i> <?= h(swapin_content_get('hero_secondary_cta')) ?>
        </a>
      </div>
    </div>
  </div>
</section>

<!-- Mobile Categories -->
<section class="mobile-categories-wrapper">
  <div class="mobile-categories-list">
    <?php
    $topCatSlugs = wizard_allowed_category_slugs();
    $topCatPlaceholders = implode(',', array_fill(0, count($topCatSlugs), '?'));
    $allCats = DB::fetchAll(
        "SELECT * FROM categories WHERE is_active = 1 AND slug IN ({$topCatPlaceholders}) ORDER BY sort_order ASC, id ASC LIMIT 10",
        $topCatSlugs
    );
    foreach ($allCats as $cat):
    ?>
    <a href="<?= category_url($cat['slug']) ?>" class="mobile-category-item">
      <div class="mobile-category-circle">
        <i class="<?= h($cat['icon'] ?: 'bi bi-grid') ?>"></i>
      </div>
      <span class="mobile-category-title"><?= h($cat['name']) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<?php stories_render_section(); ?>
<?php banners_render_slider(); ?>
<?php endif; ?>

<main id="main-content" class="section-sm">

  <div class="container">

      <!-- 4 Steps Cards — چطور معامله کنیم (عرض کامل) -->
    <section class="home-steps home-steps--compact mb-8" id="home-steps" aria-label="چطور معامله کنیم">
      <div class="steps-grid steps-grid--compact">
        <?php
        $steps = [
          ['۱', 'ثبت آگهی', 'عکس بگیرید و ثبت کنید.', 'bi-camera'],
          ['۲', 'دریافت پیشنهاد', 'پیشنهادهای معامله بگیرید.', 'bi-send'],
          ['۳', 'توافق با طرف مقابل', 'درباره شرایط توافق کنید.', 'bi-heart'],
          ['۴', 'انجام معامله', 'در مکان امن معامله کنید.', 'bi-shield-check'],
        ];
        foreach ($steps as $index => [$stepNo, $title, $desc, $icon]):
        ?>
        <article class="step-card step-card--compact" style="--step-delay: <?= $index ?>;">
          <span class="step-card__number step-card__number--compact"><?= $stepNo ?></span>
          <div class="step-card__content step-card__content--compact">
            <div class="step-card__icon-wrap">
              <div class="step-card__icon step-card__icon--compact">
                <i class="bi <?= $icon ?>"></i>
              </div>
            </div>
            <div class="step-card__text step-card__text--compact">
              <h3><?= $title ?></h3>
              <p><?= $desc ?></p>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if ($category): ?>
    <header class="home-results-header d-flex align-center gap-3 mb-5">
      <h2 class="home-results-header__title"><?= h(category_label($category['slug'], $category['name'])) ?></h2>
      <span class="badge badge-primary"><?= $total ?> آگهی</span>
      <a href="<?= APP_URL ?>/" class="home-results-header__clear"><i class="bi bi-x"></i> پاک کردن</a>
    </header>
    <?php elseif ($search): ?>
    <header class="home-results-header d-flex align-center gap-3 mb-5">
      <h2 class="home-results-header__title">نتایج برای «<?= h($search) ?>»</h2>
      <span class="badge badge-primary"><?= $total ?> مورد یافت شد</span>
    </header>
    <?php endif; ?>

    <!-- ===== Listings & Stores ===== -->
    <div class="home-main-layout" id="home-sliders-area">

      <!-- ===== Sidebar (Beside Sliders) ===== -->
      <?php include __DIR__ . '/includes/sidebar_home.php'; ?>

      <!-- Sidebar Mobile Collapsible Styles -->
      <style>
        @media (max-width: 1024px) {
          .home-sidebar--mobile-collapsible {
            position: relative;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
          }
          .home-sidebar__mobile-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 10px;
            border-radius: 14px;
            border: 1.5px solid #e5e7eb;
            background: linear-gradient(135deg, #ffffff, #f8fafc);
            cursor: pointer;
            transition: all .2s ease;
            box-shadow: 0 6px 20px -14px rgba(7,26,51,.25);
            font-weight: 700;
          }
          .home-sidebar__mobile-toggle:hover {
            border-color: #3b82f6;
            background: #ffffff;
          }
          .home-sidebar__mobile-toggle:active {
            transform: scale(.99);
          }
          .home-sidebar__mobile-toggle-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #ffffff;
            font-size: .95rem;
            flex-shrink: 0;
          }
          .home-sidebar__mobile-toggle-label {
            flex: 1;
            text-align: right;
            font-size: .95rem;
            color: #071A33;
          }
          .home-sidebar__mobile-toggle-caret {
            display: inline-flex;
            align-items: center;
            color: #64748b;
            font-size: .9rem;
            transition: transform .25s ease;
            flex-shrink: 0;
          }
          .home-sidebar--mobile-collapsible.is-open
          .home-sidebar__mobile-toggle-caret {
            transform: rotate(180deg);
            color: #1d4ed8;
          }
          .home-sidebar--mobile-collapsible.is-open
          .home-sidebar__mobile-toggle {
            border-color: rgba(59,130,246,.5);
            box-shadow: 0 0 0 3px rgba(59,130,246,.08);
          }
          .home-sidebar__inner--collapsible {
            display: none;
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity .3s ease, transform .3s ease;
          }
          .home-sidebar--mobile-collapsible.is-open
          .home-sidebar__inner--collapsible {
            display: block;
            opacity: 1;
            transform: translateY(0);
            animation: homeSidebarFadeIn .35s ease both;
          }
          @keyframes homeSidebarFadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
          }
        }
        @media (min-width: 1025px) {
          .home-sidebar__mobile-toggle { display: none !important; }
        }
      </style>

      <script>
      (function () {
        var sidebar = document.getElementById('home-mobile-sidebar');
        var toggle  = document.getElementById('home-sidebar-mobile-toggle');
        if (!sidebar || !toggle) return;
        var MOBILE_MQ = window.matchMedia ? window.matchMedia('(max-width: 1024px)') : null;
        function sync() {
          if (MOBILE_MQ && MOBILE_MQ.matches) {
            // در موبایل، پیش‌فرض بسته مگر که کاربر باز کرده
            if (!sidebar.dataset.userToggled) {
              sidebar.classList.remove('is-open');
              toggle.setAttribute('aria-expanded', 'false');
            }
          } else {
            // در دسکتاپ، همیشه باز (CSS خودش display: normal می‌دهد)
            sidebar.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
          }
        }
        toggle.addEventListener('click', function () {
          var isOpen = sidebar.classList.toggle('is-open');
          toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          sidebar.dataset.userToggled = '1';
          if (isOpen) {
            try {
              var inner = sidebar.querySelector('.home-sidebar__inner--collapsible');
              if (inner) {
                inner.scrollIntoView({ behavior: 'smooth', block: 'start' });
              }
            } catch (e) {}
          }
        });
        sync();
        if (MOBILE_MQ && MOBILE_MQ.addEventListener) {
          MOBILE_MQ.addEventListener('change', sync);
        } else if (MOBILE_MQ && MOBILE_MQ.addListener) {
          MOBILE_MQ.addListener(sync);
        }
      })();
      </script>

      <!-- ===== Main Content (Only Sliders: Listings + Stores) ===== -->
      <div class="home-main-content">

        <!-- Featured Listings Section (Active promotion plans) -->
        <?php if (!empty($premiumListings)): ?>
        <section class="home-listings-section home-listings-section--newest" aria-label="آگهی‌های ویژه">
          <div class="home-section-heading newest-home-section-heading home-section-heading--large mb-5">
            <h2>آگهی‌های ویژه</h2>
            <a href="<?= APP_URL ?>/iran" class="home-section-heading__link">
              مشاهده همه
            </a>
          </div>
          <div class="listings-rows-container">
            <div class="listings-row-wrapper">
              <button type="button" class="listings-slider-arrow listings-slider-arrow--next" data-target="featured-listings-row" aria-label="آگهی بعدی">
                <i class="bi bi-chevron-right"></i>
              </button>
              <div class="listings-scroll-row" id="featured-listings-row">
                <?php foreach (array_slice($premiumListings, 0, 40) as $l): ?>
                <div class="listings-scroll-card">
                  <?php 
                    $cardLayout = 'premium-horizontal';
                    include __DIR__ . '/includes/listing_card.php'; 
                    unset($cardLayout);
                  ?>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="listings-slider-arrow listings-slider-arrow--prev" data-target="featured-listings-row" aria-label="آگهی قبلی">
                <i class="bi bi-chevron-left"></i>
              </button>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <!-- New Listings Section -->
        <section id="listings" class="home-listings-section home-listings-section--newest" aria-label="فهرست آگهی‌ها">
          <div class="home-section-heading newest-home-section-heading home-section-heading--large mb-5">
            <h2>جدیدترین آگهی‌ها</h2>
            <a href="<?= APP_URL ?>/iran" class="home-section-heading__link">
              مشاهده همه
            </a>
          </div>
          <?php if (empty($listings)): ?>
          <div class="empty-state">
            <i class="bi bi-search"></i>
            <h3>آگهی‌ای یافت نشد</h3>
            <p>فیلترها را تغییر دهید یا اولین نفری باشید که در این دسته آگهی ثبت می‌کند!</p>
            <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary">ثبت آگهی</a>
          </div>
          <?php else: ?>
          <div class="listings-rows-container">
            <div class="listings-row-wrapper">
              <button type="button" class="listings-slider-arrow listings-slider-arrow--next" data-target="newest-listings-row" aria-label="آگهی بعدی">
                <i class="bi bi-chevron-right"></i>
              </button>
              <div class="listings-scroll-row" id="newest-listings-row">
                <?php foreach (array_slice($listings, 0, 40) as $l): ?>
                <div class="listings-scroll-card">
                  <?php 
                    $cardLayout = 'premium-horizontal';
                    include __DIR__ . '/includes/listing_card.php'; 
                    unset($cardLayout);
                  ?>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="listings-slider-arrow listings-slider-arrow--prev" data-target="newest-listings-row" aria-label="آگهی قبلی">
                <i class="bi bi-chevron-left"></i>
              </button>
            </div>
          </div>
          <?php endif; ?>
        </section>

        <!-- Stores Section -->
        <?php if (!empty($featuredStores)): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/src/css/shops.css?v=<?= @filemtime(__DIR__ . '/src/css/shops.css') ?: time() ?>">
        <style>
          #home-stores-row {
            gap: 1.25rem;
            padding: var(--sp-2) 0;
          }
          .home-stores-card {
            width: 100%;
            height: 100%;
          }
          .home-stores-card .shop-card {
            height: 100%;
          }
        </style>
        <section class="home-listings-section home-listings-section--yellow" aria-label="فروشگاه‌ها" style="margin-top:48px">
          <div class="home-section-heading home-section-heading--large mb-5" style="width: 90%;margin-right: 5%;">
            <h2>فروشگاه‌ها</h2>
            <a href="<?= APP_URL ?>/shops" class="home-section-heading__link">
              <i class="bi bi-chevron-left" style="color:var(--text-muted)"></i>
            </a>
          </div>
          <div class="listings-rows-container">
            <div class="listings-row-wrapper">
              <button type="button" class="listings-slider-arrow listings-slider-arrow--next" data-target="home-stores-row" aria-label="فروشگاه بعدی">
                <i class="bi bi-chevron-right"></i>
              </button>
              <div class="listings-scroll-row listings-scroll-row-shop" id="home-stores-row">
                <?php foreach ($featuredStores as $store):
                  $name = $store['store_name'] ?: $store['name'];
                  $slug = $store['store_slug'];
                  $storeCity = $hStoresCityCol ? ($store['store_city'] ?: $store['city']) : ($store['city'] ?? '');
                  $bannerUrl = !empty($store['store_banner']) ? UPLOAD_URL . $store['store_banner'] : APP_URL . '/src/img/heropng.png';
                  $storeTypeValue = $hStoresTypeCol ? normalize_store_type($store['store_type'] ?? 'both') : 'both';
                  $shopUrl = APP_URL . '/shop/' . h($slug);
                  $storeTypeLabels = store_type_labels();
                  $storeTypeLabel = $storeTypeLabels[$storeTypeValue] ?? '';
                  $storeTypeBadgeClass = match($storeTypeValue) {
                      'online'   => 'badge badge-info',
                      'physical' => 'badge badge-warning',
                      default    => 'badge badge-secondary',
                  };
                  $storeTypeIcon = match($storeTypeValue) {
                      'online'   => 'bi-globe2',
                      'physical' => 'bi-building-check',
                      default    => 'bi-shop',
                  };
                ?>
                <div class="listings-scroll-card">
                  <div class="home-stores-card">
                    <article class="shop-card card">
                      <a href="<?= $shopUrl ?>" class="shop-card__banner-wrap">
                        <img src="<?= h($bannerUrl) ?>" alt="<?= h($name) ?>" class="shop-card__banner" loading="lazy">
                      </a>
                      <div class="shop-card__body">
                        <div class="shop-card__profile">
                          <div>
                            <h2 class="shop-card__name"><a href="<?= $shopUrl ?>"><?= h($name) ?></a></h2>
                            <div class="shop-card__tags" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                              <?php if ($storeTypeLabel !== ''): ?>
                              <span class="<?= $storeTypeBadgeClass ?>"><i class="bi <?= $storeTypeIcon ?>"></i> <?= h($storeTypeLabel) ?></span>
                              <?php endif; ?>
                              <?php if ($storeCity): ?>
                              <span class="shop-card__city" style="display:inline-flex;align-items:center;gap:4px"><i class="bi bi-geo-alt"></i> <?= h($storeCity) ?></span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                        <p class="shop-card__desc"><?php
                          $desc = trim((string)($store['store_description'] ?? ''));
                          if ($desc !== '') {
                              echo h(mb_strimwidth($desc, 0, 100, '…'));
                          } else {
                              echo '...';
                          }
                          ?></p>
                        <div class="shop-card__meta">
                          <span><i class="bi bi-box-seam"></i> <?= fmt_num((int)$store['listings_count']) ?> محصول</span>
                          <?php if ((float)($store['rating'] ?? 0) > 0): ?>
                          <span><i class="bi bi-star-fill"></i> <?= number_format((float)$store['rating'], 1) ?></span>
                          <?php endif; ?>
                        </div>
                        <a href="<?= $shopUrl ?>" class="btn btn-primary btn-sm w-100">مشاهده فروشگاه</a>
                      </div>
                    </article>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="listings-slider-arrow listings-slider-arrow--prev" data-target="home-stores-row" aria-label="فروشگاه قبلی">
                <i class="bi bi-chevron-left"></i>
              </button>
            </div>
          </div>
        </section>
        <?php endif; ?>

      </div><!-- /home-main-content -->
    </div><!-- /home-main-layout -->

  </div>
</main>

<script>
(function () {
  // Auto-submit sidebar filter form when select changes
  var form = document.getElementById('sidebar-filters-form');
  if (!form) return;

  var selects = form.querySelectorAll('select');
  selects.forEach(function (sel) {
    sel.addEventListener('change', function () { form.submit(); });
  });

  // Auto-submit when user presses Enter in search or price inputs
  var inputs = form.querySelectorAll('input[type="search"], input[name="price_min"], input[name="price_max"]');
  inputs.forEach(function (inp) {
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); form.submit(); }
    });
  });
})();
</script>

<?php if ($page === 1): ?>
<section class="home-section home-ai home-ai--triple" aria-label="ارزش‌گذاری و مشاوره معاوضه با AI">
  <div class="container">
    <div class="home-ai__inner home-ai__inner--triple" style="margin-bottom: 0px;">

      <!-- ستون سمت چپ: فرم تخمین قیمت -->
      <div class="home-ai__form-col" id="home-price-estimate">
        <div class="home-ai-form card" style="background: rgba(7,26,51,.03);">
          <div class="home-ai-form__header">
            <span class="home-ai-form__badge"><i class="bi bi-lightning-charge-fill"></i> دستیار هوشمند سواَپین </span>
            <h3 class="home-ai-form__title">کالات چقدر می‌ارزه؟</h3>
          </div>

          <form id="home-price-estimate-form" class="home-ai-form__form" method="POST" novalidate>
            <?= csrf_input() ?>

            <!-- اسم کالا -->
            <div class="home-ai-form__field">
              <label class="home-ai-form__label" for="estimate_title" style="margin-top: -20px;">اسم کالا</label>
              <input type="text" id="estimate_title" name="title" class="form-control"
                     placeholder="مثال: آیفون ۱۵ پرو مکس ۵۱۲ گیگابایت"
                     required minlength="3" maxlength="150">
            </div>

            <!-- عکس کالا (اختیاری) -->
            <!-- <div class="home-ai-form__field">
              <label class="home-ai-form__label" for="estimate_image">عکس کالا <span style="font-weight:500;opacity:.7;display:inline-block;margin-right:4px">(اختیاری)</span></label>
              <label for="estimate_image" class="home-ai-form__upload">
                <input type="file" id="estimate_image" name="image" accept="image/*" style="display:none">
                <i class="bi bi-cloud-arrow-up-fill"></i>
                <span>عکس کالا رو آپلود کن</span>
              </label>
            </div> -->

            <!-- توضیحات کالا -->
            <div class="home-ai-form__field">
              <label class="home-ai-form__label" for="estimate_description">توضیحات کالا</label>
              <textarea id="estimate_description" name="description" class="form-control" rows="3"
                        placeholder="مثال: گوشی دست اول، شارژر و جعبه کامل"
                        maxlength="500"></textarea>
            </div>

            <!-- نتیجه (مخفی تا زمانی که نتیجه برسد) -->
            <div id="estimate-result" class="home-ai-form__result" style="display:none">
              <div class="home-ai-form__result-header">
                <i class="bi bi-graph-up-arrow"></i>
                <span>نتیجه تخمین قیمت:</span>
              </div>
              <div id="estimate-result-body" class="home-ai-form__result-body">
                <div class="home-ai-form__price-minmax">
                  <div class="home-ai-form__price-tag home-ai-form__price-tag--low">
                    <span>کمترین ارزش</span>
                    <strong id="est-min">—</strong>
                  </div>
                  <div class="home-ai-form__price-arrow"><i class="bi bi-arrow-left-right"></i></div>
                  <div class="home-ai-form__price-tag home-ai-form__price-tag--high">
                    <span>بیشترین ارزش</span>
                    <strong id="est-max">—</strong>
                  </div>
                </div>
                <div class="home-ai-form__price-mid">
                  <span>ارزش پیشنهادی بازار:</span>
                  <strong id="est-mid">—</strong>
                </div>
                <p id="est-note" class="home-ai-form__note"></p>
              </div>
            </div>

            <!-- خطا -->
            <div id="estimate-error" class="home-ai-form__error" style="display:none"></div>

            <!-- دکمه ارسال -->
            <button type="submit" id="estimate-submit-btn" class="btn home-ai-form__submit w-100">
              <i class="bi bi-cpu"></i> قیمت کالا رو ببین
            </button>

            <div class="home-ai-form__tags">
              <span><i class="bi bi-lightning-charge"></i> سریع</span>
              <span><i class="bi bi-shield-check"></i> دقیق</span>
              <span><i class="bi bi-emoji-smile"></i> بدون ثبت‌نام</span>
            </div>
          </form>
        </div>
      </div>

      <!-- ستون وسط: تصویر ربات AI -->
      <div class="home-ai__visual home-ai__visual--center" aria-hidden="false">
        <div class="home-ai__phone-wrap home-ai__phone-wrap--center">
          <div class="home-ai__phone-blob" aria-hidden="true"></div>
          <img src="<?= APP_URL ?>/91880dba-8120-4b5d-a963-7010f5abad9a.png" alt="ربات هوش مصنوعی سواَپین  برای تخمین قیمت کالا" class="home-ai__phone home-ai__phone--center" loading="lazy">
        </div>
      </div>

      <!-- ستون راست: توضیحات و CTA (حالت اصلی - بدون ویژگی‌ها) -->
      <div class="home-ai__content home-ai__content--right">
        <span class="home-ai__badge">
          <i class="bi bi-stars"></i> هوش مصنوعی
        </span>
        <h2 class="home-ai__title">ارزش‌گذاری و مشاوره معاوضه با <span class="home-ai__title-accent">AI</span></h2>
        <p class="home-ai__desc">سواَپین AI کالا، شرایط فیزیکی و بازار معاوضه را تحلیل می‌کند، تخمین قیمت دقیق می‌دهد و بهترین پیشنهادهای معاوضه را متناسب با بودجه و سلایق شما پیدا می‌کند.</p>
        <div class="home-ai__actions">
          <a href="<?= APP_URL ?>/listings/create" class="btn btn-accent btn-lg">
            <i class="bi bi-plus-circle"></i> ثبت کالا + دریافت قیمت AI
          </a>
          <a href="<?= APP_URL ?>/ai/chat" class="btn btn-hero-outline btn-lg">
            <i class="bi bi-robot"></i> دستیار هوشمند
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
/* ---------- Home AI: Triple Column Layout ---------- */
.home-ai--triple { padding: var(--sp-8) 0 var(--sp-7); background: #071A33; }
.home-ai__inner--triple {
  display: grid;
  grid-template-columns: 1.05fr 1fr 1.15fr;
  gap: var(--sp-6);
  align-items: stretch;
  direction: ltr;
}
.home-ai__inner--triple > * { direction: rtl; }

@media (max-width: 1100px) {
  .home-ai__inner--triple {
    grid-template-columns: 1fr 1fr;
    gap: var(--sp-5);
  }
  /* .home-ai__content--right { grid-column: 1 / -1; } */
  .home-ai__visual--center{display: none;}
}
@media (max-width: 720px) {
  .home-ai__inner--triple {
    grid-template-columns: 1fr;
    gap: var(--sp-5);
  }
  .home-ai__content--right { grid-column: auto; }
  .home-ai__visual--center { order: -1; }
}

/* Center visual */
.home-ai__visual--center {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--sp-2);
}
.home-ai__phone-wrap--center {
  max-width: 480px;
  width: 100%;
  aspect-ratio: 1 / 1;
  position: relative;
}
.home-ai__phone--center {
  width: 100% !important;
  height: 100% !important;
  object-fit: contain !important;
  animation: home-ai-phone-float 5s ease-in-out infinite;
}
@media (max-width: 1100px) {
  .home-ai__phone-wrap--center { max-width: 380px; }
}
@media (max-width: 720px) {
  .home-ai__phone-wrap--center { max-width: 300px; }
}

/* ---------- Estimate Form ---------- */
.home-ai-form {
  padding: var(--sp-5) var(--sp-5) var(--sp-5);
  border-radius: 18px;
  border: 1px solid rgba(7,26,51,.08);
  box-shadow: 0 12px 40px -24px rgba(7,26,51,.25);
  backdrop-filter: blur(6px);
}
.home-ai-form__header {
  text-align: center;
  margin-bottom: var(--sp-5);
}
.home-ai-form__badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 16px;
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  color: #78350f;
  border-radius: 999px;
  font-size: .82rem;
  font-weight: 800;
  margin-bottom: var(--sp-3);
  border: 1px solid rgba(217,119,6,.3);
  box-shadow: 0 6px 16px -8px rgba(245,158,11,.6);
}
.home-ai-form__title {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 900;
  color: #FFFFFF;

  line-height: 1.35;
}
@media (max-width: 720px) { .home-ai-form__title { font-size: 1.35rem; } }

.home-ai-form__form { display: flex; flex-direction: column; gap: var(--sp-4); }
.home-ai-form__label {
  display: block;
  margin-bottom: 8px;
  font-size: .88rem;
  font-weight: 700;
}
.home-ai-form__field .form-control,
.home-ai-form__field textarea.form-control {
  width: 100%;
  border-radius: 14px;
  padding: 13px 16px;
  font-size: .95rem;
  font-weight: 600;
  border: 1.5px solid #e5e7eb;
  background: #ffffff;
  transition: all .2s ease;
  text-align: right;
}
.home-ai-form__field .form-control:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,.15);
  background: #ffffff;
}
.home-ai-form__upload {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 16px 20px;
  background: #ffffff;
  border: 2px dashed #cbd5e1;
  border-radius: 14px;
  cursor: pointer;
  transition: all .2s ease;
  color: #64748b;
  font-weight: 600;
  width: 100%;
}
.home-ai-form__upload:hover {
  border-color: #3b82f6;
  color: #2563eb;
  background: rgba(59,130,246,.05);
}
.home-ai-form__upload i { font-size: 1.6rem; color: #3b82f6; }

/* Submit button */
.home-ai-form__submit {
  margin-top: 4px;
  padding: 15px 20px;
  border-radius: 16px;
  background: linear-gradient(135deg, #FCD34D 0%, #F59E0B 50%, #F97316 100%);
  color: #1c1917;
  border: none;
  font-size: 1.05rem;
  font-weight: 900;
  box-shadow: 0 10px 26px -10px rgba(245,158,11,.6);
  transition: all .2s ease;
  cursor: pointer;
}
.home-ai-form__submit:hover {
  transform: translateY(-1px);
  box-shadow: 0 14px 32px -12px rgba(245,158,11,.75);
}
.home-ai-form__submit:active { transform: translateY(0); }
.home-ai-form__submit.is-loading {
  position: relative;
  pointer-events: none;
  opacity: .9;
}
.home-ai-form__submit.is-loading i {
  animation: spin 1s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Tags row */
.home-ai-form__tags {
  display: flex;
  justify-content: center;
  gap: 16px;
  border-top: 1px dashed rgba(7,26,51,.12);

  font-size: .78rem;
  font-weight: 700;
}
.home-ai-form__tags span { display: inline-flex; align-items: center; gap: 4px; }
.home-ai-form__tags i { color: #F59E0B; }

/* Result box */
.home-ai-form__result {
  background: linear-gradient(135deg, rgba(59,130,246,.08), rgba(16,185,129,.06));
  border: 1.5px solid rgba(59,130,246,.18);
  border-radius: 16px;
  padding: 16px;
  animation: fadeInUp .35s ease;
}
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.home-ai-form__result-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 800;
  color: #1e3a8a;
  margin-bottom: 10px;
  font-size: .95rem;
}
.home-ai-form__result-header i { color: #10b981; font-size: 1.1rem; }
.home-ai-form__price-minmax {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  gap: 8px;
  align-items: stretch;
  margin-bottom: 10px;
}
.home-ai-form__price-tag {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 10px 6px;
  border-radius: 12px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
}
.home-ai-form__price-tag--low  { border-color: rgba(59,130,246,.3); }
.home-ai-form__price-tag--high { border-color: rgba(245,158,11,.4); }
.home-ai-form__price-tag span {
  font-size: .7rem;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 3px;
}
.home-ai-form__price-tag strong {
  font-size: .95rem;
  font-weight: 900;
  color: #071A33;
  line-height: 1.25;
  text-align: center;
}
.home-ai-form__price-arrow {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
  font-size: 1rem;
}
.home-ai-form__price-mid {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border-radius: 12px;
  background: linear-gradient(135deg, #FDE68A, #FCD34D);
  border: 1px solid #F59E0B;
  color: #7c2d12;
  font-weight: 700;
}
.home-ai-form__price-mid strong {
  font-size: 1.1rem;
  font-weight: 900;
  color: #78350f;
}
.home-ai-form__note {
  margin: 10px 0 0;
  padding-top: 10px;
  border-top: 1px dashed rgba(7,26,51,.1);
  font-size: .82rem;
  color: #475569;
  font-weight: 600;
  line-height: 1.7;
  text-align: center;
}
.home-ai-form__error {
  padding: 12px 14px;
  background: rgba(239,68,68,.08);
  border: 1.5px solid rgba(239,68,68,.3);
  color: #b91c1c;
  border-radius: 12px;
  font-size: .85rem;
  font-weight: 700;
  text-align: center;
}
</style>

<script>
(function () {
  const APP_URL = '<?= APP_URL ?>';
  const CREDIT_UNIT = '<?= CREDIT_UNIT ?>';

  function fmtCreditLocal(amount) {
    const n = Math.round(Number(amount) || 0);
    try {
      return new Intl.NumberFormat('fa-IR').format(n) + ' ' + CREDIT_UNIT;
    } catch {
      return n.toLocaleString('fa-IR') + ' ' + CREDIT_UNIT;
    }
  }

  // افزودن یک صفر به اعداد تخمینی ارزش‌گذاری (درخواست کاربر)
  function appendZero(amount) {
    const n = Number(amount) || 0;
    return n === 0 ? 0 : Math.round(n * 10);
  }

  function getCsrfToken() {
    const inp = document.querySelector('input[name="_csrf"]');
    if (inp && inp.value) return inp.value;
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function withCsrfHeaders() {
    const token = getCsrfToken();
    const out = {};
    if (token) out['X-CSRF-Token'] = token;
    return out;
  }

  function appendCsrf(fd) {
    const token = getCsrfToken();
    if (token && !fd.has('_csrf')) fd.append('_csrf', token);
  }

  /* Quick pricing normalizer (mirror of app.js helper) */
  function normalizePricingFromAi(parsed) {
    if (!parsed || typeof parsed !== 'object') return null;
    const type = String(parsed.type || '');
    if (type === 'chat' || type === 'error') return null;
    let min = 0, max = 0;
    if (parsed.value_range && typeof parsed.value_range === 'object') {
      min = Number(parsed.value_range.min) || 0;
      max = Number(parsed.value_range.max) || 0;
    }
    if (min <= 0 && max <= 0) {
      min = Number(parsed.min) || 0;
      max = Number(parsed.max) || 0;
    }
    if (min <= 0 && max <= 0) {
      const val = Number(parsed.estimated_value ?? parsed.valuation ?? parsed.value ?? parsed.price ?? 0) || 0;
      if (val > 0) {
        min = Math.round(val * 0.85);
        max = Math.round(val * 1.15);
      }
    }
    if (min <= 0 && max <= 0) return null;
    if (min > max) [min, max] = [max, min];
    if (min <= 0) min = Math.max(500000, Math.round(max * 0.82));
    if (max <= 0) max = Math.round(min * 1.18);
    min = Math.round(min / 100000) * 100000;
    max = Math.round(max / 100000) * 100000;
    min = Math.max(500000, min);
    if (min > max) min = Math.round(max * 0.86 / 100000) * 100000;
    const mid = Math.round(((0.42 * min) + (0.58 * max)) * 1.06 / 100000) * 100000;
    let conf = parsed.confidence ?? parsed.certainty ?? 0.55;
    if (typeof conf === 'string') conf = (Number(conf) || 55) / 100;
    if (conf > 1) conf = conf / 100;
    const confPct = Math.round(Math.max(0, Math.min(1, Number(conf) || 0.55)) * 100);
    const uncertain = confPct < 62;
    const reasons = [];
    if (Array.isArray(parsed.reasons) && parsed.reasons.length) {
      parsed.reasons.forEach(r => { const s = String(r).trim(); if (s) reasons.push(s); });
    }
    const sr = String(parsed.reason ?? '').trim();
    if (sr && !reasons.includes(sr)) reasons.push(sr);
    return {
      ok: true, value: mid, range_low: min, range_high: max,
      reasons: reasons, uncertain: uncertain,
      note: uncertain
        ? 'ارزش‌گذاری تقریبی و بصورت راهنماست؛ برای قیمت‌گذاری دقیق‌تر اطلاعات بیشتری وارد کنید.'
        : 'ارزش‌گذاری هوشمند بر اساس اطلاعات شما و آگهی‌های مشابه بازار معاوضه.'
    };
  }

  function extractJsonFromText(raw) {
    if (!raw) return null;
    const text = String(raw).trim();
    if (!text) return null;
    let first = text.indexOf('{');
    let last = text.lastIndexOf('}');
    if (first === -1 || last === -1 || last <= first) return null;
    const snip = text.substring(first, last + 1);
    try { return JSON.parse(snip); } catch {}
    const try2 = snip.replace(/,\s*([\]}])/g, '$1').replace(/\bNaN\b/g, 'null');
    try { return JSON.parse(try2); } catch { return null; }
  }

  async function browserCompleteChat(prepare) {
    // ابتدا از تابع جهانی app.js استفاده کن (پشتیبانی از Gemini و OpenAI)
    if (typeof swaapinBrowserCompleteChat === 'function') {
      try {
        const done = await swaapinBrowserCompleteChat(prepare);
        if (done && done.content) return done;
      } catch (_) { /* در صورت خطا به پیاده‌سازی محلی برمی‌گردیم */ }
    }
    const providers = Array.isArray(prepare.providers) ? prepare.providers : [];
    const msgs = Array.isArray(prepare.messages) ? prepare.messages : [];
    const temperature = typeof prepare.temperature === 'number' ? prepare.temperature : 0.15;
    const maxTokens = Number(prepare.max_tokens) || 1200;
    let lastErr = null;
    for (const p of providers) {
      try {
        const url = p.url;
        const headers = Object.assign({ 'Content-Type': 'application/json' }, p.headers || {});
        const body = Object.assign({}, p.body || {}, {
          messages: msgs,
          temperature: temperature,
          max_tokens: maxTokens,
        });
        const res = await fetch(url, {
          method: 'POST',
          headers: headers,
          body: JSON.stringify(body),
          signal: AbortSignal ? AbortSignal.timeout ? AbortSignal.timeout(20000) : void 0 : void 0,
        });
        if (!res.ok) { lastErr = new Error('http_' + res.status); continue; }
        const j = await res.json();
        let content = '';
        if (j && j.choices && j.choices[0]) {
          const c = j.choices[0];
          if (c.message && typeof c.message.content === 'string') content = c.message.content;
          else if (typeof c.text === 'string') content = c.text;
          else if (c.delta && typeof c.delta.content === 'string') content = c.delta.content;
        }
        if (!content && j && typeof j.content === 'string') content = j.content;
        if (!content && j && j.output && typeof j.output === 'string') content = j.output;
        if (content && content.trim().length > 10) {
          return { provider: p.id || 'client', content: content };
        }
      } catch (e) { lastErr = e; }
    }
    return null;
  }

  /* Main form handler */
  const form = document.getElementById('home-price-estimate-form');
  if (form) {
    const btn = document.getElementById('estimate-submit-btn');
    const btnIcon = btn.querySelector('i');
    const btnOriginalIconClass = 'bi-cpu';
    const resultBox = document.getElementById('estimate-result');
    const resultBody = document.getElementById('estimate-result-body');
    const errBox = document.getElementById('estimate-error');
    const estMin = document.getElementById('est-min');
    const estMax = document.getElementById('est-max');
    const estMid = document.getElementById('est-mid');
    const estNote = document.getElementById('est-note');

    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      errBox.style.display = 'none';
      resultBox.style.display = 'none';

      const titleInput = document.getElementById('estimate_title');
      const title = (titleInput.value || '').trim();
      if (title.length < 3) {
        errBox.textContent = 'نام کالا باید حداقل ۳ کاراکتر باشد.';
        errBox.style.display = 'block';
        titleInput.focus();
        return;
      }
      const descEl = document.getElementById('estimate_description');
      const description = (descEl.value || '').trim();
      const condition = 'good';

      const fd = new FormData();
      fd.append('title', title);
      fd.append('description', description);
      fd.append('condition', condition);
      appendCsrf(fd);

      btn.classList.add('is-loading');
      const prevHtml = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> در حال محاسبه قیمت ...';

      try {
        const res = await fetch(APP_URL + '/api/ai_estimate_public.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: withCsrfHeaders(),
        });
        let prepare;
        try { prepare = await res.json(); } catch { prepare = { ok: false }; }

        let result = null;
        if (prepare && prepare.ok === true && prepare.type === 'server_result' && prepare.data) {
          const d = prepare.data;
          result = {
            ok: true,
            value: Number(d.mid) || 0,
            range_low: Number(d.min) || 0,
            range_high: Number(d.max) || 0,
            note: String(d.note || '') || '',
          };
          if (result.value <= 0 && (result.range_low > 0 || result.range_high > 0)) {
            result.value = Math.round(((result.range_low || 0) + (result.range_high || 0)) / 2);
          }
        } else if (prepare && prepare.ok === true && prepare.type === 'client_prepare') {
          let done = null;
          try { done = await browserCompleteChat(prepare); } catch {}
          if (done && done.content) {
            const parsed = extractJsonFromText(done.content);
            const norm = normalizePricingFromAi(parsed);
            if (norm) result = norm;
          }
          if (!result && prepare.fallback && typeof prepare.fallback === 'object') {
            const fb = prepare.fallback;
            if (Number(fb.mid) > 0 || Number(fb.min) > 0 || Number(fb.max) > 0) {
              result = {
                ok: true,
                value: Number(fb.mid) || Math.round(((Number(fb.min) || 0) + (Number(fb.max) || 0)) / 2),
                range_low: Number(fb.min) || 0,
                range_high: Number(fb.max) || 0,
                note: String(fb.note || '') || 'ارزش‌گذاری با مدل پایه سواَپین  (حالت اضطراری).',
                uncertain: true,
              };
            }
          }
        } else if (prepare && prepare.ok === false && prepare.msg) {
          throw new Error(String(prepare.msg));
        } else if (prepare && prepare.error === 'rate_limited') {
          throw new Error('درخواست‌های زیادی ارسال شده است. لطفاً کمی بعد دوباره تلاش کنید.');
        } else if (!res.ok) {
          throw new Error('خطا در برقراری ارتباط. کد ' + res.status);
        }

        if (!result || (!result.value && !result.range_low && !result.range_high)) {
          throw new Error('متاسفانه تخمین قیمت برای این کالا ممکن نشد. لطفاً توضیحات بیشتری وارد کنید.');
        }

        const baseMin = result.range_low || Math.round(result.value * 0.85);
        const baseMax = result.range_high || Math.round(result.value * 1.15);
        const baseMid = result.value || Math.round((baseMin + baseMax) / 2);
        // افزودن یک صفر به اعداد تخمینی نمایش‌داده‌شده
        const min = appendZero(baseMin);
        const max = appendZero(baseMax);
        const mid = appendZero(baseMid);

        estMin.textContent = fmtCreditLocal(min);
        estMax.textContent = fmtCreditLocal(max);
        estMid.textContent = fmtCreditLocal(mid);
        estNote.textContent = result.note && String(result.note).trim()
          ? String(result.note).trim()
          : 'ارزش‌گذاری هوشمند بر اساس اطلاعات شما و بازار معاوضه سواَپین.';

        resultBox.style.display = 'block';
      } catch (err) {
        errBox.textContent = (err && err.message) ? String(err.message) : 'خطای ناشناخته در ارزش‌گذاری.';
        errBox.style.display = 'block';
      } finally {
        btn.classList.remove('is-loading');
        btn.innerHTML = prevHtml;
      }
    });
  }
})();
</script>

<section class="home-section home-trust home-trust--compact">
  <div class="container">
    <div class="trust-grid trust-grid--compact" id="home-trust-slider" data-role="trust-slider">
      <?php
      $trust = [
          ['bi-star-fill',        'امتیاز و نظرات',    'بعد از هر مبادله، طرفین به هم امتیاز می‌دهند و پروفایل اعتماد ساخته می‌شود.'],
          ['bi-patch-check-fill', 'احراز هویت',        'سطح تأیید تلفن و هویت در پروفایل هر کاربر نمایش داده می‌شود.'],
          ['bi-clock-history',    'تاریخچه معاملات',   'سوابق مبادلات انجام‌شده برای شفافیت در پروفایل قابل مشاهده است.'],
          ['bi-shield-lock',      'پیام‌رسانی امن',     'گفتگوی مستقیم داخل پلتفرم قبل از نهایی کردن معامله.'],
      ];
      $trustIdx = 0;
      foreach ($trust as [$icon, $title, $desc]):
        $trustIdx++;
      ?>
      <article class="trust-card trust-card--compact trust-slider__item" data-trust-index="<?= $trustIdx ?>">
        <div class="trust-card__icon trust-card__icon--compact"><i class="bi <?= $icon ?>"></i></div>
        <div class="trust-card__body">
          <h3 class="trust-card__title"><?= $title ?></h3>
          <p class="trust-card__desc"><?= $desc ?></p>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <style>
      /* در موبایل اسلایدر شبکه اعتماد مخفی می‌شود */
      @media (max-width: 768px) {
        .trust-grid--compact[data-role="trust-slider"] {
          display: none !important;
        }
      }
    </style>
    <script>
    (function () {
      const sliders = document.querySelectorAll('.trust-grid--compact[data-role="trust-slider"]');
      sliders.forEach(function (grid) {
        const wrap = grid.parentElement;
        const scroller = grid;
        let autoscrollTimer = null;
        let startX = null;
        let isDown = false;

        function goTo(idx) {
          const items = scroller.querySelectorAll('.trust-slider__item');
          if (!items.length) return;
          const real = Math.max(0, Math.min(idx - 1, items.length - 1));
          const it = items[real];
          const gap = parseInt(getComputedStyle(scroller).columnGap || 14, 10);
          const scrollerRect = scroller.getBoundingClientRect();
          const itemRect = it.getBoundingClientRect();
          const target = scroller.scrollLeft + (itemRect.left - scrollerRect.left) - (scrollerRect.width - itemRect.width) / 2;
          scroller.scrollTo({ left: target, behavior: 'smooth' });
        }

        function next() {
          const items = scroller.querySelectorAll('.trust-slider__item');
          const rect = scroller.getBoundingClientRect();
          let nextIdx = 1;
          items.forEach((it, i) => {
            const r = it.getBoundingClientRect();
            const center = r.left + r.width / 2;
            const rel = center - (rect.left + rect.width / 2);
            if (Math.abs(rel) < 1) nextIdx = i + 2;
          });
          if (nextIdx > items.length) nextIdx = 1;
          goTo(nextIdx);
        }

        function start() {
          stop();
          autoscrollTimer = setInterval(next, 3800);
        }
        function stop() {
          if (autoscrollTimer) { clearInterval(autoscrollTimer); autoscrollTimer = null; }
        }
        function reset() {
          stop(); start();
        }
        if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
          scroller.addEventListener('mouseenter', stop);
          scroller.addEventListener('mouseleave', start);
          scroller.addEventListener('touchstart', stop, { passive: true });
          scroller.addEventListener('touchend', reset, { passive: true });
          start();
          const first = scroller.querySelector('.trust-slider__item');
          if (first) {
            setTimeout(function () { scroller.scrollLeft = 0; }, 250);
          }
        }
      });
    })();
    </script>
  </div>
</section>
<?php endif; ?>

<!-- ===== پشتیبانی سواپین: ویجت شناور + دکمه‌ها ===== -->
<?php
$supQuestions = [
    'چطور در سواپین ثبت‌نام کنم؟',
    'چطور کالایم را برای معاوضه ثبت کنم؟',
    'معاوضه در سواپین چطور انجام می‌شود؟',
    'ارزش کالایم را چطور تعیین کنم؟',
    'چطور آگهی‌ام را ویژه کنم؟',
];
?>
<style>
:root {
  --sw-sup-navy: #071A33;
  --sw-sup-navy-dark: #030D1C;
  --sw-sup-yellow: #FFC928;
  --sw-sup-yellow-dark: #E6B000;
  --sw-sup-yellow-light: #FFF8E1;
}
.swp-support-fab {
  position: fixed;
  bottom: 24px;
  left: 24px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}
.swp-support-fab__btn {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--sw-sup-navy) 0%, var(--sw-sup-navy-dark) 100%);
  color: #fff;
  border: none;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(7,26,51,.4);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  position: relative;
  transition: transform .2s, box-shadow .2s;
}
.swp-support-fab__btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 32px rgba(7,26,51,.5);
}
.swp-support-fab__btn::after {
  content: '';
  position: absolute;
  inset: -4px;
  border-radius: 50%;
  border: 2px solid var(--sw-sup-yellow);
  opacity: .5;
  animation: swp-pulse 2s infinite;
}
@keyframes swp-pulse {
  0% { transform: scale(1); opacity: .5; }
  100% { transform: scale(1.3); opacity: 0; }
}
.swp-support-fab__badge {
  position: absolute;
  top: -4px;
  right: -4px;
  background: var(--sw-sup-yellow);
  color: var(--sw-sup-navy);
  font-size: .7rem;
  font-weight: 700;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
}

/* دکمه‌های درون صفحه */
.swp-support-btns {
  display: flex;
  gap: 12px;
  justify-content: center;
  margin: 24px 0;
  flex-wrap: wrap;
}
.swp-support-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 24px;
  border-radius: 12px;
  font-weight: 600;
  font-size: .95rem;
  border: none;
  cursor: pointer;
  transition: transform .2s, box-shadow .2s;
  font-family: inherit;
}
.swp-support-btn--primary {
  background: var(--sw-sup-navy);
  color: #fff;
}
.swp-support-btn--primary:hover {
  background: var(--sw-sup-navy-dark);
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(7,26,51,.3);
}
.swp-support-btn--accent {
  background: var(--sw-sup-yellow);
  color: var(--sw-sup-navy);
}
.swp-support-btn--accent:hover {
  background: var(--sw-sup-yellow-dark);
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(255,201,40,.3);
}
.swp-support-btn i { font-size: 1.15rem; }

/* مودال */
.swp-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(3,13,28,.65);
  backdrop-filter: blur(4px);
  z-index: 10000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.swp-modal-overlay.is-open { display: flex; }
.swp-modal {
  background: #fff;
  border-radius: 20px;
  width: 100%;
  max-width: 440px;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 24px 60px rgba(0,0,0,.4);
  animation: swp-pop .25s ease;
}
@keyframes swp-pop {
  from { transform: scale(.92); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
.swp-modal__header {
  background: linear-gradient(135deg, var(--sw-sup-navy) 0%, var(--sw-sup-navy-dark) 100%);
  color: #fff;
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 12px;
  position: relative;
}
.swp-modal__close {
  position: absolute;
  top: 12px;
  right: 12px;
  background: rgba(255,255,255,.15);
  border: none;
  color: #fff;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .2s;
}
.swp-modal__close:hover { background: rgba(255,255,255,.25); }
.swp-modal__head-info { flex: 1; }
.swp-modal__head-info h3 { margin: 0; font-size: 1.1rem; font-weight: 700; }
.swp-modal__head-info p { margin: 2px 0 0; font-size: .8rem; opacity: .8; }
.swp-modal__head-avatars { display: flex; }
.swp-modal__head-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--sw-sup-yellow);
  color: var(--sw-sup-navy);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  margin-left: -10px;
  border: 2px solid var(--sw-sup-navy);
}
.swp-modal__head-avatar:first-child { margin-left: 0; }
.swp-modal__body { flex: 1; overflow: hidden; display: flex; flex-direction: column; }

/* نمای فرم */
.swp-form-view { padding: 16px; }
.swp-msg {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 16px;
}
.swp-msg__avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--sw-sup-navy);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 1rem;
}
.swp-msg__bubble {
  background: #f4f6f9;
  border-radius: 0 14px 14px 14px;
  padding: 12px 14px;
  max-width: 85%;
}
.swp-msg__bubble p { margin: 0 0 10px; font-weight: 600; font-size: .95rem; color: var(--sw-sup-navy); }
.swp-qbuttons {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.swp-qbtn {
  text-align: right;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 12px;
  font-size: .85rem;
  cursor: pointer;
  transition: all .15s;
  font-family: inherit;
  color: var(--sw-sup-navy);
}
.swp-qbtn:hover { border-color: var(--sw-sup-yellow); background: var(--sw-sup-yellow-light); }
.swp-qbtn.is-selected { border-color: var(--sw-sup-navy); background: var(--sw-sup-yellow-light); color: var(--sw-sup-navy-dark); font-weight: 600; }

.swp-form-card {
  background: #fafbfc;
  border-radius: 14px;
  padding: 16px;
  border: 1px solid #eef0f3;
}
.swp-form-card__title { margin: 0 0 12px; font-size: .9rem; color: #475569; }
.swp-field { position: relative; margin-bottom: 12px; }
.swp-field__input {
  width: 100%;
  padding: 14px 12px 6px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  font-size: .95rem;
  font-family: inherit;
  background: #fff;
  transition: border-color .15s;
  box-sizing: border-box;
}
.swp-field__input:focus { outline: none; border-color: var(--sw-sup-navy); }
.swp-field__input.is-invalid { border-color: #ef4444; }
.swp-field__label {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  font-size: .9rem;
  pointer-events: none;
  transition: all .15s;
  background: transparent;
  padding: 0 4px;
}
.swp-field__input:focus + .swp-field__label,
.swp-field__input:not(:placeholder-shown) + .swp-field__label {
  top: 8px;
  transform: none;
  font-size: .72rem;
  color: var(--sw-sup-navy);
  font-weight: 600;
}
.swp-field__error { display: block; color: #ef4444; font-size: .75rem; margin-top: 4px; }
.swp-submit {
  width: 100%;
  padding: 13px;
  background: var(--sw-sup-navy);
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: background .15s;
}
.swp-submit:hover { background: var(--sw-sup-navy-dark); }
.swp-submit:disabled { opacity: .7; cursor: not-allowed; }
.swp-note { text-align: center; font-size: .72rem; color: #94a3b8; margin: 10px 0 0; }

/* نمای چت */
.swp-chat-view {
  flex: 1;
  display: none;
  flex-direction: column;
  background: #f4f6f9;
}
.swp-chat-view.is-active { display: flex; }
.swp-chat-thread {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.swp-c-msg { display: flex; }
.swp-c-msg--guest { justify-content: flex-end; }
.swp-c-msg--admin, .swp-c-msg--ai { justify-content: flex-start; }
.swp-c-msg__bubble {
  max-width: 78%;
  padding: 8px 12px;
  border-radius: 14px;
  font-size: .9rem;
  line-height: 1.5;
  word-wrap: break-word;
}
.swp-c-msg--guest .swp-c-msg__bubble {
  background: var(--sw-sup-yellow-light);
  color: var(--sw-sup-navy);
  border-radius: 14px 14px 0 14px;
}
.swp-c-msg--admin .swp-c-msg__bubble,
.swp-c-msg--ai .swp-c-msg__bubble {
  background: #fff;
  color: #1e293b;
  border-radius: 14px 14px 14px 0;
}
.swp-c-msg__sender { display: block; font-size: .7rem; color: var(--sw-sup-navy); font-weight: 700; margin-bottom: 2px; }
.swp-c-msg__time { display: block; font-size: .65rem; color: #94a3b8; margin-top: 4px; text-align: left; }
.swp-c-msg--guest .swp-c-msg__time { text-align: left; }

.swp-composer {
  display: flex;
  gap: 8px;
  padding: 12px;
  background: #fff;
  border-top: 1px solid #eef0f3;
}
.swp-composer__input {
  flex: 1;
  resize: none;
  border: 1.5px solid #e2e8f0;
  border-radius: 20px;
  padding: 10px 14px;
  font-size: .9rem;
  font-family: inherit;
  max-height: 100px;
  line-height: 1.4;
}
.swp-composer__input:focus { outline: none; border-color: var(--sw-sup-navy); }
.swp-composer__send {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: var(--sw-sup-navy);
  color: #fff;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: background .15s;
}
.swp-composer__send:hover { background: var(--sw-sup-navy-dark); }
.swp-composer__send:disabled { opacity: .5; }

@media (max-width: 600px) {
  .swp-modal { max-height: 92vh; border-radius: 16px; }
  .swp-support-fab__btn { width: 54px; height: 54px; font-size: 1.3rem; bottom: 16px; left: 16px; }
  .swp-support-btn { padding: 10px 18px; font-size: .88rem; }
}
</style>

<!-- دکمه‌های پشتیبانی درون صفحه -->
<div class="swp-support-btns" id="swp-support-buttons">
  <button type="button" class="swp-support-btn swp-support-btn--primary" data-swp-open>
    <i class="bi bi-headset"></i> پشتیبانی سواَپین
  </button>
  <button type="button" class="swp-support-btn swp-support-btn--accent" data-swp-open>
    <i class="bi bi-chat-dots"></i> چت با پشتیبانی
  </button>
</div>

<!-- دکمه شناور -->
<div class="swp-support-fab">
  <button type="button" class="swp-support-fab__btn" data-swp-open aria-label="پشتیبانی سواپین">
    <i class="bi bi-headset"></i>
    <span class="swp-support-fab__badge">!</span>
  </button>
</div>

<!-- مودال پشتیبانی -->
<div class="swp-modal-overlay" id="swp-modal">
  <div class="swp-modal" role="dialog" aria-modal="true" aria-label="پشتیبانی سواپین">
    <div class="swp-modal__header">
      <button type="button" class="swp-modal__close" id="swp-modal-close" aria-label="بستن">
        <i class="bi bi-x-lg"></i>
      </button>
      <div class="swp-modal__head-info">
        <h3>پشتیبانی سواپین</h3>
        <p id="swp-modal-subtitle">پاسخگوی سوالات شما هستیم</p>
      </div>
      <div class="swp-modal__head-avatars">
        <div class="swp-modal__head-avatar"><i class="bi bi-headset"></i></div>
        <div class="swp-modal__head-avatar"><i class="bi bi-person-badge"></i></div>
      </div>
    </div>
    <div class="swp-modal__body">

      <!-- نمای فرم -->
      <div class="swp-form-view" id="swp-form-view">
        <div class="swp-msg">
          <div class="swp-msg__avatar"><i class="bi bi-headset"></i></div>
          <div class="swp-msg__bubble">
            <p>چگونه می‌توانیم کمکتان کنیم؟</p>
            <div class="swp-qbuttons" id="swp-qbuttons">
              <?php foreach ($supQuestions as $q): ?>
                <button type="button" class="swp-qbtn" data-topic="<?= h($q) ?>"><?= h($q) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="swp-form-card">
          <h4 class="swp-form-card__title">برای شروع گفتگو فرم زیر را تکمیل کنید</h4>
          <form id="swp-form" novalidate>
            <input type="hidden" name="topic" id="swp-topic" value="">
            <div class="swp-field">
              <input type="text" id="swp-name" name="name" class="swp-field__input" placeholder=" " autocomplete="name" required>
              <label class="swp-field__label" for="swp-name">نام و نام خانوادگی</label>
            </div>
            <div class="swp-field">
              <input type="tel" id="swp-phone" name="phone" class="swp-field__input" placeholder=" " autocomplete="tel" maxlength="11" required>
              <label class="swp-field__label" for="swp-phone">تلفن همراه</label>
            </div>
            <button type="submit" class="swp-submit" id="swp-submit">شروع گفتگو</button>
          </form>
          <p class="swp-note">با شروع گفتگو، شرایط پشتیبانی سواپین را می‌پذیرید.</p>
        </div>
      </div>

      <!-- نمای چت -->
      <div class="swp-chat-view" id="swp-chat-view">
        <div class="swp-chat-thread" id="swp-chat-thread"></div>
        <form class="swp-composer" id="swp-composer">
          <textarea class="swp-composer__input" id="swp-chat-input" rows="1" placeholder="پیام خود را بنویسید…" autocomplete="off"></textarea>
          <button type="submit" class="swp-composer__send" id="swp-chat-send" aria-label="ارسال">
            <i class="bi bi-send-fill"></i>
          </button>
        </form>
      </div>

    </div>
  </div>
</div>

<script>
(function() {
  const APP_URL = <?= json_encode(APP_URL) ?>;
  const apiUrl  = APP_URL + '/api/live_chat.php';
  const modal   = document.getElementById('swp-modal');
  const formView = document.getElementById('swp-form-view');
  const chatView = document.getElementById('swp-chat-view');
  const thread  = document.getElementById('swp-chat-thread');
  const form    = document.getElementById('swp-form');
  const topicInp = document.getElementById('swp-topic');
  const qBtns   = document.querySelectorAll('.swp-qbtn');
  const composer = document.getElementById('swp-composer');
  const chatInp = document.getElementById('swp-chat-input');
  const chatSend = document.getElementById('swp-chat-send');
  const subtitle = document.getElementById('swp-modal-subtitle');

  let chatToken = '';
  let lastMsgId = 0;
  let pollTimer = null;

  function getCsrf() {
    const inp = document.querySelector('input[name="_csrf"]');
    if (inp && inp.value) return inp.value;
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function openModal() {
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-swp-open]').forEach(el => {
    el.addEventListener('click', openModal);
  });
  document.getElementById('swp-modal-close').addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });

  // انتخاب سوال
  qBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      qBtns.forEach(b => b.classList.remove('is-selected'));
      btn.classList.add('is-selected');
      topicInp.value = btn.getAttribute('data-topic') || '';
    });
  });

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function clearErrors() {
    document.querySelectorAll('.swp-field__error').forEach(e => e.remove());
    document.querySelectorAll('.swp-field__input').forEach(i => i.classList.remove('is-invalid'));
  }
  function addError(fieldId, msg) {
    const inp = document.getElementById(fieldId);
    inp.classList.add('is-invalid');
    const span = document.createElement('span');
    span.className = 'swp-field__error';
    span.textContent = msg;
    inp.parentElement.appendChild(span);
  }

  // ارسال فرم
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors();
    const name = document.getElementById('swp-name').value.trim();
    const phone = document.getElementById('swp-phone').value.trim();
    const topic = topicInp.value.trim();
    let ok = true;
    if (name.length < 2) { addError('swp-name', 'نام را وارد کنید.'); ok = false; }
    if (!/^09\d{9}$/.test(phone)) { addError('swp-phone', 'شماره موبایل معتبر وارد کنید.'); ok = false; }
    if (!ok) return;

    const btn = document.getElementById('swp-submit');
    btn.disabled = true;
    btn.textContent = 'در حال اتصال…';

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
        body: JSON.stringify({ action: 'start', name, phone, transcript: [] })
      });
      const data = await res.json();
      if (!data.ok) {
        if (data.errors) {
          if (data.errors.name) addError('swp-name', data.errors.name);
          if (data.errors.phone) addError('swp-phone', data.errors.phone);
        } else {
          alert(data.error || 'خطا در شروع گفتگو');
        }
        btn.disabled = false;
        btn.textContent = 'شروع گفتگو';
        return;
      }

      chatToken = data.token;
      // اگر سوالی انتخاب شده بود، به‌عنوان اولین پیام بفرست
      if (topic) {
        try {
          await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send', token: chatToken, body: topic })
          });
        } catch {}
      }
      // نمایش چت
      startChat();
    } catch {
      alert('خطا در برقراری ارتباط');
      btn.disabled = false;
      btn.textContent = 'شروع گفتگو';
    }
  });

  function startChat() {
    formView.style.display = 'none';
    chatView.classList.add('is-active');
    subtitle.textContent = 'آنلاین · در حال گفتگو';
    thread.innerHTML = '';
    appendMsg({ sender_type: 'ai', body: 'سلام! 👋 به‌زودی یکی از همکاران ما پاسخگوی شما خواهد بود.', created_at: new Date().toISOString() });
    lastMsgId = 0;
    loadMessages();
    pollTimer = setInterval(loadMessages, 3000);
    chatInp.focus();
  }

  async function loadMessages() {
    if (!chatToken) return;
    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'messages', token: chatToken, after_id: lastMsgId })
      });
      const data = await res.json();
      if (data.ok && Array.isArray(data.messages)) {
        for (const m of data.messages) {
          appendMsg(m);
          lastMsgId = Math.max(lastMsgId, parseInt(m.id, 10));
        }
      }
    } catch {}
  }

  function appendMsg(m) {
    const wrap = document.createElement('div');
    wrap.className = 'swp-c-msg swp-c-msg--' + (m.sender_type || 'guest');
    const senderLabel = m.sender_type === 'admin' ? 'پشتیبانی' : (m.sender_type === 'ai' ? 'دستیار' : '');
    const senderHtml = senderLabel ? '<span class="swp-c-msg__sender">' + esc(senderLabel) + '</span>' : '';
    let time;
    try { time = new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }); }
    catch { time = ''; }
    wrap.innerHTML = '<div class="swp-c-msg__bubble">' + senderHtml +
      '<div>' + esc(m.body).replace(/\n/g, '<br>') + '</div>' +
      '<span class="swp-c-msg__time">' + esc(time) + '</span></div>';
    thread.appendChild(wrap);
    thread.scrollTop = thread.scrollHeight;
  }

  // ارسال پیام چت
  composer.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = chatInp.value.trim();
    if (!body) return;
    chatInp.value = '';
    chatSend.disabled = true;
    appendMsg({ sender_type: 'guest', body, created_at: new Date().toISOString() });
    try {
      await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'send', token: chatToken, body })
      });
    } catch {}
    chatSend.disabled = false;
    chatInp.focus();
  });

  chatInp.addEventListener('input', () => {
    chatInp.style.height = 'auto';
    chatInp.style.height = Math.min(chatInp.scrollHeight, 100) + 'px';
  });
  chatInp.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); composer.requestSubmit(); }
  });
})();
</script>

<?php render_footer(); ?>
