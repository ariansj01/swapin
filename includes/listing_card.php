<?php
// includes/listing_card.php
// Expects $l = listing row with seller/category data, $user = current auth user

/** @var array $l Listing row (injected by caller scope) */
/** @var array|null $user Current authenticated user (injected by caller scope) */

if (!isset($l) || !is_array($l) || empty($l['id'])) {
    return;
}

static $_savedListingIds = null;
if ($_savedListingIds === null) {
    $_savedListingIds = [];
    $currentUser = $user ?? auth_user();
    if (!empty($currentUser['id'])) {
        $_savedListingIds = array_map('intval', array_column(
            DB::fetchAll('SELECT listing_id FROM saved_listings WHERE user_id = ?', [(int)$currentUser['id']]),
            'listing_id'
        ));
    }
}

$storeName = null;
$storeSlug = null;
if (isset($l['store_name']) && isset($l['store_slug'])) {
    $storeName = trim((string)$l['store_name']);
    $storeSlug = trim((string)$l['store_slug']);
}
if ((!$storeName || !$storeSlug) && !empty($l['user_id'])) {
    static $storeCache = [];
    $uid = (int)$l['user_id'];
    if (!isset($storeCache[$uid])) {
        $usersCols = db_table_columns('users');
        $cols = [];
        if (in_array('store_name', $usersCols)) $cols[] = 'store_name';
        if (in_array('store_slug', $usersCols)) $cols[] = 'store_slug';
        if ($cols) {
            $row = DB::fetch('SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = ?', [$uid]);
            $storeCache[$uid] = $row ? [
                'name' => trim((string)($row['store_name'] ?? '')),
                'slug' => trim((string)($row['store_slug'] ?? '')),
            ] : ['name' => '', 'slug' => ''];
        } else {
            $storeCache[$uid] = ['name' => '', 'slug' => ''];
        }
    }
    if (empty($storeName)) $storeName = $storeCache[$uid]['name'];
    if (empty($storeSlug)) $storeSlug = $storeCache[$uid]['slug'];
}
$hasStore = $storeName && $storeSlug;

