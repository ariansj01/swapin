<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();

$errors = [];
$vals   = [
    'title'           => '',
    'category_id'     => '',
    'description'     => '',
    'condition'       => 'good',
    'estimated_value' => '',
    'want_in_return'  => '',
    'want_type'       => 'any',
    'listing_mode'    => 'swap',
    'sell_price'      => '',
    'needs_inspection'=> 0,
    'city'            => $user['city'] ?? '',
];

if (!can_create_listing($user)) {
    $limit = get_listing_limit($user);
}

$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vals['title']           = clean($_POST['title']           ?? '');
    $vals['category_id']     = (int)($_POST['category_id']     ?? 0);
    $vals['description']     = clean($_POST['description']     ?? '');
    $vals['condition']       = clean($_POST['condition']       ?? 'good');
    $vals['estimated_value'] = (float)($_POST['estimated_value'] ?? 0);
    $vals['want_in_return']  = clean($_POST['want_in_return']  ?? '');
    $vals['want_type']       = clean($_POST['want_type']       ?? 'any');
    $vals['listing_mode']    = 'swap';
    $vals['sell_price']      = 0;
    $vals['needs_inspection']= !empty($_POST['needs_inspection']) ? 1 : 0;
    $vals['city']            = clean($_POST['city']            ?? '');

    if (!can_create_listing($user))
        $errors['limit'] = 'سقف آگهی پر شده (' . get_listing_limit($user) . '). اشتراک خود را ارتقا دهید.';

    // Validate
    if (mb_strlen($vals['title']) < 5)
        $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد';
    if (mb_strlen($vals['title']) > 200)
        $errors['title'] = 'عنوان باید کمتر از ۲۰۰ کاراکتر باشد';
    if (!$vals['category_id'])
        $errors['category_id'] = 'لطفاً دسته‌بندی را انتخاب کنید';
    if (!DB::fetch('SELECT id FROM categories WHERE id = ? AND is_active = 1', [$vals['category_id']]))
        $errors['category_id'] = 'دسته‌بندی انتخاب‌شده نامعتبر است';
    if (mb_strlen($vals['description']) < 20)
        $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد';
    if (!in_array($vals['condition'], ['new','like_new','good','fair','poor'], true))
        $errors['condition'] = 'وضعیت انتخاب‌شده نامعتبر است';
    if (mb_strlen($vals['want_in_return']) < 10 && $vals['listing_mode'] !== 'sell')
        $errors['want_in_return'] = 'لطفاً بنویسید چه چیزی در ازای آن می‌خواهید (حداقل ۱۰ کاراکتر)';
    if (!in_array($vals['want_type'], ['item','service','credit','any'], true))
        $errors['want_type'] = 'نوع معامله نامعتبر است';
    if (!in_array($vals['listing_mode'], ['swap'], true))
        $vals['listing_mode'] = 'swap';
    if (in_array($vals['listing_mode'], ['sell','both'], true) && $vals['sell_price'] <= 0)
        $errors['sell_price'] = 'برای حالت فروش/هر دو، قیمت فروش الزامی است';
    if ($vals['listing_mode'] === 'sell' && mb_strlen($vals['want_in_return']) < 10)
        $vals['want_in_return'] = 'فروش مستقیم — نیازی به تعویض نیست';

    if (empty($errors)) {
        $listingId = DB::insert('listings', [
            'user_id'          => $user['id'],
            'category_id'      => $vals['category_id'],
            'title'            => $vals['title'],
            'description'      => $vals['description'],
            'condition'        => $vals['condition'],
            'estimated_value'  => $vals['estimated_value'] ?: 0,
            'want_in_return'   => $vals['want_in_return'],
            'want_type'        => $vals['want_type'],
            'listing_mode'     => 'swap',
            'sell_price'       => 0,
            'needs_inspection' => $vals['needs_inspection'],
            'city'             => $vals['city'] ?: null,
            'status'           => 'active',
        ]);

        // Handle image uploads
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

        if (!empty($vals['needs_inspection'])) {
            request_expert_inspection($listingId, $user['id']);
        }

        ai_match_clear_cache((int) $user['id']);

        header('Location: ' . APP_URL . '/listings/view.php?id=' . $listingId . '&created=1'); exit;
    }
}

