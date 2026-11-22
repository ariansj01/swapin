<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
[$flash, $flashType] = admin_flash();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (swaapin_swap_landing_delete($id)) {
            admin_set_flash('لندینگ معاوضه حذف شد.');
        } else {
            admin_set_flash('حذف لندینگ ناموفق بود.', 'danger');
        }
        header('Location: ' . APP_URL . '/admin/swap_landings.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && swaapin_swap_landings_table_exists()) {
            $row = DB::fetch('SELECT is_active FROM swap_landings WHERE id = ? LIMIT 1', [$id]);
            if ($row) {
                DB::update('swap_landings', ['is_active' => ((int) $row['is_active'] === 1 ? 0 : 1)], 'id = ?', [$id]);
                admin_set_flash('وضعیت فعال/غیرفعال لندینگ تغییر کرد.');
            }
        }
        header('Location: ' . APP_URL . '/admin/swap_landings.php');
        exit;
    }

    if ($action === 'save') {
        $id  = (int) ($_POST['id'] ?? 0);
        $res = swaapin_swap_landing_save([
            'location_slug' => (string) ($_POST['location_slug'] ?? 'iran'),
            'token'         => (string) ($_POST['token'] ?? ''),
            'title'         => (string) ($_POST['title'] ?? ''),
            'h1'            => (string) ($_POST['h1'] ?? ''),
            'intro'         => (string) ($_POST['intro'] ?? ''),
            'index_status'  => (string) ($_POST['index_status'] ?? 'auto'),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        ], $id);

        if ($res['ok']) {
            admin_set_flash($id > 0 ? 'لندینگ معاوضه به‌روزرسانی شد.' : 'لندینگ معاوضه جدید ساخته شد.');
            header('Location: ' . APP_URL . '/admin/swap_landings.php');
            exit;
        }
        $errors = $res['errors'];
    }

    if ($action === 'import') {
        $res = swaapin_swap_landing_import((string) ($_POST['csv'] ?? ''));
        $msg = sprintf('ورود انجام شد: %d افزوده، %d به‌روزرسانی.', (int) $res['added'], (int) $res['updated']);
        if (!empty($res['skipped'])) {
            $msg .= ' ' . count($res['skipped']) . ' مورد رد شد.';
        }
        admin_set_flash($msg, !empty($res['skipped']) && $res['added'] === 0 && $res['updated'] === 0 ? 'danger' : 'success');
        header('Location: ' . APP_URL . '/admin/swap_landings.php');
        exit;
    }

    if ($action === 'save_templates') {
        $map = [];
        foreach (array_keys(swaapin_swap_template_defaults()) as $k) {
            $map[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        swaapin_swap_settings_save($map);
        admin_set_flash('قالب‌های تایتل و H1 ذخیره شد.');
        header('Location: ' . APP_URL . '/admin/swap_landings.php');
        exit;
    }
}

$editing = null;
$editId  = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && swaapin_swap_landings_table_exists()) {
    $editing = DB::fetch('SELECT * FROM swap_landings WHERE id = ? LIMIT 1', [$editId]);
}

$rows      = swaapin_swap_landings_all();
$templates = swaapin_swap_settings();

try {
    $locations = DB::fetchAll("SELECT slug, name, level FROM locations WHERE is_active = 1 ORDER BY FIELD(level,'country','province','city','neighborhood'), name");
} catch (Throwable $e) {
    $locations = [];
}
$locLabels = ['country' => 'کشور', 'province' => 'استان', 'city' => 'شهر', 'neighborhood' => 'محله'];

$typeLabels = ['location' => 'مکان', 'category' => 'دسته', 'model' => 'مدل', 'xy' => 'X با Y'];

/** توصیف خوانای «تعریف فیلتر» هر لندینگ. */
$describeFilter = static function (array $row): string {
    $ctx  = swaapin_swap_landing_context($row);
    $type = (string) ($row['type'] ?? 'category');
    if ($type === 'location') {
        return 'همهٔ معاوضه‌های ' . ($ctx['location'] !== '' ? $ctx['location'] : 'ایران');
    }
    if ($type === 'model') {
        $label = trim(($ctx['brand'] ?? '') . ' ' . ($ctx['model'] ?? ''));
        return 'مدل: ' . ($label !== '' ? $label : (string) $row['model_slug']);
    }
    if ($type === 'xy') {
        return ($ctx['x'] !== '' ? $ctx['x'] : 'کالا') . ' با ' . ($ctx['y'] !== '' ? $ctx['y'] : '—');
    }
    return 'دسته: ' . ($ctx['category'] !== '' ? $ctx['category'] : (string) $row['category_slug']);
};

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>
<?php if ($errors): ?>
<div class="alert alert-danger mb-5"><i class="bi bi-exclamation-triangle"></i> <?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="admin-header">
  <div>
    <h1>لندینگ‌های معاوضه</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">
      صفحه‌های فرود نیت «معاوضه و تهاتر». فقط آگهی‌های معاوضه‌ای نمایش داده می‌شوند؛
      آدرس هر لندینگ به شکل <code>/{location}/list/{token}</code> است و اسلاگ آن فقط از اسلاگ دسته‌ها و مکان‌ها ساخته می‌شود.
    </p>
  </div>