$listingMode = trim((string)($l['listing_mode'] ?? ''));
if ($listingMode === '') {
    $listingMode = 'swap';
}
$hasSwapCta = in_array($listingMode, ['swap', 'both'], true);
$hasSellCta = $hasStore && in_array($listingMode, ['sell', 'both'], true);
$isSaved  = isset($l['id']) && in_array((int)$l['id'], $_savedListingIds, true);
$cardHref = APP_URL . '/listings/view?id=' . $l['id'];
$allPromotions = function_exists('listing_all_promotions_meta') ? listing_all_promotions_meta($l) : [];
$promotionMeta = $allPromotions[0] ?? null;
$promotionClass = $promotionMeta['card_class'] ?? '';
$hasPromo = !empty($allPromotions);
?>
<article class="listing-card listing-card--v2 <?= h($promotionClass) ?>" style="cursor: pointer;" data-navigate="<?= $cardHref ?>">

  <!-- ========== IMAGE SECTION (TOP) ========== -->
  <div class="lc-media-wrapper">
    <?php if (!empty($l['thumb'])): ?>
    <img src="<?= UPLOAD_URL . h($l['thumb']) ?>" alt="<?= h($l['title']) ?>" class="listing-card__media-img" loading="lazy">
    <?php else: ?>
    <div class="listing-card__media-placeholder">
      <i class="bi bi-image"></i>
    </div>
    <?php endif; ?>

    <!-- Favorite Button - Top Left (ALWAYS VISIBLE via inline style) -->
    <?php $currentUser = $currentUser ?? auth_user(); ?>
    <?php if (!empty($currentUser['id'])): ?>
    <button type="button"
            class="lc-fav-btn<?= $isSaved ? ' is-saved' : '' ?>"
            style="display:inline-flex !important; visibility:visible !important; opacity:1 !important; pointer-events:auto !important;"
            data-save-toggle="<?= $isSaved ? 'true' : 'false' ?>"
            data-listing-id="<?= (int)$l['id'] ?>"
            aria-label="<?= $isSaved ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها' ?>"
            aria-pressed="<?= $isSaved ? 'true' : 'false' ?>"
            onclick="event.stopPropagation()">
      <i class="bi bi-<?= $isSaved ? 'heart-fill' : 'heart' ?>"></i>
    </button>
    <?php else: ?>
    <a href="<?= APP_URL ?>/auth/login?redirect=<?= urlencode('/listings/view?id=' . $l['id']) ?>"
       class="lc-fav-btn"
       style="display:inline-flex !important; visibility:visible !important; opacity:1 !important; pointer-events:auto !important;"
       aria-label="ورود برای ذخیره"
       onclick="event.stopPropagation()">
      <i class="bi bi-heart"></i>
    </a>
    <?php endif; ?>

    <!-- Badges Row on Image - Top Right (ALWAYS VISIBLE via inline style) -->
    <div class="lc-badges-row"
         style="display:flex !important; visibility:visible !important; opacity:1 !important; pointer-events:auto !important;">
      <?php if ($hasPromo): ?>
        <?php foreach ($allPromotions as $p): ?>
      <span class="lc-badge lc-badge--promo <?= $p['badge_class'] ?? '' ?>"
            title="<?= h($p['tooltip'] ?? '') ?>"
            style="display:inline-flex !important; visibility:visible !important; opacity:1 !important;">
        <i class="bi <?= $p['icon'] ?? 'bi-star-fill' ?>"></i>
        <?= h($p['label'] ?? 'پلن ویژه') ?>
      </span>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($hasSwapCta): ?>
      <!-- <span class="lc-badge lc-badge--swap"
            style="display:inline-flex !important; visibility:visible !important; opacity:1 !important;">
        معاوضه
        <i class="bi bi-arrow-left-right"></i>
      </span> -->
      <?php endif; ?>
    </div>
  </div>

  <!-- ========== CONTENT SECTION (BELOW IMAGE) ========== -->
  <div class="lc-content">

    <!-- Title -->
    <h3 class="lc-title"><?= h($l['title']) ?></h3>

    <!-- Value / Price Row -->
    <?php if (!empty($l['estimated_value']) && (float)$l['estimated_value'] > 0): ?>
    <div class="lc-value-row">
      <span class="lc-value-label">:ارزش تقریبی</span>
      <span class="lc-value-amount" style="font-size: 1rem;"><?= fmt_credit((float)$l['estimated_value']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Want in Return Box -->
    <?php if (!empty($l['want_in_return'])): ?>
    <div class="lc-want-box">
      <span class="lc-want-arrow"><i class="bi bi-arrow-left-right"></i></span>
      <span class="lc-want-text">مبادله با <?= h($l['want_in_return']) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($hasStore && $hasSellCta && $hasSwapCta): ?>
    <span class="lc-store-badge lc-store-badge--both"><i class="bi bi-shop"></i> قابل خرید و معاوضه</span>
    <?php endif; ?>
    <?php if ($hasStore): ?>
    <a href="<?= APP_URL ?>/shop/<?= h($storeSlug) ?>" class="lc-store-link" onclick="event.stopPropagation()">
      <i class="bi bi-shop"></i> <?= h($storeName) ?>
    </a>
    <?php endif; ?>

    <!-- Meta Row -->
    <div class="lc-meta">
      <?php if (!empty($l['created_at'])): ?>
      <span><i class="bi bi-clock"></i> <?= timeago($l['created_at']) ?></span>
      <?php endif; ?>
      <span><i class="bi bi-eye"></i> بازدید: <?= number_format((int)($l['views'] ?? 0)) ?></span>
      <?php if (!empty($l['condition'])): ?>
      <!-- <span>وضعیت: <?= condition_label($l['condition'] ?? '') ?></span> -->
      <?php endif; ?>
      <?php if (!empty($l['city'])): ?>
      <span><i class="bi bi-geo-alt"></i> <?= h($l['city']) ?><?= !empty($l['neighborhood']) ? '، ' . h($l['neighborhood']) : '' ?></span>
      <?php endif; ?>
    </div>

    <!-- CTA Button -->
    <div class="lc-cta">
      <?php if ($hasSwapCta): ?>
      <a href="<?= $cardHref ?>"
         class="lc-btn lc-btn--swap"
         onclick="handleCardV2Click(event, this)">
        <i class="bi bi-arrow-left-right"></i>
        <span>پیشنهاد معاوضه</span>
      </a>
      <?php else: ?>
      <a href="<?= $cardHref ?>"
         class="lc-btn lc-btn--view"
         onclick="handleCardV2Click(event, this)">
        <i class="bi bi-eye"></i>
        <span>مشاهده آگهی</span>
      </a>
      <?php endif; ?>
    </div>

  </div>
</article>

<style>
/* =========================================================
   NEW LISTING CARD (V2) — Image-Top Layout
   ========================================================= */
.listing-card--v2 {
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 18px;
  overflow: hidden;
  transition: all 0.25s ease;
  height: 100%;
}
.listing-card--v2:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 32px -12px rgba(7, 26, 51, 0.25);
  border-color: #d1d5db;
}

