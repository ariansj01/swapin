<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/content_manager.php';

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
$wantType  = clean($_GET['want']      ?? '');
$sort      = in_array($_GET['sort'] ?? '', ['new','old','value']) ? $_GET['sort'] : 'new';
$page      = max(1, (int)($_GET['page'] ?? 1));
$timeAgo   = in_array($_GET['time_ago'] ?? '', ['3h','12h','1d','3d','1w']) ? $_GET['time_ago'] : '';
$pmin      = isset($_GET['price_min']) ? (float)$_GET['price_min'] : 0;
$pmax      = isset($_GET['price_max']) ? (float)$_GET['price_max'] : 0;

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
    $provinces = iran_provinces();
    if (in_array($city, $provinces, true)) {
        $provinceCities = iran_cities_by_province($city);
        if (!empty($provinceCities)) {
            $placeholders = implode(',', array_fill(0, count($provinceCities), '?'));
            $whereClauses[] = "l.city IN ($placeholders)";
            foreach ($provinceCities as $pc) {
                $params[] = $pc;
            }
        } else {
            $whereClauses[] = 'l.city LIKE ?';
            $params[] = "%{$city}%";
        }
    } else {
        $whereClauses[] = 'l.city LIKE ?';
        $params[] = "%{$city}%";
    }
}
if ($wantType) {
    $whereClauses[] = 'l.want_type = ?';
    $params[] = $wantType;
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
    default => '(l.vip_until > NOW()) DESC, (l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC, l.id DESC',
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
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     {$where}
     ORDER BY {$orderBy}
     LIMIT ? OFFSET ?",
    [...$params, $displayLimit, $pag['offset']]
);

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
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         WHERE " . implode(' AND ', $premiumWhere) . "
         ORDER BY {$premiumOrderBy}
         LIMIT 20"
    );
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
         ORDER BY listings_count DESC, rating DESC, created_at DESC
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
             ORDER BY listings_count DESC, rating DESC, created_at DESC
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
    'json_ld'   => [seo_json_ld_website(), seo_json_ld_organization()],
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
  <div class="container hero__inner" style="background: var(--gradient-brand);border-radius: 10px;margin-top: 13px;">
    <div class="hero__visual">
      <img src="<?= APP_URL ?>/src/img/heropng.png" alt="مبادله هوشمند کالا در <?= APP_NAME ?>" class="hero__img" loading="eager">
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
      <aside class="home-sidebar card home-sidebar--mobile-collapsible" id="home-mobile-sidebar"
             aria-label="دسته‌بندی‌ها، فیلترها و منوها"
             style="background: transparent;border: none;box-shadow: none;">

        <!-- دکمه باز/بسته سایدبار (فقط در موبایل) -->
        <button type="button"
                class="home-sidebar__mobile-toggle"
                id="home-sidebar-mobile-toggle"
                aria-expanded="false"
                aria-controls="home-mobile-sidebar">
          <span class="home-sidebar__mobile-toggle-icon"><i class="bi bi-sliders2"></i></span>
          <span class="home-sidebar__mobile-toggle-label">فیلترها و دسته‌بندی‌ها</span>
          <span class="home-sidebar__mobile-toggle-caret"><i class="bi bi-chevron-down"></i></span>
        </button>

        <div class="home-sidebar__inner home-sidebar__inner--collapsible">

          <!-- Title + Count -->
          <div class="home-sidebar__header">
            <h3 class="home-sidebar__title">دسته‌بندی‌های محبوب</h3>
            <!-- <span class="home-sidebar__count"><?= fmt_num($total) ?> دسته</span> -->
          </div>

          <!-- Categories List (Real Filter Links) -->
          <nav class="home-sidebar-cats" aria-label="دسته‌بندی‌ها">
            <?php
            $sidebarCats = [
                ['slug' => 'real-estate',     'name' => 'املاک',              'icon' => 'bi-house',          'img'  => 'real-estate.png'],
                ['slug' => 'vehicles',        'name' => 'وسایل نقلیه',        'icon' => 'bi-car-front',      'img'  => 'vehicles.png'],
                ['slug' => 'electronics',     'name' => 'کالای دیجیتال',      'icon' => 'bi-phone',          'img'  => 'electronic-devices.png'],
                ['slug' => 'home-garden',     'name' => 'خانه و آشپزخانه',    'icon' => 'bi-chef-hat',       'img'  => 'home-kitchen.png'],
                ['slug' => 'services',        'name' => 'خدمات',              'icon' => 'bi-cursor',         'img'  => 'services.png'],
                ['slug' => 'clothing',        'name' => 'پوشاک',              'icon' => 'bi-t-shirt',        'img'  => 'personal.png'],
                ['slug' => 'sports',          'name' => 'ورزش و سرگرمی',     'icon' => 'bi-soccer',         'img'  => 'leisure-hobbies.png'],
                ['slug' => 'jobs',            'name' => 'استخدام و کاریابی',  'icon' => 'bi-briefcase',      'img'  => 'jobs.png'],
                ['slug' => 'jobs-2',          'name' => 'استخدام و کاریابی',  'icon' => 'bi-briefcase',      'img'  => 'jobs.png'],
            ];
            $catImgBase = APP_URL . '/src/img/category/';
            $buildSidebarQuery = function($overrides = []) use ($search, $city, $wantType, $sort, $timeAgo, $pmin, $pmax, $catSlug) {
                $params = array_filter([
                    'cat'       => $overrides['cat']       ?? $catSlug,
                    'q'         => $overrides['q']         ?? $search,
                    'city'      => $overrides['city']      ?? $city,
                    'want'      => $overrides['want']      ?? $wantType,
                    'sort'      => $overrides['sort']      ?? $sort,
                    'time_ago'  => $overrides['time_ago']  ?? $timeAgo,
                    'price_min' => $overrides['price_min'] ?? ($pmin > 0 ? $pmin : null),
                    'price_max' => $overrides['price_max'] ?? ($pmax > 0 ? $pmax : null),
                ]);
                return $params ? '?' . http_build_query($params) : '';
            };
            ?>
            <?php foreach ($sidebarCats as $sc):
              $isCatActive = ($catSlug === $sc['slug']);
            ?>
              <a href="<?= APP_URL ?>/<?= $buildSidebarQuery(['cat' => $sc['slug']]) ?>" class="home-sidebar-cats__item <?= $isCatActive ? 'is-active' : '' ?>">
                <span class="home-sidebar-cats__icon">
                  <img src="<?= $catImgBase . $sc['img'] ?>" alt="<?= h($sc['name']) ?>" class="home-sidebar-cats__icon-img"
                       onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                  <i class="bi <?= $sc['icon'] ?> home-sidebar-cats__icon-fallback" style="display:none"></i>
                </span>
                <span class="home-sidebar-cats__label"><?= h($sc['name']) ?></span>
              </a>
            <?php endforeach; ?>
          </nav>

          <!-- Filters: Real Dropdowns (همه شهرها، انواع معامله، جدیدترین) + جستجو -->
          <form id="sidebar-filters-form" method="GET" action="<?= APP_URL ?>/" class="home-sidebar-filters-form">
            <input type="hidden" name="cat" value="<?= h($catSlug) ?>">
            <input type="hidden" name="time_ago" value="<?= h($timeAgo) ?>">

            <!-- جستجو -->
            <div class="home-sidebar-field">
              <label class="home-sidebar-field__label" for="sidebar-search-input">
                <i class="bi bi-search"></i> جستجوی کالا
              </label>
              <input type="search" id="sidebar-search-input" name="q" class="form-control"
                     value="<?= h($search) ?>" placeholder="دنبال چه کالایی هستید؟">
            </div>

            <!-- همه شهرها -->
            <div class="home-sidebar-field">
              <label class="home-sidebar-field__label" for="sidebar-city-select">
                <i class="bi bi-geo-alt"></i> همه استان‌ها
              </label>
              <select id="sidebar-city-select" name="city" class="form-control">
                <option value="">همه استان‌ها</option>
                <?php foreach (iran_provinces() as $prov): ?>
                  <option value="<?= h($prov) ?>" <?= $city === $prov ? 'selected' : '' ?>><?= h($prov) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- انواع معامله + مرتب‌سازی کنار هم -->
            <div class="home-sidebar-field-row">
              <!-- انواع معامله -->
              <div class="home-sidebar-field">
                <label class="home-sidebar-field__label" for="sidebar-want-select">
                  <i class="bi bi-arrow-left-right"></i> انواع معامله
                </label>
                <select id="sidebar-want-select" name="want" class="form-control">
                  <option value=""    <?= $wantType === '' ? 'selected' : '' ?>>همه انواع معامله</option>
                  <option value="item"    <?= $wantType === 'item' ? 'selected' : '' ?>>کالا با کالا</option>
                  <option value="service" <?= $wantType === 'service' ? 'selected' : '' ?>>خدمات</option>
                  <option value="credit"  <?= $wantType === 'credit' ? 'selected' : '' ?>>اعتبار</option>
                </select>
              </div>

              <!-- مرتب‌سازی -->
              <div class="home-sidebar-field">
                <label class="home-sidebar-field__label" for="sidebar-sort-select">
                  <i class="bi bi-sort-down-alt"></i> مرتب‌سازی
                </label>
                <select id="sidebar-sort-select" name="sort" class="form-control">
                  <option value="new"   <?= $sort === 'new'   ? 'selected' : '' ?>>جدیدترین</option>
                  <option value="old"   <?= $sort === 'old'   ? 'selected' : '' ?>>قدیمی‌ترین</option>
                  <option value="value" <?= $sort === 'value' ? 'selected' : '' ?>>بالاترین ارزش</option>
                </select>
              </div>
            </div>


            <!-- Price Min/Max -->
            <div class="home-sidebar-field">
              <label class="home-sidebar-field__label">
                <i class="bi bi-tag"></i> محدوده قیمت
              </label>
              <div class="home-sidebar-field__row">
                <div class="home-sidebar-field__col">
                  <span class="home-sidebar-field__sub">از</span>
                  <input type="number" name="price_min" class="form-control" min="0" step="1000"
                         value="<?= $pmin > 0 ? (int)$pmin : '' ?>" placeholder="قیمت کمینه">
                </div>
                <div class="home-sidebar-field__col">
                  <span class="home-sidebar-field__sub">تا</span>
                  <input type="number" name="price_max" class="form-control" min="0" step="1000"
                         value="<?= $pmax > 0 ? (int)$pmax : '' ?>" placeholder="قیمت بیشینه">
                </div>
              </div>
            </div>

            <!-- Apply Filter Button -->
            <button type="submit" class="btn btn-primary w-100 home-sidebar-apply-btn">
              <i class="bi bi-funnel"></i> اعمال فیلترها
            </button>
          </form>

          <!-- Time Chips -->
          <div class="home-sidebar-time">
            <div class="home-sidebar-time__header">
              <i class="bi bi-question-circle"></i>
              <h4>زمان انتشار آگهی:</h4>
            </div>
            <div class="home-sidebar-time__chips">
              <?php
              $timeChipList = [
                ['value' => '',    'label' => 'همه'],
                ['value' => '1d',  'label' => 'اروزه'],
                ['value' => '3h',  'label' => '۳ساعته'],
                ['value' => '12h', 'label' => '۱۲ساعته'],
                ['value' => '1w',  'label' => 'اهفته'],
                ['value' => '3d',  'label' => '۳روزه'],
              ];
              foreach ($timeChipList as $tc):
                $isActive = ($timeAgo === $tc['value']);
                $chipQuery = [
                  'cat'       => $catSlug,
                  'q'         => $search,
                  'city'      => $city,
                  'want'      => $wantType,
                  'sort'      => $sort,
                  'time_ago'  => $tc['value'],
                  'price_min' => $pmin > 0 ? (int)$pmin : null,
                  'price_max' => $pmax > 0 ? (int)$pmax : null,
                ];
                $chipQuery = array_filter($chipQuery);
                $chipUrl = APP_URL . '/' . ($chipQuery ? '?' . http_build_query($chipQuery) : '');
              ?>
                <a href="<?= $chipUrl ?>" class="time-chip <?= $isActive ? 'time-chip--active' : '' ?>">
                  <?= $tc['label'] ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Menus -->
          <div class="home-sidebar-menu">
            <a href="<?= APP_URL ?>/about.php">درباره ما</a>
            <span class="home-sidebar-menu__dot"></span>
            <a href="<?= APP_URL ?>/contact.php">تماس با ما</a>
            <span class="home-sidebar-menu__dot"></span>
            <a href="<?= APP_URL ?>/faq.php">سوالات متداول</a>
          </div>
          <div class="home-sidebar-menu">
            <a href="#">نصب اپلیکیشن</a>
            <span class="home-sidebar-menu__dot"></span>
            <a href="#">بلاگ</a>
            <span class="home-sidebar-menu__dot"></span>
            <a href="<?= APP_URL ?>/ai/chat">جستجوی هوشمند</a>
          </div>

          <!-- Social Networks (Real Links) -->
          <div class="home-sidebar-social">
            <a href="https://www.linkedin.com/company/swaapin" class="social-btn" target="_blank" rel="noopener" aria-label="LinkedIn">
              <i class="bi bi-linkedin"></i>
            </a>
            <a href="https://wa.me/989000000000?text=<?= urlencode('سلام. از سایت سواپین دیدن کردم...') ?>" class="social-btn" target="_blank" rel="noopener" aria-label="WhatsApp">
              <i class="bi bi-whatsapp"></i>
            </a>
            <a href="https://t.me/swaapin" class="social-btn" target="_blank" rel="noopener" aria-label="Telegram">
              <i class="bi bi-telegram"></i>
            </a>
            <a href="https://www.instagram.com/swaapin.ir" class="social-btn" target="_blank" rel="noopener" aria-label="Instagram">
              <i class="bi bi-instagram"></i>
            </a>
          </div>

          <!-- Enamad -->
          <a href="https://trustseal.enamad.ir/Verify.aspx?id=12345" class="home-sidebar-enamad" target="_blank" rel="noopener">
            <img src="<?= APP_URL ?>/src/img/enamad.png" alt="نماد اعتماد اینماد">
          </a>

        </div>
      </aside>

      <!-- Sidebar Mobile Collapsible Styles -->
      <style>
        @media (max-width: 768px) {
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
        @media (min-width: 769px) {
          .home-sidebar__mobile-toggle { display: none !important; }
        }
      </style>

      <script>
      (function () {
        var sidebar = document.getElementById('home-mobile-sidebar');
        var toggle  = document.getElementById('home-sidebar-mobile-toggle');
        if (!sidebar || !toggle) return;
        var MOBILE_MQ = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;
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

        <!-- Premium Listings Section (Active promotion plans) -->
        <?php if (!empty($premiumListings)): ?>
        <section class="home-listings-section" aria-label="اگهی‌های ویژه">
          <div class="home-section-heading home-section-heading--large mb-5">
            <h2>اگهی‌های ویژه</h2>
            <a href="<?= APP_URL ?>/listings/all.php" class="home-section-heading__link">
              مشاهده همه آگهی‌ها
            </a>
          </div>
          <div class="listings-rows-container">
            <div class="listings-row-wrapper">
              <button type="button" class="listings-slider-arrow listings-slider-arrow--next" data-target="listings-row-premium" aria-label="آگهی بعدی">
                <i class="bi bi-chevron-right"></i>
              </button>
              <div class="listings-scroll-row" id="listings-row-premium">
                <?php foreach (array_slice($premiumListings, 0, 20) as $l): ?>
                <div class="listings-scroll-card">
                  <?php include __DIR__ . '/includes/listing_card.php'; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="listings-slider-arrow listings-slider-arrow--prev" data-target="listings-row-premium" aria-label="آگهی قبلی">
                <i class="bi bi-chevron-left"></i>
              </button>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <!-- New Listings Section -->
        <section id="listings" class="home-listings-section" aria-label="فهرست آگهی‌ها">
          <div class="home-section-heading home-section-heading--large mb-5">
            <h2>جدیدترین آگهی‌ها</h2>
            <a href="<?= APP_URL ?>/listings/all.php" class="home-section-heading__link">
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
          <?php
          $showcaseListings = array_slice($listings, 0, 20);
          ?>
          <div class="listings-rows-container">
            <div class="listings-row-wrapper">
              <button type="button" class="listings-slider-arrow listings-slider-arrow--next" data-target="listings-row-1" aria-label="آگهی بعدی">
                <i class="bi bi-chevron-right"></i>
              </button>
              <div class="listings-scroll-row" id="listings-row-1">
                <?php foreach ($showcaseListings as $l): ?>
                <div class="listings-scroll-card">
                  <?php include __DIR__ . '/includes/listing_card.php'; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="listings-slider-arrow listings-slider-arrow--prev" data-target="listings-row-1" aria-label="آگهی قبلی">
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
        <section class="home-listings-section" aria-label="فروشگاه‌ها" style="margin-top:48px">
          <div class="home-section-heading home-section-heading--large mb-5">
            <h2>فروشگاه‌ها</h2>
            <a href="<?= APP_URL ?>/shops" class="home-section-heading__link">
              مشاهده همه
            </a>
          </div>
          <div class="listings-rows-container">
            <div class="listings-row-wrapper">
              <button type="button" class="listings-slider-arrow listings-slider-arrow--next" data-target="home-stores-row" aria-label="فروشگاه بعدی">
                <i class="bi bi-chevron-right"></i>
              </button>
              <div class="listings-scroll-row" id="home-stores-row">
                <?php foreach ($featuredStores as $store):
                  $name = $store['store_name'] ?: $store['name'];
                  $slug = $store['store_slug'];
                  $storeCity = $hStoresCityCol ? ($store['store_city'] ?: $store['city']) : ($store['city'] ?? '');
                  $bannerUrl = !empty($store['store_banner']) ? UPLOAD_URL . $store['store_banner'] : APP_URL . '/src/img/heropng.png';
                  $storeTypeValue = $hStoresTypeCol ? normalize_store_type($store['store_type'] ?? 'both') : 'both';
                  $shopUrl = APP_URL . '/shop/' . ($storeTypeValue === 'both' ? 'both' : $storeTypeValue) . '/' . h($slug);
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
    <div class="home-ai__inner home-ai__inner--triple">

      <!-- ستون سمت چپ: فرم تخمین قیمت -->
      <div class="home-ai__form-col" id="home-price-estimate">
        <div class="home-ai-form card" style="background: rgba(7,26,51,.03);">
          <div class="home-ai-form__header">
            <span class="home-ai-form__badge"><i class="bi bi-lightning-charge-fill"></i> دستیار هوشمند سواپین</span>
            <h3 class="home-ai-form__title">کالات چقدر می‌ارزه؟</h3>
          </div>

          <form id="home-price-estimate-form" class="home-ai-form__form" method="POST" novalidate>
            <?= csrf_input() ?>

            <!-- اسم کالا -->
            <div class="home-ai-form__field">
              <label class="home-ai-form__label" for="estimate_title">اسم کالا</label>
              <input type="text" id="estimate_title" name="title" class="form-control"
                     placeholder="مثال: آیفون ۱۵ پرو مکس ۵۱۲ گیگابایت"
                     required minlength="3" maxlength="150">
            </div>

            <!-- عکس کالا (اختیاری) -->
            <div class="home-ai-form__field">
              <label class="home-ai-form__label" for="estimate_image">عکس کالا <span style="font-weight:500;opacity:.7;display:inline-block;margin-right:4px">(اختیاری)</span></label>
              <label for="estimate_image" class="home-ai-form__upload">
                <input type="file" id="estimate_image" name="image" accept="image/*" style="display:none">
                <i class="bi bi-cloud-arrow-up-fill"></i>
                <span>عکس کالا رو آپلود کن</span>
              </label>
            </div>

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
          <img src="<?= APP_URL ?>/91880dba-8120-4b5d-a963-7010f5abad9a.png" alt="ربات هوش مصنوعی سواپین برای تخمین قیمت کالا" class="home-ai__phone home-ai__phone--center" loading="lazy">
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
            <i class="bi bi-robot"></i> دستیار AI
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
/* ---------- Home AI: Triple Column Layout ---------- */
.home-ai--triple { padding: var(--sp-8) 0 var(--sp-7); background: linear-gradient(180deg, #FFFFFF 0%, #EEF2FF 50%, #FFFFFF 100%); }
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
  .home-ai__content--right { grid-column: 1 / -1; }
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
  margin-top: 8px;
  padding-top: var(--sp-3);
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

  function getCsrfToken() {
    const inp = document.querySelector('input[name="csrf_token"]');
    return inp ? inp.value : '';
  }

  function withCsrfHeaders() {
    const token = getCsrfToken();
    const out = {};
    if (token) out['X-CSRF-Token'] = token;
    return out;
  }

  function appendCsrf(fd) {
    const token = getCsrfToken();
    if (token && !fd.has('csrf_token')) fd.append('csrf_token', token);
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
                note: String(fb.note || '') || 'ارزش‌گذاری با مدل پایه سواپین (حالت اضطراری).',
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

        const min = result.range_low || Math.round(result.value * 0.85);
        const max = result.range_high || Math.round(result.value * 1.15);
        const mid = result.value || Math.round((min + max) / 2);

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
      @media (max-width: 768px) {
        .trust-grid--compact[data-role="trust-slider"] {
          display: flex !important;
          overflow-x: auto;
          overflow-y: hidden;
          scroll-snap-type: x mandatory;
          -webkit-overflow-scrolling: touch;
          scrollbar-width: none;
          padding-bottom: 8px;
          margin-bottom: -8px;
          gap: 14px;
        }
        .trust-grid--compact[data-role="trust-slider"]::-webkit-scrollbar {
          display: none;
        }
        .trust-slider__item {
          flex: 0 0 82% !important;
          max-width: 82% !important;
          scroll-snap-align: center;
          scroll-snap-stop: always;
          min-height: 160px;
          margin-bottom: 0 !important;
        }
        .trust-slider__item:first-child { margin-right: 4px; }
        .trust-slider__item:last-child  { margin-left: 4px; }
      }
      @media (max-width: 420px) {
        .trust-slider__item { flex-basis: 86% !important; max-width: 86% !important; }
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

<?php render_footer(); ?>
