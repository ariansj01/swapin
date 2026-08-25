<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/iso.php';

$user = require_auth();
$uid  = (int)$user['id'];

$myActiveListings = DB::fetchAll(
    'SELECT l.id, l.title, l.city, l.estimated_value, c.name AS cat_name,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved"
     ORDER BY l.created_at DESC',
    [$uid]
);

if (empty($myActiveListings)) {
    header('Location: ' . APP_URL . '/iso');
    exit;
}

$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);

$errors = [];
$vals = [
    'listing_id'   => (int)($_GET['listing_id'] ?? $myActiveListings[0]['id']),
    'title'        => '',
    'description'  => '',
    'category_id'  => 0,
    'city'         => $user['city'] ?? '',
    'neighborhood' => '',
    'latitude'     => '',
    'longitude'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $vals = [
        'listing_id'   => (int)($_POST['listing_id']   ?? 0),
        'title'        => clean($_POST['title']        ?? ''),
        'description'  => clean($_POST['description']  ?? ''),
        'category_id'  => (int)($_POST['category_id']  ?? 0),
        'city'         => clean($_POST['city']         ?? ''),
        'neighborhood' => clean($_POST['neighborhood'] ?? ''),
        'latitude'     => clean($_POST['latitude']     ?? ''),
        'longitude'    => clean($_POST['longitude']    ?? ''),
    ];
    $location = listing_location_from_request($vals);
    foreach (validate_listing_location($location) as $field => $msg) {
        if (!isset($errors[$field])) $errors[$field] = $msg;
    }
    $vals = array_merge($vals, $location);

    foreach (iso_validate_request($vals) as $f => $m) $errors[$f] = $m;

    if (empty($errors)) {
        $isoId = iso_create_request($uid, $vals);
        iso_generate_and_save_matches($isoId, 30);
        header('Location: ' . APP_URL . '/iso?msg=created_ok');
        exit;
    }
}