render_head('ثبت آگهی');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به آگهی‌ها
      </a>
      <h2 class="mt-3">ثبت آگهی جدید</h2>
      <p style="color:var(--text-muted)">کالا یا خدمت خود را ثبت کنید و بگویید چه چیزی می‌خواهید</p>
    </div>

    <!-- Progress Steps -->
    <div class="steps mb-8">
      <div class="step-item active" id="step-1-indicator">
        <div class="step-num">1</div>
        <div style="font-size:.8125rem;font-weight:600;color:var(--primary);margin-inline-start:var(--sp-2)">جزئیات</div>
        <div class="step-line"></div>
      </div>
      <div class="step-item" id="step-2-indicator">
        <div class="step-num">2</div>
        <div style="font-size:.8125rem;color:var(--text-muted);margin-inline-start:var(--sp-2)">تصاویر</div>
        <div class="step-line"></div>
      </div>
      <div class="step-item" id="step-3-indicator">
        <div class="step-num">3</div>
        <div style="font-size:.8125rem;color:var(--text-muted);margin-inline-start:var(--sp-2)">شرایط معامله</div>
      </div>
    </div>

    <?php if (!empty($errors['limit'])): ?>
    <div class="alert alert-warning mb-6">
      <i class="bi bi-exclamation-triangle"></i>
      <?= h($errors['limit']) ?> <a href="<?= APP_URL ?>/subscription.php">مشاهده پلن‌ها</a>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-6">
      <i class="bi bi-exclamation-circle"></i>
      <div>لطفاً <strong><?= count($errors) ?></strong> خطای زیر را قبل از ارسال برطرف کنید.</div>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="create-form" novalidate>

      <!-- ── Step 1: Listing Details ───────────────────────────────── -->
      <div class="card mb-5" id="step-1">
        <div class="card-header">
          <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-info-circle" style="color:var(--primary)"></i> جزئیات آگهی</h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label" for="title">عنوان <span class="required">*</span></label>
            <input type="text" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                   id="title" name="title" value="<?= h($vals['title']) ?>"
                   placeholder="مثلاً آیفون ۱۳ پرو مکس ۲۵۶ گیگ" maxlength="200" required>
            <?php if (isset($errors['title'])): ?>
            <div class="invalid-feedback"><?= h($errors['title']) ?></div>
            <?php endif; ?>
            <div class="form-hint"><span id="title-count">0</span>/200 کاراکتر</div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
            <div class="form-group">
              <label class="form-label" for="category_id">دسته‌بندی <span class="required">*</span></label>
              <select class="form-control <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>"
                      id="category_id" name="category_id" required>
                <option value="">انتخاب دسته‌بندی…</option>
                <?php
                $lastParent = null;
                foreach ($categories as $cat):
                    if ($cat['parent_id'] === null) {
                        if ($lastParent !== null) echo '</optgroup>';
                        echo "<optgroup label=\"" . h(category_label($cat['slug'], $cat['name'])) . "\">";
                        $lastParent = $cat['id'];
                    } else {
                        $sel = $vals['category_id'] == $cat['id'] ? 'selected' : '';
                        echo "<option value=\"{$cat['id']}\" {$sel}>" . h(category_label($cat['slug'], $cat['name'])) . "</option>";
                    }
                endforeach;
                if ($lastParent !== null) echo '</optgroup>';
                ?>
              </select>
              <?php if (isset($errors['category_id'])): ?>
              <div class="invalid-feedback"><?= h($errors['category_id']) ?></div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="form-label" for="condition">وضعیت <span class="required">*</span></label>
              <select class="form-control" id="condition" name="condition">
                <?php foreach (['new','like_new','good','fair','poor'] as $v): ?>
                <option value="<?= $v ?>" <?= $vals['condition'] === $v ? 'selected' : '' ?>><?= condition_label($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="description">توضیحات <span class="required">*</span></label>
            <textarea class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                      id="description" name="description" rows="5"
                      placeholder="کالا را با جزئیات توضیح دهید — سن، برند، مشخصات، ایرادات…" required><?= h($vals['description']) ?></textarea>
            <?php if (isset($errors['description'])): ?>
            <div class="invalid-feedback"><?= h($errors['description']) ?></div>
            <?php endif; ?>
            <div class="form-hint"><span id="desc-count">0</span> کاراکتر</div>
          </div>

          <div style="display:grid;grid-template-columns:1fr;gap:var(--sp-5)">
            <div class="form-group">
              <label class="form-label" for="city">شهر</label>
              <input type="text" class="form-control" id="city" name="city"
                     value="<?= h($vals['city']) ?>" placeholder="شهر شما">
            </div>
          </div>

          <div class="ai-pricing-hint">
            <div class="ai-pricing-hint__icon"><i class="bi bi-stars"></i></div>
            <div>
              <strong>ارزش‌گذاری هوشمند با AI</strong>
              <p>بعد از تکمیل فرم، هوش مصنوعی سواپین ارزش تقریبی کالای شما را محاسبه می‌کند — نیازی به حدس زدن قیمت نیست.</p>
            </div>
          </div>
          <input type="hidden" id="estimated_value" name="estimated_value" value="<?= h((string)$vals['estimated_value']) ?>">

        </div>
      </div>

      <!-- ── Step 2: Photos ────────────────────────────────────────── -->
      <div class="card mb-5" id="step-2">
        <div class="card-header">
          <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-images" style="color:var(--primary)"></i> تصاویر</h3>
        </div>
        <div class="card-body">
          <div class="upload-zone" id="upload-zone" onclick="document.getElementById('images').click()">
            <i class="bi bi-cloud-upload"></i>
            <p style="font-weight:600;color:var(--text-secondary);margin-bottom:var(--sp-1)">تصاویر را اینجا رها کنید یا برای آپلود کلیک کنید</p>
            <p class="fs-sm" style="color:var(--text-muted)">JPG، PNG یا WEBP — حداکثر ۵ مگابایت — تا <?= MAX_IMAGES ?> تصویر</p>
            <input type="file" id="images" name="images[]" multiple accept="image/*" style="display:none">
          </div>
          <div class="image-preview-grid" id="preview-grid"></div>
        </div>
      </div>

      <!-- ── Step 3: Trade Terms ────────────────────────────────────── -->
      <div class="card mb-6" id="step-3">
        <div class="card-header">
          <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-arrow-left-right" style="color:var(--primary)"></i> شرایط معامله</h3>
        </div>
        <div class="card-body">

          <div class="alert alert-info mb-5">
            <i class="bi bi-lightbulb"></i>
            <div>هسته <?= APP_NAME ?> همین است — دقیق بگویید در ازای کالای خود چه چیزی می‌خواهید.</div>
          </div>

          <input type="hidden" name="listing_mode" value="swap">

          <div class="alert alert-info mb-5">
            <i class="bi bi-arrow-left-right"></i>
            <div>سواپین فقط برای <strong>معاوضه</strong> است — کالای خود را بدهید، آنچه می‌خواهید بگیرید.</div>
          </div>

          <div class="form-group" id="sell-price-group" style="display:none">
            <label class="form-label" for="sell_price">قیمت فروش (تومان) <span class="required">*</span></label>
            <input type="number" class="form-control <?= isset($errors['sell_price']) ? 'is-invalid' : '' ?>"
                   id="sell_price" name="sell_price" value="<?= h((string)$vals['sell_price']) ?>"
                   min="0" step="1000" placeholder="مثلاً ۵۰۰۰۰۰۰">
            <?php if (isset($errors['sell_price'])): ?><div class="invalid-feedback"><?= h($errors['sell_price']) ?></div><?php endif; ?>
          </div>

          <div class="form-group">
            <label style="display:flex;align-items:center;gap:var(--sp-2);cursor:pointer">
              <input type="checkbox" name="needs_inspection" value="1" <?= !empty($vals['needs_inspection']) ? 'checked' : '' ?>>
              <span class="form-label" style="margin:0">درخواست بازرسی کارشناس (<?= fmt_credit(INSPECTION_KBC) ?>)</span>
            </label>
            <div class="form-hint">کارشناس تأییدشده وضعیت کالا را قبل از معامله بررسی می‌کند</div>
          </div>

          <div class="form-group">
            <label class="form-label">به دنبال چه چیزی هستید؟ <span class="required">*</span></label>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-3)">
              <?php
              $types = ['any' => ['bi-stars','هر معامله‌ای'], 'item' => ['bi-box','یک کالا'], 'service' => ['bi-tools','یک خدمت'], 'credit' => ['bi-wallet2','اعتبار ' . CREDIT_UNIT]];
              foreach ($types as $v => [$icon, $label]):
                $sel = $vals['want_type'] === $v;
              ?>
              <label style="cursor:pointer">
                <input type="radio" name="want_type" value="<?= $v ?>" <?= $sel ? 'checked' : '' ?> style="display:none" class="want-radio">
                <div class="trade-type-btn <?= $sel ? 'selected' : '' ?>" style="text-align:center;padding:var(--sp-4);border:2px solid var(--border);border-radius:var(--radius-md);transition:all var(--duration)">
                  <i class="bi <?= $icon ?>" style="font-size:1.5rem;color:var(--text-muted);display:block;margin-bottom:var(--sp-2)"></i>
                  <span style="font-size:.8125rem;font-weight:600;color:var(--text-secondary)"><?= $label ?></span>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="want_in_return">
              توضیح آنچه می‌خواهید <span class="required">*</span>
            </label>
            <textarea class="form-control <?= isset($errors['want_in_return']) ? 'is-invalid' : '' ?>"
                      id="want_in_return" name="want_in_return" rows="3"
                      placeholder="مثلاً لپ‌تاپ خوب، هدست گیمینگ یا اعتبار معادل <?= CREDIT_UNIT ?>…" required><?= h($vals['want_in_return']) ?></textarea>
            <?php if (isset($errors['want_in_return'])): ?>
            <div class="invalid-feedback"><?= h($errors['want_in_return']) ?></div>
            <?php endif; ?>
            <div class="form-hint">هرچه دقیق‌تر بنویسید، پیشنهادهای مرتبط‌تری دریافت می‌کنید</div>
          </div>

        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:var(--sp-3)">
        <a href="<?= APP_URL ?>/" class="btn btn-ghost">انصراف</a>
        <button type="submit" class="btn btn-primary btn-lg" id="submit-btn">
          <i class="bi bi-stars"></i>
          <span id="btn-text">ادامه — قیمت‌گذاری هوشمند</span>
          <span id="btn-spinner" class="spinner" style="display:none;width:18px;height:18px;border-width:2px;border-color:rgba(255,255,255,.3);border-top-color:#fff"></span>
        </button>
      </div>

    </form>
  </div>
