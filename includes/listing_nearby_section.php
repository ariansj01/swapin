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
      <div class="lv-nearby-sort" role="group" aria-label="مرتب‌سازی آگهی‌های اطراف">
        <button type="button" class="lv-nearby-sort__btn is-active" data-nearby-sort="distance" aria-pressed="true">نزدیک‌ترین</button>
        <button type="button" class="lv-nearby-sort__btn" data-nearby-sort="relevant" aria-pressed="false">مرتبط‌ترین</button>
      </div>
      <span class="lv-nearby-section__controls-label">شعاع جستجو</span>
      <select data-nearby-radius class="form-control" aria-label="شعاع جستجوی آگهی‌های اطراف">
        <option value="smart">پیشنهاد هوشمند 📍</option>
        <?php foreach ($radiusOptions as $r): ?>
          <?php if ($r > $nearbyMaxRadius) continue; ?>
        <option value="<?= $r ?>" <?= $r === (int) $nearbyDefaultRadius ? 'selected' : '' ?>><?= $r ?> کیلومتر</option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="lv-nearby-smart-msg" data-nearby-smart-msg hidden></div>

  <div class="lv-nearby-loading" data-nearby-loading>در حال بارگذاری نقشه و آگهی‌های اطراف…</div>

  <div class="lv-nearby-map-wrap">
    <div class="lv-nearby-map" aria-label="نقشه آگهی‌های اطراف"></div>
    <aside class="lv-nearby-panel" data-nearby-panel hidden aria-label="جزئیات آگهی انتخاب‌شده"></aside>
  </div>

  <div class="lv-nearby-sheet" data-nearby-sheet hidden aria-hidden="true">
    <button type="button" class="lv-nearby-sheet__backdrop" data-nearby-sheet-close aria-label="بستن"></button>
    <div class="lv-nearby-sheet__body" data-nearby-sheet-body role="dialog" aria-modal="true" aria-label="جزئیات آگهی"></div>
  </div>

  <div class="lv-nearby-empty" data-nearby-empty hidden></div>
  <div class="lv-nearby-list" data-nearby-list></div>
</section>
