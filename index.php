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
$whereClauses = [listing_public_sql('l'), 'l.listing_mode != "sell"'];
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
    'old'   => 'l.created_at ASC',
    'value' => 'l.estimated_value DESC',
    default => '(l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC',
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
    "SELECT COUNT(*) AS c FROM listings l JOIN categories c ON c.id = l.category_id $where",
    $params
);
$total = (int)($totalRow['c'] ?? 0);
$pag   = paginate($total, LISTINGS_PER_PAGE, $page);

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
    [...$params, LISTINGS_PER_PAGE, $pag['offset']]
);

$cities = iran_cities();

$homeMetaTitle = swapin_content_get('home_meta_title');
$homeMetaDesc  = swapin_content_get('home_meta_desc');

render_head($homeMetaTitle, $homeMetaDesc, [
    'canonical' => APP_URL . '/',
    'og_type'   => 'website',
    'og_image'  => APP_URL . '/src/img/heropng.png',
    'keywords'  => 'مبادله کالا, تعویض کالا, بازار مبادله, سواپین, معاوضه',
    'json_ld'   => [seo_json_ld_website(), seo_json_ld_organization()],
]);
render_navbar($user);
?>

<?php if ($user && isset($_GET['welcome'])): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container">
    <!-- <i class="bi bi-stars"></i>
    <strong>به <?= APP_NAME ?> خوش آمدید!</strong> مبلغ <strong><?= fmt_num(WELCOME_BONUS) ?> <?= CREDIT_UNIT ?></strong> به عنوان پاداش خوش‌آمدگویی به کیف پول شما اضافه شد. با ثبت اولین آگهی شروع کنید! -->
  </div>
</div>
<?php endif; ?>

<?php if (!$search && !$catSlug && $page === 1): ?>
<section class="hero">
  <div class="container hero__inner">
    <div class="hero__content">
      <h1 class="hero__title">
        <span class="hero__line"><?= h(swapin_content_get('hero_title_line_1')) ?></span>
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
    <div class="hero__visual">
      <img src="<?= APP_URL ?>/src/img/heropng.png" alt="مبادله هوشمند کالا در <?= APP_NAME ?>" class="hero__img" loading="eager">
    </div>
  </div>
</section>
<?php endif; ?>