</div>

<div class="ai-pricing-overlay" id="ai-pricing-overlay" hidden>
  <div class="ai-pricing-panel">
    <div class="ai-pricing-panel__head">
      <span class="ai-pricing-panel__badge"><i class="bi bi-stars"></i> هوش مصنوعی سواپین</span>
      <h2 id="ai-pricing-title">در حال تحلیل کالای شما…</h2>
    </div>

    <div class="ai-pricing-loading" id="ai-pricing-loading">
      <div class="ai-pricing-loader">
        <div class="ai-pricing-loader__ring"></div>
        <i class="bi bi-robot"></i>
      </div>
      <ul class="ai-pricing-steps" id="ai-pricing-steps">
        <li class="is-active">بررسی مشخصات و وضعیت کالا</li>
        <li>مقایسه با بازار معاوضه</li>
        <li>محاسبه ارزش تقریبی SWP</li>
      </ul>
    </div>

    <div class="ai-pricing-result" id="ai-pricing-result" hidden>
      <div class="ai-pricing-result__value">
        <span class="ai-pricing-result__label">ارزش پیشنهادی AI</span>
        <div class="ai-pricing-result__amount" id="ai-pricing-amount">—</div>
        <div class="ai-pricing-result__range" id="ai-pricing-range"></div>
        <div class="ai-pricing-result__confidence" id="ai-pricing-confidence"></div>
      </div>
      <ul class="ai-pricing-result__reasons" id="ai-pricing-reasons"></ul>
      <p class="ai-pricing-result__note" id="ai-pricing-note"></p>
      <div class="ai-pricing-result__actions">
        <button type="button" class="btn btn-ghost" id="ai-pricing-back">ویرایش مشخصات</button>
        <button type="button" class="btn btn-accent btn-lg" id="ai-pricing-confirm">
          <i class="bi bi-check-circle"></i> تأیید و ثبت آگهی
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Character counters
const titleInput = document.getElementById('title');
const descInput  = document.getElementById('description');
titleInput.addEventListener('input', () => {
  document.getElementById('title-count').textContent = titleInput.value.length;
});
descInput.addEventListener('input', () => {
  document.getElementById('desc-count').textContent = descInput.value.length;
});
// Init counts
document.getElementById('title-count').textContent = titleInput.value.length;
document.getElementById('desc-count').textContent  = descInput.value.length;

