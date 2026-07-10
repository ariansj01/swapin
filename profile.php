<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$currentUser = auth_user();
$profileId   = (int)($_GET['id'] ?? ($currentUser['id'] ?? 0));

if (!$profileId) {
    header('Location: ' . APP_URL . '/'); exit;
}

$profile = DB::fetch(
    'SELECT id, name, email, city, bio, avatar, rating, rating_count, verification_level,
            credit_balance, created_at, kyc_status, seller_type, store_name, subscription_plan
     FROM users WHERE id = ? AND is_active = 1',
    [$profileId]
);

if (!$profile) {
    http_response_code(404);
    render_head('کاربر یافت نشد');
    render_navbar($currentUser);
    echo '<div class="section"><div class="container"><div class="empty-state"><i class="bi bi-person-x"></i><h3>کاربر یافت نشد</h3><a href="' . APP_URL . '/" class="btn btn-primary">بازگشت به خانه</a></div></div></div>';
    render_footer();
    exit;
}

$isOwnProfile = $currentUser && $currentUser['id'] == $profileId;

// Stats
$listingCount  = (int)(DB::fetch('SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = "active" AND review_status = "approved"', [$profileId])['c'] ?? 0);
$tradeCount    = (int)(DB::fetch('SELECT COUNT(*) AS c FROM trades WHERE (user_a_id = ? OR user_b_id = ?) AND status = "completed"', [$profileId, $profileId])['c'] ?? 0);
$reviewCount   = (int)(DB::fetch('SELECT COUNT(*) AS c FROM reviews WHERE to_user_id = ?', [$profileId])['c'] ?? 0);

// Active listings
$listings = DB::fetchAll(
    'SELECT l.*, c.name AS cat_name, u.name AS seller_name, u.rating AS seller_rating,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN categories c ON c.id = l.category_id
     JOIN users u ON u.id = l.user_id
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved"
     ORDER BY l.created_at DESC LIMIT 8',
    [$profileId]
);

// Reviews
$reviews = DB::fetchAll(
    'SELECT r.*, u.name AS reviewer_name FROM reviews r
     JOIN users u ON u.id = r.from_user_id
     WHERE r.to_user_id = ?
     ORDER BY r.created_at DESC LIMIT 10',
    [$profileId]
);

$swapScore = compute_swap_score($profileId);

render_head(h($profile['name']) . ' — پروفایل');
render_navbar($currentUser);
?>