<main id="main-content" class="section-sm">
  <div class="container">

    <!-- Category strip -->
    <nav class="mb-5" aria-label="دسته‌بندی‌ها">
      <?php render_categories_strip($catId); ?>
    </nav>

    <!-- Filter bar -->
    <form class="filter-bar mb-6" role="search" aria-label="جستجو و فیلتر آگهی‌ها" onsubmit="return false">
      <div style="flex:1;min-width:200px;position:relative">
        <label for="search-input" class="visually-hidden">جستجوی آگهی‌ها</label>
        <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted)" aria-hidden="true"></i>
        <input type="search" class="form-control" style="padding-left:40px;height:40px"
               id="search-input" name="q" placeholder="جستجوی آگهی‌ها…"
               value="<?= h($search) ?>">
      </div>

      <label for="city-filter" class="visually-hidden">شهر</label>
      <select class="form-control" id="city-filter" name="city" style="width:auto;min-width:140px;height:50px">
        <option value="">همه شهرها</option>
        <?= render_city_options($city) ?>
      </select>

      <label for="want-filter" class="visually-hidden">نوع مبادله</label>
      <select class="form-control" id="want-filter" name="want" style="width:auto;min-width:150px;height:50px">
        <option value="item"    <?= $wantType === 'item'    ? 'selected' : '' ?>>کالا با کالا</option>
        <option value="service" <?= $wantType === 'service' ? 'selected' : '' ?>>خدمات</option>
        <option value="credit"  <?= $wantType === 'credit'  ? 'selected' : '' ?>>اعتبار</option>
      </select>

      <label for="sort-filter" class="visually-hidden">مرتب‌سازی</label>
      <select class="form-control" id="sort-filter" name="sort" style="width:auto;min-width:130px;height:50px">
        <option value="new"   <?= $sort === 'new'   ? 'selected' : '' ?>>جدیدترین</option>
        <option value="old"   <?= $sort === 'old'   ? 'selected' : '' ?>>قدیمی‌ترین</option>
        <option value="value" <?= $sort === 'value' ? 'selected' : '' ?>>بالاترین ارزش</option>
      </select>
    </form>

    <?php if ($category): ?>
    <header class="d-flex align-center gap-3 mb-5">
      <h2 style="font-size:1.25rem"><?= h(category_label($category['slug'], $category['name'])) ?></h2>
      <span class="badge badge-primary"><?= $total ?> آگهی</span>
      <a href="<?= APP_URL ?>/" style="margin-inline-start:auto;font-size:.875rem"><i class="bi bi-x"></i> پاک کردن</a>
    </header>
    <?php elseif ($search): ?>
    <header class="d-flex align-center gap-3 mb-5">
      <h2 style="font-size:1.25rem">نتایج برای «<?= h($search) ?>»</h2>
      <span class="badge badge-primary"><?= $total ?> مورد یافت شد</span>
    </header>
    <?php endif; ?>

    <!-- Listings grid -->
    <section id="listings" aria-label="فهرست آگهی‌ها">
    <?php if (empty($listings)): ?>
    <div class="empty-state">
      <i class="bi bi-search"></i>
      <h3>آگهی‌ای یافت نشد</h3>
      <p>فیلترها را تغییر دهید یا اولین نفری باشید که در این دسته آگهی ثبت می‌کند!</p>
      <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary">ثبت آگهی</a>
    </div>
    <?php else: ?>
    <div class="listings-grid" id="listings-grid">
      <?php foreach ($listings as $l): ?>
      <?php include __DIR__ . '/includes/listing_card.php'; ?>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($pag['pages'] > 1): ?>
    <nav aria-label="صفحه‌بندی آگهی‌ها" style="display:flex;justify-content:center;align-items:center;gap:var(--sp-2);margin-top:var(--sp-10)">
      <?php if ($pag['has_prev']): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>" class="btn btn-outline btn-sm">
          <i class="bi bi-chevron-right"></i> قبلی
        </a>
      <?php endif; ?>
      <?php
        $start = max(1, $page - 2);
        $end   = min($pag['pages'], $page + 2);
        for ($p = $start; $p <= $end; $p++):
          $cls = $p === $page ? 'btn-primary' : 'btn-outline';
      ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="btn <?= $cls ?> btn-sm"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($pag['has_next']): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>" class="btn btn-outline btn-sm">
          بعدی <i class="bi bi-chevron-left"></i>
        </a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
    </section>

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

<section class="home-section home-steps">
  <div class="container">
    <div class="home-section__header">
      <h2>چطور کار می‌کند؟</h2>
      <p>در چهار مرحله ساده، کالای خود را با آنچه نیاز دارید مبادله کنید</p>
    </div>
    <div class="steps-grid">
      <?php
      $steps = [
          ['bi-plus-circle',    '۱. ثبت آگهی',     'کالا یا خدمت خود را ثبت کنید، ارزش تقریبی بگذارید و بگویید چه چیزی می‌خواهید.'],
          ['bi-search',         '۲. جستجو و کشف',  'بین آگهی‌ها بگردید. با دسته‌بندی، شهر و نوع مبادله فیلتر کنید.'],
          ['bi-chat-dots',      '۳. پیشنهاد دهید', 'کالای خود را پیشنهاد دهید، پیام بفرستید و جزئیات را هماهنگ کنید.'],
          ['bi-check2-all',     '۴. معامله کنید',  'هر دو طرف تأیید می‌کنند. امتیازدهی اعتماد را برای دفعات بعد می‌سازد.'],
      ];
      foreach ($steps as [$icon, $title, $desc]):
      ?>
      <article class="step-card">
        <div class="step-card__icon"><i class="bi <?= $icon ?>"></i></div>
        <h3><?= $title ?></h3>
        <p><?= $desc ?></p>
      </article>
      <?php endforeach; ?>
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
