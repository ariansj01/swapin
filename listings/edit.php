<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = $user['id'];
$id   = (int)($_GET['id'] ?? 0);

// Load listing and verify ownership
$listing = DB::fetch(
    'SELECT * FROM listings WHERE id = ? AND user_id = ? AND status = "active"',
    [$id, $uid]
);

if (!$listing) {
    header('Location: ' . APP_URL . '/listings/my.php');
    exit;
}

// Existing images
$images = DB::fetchAll(
    'SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order',
    [$id]
);

$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order, c.sort_order), c.sort_order'
);

$errors = [];
$vals   = [
    'title'           => $listing['title'],
    'category_id'     => $listing['category_id'],
    'description'     => $listing['description'],
    'condition'       => $listing['condition'],
    'estimated_value' => $listing['estimated_value'],
    'want_in_return'  => $listing['want_in_return'],
    'want_type'       => $listing['want_type'],
    'listing_mode'    => $listing['listing_mode'] ?? 'swap',
    'sell_price'      => $listing['sell_price'] ?? 0,
    'city'            => $listing['city'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $vals['title']           = clean($_POST['title']           ?? '');
    $vals['category_id']     = (int)($_POST['category_id']     ?? 0);
    $vals['description']     = clean($_POST['description']     ?? '');
    $vals['condition']       = clean($_POST['condition']       ?? 'good');
    $vals['estimated_value'] = (float)($_POST['estimated_value'] ?? 0);
    $vals['want_in_return']  = clean($_POST['want_in_return']  ?? '');
    $vals['want_type']       = clean($_POST['want_type']       ?? 'any');
    $vals['listing_mode']    = clean($_POST['listing_mode']    ?? 'swap');
    $vals['sell_price']      = (float)($_POST['sell_price']    ?? 0);
    $vals['city']            = clean($_POST['city']            ?? '');

    // Validate (same rules as create.php)
    if (mb_strlen($vals['title']) < 5)
        $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد.';
    if (mb_strlen($vals['title']) > 200)
        $errors['title'] = 'عنوان باید کمتر از ۲۰۰ کاراکتر باشد.';
    if (!$vals['category_id'])
        $errors['category_id'] = 'لطفاً دسته‌بندی را انتخاب کنید.';
    if (!DB::fetch('SELECT id FROM categories WHERE id = ? AND is_active = 1', [$vals['category_id']]))
        $errors['category_id'] = 'دسته‌بندی انتخاب‌شده نامعتبر است.';
    if (mb_strlen($vals['description']) < 20)
        $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد.';
    if (!in_array($vals['condition'], ['new','like_new','good','fair','poor'], true))
        $errors['condition'] = 'وضعیت نامعتبر است.';
    if (mb_strlen($vals['want_in_return']) < 10)
        $errors['want_in_return'] = 'لطفاً بنویسید چه چیزی می‌خواهید (حداقل ۱۰ کاراکتر).';
    if (!in_array($vals['want_type'], ['item','service','credit','any'], true))
        $errors['want_type'] = 'نوع معامله نامعتبر است.';
    if (!in_array($vals['listing_mode'], ['swap','sell','both'], true))
        $errors['listing_mode'] = 'حالت آگهی نامعتبر است.';
    if (in_array($vals['listing_mode'], ['sell','both'], true) && $vals['sell_price'] <= 0)
        $errors['sell_price'] = 'قیمت فروش الزامی است.';
    if ($vals['city'] && !in_array($vals['city'], iran_cities(), true))
        $errors['city'] = 'لطفاً شهر را از فهرست انتخاب کنید.';

    $contentErrors = validate_listing_content([
        'title'           => $vals['title'],
        'description'     => $vals['description'],
        'want_in_return'  => $vals['want_in_return'],
    ]);
    foreach ($contentErrors as $field => $msg) {
        if (!isset($errors[$field])) $errors[$field] = $msg;
    }

    if (empty($errors)) {
        $reviewUpdate = ($listing['review_status'] ?? 'approved') === 'approved'
            ? ['review_status' => 'pending', 'review_note' => null]
            : [];

        DB::update('listings', array_merge([
            'category_id'     => $vals['category_id'],
            'title'           => $vals['title'],
            'description'     => $vals['description'],
            'condition'       => $vals['condition'],
            'estimated_value' => $vals['estimated_value'] ?: 0,
            'want_in_return'  => $vals['want_in_return'],
            'want_type'       => $vals['want_type'],
            'listing_mode'    => $vals['listing_mode'],
            'sell_price'      => in_array($vals['listing_mode'], ['sell','both'], true) ? $vals['sell_price'] : 0,
            'city'            => $vals['city'] ?: null,
        ], $reviewUpdate), 'id = ? AND user_id = ?', [$id, $uid]);

        // Handle image deletions
        $deleteIds = $_POST['delete_images'] ?? [];
        foreach ($deleteIds as $imgId) {
            $imgId = (int)$imgId;
            $img   = DB::fetch('SELECT filename FROM listing_images WHERE id = ? AND listing_id = ?', [$imgId, $id]);
            if ($img) {
                $path = UPLOAD_DIR . $img['filename'];
                if (file_exists($path)) unlink($path);
                DB::query('DELETE FROM listing_images WHERE id = ?', [$imgId]);
            }
        }

        // Handle new image uploads
        $currentCount = (int)(DB::fetch('SELECT COUNT(*) AS c FROM listing_images WHERE listing_id = ?', [$id])['c'] ?? 0);
        if (!empty($_FILES['images']['name'][0])) {
            foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                if ($currentCount >= MAX_IMAGES) break;
                $file = [
                    'name'     => $_FILES['images']['name'][$i],
                    'tmp_name' => $tmp,
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                $filename = upload_image($file, 'listing');
                if ($filename) {
                    $isPrimary = ($currentCount === 0) ? 1 : 0;
                    DB::insert('listing_images', [
                        'listing_id' => $id,
                        'filename'   => $filename,
                        'is_primary' => $isPrimary,
                        'sort_order' => $currentCount,
                    ]);
                    $currentCount++;
                }
            }
        }

        // Ensure at least one image is marked primary
        $primaryCheck = DB::fetch('SELECT id FROM listing_images WHERE listing_id = ? AND is_primary = 1 LIMIT 1', [$id]);
        if (!$primaryCheck) {
            $firstImg = DB::fetch('SELECT id FROM listing_images WHERE listing_id = ? ORDER BY sort_order LIMIT 1', [$id]);
            if ($firstImg) {
                DB::query('UPDATE listing_images SET is_primary = 1 WHERE id = ?', [$firstImg['id']]);
            }
        }

        header('Location: ' . APP_URL . '/listings/view?id=' . $id . '&edited=1');
        exit;
    }

    // Reload images after possible deletions on validation fail
    $images = DB::fetchAll(
        'SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order',
        [$id]
    );
}

render_head('ویرایش آگهی — ' . $listing['title']);
render_navbar($user);
?>

<?php if (isset($_GET['edited'])): ?>
<div class="alert alert-success" style="border-radius:0;border-inline-start:0;border-inline-end:0">
  <div class="container"><i class="bi bi-check-circle-fill"></i> آگهی با موفقیت به‌روزرسانی شد!</div>
</div>
<?php endif; ?>

<div class="section-sm">
  <div class="container-md">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/listings/view?id=<?= $id ?>" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به آگهی
      </a>
      <h2 class="mt-3">ویرایش آگهی</h2>
      <p style="color:var(--text-muted)">جزئیات آگهی خود را به‌روزرسانی کنید</p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-6">
      <i class="bi bi-exclamation-circle"></i>
      <div>لطفاً <strong><?= count($errors) ?></strong> خطای زیر را قبل از ذخیره برطرف کنید.</div>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="edit-form" novalidate>
    <?= csrf_field() ?>

      <!-- ── Step 1: Listing Details ── -->
      <div class="card mb-5">
        <div class="card-header">
          <h3 style="margin:0;font-size:1.0625rem">
            <i class="bi bi-info-circle" style="color:var(--primary)"></i> جزئیات آگهی
          </h3>
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
            <div class="form-hint"><span id="title-count"><?= mb_strlen($vals['title']) ?></span>/200 کاراکتر</div>
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
                        echo '<optgroup label="' . h(category_label($cat['slug'], $cat['name'])) . '">';
                        $lastParent = $cat['id'];
                    } else {
                        $sel = $vals['category_id'] == $cat['id'] ? 'selected' : '';
                        echo '<option value="' . $cat['id'] . '" ' . $sel . '>' . h(category_label($cat['slug'], $cat['name'])) . '</option>';
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
                      placeholder="کالا را با جزئیات توضیح دهید…" required><?= h($vals['description']) ?></textarea>
            <?php if (isset($errors['description'])): ?>
            <div class="invalid-feedback"><?= h($errors['description']) ?></div>
            <?php endif; ?>
            <div class="form-hint"><span id="desc-count"><?= mb_strlen($vals['description']) ?></span> کاراکتر</div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
            <div class="form-group">
              <label class="form-label" for="estimated_value">ارزش تقریبی (<?= CREDIT_UNIT ?>)</label>
              <input type="number" class="form-control" id="estimated_value" name="estimated_value"
                     value="<?= h((string)(int)$vals['estimated_value']) ?>"
                     placeholder="0" min="0" step="1">
              <div class="form-hint">برای تطبیق هوشمند کمک می‌کند</div>
            </div>
            <div class="form-group">
              <label class="form-label" for="city">شهر</label>
              <select id="city" name="city" class="form-control <?= isset($errors['city']) ? 'is-invalid' : '' ?>">
                <option value="">انتخاب شهر</option>
                <?= render_city_options($vals['city']) ?>
              </select>
              <?php if (isset($errors['city'])): ?>
              <div class="invalid-feedback"><?= h($errors['city']) ?></div>
              <?php endif; ?>
            </div>
          </div>

        </div>
      </div>

      <!-- ── Step 2: Photos ── -->
      <div class="card mb-5">
        <div class="card-header">
          <h3 style="margin:0;font-size:1.0625rem">
            <i class="bi bi-images" style="color:var(--primary)"></i> تصاویر
          </h3>
        </div>
        <div class="card-body">

          <!-- Existing images -->
          <?php if ($images): ?>
          <div class="mb-5">
            <p class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-3)">
              تصاویر فعلی — برای حذف تیک بزنید:
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:var(--sp-3)">
              <?php foreach ($images as $img): ?>
              <div style="position:relative;width:96px">
                <img src="<?= UPLOAD_URL . h($img['filename']) ?>" alt="تصویر آگهی"
                     style="width:96px;height:88px;object-fit:cover;border-radius:var(--radius-md);border:2px solid <?= $img['is_primary'] ? 'var(--primary)' : 'var(--border)' ?>">
                <?php if ($img['is_primary']): ?>
                <span style="position:absolute;top:4px;inset-inline-start:4px;font-size:.6rem;background:var(--primary);color:#fff;padding:2px 5px;border-radius:3px;font-weight:700">اصلی</span>
                <?php endif; ?>
                <label style="display:flex;align-items:center;gap:4px;margin-top:var(--sp-1);font-size:.75rem;color:var(--danger);cursor:pointer">
                  <input type="checkbox" name="delete_images[]" value="<?= $img['id'] ?>">
                  حذف
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Upload new -->
          <?php $remaining = MAX_IMAGES - count($images); ?>
          <?php if ($remaining > 0): ?>
          <div class="upload-zone" id="upload-zone" onclick="document.getElementById('images').click()">
            <i class="bi bi-cloud-upload"></i>
            <p style="font-weight:600;color:var(--text-secondary);margin-bottom:var(--sp-1)">
              افزودن تصویر
            </p>
            <p class="fs-sm" style="color:var(--text-muted)">
              تا <?= $remaining ?> تصویر دیگر — JPG، PNG یا WEBP، حداکثر ۵ مگابایت
            </p>
            <input type="file" id="images" name="images[]" multiple accept="image/*" style="display:none">
          </div>
          <div class="image-preview-grid" id="preview-grid"></div>
          <?php else: ?>
          <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            حداکثر <?= MAX_IMAGES ?> تصویر مجاز است. برای افزودن تصویر جدید، ابتدا تصاویر فعلی را حذف کنید.
          </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- ── Step 3: Trade Terms ── -->
      <div class="card mb-6">
        <div class="card-header">
          <h3 style="margin:0;font-size:1.0625rem">
            <i class="bi bi-arrow-left-right" style="color:var(--primary)"></i> شرایط معامله
          </h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label">حالت آگهی</label>
            <select name="listing_mode" class="form-control" id="listing_mode">
              <?php foreach (['swap'=>'فقط تعویض','sell'=>'فقط فروش','both'=>'تعویض + فروش'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($vals['listing_mode'] ?? 'swap') === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" id="edit-sell-price">
            <label class="form-label" for="sell_price">قیمت فروش (تومان)</label>
            <input type="number" name="sell_price" id="sell_price" class="form-control"
                   value="<?= h((string)$vals['sell_price']) ?>" min="0" step="1000">
          </div>

          <div class="form-group">
            <label class="form-label">به دنبال چه چیزی هستید؟ <span class="required">*</span></label>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-3)">
              <?php
              $types = ['any'=>['bi-stars','هر معامله‌ای'],'item'=>['bi-box','یک کالا'],'service'=>['bi-tools','یک خدمت'],'credit'=>['bi-wallet2','اعتبار ' . CREDIT_UNIT]];
              foreach ($types as $v => [$icon, $label]):
                $sel = $vals['want_type'] === $v;
              ?>
              <label style="cursor:pointer">
                <input type="radio" name="want_type" value="<?= $v ?>" <?= $sel ? 'checked' : '' ?> style="display:none" class="want-radio">
                <div class="trade-type-btn <?= $sel ? 'selected' : '' ?>"
                     style="text-align:center;padding:var(--sp-4);border:2px solid <?= $sel ? 'var(--primary)' : 'var(--border)' ?>;border-radius:var(--radius-md);background:<?= $sel ? 'rgba(26,107,74,.05)' : '' ?>;transition:all var(--duration)">
                  <i class="bi <?= $icon ?>" style="font-size:1.5rem;color:<?= $sel ? 'var(--primary)' : 'var(--text-muted)' ?>;display:block;margin-bottom:var(--sp-2)"></i>
                  <span style="font-size:.8125rem;font-weight:600;color:<?= $sel ? 'var(--primary)' : 'var(--text-secondary)' ?>"><?= $label ?></span>
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
                      placeholder="مثلاً لپ‌تاپ خوب، هدست گیمینگ یا اعتبار <?= CREDIT_UNIT ?>…"
                      required><?= h($vals['want_in_return']) ?></textarea>
            <?php if (isset($errors['want_in_return'])): ?>
            <div class="invalid-feedback"><?= h($errors['want_in_return']) ?></div>
            <?php endif; ?>
            <div class="form-hint">هرچه دقیق‌تر بنویسید، پیشنهادهای مرتبط‌تری دریافت می‌کنید</div>
          </div>

        </div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-3)">
        <a href="<?= APP_URL ?>/listings/view?id=<?= $id ?>" class="btn btn-ghost">
          <i class="bi bi-x"></i> انصراف
        </a>
        <button type="submit" class="btn btn-primary btn-lg" id="submit-btn">
          <i class="bi bi-check-circle"></i>
          <span id="btn-text">ذخیره تغییرات</span>
          <span id="btn-spinner" class="spinner" style="display:none;width:18px;height:18px;border-width:2px;border-color:rgba(255,255,255,.3);border-top-color:#fff"></span>
        </button>
      </div>

    </form>
  </div>
