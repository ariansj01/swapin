<?php
/**
 * Onboarding preference fields — shown on first ad creation.
 * Expects: $onboardingVals (array), $onboardingErrors (array), $parentCategories (array), $user (array)
 */
$ov = $onboardingVals ?? [];
$oe = $onboardingErrors ?? [];
?>
<div class="card mb-5" id="onboarding-section">
  <div class="card-header">
    <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-person-check" style="color:var(--primary)"></i> چند سوال کوتاه</h3>
  </div>
  <div class="card-body">
    <p class="fs-sm mb-5" style="color:var(--text-muted)">برای شخصی‌سازی تجربه شما — فقط یک‌بار پرسیده می‌شود.</p>

    <div class="form-group mb-6">
      <label class="form-label">بیشتر قصد دارید… <span class="required">*</span></label>
      <div class="choice-grid choice-grid--2">
        <?php
        $goals = [
            'swap' => 'کالا تعویض کنید',
            'buy'  => 'کالا بخرید',
            'sell' => 'کالا بفروشید',
            'any'  => 'هر سه',
        ];
        foreach ($goals as $val => $label):
            $checked = ($ov['primary_goal'] ?? '') === $val;
        ?>
        <label class="card choice-card">
          <input type="radio" name="primary_goal" value="<?= $val ?>" <?= $checked ? 'checked' : '' ?>>
          <span class="choice-card__label"><?= $label ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if (isset($oe['primary_goal'])): ?>
      <div class="invalid-feedback"><?= h($oe['primary_goal']) ?></div>
      <?php endif; ?>
    </div>

    <div class="form-group mb-6">
      <label class="form-label">بیشتر دنبال چه دسته‌بندی‌هایی هستید؟</label>
      <div class="choice-grid choice-grid--auto">
        <?php
        $selectedCats = $ov['interested_categories'] ?? [];
        foreach ($parentCategories as $cat):
            $checked = in_array((string)$cat['id'], array_map('strval', $selectedCats), true)
                    || in_array((int)$cat['id'], array_map('intval', $selectedCats), true);
        ?>
        <label class="card choice-card choice-card--compact">
          <input type="checkbox" name="interested_categories[]" value="<?= $cat['id'] ?>" <?= $checked ? 'checked' : '' ?>>
          <span><?= h($cat['name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-group mb-6">
      <label class="form-label" for="onboarding_city">شهر محل فعالیت</label>
      <input type="text" class="form-control" id="onboarding_city" name="onboarding_city"
             value="<?= h($ov['city'] ?? $user['city'] ?? '') ?>" placeholder="شهر شما">
    </div>

    <div class="form-group mb-6">
      <label class="form-label" for="typical_value_range">حدود ارزش کالاهایی که معمولاً معامله می‌کنید</label>
      <select class="form-control" id="typical_value_range" name="typical_value_range">
        <option value="">انتخاب کنید</option>
        <?php
        $ranges = [
            'under_1m'  => 'زیر ۱ میلیون تومان',
            '1m_5m'     => '۱ تا ۵ میلیون تومان',
            '5m_20m'    => '۵ تا ۲۰ میلیون تومان',
            '20m_100m'  => '۲۰ تا ۱۰۰ میلیون تومان',
            'over_100m' => 'بالای ۱۰۰ میلیون تومان',
        ];
        foreach ($ranges as $val => $label):
        ?>
        <option value="<?= $val ?>" <?= ($ov['typical_value_range'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group mb-0">
      <label class="form-label">آیا امکان ارسال دارید یا فقط حضوری معامله می‌کنید؟</label>
      <div class="choice-grid choice-grid--2">
        <?php
        $shipOpts = ['1' => 'امکان ارسال دارم', '0' => 'فقط حضوری'];
        foreach ($shipOpts as $val => $label):
            $checked = isset($ov['can_ship']) && (string)$ov['can_ship'] === $val;
        ?>
        <label class="card choice-card">
          <input type="radio" name="can_ship" value="<?= $val ?>" <?= $checked ? 'checked' : '' ?>>
          <span class="choice-card__label"><?= $label ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
