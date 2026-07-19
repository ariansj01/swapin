<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();

// KYC Wall
if (($user['kyc_status'] ?? 'none') !== 'approved') {
    render_full_page_modal(
        'احراز هویت لازم است',
        'برای ثبت آگهی، ابتدا باید هویت شما تایید شود.',
        'رفتن به صفحه احراز هویت',
        APP_URL . '/profile/edit.php',
        'bi-shield-check'
    );
}
$errors = [];
$vals = [
    'title'           => '',
    'description'     => '',
    'category_id'     => 0,
    'condition'       => 'good',
    'city'            => '',
    'want_categories' => [],
    'want_description' => '',
    'custom_value'    => 0,
    'estimated_value' => 0,
];

$suggestedValue = (int)($_POST['suggested_value'] ?? rand(10000000, 50000000));

// Category data
$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);

// Exchange categories for the new step 4
$exchangeCategories = [
    ['name' => 'موبایل', 'icon' => 'bi-phone'],
    ['name' => 'لپ‌تاپ', 'icon' => 'bi-laptop'],
    ['name' => 'خودرو', 'icon' => 'bi-car-front'],
    ['name' => 'موتور', 'icon' => 'bi-bicycle'],
    ['name' => 'کنسول بازی', 'icon' => 'bi-controller'],
    ['name' => 'دوربین', 'icon' => 'bi-camera'],
    ['name' => 'طلا و جواهر', 'icon' => 'bi-gem'],
    ['name' => 'ساعت', 'icon' => 'bi-watch'],
    ['name' => 'کتاب', 'icon' => 'bi-book'],
    ['name' => 'لوازم خانگی', 'icon' => 'bi-tv'],
    ['name' => 'پوشاک', 'icon' => 'bi-bag'],
    ['name' => 'لوازم ورزشی', 'icon' => 'bi-activity'],
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    
    $vals = [
        'title'           => clean($_POST['title']           ?? ''),
        'description'     => clean($_POST['description']     ?? ''),
        'category_id'     => (int)($_POST['category_id']     ?? 0),
        'condition'       => clean($_POST['condition']       ?? 'good'),
        'city'            => clean($_POST['city']            ?? ''),
        'want_categories' => $_POST['want_categories']       ?? [],
        'want_description' => clean($_POST['want_description'] ?? ''),
        'custom_value'    => (int)($_POST['custom_value']    ?? 0),
        'estimated_value' => 0,
    ];
    $suggestedValue = (int)($_POST['suggested_value'] ?? $suggestedValue);
    $vals['estimated_value'] = $vals['custom_value'] > 0 ? $vals['custom_value'] : $suggestedValue;

    // Validation
    $errors = [];
    if (mb_strlen($vals['title']) < 5) $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد';
    if (mb_strlen($vals['title']) > 200) $errors['title'] = 'عنوان باید کمتر از ۲۰۰ کاراکتر باشد';
    if (!$vals['category_id']) $errors['category_id'] = 'لطفا دسته‌بندی را انتخاب کنید';
    if (mb_strlen($vals['description']) < 20) $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد';
    if ($vals['city'] && !in_array($vals['city'], iran_cities(), true)) {
        $errors['city'] = 'لطفاً شهر را از فهرست انتخاب کنید';
    }

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
        $listingId = DB::insert('listings', [
            'user_id'          => $user['id'],
            'category_id'      => $vals['category_id'],
            'title'            => $vals['title'],
            'description'      => $vals['description'],
            'condition'        => $vals['condition'],
            'estimated_value'  => $vals['estimated_value'] ?: 0,
            'want_in_return'   => $vals['want_description'] ?: '',
            'want_type'        => 'any',
            'listing_mode'     => 'swap',
            'sell_price'       => 0,
            'needs_inspection' => 0,
            'city'             => $vals['city'] ?: null,
            'status'           => 'active',
            'review_status'    => 'pending',
        ]);

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

            // Redirect to success page
            header('Location: ' . APP_URL . '/listings/success?id=' . $listingId);
            exit;
        }
    }
}

render_head('ثبت آگهی جدید');
render_navbar($user);
?>

