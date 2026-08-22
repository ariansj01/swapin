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

$isSwap   = empty($l['listing_mode']) || $l['listing_mode'] === 'swap' || $l['listing_mode'] === 'both';
$listingMode = trim((string)($l['listing_mode'] ?? ''));
if ($listingMode === '') {
    $listingMode = 'swap';
}
$hasSellCta = in_array($listingMode, ['sell', 'both'], true);
$hasSwapCta = in_array($listingMode, ['swap', 'both'], true);
$isSaved  = isset($l['id']) && in_array((int)$l['id'], $_savedListingIds, true);
$cardHref = APP_URL . '/listings/view?id=' . $l['id'];
$promotionMeta = function_exists('listing_active_promotion_meta') ? listing_active_promotion_meta($l) : null;
$promotionClass = $promotionMeta['card_class'] ?? '';
?>
<article class="listing-card <?= h($promotionClass) ?>" style="height: 100%; cursor: pointer;" data-navigate="<?= $cardHref ?>">
  <div class="listing-card__header">
    <div class="listing-card__header-start">
      <?php if (!empty($l['want_in_return'])): ?>
      <span class="listing-card__badge listing-card__swap-badge">
        <i class="bi bi-arrow-left-right"></i>
        <!-- معاوضه با: <?= h(mb_strimwidth($l['want_in_return'], 0, 36, '…')) ?> -->
        معاوضه
      </span>
      <?php elseif ($isSwap): ?>
      <span class="listing-card__badge">
        <i class="bi bi-arrow-left-right"></i> معاوضه
      </span>
      <?php else: ?>
      <span class="listing-card__badge">
        <i class="bi bi-tag"></i> <?= h(listing_mode_label($l['listing_mode'])) ?>
      </span>
      <?php endif; ?>
      <?= listing_promotion_badges_html($l) ?>
    </div>
    <?php $currentUser = $currentUser ?? auth_user(); ?>
    <?php if (!empty($currentUser['id'])): ?>
    <button type="button"
            class="listing-card__favorite<?= $isSaved ? ' is-saved' : '' ?>"
            data-save-toggle="<?= $isSaved ? 'true' : 'false' ?>"
            data-listing-id="<?= (int)$l['id'] ?>"
            aria-label="<?= $isSaved ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها' ?>"
            aria-pressed="<?= $isSaved ? 'true' : 'false' ?>"
            onclick="event.stopPropagation()">
      <i class="bi bi-<?= $isSaved ? 'heart-fill' : 'heart' ?>"></i>
    </button>
    <?php else: ?>
    <a href="<?= APP_URL ?>/auth/login?redirect=<?= urlencode('/listings/view?id=' . $l['id']) ?>"
       class="listing-card__favorite"
       aria-label="ورود برای ذخیره"
       onclick="event.stopPropagation()">
      <i class="bi bi-heart"></i>
    </a>
    <?php endif; ?>
  </div>

  <div class="listing-card__link">
    <div class="listing-card__product">
      <div class="listing-card__details">
        <h3 class="listing-card__title"><?= h($l['title']) ?></h3>
        <?php if ($hasStore && $hasSellCta && $hasSwapCta): ?>
        <span class="listing-card__badge listing-card__badge--both" style="display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;color:var(--accent,#2563eb);background:rgba(37,99,235,.1);padding:2px 8px;border-radius:999px;margin-bottom:4px"><i class="bi bi-shop"></i> قابل خرید و معاوضه</span>
        <?php endif; ?>
        <?php if (!empty($l['cat_name'])): ?>
        <span class="listing-card__cat">دسته: <?= h(category_label($l['cat_slug'] ?? '', $l['cat_name'] ?? '')) ?></span>
        <?php endif; ?>
        <?php if ($hasStore): ?>
        <a href="<?= APP_URL ?>/shop/<?= h($storeSlug) ?>" class="listing-card__store-link" onclick="event.stopPropagation()" style="width: 65%;display:inline-flex;align-items:center;gap:3px;font-size:.75rem;color:var(--dash-navy);text-decoration:none;margin-top:2px;padding:2px 8px;background:rgba(59,130,246,.1);border-radius:999px;">
          <i class="bi bi-shop"></i> <?= h($storeName) ?>
        </a>
        <?php endif; ?>

        <!-- <?php if (!empty($l['estimated_value']) && (float)$l['estimated_value'] > 0): ?>
        <div class="listing-card__value">
          <span class="listing-card__value-label">ارزش تقریبی:</span>
          <span class="listing-card__value-amount"><?= fmt_credit((float)$l['estimated_value']) ?></span>
        </div>
        <?php endif; ?> -->
      </div>

      <div class="listing-card__media">
        <?php if (!empty($l['thumb'])): ?>
        <img src="<?= UPLOAD_URL . h($l['thumb']) ?>" alt="<?= h($l['title']) ?>" class="listing-card__img" loading="lazy">
        <?php else: ?>
        <div class="listing-card__img-placeholder">
          <i class="bi bi-image"></i>
        </div>
        <?php endif; ?>
      </div>
    </div>

    
    <?php if (!empty($l['want_in_return'])): ?>
      <div class="listing-card__exchange">
      <div class="listing-card__exchange-heading">نیازمند:</div>
      <div class="listing-card__exchange-items">
        <i class="bi bi-arrow-left-right"></i><?= h($l['want_in_return']) ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($l['estimated_value']) && (float)$l['estimated_value'] > 0): ?>
      <div class="listing-card__value" style="margin: 6px 16px;">
        <span class="listing-card__value-label">ارزش تقریبی:</span>
        <span class="listing-card__value-amount"><?= fmt_credit((float)$l['estimated_value']) ?></span>
      </div>
    <?php endif; ?>

    <div class="listing-card__meta">
      <?php if (!empty($l['created_at'])): ?>
      <span><i class="bi bi-clock"></i> <?= timeago($l['created_at']) ?></span>
      <?php endif; ?>
      <span><i class="bi bi-eye"></i> بازدید: <?= number_format((int)($l['views'] ?? 0)) ?></span>
      <span>وضعیت: <?= condition_label($l['condition'] ?? '') ?></span>
      <?php if (!empty($l['city'])): ?>
      <span><i class="bi bi-geo-alt"></i> <?= h($l['city']) ?><?= !empty($l['neighborhood']) ? '، ' . h($l['neighborhood']) : '' ?></span>
      <?php endif; ?>
    </div>

    <div class="listing-card__cta-container">
        <div class="listing-card__cta-flex">
            <?php
            $buyUrl = APP_URL . '/orders/checkout.php?listing_id=' . $l['id'];
            $swapUrl = APP_URL . '/listings/view?id=' . $l['id'];

            $buyBtnDisabled = !$hasSellCta;
            $swapBtnDisabled = !$hasSwapCta;

            $buyToast = $buyBtnDisabled ? 'امکان خرید نقدی برای این کالا وجود ندارد.' : '';
            $swapToast = $swapBtnDisabled ? 'امکان معاوضه برای این کالا وجود ندارد.' : '';
            ?>
            <a href="<?= !$buyBtnDisabled ? $buyUrl : '#' ?>"
               class="btn-new btn-new--buy <?= $buyBtnDisabled ? 'is-disabled' : '' ?>"
               <?= $buyBtnDisabled ? 'data-toast="' . $buyToast . '"' : '' ?>
               onclick="handleCardClick(event, this)">
                <i class="bi bi-cart-check" style="color: #FBBF24;"></i>
                <span>خرید کالا</span>
            </a>
            <a href="<?= !$swapBtnDisabled ? $swapUrl : '#' ?>"
               class="btn-new btn-new--swap <?= $swapBtnDisabled ? 'is-disabled' : '' ?>"
               <?= $swapBtnDisabled ? 'data-toast="' . $swapToast . '"' : '' ?>
               onclick="handleCardClick(event, this)">
                <i class="bi bi-arrow-left-right" style="color: #FBBF24;"></i>
                <span>پیشنهاد معاوضه</span>
            </a>
        </div>
    </div>
  </div>
</article>

<style>
.btn-new {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex: 1;
    padding: 10px;
    border-radius: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s ease;
}
.btn-new.is-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-new--buy {
    background-color: #1E3A8A; /* navy */
    color: white;
}
.btn-new--buy:not(.is-disabled):hover {
    background-color: #1c3478;
}
.btn-new--swap {
    background-color: transparent;
    border: 2px solid #FBBF24; /* yellow */
    color: #1E3A8A; /* navy */
}
.btn-new--swap:not(.is-disabled):hover {
    background-color: rgba(251, 191, 36, 0.1);
}
.listing-card__cta-container {
    padding: 8px 16px 16px;
}
.listing-card__cta-flex {
    display: flex;
    gap: 8px;
}
</style>

<script>
function handleCardClick(event, element) {
    event.stopPropagation();
    if (element.classList.contains('is-disabled')) {
        event.preventDefault();
        const message = element.getAttribute('data-toast');
        if (message && typeof showToast === 'function') {
            showToast(message, 'info');
        }
    } else {
        window.location.href = element.href;
    }
}
</script>