render_head('ثبت نیاز جدید (ISO)', 'ثبت چیزی که دنبالش هستید در ' . APP_NAME, [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
render_navbar($user);
listing_location_enqueue_assets();
?>

<?php render_user_panel_open($user, 'iso'); ?>
<div class="dash-panel">
  <?php render_panel_page_header('ثبت نیاز جدید', 'معمولاً ۳ تا ۵ دقیقه طول می‌کشد', APP_URL . '/iso', 'بازگشت به لیست ISO'); ?>

  <div class="card" style="max-width:760px;margin:0 auto 32px">
    <div class="card-header"><h3 style="margin:0;font-size:1rem"><i class="bi bi-search-heart" style="color:var(--accent)"></i> چی دنبالش می‌گردی؟</h3></div>
    <div class="card-body" style="padding:var(--sp-5)">
      <form method="post" id="iso-create-form" class="wizard-form">
        <?= csrf_input() ?>

        <!-- Step 1: Listing -->
        <fieldset class="wizard-step" data-step="1">
          <legend><span>۱</span> انتخاب کالایی که داری</legend>
          <div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-4)">ISO شما به این آگهی وصل می‌شود: «من [کالای شما] را دارم و در ازای آن [نیاز شما] را می‌پذیریم.»</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:var(--sp-3);margin-bottom:var(--sp-3)">
          <?php foreach ($myActiveListings as $l): ?>
            <label class="radio-card<?= $vals['listing_id'] === (int)$l['id'] ? ' is-checked' : '' ?>">
              <input type="radio" name="listing_id" value="<?= (int)$l['id'] ?>" <?= $vals['listing_id'] === (int)$l['id'] ? 'checked' : '' ?> required onchange="wizardSelectRadio(this)">
              <div class="radio-card__body" style="display:flex;gap:var(--sp-3);align-items:center;padding:10px">
                <div style="width:56px;height:56px;border-radius:10px;overflow:hidden;background:var(--border);flex-shrink:0">
                  <?php if (!empty($l['thumb'])): ?>
                    <img src="<?= UPLOAD_URL . h($l['thumb']) ?>" style="width:100%;height:100%;object-fit:cover">
                  <?php else: ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><i class="bi bi-image"></i></div>
                  <?php endif; ?>
                </div>
                <div style="min-width:0;flex:1">
                  <div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($l['title']) ?></div>
                  <div class="fs-xs" style="color:var(--text-muted)"><?= h($l['cat_name']) ?><?php if (!empty($l['city'])): ?> · <?= h($l['city']) ?><?php endif; ?></div>
                  <?php if (!empty($l['estimated_value']) && $l['estimated_value'] > 0): ?>
                  <div class="fs-xs" style="color:var(--primary);margin-top:2px">ارزش تقریبی: <?= fmt_num($l['estimated_value']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </label>
          <?php endforeach; ?>
          </div>
          <?php if (!empty($errors['listing_id'])): ?><div class="form-error" style="color:var(--danger);font-size:.85rem;margin-bottom:8px"><i class="bi bi-exclamation-circle"></i> <?= h($errors['listing_id']) ?></div><?php endif; ?>
          <div style="display:flex;justify-content:flex-end;margin-top:var(--sp-4)">
            <button type="button" class="btn btn-primary" onclick="wizardGoTo(2)">مرحله بعد <i class="bi bi-arrow-left"></i></button>
          </div>
        </fieldset>

        <!-- Step 2: Title + Description -->
        <fieldset class="wizard-step" data-step="2" hidden>
          <legend><span>۲</span> مشخصات کالای موردنظر</legend>
          <div class="form-group">
            <label class="form-label">عنوان چیزی که دنبالش هستید *</label>
            <input type="text" name="title" class="form-control" value="<?= h($vals['title']) ?>" placeholder="مثلاً: دوچرخه کوهستان سایز ۲۶" required minlength="3" maxlength="191">
            <?php if (!empty($errors['title'])): ?><div class="form-error" style="color:var(--danger);font-size:.85rem;margin-top:6px"><i class="bi bi-exclamation-circle"></i> <?= h($errors['title']) ?></div><?php endif; ?>
            <div class="form-hint" style="font-size:.78rem;color:var(--text-muted);margin-top:6px">هرچه عنوان دقیق‌تر باشد، تطابق‌های بهتری دریافت می‌کنید.</div>
          </div>
          <div class="form-group">
            <label class="form-label">توضیحات بیشتر (اختیاری)</label>
            <textarea name="description" class="form-control" rows="4" placeholder="مثلاً: ترجیحاً سالم و بدون ضربه، رنگ تیره، با لوازم جانبی اصلی"><?= h($vals['description']) ?></textarea>
            <?php if (!empty($errors['description'])): ?><div class="form-error" style="color:var(--danger);font-size:.85rem;margin-top:6px"><i class="bi bi-exclamation-circle"></i> <?= h($errors['description']) ?></div><?php endif; ?>
          </div>
          <div style="display:flex;justify-content:space-between;margin-top:var(--sp-4);gap:var(--sp-2);flex-wrap:wrap">
            <button type="button" class="btn btn-outline" onclick="wizardGoTo(1)"><i class="bi bi-arrow-right"></i> بازگشت</button>
            <button type="button" class="btn btn-primary" onclick="wizardGoTo(3)">مرحله بعد <i class="bi bi-arrow-left"></i></button>
          </div>
        </fieldset>

        <!-- Step 3: Category + Location -->
        <fieldset class="wizard-step" data-step="3" hidden>
          <legend><span>۳</span> دسته‌بندی و محدوده</legend>
          <div class="form-group">
            <label class="form-label">دسته‌بندی کالای موردنظر *</label>
            <select name="category_id" class="form-control" required id="iso-category-select">
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
                <option value="<?= (int)$c['id'] ?>" <?= $vals['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= $isParent ? h($c['name']) . ' (همه)' : h($c['name']) ?>
                </option>
              <?php endforeach; if ($lastParent !== null) echo '</optgroup>'; ?>
            </select>
            <?php if (!empty($errors['category_id'])): ?><div class="form-error" style="color:var(--danger);font-size:.85rem;margin-top:6px"><i class="bi bi-exclamation-circle"></i> <?= h($errors['category_id']) ?></div><?php endif; ?>
          </div>

          <?php listing_location_render_picker($vals, $errors); ?>

          <div style="display:flex;justify-content:space-between;margin-top:var(--sp-4);gap:var(--sp-2);flex-wrap:wrap">
            <button type="button" class="btn btn-outline" onclick="wizardGoTo(2)"><i class="bi bi-arrow-right"></i> بازگشت</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> ثبت و پیدا کردن تطابق</button>
          </div>
        </fieldset>
      </form>
    </div>
  </div>

</div>

<script>
function wizardSelectRadio(el) {
  document.querySelectorAll('.radio-card').forEach(c => c.classList.remove('is-checked'));
  const card = el.closest('.radio-card');
  if (card) card.classList.add('is-checked');
}
function wizardGoTo(n) {
  document.querySelectorAll('.wizard-step').forEach(s => s.hidden = true);
  const target = document.querySelector('.wizard-step[data-step="' + n + '"]');
  if (target) { target.hidden = false; target.scrollIntoView({behavior:'smooth',block:'start'}); }
}
</script>

<script>
// Make radio-card clickable as a fallback when inputs don't receive pointer events
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.radio-card').forEach(card => {
    card.addEventListener('click', (ev) => {
      // avoid reacting when clicking controls inside card (buttons/links)
      const tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
      if (['a','button','input','label'].includes(tag)) return;
      const input = card.querySelector('input[type="radio"][name="listing_id"]');
      if (!input || input.disabled) return;
      input.checked = true;
      if (typeof wizardSelectRadio === 'function') {
        try { wizardSelectRadio(input); } catch (e) { /* ignore */ }
      }
    });
  });
});
</script>

<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php listing_location_render_picker_inline_js(); ?>
<?php render_footer(); ?>