<div class="wizard-page">
  <!-- Stepper Header -->
  <div class="wizard-header">
    <div class="wizard-container" style="padding: var(--wizard-gap) var(--wizard-gap);">
      <div class="stepper">
        <div class="stepper-progress" id="stepper-progress"></div>
        <?php 
        $stepLabels = [
          1 => 'عنوان و توضیحات',
          2 => 'تصاویر',
          3 => 'جزئیات',
          4 => 'چی می‌خوای؟',
          5 => 'توضیح دقیق‌تر',
          6 => 'قیمت تخمینی',
          7 => 'بررسی نهایی'
        ];
        for ($i = 1; $i <= 7; $i++): ?>
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
    <?php endif; ?>

    <!-- Step Content Card -->
    <div class="wizard-card">
      <form method="POST" id="wizard-form" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <!-- File input always in form root (not inside hidden steps) -->
        <input type="file" id="step2-images" name="images[]" multiple accept="image/jpeg,image/png,image/webp"
               class="wizard-file-input" tabindex="-1" aria-hidden="true">
        
        <!-- Step 1: Title & Description -->
        <div class="wizard-step" data-step="1" id="step-1">
          <h2 class="wizard-step-title">عنوان و توضیحات</h2>
          <p class="wizard-step-subtitle">نام کالا و توضیحات کامل را وارد کنید.</p>

          <div class="wizard-form-group">
            <label class="wizard-form-label">عنوان آگهی *</label>
            <input type="text" name="title" id="step1-title" class="wizard-form-input" 
                   placeholder="مثلا آیفون ۱۳ پرو مکس ۲۵۶ گیگ" maxlength="200">
            <div class="char-count"><span id="step1-title-count">0</span>/200 کاراکتر</div>
          </div>

          <div class="wizard-form-group">
            <label class="wizard-form-label">توضیحات *</label>
            <textarea name="description" id="step1-description" class="wizard-form-textarea" rows="6"
                      placeholder="کالا را با جزئیات توضیح دهید — سن، برند، مشخصات، ایرادات…"></textarea>
            <div class="char-count"><span id="step1-desc-count">0</span> کاراکتر</div>
          </div>
        </div>

        <!-- Step 2: Images -->
        <div class="wizard-step" data-step="2" id="step-2" style="display:none">
          <h2 class="wizard-step-title">تصاویر</h2>
          <p class="wizard-step-subtitle">تصاویر واضح از کالا اضافه کنید.</p>

          <div class="upload-zone" id="step2-upload-zone">
            <div class="upload-zone-icon">
              <i class="bi bi-cloud-arrow-up"></i>
            </div>
            <p class="upload-zone-text">تصاویر را اینجا رها کنید یا برای آپلود کلیک کنید</p>
            <p class="upload-zone-subtext">JPG، PNG یا WEBP — حداکثر ۵ مگابایت — تا <?= MAX_IMAGES ?> تصویر</p>
          </div>
          
          <div class="image-previews" id="step2-preview-grid"></div>
        </div>

        <!-- Step 3: Category, Condition, City -->
        <div class="wizard-step" data-step="3" id="step-3" style="display:none">
          <h2 class="wizard-step-title">دسته‌بندی، وضعیت و شهر</h2>
          <p class="wizard-step-subtitle">جزئیات بیشتر را وارد کنید.</p>

          <div class="wizard-form-group">
            <label class="wizard-form-label">دسته‌بندی *</label>
            <select name="category_id" id="step3-category" class="wizard-form-select">
              <option value="">انتخاب دسته‌بندی…</option>
              <?php
                $lastParent = null;
                foreach ($categories as $cat):
                  if ($cat['parent_id'] === null):
                    if ($lastParent !== null) echo '</optgroup>';
                    echo '<optgroup label="'.h(category_label($cat['slug'], $cat['name'])).'">';
                    $lastParent = $cat['id'];
                  else:
                    echo '<option value="'.$cat['id'].'">'.h(category_label($cat['slug'], $cat['name'])).'</option>';
                  endif;
                endforeach;
                if ($lastParent !== null) echo '</optgroup>';
              ?>
            </select>
          </div>

          <div class="wizard-form-group">
            <label class="wizard-form-label">وضعیت کالا *</label>
            <select name="condition" id="step3-condition" class="wizard-form-select">
              <option value="new">نو</option>
              <option value="like_new">مثل نو</option>
              <option value="good" selected>خوب</option>
              <option value="fair">متوسط</option>
              <option value="poor">خورده</option>
            </select>
          </div>

          <div class="wizard-form-group">
            <label class="wizard-form-label" for="step3-city">شهر</label>
            <select name="city" id="step3-city" class="wizard-form-select <?= isset($errors['city']) ? 'is-invalid' : '' ?>">
              <option value="">انتخاب شهر</option>
              <?= render_city_options($vals['city']) ?>
            </select>
            <?php if (isset($errors['city'])): ?>
            <div class="invalid-feedback"><?= h($errors['city']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Step 4: What do you want in exchange? -->
        <div class="wizard-step" data-step="4" id="step-4" style="display:none">
          <h2 class="wizard-step-title">به دنبال چه چیزی هستید؟</h2>
          <p class="wizard-step-subtitle">دسته‌بندی‌های مورد علاقه خود را انتخاب کنید.</p>

          <div class="category-chips">
            <?php foreach ($exchangeCategories as $cat): ?>
              <div class="category-chip" data-category="<?= h($cat['name']) ?>" 
                   onclick="toggleExchangeCategory(this, '<?= h($cat['name']) ?>')">
                <span class="chip-icon"><i class="bi <?= $cat['icon'] ?>"></i></span>
                <?= h($cat['name']) ?>
              </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="step4-want-cats" name="want_categories[]" multiple>
        </div>

        <!-- Step 5: Describe what you want -->
        <div class="wizard-step" data-step="5" id="step-5" style="display:none">
          <h2 class="wizard-step-title">توضیح دقیق‌تر</h2>
          <p class="wizard-step-subtitle">دقیق بنویسید که به چه چیزی نیاز دارید.</p>

          <div class="wizard-form-group">
            <textarea name="want_description" id="step5-description" class="wizard-form-textarea" rows="6"
                      placeholder="مثلا فقط آیفون ۱۵ پرو تمیز با سلامت باتری بالای ۹۰ درصد…"></textarea>
          </div>
        </div>

        <!-- Step 6: Estimated Price -->
        <div class="wizard-step" data-step="6" id="step-6" style="display:none">
          <h2 class="wizard-step-title">قیمت تخمینی</h2>
          <p class="wizard-step-subtitle">سیستم یک مبلغ پیشنهادی می‌دهد؛ اگر نخواستید، پایین‌تر قیمت دلخواه خودتان را وارد کنید.</p>

          <div class="price-estimate-card">
            <p class="price-estimate-label">قیمت پیشنهادی سیستم</p>
            <div class="wizard-form-group" style="margin-top: var(--wizard-gap)">
              <input type="text" id="step6-suggested-price-input" class="wizard-form-input"
                     value="<?= h(number_format($suggestedValue)) ?>"
                     readonly>
              <p class="price-estimate-note" style="margin-top: var(--wizard-gap)">این مبلغ فقط پیشنهاد سیستم است و در صورت خالی گذاشتن فیلد پایین، همین مقدار ثبت می‌شود.</p>
            </div>
          </div>

          <div class="wizard-form-group" style="margin-top: var(--wizard-gap)">
            <label class="wizard-form-label" for="step6-custom-price-input">قیمت دلخواه شما</label>
            <input type="text" id="step6-custom-price-input" class="wizard-form-input"
                   placeholder="اگر قیمت دیگری مدنظر دارید وارد کنید"
                   value="<?= $vals['custom_value'] > 0 ? h(number_format($vals['custom_value'])) : '' ?>">
            <p class="price-estimate-note" style="margin-top: var(--wizard-gap)">این بخش اختیاری است.</p>
          </div>

          <input type="hidden" id="step6-suggested-value" name="suggested_value" value="<?= h($suggestedValue) ?>">
          <input type="hidden" id="step6-custom-value" name="custom_value" value="<?= h($vals['custom_value']) ?>">
          <input type="hidden" id="step6-estimated-value" name="estimated_value" value="<?= h($vals['estimated_value'] ?: $suggestedValue) ?>">
        </div>

        <!-- Step 7: Review -->
        <div class="wizard-step" data-step="7" id="step-7" style="display:none">
          <div class="review-header">
            <h2 class="wizard-step-title" style="margin:0">بررسی نهایی</h2>
            <button type="button" class="edit-btn" onclick="goToStep(1)">
              <i class="bi bi-pencil"></i> ویرایش اطلاعات
            </button>
          </div>
          <p class="wizard-step-subtitle">تمام اطلاعات را بررسی کنید.</p>

          <div class="review-section">
            <p class="review-label">تصاویر</p>
            <div class="review-images" id="step7-images"></div>
          </div>
          
          <div class="review-section">
            <p class="review-label">عنوان</p>
            <p class="review-value" id="step7-title"></p>
          </div>
          
          <div class="review-section">
            <p class="review-label">توضیحات</p>
            <p class="review-value" id="step7-description"></p>
          </div>
          
          <div class="review-section">
            <p class="review-label">دسته‌بندی</p>
            <p class="review-value" id="step7-category"></p>
          </div>
          
          <div class="review-section">
            <p class="review-label">وضعیت کالا</p>
            <p class="review-value" id="step7-condition"></p>
          </div>
          
          <div class="review-section">
            <p class="review-label">شهر</p>
            <p class="review-value" id="step7-city"></p>
          </div>
          
          <div class="review-section">
            <p class="review-label">دسته‌بندی‌های مورد علاقه برای معاوضه</p>
            <p class="review-value" id="step7-want-cats"></p>
          </div>
          
          <div class="review-section">
            <p class="review-label">توضیح آنچه می‌خواهید</p>
            <p class="review-value" id="step7-want-desc"></p>
          </div>
          
          <div class="review-section">
            <p class="review-label">قیمت تخمینی</p>
            <p class="review-value" id="step7-price"></p>
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
              ادامه
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

<link rel="stylesheet" href="<?= APP_URL ?>/src/css/listing-wizard.css">
<script>
let currentStep = 1;
const totalSteps = 7;
let exchangeCategories = new Set();
let uploadedFiles = [];

function syncUploadedFilesInput() {
  const input = document.getElementById('step2-images');
  if (!input) return 0;
  const dt = new DataTransfer();
  uploadedFiles.filter(Boolean).forEach(f => dt.items.add(f));
  input.files = dt.files;
  return dt.files.length;
}

// Initialize everything
document.addEventListener('DOMContentLoaded', () => {
  updateStepper();
  initStep1Counters();
  initStep2Upload();
  initStep3Fields();
  initStep6Price();
  initWizardSubmit();
});

function initStep6Price() {
  const customInput = document.getElementById('step6-custom-price-input');
  const customHiddenInput = document.getElementById('step6-custom-value');
  const selectedHiddenInput = document.getElementById('step6-estimated-value');
  const suggestedHiddenInput = document.getElementById('step6-suggested-value');
  
  if (!customInput || !customHiddenInput || !selectedHiddenInput || !suggestedHiddenInput) return;
  
  function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }

  function syncPriceValue() {
    const rawValue = customInput.value.replace(/[^\d]/g, '');
    customInput.value = rawValue ? formatNumber(parseInt(rawValue, 10)) : '';
    customHiddenInput.value = rawValue;
    selectedHiddenInput.value = rawValue || suggestedHiddenInput.value;
  }

  customInput.addEventListener('input', syncPriceValue);
  syncPriceValue();
}

