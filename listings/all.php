<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/i18n.php';

$user = auth_user();

// فیلترها
$search    = clean($_GET['q']          ?? '');
$catSlug   = clean($_GET['cat']        ?? '');
$city      = clean($_GET['city']       ?? '');
$wantType  = clean($_GET['want']       ?? '');
$condition = clean($_GET['condition']  ?? '');
$pmin      = (int)($_GET['price_min']  ?? 0);
$pmax      = (int)($_GET['price_max']  ?? 0);
$sort      = in_array($_GET['sort'] ?? '', ['new','old','value']) ? $_GET['sort'] : 'new';
$page      = max(1, (int)($_GET['page'] ?? 1));

// دسته‌بندی
$category = $catSlug ? DB::fetch('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [$catSlug]) : null;
$catId    = $category['id'] ?? null;

// ساخت شرط‌ها
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
if ($condition) {
    $whereClauses[] = 'l.condition = ?';
    $params[] = $condition;
}
if ($pmin > 0) {
    $whereClauses[] = 'l.estimated_value >= ?';
    $params[] = $pmin;
}
if ($pmax > 0 && $pmax >= $pmin) {
    $whereClauses[] = 'l.estimated_value <= ?';
    $params[] = $pmax;
}

$where   = 'WHERE ' . implode(' AND ', $whereClauses);
$orderBy = match($sort) {
    'old'   => 'l.created_at ASC',
    'value' => 'l.estimated_value DESC',
    default => '(l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC',
};

// شمارش و دریافت داده
$totalRow = DB::fetch(
    "SELECT COUNT(*) AS c FROM listings l JOIN categories c ON c.id = l.category_id {$where}",
    $params
);
$total = (int)($totalRow['c'] ?? 0);
$perPage = 24;
$pag = paginate($total, $perPage, $page);

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
    [...$params, $perPage, $pag['offset']]
);

// متادیتا
$title = 'همه آگهی‌ها';
if ($category) $title = 'آگهی‌های ' . category_label($category['slug'], $category['name']);
if ($search)   $title = 'نتایج برای «' . $search . '»';
$desc = 'فهرست کامل آگهی‌ها با فیلتر بر اساس دسته‌بندی، شهر، وضعیت و قیمت';

render_head($title, $desc, [
  'canonical' => APP_URL . '/listings/all.php',
  'og_type'   => 'website',
  'og_image'  => APP_URL . '/src/img/heropng.png',
]);
render_navbar($user);
?>

<main class="section-sm">
  <div class="container all-listings-layout">
    <!-- Sidebar -->
    <aside aria-label="فیلترها" class="all-listings-sidebar">
      <div class="card all-listings-filter-card">
        <h2 class="all-listings-sidebar__title">دسته‌بندی‌ها</h2>
        <ul class="all-listings-categories">
          <li>
            <a href="<?= APP_URL ?>/listings/all.php" class="<?= $catSlug === '' ? 'text-strong' : '' ?>"><i class="bi bi-grid"></i> همه</a>
          </li>
          <?php foreach (DB::fetchAll('SELECT * FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order') as $c): ?>
          <?php $active = $catSlug === $c['slug'] ? 'text-strong' : ''; ?>
          <li>
            <a href="<?= APP_URL ?>/listings/all.php?cat=<?= h($c['slug']) ?>" class="<?= $active ?>"><i class="<?= h($c['icon']) ?>"></i> <?= h(category_label($c['slug'], $c['name'])) ?></a>
          </li>
          <?php endforeach; ?>
        </ul>

        <h3 class="all-listings-sidebar__subtitle">فیلترهای دیگر</h3>
        <form method="GET" action="<?= APP_URL ?>/listings/all.php" class="all-listings-filters">
          <input type="hidden" name="cat" value="<?= h($catSlug) ?>">
          <?php if ($search): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?>

          <label class="fs-xs" for="city">شهر</label>
          <select id="city" name="city" class="form-control">
            <option value="">همه شهرها</option>
            <?= render_city_options($city) ?>
          </select>

          <label class="fs-xs" for="want">نوع معامله</label>
          <select id="want" name="want" class="form-control">
            <option value="">همه</option>
            <option value="item"    <?= $wantType === 'item' ? 'selected' : '' ?>>کالا</option>
            <option value="service" <?= $wantType === 'service' ? 'selected' : '' ?>>خدمات</option>
            <option value="credit"  <?= $wantType === 'credit' ? 'selected' : '' ?>>اعتبار</option>
          </select>

          <label class="fs-xs" for="condition">وضعیت کالا</label>
          <select id="condition" name="condition" class="form-control">
            <option value="">همه</option>
            <?php foreach (['new','like_new','good','fair','poor'] as $c): ?>
            <option value="<?= h($c) ?>" <?= $condition === $c ? 'selected' : '' ?>><?= h(condition_label($c)) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="all-listings-price-grid">
            <div>
              <label class="fs-xs" for="price_min">حداقل قیمت</label>
              <input type="number" id="price_min" name="price_min" class="form-control" value="<?= $pmin > 0 ? (int)$pmin : '' ?>" min="0" step="1000" inputmode="numeric">
            </div>
            <div>
              <label class="fs-xs" for="price_max">حداکثر قیمت</label>
              <input type="number" id="price_max" name="price_max" class="form-control" value="<?= $pmax > 0 ? (int)$pmax : '' ?>" min="0" step="1000" inputmode="numeric">
            </div>
          </div>

          <div>
            <label class="fs-xs" for="sort">مرتب‌سازی</label>
            <select id="sort" name="sort" class="form-control">
              <option value="new"   <?= $sort === 'new'   ? 'selected' : '' ?>>جدیدترین</option>
              <option value="old"   <?= $sort === 'old'   ? 'selected' : '' ?>>قدیمی‌ترین</option>
              <option value="value" <?= $sort === 'value' ? 'selected' : '' ?>>بالاترین ارزش</option>
            </select>
          </div>

          <button type="submit" class="btn btn-primary">
            <i class="bi bi-funnel"></i> اعمال فیلتر
          </button>
        </form>
      </div>
    </aside>

    <!-- Results -->
    <section aria-label="همه آگهی‌ها" class="all-listings-results">
      <header class="all-listings-results__header d-flex align-center mb-5">
        <h1 class="all-listings-results__title">
          <?= h($title) ?>
        </h1>
        <span class="badge badge-primary"><?= fmt_num($total) ?> آگهی</span>
      </header>

      <?php if (empty($listings)): ?>
      <div class="empty-state">
        <i class="bi bi-search"></i>
        <h3>آگهی‌ای یافت نشد</h3>
        <p>فیلترها را تغییر دهید یا اولین نفری باشید که آگهی ثبت می‌کند!</p>
        <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary">ثبت آگهی</a>
      </div>
      <?php else: ?>
      <div class="all-listings-grid">
        <?php foreach ($listings as $l): ?>
        <div>
          <?php include __DIR__ . '/../includes/listing_card.php'; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($pag['pages'] > 1): ?>
      <nav class="pagination mt-6" aria-label="صفحه‌بندی">
        <?php for ($p = 1; $p <= $pag['pages']; $p++): ?>
          <?php
          $qs = $_GET;
          $qs['page'] = $p;
          $href = APP_URL . '/listings/all.php?' . http_build_query($qs);
          $cls = $p === $pag['page'] ? 'active' : '';
          ?>
          <a href="<?= h($href) ?>" class="page-link <?= $cls ?>"><?= fmt_num($p) ?></a>
        <?php endfor; ?>
      </nav>
      <?php endif; ?>
    </section>
  </div>
</main>

<?php render_footer(); ?>
