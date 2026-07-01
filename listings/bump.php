<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user      = require_auth();
$listingId = (int)($_GET['id'] ?? 0);
$error     = '';
$success   = '';

$listing = DB::fetch(
    'SELECT l.*, (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS thumb
     FROM listings l WHERE l.id = ? AND l.user_id = ? AND l.status = "active"',
    [$listingId, $user['id']]
);

if (!$listing) {
    header('Location: ' . APP_URL . '/listings/my.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $type   = clean($_POST['type'] ?? '');
    $result = promote_listing($listingId, $user['id'], $type);
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: ' . APP_URL . '/listings/my.php?promoted=1');
        exit;
    }
}

render_head('ارتقای آگهی');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-sm">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/listings/my.php" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> آگهی‌های من
      </a>
      <h2 class="mt-3">ارتقای آگهی</h2>
      <p style="color:var(--text-muted)"><?= h($listing['title']) ?></p>
    </div>

    <?php if ($error): ?><div class="alert alert-danger mb-5"><?= h($error) ?></div><?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
      <?php
      $options = [
          'bump'    => ['بالا بردن', 'bi-arrow-up-circle', BUMP_PRICE_KBC['bump'], BUMP_DURATION_H['bump'], 'آگهی را به مدت ۲۴ ساعت در بالای نتایج جستجو قرار می‌دهد'],
          'feature' => ['ویژه', 'bi-star-fill', BUMP_PRICE_KBC['feature'], BUMP_DURATION_H['feature'], 'نشان ویژه و اولویت نمایش به مدت ۷۲ ساعت'],
      ];
      foreach ($options as $type => [$label, $icon, $price, $hours, $desc]):
      ?>
      <form method="POST" class="card">
        <?= csrf_field() ?>
        <div class="card-body text-center" style="padding:var(--sp-6)">
          <i class="bi <?= $icon ?>" style="font-size:2.5rem;color:var(--<?= $type === 'feature' ? 'accent-dark' : 'primary-light' ?>)"></i>
          <h3 class="mt-3"><?= $label ?></h3>
          <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-3) 0"><?= h($desc) ?></p>
          <div style="font-size:1.5rem;font-weight:800;color:var(--primary)"><?= fmt_credit($price) ?></div>
          <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-5)"><?= $hours ?> ساعت</div>
          <input type="hidden" name="type" value="<?= $type ?>">
          <button type="submit" class="btn btn-<?= $type === 'feature' ? 'accent' : 'primary' ?> w-100">
            ارتقا الآن
          </button>
        </div>
      </form>
      <?php endforeach; ?>
    </div>

    <?php if (listing_is_featured($listing) || listing_is_bumped($listing)): ?>
    <div class="alert alert-success mt-5">
      <i class="bi bi-check-circle"></i>
      ارتقاهای فعال:
      <?php if (listing_is_featured($listing)): ?> ویژه تا <?= persian_datetime($listing['featured_until']) ?><?php endif; ?>
      <?php if (listing_is_bumped($listing)): ?> · بالا برده‌شده تا <?= persian_datetime($listing['bump_until']) ?><?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php render_footer(); ?>