</div>

<form method="POST" class="card" style="padding:var(--sp-6);display:grid;gap:var(--sp-5);margin-bottom:var(--sp-6)">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : 0 ?>">

  <h3 style="margin:0"><?= $editing ? 'ویرایش لندینگ' : 'افزودن لندینگ جدید' ?></h3>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
    <div class="form-group">
      <label class="form-label" for="location_slug">مکان</label>
      <select id="location_slug" name="location_slug" class="form-control">
        <?php foreach ($locations as $loc): ?>
        <option value="<?= h((string) $loc['slug']) ?>" <?= $editing && (string) $editing['location_slug'] === (string) $loc['slug'] ? 'selected' : '' ?>>
          <?= h((string) $loc['name']) ?> (<?= h($locLabels[(string) $loc['level']] ?? (string) $loc['level']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label" for="token">اسلاگ لندینگ (توکن)</label>
      <input type="text" id="token" name="token" class="form-control" dir="ltr" value="<?= h($editing['token'] ?? '') ?>" placeholder="swap-real-estate">
      <p class="fs-xs" style="color:var(--text-muted);margin:6px 0 0">
        مکان: <code>swap</code> · دسته: <code>swap-real-estate</code> · مدل: <code>swap-car-pride</code> · X با Y: <code>swap-car-with-real-estate</code>
      </p>
    </div>

    <div class="form-group">
      <label class="form-label" for="title">تایتل (خالی = استفاده از قالب)</label>
      <input type="text" id="title" name="title" class="form-control" value="<?= h($editing['title'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="h1">H1 (خالی = استفاده از قالب)</label>
      <input type="text" id="h1" name="h1" class="form-control" value="<?= h($editing['h1'] ?? '') ?>">
    </div>

    <div class="form-group" style="grid-column: span 2">
      <label class="form-label" for="intro">مقدمه (خالی = متن پیش‌فرض)</label>
      <textarea id="intro" name="intro" class="form-control" rows="2"><?= h($editing['intro'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label" for="index_status">وضعیت ایندکس</label>
      <select id="index_status" name="index_status" class="form-control">
        <?php $isSel = (string) ($editing['index_status'] ?? 'auto'); ?>
        <option value="auto"    <?= $isSel === 'auto'    ? 'selected' : '' ?>>خودکار (حداقل ۶ آگهی)</option>
        <option value="index"   <?= $isSel === 'index'   ? 'selected' : '' ?>>ایندکس اجباری</option>
        <option value="noindex" <?= $isSel === 'noindex' ? 'selected' : '' ?>>noindex اجباری</option>
      </select>
    </div>

    <div class="form-group" style="display:flex;align-items:flex-end">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer">
        <input type="checkbox" name="is_active" value="1" <?= (!$editing || (int) $editing['is_active'] === 1) ? 'checked' : '' ?>>
        لندینگ فعال باشد
      </label>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;gap:var(--sp-3)">
    <?php if ($editing): ?>
    <a href="<?= APP_URL ?>/admin/swap_landings.php" class="btn btn-ghost">انصراف</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> <?= $editing ? 'ذخیره تغییرات' : 'افزودن لندینگ' ?>
    </button>
  </div>
</form>

<div class="card" style="margin-bottom:var(--sp-6)">
  <h3 style="margin-top:0">قالب تایتل و H1</h3>
  <p class="fs-xs" style="color:var(--text-muted);margin:0 0 var(--sp-4)">
    جایگزین‌شوها: <code>{location}</code> <code>{category}</code> <code>{brand}</code> <code>{model}</code> <code>{x}</code> <code>{y}</code>.
    برای سطح کشور (iran) بخش «در {location}» خودکار حذف می‌شود.
  </p>
  <form method="POST" style="display:grid;gap:var(--sp-4)">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_templates">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
      <?php
      $tplFields = [
          'title_location' => 'تایتل — لندینگ مکان',
          'title_category' => 'تایتل — لندینگ دسته',
          'title_model'    => 'تایتل — لندینگ مدل',
          'title_xy'       => 'تایتل — لندینگ X با Y',
          'h1_location'    => 'H1 — لندینگ مکان',
          'h1_category'    => 'H1 — لندینگ دسته',
          'h1_model'       => 'H1 — لندینگ مدل',
          'h1_xy'          => 'H1 — لندینگ X با Y',
      ];
      foreach ($tplFields as $key => $label): ?>
      <div class="form-group">
        <label class="form-label" for="<?= h($key) ?>"><?= h($label) ?></label>
        <input type="text" id="<?= h($key) ?>" name="<?= h($key) ?>" class="form-control" value="<?= h($templates[$key] ?? '') ?>">
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> ذخیره قالب‌ها</button>
    </div>
  </form>
</div>

<div class="card" style="margin-bottom:var(--sp-6)">
  <h3 style="margin-top:0">ورود گروهی لیست کیورد تأییدشده</h3>
  <p class="fs-xs" style="color:var(--text-muted);margin:0 0 var(--sp-4)">
    هر خط یک آدرس یا توکن. سطر سرصفحه اختیاری است (ستون‌های: آدرس، مکان، اسلاگ، تایتل، H1، مقدمه).
    ترکیب‌های نامعتبر رد می‌شوند؛ لندینگ هرگز از ترکیب خودکار فیلترها ساخته نمی‌شود.
  </p>
  <form method="POST" style="display:grid;gap:var(--sp-4)">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <textarea name="csv" class="form-control" rows="5" dir="ltr" placeholder="/tehran/list/swap-real-estate&#10;/iran/list/swap-car-pride&#10;/karaj/list/swap"></textarea>
    <div style="display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-outline"><i class="bi bi-upload"></i> ورود</button>
    </div>
  </form>
</div>

<div class="card">
<table class="admin-table">
<thead>
<tr>
<th>نوع</th>
<th>آدرس</th>
<th>مکان</th>
<th>تعریف فیلتر</th>
<th>تایتل</th>
<th>H1</th>
<th>مقدمه</th>
<th>وضعیت ایندکس</th>
<th>اولین ایندکس</th>
<th>آگهی</th>
<th></th>
</tr>
</thead>
<tbody>
<?php if (!$rows): ?>
<tr><td colspan="11" style="text-align:center;color:var(--text-muted);padding:var(--sp-5)">هنوز لندینگ معاوضه‌ای ثبت نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r):
    $url       = swaapin_swap_landing_url($r);
    $count     = swaapin_swap_landing_count($r);
    $indexable = swaapin_swap_landing_indexable($r);
    $firstIdx  = swaapin_swap_landing_first_indexed_at($r);
    $status    = (string) ($r['index_status'] ?? 'auto');
?>
<tr>
<td><span class="badge badge-primary"><?= h($typeLabels[(string) $r['type']] ?? (string) $r['type']) ?></span></td>
<td><a href="<?= h($url) ?>" target="_blank" rel="noopener" class="fs-xs" dir="ltr"><?= h((string) parse_url($url, PHP_URL_PATH)) ?></a></td>
<td class="fs-xs"><?= h((string) $r['location_slug']) ?></td>
<td class="fs-xs"><?= h($describeFilter($r)) ?></td>
<td class="fs-xs"><?= h((string) ($r['title'] ?? '')) ?: '<span style="color:var(--text-muted)">— قالب —</span>' ?></td>
<td class="fs-xs"><?= h((string) ($r['h1'] ?? '')) ?: '<span style="color:var(--text-muted)">— قالب —</span>' ?></td>
<td class="fs-xs"><?= h((string) ($r['intro'] ?? '')) ?: '<span style="color:var(--text-muted)">— پیش‌فرض —</span>' ?></td>
<td class="fs-xs">
  <?= h(['auto' => 'خودکار', 'index' => 'ایندکس', 'noindex' => 'noindex'][$status] ?? $status) ?>
  <?php if ((int) $r['is_active'] !== 1): ?><br><span style="color:var(--danger)">غیرفعال</span><?php endif; ?>
  <br><span style="color:<?= $indexable ? 'var(--success)' : 'var(--text-muted)' ?>"><?= $indexable ? 'ایندکس‌پذیر' : 'noindex' ?></span>
</td>
<td class="fs-xs"><?= $firstIdx !== null && $firstIdx !== '' ? h($firstIdx) : '<span style="color:var(--text-muted)">—</span>' ?></td>
<td class="fs-xs"><?= h(fmt_num($count)) ?></td>
<td>
  <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/admin/swap_landings.php?edit=<?= (int) $r['id'] ?>">ویرایش</a>
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <button type="submit" class="btn btn-sm <?= (int) $r['is_active'] === 1 ? 'btn-primary' : 'btn-outline' ?>">
      <?= (int) $r['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
    </button>
  </form>
  <form method="POST" style="display:inline" onsubmit="return confirm('این لندینگ حذف شود؟');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <button type="submit" class="btn btn-sm btn-danger">حذف</button>
  </form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
$content = ob_get_clean();

render_admin_head('لندینگ‌های معاوضه');
render_admin_shell($admin, 'swap_landings', $content);
render_admin_footer();
