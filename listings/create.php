<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/iso.php';
require_once __DIR__ . '/../includes/ai_moderation.php';

$user = auth_user();
$isGuest = !$user;
$isStoreSeller = $user ? is_store_seller($user) : false;
// حالت فروشگاهی فقط از پنل فروشگاه (/store) فعال می‌شود؛ ورود مستقیم به /listings/create = آگهی شخصی
$storeMode = $isStoreSeller && (($_GET['as'] ?? '') === 'store');
$listingProviderType = $isStoreSeller ? user_provider_type($user) : 'normal_store';

if ($user && ($listingErr = kyc_check_listing_action($user))) {
    render_full_page_modal(
        'تکمیل پروفایل لازم است',
        $listingErr,
        'رفتن به پروفایل',
        APP_URL . '/profile/edit',
        'bi-person-fill'
    );
}

// سقف آگهی‌های فعال: کاربران رایگان تا ۲۰ آگهی و مشترکین تا سقف پلن
if ($user && !can_create_listing($user)) {
    $limit     = get_listing_limit($user);
    $activeSub = get_active_subscription($user);
    $limitMsg  = $activeSub
        ? 'شما به سقف ' . fmt_num($limit) . ' آگهی فعال پلن «' . h($activeSub['name']) . '» رسیده‌اید. برای ثبت آگهی بیشتر، یکی از آگهی‌های فعال خود را ببندید یا پلن بالاتری تهیه کنید.'
        : 'شما به سقف ' . fmt_num($limit) . ' آگهی رایگان رسیده‌اید. برای ثبت آگهی جدید، یکی از آگهی‌های فعال خود را ببندید یا یکی از پلن‌های اشتراک را تهیه کنید.';
    render_full_page_modal(
        'سقف آگهی‌های فعال شما تکمیل شده',
        $limitMsg,
        'مشاهده پلن‌های اشتراک',
        APP_URL . '/subscription',
        'bi-gem'
    );
}

$errors = [];
$vals = [
    'title'           => '',
    'description'     => '',
    'category_id'     => 0,
    'condition'       => 'good',
    'city'            => '',
    'latitude'        => '',
    'longitude'       => '',
    'neighborhood'    => '',
    'want_category_id' => 0,
    'want_description' => '',
    'custom_value'    => 0,
    'estimated_value' => 0,
    'sell_price'      => 0,
    'listing_mode'    => '',
    'deal_type'       => 'swap',
];

$suggestedValue = (int)($_POST['suggested_value'] ?? rand(10000000, 50000000));

// کلاب مبدأ (ثبت آگهی از داخل کلاب): پس از انتشار، آگهی به کلاب متصل و بازگشت داده می‌شود
$attachClub = (int)($_POST['attach_club'] ?? $_GET['club'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['prefill_title'])) {
        $vals['title'] = clean($_GET['prefill_title']);
    }
    if (isset($_GET['prefill_desc'])) {
        $vals['description'] = clean($_GET['prefill_desc']);
    }
    if (isset($_GET['prefill_cat'])) {
        $vals['category_id'] = (int)$_GET['prefill_cat'];
    }
    if (isset($_GET['prefill_cond'])) {
        $allowedCond = ['new', 'like_new', 'good', 'fair', 'poor'];
        $prefillCond = clean($_GET['prefill_cond']);
        if (in_array($prefillCond, $allowedCond, true)) {
            $vals['condition'] = $prefillCond;
        }
    }
    if (isset($_GET['prefill_value'])) {
        $suggestedValue = max(0, (int)$_GET['prefill_value']);
        $vals['estimated_value'] = $suggestedValue;
    }
}

// Category data
$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);

