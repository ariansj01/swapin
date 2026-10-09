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
$city      = clean($_GET['city']      ?? '');
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
    $whereClauses[] = 'l.city LIKE ?';
    $params[] = "%{$city}%";
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

// ─── Featured Stores (homepage, page 1, no filters) ────────────────────────
$featuredStores = [];
if (!$search && !$catSlug && !$city && $page === 1) {
    $hSellerType = db_has_column('users', 'seller_type');
    $hStoreCity  = db_has_column('users', 'store_city');
    $hStoreType  = db_has_column('users', 'store_type');

    $hWhereParts = ['is_active = 1'];
    if ($hSellerType) {
        $hWhereParts[] = '(seller_type = "store" OR (store_name IS NOT NULL AND store_name != ""))';
    } else {
        $hWhereParts[] = '(store_name IS NOT NULL AND store_name != "")';
    }
    $hWhereParts[] = 'store_slug IS NOT NULL AND store_slug != ""';
    $hWhere = 'WHERE ' . implode(' AND ', $hWhereParts);

    $hCols = "id, name, store_name, store_slug, store_description, store_banner, avatar, rating, created_at";
    if ($hStoreType) $hCols .= ", store_type";
    if ($hStoreCity) $hCols .= ", store_city, city";
    else $hCols .= ", city";

    $featuredStores = DB::fetchAll(
        "SELECT {$hCols},
                (SELECT COUNT(*) FROM listings WHERE user_id = users.id AND status = 'active' AND review_status = 'approved') AS listings_count
         FROM users
         {$hWhere}
         ORDER BY listings_count DESC, rating DESC, created_at DESC
         LIMIT 8"
    );
    $hStoresTypeCol = $hStoreType;
    $hStoresCityCol = $hStoreCity;
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
  <div class="container hero__inner">
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

    <!-- ===== Listings & Stores ===== -->
    <div class="home-main-layout" id="home-sliders-area">

      <!-- ===== Sidebar (Beside Sliders) ===== -->
      <aside class="home-sidebar card" aria-label="دسته‌بندی‌ها، فیلترها و منوها">
        <div class="home-sidebar__inner">

          <!-- Title + Count -->
          <div class="home-sidebar__header">
            <h3 class="home-sidebar__title">دسته‌بندی‌های محبوب</h3>
            <span class="home-sidebar__count"><?= fmt_num($total) ?> دسته</span>
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
                <i class="bi bi-geo-alt"></i> همه شهرها
              </label>
              <select id="sidebar-city-select" name="city" class="form-control">
                <option value="">همه شهرها</option>
                <?= render_city_options($city) ?>
              </select>
            </div>

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

      <!-- ===== Main Content (Only Sliders: Listings + Stores) ===== -->
      <div class="home-main-content">

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

        <!-- 4 Steps Cards — چطور معامله کنیم -->
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
          .home-stores-scroll {
            display: flex;
            gap: 1.25rem;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            padding: 4px 8px 16px 8px;
            scrollbar-width: none;
          }
          .home-stores-scroll::-webkit-scrollbar { height: 8px; }
          .home-stores-scroll::-webkit-scrollbar-track { background: transparent; }
          .home-stores-scroll::-webkit-scrollbar-thumb { background: #D1D5DB; border-radius: 999px; }
          .home-stores-scroll::-webkit-scrollbar-thumb:hover { background: #9CA3AF; }
          .home-stores-card {
            flex: 0 0 auto;
            width: 320px;
          }
          @media (max-width: 768px) {
            .home-stores-card { width: calc(100% - 48px); }
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
              <div class="home-stores-scroll" id="home-stores-row">
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
                <div class="home-stores-card">
                  <article class="shop-card card">
                    <a href="<?= $shopUrl ?>" class="shop-card__banner-wrap">
                      <img src="<?= h($bannerUrl) ?>" alt="<?= h($name) ?>" class="shop-card__banner" loading="lazy">
                    </a>
                    <div class="shop-card__body">
                      <div class="shop-card__profile">
                        <?= avatar_html(null, $name, 'md') ?>
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
<section class="home-section home-ai" aria-label="ارزش‌گذاری و مشاوره معاوضه با AI">
  <div class="container">
    <div class="home-ai__inner">
      <div class="home-ai__visual" aria-hidden="false">
        <div class="home-ai__phone-wrap">
          <div class="home-ai__phone-blob" aria-hidden="true"></div>
          <!-- <img src="<?= APP_URL ?>/src/img/583fde7d-1ca3-4763-9c00-8ec1b68bfaf3.png" alt="نمایش ارزش‌گذاری هوشمند سواَپین در موبایل" class="home-ai__phone" loading="lazy"> -->
          <img src="<?= APP_URL ?>/src/img/171277459.png" alt="نمایش ارزش‌گذاری هوشمند سواَپین در موبایل" class="home-ai__phone" loading="lazy">
        </div>
      </div>
      <div class="home-ai__content">
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

<section class="home-section home-trust home-trust--compact">
  <div class="container">
    <div class="trust-grid trust-grid--compact">
      <?php
      $trust = [
          ['bi-star-fill',        'امتیاز و نظرات',    'بعد از هر مبادله، طرفین به هم امتیاز می‌دهند و پروفایل اعتماد ساخته می‌شود.'],
          ['bi-patch-check-fill', 'احراز هویت',        'سطح تأیید تلفن و هویت در پروفایل هر کاربر نمایش داده می‌شود.'],
          ['bi-clock-history',    'تاریخچه معاملات',   'سوابق مبادلات انجام‌شده برای شفافیت در پروفایل قابل مشاهده است.'],
          ['bi-shield-lock',      'پیام‌رسانی امن',     'گفتگوی مستقیم داخل پلتفرم قبل از نهایی کردن معامله.'],
      ];
      foreach ($trust as [$icon, $title, $desc]):
      ?>
      <article class="trust-card trust-card--compact">
        <div class="trust-card__icon trust-card__icon--compact"><i class="bi <?= $icon ?>"></i></div>
        <div class="trust-card__body">
          <h3 class="trust-card__title"><?= $title ?></h3>
          <p class="trust-card__desc"><?= $desc ?></p>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php render_footer(); ?>
