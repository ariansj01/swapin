<?php
/**
 * Reusable listing location picker (city → map → neighborhood).
 *
 * Expected $picker config:
 * - prefix: string ID prefix (e.g. step3, edit)
 * - city, latitude, longitude, neighborhood
 * - errors: optional validation errors array
 * - city_select_id: ID of the city <select>
 * - control_class: CSS class for inputs (wizard-form-select | form-control)
 * - label_class: CSS class for labels
 */
$pickerPrefix = $picker['prefix'] ?? 'listing';
$pickerCity = $picker['city'] ?? '';
$pickerLat = $picker['latitude'] ?? '';
$pickerLng = $picker['longitude'] ?? '';
$pickerNeighborhood = $picker['neighborhood'] ?? '';
$pickerErrors = $picker['errors'] ?? [];
$pickerCitySelectId = $picker['city_select_id'] ?? ($pickerPrefix . '-city');
$pickerControlClass = $picker['control_class'] ?? 'wizard-form-select';
$pickerLabelClass = $picker['label_class'] ?? 'wizard-form-label';
$pickerInputClass = $picker['input_class'] ?? (str_contains($pickerControlClass, 'form-control') ? 'form-control' : 'wizard-form-input');
?>
<div class="listing-location-picker is-hidden"
     data-city-select="#<?= h($pickerCitySelectId) ?>"
     data-lat-input="#<?= h($pickerPrefix) ?>-latitude"
     data-lng-input="#<?= h($pickerPrefix) ?>-longitude"
     data-neighborhood-select="#<?= h($pickerPrefix) ?>-neighborhood"
     data-neighborhood-input="#<?= h($pickerPrefix) ?>-neighborhood-text"
     data-neighborhood-hidden="#<?= h($pickerPrefix) ?>-neighborhood-hidden"
     data-initial-lat="<?= h((string)$pickerLat) ?>"
     data-initial-lng="<?= h((string)$pickerLng) ?>"
     data-initial-neighborhood="<?= h($pickerNeighborhood) ?>">

  <input type="hidden" name="latitude" id="<?= h($pickerPrefix) ?>-latitude" value="<?= h((string)$pickerLat) ?>">
  <input type="hidden" name="longitude" id="<?= h($pickerPrefix) ?>-longitude" value="<?= h((string)$pickerLng) ?>">
  <input type="hidden" name="neighborhood" id="<?= h($pickerPrefix) ?>-neighborhood-hidden" value="<?= h($pickerNeighborhood) ?>">

  <div class="listing-location-picker__map-wrap" hidden>
    <div class="listing-location-picker__map" aria-label="نقشه انتخاب موقعیت"></div>
  </div>
  <p class="listing-location-picker__hint">
    <i class="bi bi-geo-alt"></i>
    روی نقشه کلیک کنید یا نشانگر را جابه‌جا کنید تا موقعیت دقیق آگهی مشخص شود.
  </p>
  <div class="listing-location-picker__coords" aria-live="polite"></div>

  <?php if (isset($pickerErrors['location'])): ?>
  <div class="invalid-feedback d-block"><?= h($pickerErrors['location']) ?></div>
  <?php endif; ?>

  <div class="form-group listing-location-picker__neighborhood" style="margin-top: var(--wizard-gap, 1rem);">
    <label class="<?= h($pickerLabelClass) ?>" for="<?= h($pickerPrefix) ?>-neighborhood">محله *</label>
    <select id="<?= h($pickerPrefix) ?>-neighborhood" class="<?= h($pickerControlClass) ?> <?= isset($pickerErrors['neighborhood']) ? 'is-invalid' : '' ?>"></select>
    <input type="text" id="<?= h($pickerPrefix) ?>-neighborhood-text"
           class="<?= h($pickerInputClass) ?> <?= isset($pickerErrors['neighborhood']) ? 'is-invalid' : '' ?>"
           value="<?= h($pickerNeighborhood) ?>"
           placeholder="نام محله" style="display:none" autocomplete="off">
    <?php if (isset($pickerErrors['neighborhood'])): ?>
    <div class="invalid-feedback"><?= h($pickerErrors['neighborhood']) ?></div>
    <?php endif; ?>
  </div>
</div>