/* ---------- Media / Image - Fixed height for ALL cards ---------- */
.lc-media-wrapper {
  position: relative;
  width: 100%;
  height: 240px !important;
  min-height: 240px !important;
  max-height: 240px !important;
  flex: 0 0 240px !important;
  background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
  overflow: hidden;
  border-bottom-left-radius: 18px;
  border-bottom-right-radius: 18px;
}
.listing-card__media-img {
  width: 100% !important;
  height: 240px !important;
  min-height: 240px !important;
  max-height: 240px !important;
  object-fit: cover !important;
  object-position: center center !important;
  display: block !important;
}
.listing-card__media-placeholder {
  width: 100%;
  height: 240px !important;
  min-height: 240px !important;
  max-height: 240px !important;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9ca3af;
  flex-shrink: 0;
}
.listing-card__media-placeholder i { font-size: 3rem; }

/* ---------- Favorite Button (Top-Left, white round) - ALWAYS VISIBLE ---------- */
html body main .listing-card.listing-card--v2 .lc-media-wrapper .lc-fav-btn,
.lc-fav-btn {
  position: absolute;
  top: 12px;
  left: 12px;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(4px);
  border: none;
  color: #071A33;
  font-size: 1.15rem;
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  text-decoration: none;
  z-index: 10;
  transition: all 0.2s ease;
  box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  opacity: 1 !important;
  visibility: visible !important;
  transform: translateY(0) scale(1) !important;
  pointer-events: auto !important;
}
html body main .listing-card.listing-card--v2 .lc-media-wrapper .lc-fav-btn:hover,
.lc-fav-btn:hover {
  background: #fff;
  transform: scale(1.08) !important;
  color: #ef4444;
}
html body main .listing-card.listing-card--v2 .lc-media-wrapper .lc-fav-btn.is-saved,
.lc-fav-btn.is-saved { color: #ef4444; }

/* ---------- Badges Row (Top Right/Center) - ALWAYS VISIBLE ---------- */
html body main .listing-card.listing-card--v2 .lc-media-wrapper .lc-badges-row,
.lc-badges-row {
  position: absolute;
  top: 12px;
  right: 12px;
  display: flex !important;
  align-items: center;
  gap: 8px;
  z-index: 10;
  opacity: 1 !important;
  visibility: visible !important;
  transform: translateY(0) translateX(0) scale(1) !important;
  pointer-events: auto !important;
  filter: none !important;
  will-change: auto !important;
}
html body main .listing-card.listing-card--v2 .lc-media-wrapper .lc-badges-row .lc-badge,
.lc-badge {
  display: inline-flex !important;
  align-items: center;
  gap: 5px;
  padding: 6px 14px;
  border-radius: 999px;
  font-size: .82rem;
  font-weight: 800;
  line-height: 1.2;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  opacity: 1 !important;
  visibility: visible !important;
  transform: translateY(0) scale(1) !important;
  filter: none !important;
}
.lc-badge i { font-size: .9rem; }

/* VIP/Plan Badge — cream/yellow with star */
.lc-badge--promo {
  background: #FFF5D1;
  color: #92400e;
  border: 1px solid #FDE68A;
}
.lc-badge--promo i { color: #F59E0B; }
.lc-badge--promo.listing-promo-badge--vip {
  background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);
  color: #ffffff;
  border: 1px solid #6d28d9;
}
.lc-badge--promo.listing-promo-badge--vip i { color: #fbbf24; }
.lc-badge--promo.listing-promo-badge--featured {
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
  color: #78350f;
  border: 1px solid #d97706;
}
.lc-badge--promo.listing-promo-badge--featured i { color: #78350f; }
.lc-badge--promo.listing-promo-badge--bumped {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: #ffffff;
  border: 1px solid #047857;
}
.lc-badge--promo.listing-promo-badge--bumped i { color: #ecfdf5; }

/* Swap Badge — navy background */
.lc-badge--swap {
  background: #071A33;
  color: #ffffff;
}
.lc-badge--swap i { color: #FBBF24; }

/* ---------- Content ---------- */
.lc-content {
  padding: 18px 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex: 1;
}

.lc-title {
  margin: 0;
  font-size: 1.12rem;
  font-weight: 800;
  color: #071A33;
  line-height: 1.55;
  text-align: center;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
  min-height: calc(1.12rem * 1.55 * 2);
}

/* Value row */
.lc-value-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 4px;
}
.lc-value-label {
  font-size: .9rem;
  color: #6b7280;
  font-weight: 600;
}
.lc-value-amount {
  font-size: 1.55rem;
  font-weight: 900;
  color: #F59E0B;
  line-height: 1.2;
}

/* Want Box */
.lc-want-box {
  margin-top: 2px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
}
.lc-want-arrow {
  color: #F59E0B;
  display: inline-flex;
  align-items: center;
}
.lc-want-arrow i { font-size: 1.05rem; }
.lc-want-text {
  flex: 1;
  min-width: 0;
  color: #071A33;
  font-size: .95rem;
  font-weight: 700;
  text-align: center;
  line-height: 1.6;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Store badges */
.lc-store-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: .78rem;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 999px;
  align-self: flex-start;
}
.lc-store-badge--both {
  color: #2563eb;
  background: rgba(37,99,235,.1);
}
.lc-store-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: .78rem;
  font-weight: 600;
  color: #071A33;
  text-decoration: none;
  padding: 4px 10px;
  background: rgba(59,130,246,.08);
  border-radius: 999px;
  align-self: flex-start;
}

/* Meta row */
.lc-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 18px;
  padding: 8px 0 4px;
  color: #6b7280;
  font-size: .82rem;
  font-weight: 600;
  line-height: 1.8;
  margin-top: 4px;
}
.lc-meta i {
  font-size: .82rem;
  opacity: .8;
  margin-left: 2px;
}

/* CTA */
.lc-cta {
  padding-top: 4px;
}
.lc-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  /* gap: 10px; */
  width: 100%;
  padding: 7px 5px;
  border-radius: 14px;
  font-size: 1rem;
  font-weight: 800;
  text-decoration: none;
  transition: all 0.2s ease;
  cursor: pointer;
  border: 2px solid transparent;
}
.lc-btn i { font-size: 1rem; }

/* Swap button — yellow/orange border, navy text */
.lc-btn--swap {
  background: transparent;
  border-color: #FBBF24;
  color: #071A33;
}
.lc-btn--swap i { color: #F59E0B; }
.lc-btn--swap:hover {
  background: rgba(251, 191, 36, 0.12);
  transform: scale(1.01);
}

/* View button (non-swap mode) */
.lc-btn--view {
  background: #071A33;
  border-color: #071A33;
  color: #ffffff;
}
.lc-btn--view:hover {
  background: #14305e;
  transform: scale(1.01);
}
</style>

<script>
function handleCardV2Click(event, element) {
    event.stopPropagation();
    window.location.href = element.href;
}
</script>