// Image preview
const zone    = document.getElementById('upload-zone');
const input   = document.getElementById('images');
const grid    = document.getElementById('preview-grid');
let files     = [];

input.addEventListener('change', () => addFiles(input.files));

zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragging'); });
zone.addEventListener('dragleave',() => zone.classList.remove('dragging'));
zone.addEventListener('drop', e => {
  e.preventDefault();
  zone.classList.remove('dragging');
  addFiles(e.dataTransfer.files);
});

function addFiles(newFiles) {
  for (const f of newFiles) {
    if (files.length >= <?= MAX_IMAGES ?>) break;
    if (!f.type.match('image.*')) continue;
    files.push(f);
    const reader = new FileReader();
    const idx    = files.length - 1;
    reader.onload = e => renderPreview(e.target.result, idx);
    reader.readAsDataURL(f);
  }
  syncFilesInput();
}

function renderPreview(src, idx) {
  const wrap = document.createElement('div');
  wrap.className = 'preview-img-wrap';
  wrap.id = 'prev-' + idx;
  wrap.innerHTML = `<img src="${src}"><button type="button" class="preview-img-remove" onclick="removeImg(${idx})"><i class="bi bi-x"></i></button>`;
  if (idx === 0) wrap.style.outline = '2.5px solid var(--primary)'; // primary badge
  grid.appendChild(wrap);
}

