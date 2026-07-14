<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';

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

render_head('علاقه‌مندی‌ها', 'آگهی‌های ذخیره‌شده شما در ' . APP_NAME, ['canonical' => APP_URL . '/listings/saved']);
render_panel_styles();
render_navbar($user);
render_user_panel_open($user, 'saved');
?>

  <div class="dash-panel">
    <?php render_panel_page_header('علاقه‌مندی‌ها', fmt_num($total) . ' آگهی ذخیره شده'); ?>
    <div class="dash-page-head__actions" style="justify-content:flex-end;margin-bottom:24px">
      <a href="<?= APP_URL ?>/dashboard" class="btn btn-outline btn-sm">
        <i class="bi bi-arrow-right"></i> بازگشت
      </a>
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
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
