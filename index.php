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
$search   = clean($_GET['q']     ?? '');
$catSlug  = clean($_GET['cat']   ?? '');
$city     = clean($_GET['city']  ?? '');
$wantType = clean($_GET['want']  ?? '');
$sort     = in_array($_GET['sort'] ?? '', ['new','old','value']) ? $_GET['sort'] : 'new';
$page     = max(1, (int)($_GET['page'] ?? 1));

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
<section class="hero">
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
      <div class="hero__stats">
        <div class="hero__stat">
          <div class="hero__stat-value"><?= fmt_num($total) ?>+</div>
          <div class="hero__stat-label">آگهی فعال</div>
        </div>
        <div class="hero__stat">
          <div class="hero__stat-value"><?= fmt_num((int)(DB::fetch('SELECT COUNT(*) AS c FROM trades WHERE status="completed"')['c'] ?? 0)) ?>+</div>
          <div class="hero__stat-label">مبادله انجام‌شده</div>
        </div>
        <div class="hero__stat">
          <div class="hero__stat-value"><?= fmt_num((int)(DB::fetch('SELECT COUNT(*) AS c FROM users WHERE is_active=1')['c'] ?? 0)) ?>+</div>
          <div class="hero__stat-label">عضو</div>
        </div>
      </div>
    </div>
  </div>
  <div class="container home-assurance">
    <dl class="site-footer__stats home-assurance__stats">
      <div class="site-footer__stat">
        <i class="bi bi-shield-lock site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dt class="site-footer__stat-value">اتاق امن معامله</dt>
          <dd class="site-footer__stat-lable">معامله مطمئن و امن</dd>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-person-check site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dt class="site-footer__stat-value">احراز هویت کاربران</dt>
          <dd class="site-footer__stat-lable">برای تجربه ای امن و ایمن</dd>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-chat-dots site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dt class="site-footer__stat-value">پشتیبانی آنلاین</dt>
          <dd class="site-footer__stat-lable">همراه شما در هر مرحله</dd>
        </div>
      </div>
      <div class="site-footer__stat">
        <i class="bi bi-trophy site-footer__stat-icon" aria-hidden="true"></i>
        <div class="site-footer__stat-body">
          <dd class="site-footer__stat-value">توسط کاربران سواَپین</dd>
          <dt class="site-footer__stat-lable">هزاران معامله موفق</dt>
        </div>
      </div>
    </dl>
  </div>
</section>
<?php endif; ?>

<main id="main-content" class="section-sm">
  <div class="container">

    <!-- Category strip -->
    <header class="home-section-heading mb-3" aria-label="سرفصل دسته‌بندی‌ها">
      <h2>دسته‌بندی‌های محبوب</h2>
      <a href="<?= APP_URL ?>/listings/all.php" class="home-section-heading__link">
        مشاهده همه
      </a>
    </header>
    <nav class="mb-5" aria-label="دسته‌بندی‌ها">
      <?php render_categories_strip($catId); ?>
    </nav>

    <!-- Filter bar -->
    <form class="filter-bar home-filter-bar mb-6" role="search" aria-label="جستجو و فیلتر آگهی‌ها" onsubmit="return false">
      <div class="home-filter-search">
        <label for="search-input" class="visually-hidden">جستجوی آگهی‌ها</label>
        <i class="bi bi-search home-filter-search__icon" aria-hidden="true"></i>
        <input type="search" class="form-control home-filter-control home-filter-control--search"
               id="search-input" name="q" placeholder="جستجوی کالا"
               value="<?= h($search) ?>">
      </div>

      <label for="city-filter" class="visually-hidden">شهر</label>
      <select class="form-control home-filter-control" id="city-filter" name="city">
        <option value="">همه شهرها</option>
        <?= render_city_options($city) ?>
      </select>

      <label for="want-filter" class="visually-hidden">نوع مبادله</label>
      <select class="form-control home-filter-control" id="want-filter" name="want">
        <option value="item"    <?= $wantType === 'item'    ? 'selected' : '' ?>>کالا با کالا</option>
        <option value="service" <?= $wantType === 'service' ? 'selected' : '' ?>>خدمات</option>
        <option value="credit"  <?= $wantType === 'credit'  ? 'selected' : '' ?>>اعتبار</option>
      </select>

      <label for="sort-filter" class="visually-hidden">مرتب‌سازی</label>
      <select class="form-control home-filter-control" id="sort-filter" name="sort">
        <option value="new"   <?= $sort === 'new'   ? 'selected' : '' ?>>جدیدترین</option>
        <option value="old"   <?= $sort === 'old'   ? 'selected' : '' ?>>قدیمی‌ترین</option>
        <option value="value" <?= $sort === 'value' ? 'selected' : '' ?>>بالاترین ارزش</option>
      </select>
    </form>

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

    <!-- New Listings Section -->
    <section class="home-section home-steps" id="home-steps">
      <div class="container">
        <div class="home-section__header home-steps__header">
          <span class="home-steps__eyebrow">مسیر ساده معامله</span>
          <h2>چطور در سواَپین معامله کنیم؟</h2>
          <p>فقط در چهار مرحله ساده کالای خود را با دیگران معامله کنید.</p>
        </div>
        <div class="steps-grid" aria-label="مراحل معامله در سواَپین">
          <?php
          $steps = [
              ['۱', 'ثبت آگهی', 'از کالای خود عکس بگیرید، توضیحات بنویسید و آگهی را ثبت کنید.', 'bi-phone', 'bi-plus-lg', 'آگهی شما در چند دقیقه آماده نمایش است.'],
              ['۲', 'دریافت پیشنهاد', 'کاربران دیگر برای کالای شما پیشنهادهای معاوضه ارسال می‌کنند.', 'bi-chat-dots', 'bi-send', 'همه پیشنهادها یک‌جا و شفاف نمایش داده می‌شوند.'],
              ['۳', 'توافق با طرف مقابل', 'از طریق گفتگو درباره شرایط معامله به توافق برسید.', 'bi-people', 'bi-check2-circle', 'جزئیات معامله را قبل از نهایی‌سازی هماهنگ کنید.'],
              ['۴', 'انجام معامله', 'در مکان امن ملاقات کرده و کالای خود را با طرف مقابل معاوضه کنید.', 'bi-box-seam', 'bi-gift', 'تجربه‌ای سریع، مطمئن و حرفه‌ای تا پایان معامله.'],
          ];
          foreach ($steps as $index => [$stepNo, $title, $desc, $icon, $iconBadge, $caption]):
          ?>
          <article class="step-card" style="--step-delay: <?= $index ?>;">
            <div class="step-card__top">
              <span class="step-card__number"><?= $stepNo ?></span>
              <div class="step-card__icon-wrap">
                <div class="step-card__icon">
                  <i class="bi <?= $icon ?>"></i>
                </div>
                <?php if ($iconBadge): ?>
                <span class="step-card__icon-badge" style="display: none;" aria-hidden="true"><i class="bi <?= $iconBadge ?>"></i></span>
                <?php endif; ?>
              </div>
              <!-- <i class="bi bi-arrow-right step-arrow" aria-hidden="true"></i> -->
            </div>
            <div class="step-card__body">
              <span class="step-card__label">مرحله <?= $stepNo ?></span>
              <h3><?= $title ?></h3>
              <p><?= $desc ?></p>
            </div>
            <div class="step-card__footer"><?= $caption ?></div>
          </article>
          <?php endforeach; ?>
        </div>
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
        <!-- Single Row with 20 Listings (4 visible at a time) -->
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
              $shopUrl = APP_URL . '/shop/' . h($slug);
              $storeTypeValue = $hStoresTypeCol ? normalize_store_type($store['store_type'] ?? 'both') : 'both';
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
                    <?php if (!empty($store['store_banner'])): ?>
                      <img src="<?= h(UPLOAD_URL . $store['store_banner']) ?>" alt="<?= h($name) ?>" class="avatar avatar--md" style="object-fit:cover;border:2px solid var(--surface, #fff)">
                    <?php else: ?>
                      <?= avatar_html($store['avatar'] ?? null, $name, 'md') ?>
                    <?php endif; ?>
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

  </div>
