<?php
/** Primary swap suggestions CTA for listing detail page. */
$swapListingId = (int) ($swapListingId ?? 0);
if ($swapListingId <= 0) {
    return;
}
$swapCanFeedback = !empty($user);
$swapCanOffer = !empty($swapCanOffer);
$swapSourceTitle = (string) ($swapSourceTitle ?? '');
?>
<section class="lv-swap-cta" data-swap-cta data-listing-id="<?= $swapListingId ?>" data-can-feedback="<?= $swapCanFeedback ? '1' : '0' ?>" data-can-offer="<?= $swapCanOffer ? '1' : '0' ?>" data-source-title="<?= h($swapSourceTitle) ?>" aria-label="پیشنهادهای معاوضه">
  <div class="lv-swap-cta__head">
    <h2 class="lv-swap-cta__title"><i class="bi bi-arrow-left-right"></i> بهترین معاوضه‌های پیشنهادی برای این آگهی</h2>
    <p class="lv-swap-cta__subtitle">سواَپین بر اساس نیاز، دسته، ارزش و موقعیت، مناسب‌ترین گزینه‌های معاوضه را پیشنهاد می‌دهد.</p>
  </div>
  <div class="lv-swap-cta__body" data-swap-suggestions>
    <div class="lv-swap-cta__loading">در حال یافتن بهترین معاوضه‌ها…</div>
  </div>
</section>