</div>

<script>
// Character counters
const titleInput = document.getElementById('title');
const descInput  = document.getElementById('description');
if (titleInput) titleInput.addEventListener('input', () => {
  document.getElementById('title-count').textContent = titleInput.value.length;
});
if (descInput) descInput.addEventListener('input', () => {
  document.getElementById('desc-count').textContent = descInput.value.length;
});

// New image preview (same as create.php)
const zone  = document.getElementById('upload-zone');
const input = document.getElementById('images');
const grid  = document.getElementById('preview-grid');
let newFiles = [];

if (input) {
  input.addEventListener('change', () => addFiles(input.files));
}
if (zone) {
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragging'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragging'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragging');
    addFiles(e.dataTransfer.files);
  });
}

function addFiles(files) {
  const remaining = <?= $remaining ?? 0 ?>;
  for (const f of files) {
    if (newFiles.length >= remaining) break;
    if (!f.type.match('image.*')) continue;
    newFiles.push(f);
    const reader = new FileReader();
    const idx = newFiles.length - 1;
    reader.onload = e => renderPreview(e.target.result, idx);
    reader.readAsDataURL(f);
  }
  syncInput();
}

function renderPreview(src, idx) {
  if (!grid) return;
  const wrap = document.createElement('div');
  wrap.className = 'preview-img-wrap';
  wrap.id = 'prev-' + idx;
  wrap.innerHTML = `<img src="${src}"><button type="button" class="preview-img-remove" onclick="removeImg(${idx})"><i class="bi bi-x"></i></button>`;
  grid.appendChild(wrap);
}