// Exchange categories for step 4 — از همان درخت واحد دسته‌بندی‌ها (سند — بخش ۱۳)
$exchangeCategories = [];
try {
    swaapin_ensure_category_tree();
} catch (Throwable $e) {
    swapin_debug_log('create_exchange_tree_skip', ['msg' => $e->getMessage()]);
}
$catTreeRows  = DB::fetchAll('SELECT id, name, slug, parent_id, icon FROM categories WHERE is_active = 1 ORDER BY sort_order, id');
$catTreeByPar = [];
foreach ($catTreeRows as $r) {
    $catTreeByPar[(int)($r['parent_id'] ?? 0)][] = $r;
}
foreach ($catTreeByPar[0] ?? [] as $topRow) {
    foreach ($catTreeByPar[(int)$topRow['id']] ?? [] as $l2Row) {
        $icon = trim((string)($l2Row['icon'] ?? ''));
        if ($icon === '') {
            $icon = trim((string)($topRow['icon'] ?? ''));
        }
        $exchangeCategories[] = [
            'name' => category_label((string)$l2Row['slug'], (string)$l2Row['name']),
            'icon' => 'bi ' . preg_replace('/^bi\s+/', '', $icon !== '' ? $icon : 'bi-collection'),
        ];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    // مهمان اجازه انتشار ندارد؛ پس از ورود دوباره تلاش می‌کند
    if (!$user) {
        header('Location: ' . APP_URL . '/auth/login?redirect=' . urlencode('/listings/create'));
        exit;
    }
    
    $vals = [
        'title'           => clean($_POST['title']           ?? ''),
        'description'     => clean($_POST['description']     ?? ''),
        'category_id'     => (int)($_POST['category_id']     ?? 0),
        'condition'       => clean($_POST['condition']       ?? 'good'),
        'city'            => clean($_POST['city']            ?? ''),
        'latitude'        => clean($_POST['latitude']        ?? ''),
        'longitude'       => clean($_POST['longitude']       ?? ''),
        'neighborhood'    => clean($_POST['neighborhood']    ?? ''),
        'want_category_id' => (int)($_POST['want_category_id'] ?? 0),
        'want_description' => clean($_POST['want_description'] ?? ''),
        'custom_value'    => (int)($_POST['custom_value']    ?? 0),
        'estimated_value' => 0,
        'sell_price'      => max(0, min((float)($_POST['sell_price']    ?? 0), 99999999999999.00)),
        'listing_mode'    => clean($_POST['listing_mode'] ?? ''),
        'deal_type'       => clean($_POST['deal_type'] ?? ($_POST['listing_mode'] ?? 'swap')),
    ];
    $suggestedValue = (int)($_POST['suggested_value'] ?? $suggestedValue);
    $vals['estimated_value'] = $vals['custom_value'] > 0 ? $vals['custom_value'] : $suggestedValue;
    $vals['estimated_value'] = max(0, min((float)$vals['estimated_value'], 999999999999.99));

    if ($storeMode) {
        $vals['listing_mode'] = 'both';
    }
    if (!$storeMode && !in_array($vals['listing_mode'], ['sell', 'both'], true)) {
        $vals['sell_price'] = 0;
    }

    // Validation
    $errors = [];
    if (mb_strlen($vals['title']) < 5) $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد';
    if (mb_strlen($vals['title']) > 200) $errors['title'] = 'عنوان باید کمتر از ۲۰۰ کاراکتر باشد';
    if (!$vals['category_id']) $errors['category_id'] = 'لطفا دسته‌بندی را انتخاب کنید';
    if ($vals['category_id'] && !wizard_validate_root_category_id($vals['category_id'])) $errors['category_id'] = 'دسته‌بندی انتخاب‌شده نامعتبر است';
    if (!$vals['want_category_id'] && in_array($vals['listing_mode'], ['swap', 'both'], true)) {
        $errors['want_category_id'] = 'لطفاً دسته‌بندی درخواستی را انتخاب کنید';
    }
    if (mb_strlen($vals['description']) < 20) $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد';
    if (!$storeMode && !in_array($vals['listing_mode'], ['swap', 'sell', 'both'], true)) {
        $errors['listing_mode'] = 'نوع معامله را انتخاب کنید';
    }
    if (empty($vals['deal_type']) || !in_array($vals['deal_type'], ['swap', 'sell', 'both'], true)) {
        $errors['deal_type'] = 'انتخاب نوع معامله (deal_type) الزامی است';
    }
    if ($storeMode && $vals['sell_price'] <= 0)
        $errors['sell_price'] = 'قیمت فروش برای فروشگاه‌ها الزامی است.';
    if (!$storeMode && in_array($vals['listing_mode'], ['sell', 'both'], true) && $vals['sell_price'] <= 0)
        $errors['sell_price'] = 'برای آگهی فروش، وارد کردن قیمت فروش الزامی است.';

    $location = listing_location_from_request($vals);
    foreach (validate_listing_location($location) as $field => $msg) {
        $errors[$field] = $msg;
    }
    $vals = array_merge($vals, $location);

    if (empty($errors)) {
        $hasImageUpload = false;
        if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
            foreach ($_FILES['images']['name'] as $i => $name) {
                if ($name && ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $hasImageUpload = true;
                    break;
                }
            }
        }
        if (!$hasImageUpload) {
            $errors['images'] = 'حداقل یک تصویر الزامی است.';
        }
    }

    if (empty($errors)) {
        // Insert listing
        $listingAttrs = listing_attrs_from_input($listingProviderType, $_POST);
        // برند/مدل/تیپ انتخاب‌شده از فهرست (سند معماری سئو — بخش ۱ و ۲)
        $catalogSel   = swaapin_catalog_selection_from_input((int) $vals['category_id'], $_POST);
        $listingAttrs = array_merge($listingAttrs, $catalogSel['attrs']);
        $listingId = DB::insert('listings', array_merge([
            'user_id'          => $user['id'],
            'category_id'      => $vals['category_id'],
            'want_category_id' => $vals['want_category_id'] ?: null,
            'title'            => $vals['title'],
            'description'      => $vals['description'],
            'condition'        => $vals['condition'],
            'estimated_value'  => $vals['estimated_value'] ?: 0,
            'want_in_return'   => $vals['want_description'] ?: '',
            'want_type'        => 'any',
            'listing_mode'     => $vals['listing_mode'],
            'deal_type'        => $vals['deal_type'],
            'sell_price'       => $vals['sell_price'],
            'needs_inspection' => 0,
            'city'             => $vals['city'] ?: null,
            'status'           => 'active',
            'review_status'    => 'pending',
        ], listing_location_db_payload($location), listing_attrs_db_payload($listingAttrs), swaapin_catalog_columns_payload($catalogSel)));

        // Handle image uploads
        $uploadedImages = 0;
        if (!empty($_FILES['images']['name'][0])) {
            foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                if ($uploadedImages >= MAX_IMAGES) break;
                if (empty($tmp) || ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $file = [
                    'name'     => $_FILES['images']['name'][$i],
                    'tmp_name' => $tmp,
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                $filename = upload_image($file, 'listing');
                if ($filename) {
                    DB::insert('listing_images', [
                        'listing_id' => $listingId,
                        'filename'   => $filename,
                        'is_primary' => $uploadedImages === 0 ? 1 : 0,
                        'sort_order' => $uploadedImages,
                    ]);
                    $uploadedImages++;
                }
            }
        }

        if ($uploadedImages === 0) {
            DB::query('DELETE FROM listings WHERE id = ? AND user_id = ?', [$listingId, $user['id']]);
            $errors['images'] = 'آپلود تصاویر ناموفق بود. لطفاً دوباره تلاش کنید. اگر مشکل ادامه داشت، با پشتیبانی تماس بگیرید.';
        } else {
            // Clear cache
            ai_match_clear_cache((int)$user['id']);

            // Process ISO reverse matches + SMS notifications (best-effort, never block flow)
            try {
                if (function_exists('iso_process_new_listing_matches')) {
                    iso_process_new_listing_matches((int)$listingId);
                }
            } catch (Throwable) {
            }

            // AI Moderation (always best-effort — never block user flow)
            try {
                if (function_exists('ai_mod_review_listing')) {
                    ai_mod_review_listing((int)$listingId);
                }
            } catch (Throwable $e) {
                swapin_debug_log('ai-moderation-create-failed', [
                    'listing_id' => $listingId,
                    'err'        => $e->getMessage(),
                ]);
            }

            // اتصال آگهی به کلاب مبدأ (ثبت آگهی از داخل کلاب)
            if ($attachClub > 0) {
                try {
                    require_once __DIR__ . '/../includes/clubs.php';
                    if (function_exists('clubs_attach_listing')) {
                        clubs_attach_listing($attachClub, (int)$user['id'], (int)$listingId);
                    }
                } catch (Throwable $e) {
                    swapin_debug_log('listing-attach-club-failed', ['msg' => $e->getMessage(), 'club' => $attachClub]);
                }
            }

            // Redirect to success page
            header('Location: ' . APP_URL . '/listings/success?id=' . $listingId . ($attachClub > 0 ? '&club=' . $attachClub : ''));
            exit;
        }
    }
}

render_head('ثبت آگهی جدید');
render_navbar($user);

// تعیین هدف آگهی برای نمایش اولیه (فروشگاه‌ها در پنل خود همیشه «فروش یا معاوضه»)
$dealType = in_array(($vals['deal_type'] ?? 'swap'), ['swap', 'sell', 'both'], true) ? $vals['deal_type'] : 'swap';
if ($storeMode) {
    $dealType = 'both';
}
?>

<div class="wizard-page listing-create-page" data-current-step="1">
  <!-- Stepper Header -->
  <div class="wizard-header">
    <div class="wizard-container" style="padding: var(--wizard-gap) var(--wizard-gap);">
      <div class="stepper">
        <div class="stepper-progress" id="stepper-progress"></div>
        <?php 
        $stepLabels = [
          1 => 'هدف آگهی',
          2 => 'اطلاعات آگهی',
          3 => 'جزئیات معامله',
          4 => 'بررسی نهایی',
        ];
        for ($i = 1; $i <= 4; $i++): ?>
          <div class="stepper-step" data-step="<?= $i ?>" id="step-<?= $i ?>-indicator">
            <div class="stepper-circle">
              <i class="bi bi-check-lg" style="display: none;"></i>
            </div>
            <div class="stepper-label"><?= $stepLabels[$i] ?></div>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  <div class="wizard-container">
    <?php if (!empty($errors['images'])): ?>
    <div class="wizard-alert wizard-alert--error">
      <i class="bi bi-exclamation-circle"></i> <?= h($errors['images']) ?>
    </div>
    <?php elseif (!empty($errors)): ?>
    <div class="wizard-alert wizard-alert--error">
      <i class="bi bi-exclamation-circle"></i> لطفاً خطاهای مشخص‌شده را برطرف کنید.
    </div>
    <?php endif; ?>

    <!-- Step Content Card -->
    <div class="wizard-card">
      <div class="wizard-draft-notice" id="wz-draft-notice" style="display:none">
        <i class="bi bi-clock-history"></i>
        <span>پیش‌نویس قبلی شما بازیابی شد. توجه: تصاویر ذخیره نمی‌شوند و باید دوباره انتخاب شوند.</span>
        <button type="button" class="wizard-draft-notice__clear" id="wz-draft-clear">حذف پیش‌نویس</button>
      </div>
      <form method="POST" id="wizard-form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="attach_club" value="<?= (int)$attachClub ?>">

        <!-- File input always in form root (not inside hidden steps) -->
        <input type="file" id="wz-images" name="images[]" multiple accept="image/jpeg,image/png,image/webp"
               class="wizard-file-input" tabindex="-1" aria-hidden="true">

        <!-- Step 1: هدف آگهی -->
        <div class="wizard-step" data-step="1" id="step-1">
          <h2 class="wizard-step-title">می‌خوای با کالای خودت چه کاری انجام بدی؟</h2>
          <p class="wizard-step-subtitle">
            <?= $storeMode ? 'فروشگاه‌ها همیشه هم برای معاوضه و هم برای فروش نقدی آگهی ثبت می‌کنند.' : 'با انتخاب هدف آگهی، مراحل بعدی بر اساس انتخاب شما تنظیم می‌شود.' ?>
          </p>

          <div class="listing-mode-switch">
            <?php if (!$storeMode): ?>
            <label class="listing-mode-option">
              <input type="radio" name="deal_type" value="sell" id="deal-type-sell" <?= $dealType === 'sell' ? 'checked' : '' ?>>
              <span class="listing-mode-option__inner">
                <i class="bi bi-tag-fill"></i>
                <span class="listing-mode-option__copy">
                  <strong>فروش</strong>
                  <small>دریافت پول در ازای کالا</small>
                </span>
                <span class="listing-mode-option__radio" aria-hidden="true"></span>
              </span>
            </label>
            <label class="listing-mode-option">
              <input type="radio" name="deal_type" value="swap" id="deal-type-swap" <?= $dealType === 'swap' ? 'checked' : '' ?>>
              <span class="listing-mode-option__inner">
                <i class="bi bi-arrow-left-right"></i>
                <span class="listing-mode-option__copy">
                  <strong>معاوضه</strong>
                  <small>دریافت کالای دیگر</small>
                </span>
                <span class="listing-mode-option__radio" aria-hidden="true"></span>
              </span>
            </label>
            <?php endif; ?>
            <label class="listing-mode-option">
              <input type="radio" name="deal_type" value="both" id="deal-type-both" <?= $dealType === 'both' ? 'checked' : '' ?>>
              <span class="listing-mode-option__inner">
                <i class="bi bi-tags-fill"></i>
                <span class="listing-mode-option__copy">
                  <strong>فروش یا معاوضه</strong>
                  <small>هر دو پیشنهاد قابل قبول است</small>
                </span>
                <span class="listing-mode-option__radio" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <input type="hidden" name="listing_mode" id="wz-listing-mode" value="<?= h($dealType) ?>">
          <?php if (isset($errors['deal_type']) || isset($errors['listing_mode'])): ?>
          <div class="invalid-feedback" style="display:block;margin-top:var(--wizard-gap)"><?= h($errors['deal_type'] ?? $errors['listing_mode']) ?></div>
          <?php endif; ?>
        </div>

        <!-- Step 2: اطلاعات اصلی آگهی -->
        <div class="wizard-step" data-step="2" id="step-2" style="display:none">
          <h2 class="wizard-step-title">اطلاعات اصلی آگهی</h2>
          <p class="wizard-step-subtitle">عنوان، توضیحات، تصاویر و جزئیات کالا را وارد کنید.</p>

          <div class="wizard-form-group">
            <div class="wizard-label-row">
              <label class="wizard-form-label" for="wz-title">عنوان آگهی *</label>
              <button type="button" class="ai-improve-btn" data-ai-improve="title">
                <i class="bi bi-stars"></i> بهبود با هوش مصنوعی
              </button>
            </div>
            <input type="text" name="title" id="wz-title" class="wizard-form-input" 
                   placeholder="مثلا آیفون ۱۳ پرو مکس ۲۵۶ گیگ" maxlength="200" value="<?= h($vals['title']) ?>">
            <div class="char-count"><span id="wz-title-count"><?= mb_strlen((string)$vals['title']) ?></span>/200 کاراکتر</div>
          </div>

          <div class="wizard-form-group">
            <div class="wizard-label-row">
              <label class="wizard-form-label" for="wz-desc">توضیحات *</label>
              <button type="button" class="ai-improve-btn" data-ai-improve="description">
                <i class="bi bi-stars"></i> بهبود با هوش مصنوعی
              </button>
            </div>
            <textarea name="description" id="wz-desc" class="wizard-form-textarea" rows="4"
                      placeholder="کالا را با جزئیات توضیح دهید — سن، برند، مشخصات، ایرادات…"><?= h($vals['description']) ?></textarea>
            <div class="char-count"><span id="wz-desc-count"><?= mb_strlen((string)$vals['description']) ?></span> کاراکتر</div>
          </div>

          <div class="wizard-form-group">
            <label class="wizard-form-label">تصاویر *</label>
            <div class="upload-zone" id="wz-upload-zone">
              <div class="upload-zone-icon">
                <i class="bi bi-cloud-arrow-up"></i>
              </div>
              <p class="upload-zone-text">تصاویر را اینجا رها کنید یا برای آپلود کلیک کنید</p>
              <p class="upload-zone-subtext">JPG، PNG یا WEBP — حداکثر ۵ مگابایت — تا <?= MAX_IMAGES ?> تصویر</p>
            </div>
            <div class="image-previews" id="wz-preview-grid"></div>
          </div>

          <div class="wizard-form-row">
            <div class="wizard-form-group">
              <label class="wizard-form-label">دسته‌بندی *</label>
              <?= render_wizard_category_picker((int)$vals['category_id'], true) ?>
              <?php if (isset($errors['category_id'])): ?>
              <div class="invalid-feedback" style="display:block"><?= h($errors['category_id']) ?></div>
              <?php endif; ?>
            </div>

            <div class="wizard-form-group">
              <label class="wizard-form-label" for="wz-condition">وضعیت کالا *</label>
              <select name="condition" id="wz-condition" class="wizard-form-select">
                <option value="new" <?= $vals['condition'] === 'new' ? 'selected' : '' ?>>نو</option>
                <option value="like_new" <?= $vals['condition'] === 'like_new' ? 'selected' : '' ?>>مثل نو</option>
                <option value="good" <?= $vals['condition'] === 'good' ? 'selected' : '' ?>>خوب</option>
                <option value="fair" <?= $vals['condition'] === 'fair' ? 'selected' : '' ?>>متوسط</option>
                <option value="poor" <?= $vals['condition'] === 'poor' ? 'selected' : '' ?>>خورده</option>
              </select>
            </div>
          </div>

          <!-- برند / مدل / تیپ از فهرست (سند معماری سئو — بخش ۱ و ۲) -->
          <div class="wizard-form-group" id="catalog-picker" style="display:none">
            <label class="wizard-form-label" for="catalog-brand">برند *</label>
            <select name="catalog_brand" id="catalog-brand" class="wizard-form-select">
              <option value="">انتخاب برند…</option>
            </select>
            <label class="wizard-form-label" for="catalog-model" style="margin-top:10px">مدل *</label>
            <select name="catalog_model" id="catalog-model" class="wizard-form-select" disabled>
              <option value="">انتخاب مدل…</option>
            </select>
            <div id="catalog-trim-wrap" style="display:none">
              <label class="wizard-form-label" for="catalog-trim" style="margin-top:10px">تیپ</label>
              <select name="catalog_trim" id="catalog-trim" class="wizard-form-select" disabled>
                <option value="">انتخاب تیپ…</option>
              </select>
            </div>
          </div>

          <div class="wizard-form-group">
            <label class="wizard-form-label" for="wz-city">شهر *</label>
            <select name="city" id="wz-city" class="wizard-form-select <?= isset($errors['city']) ? 'is-invalid' : '' ?>">
              <option value="">انتخاب شهر</option>
              <?= render_city_options($vals['city']) ?>
            </select>
            <?php if (isset($errors['city'])): ?>
            <div class="invalid-feedback"><?= h($errors['city']) ?></div>
            <?php endif; ?>
          </div>

          <?php
          $picker = [
              'prefix'    => 'wz',
              'city'      => $vals['city'],
              'latitude'  => $vals['latitude'],
              'longitude' => $vals['longitude'],
              'neighborhood' => $vals['neighborhood'],
              'errors'    => $errors,
              'city_select_id' => 'wz-city',
          ];
          include __DIR__ . '/../includes/listing_location_picker.php';
          ?>

          <?= render_listing_type_fields($listingProviderType, listing_attrs_from_input($listingProviderType, $_POST ?? []), 'wizard', ['brand', 'model']) ?>
        </div>

        <!-- Step 3: جزئیات معامله (داینامیک بر اساس هدف آگهی) -->
        <div class="wizard-step" data-step="3" id="step-3" style="display:none">

          <!-- مسیر فروش: فقط قیمت فروش -->
          <div id="wz-sell-block" style="display:none">
            <h2 class="wizard-step-title">قیمت فروش</h2>
            <p class="wizard-step-subtitle">قیمت نقدی کالای خود را مشخص کنید.</p>

            <div class="price-estimate-card">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:var(--wizard-gap)">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;background:rgba(8,27,69,.08);color:var(--primary);font-weight:800;font-size:.8125rem">۱</span>
                <h3 style="font-size:1rem;font-weight:800;margin:0;color:var(--primary)">تخمین قیمت با دستیار هوشمند</h3>
              </div>
              <p class="price-estimate-note" style="margin:0 0 var(--wizard-gap) 0">دستیار هوشمند سواپین بر اساس مشخصات کالا یک قیمت پیشنهادی بازار ارائه می‌دهد؛ می‌توانید آن را بپذیرید یا تغییر دهید.</p>
              <div style="display:flex;gap:8px;align-items:center">
                <input type="text" id="wz-sell-suggested-price" class="wizard-form-input"
                       value="<?= h(number_format($suggestedValue)) ?>" readonly style="flex:1">
                <button type="button" id="wz-use-suggested" class="wizard-btn wizard-btn-secondary" style="white-space:nowrap">استفاده</button>
              </div>
            </div>

            <div class="wizard-form-group" style="margin-top:var(--wizard-gap)">
              <label class="wizard-form-label" for="wz-sell-price">
                قیمت فروش نقدی (تومان) <span style="color:var(--danger)">*</span>
              </label>
              <input type="number" id="wz-sell-price" name="sell_price" class="wizard-form-input"
                     placeholder="مثلاً ۳۲٬۰۰۰٬۰۰۰ — این مبلغ به‌صورت نقدی قابل خرید خواهد بود" min="0" step="1000"
                     value="<?= $vals['sell_price'] > 0 ? h((string)$vals['sell_price']) : '' ?>">
              <p class="price-estimate-note" style="margin-top: var(--wizard-gap)">
                <i class="bi bi-bag-check"></i>
                کاربران در صفحه آگهی دکمه «خرید» را می‌بینند و با این مبلغ نقدی خرید می‌کنند.
              </p>
              <?php if (isset($errors['sell_price'])): ?>
              <div class="invalid-feedback" style="display:block"><?= h($errors['sell_price']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <!-- مسیر معاوضه / فروش یا معاوضه -->
          <div id="wz-swap-block" style="display:none">
            <h2 class="wizard-step-title">به دنبال چه چیزی هستید؟</h2>
            <p class="wizard-step-subtitle">دسته کالایی که قصد دریافت آن را دارید انتخاب کنید.</p>

            <div class="wizard-form-group">
              <label class="wizard-form-label">دسته‌بندی کالای موردنظر <span style="color:var(--danger)">*</span></label>
              <?= render_wizard_category_picker((int)($vals['want_category_id'] ?? 0), true, 'want_category_id', 'want-cat-pick') ?>
              <p class="form-hint" style="margin-top: 8px; font-size: 0.75rem; color: var(--text-muted);">
                انتخاب دقیق دسته‌بندی به ما کمک می‌کند آگهی شما را به افراد مناسب‌تری نشان دهیم.
              </p>
              <?php if (isset($errors['want_category_id'])): ?>
              <div class="invalid-feedback" style="display:block"><?= h($errors['want_category_id']) ?></div>
              <?php endif; ?>
            </div>

            <div class="wizard-form-group">
              <label class="wizard-form-label" for="wz-want-desc">توضیحات کالای درخواستی</label>
              <textarea name="want_description" id="wz-want-desc" class="wizard-form-textarea" rows="4"
                        placeholder="مثلا فقط آیفون ۱۵ پرو تمیز با سلامت باتری بالای ۹۰ درصد…"><?= h($vals['want_description']) ?></textarea>
            </div>

            <div class="price-estimate-card">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:var(--wizard-gap)">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:8px;background:rgba(8,27,69,.08);color:var(--primary);font-weight:800;font-size:.8125rem">۱</span>
                <h3 style="font-size:1rem;font-weight:800;margin:0;color:var(--primary)">قیمت معاوضه (تخمین ارزش کالا)</h3>
              </div>
              <p class="price-estimate-note" style="margin:0 0 var(--wizard-gap) 0">این قیمت برای پیشنهاد معاوضه، محاسبه «اختلاف قیمت» و امتیازدهی هوشمند استفاده می‌شود.</p>
              <p class="price-estimate-label">قیمت پیشنهاد سیستم (تومان)</p>
              <input type="text" id="wz-swap-suggested-price" class="wizard-form-input"
                     value="<?= h(number_format($suggestedValue)) ?>" readonly>
            </div>

            <div class="wizard-form-group" style="margin-top:var(--wizard-gap)">
              <label class="wizard-form-label" for="wz-custom-price">
                قیمت دلخواه شما برای معاوضه (اختیاری)
              </label>
              <input type="text" id="wz-custom-price" class="wizard-form-input"
                     placeholder="مثلاً ۲۵٬۰۰۰٬۰۰۰ تومان — اگر خالی بگذارید قیمت پیشنهادی سیستم ثبت می‌شود"
                     value="<?= $vals['custom_value'] > 0 ? h(number_format($vals['custom_value'])) : '' ?>">
              <p class="price-estimate-note" style="margin-top: var(--wizard-gap)">
                <i class="bi bi-info-circle" style="color:var(--primary)"></i>
                این قیمت صرفاً برای محاسبه ارزش معاوضه و اختلاف قیمت شما با طرف مقابل است؛ «قیمت خرید نقدی» نیست.
              </p>
            </div>
          </div>
        </div>

        <input type="hidden" id="wz-suggested-value" name="suggested_value" value="<?= h($suggestedValue) ?>">
        <input type="hidden" id="wz-custom-value" name="custom_value" value="<?= h($vals['custom_value']) ?>">
        <input type="hidden" id="wz-estimated-value" name="estimated_value" value="<?= h($vals['estimated_value'] ?: $suggestedValue) ?>">

        <!-- Step 4: Review -->
        <div class="wizard-step" data-step="4" id="step-4" style="display:none">
          <div class="review-header">
            <h2 class="wizard-step-title" style="margin:0">بررسی نهایی</h2>
            <div style="display:flex;gap:8px">
              <button type="button" class="edit-btn" onclick="goToStep(2)">
                <i class="bi bi-pencil"></i> ویرایش اطلاعات
              </button>
              <button type="button" class="edit-btn" id="wz-edit-deal-btn" onclick="goToStep(3)">
                <i class="bi bi-pencil"></i> ویرایش جزئیات معامله
              </button>
            </div>
          </div>
          <p class="wizard-step-subtitle">تمام اطلاعات را بررسی کنید.</p>

          <div class="review-grid">
            <div class="review-section review-section--full">
              <p class="review-label"><i class="bi bi-images"></i> تصاویر</p>
              <div class="review-images" id="wz-review-images"></div>
            </div>

            <div class="review-section">
              <p class="review-label"><i class="bi bi-bullseye"></i> هدف آگهی</p>
              <p class="review-value" id="wz-review-goal"></p>
            </div>

            <div class="review-section">
              <p class="review-label"><i class="bi bi-type"></i> عنوان</p>
              <p class="review-value" id="wz-review-title"></p>
            </div>

            <div class="review-section review-section--full">
              <p class="review-label"><i class="bi bi-text-paragraph"></i> توضیحات</p>
              <p class="review-value" id="wz-review-desc"></p>
            </div>

            <div class="review-section">
              <p class="review-label"><i class="bi bi-diagram-3"></i> دسته‌بندی</p>
              <p class="review-value" id="wz-review-category"></p>
            </div>

            <div class="review-section">
              <p class="review-label"><i class="bi bi-patch-check"></i> وضعیت کالا</p>
              <p class="review-value" id="wz-review-condition"></p>
            </div>

            <div class="review-section">
              <p class="review-label"><i class="bi bi-geo-alt"></i> شهر و موقعیت</p>
              <p class="review-value" id="wz-review-city"></p>
              <p class="review-value fs-sm" id="wz-review-neighborhood" style="color:var(--text-muted)"></p>
            </div>

            <div class="review-section" id="wz-review-sell-section">
              <p class="review-label"><i class="bi bi-cash-coin"></i> قیمت فروش</p>
              <p class="review-value" id="wz-review-sell-price"></p>
            </div>

            <div class="review-section" id="wz-review-wantcat-section">
              <p class="review-label"><i class="bi bi-arrow-left-right"></i> دسته‌بندی کالای موردنظر</p>
              <p class="review-value" id="wz-review-want-cat"></p>
            </div>

            <div class="review-section review-section--full" id="wz-review-wantdesc-section">
              <p class="review-label"><i class="bi bi-chat-left-text"></i> توضیحات کالای درخواستی</p>
              <p class="review-value" id="wz-review-want-desc"></p>
            </div>

            <div class="review-section" id="wz-review-swap-section">
              <p class="review-label"><i class="bi bi-calculator"></i> قیمت تخمینی معاوضه</p>
              <p class="review-value" id="wz-review-swap-price"></p>
            </div>
          </div>
        </div>

        <!-- Wizard Footer -->
        <div class="wizard-footer">
          <div>
            <button type="button" id="wizard-back-btn" class="wizard-btn wizard-btn-secondary" style="display:none">
              بازگشت
            </button>
          </div>
          <div class="wizard-footer-right">
            <button type="button" id="wizard-next-btn" class="wizard-btn wizard-btn-primary">
              <i class="bi bi-chevron-right" aria-hidden="true"></i> ادامه
            </button>
            <button type="submit" id="wizard-submit-btn" class="wizard-btn wizard-btn-primary" style="display:none">
              انتشار آگهی
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/listing-wizard.css?v=<?= filemtime(__DIR__ . '/../src/css/listing-wizard.css') ?>">
<style>
.wizard-category-picker { position: relative; width: 100%; }
.cat-pick-trigger {
  display: flex; align-items: center; gap: 8px;
  width: 100%; min-height: 36px; padding: 6px 12px;
  background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px;
  cursor: pointer; transition: all .15s ease; user-select: none;
}
.cat-pick-trigger:hover { border-color: var(--primary,#0066FF); box-shadow: 0 0 0 3px rgba(0,102,255,.08); }
.cat-pick-trigger.cat-pick-open { border-color: var(--primary,#0066FF); box-shadow: 0 0 0 3px rgba(0,102,255,.12); }
.cat-pick-trigger[data-empty="1"] .cat-pick-trigger-label { color: #9ca3af; }
.cat-pick-trigger[data-empty="0"] .cat-pick-trigger-label { color: #111827; font-weight: 600; }
.cat-pick-trigger-icon { color: var(--primary,#0066FF); font-size: .9rem; flex-shrink: 0; }
.cat-pick-trigger-label { flex: 1; text-align: right; font-size: .8125rem; }
.cat-pick-trigger-caret { color: #6b7280; font-size: .75rem; transition: transform .2s ease; }
.cat-pick-trigger.cat-pick-open .cat-pick-trigger-caret { transform: rotate(180deg); }

.cat-pick-menu {
  position: absolute; top: calc(100% + 6px); right: 0; left: 0;
  z-index: 9999; display: none;
  max-height: 480px; overflow-y: auto; overflow-x: visible;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 6px; box-shadow: 0 12px 40px rgba(17,24,39,.12);
  direction: rtl; columns: 2; column-gap: 2px;
}
.cat-pick-menu.show { display: block; }
.wizard-form-row .cat-pick-menu { columns: 1; }
@media (max-width: 640px) { .cat-pick-menu { columns: 1; } }

.cat-pick-menu .dropdown-item {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 10px; border-radius: 8px; color: #1f2937;
  font-size: .875rem; text-decoration: none; cursor: pointer;
  break-inside: avoid; margin-bottom: 2px;
  transition: background-color .1s ease;
}
.cat-pick-menu .dropdown-item:hover { background: rgba(0,102,255,.08); color: var(--primary,#0066FF); }
.cat-pick-menu .cat-pick-selected { background: rgba(0,102,255,.12) !important; color: var(--primary,#0066FF) !important; font-weight: 700; }
.cat-pick-menu .cat-pick-icon { color: #6b7280; font-size: .95rem; width: 20px; text-align: center; flex-shrink: 0; }
.cat-pick-menu .cat-pick-chevron { margin-inline-start: auto; opacity: .55; font-size: .7rem; }
.cat-pick-menu .dropdown-submenu { position: relative; break-inside: avoid; }
.cat-pick-menu .dropdown-submenu > .dropdown-menu--sub {
  position: absolute; top: -6px; right: calc(100% + 4px);
  min-width: 240px; max-height: 460px; overflow-y: auto; overflow-x: visible;
  background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 6px; box-shadow: 0 12px 40px rgba(17,24,39,.12);
  display: none; z-index: 10000;
}
.cat-pick-menu .dropdown-submenu:hover > .dropdown-menu--sub,
.cat-pick-menu .dropdown-submenu:focus-within > .dropdown-menu--sub,
.cat-pick-menu .dropdown-submenu.cat-pick-sub-open > .dropdown-menu--sub { display: block; }

#wz-review-category { padding-right: 0; }
.wizard-form-group.is-invalid .cat-pick-trigger { border-color: var(--danger,#ef4444) !important; box-shadow: 0 0 0 3px rgba(239,68,68,.08); }

</style>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/listing-location.css?v=<?= filemtime(__DIR__ . '/../src/css/listing-location.css') ?>">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="<?= APP_URL ?>/src/js/listing-location.js?v=<?= filemtime(__DIR__ . '/../src/js/listing-location.js') ?>"></script>
<script>
const STORE_MODE = <?= $storeMode ? 'true' : 'false' ?>;
const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
const DRAFT_KEY = <?= json_encode('swaapin_listing_draft_' . ($user ? 'u' . (int)$user['id'] : 'guest')) ?>;
const DRAFT_KEY_GUEST = 'swaapin_listing_draft_guest';
let currentStep = 1;
const totalSteps = 4;
let uploadedFiles = [];

function syncUploadedFilesInput() {
  const input = document.getElementById('wz-images');
  if (!input) return 0;
  const dt = new DataTransfer();
  uploadedFiles.filter(Boolean).forEach(f => dt.items.add(f));
  input.files = dt.files;
  return dt.files.length;
}

// ── هدف آگهی: معاوضه / فروش / فروش یا معاوضه ─────────────────────────────
function getDealType() {
  return document.querySelector('input[name="deal_type"]:checked')?.value || '';
}

function applyDealType() {
  const mode = STORE_MODE ? 'both' : getDealType();
  const hidden = document.getElementById('wz-listing-mode');
  if (hidden && mode) hidden.value = mode;

  const sellBlock = document.getElementById('wz-sell-block');
  const swapBlock = document.getElementById('wz-swap-block');
  if (sellBlock) sellBlock.style.display = (mode === 'sell' || mode === 'both') ? '' : 'none';
  if (swapBlock) swapBlock.style.display = (mode === 'swap' || mode === 'both') ? '' : 'none';

  updateButtons();
}

// Initialize everything
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('input[name="deal_type"]').forEach((el) => {
    el.addEventListener('change', applyDealType);
  });
  updateStepper();
  initStep2Counters();
  initAiImproveButtons();
  initUpload();
  initInfoFields();
  initCatalogPicker();
  initWizardCategoryPicker('cat-pick');
  initWizardCategoryPicker('want-cat-pick');
  initPriceInputs();
  initWizardSubmit();
  restoreWizardDraft();
  initWizardDraftAutosave();
  applyDealType();
});

function initWizardCategoryPicker(prefix) {
  prefix = prefix || 'cat-pick';
  const trigger = document.getElementById(prefix + '-trigger');
  const menu = document.getElementById(prefix + '-menu');
  const labelEl = document.getElementById(prefix + '-label');
  const hiddenEl = document.getElementById(prefix + '-hidden');
  if (!trigger || !menu) return;

  function closeMenu() {
    menu.classList.remove('show');
    trigger.classList.remove('cat-pick-open');
    menu.querySelectorAll('.cat-pick-sub-open').forEach(el => el.classList.remove('cat-pick-sub-open'));
  }
  function openMenu() {
    menu.classList.add('show');
    trigger.classList.add('cat-pick-open');
  }

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    if (menu.classList.contains('show')) closeMenu(); else openMenu();
  });

  document.addEventListener('click', (e) => {
    if (!menu.classList.contains('show')) return;
    if (!menu.contains(e.target) && !trigger.contains(e.target)) closeMenu();
  });

  // Touch support for submenus
  menu.querySelectorAll('.cat-pick-parent').forEach(btn => {
    btn.addEventListener('click', (e) => {
      if (window.matchMedia('(hover: none)').matches || 'ontouchstart' in window) {
        e.preventDefault();
        e.stopPropagation();
        const sub = btn.closest('.dropdown-submenu');
        if (sub) {
          const isOpen = sub.classList.contains('cat-pick-sub-open');
          menu.querySelectorAll('.cat-pick-sub-open').forEach(el => {
            if (el !== sub) el.classList.remove('cat-pick-sub-open');
          });
          if (!isOpen) sub.classList.add('cat-pick-sub-open');
          else sub.classList.remove('cat-pick-sub-open');
        }
      }
    });
  });

  // Leaf selection
  menu.querySelectorAll('.cat-pick-leaf').forEach(leaf => {
    leaf.addEventListener('click', (e) => {
      e.stopPropagation();
      const catId = leaf.getAttribute('data-cat-id');
      const catLabel = leaf.getAttribute('data-cat-label');
      let fullPath = catLabel;
      // Build path from ancestors' text
      const parents = [];
      let el = leaf.parentElement;
      let safety = 10;
      while (el && safety-- > 0) {
        const sub = el.closest('.dropdown-submenu');
        if (!sub) break;
        const parentLink = sub.querySelector(':scope > .cat-pick-parent');
        if (parentLink) {
          const icon = parentLink.querySelector('.cat-pick-icon');
          let txt = parentLink.textContent.trim().replace(/\s+/g, ' ');
          if (icon) txt = txt.replace(icon.textContent.trim(), '').trim();
          txt = txt.replace('‹', '').replace('›', '').trim();
          parents.unshift(txt);
        }
        el = sub.parentElement;
      }
      if (parents.length) fullPath = parents.join(' › ') + ' › ' + catLabel;

      if (hiddenEl) {
        hiddenEl.value = catId;
        // Trigger change event for validation
        hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (labelEl) {
        labelEl.textContent = fullPath;
        trigger.setAttribute('data-empty', '0');
      }
      menu.querySelectorAll('.cat-pick-selected').forEach(el => el.classList.remove('cat-pick-selected'));
      leaf.classList.add('cat-pick-selected');
      closeMenu();
      updateButtons();
    });
  });
}

// ── قیمت‌ها ────────────────────────────────────────────────────────────────
function initPriceInputs() {
  const customInput = document.getElementById('wz-custom-price');
  const customHiddenInput = document.getElementById('wz-custom-value');
  const selectedHiddenInput = document.getElementById('wz-estimated-value');
  const suggestedHiddenInput = document.getElementById('wz-suggested-value');
  const sellInput = document.getElementById('wz-sell-price');
  const useSuggestedBtn = document.getElementById('wz-use-suggested');

  if (customInput && customHiddenInput && selectedHiddenInput && suggestedHiddenInput) {
    const syncPriceValue = () => {
      const rawValue = customInput.value.replace(/[^\d]/g, '');
      customInput.value = rawValue ? Number(rawValue).toLocaleString('en-US') : '';
      customHiddenInput.value = rawValue;
      selectedHiddenInput.value = rawValue || suggestedHiddenInput.value;
    };
    customInput.addEventListener('input', syncPriceValue);
    customInput.addEventListener('input', updateButtons);
    syncPriceValue();
  }

  if (sellInput) {
    sellInput.addEventListener('input', updateButtons);
  }

  if (useSuggestedBtn && suggestedHiddenInput) {
    useSuggestedBtn.addEventListener('click', () => {
      const value = suggestedHiddenInput.value;
      if (value && sellInput) {
        sellInput.value = value;
        updateButtons();
      }
    });
  }
}

let aiPricingKey = '';
let aiPricingBusy = false;

function runWizardAiPricing() {
  const titleEl = document.getElementById('wz-title');
  const descEl = document.getElementById('wz-desc');
  const catEl = document.getElementById('cat-pick-hidden');
  const condEl = document.getElementById('wz-condition');
  const sellSuggestedInput = document.getElementById('wz-sell-suggested-price');
  const swapSuggestedInput = document.getElementById('wz-swap-suggested-price');
  const suggestedHiddenInput = document.getElementById('wz-suggested-value');
  const selectedHiddenInput = document.getElementById('wz-estimated-value');
  const customHiddenInput = document.getElementById('wz-custom-value');
  if (!titleEl || !descEl || !catEl || !condEl || !suggestedHiddenInput) return;
  if (typeof swaapinAiValuateFromBrowser !== 'function') return;

  const title = titleEl.value.trim();
  const desc = descEl.value.trim();
  const catId = catEl.value;
  const cond = condEl.value;
  const key = title + '|' + desc + '|' + catId + '|' + cond;
  if (key === aiPricingKey || aiPricingBusy) return;
  if (title.length < 5 || desc.length < 20 || !(Number(catId) > 0)) return;

  const appUrl = document.querySelector('meta[name="app-url"]')?.content || '';
  const fd = new FormData();
  fd.append('title', title);
  fd.append('description', desc);
  fd.append('condition', cond);
  fd.append('category_id', catId);
  if (typeof appendCsrf === 'function') appendCsrf(fd);

  aiPricingBusy = true;
  if (sellSuggestedInput) sellSuggestedInput.value = 'در حال استعلام قیمت بازار…';
  if (swapSuggestedInput) swapSuggestedInput.value = 'در حال استعلام قیمت بازار…';

  swaapinAiValuateFromBrowser(appUrl, fd).then(data => {
    const value = Math.round(Number(data && data.value) || 0);
    if (value <= 0) return;
    aiPricingKey = key;
    const formatted = Number(value).toLocaleString('en-US');
    if (sellSuggestedInput) sellSuggestedInput.value = formatted;
    if (swapSuggestedInput) swapSuggestedInput.value = formatted;
    suggestedHiddenInput.value = String(value);
    if (!customHiddenInput || !String(customHiddenInput.value || '').replace(/[^\d]/g, '')) {
      selectedHiddenInput.value = String(value);
    }
    // پیش‌پر کردن قیمت فروش در صورت خالی بودن
    const sellInput = document.getElementById('wz-sell-price');
    if (sellInput && !String(sellInput.value || '').trim()) {
      sellInput.value = String(value);
      updateButtons();
    }
  }).catch(() => {
    const fallback = suggestedHiddenInput.value
      ? Number(suggestedHiddenInput.value).toLocaleString('en-US')
      : '';
    if (sellSuggestedInput) sellSuggestedInput.value = fallback;
    if (swapSuggestedInput) swapSuggestedInput.value = fallback;
  }).finally(() => {
    aiPricingBusy = false;
  });
}

function initInfoFields() {
  const catHidden = document.getElementById('cat-pick-hidden');
  const condSelect = document.getElementById('wz-condition');
  const cityInput = document.getElementById('wz-city');
  const locationPicker = document.querySelector('.listing-location-picker');
  if (catHidden && typeof MutationObserver !== 'undefined') {
    const obs = new MutationObserver(() => updateButtons());
    obs.observe(catHidden, { attributes: true, attributeFilter: ['value'] });
  }
  document.addEventListener('change', () => updateButtons());
  if (condSelect) condSelect.addEventListener('change', updateButtons);
  if (cityInput) cityInput.addEventListener('change', updateButtons);
  locationPicker?.addEventListener('listing-location-change', updateButtons);
}

// ── برند / مدل / تیپ از فهرست (سند معماری سئو — بخش ۱ و ۲) ─────────────────
function initCatalogPicker() {
  const catHidden = document.getElementById('cat-pick-hidden');
  const wrap = document.getElementById('catalog-picker');
  const brandSel = document.getElementById('catalog-brand');
  const modelSel = document.getElementById('catalog-model');
  const trimWrap = document.getElementById('catalog-trim-wrap');
  const trimSel = document.getElementById('catalog-trim');
  if (!catHidden || !wrap || !brandSel || !modelSel) return;

  const baseUrl = (document.querySelector('meta[name="app-url"]')?.content || '').replace(/\/$/, '') + '/api/catalog.php';

  function fillSelect(sel, items, placeholder) {
    sel.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    sel.appendChild(first);
    items.forEach((it) => {
      const opt = document.createElement('option');
      opt.value = String(it.id);
      opt.textContent = String(it.name);
      sel.appendChild(opt);
    });
  }

  function hideTrim() {
    if (!trimSel || !trimWrap) return;
    trimSel.value = '';
    trimSel.disabled = true;
    trimSel.innerHTML = '<option value="">انتخاب تیپ…</option>';
    trimWrap.style.display = 'none';
  }

  function resetModels() {
    modelSel.value = '';
    modelSel.disabled = true;
    modelSel.innerHTML = '<option value="">انتخاب مدل…</option>';
    hideTrim();
  }

  async function fetchCatalog(query) {
    const res = await fetch(baseUrl + query, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('catalog_request_failed');
    return res.json();
  }

  async function loadBrands() {
    const catId = parseInt(catHidden.value || '0', 10);
    brandSel.value = '';
    resetModels();
    if (!catId) {
      wrap.style.display = 'none';
      updateButtons();
      return;
    }
    try {
      const data = await fetchCatalog('?cat=' + encodeURIComponent(catId));
      const brands = data.brands || [];
      if (!brands.length) {
        wrap.style.display = 'none';
        updateButtons();
        return;
      }
      fillSelect(brandSel, brands, 'انتخاب برند…');
      wrap.style.display = '';
    } catch (e) {
      wrap.style.display = 'none';
    }
    updateButtons();
  }

  async function loadModels() {
    const catId = parseInt(catHidden.value || '0', 10);
    const brandId = parseInt(brandSel.value || '0', 10);
    resetModels();
    if (!catId || !brandId) {
      updateButtons();
      return;
    }
    try {
      const data = await fetchCatalog('?cat=' + encodeURIComponent(catId) + '&brand=' + encodeURIComponent(brandId));
      const models = data.models || [];
      fillSelect(modelSel, models, models.length ? 'انتخاب مدل…' : 'مدلی ثبت نشده');
      modelSel.disabled = models.length === 0;
    } catch (e) {
    }
    updateButtons();
  }

  async function loadTrims() {
    const catId = parseInt(catHidden.value || '0', 10);
    const brandId = parseInt(brandSel.value || '0', 10);
    const modelId = parseInt(modelSel.value || '0', 10);
    hideTrim();
    if (!catId || !brandId || !modelId || !trimSel || !trimWrap) {
      updateButtons();
      return;
    }
    try {
      const data = await fetchCatalog('?cat=' + encodeURIComponent(catId) + '&brand=' + encodeURIComponent(brandId) + '&model=' + encodeURIComponent(modelId));
      const trims = data.trims || [];
      if (trims.length) {
        fillSelect(trimSel, trims, 'انتخاب تیپ…');
        trimSel.disabled = false;
        trimWrap.style.display = '';
      }
    } catch (e) {
    }
    updateButtons();
  }

  window.swaapinCatalogValid = () => {
    if (wrap.style.display === 'none') return true;
    return !!brandSel.value && !!modelSel.value;
  };

  catHidden.addEventListener('change', loadBrands);
  brandSel.addEventListener('change', loadModels);
  modelSel.addEventListener('change', loadTrims);

  // فرم پس از خطا دوباره رندر شده و دسته از قبل انتخاب است
  if (parseInt(catHidden.value || '0', 10) > 0) {
    loadBrands();
  }
}

function initStep2Counters() {
  const titleInput = document.getElementById('wz-title');
  const descInput = document.getElementById('wz-desc');

  titleInput.addEventListener('input', () => {
    document.getElementById('wz-title-count').textContent = titleInput.value.length;
    updateButtons();
  });
  descInput.addEventListener('input', () => {
    document.getElementById('wz-desc-count').textContent = descInput.value.length;
    updateButtons();
  });
}

function initAiImproveButtons() {
  const buttons = document.querySelectorAll('[data-ai-improve]');
  if (!buttons.length || typeof swaapinAiImproveTextFromBrowser !== 'function') return;

  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const field = btn.getAttribute('data-ai-improve');
      const isTitle = field === 'title';
      const target = document.getElementById(isTitle ? 'wz-title' : 'wz-desc');
      if (!target) return;

      const value = target.value.trim();
      if (value.length < 3) {
        showToast(
          isTitle
            ? 'ابتدا یک عنوان کوتاه بنویسید، سپس آن را بهبود دهید.'
            : 'ابتدا توضیح کوتاهی بنویسید، سپس آن را بهبود دهید.',
          'info'
        );
        return;
      }

      const appUrl = document.querySelector('meta[name="app-url"]')?.content || '';
      const fd = new FormData();
      fd.append('field', field);
      fd.append('text', value);
      fd.append('title', document.getElementById('wz-title')?.value.trim() || '');
      fd.append('condition', document.getElementById('wz-condition')?.value || 'good');
      const catLabelEl = document.getElementById('cat-pick-label');
      const catLabelText = catLabelEl ? (catLabelEl.textContent || '').trim() : '';
      if (catLabelText && !catLabelText.includes('انتخاب دسته‌بندی')) {
        fd.append('category_label', catLabelText);
      }
      if (typeof appendCsrf === 'function') appendCsrf(fd);

      btn.disabled = true;
      btn.classList.add('is-loading');

      swaapinAiImproveTextFromBrowser(appUrl, fd).then((improved) => {
        if (!improved) throw new Error('دستیار نتوانست متن را بازنویسی کند.');
        target.value = improved;
        const counter = document.getElementById(isTitle ? 'wz-title-count' : 'wz-desc-count');
        if (counter) counter.textContent = target.value.length;
        updateButtons();
        showToast('متن با هوش مصنوعی بازنویسی شد. می‌توانید آن را ویرایش کنید.', 'success');
      }).catch((err) => {
        showToast((err && err.message) ? err.message : 'بهبود متن ناموفق بود. دوباره تلاش کنید.', 'error');
      }).finally(() => {
        btn.disabled = false;
        btn.classList.remove('is-loading');
      });
    });
  });
}

function initUpload() {
  const zone = document.getElementById('wz-upload-zone');
  const input = document.getElementById('wz-images');
  const grid = document.getElementById('wz-preview-grid');
  if (!zone || !input || !grid) return;

  zone.addEventListener('click', () => input.click());

  input.addEventListener('change', () => addFiles(input.files));

  zone.addEventListener('dragover', e => {
    e.preventDefault();
    zone.classList.add('dragover');
  });

  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));

  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    addFiles(e.dataTransfer.files);
  });

  function addFiles(newFiles) {
    for (const f of newFiles) {
      if (uploadedFiles.length >= <?= MAX_IMAGES ?>) break;
      if (!f.type.match('image.*')) continue;
      uploadedFiles.push(f);
      const reader = new FileReader();
      const idx = uploadedFiles.length - 1;
      reader.onload = e => renderPreview(e.target.result, idx);
      reader.readAsDataURL(f);
    }
    syncUploadedFilesInput();
    updateButtons();
  }

  function renderPreview(src, idx) {
    const wrap = document.createElement('div');
    wrap.className = 'image-preview';
    wrap.id = 'wz-preview-' + idx;
    wrap.innerHTML = `<img src="${src}"><button type="button" class="image-preview-remove" onclick="removeImg(${idx})"><i class="bi bi-x-lg"></i></button>`;
    if (idx === 0) {
      wrap.style.borderColor = 'var(--wizard-primary)';
    }
    grid.appendChild(wrap);
  }

  window.removeImg = function(idx) {
    uploadedFiles[idx] = null;
    const el = document.getElementById('wz-preview-' + idx);
    if (el) el.remove();
    syncUploadedFilesInput();
    updateButtons();
  }
}

function readWizardDraft() {
  const form = document.getElementById('wizard-form');
  if (!form) return null;
  const data = {};
  form.querySelectorAll('input, select, textarea').forEach(el => {
    const name = el.name;
    if (!name || name === '_csrf' || el.type === 'file' || el.type === 'submit' || el.type === 'button') return;
    if (el.type === 'radio') {
      if (el.checked) data[name] = el.value;
      else if (!(name in data)) data[name] = '';
    } else if (el.type === 'checkbox') {
      data[name] = el.checked ? (el.value || '1') : '';
    } else {
      data[name] = el.value;
    }
  });
  return data;
}

function saveWizardDraft() {
  const data = readWizardDraft();
  if (!data) return;
  try {
    data._ts = Date.now();
    localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
  } catch (e) {}
}

function clearWizardDraft() {
  try {
    localStorage.removeItem(DRAFT_KEY);
    if (DRAFT_KEY !== DRAFT_KEY_GUEST) localStorage.removeItem(DRAFT_KEY_GUEST);
  } catch (e) {}
  const notice = document.getElementById('wz-draft-notice');
  if (notice) notice.style.display = 'none';
}

function readStoredDraft() {
  let raw = null;
  try { raw = localStorage.getItem(DRAFT_KEY); } catch (e) {}
  // کاربر مهمان که پس از ورود به همین صفحه بازگشته: پیش‌نویس مهمان را به حساب کاربر منتقل کن
  if (!raw && DRAFT_KEY !== DRAFT_KEY_GUEST) {
    try {
      raw = localStorage.getItem(DRAFT_KEY_GUEST);
      if (raw) localStorage.removeItem(DRAFT_KEY_GUEST);
    } catch (e) {}
  }
  if (!raw) return null;
  try {
    const data = JSON.parse(raw);
    return (data && typeof data === 'object') ? data : null;
  } catch (e) { return null; }
}

function restoreWizardDraft() {
  const data = readStoredDraft();
  if (!data) return;

  const form = document.getElementById('wizard-form');
  if (!form) return;

  form.querySelectorAll('input, select, textarea').forEach(el => {
    const name = el.name;
    if (!name || name === '_csrf' || el.type === 'file') return;
    if (!(name in data)) return;
    if (el.type === 'radio') {
      el.checked = (String(el.value) === String(data[name]));
    } else if (el.type === 'checkbox') {
      el.checked = !!data[name];
    } else {
      el.value = data[name];
    }
  });

  // بازسازی برچسب دسته‌بندی‌های انتخاب‌شده
  [['cat-pick', 'category_id'], ['want-cat-pick', 'want_category_id']].forEach(([prefix, field]) => {
    const catId = String(data[field] || '').replace(/["\\]/g, '');
    if (!catId) return;
    const leaf = document.querySelector('#' + prefix + '-menu .cat-pick-leaf[data-cat-id="' + catId + '"]');
    if (leaf) leaf.click();
  });

  ['wz-title', 'wz-desc'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.dispatchEvent(new Event('input', { bubbles: true }));
  });

  updateButtons();
  applyDealType();

  const notice = document.getElementById('wz-draft-notice');
  if (notice) notice.style.display = '';
}

function initWizardDraftAutosave() {
  const form = document.getElementById('wizard-form');
  if (!form) return;

  let timer = null;
  const schedule = () => {
    clearTimeout(timer);
    timer = setTimeout(saveWizardDraft, 800);
  };
  form.addEventListener('input', schedule, true);
  form.addEventListener('change', schedule, true);

  document.getElementById('wz-draft-clear')?.addEventListener('click', () => {
    clearWizardDraft();
    if (typeof showToast === 'function') showToast('پیش‌نویس حذف شد.', 'success');
  });
}

function initWizardSubmit() {
  const form = document.getElementById('wizard-form');
  const submitBtn = document.getElementById('wizard-submit-btn');
  if (!form) return;

  form.addEventListener('submit', function(e) {
    // مهمان: پیش از انتشار باید وارد حساب کاربری شود؛ داده‌های فرم حفظ می‌شود
    if (IS_GUEST) {
      e.preventDefault();
      saveWizardDraft();
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px"></span> انتقال به صفحه ورود…';
      }
      const appUrl = document.querySelector('meta[name="app-url"]')?.content || '';
      window.location.href = appUrl + '/auth/login?redirect=' + encodeURIComponent('/listings/create');
      return;
    }

    const count = syncUploadedFilesInput();
    if (count === 0) {
      e.preventDefault();
      goToStep(2);
      showWizardError('لطفاً حداقل یک تصویر برای آگهی انتخاب کنید.');
      return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px"></span> در حال انتشار…';
    }
  });
}

function showWizardError(msg) {
  if (typeof showToast === 'function') {
    showToast(msg, 'error');
    return;
  }
  alert(msg);
}

function validateCurrentStepWithMessage() {
  const stepMessages = {
    1: 'هدف آگهی را انتخاب کنید.',
    2: 'عنوان و توضیحات، تصاویر، دسته‌بندی، وضعیت، شهر و موقعیت جغرافیایی را کامل کنید.',
    3: 'اطلاعات معامله (قیمت یا کالای موردنظر) را کامل کنید.',
    4: 'قبل از انتشار آگهی، اطلاعات را بررسی کنید.'
  };

  if (!validateCurrentStep()) {
    showWizardError(stepMessages[currentStep] || 'لطفاً اطلاعات را کامل کنید.');
    return false;
  }

  return true;
}

function validateCurrentStep() {
  switch (currentStep) {
    case 1:
      return STORE_MODE || !!getDealType();

    case 2: {
      const title = document.getElementById('wz-title').value.trim();
      const desc = document.getElementById('wz-desc').value.trim();
      const hasImage = uploadedFiles.filter(Boolean).length > 0;
      const catId = document.getElementById('cat-pick-hidden')?.value || '';
      const city = document.getElementById('wz-city').value.trim();
      const lat = document.getElementById('wz-latitude').value.trim();
      const lng = document.getElementById('wz-longitude').value.trim();
      const neighborhood = document.getElementById('wz-neighborhood-hidden')?.value.trim()
        || document.getElementById('wz-neighborhood')?.value.trim()
        || document.getElementById('wz-neighborhood-text')?.value.trim();
      return title.length >= 5 && desc.length >= 20 && hasImage && !!catId && !!city && !!lat && !!lng && !!neighborhood
        && (typeof window.swaapinCatalogValid !== 'function' || window.swaapinCatalogValid());
    }

    case 3: {
      const mode = STORE_MODE ? 'both' : getDealType();
      const needSell = (mode === 'sell' || mode === 'both');
      const needSwap = (mode === 'swap' || mode === 'both');
      if (needSell) {
        const sp = parseFloat(document.getElementById('wz-sell-price')?.value || '0');
        if (!(sp > 0)) return false;
      }
      if (needSwap) {
        const wantCatId = document.getElementById('want-cat-pick-hidden')?.value || '';
        if (!wantCatId) return false;
      }
      return true;
    }

    case 4:
      return true;

    default:
      return true;
  }
}

function goToStep(step) {
  if (step < 1 || step > totalSteps) return;

  if (step === 2) {
    setTimeout(() => {
      document.querySelectorAll('.listing-location-picker .listing-location-picker__map')
        .forEach(() => window.dispatchEvent(new Event('resize')));
    }, 120);
  }
  
  // Hide all steps
  document.querySelectorAll('.wizard-step').forEach(el => el.style.display = 'none');
  
  // Show target
  document.getElementById('step-' + step).style.display = 'block';
  currentStep = step;
  document.querySelector('.listing-create-page')?.setAttribute('data-current-step', String(step));
  
  updateStepper();
  updateButtons();
  
  if (step === 3) {
    applyDealType();
    runWizardAiPricing();
  }

  if (step === 4) {
    populateReview();
  }
}

function updateStepper() {
  // Update the progress line
  const progressEl = document.getElementById('stepper-progress');
  const percentage = ((currentStep - 1) / (totalSteps - 1)) * 100;
  progressEl.style.width = percentage + '%';

  // Update each step indicator
  for (let i = 1; i <= totalSteps; i++) {
    const indicator = document.getElementById('step-' + i + '-indicator');
    const circle = indicator.querySelector('.stepper-circle');
    const icon = circle.querySelector('i');
    
    indicator.classList.remove('active', 'completed');
    
    if (i < currentStep) {
      indicator.classList.add('completed');
      // Show check icon
      icon.style.display = 'flex';
      circle.textContent = '';
      circle.appendChild(icon);
    } else if (i === currentStep) {
      indicator.classList.add('active');
      // Hide check icon
      icon.style.display = 'none';
    } else {
      // Hide check icon
      icon.style.display = 'none';
    }
  }
}

function updateButtons() {
  const backBtn = document.getElementById('wizard-back-btn');
  const nextBtn = document.getElementById('wizard-next-btn');
  const submitBtn = document.getElementById('wizard-submit-btn');

  backBtn.style.display = currentStep > 1 ? 'inline-block' : 'none';
  
  if (currentStep === totalSteps) {
    nextBtn.style.display = 'none';
    submitBtn.style.display = 'inline-block';
  } else {
    nextBtn.style.display = 'inline-block';
    submitBtn.style.display = 'none';
  }

  // Disable next if current invalid
  nextBtn.disabled = !validateCurrentStep();
}

function populateReview() {
  const mode = STORE_MODE ? 'both' : getDealType();
  const needSell = (mode === 'sell' || mode === 'both');
  const needSwap = (mode === 'swap' || mode === 'both');

  const goalLabels = { swap: 'معاوضه', sell: 'فروش', both: 'فروش یا معاوضه' };
  document.getElementById('wz-review-goal').textContent = goalLabels[mode] || 'نامشخص';

  // اطلاعات اصلی
  document.getElementById('wz-review-title').textContent = document.getElementById('wz-title').value;
  document.getElementById('wz-review-desc').textContent = document.getElementById('wz-desc').value;

  const catLabel = document.getElementById('cat-pick-label');
  const catHidden = document.getElementById('cat-pick-hidden');
  document.getElementById('wz-review-category').textContent =
    (catHidden?.value && catLabel?.textContent && catLabel.textContent !== 'انتخاب دسته‌بندی…')
      ? catLabel.textContent
      : 'انتخاب نشده';

  const condSelect = document.getElementById('wz-condition');
  const condLabels = { 'new' : 'نو', 'like_new' : 'مثل نو', 'good' : 'خوب', 'fair' : 'متوسط', 'poor' : 'خورده'};
  document.getElementById('wz-review-condition').textContent = condLabels[condSelect.value];

  const cityVal = document.getElementById('wz-city').value;
  document.getElementById('wz-review-city').textContent = cityVal || 'نامشخص';
  const neighborhoodVal = document.getElementById('wz-neighborhood-hidden')?.value
    || document.getElementById('wz-neighborhood')?.value
    || document.getElementById('wz-neighborhood-text')?.value
    || '';
  const latVal = document.getElementById('wz-latitude')?.value || '';
  const lngVal = document.getElementById('wz-longitude')?.value || '';
  document.getElementById('wz-review-neighborhood').textContent = neighborhoodVal
    ? `محله ${neighborhoodVal}${latVal && lngVal ? ` — ${latVal}, ${lngVal}` : ''}`
    : 'موقعیت روی نقشه انتخاب نشده';

  // قیمت فروش
  const sellSection = document.getElementById('wz-review-sell-section');
  sellSection.style.display = needSell ? '' : 'none';
  if (needSell) {
    const sellVal = document.getElementById('wz-sell-price')?.value || '0';
    document.getElementById('wz-review-sell-price').textContent =
      new Intl.NumberFormat('fa-IR').format(parseInt(sellVal || '0', 10)) + ' تومان';
  }

  // کالای موردنظر
  const wantCatLabel = document.getElementById('want-cat-pick-label');
  const wantCatHidden = document.getElementById('want-cat-pick-hidden');
  const wantCatSection = document.getElementById('wz-review-wantcat-section');
  const wantDescSection = document.getElementById('wz-review-wantdesc-section');
  const wantDesc = document.getElementById('wz-want-desc')?.value || '';
  wantCatSection.style.display = needSwap ? '' : 'none';
  wantDescSection.style.display = needSwap ? '' : 'none';
  if (needSwap) {
    document.getElementById('wz-review-want-cat').textContent =
      (wantCatHidden?.value && wantCatLabel?.textContent
        && !wantCatLabel.textContent.includes('انتخاب دسته‌بندی'))
        ? wantCatLabel.textContent
        : 'انتخاب نشده';
    const wantDescEl = document.getElementById('wz-review-want-desc');
    if (wantDescEl) wantDescEl.textContent = wantDesc || 'نوشته نشده';
  }

  // قیمت تخمینی معاوضه
  const swapSection = document.getElementById('wz-review-swap-section');
  swapSection.style.display = needSwap ? '' : 'none';
  if (needSwap) {
    const customRaw = (document.getElementById('wz-custom-value')?.value || '').replace(/[^\d]/g, '');
    const finalPrice = customRaw || document.getElementById('wz-estimated-value')?.value || '0';
    document.getElementById('wz-review-swap-price').textContent =
      new Intl.NumberFormat('fa-IR').format(parseInt(finalPrice || '0', 10)) + ' تومان';
  }

  // تصاویر
  const imgGrid = document.getElementById('wz-review-images');
  imgGrid.innerHTML = '';
  uploadedFiles.filter(Boolean).forEach((_, idx) => {
    const preview = document.getElementById('wz-preview-' + idx);
    if (preview) {
      const img = preview.querySelector('img');
      if (img) {
        const clone = document.createElement('img');
        clone.src = img.src;
        imgGrid.appendChild(clone);
      }
    }
  });
}

// Event listeners for buttons
document.getElementById('wizard-back-btn').addEventListener('click', () => {
  if (currentStep > 1) {
    goToStep(currentStep - 1);
  }
});

document.getElementById('wizard-next-btn').addEventListener('click', () => {
  if (!validateCurrentStepWithMessage()) {
    return;
  }

  if (currentStep < totalSteps) {
    goToStep(currentStep + 1);
  }
});
</script>

<?php render_footer(); ?>
