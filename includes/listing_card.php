<?php
// includes/listing_card.php
// Expects $l = listing row with seller/category data, $user = current auth user

static $_savedListingIds = null;
if ($_savedListingIds === null) {
    $_savedListingIds = [];
    if (!empty($user['id'])) {
        $_savedListingIds = array_map('intval', array_column(
            DB::fetchAll('SELECT listing_id FROM saved_listings WHERE user_id = ?', [(int)$user['id']]),
            'listing_id'
        ));
    }
}

$isSwap   = empty($l['listing_mode']) || $l['listing_mode'] === 'swap' || $l['listing_mode'] === 'both';
$isSaved  = in_array((int)$l['id'], $_savedListingIds, true);
$cardHref = APP_URL . '/listings/view.php?id=' . $l['id'];
?>
<article class="listing-card <?= listing_is_featured($l) ? 'featured' : '' ?> <?= listing_is_bumped($l) ? 'bumped' : '' ?>">
  <div class="listing-card__header">
    <div class="listing-card__header-start">
      <?php if (!empty($l['want_in_return'])): ?>
      <span class="listing-card__badge listing-card__swap-badge">
        <i class="bi bi-arrow-left-right"></i>
        معاوضه با: <?= h(mb_strimwidth($l['want_in_return'], 0, 36, '…')) ?>
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
      <?php if (listing_is_featured($l)): ?>
      <span class="badge badge-gold"><i class="bi bi-star-fill"></i></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($user['id'])): ?>
    <button type="button"
            class="listing-card__favorite<?= $isSaved ? ' is-saved' : '' ?>"
            data-save-toggle="<?= $isSaved ? 'true' : 'false' ?>"
            data-listing-id="<?= (int)$l['id'] ?>"
            aria-label="<?= $isSaved ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها' ?>"
            aria-pressed="<?= $isSaved ? 'true' : 'false' ?>">
      <i class="bi bi-<?= $isSaved ? 'heart-fill' : 'heart' ?>"></i>
    </button>
    <?php else: ?>
    <a href="<?= APP_URL ?>/auth/login.php?redirect=<?= urlencode('/listings/view.php?id=' . $l['id']) ?>"
       class="listing-card__favorite"
       aria-label="ورود برای ذخیره">
      <i class="bi bi-heart"></i>
    </a>
    <?php endif; ?>
  </div>

  <a href="<?= $cardHref ?>" class="listing-card__link">
    <div class="listing-card__product">
      <div class="listing-card__details">
        <h3 class="listing-card__title"><?= h($l['title']) ?></h3>
        <?php if (!empty($l['cat_name'])): ?>
        <span class="listing-card__cat">دسته: <?= h(category_label($l['cat_slug'] ?? '', $l['cat_name'] ?? '')) ?></span>
        <?php endif; ?>

        <?php if (!empty($l['estimated_value']) && (float)$l['estimated_value'] > 0): ?>
        <div class="listing-card__value">
          <span class="listing-card__value-label">ارزش تقریبی:</span>
          <span class="listing-card__value-amount"><?= fmt_credit((float)$l['estimated_value']) ?></span>
        </div>
        <?php endif; ?>
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

    <div class="listing-card__meta">
      <span><i class="bi bi-eye"></i> بازدید: <?= number_format((int)($l['views'] ?? 0)) ?></span>
      <span>وضعیت: <?= condition_label($l['condition'] ?? '') ?></span>
      <?php if (!empty($l['city'])): ?>
      <span><i class="bi bi-geo-alt"></i> <?= h($l['city']) ?></span>
      <?php endif; ?>
    </div>

    <div class="listing-card__cta">
      <span class="listing-card__cta-btn">
        <i class="bi bi-arrow-left-right"></i> پیشنهاد معاوضه
      </span>
    </div>
  </a>
</article>
