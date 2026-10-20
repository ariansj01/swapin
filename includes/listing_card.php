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
$cardHref = listing_url($l);
$cardPath = (string) parse_url($cardHref, PHP_URL_PATH);

// آگهی‌های حالت «فروش»: به‌جای «پیشنهاد معاوضه» دکمه «خرید» (مستقیم به صفحه پرداخت)
$cardBuyUrl = null;
if ($listingMode === 'sell') {
    require_once __DIR__ . '/store_orders.php';
    $cardViewer = $user ?? auth_user();
    if (function_exists('listing_can_cash_buy') && listing_can_cash_buy($l, $cardViewer)) {
        $cardCheckoutPath = '/orders/checkout?listing_id=' . (int)$l['id'];
        $cardBuyUrl = !empty($cardViewer['id'])
            ? APP_URL . $cardCheckoutPath
            : APP_URL . '/auth/login?redirect=' . urlencode($cardCheckoutPath);
    }
}
$allPromotions = function_exists('listing_all_promotions_meta') ? listing_all_promotions_meta($l) : [];
$promotionMeta = $allPromotions[0] ?? null;
$promotionClass = $promotionMeta['card_class'] ?? '';
$hasPromo = !empty($allPromotions);

$cardLayout = isset($cardLayout) ? $cardLayout : 'vertical';
$isHorizontal = $cardLayout === 'horizontal';
$isPremiumHorizontal = $cardLayout === 'premium-horizontal';

