<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();
$search = clean($_GET['q'] ?? '');
$city   = clean($_GET['city'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 18;

$hasSellerType = db_has_column('users', 'seller_type');
$hasStoreCity  = db_has_column('users', 'store_city');

$whereParts = ['is_active = 1'];
$params = [];

if ($hasSellerType) {
    $whereParts[] = '(seller_type = "store" OR (store_name IS NOT NULL AND store_name != ""))';
} else {
    $whereParts[] = '(store_name IS NOT NULL AND store_name != "")';
}

if ($search) {
    $whereParts[] = '(store_name LIKE ? OR name LIKE ? OR store_description LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$cityCol = $hasStoreCity ? 'COALESCE(store_city, city)' : 'city';
if ($city) {
    $whereParts[] = "{$cityCol} LIKE ?";
    $params[] = "%{$city}%";
}

$whereParts[] = 'store_slug IS NOT NULL AND store_slug != ""';
$where = 'WHERE ' . implode(' AND ', $whereParts);

$total = (int)(DB::fetch("SELECT COUNT(*) AS c FROM users {$where}", $params)['c'] ?? 0);
$pag   = paginate($total, $perPage, $page);

$selectCols = "id, name, store_name, store_slug, store_description, store_banner, avatar,
               store_address, store_phone, rating, created_at";
if ($hasStoreCity) {
    $selectCols .= ", store_city, city";
} else {
    $selectCols .= ", city";
}

$stores = DB::fetchAll(
    "SELECT {$selectCols},
            (SELECT COUNT(*) FROM listings WHERE user_id = users.id AND status = 'active' AND review_status = 'approved') AS listings_count
     FROM users
     {$where}
     ORDER BY listings_count DESC, rating DESC, created_at DESC
     LIMIT ? OFFSET ?",
    [...$params, $perPage, $pag['offset']]
);

render_head('فهرست فروشگاه‌ها | ' . APP_NAME, 'فروشگاه‌های ثبت‌شده در سواَپین — خرید نقدی و معاوضه', [
    'canonical' => APP_URL . '/shops',
    'og_type'   => 'website',
]);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/shops.css?v=<?= @filemtime(__DIR__ . '/../src/css/shops.css') ?: time() ?>">

<main class="section-sm" id="main-content">
  <div class="container">
    <header class="shops-page-head">
      <div>
        <span class="shops-page-head__badge"><i class="bi bi-shop"></i> فروشگاه‌های سواَپین</span>
        <h1>فهرست فروشگاه‌ها</h1>
        <p>فروشگاه‌های معتبر با امکان خرید نقدی و معاوضه — <?= fmt_num($total) ?> فروشگاه</p>
      </div>
    </header>

    <form method="GET" class="shops-filter-bar card mb-6">
      <div class="card-body shops-filter-bar__inner">
        <div class="shops-filter-field">
          <label for="shops-q">جستجوی فروشگاه</label>
          <input type="search" id="shops-q" name="q" class="form-control" value="<?= h($search) ?>" placeholder="نام فروشگاه…">
        </div>
        <div class="shops-filter-field">
          <label for="shops-city">شهر</label>
          <select id="shops-city" name="city" class="form-control">
            <option value="">همه شهرها</option>
            <?= render_city_options($city) ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> جستجو</button>
      </div>
    </form>

    <?php if (empty($stores)): ?>
    <div class="shops-empty card">
      <div class="card-body text-center py-8">
        <i class="bi bi-shop-window shops-empty__icon"></i>
        <h2>فروشگاهی یافت نشد</h2>
        <p class="text-muted">فیلترها را تغییر دهید یا بعداً دوباره سر بزنید.</p>
        <a href="<?= APP_URL ?>/store/request" class="btn btn-accent mt-4">ثبت درخواست فروشگاه</a>
      </div>
    </div>
    <?php else: ?>
    <div class="shops-grid">
      <?php foreach ($stores as $store):
        $name = $store['store_name'] ?: $store['name'];
        $slug = $store['store_slug'];
        $storeCity = $hasStoreCity ? ($store['store_city'] ?: $store['city']) : ($store['city'] ?? '');
        $bannerUrl = !empty($store['store_banner']) ? UPLOAD_URL . $store['store_banner'] : APP_URL . '/src/img/heropng.png';
        $shopUrl = APP_URL . '/shop/' . h($slug);
      ?>
      <article class="shop-card card">
        <a href="<?= $shopUrl ?>" class="shop-card__banner-wrap">
          <img src="<?= h($bannerUrl) ?>" alt="<?= h($name) ?>" class="shop-card__banner" loading="lazy">
        </a>
        <div class="shop-card__body">
          <div class="shop-card__profile">
            <?= avatar_html($store['avatar'] ?? null, $name, 'md') ?>
            <div>
              <h2 class="shop-card__name"><a href="<?= $shopUrl ?>"><?= h($name) ?></a></h2>
              <?php if ($storeCity): ?>
              <div class="shop-card__city"><i class="bi bi-geo-alt"></i> <?= h($storeCity) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!empty($store['store_description'])): ?>
          <p class="shop-card__desc"><?= h(mb_strimwidth($store['store_description'], 0, 100, '…')) ?></p>
          <?php endif; ?>
          <div class="shop-card__meta">
            <span><i class="bi bi-box-seam"></i> <?= fmt_num((int)$store['listings_count']) ?> محصول</span>
            <?php if ((float)($store['rating'] ?? 0) > 0): ?>
            <span><i class="bi bi-star-fill"></i> <?= number_format((float)$store['rating'], 1) ?></span>
            <?php endif; ?>
          </div>
          <a href="<?= $shopUrl ?>" class="btn btn-primary btn-sm w-100">مشاهده فروشگاه</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <?php if ($pag['pages'] > 1): ?>
    <nav class="pagination-wrap mt-6" aria-label="صفحه‌بندی">
      <?php
      $base = APP_URL . '/shops?' . http_build_query(array_filter([
          'q'    => $search ?: null,
          'city' => $city ?: null,
      ]));
      for ($i = 1; $i <= $pag['pages']; $i++):
        $active = $i === $pag['page'] ? 'is-active' : '';
      ?>
      <a href="<?= $base . '&page=' . $i ?>" class="pagination-btn <?= $active ?>"><?= $i ?></a>
      <?php endfor; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</main>

<?php
render_footer();
