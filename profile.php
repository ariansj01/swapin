<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$currentUser = auth_user();
$profileId   = (int)($_GET['id'] ?? ($currentUser['id'] ?? 0));

if (!$profileId) {
    header('Location: ' . APP_URL . '/'); exit;
}

$usersCols = db_table_columns('users');
$selectCols = ['id', 'name', 'email', 'city', 'bio', 'avatar', 'rating', 'rating_count', 'verification_level', 'credit_balance', 'created_at'];

if (in_array('kyc_status', $usersCols)) $selectCols[] = 'kyc_status';
if (in_array('seller_type', $usersCols)) $selectCols[] = 'seller_type';
if (in_array('store_name', $usersCols)) $selectCols[] = 'store_name';
if (in_array('subscription_plan', $usersCols)) $selectCols[] = 'subscription_plan';

$profile = DB::fetch(
    'SELECT ' . implode(', ', $selectCols) . ' FROM users WHERE id = ? AND is_active = 1',
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
    'SELECT r.*, u.name AS reviewer_name, u.avatar AS reviewer_avatar FROM reviews r
     JOIN users u ON u.id = r.from_user_id
     WHERE r.to_user_id = ?
     ORDER BY r.created_at DESC LIMIT 10',
    [$profileId]
);

$swapScore = compute_swap_score($profileId);

render_head(h($profile['name']) . ' — پروفایل');
render_panel_styles();
render_navbar($currentUser);
?>

<div class="section-sm">
  <div class="container">
    <div style="margin-bottom:24px">
      <a href="<?= APP_URL ?>/dashboard" class="btn btn-outline btn-sm"><i class="bi bi-arrow-right"></i> بازگشت</a>
    </div>
    
    <div class="profile-compact">
      <section class="profile-compact__hero">
        <div class="profile-compact__top">
          <div><?= avatar_html($profile['avatar'] ?? null, $profile['name'], 'xl') ?></div>

          <div style="flex:1">
            <h1 class="dash-page-head__title" style="margin-bottom:8px"><?= h($profile['name']) ?></h1>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
              <?php if ($profile['city']): ?>
              <span class="badge badge-primary"><i class="bi bi-geo-alt"></i> <?= h($profile['city']) ?></span>
              <?php endif; ?>
              <?php if ($profile['verification_level'] >= 2): ?>
              <span class="badge badge-success"><i class="bi bi-patch-check-fill"></i> تأییدشده</span>
              <?php endif; ?>
              <?php if (array_key_exists('kyc_status', $profile) && $profile['kyc_status'] === 'approved'): ?>
              <span class="badge badge-success"><i class="bi bi-shield-check"></i> KYC تأییدشده</span>
              <?php endif; ?>
              <?php if (array_key_exists('subscription_plan', $profile) && $profile['subscription_plan'] !== 'none'): ?>
              <span class="badge badge-warning"><i class="bi bi-gem"></i> <?= ucfirst($profile['subscription_plan']) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($profile['bio']): ?>
            <p class="dash-page-head__sub" style="margin:0;max-width:620px;line-height:1.9"><?= nl2br(h($profile['bio'])) ?></p>
            <?php else: ?>
            <p class="dash-page-head__sub" style="margin:0">پروفایل کاربری در سواپین</p>
            <?php endif; ?>
          </div>

          <div style="display:flex;flex-direction:column;gap:10px">
            <?php if ($isOwnProfile): ?>
            <a href="<?= APP_URL ?>/profile/edit" class="btn btn-outline"><i class="bi bi-pencil"></i> ویرایش پروفایل</a>
            <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> ثبت آگهی</a>
            <?php elseif (!$currentUser): ?>
            <a href="<?= APP_URL ?>/auth/login" class="btn btn-primary">ورود</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Stats and Score in same row -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px">
          <div class="profile-compact__stats">
            <?php if ($profile['rating'] > 0): ?>
            <div class="profile-compact__stat">
              <div style="font-size:1.25rem;font-weight:800;color:var(--dash-navy)"><?= fmt_num((float)$profile['rating'], 1) ?></div>
              <div style="font-size:.75rem;color:var(--text-muted)">امتیاز (<?= fmt_num($reviewCount) ?>)</div>
            </div>
            <?php endif; ?>
            <div class="profile-compact__stat">
              <div style="font-size:1.25rem;font-weight:800;color:var(--dash-navy)"><?= fmt_num($tradeCount) ?></div>
              <div style="font-size:.75rem;color:var(--text-muted)">معامله</div>
            </div>
            <div class="profile-compact__stat">
              <div style="font-size:1.25rem;font-weight:800;color:var(--dash-navy)"><?= fmt_num($listingCount) ?></div>
              <div style="font-size:.75rem;color:var(--text-muted)">آگهی</div>
            </div>
            <div class="profile-compact__stat">
              <div style="font-size:1rem;font-weight:800;color:var(--dash-navy)"><?= persian_date($profile['created_at']) ?></div>
              <div style="font-size:.75rem;color:var(--text-muted)">عضو از</div>
            </div>
          </div>

          <div class="profile-compact__score">
            <div class="profile-compact__score-main">
              <div class="profile-compact__score-ring" style="--pct: <?= (int)$swapScore['score'] ?>">
                <span><?= fmt_num((int)$swapScore['score']) ?></span>
              </div>
              <div>
                <div class="dash-page-head__title" style="font-size:1rem;margin-bottom:4px">Swap Score</div>
                <div class="dash-page-head__sub" style="margin:0 0 6px"><?= h($swapScore['label']) ?></div>
                <div class="badge badge-warning"><i class="bi bi-stars"></i> وضعیت اعتماد</div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div class="profile-compact__grid">
        <div>
        <h3 class="mb-4">آگهی‌های فعال (<?= fmt_num($listingCount) ?>)</h3>
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

      <div>
        <div class="card" style="margin-top: 40px;">
          <div class="card-header">
            <h3 style="margin:0;font-size:1rem"><i class="bi bi-star-fill" style="color:var(--accent)"></i> نظرات (<?= fmt_num($reviewCount) ?>)</h3>
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
                <?= avatar_html($review['reviewer_avatar'] ?? null, $review['reviewer_name'], 'sm') ?>
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
</div>

<?php render_footer(); ?>