// Image count for badge
$imgCount = 0;
if ($isHorizontal) {
    $resCount = DB::fetch('SELECT COUNT(*) as cnt FROM listing_images WHERE listing_id = ?', [(int)$l['id']]);
    $imgCount = (int)($resCount['cnt'] ?? 0);
}
?>
<article class="listing-card listing-card--v2 <?= $isHorizontal ? 'listing-card--horizontal' : '' ?> <?= $isPremiumHorizontal ? 'listing-card--premium-horizontal' : '' ?> <?= h($promotionClass) ?>" style="cursor: pointer;" data-navigate="<?= $cardHref ?>">

  <!-- ========== IMAGE SECTION (TOP) ========== -->
  <div class="lc-media-wrapper">
    <?php if (!empty($l['thumb'])): ?>
    <img src="<?= UPLOAD_URL . h($l['thumb']) ?>" alt="<?= h($l['title']) ?>" class="listing-card__media-img" loading="lazy">
    <?php else: ?>
    <div class="listing-card__media-placeholder">
      <i class="bi bi-image"></i>
    </div>
    <?php endif; ?>

    <!-- Badges Row on Image - Top Right -->
    <div class="lc-badges-row"
         style="display:flex !important; visibility:visible !important; opacity:1 !important; pointer-events:auto !important;">
      <?php if ($hasPromo): ?>
        <?php foreach ($allPromotions as $p): ?>
      <span class="lc-badge lc-badge--promo <?= $p['badge_class'] ?? '' ?>"
            style="display:inline-flex !important; visibility:visible !important; opacity:1 !important;">
        <i class="bi <?= $p['icon'] ?? 'bi-star-fill' ?>"></i>
        <?= h($p['label'] ?? 'پلن ویژه') ?>
      </span>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Favorite Button - Top Left (Only for standard vertical) -->
    <?php if (!$isHorizontal && !$isPremiumHorizontal): ?>
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
    <a href="<?= APP_URL ?>/auth/login?redirect=<?= urlencode($cardPath) ?>"
       class="lc-fav-btn"
       style="display:inline-flex !important; visibility:visible !important; opacity:1 !important; pointer-events:auto !important;"
       aria-label="ورود برای ذخیره"
       onclick="event.stopPropagation()">
      <i class="bi bi-heart"></i>
    </a>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ========== CONTENT SECTION ========== -->
  <div class="lc-content">
    <?php if ($isPremiumHorizontal): ?>
      <h3 class="lc-title"><?= h($l['title']) ?></h3>
      
      <!-- Want in Return Box -->
      <?php if (!empty($l['want_in_return'])): ?>
      <div class="lc-want-box">
        <span class="lc-want-arrow"><i class="bi bi-arrow-left-right"></i></span>
        <span class="lc-want-text">مبادله با <?= h($l['want_in_return']) ?></span>
      </div>
      <?php endif; ?>

      <!-- Value / Price Row -->
      <?php if (!empty($l['estimated_value']) && (float)$l['estimated_value'] > 0): ?>
      <div class="lc-value-row">
        <span class="lc-value-label">ارزش تقریبی:</span>
        <span class="lc-value-amount"><?= fmt_credit((float)$l['estimated_value']) ?></span>
      </div>
      <?php endif; ?>

      <!-- Meta Row -->
      <div class="lc-meta">
        <?php if (!empty($l['created_at'])): ?>
        <span><i class="bi bi-clock"></i> <?= timeago($l['created_at']) ?></span>
        <?php endif; ?>
        <?php if (!empty($l['city'])): ?>
        <span><i class="bi bi-geo-alt"></i> <?= h($l['city']) ?></span>
        <?php endif; ?>
      </div>

      <!-- CTA Button (only shown on desktop) -->
      <div class="lc-cta lc-cta--premium">
        <?php if ($cardBuyUrl): ?>
        <a href="<?= h($cardBuyUrl) ?>" class="lc-btn lc-btn--buy" data-navigate="<?= h($cardBuyUrl) ?>" onclick="handleCardV2Click(event, this)">
          <i class="bi bi-cart-check"></i>
          <span>خرید</span>
        </a>
        <?php else: ?>
        <a href="<?= $cardHref ?>" class="lc-btn <?= $hasSwapCta ? 'lc-btn--swap' : 'lc-btn--view' ?>" onclick="handleCardV2Click(event, this)">
          <i class="bi <?= $hasSwapCta ? 'bi-arrow-left-right' : 'bi-eye' ?>"></i>
          <span><?= $hasSwapCta ? 'پیشنهاد معاوضه' : 'مشاهده آگهی' ?></span>
        </a>
        <?php endif; ?>
      </div>
    <?php elseif ($isHorizontal): ?>
    <!-- Horizontal Minimal Layout (Title -> Condition -> Price -> Time/Location) -->
    <h3 class="lc-title"><?= h($l['title']) ?></h3>
    
    <div class="lc-h-minimal-body">
      <?php if (!empty($l['condition'])): ?>
      <div class="lc-h-condition"><?= condition_label($l['condition']) ?></div>
      <?php endif; ?>

      <?php
        $hPrice = 0;
        if (!empty($l['sell_price']) && (float)$l['sell_price'] > 0) {
            $hPrice = (float)$l['sell_price'];
        } elseif (!empty($l['estimated_value']) && (float)$l['estimated_value'] > 0) {
            $hPrice = (float)$l['estimated_value'];
        }
      ?>
      <?php if ($hPrice > 0): ?>
      <div class="lc-h-price"><?= fmt_credit($hPrice, false) ?> <span class="lc-h-unit"><?= (string)CREDIT_UNIT ?></span></div>
      <?php endif; ?>

      <div class="lc-h-meta">
        <?= timeago($l['created_at']) ?>
        <?php if (!empty($l['city'])): ?>
        در <?= h($l['city']) ?><?= !empty($l['neighborhood']) ? ' (' . h($l['neighborhood']) . ')' : '' ?>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <!-- Vertical Layout (Existing) -->
    <h3 class="lc-title"><?= h($l['title']) ?></h3>

    <!-- Value / Price Row -->
    <?php if (!empty($l['estimated_value']) && (float)$l['estimated_value'] > 0): ?>
    <div class="lc-value-row">
      <span class="lc-value-label">ارزش تقریبی:</span>
      <span class="lc-value-amount" style="font-size: 0.90rem;"><?= fmt_credit((float)$l['estimated_value']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Want in Return Box -->
    <?php if (!empty($l['want_in_return'])): ?>
    <div class="lc-want-box">
      <span class="lc-want-arrow"><i class="bi bi-arrow-left-right"></i></span>
      <span class="lc-want-text">مبادله با <?= h($l['want_in_return']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Meta Row -->
    <div class="lc-meta">
      <?php if (!empty($l['created_at'])): ?>
      <span><i class="bi bi-clock"></i> <?= timeago($l['created_at']) ?></span>
      <?php endif; ?>
      <!-- <?php $lcViews = (int)($l['views'] ?? 0); ?>
      <span><i class="bi bi-eye"></i> بازدید: <?= $lcViews > 5000 ? fmt_num(5000) . '+' : fmt_num($lcViews) ?></span> -->
      <?php if (!empty($l['city'])): ?>
      <span><i class="bi bi-geo-alt"></i> <?= h($l['city']) ?><?= !empty($l['neighborhood']) ? '، ' . h($l['neighborhood']) : '' ?></span>
      <?php endif; ?>
    </div>

    <!-- CTA Button -->
    <div class="lc-cta">
      <?php if ($cardBuyUrl): ?>
      <a href="<?= h($cardBuyUrl) ?>" class="lc-btn lc-btn--buy" data-navigate="<?= h($cardBuyUrl) ?>" onclick="handleCardV2Click(event, this)">
        <i class="bi bi-cart-check"></i>
        <span>خرید</span>
      </a>
      <?php else: ?>
      <a href="<?= $cardHref ?>" class="lc-btn <?= $hasSwapCta ? 'lc-btn--swap' : 'lc-btn--view' ?>" onclick="handleCardV2Click(event, this)">
        <i class="bi <?= $hasSwapCta ? 'bi-arrow-left-right' : 'bi-eye' ?>"></i>
        <span><?= $hasSwapCta ? 'پیشنهاد معاوضه' : 'مشاهده آگهی' ?></span>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
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
  overflow: visible;
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
  height: 200px !important;
  min-height: 200px !important;
  max-height: 200px !important;
  flex: 0 0 240px !important;
  background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
  overflow: visible;
  border-top-left-radius: 18px;
  border-top-right-radius: 18px;
  border-bottom-left-radius: 18px;
  border-bottom-right-radius: 18px;
}
.listing-card__media-img {
  width: 100% !important;
  height: 230px !important;
  min-height: 230px !important;
  max-height: 230px !important;
  object-fit: cover !important;
  object-position: center center !important;
  display: block !important;
  border-top-left-radius: 18px;
  border-top-right-radius: 18px;
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
  position: relative;
  display: inline-flex !important;
  align-items: center;
  gap: 3px;
  padding: 3px 7px;
  border-radius: 999px;
  font-size: .6rem;
  font-weight: 800;
  line-height: 1;
  box-shadow: 0 3px 10px rgba(0,0,0,0.12);
  backdrop-filter: blur(12px) saturate(160%);
  -webkit-backdrop-filter: blur(12px) saturate(160%);
  opacity: 1 !important;
  visibility: visible !important;
  transform: translateY(0) scale(1) !important;
  filter: none !important;
  cursor: help;
}
.lc-badge i { font-size: .68rem; line-height: 1; display: inline-flex; align-items: center; }

.lc-badge-help {
  position: absolute;
  top: -4px;
  left: -4px;
  z-index: 12;
  width: 11px;
  height: 11px;
  pointer-events: none;
}
.lc-badge-q {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(8px) saturate(140%);
  -webkit-backdrop-filter: blur(8px) saturate(140%);
  color: #071A33;
  box-shadow: 0 1px 4px rgba(7, 26, 51, 0.18);
}
.lc-badge-q i {
  font-size: 8px !important;
  color: #071A33 !important;
  line-height: 1;
}
.lc-badge-tip {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  width: max-content;
  max-width: 112px;
  padding: 4px 6px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.32);
  border: 1px solid rgba(255, 255, 255, 0.55);
  box-shadow: 0 8px 20px rgba(7, 26, 51, 0.14);
  backdrop-filter: blur(14px) saturate(160%);
  -webkit-backdrop-filter: blur(14px) saturate(160%);
  color: #071A33;
  font-size: 0.58rem;
  font-weight: 600;
  line-height: 1.45;
  text-align: right;
  white-space: normal;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transform: translateX(-50%) translateY(4px);
  transition: opacity 0.15s ease, visibility 0.15s ease, transform 0.15s ease;
  z-index: 30;
}
.lc-badge:hover .lc-badge-tip,
.lc-badge:focus-within .lc-badge-tip {
  opacity: 1;
  visibility: visible;
  transform: translateX(-50%) translateY(0);
}

