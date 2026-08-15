<?php
/** Nearby listings + smart swap suggestions block for listing view. */
$nearbyListingId = (int) ($nearbyListingId ?? 0);
if ($nearbyListingId <= 0) {
    return;
}

$nearbyDefaultRadius = listing_nearby_default_radius_km();
$nearbyMaxRadius = listing_nearby_max_radius_km();
$radiusOptions = [5, 10, 15, 25, 50];
?>
<section class="lv-nearby-section" data-listing-id="<?= $nearbyListingId ?>" aria-label="آگهی‌های اطراف">
  <div class="lv-nearby-section__head">
    <h2 class="lv-nearby-section__title"><i class="bi bi-geo-alt"></i> آگهی‌های اطراف</h2>
    <div class="lv-nearby-section__controls">
      <span class="lv-nearby-section__controls-label">شعاع جستجو</span>
      <select data-nearby-radius class="form-control" aria-label="شعاع جستجوی آگهی‌های اطراف">
        <?php foreach ($radiusOptions as $r): ?>
          <?php if ($r > $nearbyMaxRadius) continue; ?>
        <option value="<?= $r ?>" <?= $r === (int) $nearbyDefaultRadius ? 'selected' : '' ?>><?= $r ?> کیلومتر</option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="lv-nearby-loading" data-nearby-loading>در حال بارگذاری نقشه و آگهی‌های اطراف…</div>
  <div class="lv-nearby-map" aria-label="نقشه آگهی‌های اطراف"></div>
  <div class="lv-nearby-empty" data-nearby-empty hidden></div>
  <div class="lv-nearby-list" data-nearby-list></div>

  <div class="lv-nearby-suggestions">
    <h3 class="lv-nearby-suggestions__title"><i class="bi bi-stars"></i> پیشنهادهای معاوضه هوشمند</h3>
    <div data-swap-suggestions>
      <div class="lv-nearby-loading">در حال یافتن پیشنهادهای مناسب…</div>
    </div>
  </div>
</section>