function removeImg(idx) {
  newFiles[idx] = null;
  const el = document.getElementById('prev-' + idx);
  if (el) el.remove();
  syncInput();
}

function syncInput() {
  if (!input) return;
  const dt = new DataTransfer();
  newFiles.filter(Boolean).forEach(f => dt.items.add(f));
  input.files = dt.files;
}

// Trade type selection highlight
document.querySelectorAll('.want-radio').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.trade-type-btn').forEach(btn => {
      btn.style.borderColor = 'var(--border)';
      btn.style.background  = '';
      btn.querySelector('i').style.color = 'var(--text-muted)';
      btn.querySelector('span').style.color = 'var(--text-secondary)';
    });
    const btn = radio.nextElementSibling;
    btn.style.borderColor = 'var(--primary-light)';
    btn.style.background  = 'rgba(0,174,239,.05)';
    btn.querySelector('i').style.color = 'var(--primary-light)';
    btn.querySelector('span').style.color = 'var(--primary-light)';
  });
});

// Loading state on submit
document.getElementById('edit-form').addEventListener('submit', function() {
  document.getElementById('btn-text').style.display    = 'none';
  document.getElementById('btn-spinner').style.display = 'inline-block';
  document.getElementById('submit-btn').disabled = true;
});

function toggleSellPrice() {
  const mode = document.getElementById('listing_mode')?.value || 'swap';
  const grp  = document.getElementById('edit-sell-price');
  if (grp) grp.style.display = (mode === 'sell' || mode === 'both') ? '' : 'none';
}
document.getElementById('listing_mode')?.addEventListener('change', toggleSellPrice);
toggleSellPrice();
</script>

<?php render_footer(); ?>