/* VIP/Plan Badge — cream/yellow with star */
.lc-badge--promo {
  background: rgba(255, 245, 209, 0.55);
  color: #92400e;
  border: 1px solid rgba(253, 230, 138, 0.6);
}
.lc-badge--promo i { color: #F59E0B; }
.lc-badge--promo.listing-promo-badge--vip {
  background: linear-gradient(135deg, rgba(124, 58, 237, 0.55) 0%, rgba(79, 70, 229, 0.55) 100%);
  color: #ffffff;
  border: 1px solid rgba(109, 40, 217, 0.5);
}
.lc-badge--promo.listing-promo-badge--vip i { color: #fbbf24; }
.lc-badge--promo.listing-promo-badge--featured {
  background: linear-gradient(135deg, rgba(251, 191, 36, 0.55) 0%, rgba(245, 158, 11, 0.55) 100%);
  color: #78350f;
  border: 1px solid rgba(217, 119, 6, 0.5);
}
.lc-badge--promo.listing-promo-badge--featured i { color: #78350f; }
.lc-badge--promo.listing-promo-badge--bumped {
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.55) 0%, rgba(5, 150, 105, 0.55) 100%);
  color: #ffffff;
  border: 1px solid rgba(4, 120, 87, 0.5);
}
.lc-badge--promo.listing-promo-badge--bumped i { color: #ecfdf5; }

/* Swap Badge — navy background */
.lc-badge--swap {
  background: rgba(7, 26, 51, 0.55);
  color: #ffffff;
  border: 1px solid rgba(7, 26, 51, 0.4);
}
.lc-badge--swap i { color: #FBBF24; }

/* ---------- Content ---------- */
.lc-content {
  padding: 9px 9px 10px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
  min-height: 200px;
}

.lc-title {
  margin: 0;
  font-size: 0.90rem;
  font-weight: 800;
  color: #071A33;
  line-height: 1.55;
  text-align: center;
  display: block;
  width: 100%;
  max-width: 320px;
  margin-inline: auto;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
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
  font-size: .70rem;
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
  /* padding: 8px 0 4px; */
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
  /* padding-top: 4px; */
  /* margin-top: auto; */
}
.lc-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 14px 22px;
  min-height: 46px;
  border-radius: 14px;
  font-size: 0.88rem;
  font-weight: 800;
  text-decoration: none;
  transition: all 0.2s ease;
  cursor: pointer;
  border: 2px solid transparent;
  line-height: 1.2;
}
.lc-btn i { font-size: 0.9rem; line-height: 1; }

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

/* Buy button (sell-mode listings) */
.lc-btn--buy {
  background: #FF6600;
  border-color: #FF6600;
  color: #ffffff;
}
.lc-btn--buy i { color: #ffffff; }
.lc-btn--buy:hover {
  background: #e65c00;
  transform: scale(1.01);
}

/* =========================================================
   HORIZONTAL CARD VARIANT — Image Left, Content Right
   Used for "آگهی‌های مشابه" section
   ========================================================= */
.listing-card.listing-card--v2.listing-card--horizontal {
  flex-direction: row-reverse; /* Force image to left in RTL */
  align-items: center;
  gap: 0;
  padding: 10px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
  text-decoration: none;
}
.listing-card.listing-card--v2.listing-card--horizontal:hover {
  transform: none;
  box-shadow: 0 4px 14px rgba(7, 26, 51, 0.12);
  border-color: #d1d5db;
}

/* Image on the left */
.listing-card.listing-card--v2.listing-card--horizontal .lc-media-wrapper {
  width: 90px !important;
  min-width: 90px !important;
  height: 90px !important;
  min-height: 90px !important;
  max-height: 90px !important;
  flex: 0 0 90px !important;
  border-radius: 6px;
  overflow: hidden;
  background: #f3f4f6;
}
.listing-card.listing-card--v2.listing-card--horizontal .listing-card__media-img {
  width: 100% !important;
  height: 100% !important;
  min-height: 100% !important;
  max-height: none !important;
  object-fit: cover !important;
  border-radius: 6px;
}
.listing-card.listing-card--v2.listing-card--horizontal .listing-card__media-placeholder {
  width: 100%;
  height: 100% !important;
  min-height: 90px !important;
  max-height: none !important;
  border-radius: 6px;
}

/* Image count badge */
.lc-img-count {
  position: absolute;
  top: 8px;
  right: 8px;
  background: rgba(0,0,0,0.6);
  color: #fff;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.75rem;
  display: flex;
  align-items: center;
  gap: 4px;
  z-index: 5;
}

/* Content on the right */
.listing-card.listing-card--v2.listing-card--horizontal .lc-content {
  flex: 1;
  min-width: 0;
  min-height: 0;
  padding: 0 16px 0 0; /* Content is to the right of image */
  gap: 8px;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  align-items: flex-start;
  text-align: right;
  background: transparent;
}

/* Title — right aligned, bold navy */
.listing-card.listing-card--v2.listing-card--horizontal .lc-title {
  text-align: right;
  max-width: none;
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #071A33;
  white-space: normal;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.4;
  width: 100%;
}

.lc-h-minimal-body {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  width: 100%;
  margin-top: auto;
}

/* Condition */
.listing-card.listing-card--v2.listing-card--horizontal .lc-h-condition {
  font-size: 0.85rem;
  color: #6b7280;
  font-weight: 400;
  text-align: right;
  width: 100%;
}

/* Price */
.listing-card.listing-card--v2.listing-card--horizontal .lc-h-price {
  font-size: 1rem;
  font-weight: 700;
  color: #071A33;
  line-height: 1.2;
  direction: rtl;
  text-align: right;
  width: 100%;
}
.listing-card.listing-card--v2.listing-card--horizontal .lc-h-unit {
  font-size: 0.85rem;
  color: #071A33;
  margin-right: 2px;
}

/* Meta row (time + location) */
.listing-card.listing-card--v2.listing-card--horizontal .lc-h-meta {
  color: #6b7280;
  font-size: 0.85rem;
  font-weight: 400;
  text-align: right;
  width: 100%;
}

/* Hide elements not needed in minimal layout */
.listing-card.listing-card--v2.listing-card--horizontal .lc-cta,
.listing-card.listing-card--v2.listing-card--horizontal .lc-fav-btn,
.listing-card.listing-card--v2.listing-card--horizontal .lc-badges-row {
  display: none !important;
}

/* Responsive */
@media (max-width: 480px) {
  .listing-card.listing-card--v2.listing-card--horizontal .lc-media-wrapper {
    width: 72px !important;
    min-width: 72px !important;
    height: 72px !important;
    min-height: 72px !important;
    max-height: 72px !important;
    flex: 0 0 72px !important;
  }
  .listing-card.listing-card--v2.listing-card--horizontal .lc-title {
    font-size: 0.9rem;
  }
}
</style>

<script>
function handleCardV2Click(event, element) {
    event.stopPropagation();
    window.location.href = element.href;
}
</script>
