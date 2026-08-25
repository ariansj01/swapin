<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/iso.php';

$user = require_auth();
$uid  = (int)$user['id'];
$id   = (int)($_GET['id'] ?? 0);

$iso = iso_get_request($id, $uid);

if (!$iso) {
    http_response_code(404);
    render_head('ISO یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container"><div class="empty-state"><i class="bi bi-exclamation-circle"></i><h1>ISO یافت نشد</h1><p>این درخواست ISO وجود ندارد یا متعلق به شما نیست.</p><a href="' . APP_URL . '/iso" class="btn btn-primary">بازگشت به ISOها</a></div></div></main>';
    render_footer();
    exit;
}

$myActiveListings = DB::fetchAll(
    'SELECT l.id, l.title, l.city, l.estimated_value, c.name AS cat_name,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved"
     ORDER BY l.created_at DESC',
    [$uid]
);

$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);

$errors = [];
$vals = [
    'listing_id'   => $iso['listing_id'],
    'title'        => $iso['title'],
    'description'  => $iso['description'] ?? '',
    'category_id'  => $iso['category_id'],
    'city'         => $iso['city'] ?? '',
    'neighborhood' => $iso['neighborhood'] ?? '',
    'latitude'     => $iso['latitude'] ?? '',
    'longitude'    => $iso['longitude'] ?? '',
    'status'       => $iso['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $vals = [
        'listing_id'   => (int)($_POST['listing_id']   ?? $iso['listing_id']),
        'title'        => clean($_POST['title']        ?? $iso['title']),
        'description'  => clean($_POST['description']  ?? ''),
        'category_id'  => (int)($_POST['category_id']  ?? $iso['category_id']),
        'city'         => clean($_POST['city']         ?? ''),
        'neighborhood' => clean($_POST['neighborhood'] ?? ''),
        'latitude'     => clean($_POST['latitude']     ?? ''),
        'longitude'    => clean($_POST['longitude']    ?? ''),
        'status'       => clean($_POST['status']       ?? $iso['status']),
    ];
    $location = listing_location_from_request($vals);
    foreach (validate_listing_location($location) as $field => $msg) {
        if (!isset($errors[$field])) $errors[$field] = $msg;
    }
    $vals = array_merge($vals, $location);

    foreach (iso_validate_request($vals, true) as $f => $m) $errors[$f] = $m;

    if (empty($errors)) {
        iso_update_request($id, $uid, $vals);
        if ($vals['status'] === 'active') {
            iso_generate_and_save_matches($id, 30);
        }
        header('Location: ' . APP_URL . '/iso/view?id=' . $id . '&msg=updated');
        exit;
    }
}

render_head('ویرایش ISO', 'ویرایش نیاز ISO در ' . APP_NAME, [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
render_navbar($user);
listing_location_enqueue_assets();
?>

<?php render_user_panel_open($user, 'iso'); ?>
<div class="dash-panel">
  <?php render_panel_page_header('ویرایش ISO', 'تغییرات را ذخیره کنید تا تطابق‌ها به‌روز شوند', APP_URL . '/iso/view?id=' . (int)$id, 'بازگشت به تطابق‌ها'); ?>

  <div class="card" style="max-width:760px;margin:0 auto 32px">
    <div class="card-header"><h3 style="margin:0;font-size:1rem"><i class="bi bi-pencil" style="color:var(--primary)"></i> ویرایش «<?= h(mb_strimwidth($iso['title'], 0, 50, '…')) ?>»</h3></div>
    <div class="card-body" style="padding:var(--sp-5)">
      <form method="post">
        <?= csrf_input() ?>

        <div class="form-group">
          <label class="form-label">وضعیت ISO</label>
          <select name="status" class="form-control">
            <option value="active" <?= $vals['status'] === 'active' ? 'selected' : ?>>فعال — در حال جستجو</option>
            <option value="paused" <?= $vals['status'] === 'paused' ? 'selected' : ?>>متوقف — موقتاً غیرفعال</option>
            <option value="completed" <?= $vals['status'] === 'completed' ? 'selected' : ?>>تکمیل‌شده — پیدا شد</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">کالایی که دارید (ISO به این آگهی وصل است)</label>
          <select name="listing_id" class="form-control" required>
          <?php foreach ($myActiveListings as $l): ?>
            <option value="<?= (int)$l['id'] ?>" <?= $vals['listing_id'] === (int)$l['id'] ? 'selected' : ?>>
              <?= h($l['title']) ?> · <?= h($l['cat_name']) ?><?php if (!empty($l['estimated_value'])): ?> · <?= fmt_num($l['estimated_value']) ?><?php endif; ?>
            </option>
          <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">عنوان چیزی که دنبالش هستید *</label>
          <input type="text" name="title" class="form-control" value="<?= h($vals['title']) ?>" required minlength="3" maxlength="191">
          <?php if (!empty($errors['title'])): ?><div class="form-error" style="color:var(--danger);font-size:.85rem;margin-top:6px"><i class="bi bi-exclamation-circle"></i> <?= h($errors['title']) ?></div><?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">توضیحات بیشتر</label>
          <textarea name="description" class="form-control" rows="4"><?= h($vals['description']) ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">دسته‌بندی کالای موردنظر *</label>
          <select name="category_id" class="form-control" required>
            <option value="">انتخاب کنید...</option>
            <?php
              $lastParent = null;
              foreach ($categories as $c):
                $isParent = (int)$c['parent_id'] === 0;
                if ($isParent) {
                    if ($lastParent !== null) echo '</optgroup>';
                    echo '<optgroup label="' . h($c['name']) . '">';
                    $lastParent = $c['id'];
                }
            ?>
              <option value="<?= (int)$c['id'] ?>" <?= $vals['category_id'] === (int)$c['id'] ? 'selected' : ?>>
                <?= $isParent ? h($c['name']) . ' (همه)' : h($c['name']) ?>
              </option>
            <?php endforeach; if ($lastParent !== null) echo '</optgroup>'; ?>
          </select>
          <?php if (!empty($errors['category_id'])): ?><div class="form-error" style="color:var(--danger);font-size:.85rem;margin-top:6px"><i class="bi bi-exclamation-circle"></i> <?= h($errors['category_id']) ?></div><?php endif; ?>
        </div>

        <?php listing_location_render_picker($vals, $errors); ?>

        <div style="display:flex;gap:var(--sp-3);justify-content:space-between;margin-top:var(--sp-5);flex-wrap:wrap">
          <a href="<?= APP_URL ?>/iso/view?id=<?= (int)$id ?>" class="btn btn-outline">انصراف</a>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> ذخیره تغییرات و به‌روزرسانی تطابق‌ها</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php listing_location_render_picker_inline_js(); ?>
<?php render_footer(); ?>