function initStep3Fields() {
  const catSelect = document.getElementById('step3-category');
  const condSelect = document.getElementById('step3-condition');
  const cityInput = document.getElementById('step3-city');
  if (catSelect) catSelect.addEventListener('change', updateButtons);
  if (condSelect) condSelect.addEventListener('change', updateButtons);
  if (cityInput) cityInput.addEventListener('input', updateButtons);
}

function initStep1Counters() {
  const titleInput = document.getElementById('step1-title');
  const descInput = document.getElementById('step1-description');

  titleInput.addEventListener('input', () => {
    document.getElementById('step1-title-count').textContent = titleInput.value.length;
    updateButtons();
  });
  descInput.addEventListener('input', () => {
    document.getElementById('step1-desc-count').textContent = descInput.value.length;
    updateButtons();
  });
}

function initStep2Upload() {
  const zone = document.getElementById('step2-upload-zone');
  const input = document.getElementById('step2-images');
  const grid = document.getElementById('step2-preview-grid');

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
    wrap.id = 'step2-preview-' + idx;
    wrap.innerHTML = `<img src="${src}"><button type="button" class="image-preview-remove" onclick="removeImg(${idx})"><i class="bi bi-x-lg"></i></button>`;
    if (idx === 0) {
      wrap.style.borderColor = 'var(--wizard-primary)';
    }
    grid.appendChild(wrap);
  }

  window.removeImg = function(idx) {
    uploadedFiles[idx] = null;
    const el = document.getElementById('step2-preview-' + idx);
    if (el) el.remove();
    syncUploadedFilesInput();
    updateButtons();
  }
}