</main>

<?php if ($page === 1): ?>
<section class="home-section home-ai">
  <div class="container">
    <div class="home-ai__inner">
      <div class="home-ai__content">
        <span class="home-ai__badge"><i class="bi bi-stars"></i><?= h(swapin_content_get('home_ai_badge')) ?></span>
        <h2><?= h(swapin_content_get('home_ai_title')) ?></h2>
        <p><?= h(swapin_content_get('home_ai_desc')) ?></p>
        <div class="home-ai__actions">
          <a href="<?= APP_URL ?>/listings/create" class="btn btn-accent btn-lg">
            <i class="bi bi-stars"></i> <?= h(swapin_content_get('home_ai_primary_cta')) ?>
          </a>
          <a href="<?= APP_URL ?>/ai/chat" class="btn btn-hero-outline btn-lg">
            <i class="bi bi-robot"></i> <?= h(swapin_content_get('home_ai_secondary_cta')) ?>
          </a>
        </div>
      </div>
      <div class="home-ai__visual">
        <div class="home-ai__card">
          <div class="home-ai__card-row"><i class="bi bi-check2-circle"></i> تحلیل وضعیت و دسته‌بندی</div>
          <div class="home-ai__card-row"><i class="bi bi-check2-circle"></i> مقایسه با بازار معاوضه</div>
          <div class="home-ai__card-row"><i class="bi bi-check2-circle"></i> پیشنهاد ارزش <?= CREDIT_UNIT ?></div>
          <div class="home-ai__card-value">~ ۱۲,۵۰۰,۰۰۰ <?= CREDIT_UNIT ?></div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="home-section home-trust">
  <div class="container">
    <div class="home-section__header">
      <h2>اعتماد و اعتبار کاربران</h2>
      <p>زیرساخت‌هایی که معامله امن و شفاف را ممکن می‌کنند</p>
    </div>
    <div class="trust-grid">
      <?php
      $trust = [
          ['bi-star-fill',        'امتیاز و نظرات',    'بعد از هر مبادله، طرفین به هم امتیاز می‌دهند و پروفایل اعتماد ساخته می‌شود.'],
          ['bi-patch-check-fill', 'احراز هویت',        'سطح تأیید تلفن و هویت در پروفایل هر کاربر نمایش داده می‌شود.'],
          ['bi-clock-history',    'تاریخچه معاملات',   'سوابق مبادلات انجام‌شده برای شفافیت در پروفایل قابل مشاهده است.'],
          ['bi-shield-lock',      'پیام‌رسانی امن',     'گفتگوی مستقیم داخل پلتفرم قبل از نهایی کردن معامله.'],
      ];
      foreach ($trust as [$icon, $title, $desc]):
      ?>
      <article class="trust-card">
        <div class="trust-card__icon"><i class="bi <?= $icon ?>"></i></div>
        <h3><?= $title ?></h3>
        <p><?= $desc ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php render_footer(); ?>
