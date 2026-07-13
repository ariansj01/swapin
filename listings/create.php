<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();

// Category data
$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);
$parentCategories = DB::fetchAll(
    'SELECT id, name FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order, id'
);

// Exchange categories for step 4 & 8
$exchangeCategories = [
    'موبایل', 'لپ‌تاپ', 'خودرو', 'موتور', 'کنسول بازی', 'دوربین', 'طلا', 'ساعت',
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
        'want_description'=> clean($_POST['want_description']?? ''),
        'estimated_value' => (float)($_POST['estimated_value'] ?? 0),
    ];

    // Validation
    $errors = [];
    if (mb_strlen($vals['title']) < 5) $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد';
    if (mb_strlen($vals['title']) > 200) $errors['title'] = 'عنوان باید کمتر از ۲۰۰ کاراکتر باشد';
    if (!$vals['category_id']) $errors['category_id'] = 'لطفاً دسته‌بندی را انتخاب کنید';
    if (mb_strlen($vals['description']) < 20) $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد';

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

        // Handle image uploads (from session first)
        $uploadedImages = 0;
        if (!empty($_FILES['images']['name'][0])) {
            foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                if ($uploadedImages >= MAX_IMAGES) break;
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

        // Clear cache
        ai_match_clear_cache((int)$user['id']);

        // Redirect to success
        header('Location: ' . APP_URL . '/listings/success?id=' . $listingId);
        exit;
    }
}

// Render the wizard
render_head('ثبت آگهی جدید');
render_navbar($user);
?>