<div class="section-sm">
  <div class="container">

    <!-- Profile Header Card -->
    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <div style="display:flex;align-items:flex-start;gap:var(--sp-6);flex-wrap:wrap">

          <div style="flex-shrink:0">
            <?php if ($profile['avatar']): ?>
            <img src="<?= UPLOAD_URL . h($profile['avatar']) ?>"
                 style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--border)" alt="<?= h($profile['name']) ?>">
            <?php else: ?>
            <div class="avatar avatar-xl"><?= strtoupper(substr($profile['name'], 0, 1)) ?></div>
            <?php endif; ?>
          </div>

          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap;margin-bottom:var(--sp-2)">
              <h1 style="margin:0;font-size:1.5rem;"><?= h($profile['name']) ?></h1>
              <?php if ($profile['verification_level'] >= 2): ?>
              <span class="badge badge-success"><i class="bi bi-patch-check-fill"></i> تأیید‌شده</span>
              <?php endif; ?>
              <?php if (($profile['kyc_status'] ?? 'none') === 'approved'): ?>
              <span class="badge badge-success"><i class="bi bi-shield-check"></i> KYC تأیید‌شده</span>
              <?php endif; ?>
              <?php if (($profile['subscription_plan'] ?? 'none') !== 'none'): ?>
              <span class="badge badge-warning"><i class="bi bi-gem"></i> <?= ucfirst($profile['subscription_plan']) ?></span>
              <?php endif; ?>
            </div>

            <?php if ($profile['city']): ?>
            <div class="fs-sm mb-3" style="color:var(--text-muted)">
              <i class="bi bi-geo-alt"></i> <?= h($profile['city']) ?>
            </div>
            <?php endif; ?>

            <?php if ($profile['bio']): ?>
            <p style="margin-bottom:var(--sp-4)"><?= nl2br(h($profile['bio'])) ?></p>
            <?php endif; ?>

            <!-- Swap Score -->
            <div class="swap-score-card mb-4">
              <div class="swap-score-card__header">
                <span class="swap-score-card__label">Swap Score</span>
                <span class="swap-score-card__value"><?= $swapScore['score'] ?><small>/100</small></span>
              </div>
              <div class="swap-score-card__bar">
                <div class="swap-score-card__fill" style="width:<?= $swapScore['score'] ?>%"></div>
              </div>
              <div class="swap-score-card__status"><?= h($swapScore['label']) ?></div>
              <div class="swap-score-card__breakdown">
                <?php foreach ($swapScore['breakdown'] as $item): ?>
                <div class="swap-score-card__item">
                  <span><?= h($item['label']) ?></span>
                  <span><?= (int)$item['points'] ?> / <?= (int)$item['max'] ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Stats row -->
            <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap">
              <?php if ($profile['rating'] > 0): ?>
              <div class="stat-pill">
                <span style="font-size:1.25rem;font-weight:800;color:var(--accent-dark)"><?= number_format((float)$profile['rating'], 1) ?></span>
                <span class="fs-xs" style="color:var(--text-muted)">امتیاز (<?= $reviewCount ?>)</span>
              </div>
              <?php endif; ?>
              <div class="stat-pill">
                <span style="font-size:1.25rem;font-weight:800;color:var(--primary)"><?= $tradeCount ?></span>
                <span class="fs-xs" style="color:var(--text-muted)">معامله انجام‌شده</span>
              </div>
              <div class="stat-pill">
                <span style="font-size:1.25rem;font-weight:800;color:var(--text-secondary)"><?= $listingCount ?></span>
                <span class="fs-xs" style="color:var(--text-muted)">آگهی فعال</span>
              </div>
              <div class="stat-pill">
                <span class="fs-xs" style="color:var(--text-muted)">عضو از</span>
                <span style="font-weight:700"><?= persian_date($profile['created_at']) ?></span>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
            <?php if ($isOwnProfile): ?>
            <a href="<?= APP_URL ?>/profile/edit" class="btn btn-outline">
              <i class="bi bi-pencil"></i> ویرایش پروفایل
            </a>
            <?php elseif ($currentUser): ?>
            <a href="<?= APP_URL ?>/messages?to=<?= $profile['id'] ?>" class="btn btn-primary">
              <i class="bi bi-chat"></i> پیام
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/auth/login" class="btn btn-primary">ورود برای پیام</a>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--sp-6);align-items:start">

      <!-- Listings -->
      <div>
        <h3 class="mb-4">آگهی‌های فعال (<?= $listingCount ?>)</h3>
        <?php if (empty($listings)): ?>
        <div class="empty-state" style="padding:var(--sp-10) 0">
          <i class="bi bi-box-seam"></i>
          <h3>هنوز آگهی‌ای نیست</h3>
          <?php if ($isOwnProfile): ?>
          <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary">اولین آگهی خود را ثبت کنید</a>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="listings-grid">
          <?php
          $seller_name   = $profile['name'];
          $seller_rating = $profile['rating'];
          foreach ($listings as $l):
            $l['seller_name']   = $seller_name;
            $l['seller_rating'] = $seller_rating;
          ?>
          <?php include __DIR__ . '/includes/listing_card.php'; ?>
          <?php endforeach; ?>
        </div>
        <?php if ($listingCount > 8): ?>
        <div style="text-align:center;margin-top:var(--sp-5)">
          <a href="<?= APP_URL ?>/?seller=<?= $profileId ?>" class="btn btn-outline">مشاهده همه آگهی‌ها</a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Reviews sidebar -->
      <div>
        <div class="card">
          <div class="card-header">
            <h3 style="margin:0;font-size:1rem"><i class="bi bi-star-fill" style="color:var(--accent)"></i> نظرات (<?= $reviewCount ?>)</h3>
          </div>
          <?php if (empty($reviews)): ?>
          <div class="card-body" style="text-align:center;color:var(--text-muted);padding:var(--sp-8)">
            <i class="bi bi-star" style="font-size:2rem;opacity:.3;display:block;margin-bottom:var(--sp-3)"></i>
            <p style="font-size:.875rem">هنوز نظری ثبت نشده</p>
          </div>
          <?php else: ?>
          <?php foreach ($reviews as $review): ?>
          <div style="padding:var(--sp-4) var(--sp-5);border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-2)">
              <div style="display:flex;align-items:center;gap:var(--sp-2)">
                <div class="avatar avatar-sm"><?= strtoupper(substr($review['reviewer_name'], 0, 1)) ?></div>
                <span style="font-weight:600;font-size:.875rem"><?= h($review['reviewer_name']) ?></span>
              </div>
              <div class="stars">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                <i class="bi bi-star<?= $s <= $review['rating'] ? '-fill' : '' ?>" style="font-size:.8rem;color:<?= $s <= $review['rating'] ? 'var(--accent)' : 'var(--border-strong)' ?>"></i>
                <?php endfor; ?>
              </div>
            </div>
            <?php if ($review['comment']): ?>
            <p style="font-size:.875rem;margin:0"><?= h($review['comment']) ?></p>
            <?php endif; ?>
            <div class="fs-xs mt-2" style="color:var(--text-muted)"><?= persian_date($review['created_at']) ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>

  </div>
</div>

<?php render_footer(); ?>
