<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$page = max(1, (int)($_GET['page'] ?? 1));

$total = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM saved_listings s
     JOIN listings l ON l.id = s.listing_id
     WHERE s.user_id = ? AND ' . listing_public_sql('l'),
    [$user['id']]
)['c'] ?? 0);

$pag = paginate($total, LISTINGS_PER_PAGE, $page);

$listings = DB::fetchAll(
    'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating,
            c.name AS cat_name, c.slug AS cat_slug,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb,
            s.created_at AS saved_at
     FROM saved_listings s
     JOIN listings l ON l.id = s.listing_id
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     WHERE s.user_id = ? AND ' . listing_public_sql('l') . '
     ORDER BY s.created_at DESC
     LIMIT ? OFFSET ?',
    [$user['id'], LISTINGS_PER_PAGE, $pag['offset']]
);

render_head('علاقه‌مندی‌ها', 'آگهی‌های ذخیره‌شده شما در ' . APP_NAME);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-4);margin-bottom:var(--sp-6)">
      <div>
        <h1 style="font-size:1.5rem;margin:0 0 var(--sp-2)"><i class="bi bi-heart-fill" style="color:var(--danger)"></i> علاقه‌مندی‌ها</h1>
        <p style="color:var(--text-muted);margin:0"><?= number_format($total) ?> آگهی ذخیره شده</p>
      </div>
      <a href="<?= APP_URL ?>/" class="btn btn-outline"><i class="bi bi-search"></i> مرور آگهی‌ها</a>
    </div>

    <?php if (empty($listings)): ?>
    <div class="empty-state">
      <i class="bi bi-heart"></i>
      <h2>هنوز آگهی ذخیره نکرده‌اید</h2>
      <p>روی آیکون قلب در هر آگهی بزنید تا اینجا ذخیره شود.</p>
      <a href="<?= APP_URL ?>/" class="btn btn-primary">مشاهده آگهی‌ها</a>
    </div>
    <?php else: ?>
    <div class="listings-grid">
      <?php foreach ($listings as $l): ?>
        <?php include __DIR__ . '/../includes/listing_card.php'; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($pag['pages'] > 1): ?>
    <nav class="pagination" style="margin-top:var(--sp-8)">
      <?php if ($pag['has_prev']): ?>
      <a href="?page=<?= $pag['page'] - 1 ?>" class="btn btn-outline btn-sm"><i class="bi bi-chevron-right"></i> قبلی</a>
      <?php endif; ?>
      <span class="fs-sm" style="color:var(--text-muted)">صفحه <?= $pag['page'] ?> از <?= $pag['pages'] ?></span>
      <?php if ($pag['has_next']): ?>
      <a href="?page=<?= $pag['page'] + 1 ?>" class="btn btn-outline btn-sm">بعدی <i class="bi bi-chevron-left"></i></a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>

  </div>
</main>

<?php render_footer(); ?>