function removeImg(idx) {
  files[idx] = null;
  const el = document.getElementById('prev-' + idx);
  if (el) el.remove();
  syncFilesInput();
}

function syncFilesInput() {
  const dt = new DataTransfer();
  files.filter(Boolean).forEach(f => dt.items.add(f));
  input.files = dt.files;
}

// Listing mode toggle
function updateListingMode() {
  const mode = document.querySelector('.mode-radio:checked')?.value || 'swap';
  const sellGroup = document.getElementById('sell-price-group');
  const wantGroup = document.querySelector('.form-group:has(.want-radio)')?.parentElement;
  sellGroup.style.display = (mode === 'sell' || mode === 'both') ? '' : 'none';
  const wantField = document.getElementById('want_in_return');
  if (mode === 'sell') {
    wantField.removeAttribute('required');
    wantField.placeholder = 'اختیاری — خریدار ترجیحی را توضیح دهید یا خالی بگذارید';
  } else {
    wantField.setAttribute('required', 'required');
  }
}
document.querySelectorAll('.mode-radio').forEach(r => r.addEventListener('change', updateListingMode));
updateListingMode();

// Trade type selection highlight
document.querySelectorAll('.want-radio').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.trade-type-btn').forEach(btn => {
      btn.style.borderColor = 'var(--border)';
      btn.style.background  = '';
      btn.querySelector('i').style.color = 'var(--text-muted)';
    });
    const btn = radio.nextElementSibling;
    btn.style.borderColor = 'var(--primary-light)';
    btn.style.background  = 'rgba(0,174,239,.05)';
    btn.querySelector('i').style.color = 'var(--primary-light)';
  });
});
// Init selected
document.querySelector('.want-radio:checked')?.dispatchEvent(new Event('change'));
</script>

<script src="<?= APP_URL ?>/src/js/ai-pricing.js"></script>

<?php render_footer(); ?>
