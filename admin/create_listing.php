<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/listing_validator.php';

$admin = require_admin();
[$flash, $flashType] = admin_flash();

$errors = [];
$vals = [
    'title'            => '',
    'category_id'      => '',
    'description'      => '',
    'condition'        => 'good',
    'estimated_value'  => '',
    'want_type'        => 'any',
    'want_in_return'   => '',
    'city'             => $admin['city'] ?? '',
    'needs_inspection' => 0,
];

$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name
     FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1
     ORDER BY COALESCE(p.sort_order, c.sort_order), c.sort_order'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    $vals['title']            = clean($_POST['title'] ?? '');
    $vals['category_id']      = (int) ($_POST['category_id'] ?? 0);
    $vals['description']      = clean($_POST['description'] ?? '');
    $vals['condition']        = clean($_POST['condition'] ?? 'good');
    $vals['estimated_value']  = (float) ($_POST['estimated_value'] ?? 0);
    $vals['want_type']        = clean($_POST['want_type'] ?? 'any');
    $vals['want_in_return']   = clean($_POST['want_in_return'] ?? '');
    $vals['city']             = clean($_POST['city'] ?? '');
    $vals['needs_inspection'] = !empty($_POST['needs_inspection']) ? 1 : 0;

    if (mb_strlen($vals['title']) < 5) {
        $errors['title'] = 'عنوان باید حداقل ۵ کاراکتر باشد.';
    } elseif (mb_strlen($vals['title']) > 200) {
        $errors['title'] = 'عنوان باید کمتر از ۲۰۰ کاراکتر باشد.';
    }

    if (!$vals['category_id']) {
        $errors['category_id'] = 'دسته‌بندی را انتخاب کنید.';
    } elseif (!DB::fetch('SELECT id FROM categories WHERE id = ? AND is_active = 1', [$vals['category_id']])) {
        $errors['category_id'] = 'دسته‌بندی انتخاب‌شده نامعتبر است.';
    }

    if (mb_strlen($vals['description']) < 20) {
        $errors['description'] = 'توضیحات باید حداقل ۲۰ کاراکتر باشد.';
    }

    if (!in_array($vals['condition'], ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
        $errors['condition'] = 'وضعیت انتخاب‌شده نامعتبر است.';
    }

    if (!in_array($vals['want_type'], ['item', 'service', 'credit', 'any'], true)) {
        $errors['want_type'] = 'نوع درخواست نامعتبر است.';
    }

    if (mb_strlen($vals['want_in_return']) < 10) {
        $errors['want_in_return'] = 'توضیح خواسته‌ی ادمین باید حداقل ۱۰ کاراکتر باشد.';
    }

    $contentErrors = validate_listing_content([
        'title'          => $vals['title'],
        'description'    => $vals['description'],
        'want_in_return' => $vals['want_in_return'],
    ]);
    foreach ($contentErrors as $field => $msg) {
        if (!isset($errors[$field])) {
            $errors[$field] = $msg;
        }
    }

    if (empty($errors)) {
        $listingId = DB::insert('listings', [
            'user_id'          => (int) $admin['id'],
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
            'review_status'    => 'approved',
            'review_note'      => 'ثبت مستقیم توسط ادمین',
        ]);

        $uploadedImages = 0;
        if (!empty($_FILES['images']['name'][0])) {
            foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                if ($uploadedImages >= MAX_IMAGES) {
                    break;
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

        if (!empty($vals['needs_inspection'])) {
            request_expert_inspection($listingId, (int) $admin['id']);
        }

        ai_match_clear_cache((int) $admin['id']);
        admin_set_flash('آگهی ادمین با موفقیت ثبت و مستقیم منتشر شد.');
        header('Location: ' . APP_URL . '/admin/create_listing.php');
        exit;
    }
}

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1>ایجاد آگهی توسط ادمین</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">این آگهی با حساب ادمین ثبت می‌شود و مستقیماً در سایت فعال خواهد شد.</p>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-5">
  <i class="bi bi-exclamation-circle"></i>
  لطفاً خطاهای فرم را برطرف کنید.
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="card" style="padding:var(--sp-6);display:grid;gap:var(--sp-5)">
  <?= csrf_field() ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
    <div class="form-group">
      <label class="form-label" for="title">عنوان آگهی</label>
      <input type="text" id="title" name="title" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>" value="<?= h($vals['title']) ?>" maxlength="200" required>
      <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?= h($errors['title']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="category_id">دسته‌بندی</label>
      <select id="category_id" name="category_id" class="form-control <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" required>
        <option value="">انتخاب دسته‌بندی...</option>
        <?php
        $lastParent = null;
        foreach ($categories as $cat):
            if ($cat['parent_id'] === null) {
                if ($lastParent !== null) {
                    echo '</optgroup>';
                }
                echo '<optgroup label="' . h(category_label($cat['slug'], $cat['name'])) . '">';
                $lastParent = $cat['id'];
                continue;
            }
            $selected = (int) $vals['category_id'] === (int) $cat['id'] ? 'selected' : '';
            echo '<option value="' . (int) $cat['id'] . '" ' . $selected . '>' . h(category_label($cat['slug'], $cat['name'])) . '</option>';
        endforeach;
        if ($lastParent !== null) {
            echo '</optgroup>';
        }
        ?>
      </select>
      <?php if (isset($errors['category_id'])): ?><div class="invalid-feedback"><?= h($errors['category_id']) ?></div><?php endif; ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--sp-5)">
    <div class="form-group">
      <label class="form-label" for="condition">وضعیت</label>
      <select id="condition" name="condition" class="form-control <?= isset($errors['condition']) ? 'is-invalid' : '' ?>">
        <?php foreach (['new', 'like_new', 'good', 'fair', 'poor'] as $condition): ?>
        <option value="<?= h($condition) ?>" <?= $vals['condition'] === $condition ? 'selected' : '' ?>><?= h(condition_label($condition)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['condition'])): ?><div class="invalid-feedback"><?= h($errors['condition']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="estimated_value">ارزش تقریبی (<?= h(CREDIT_UNIT) ?>)</label>
      <input type="number" id="estimated_value" name="estimated_value" class="form-control" min="0" step="1000" value="<?= h((string) $vals['estimated_value']) ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="city">شهر</label>
      <select id="city" name="city" class="form-control">
        <option value="">انتخاب شهر</option>
        <?= render_city_options($vals['city']) ?>
      </select>
    </div>
  </div>

  <div class="form-group">
    <label class="form-label" for="description">توضیحات</label>
    <textarea id="description" name="description" rows="5" class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>" required><?= h($vals['description']) ?></textarea>
    <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= h($errors['description']) ?></div><?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:280px 1fr;gap:var(--sp-5)">
    <div class="form-group">
      <label class="form-label" for="want_type">نوع درخواست در ازا</label>
      <select id="want_type" name="want_type" class="form-control <?= isset($errors['want_type']) ? 'is-invalid' : '' ?>">
        <option value="any" <?= $vals['want_type'] === 'any' ? 'selected' : '' ?>>هر نوع پیشنهاد</option>
        <option value="item" <?= $vals['want_type'] === 'item' ? 'selected' : '' ?>>کالا</option>
        <option value="service" <?= $vals['want_type'] === 'service' ? 'selected' : '' ?>>خدمت</option>
        <option value="credit" <?= $vals['want_type'] === 'credit' ? 'selected' : '' ?>>اعتبار</option>
      </select>
      <?php if (isset($errors['want_type'])): ?><div class="invalid-feedback"><?= h($errors['want_type']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="want_in_return">در ازای این آگهی چه می‌خواهید؟</label>
      <textarea id="want_in_return" name="want_in_return" rows="3" class="form-control <?= isset($errors['want_in_return']) ? 'is-invalid' : '' ?>" required><?= h($vals['want_in_return']) ?></textarea>
      <?php if (isset($errors['want_in_return'])): ?><div class="invalid-feedback"><?= h($errors['want_in_return']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="form-group">
    <label class="form-label" for="images">تصاویر</label>
    <input type="file" id="images" name="images[]" class="form-control" multiple accept="image/*">
    <div class="form-hint">تا <?= MAX_IMAGES ?> تصویر قابل آپلود است.</div>
  </div>

  <label style="display:flex;align-items:center;gap:var(--sp-2);cursor:pointer">
    <input type="checkbox" name="needs_inspection" value="1" <?= !empty($vals['needs_inspection']) ? 'checked' : '' ?>>
    <span>برای این آگهی درخواست بازرسی هم ثبت شود</span>
  </label>

  <div style="display:flex;justify-content:flex-end;gap:var(--sp-3)">
    <a href="<?= APP_URL ?>/admin/listings.php" class="btn btn-ghost">مشاهده آگهی‌ها</a>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-plus-circle"></i> ثبت آگهی ادمین
    </button>
  </div>
</form>
<?php
$content = ob_get_clean();

render_admin_head('ایجاد آگهی');
render_admin_shell($admin, 'create_listing', $content);
render_admin_footer();