function initWizardSubmit() {
  const form = document.getElementById('wizard-form');
  const submitBtn = document.getElementById('wizard-submit-btn');
  if (!form) return;

  form.addEventListener('submit', function(e) {
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

window.toggleExchangeCategory = function(el, cat) {
  if (exchangeCategories.has(cat)) {
    exchangeCategories.delete(cat);
    el.classList.remove('selected');
  } else {
    exchangeCategories.add(cat);
    el.classList.add('selected');
  }
  document.getElementById('step4-want-cats').value = JSON.stringify([...exchangeCategories]);
  updateButtons();
}

function validateCurrentStep() {
  switch (currentStep) {
    case 1:
      const title = document.getElementById('step1-title').value.trim();
      const desc = document.getElementById('step1-description').value.trim();
      return title.length >= 5 && desc.length >= 20;

    case 2:
      return uploadedFiles.filter(Boolean).length > 0;

    case 3:
      const catId = document.getElementById('step3-category').value;
      return !!catId;

    case 4:
      return exchangeCategories.size > 0;

    case 5:
    case 6:
    case 7:
      return true;

    default:
      return true;
  }
}

function goToStep(step) {
  if (step < 1 || step > totalSteps) return;
  
  // Hide all steps
  document.querySelectorAll('.wizard-step').forEach(el => el.style.display = 'none');
  
  // Show target
  document.getElementById('step-' + step).style.display = 'block';
  currentStep = step;
  
  updateStepper();
  updateButtons();
  
  if (step === 7) {
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
  // Step 1
  document.getElementById('step7-title').textContent = document.getElementById('step1-title').value;
  document.getElementById('step7-description').textContent = document.getElementById('step1-description').value;

  // Step 3
  const catSelect = document.getElementById('step3-category');
  document.getElementById('step7-category').textContent = catSelect.options[catSelect.selectedIndex].text;
  
  const condSelect = document.getElementById('step3-condition');
  const condLabels = { 'new' : 'نو', 'like_new' : 'مثل نو', 'good' : 'خوب', 'fair' : 'متوسط', 'poor' : 'خورده'};
  document.getElementById('step7-condition').textContent = condLabels[condSelect.value];
  
  const cityVal = document.getElementById('step3-city').value;
  document.getElementById('step7-city').textContent = cityVal || 'نامشخص';

  // Step 4 & 5
  document.getElementById('step7-want-cats').textContent = [...exchangeCategories].join('، ');
  document.getElementById('step7-want-desc').textContent = document.getElementById('step5-description').value || 'نوشته نشده';

  // Step 6
  const finalPrice = document.getElementById('step6-estimated-value').value;
  document.getElementById('step7-price').textContent = new Intl.NumberFormat('fa-IR').format(parseInt(finalPrice || '0', 10)) + ' تومان';

  // Step 2 images
  const imgGrid = document.getElementById('step7-images');
  imgGrid.innerHTML = '';
  uploadedFiles.filter(Boolean).forEach((_, idx) => {
    const preview = document.getElementById('step2-preview-' + idx);
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
  if (validateCurrentStep() && currentStep < totalSteps) {
    goToStep(currentStep + 1);
  }
});

</script>

<?php render_footer(); ?>