<div class="wizard-page">
  <!-- Stepper Header -->
  <div class="wizard-header">
    <div class="wizard-container" style="padding: var(--sp-4) var(--sp-5);">
      <div class="stepper">
        <?php for ($i = 1; $i <= 8; $i++): ?>
          <div class="stepper-step" data-step="<?= $i ?>" id="step-<?= $i ?>-indicator">
            <div class="stepper-circle"><?= $i ?></div>
            <div class="stepper-label">
              <?php
                $stepLabels = [
                    1 => 'عنوان و توضیحات',
                    2 => 'تصاویر',
                    3 => 'جزئیات',
                    4 => 'معاوضه',
                    5 => 'توضیح',
                    6 => 'قیمت',
                    7 => 'بازبینی',
                    8 => 'پیشنهادات',
                ];
                echo $stepLabels[$i];
              ?>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  <div class="wizard-container">
    <!-- Step Content Card -->
    <div class="wizard-card">
      <form method="POST" id="wizard-form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        
        <!-- Step 1: Title & Description -->
        <div class="wizard-step" data-step="1" id="step-1">
          <h2 class="wizard-step-title">عنوان و توضیحات</h2>
          <p class="wizard-step-subtitle">نام کالا و توضیحات کامل را وارد کنید.</p>

          <div class="wizard-form-group">
            <label class="wizard-form-label">عنوان آگهی *</label>
            <input type="text" name="title" id="step1-title" class="wizard-form-input" 
                   placeholder="مثلاً آیفون ۱۳ پرو مکس ۲۵۶ گیگ" maxlength="200">
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
              <i class="bi bi-cloud-upload"></i>
            </div>
            <p class="upload-zone-text">تصاویر را اینجا رها کنید یا برای آپلود کلیک کنید</p>
            <p class="upload-zone-subtext">JPG، PNG یا WEBP — حداکثر ۵ مگابایت — تا <?= MAX_IMAGES ?> تصویر</p>
            <input type="file" id="step2-images" name="images[]" multiple accept="image/*" style="display:none">
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
                  if ($cat['parent_id'] === null) {
                    if ($lastParent !== null) echo '</optgroup>';
                    echo '<optgroup label="'.h(category_label($cat['slug'], $cat['name'])).'">';
                    $lastParent = $cat['id'];
                  } else {
                    echo '<option value="'.$cat['id'].'">'.h(category_label($cat['slug'], $cat['name'])).'</option>';
                  }
                endforeach;
                if ($lastParent !== null) echo '</optgroup>';
              ?>
            </select>
          </div>

          <div class="wizard-form-group">
            <label class="wizard-form-label">وضعیت کالا *</label>
            <select name="condition" id="step3-condition" class="wizard-form-select">
              <?php foreach (['new','like_new','good','fair','poor'] as $v): ?>
                <option value="<?= $v ?>"><?= condition_label($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="wizard-form-group">
            <label class="wizard-form-label">شهر</label>
            <input type="text" name="city" id="step3-city" class="wizard-form-input"
                   value="<?= h($user['city'] ?? '') ?>" placeholder="شهر شما">
          </div>
        </div>

        <!-- Step 4: What to exchange (category selection) -->
        <div class="wizard-step" data-step="4" id="step-4" style="display:none">
          <h2 class="wizard-step-title">به دنبال چه چیزی هستید؟</h2>
          <p class="wizard-step-subtitle">دسته‌بندی‌های مورد علاقه خود را انتخاب کنید.</p>

          <div class="category-chips">
            <?php foreach ($exchangeCategories as $cat): ?>
              <div class="category-chip" data-category="<?= h($cat) ?>" 
                   onclick="toggleExchangeCategory(this, '<?= h($cat) ?>')">
                <?= h($cat) ?>
              </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="step4-want-cats" name="want_categories[]" multiple>
        </div>

        <!-- Step 5: Describe what you want -->
        <div class="wizard-step" data-step="5" id="step-5" style="display:none">
          <h2 class="wizard-step-title">توضیح آنچه می‌خواهید</h2>
          <p class="wizard-step-subtitle">دقیق بنویسید که به چه چیزی نیاز دارید.</p>

          <div class="wizard-form-group">
            <textarea name="want_description" id="step5-description" class="wizard-form-textarea" rows="6"
                      placeholder="مثلاً فقط آیفون ۱۵ پرو تمیز با سلامت باتری بالای ۹۰ درصد…"></textarea>
          </div>
        </div>

        <!-- Step 6: Estimated Price -->
        <div class="wizard-step" data-step="6" id="step-6" style="display:none">
          <h2 class="wizard-step-title">ارزش تقریبی</h2>
          <p class="wizard-step-subtitle">این قیمت صرفاً تخمینی است.</p>

          <div class="price-estimate-card">
            <p class="price-estimate-label">قیمت تخمینی</p>
            <p class="price-estimate-value" id="step6-price">
              <?php
                // Dummy estimate for now; replace with real AI later
                $dummy = rand(10000000, 50000000);
                echo h(number_format($dummy)) . ' تومان';
              ?>
            </p>
            <p class="price-estimate-note">این قیمت صرفاً تخمینی است و ممکن است با قیمت نهایی متفاوت باشد.</p>
          </div>
          <input type="hidden" id="step6-estimated-value" name="estimated_value" value="<?= $dummy ?>">
        </div>

        <!-- Step 7: Review -->
        <div class="wizard-step" data-step="7" id="step-7" style="display:none">
          <h2 class="wizard-step-title">بازبینی اطلاعات</h2>
          <p class="wizard-step-subtitle">تمام اطلاعات را بررسی کنید.</p>

          <div class="review-section">
            <p class="review-label">تصاویر</p>
            <div class="review-images" id="step7-images"></div>
            <button type="button" class="edit-btn" onclick="goToStep(2)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">عنوان</p>
            <p class="review-value" id="step7-title"></p>
            <button type="button" class="edit-btn" onclick="goToStep(1)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">توضیحات</p>
            <p class="review-value" id="step7-description"></p>
            <button type="button" class="edit-btn" onclick="goToStep(1)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">دسته‌بندی</p>
            <p class="review-value" id="step7-category"></p>
            <button type="button" class="edit-btn" onclick="goToStep(3)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">وضعیت کالا</p>
            <p class="review-value" id="step7-condition"></p>
            <button type="button" class="edit-btn" onclick="goToStep(3)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">شهر</p>
            <p class="review-value" id="step7-city"></p>
            <button type="button" class="edit-btn" onclick="goToStep(3)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">دسته‌بندی‌های مورد علاقه برای معاوضه</p>
            <p class="review-value" id="step7-want-cats"></p>
            <button type="button" class="edit-btn" onclick="goToStep(4)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">توضیح آنچه می‌خواهید</p>
            <p class="review-value" id="step7-want-desc"></p>
            <button type="button" class="edit-btn" onclick="goToStep(5)">ویرایش</button>
          </div>
          
          <div class="review-section">
            <p class="review-label">قیمت تخمینی</p>
            <p class="review-value" id="step7-price"></p>
            <button type="button" class="edit-btn" onclick="goToStep(6)">ویرایش</button>
          </div>
        </div>

        <!-- Step 8: Final preference categories -->
        <div class="wizard-step" data-step="8" id="step-8" style="display:none">
          <h2 class="wizard-step-title">انتخاب دسته‌بندی برای پیشنهادات</h2>
          <p class="wizard-step-subtitle">این دسته‌بندی‌ها در پیشنهادات آینده برای شما نمایش داده می‌شوند.</p>

          <div class="category-chips">
            <?php foreach ($exchangeCategories as $cat): ?>
              <div class="category-chip" data-category="<?= h($cat) ?>" 
                   onclick="toggleFinalCategory(this, '<?= h($cat) ?>')">
                <?= h($cat) ?>
              </div>
            <?php endforeach; ?>
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
const totalSteps = 8;
let exchangeCategories = new Set();
let finalCategories = new Set();
let uploadedFiles = [];

// Initialize step 1 character counters
document.addEventListener('DOMContentLoaded', () => {
  updateStepper();
  initStep1Counters();
  initStep2Upload();
});

function initStep1Counters() {
  const titleInput = document.getElementById('step1-title');
  const descInput  = document.getElementById('step1-description');

  titleInput.addEventListener('input', () => {
    document.getElementById('step1-title-count').textContent = titleInput.value.length;
  });
  descInput.addEventListener('input', () => {
    document.getElementById('step1-desc-count').textContent = descInput.value.length;
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
    syncFilesInput();
  }

  function renderPreview(src, idx) {
    const wrap = document.createElement('div');
    wrap.className = 'image-preview';
    wrap.id = 'step2-preview-' + idx;
    wrap.innerHTML = `<img src="${src}"><button type="button" class="image-preview-remove" onclick="removeImg(${idx})"><i class="bi bi-x"></i></button>`;
    if (idx === 0) {
      wrap.style.outline = '2.5px solid var(--wizard-primary)';
    }
    grid.appendChild(wrap);
  }

  window.removeImg = function(idx) {
    uploadedFiles[idx] = null;
    const el = document.getElementById('step2-preview-' + idx);
    if (el) el.remove();
    syncFilesInput();
  }

  function syncFilesInput() {
    const dt = new DataTransfer();
    uploadedFiles.filter(Boolean).forEach(f => dt.items.add(f));
    input.files = dt.files;
  }
}

// Exchange category toggles for step4
window.toggleExchangeCategory = function(el, cat) {
  if (exchangeCategories.has(cat)) {
    exchangeCategories.delete(cat);
    el.classList.remove('selected');
  } else {
    exchangeCategories.add(cat);
    el.classList.add('selected');
  }
  document.getElementById('step4-want-cats').value = JSON.stringify([...exchangeCategories]);
}

window.toggleFinalCategory = function(el, cat) {
  if (finalCategories.has(cat)) {
    finalCategories.delete(cat);
    el.classList.remove('selected');
  } else {
    finalCategories.add(cat);
    el.classList.add('selected');
  }
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
    case 8:
      return true;

    case 7:
      return true;

    default:
      return true;
  }
}

function goToStep(step) {
  if (step < 1 || step > totalSteps) return;
  // Hide all steps first
  document.querySelectorAll('.wizard-step').forEach(el => el.style.display = 'none');
  // Show target step
  document.getElementById('step-' + step).style.display = 'block';
  currentStep = step;
  updateStepper();
  updateButtons();
  
  if (step === 7) {
    populateReview();
  }
}

function updateStepper() {
  for (let i = 1; i <= totalSteps; i++) {
    const indicator = document.getElementById('step-' + i + '-indicator');
    indicator.classList.remove('active', 'completed');
    
    if (i < currentStep) {
      indicator.classList.add('completed');
    } else if (i === currentStep) {
      indicator.classList.add('active');
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

  // Disable next button if current step is invalid
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
  document.getElementById('step7-condition').textContent = condSelect.options[condSelect.selectedIndex].text;
  document.getElementById('step7-city').textContent = document.getElementById('step3-city').value || 'نامشخص';

  // Step 4 & 5
  document.getElementById('step7-want-cats').textContent = [...exchangeCategories].join('، ');
  document.getElementById('step7-want-desc').textContent = document.getElementById('step5-description').value;

  // Step 6
  document.getElementById('step7-price').textContent = document.getElementById('step6-price').textContent;

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
        clone.style.height = '80px';
        clone.style.borderRadius = '8px';
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

// Also update next button state when fields change
document.querySelectorAll('#wizard-form input, #wizard-form textarea, #wizard-form select').forEach(el => {
  el.addEventListener('input', updateButtons);
  el.addEventListener('change', updateButtons);
});

</script>

<?php render_footer(); ?>
