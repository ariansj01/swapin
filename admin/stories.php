<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/stories.php';

$admin = require_admin();
[$flash, $flashType] = admin_flash();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = stories_get($id);
        if ($row) {
            DB::query('DELETE FROM stories WHERE id = ?', [$id]);
            if (!empty($row['image'])) {
                $file = UPLOAD_DIR . '/' . $row['image'];
                if (is_file($file)) { @unlink($file); }
            }
            admin_set_flash('استوری حذف شد.');
        }
        header('Location: ' . APP_URL . '/admin/stories.php');
        exit;
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = stories_get($id);
        if ($row) {
            DB::update('stories', ['is_active' => ((int)$row['is_active'] === 1 ? 0 : 1)], 'id = ?', [$id]);
            admin_set_flash('وضعیت نمایش استوری تغییر کرد.');
        }
        header('Location: ' . APP_URL . '/admin/stories.php');
        exit;
    }

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $title     = trim((string)($_POST['title'] ?? ''));
        $caption   = trim((string)($_POST['caption'] ?? ''));
        $linkUrl   = stories_safe_link((string)($_POST['link_url'] ?? ''));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        if ($title !== '' && mb_strlen($title) > 120) {
            $title = mb_strimwidth($title, 0, 120, '');
        }
        if ($caption !== '' && mb_strlen($caption) > 255) {
            $caption = mb_strimwidth($caption, 0, 255, '');
        }

        $existing = stories_get($id);

        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $image = upload_image($_FILES['image'], 'story');
            if (!$image) {
                $errors[] = 'آپلود تصویر ناموفق بود. فرمت (JPG/PNG/WEBP) و حجم تصویر را بررسی کنید.';
            }
        }
        if (!$existing && !$image) {
            $errors[] = 'برای استوری جدید، انتخاب تصویر الزامی است.';
        }
        $rawLink = trim((string)($_POST['link_url'] ?? ''));
        if ($rawLink !== '' && $linkUrl === '') {
            $errors[] = 'لینک باید با http:// یا https:// شروع شود.';
        }

        if (!$errors) {
            $data = [
                'title'      => $title,
                'caption'    => $caption,
                'link_url'   => $linkUrl,
                'sort_order' => $sortOrder,
                'is_active'  => $isActive,
            ];
            if ($image) {
                $data['image'] = $image;
            }

            if ($existing) {
                DB::update('stories', $data, 'id = ?', [$id]);
                if ($image && !empty($existing['image']) && $existing['image'] !== $image) {
                    $old = UPLOAD_DIR . '/' . $existing['image'];
                    if (is_file($old)) { @unlink($old); }
                }
                admin_set_flash('استوری به‌روزرسانی شد.');
            } else {
                DB::insert('stories', $data);
                admin_set_flash('استوری جدید اضافه شد.');
            }
            header('Location: ' . APP_URL . '/admin/stories.php');
            exit;
        }
    }
}

$editing = null;
$editId  = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = stories_get($editId);
}

$stories = stories_all();

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>
<?php if ($errors): ?>
<div class="alert alert-danger mb-5"><i class="bi bi-exclamation-triangle"></i> <?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="admin-header">
  <div>
    <h1>استوری‌ها</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">استوری‌های نمایش‌داده‌شده در صفحه اصلی (زیر هدر) را مدیریت کنید.</p>
  </div>
</div>

<form method="POST" enctype="multipart/form-data" class="card" style="padding:var(--sp-6);display:grid;gap:var(--sp-5);margin-bottom:var(--sp-6)">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

  <h3 style="margin:0"><?= $editing ? 'ویرایش استوری' : 'افزودن استوری جدید' ?></h3>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
    <div class="form-group">
      <label class="form-label" for="title">عنوان (اختیاری)</label>
      <input type="text" id="title" name="title" class="form-control" maxlength="120" value="<?= h($editing['title'] ?? '') ?>" placeholder="مثلاً پیشنهاد ویژه">
    </div>

    <div class="form-group">
      <label class="form-label" for="image">تصویر <?= $editing ? '(برای تغییر، فایل جدید انتخاب کنید)' : '' ?></label>
      <input type="file" id="image" name="image" class="form-control" accept="image/png,image/jpeg,image/webp">
      <?php if ($editing && !empty($editing['image'])): ?>
      <img src="<?= UPLOAD_URL . h($editing['image']) ?>" alt="" style="margin-top:8px;width:88px;height:88px;object-fit:cover;border-radius:12px;border:1px solid var(--border)">
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="caption">متن استوری (اختیاری)</label>
      <textarea id="caption" name="caption" rows="2" class="form-control" maxlength="255"><?= h($editing['caption'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label" for="link_url">لینک (اختیاری)</label>
      <input type="text" id="link_url" name="link_url" class="form-control" value="<?= h($editing['link_url'] ?? '') ?>" placeholder="https://swaapin.ir/listings">
    </div>

    <div class="form-group">
      <label class="form-label" for="sort_order">ترتیب نمایش</label>
      <input type="number" id="sort_order" name="sort_order" class="form-control" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
    </div>

    <div class="form-group" style="display:flex;align-items:flex-end">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer">
        <input type="checkbox" name="is_active" value="1" <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
        نمایش در سایت
      </label>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;gap:var(--sp-3)">
    <?php if ($editing): ?>
    <a href="<?= APP_URL ?>/admin/stories.php" class="btn btn-ghost">انصراف</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> <?= $editing ? 'ذخیره تغییرات' : 'افزودن استوری' ?>
    </button>
  </div>
</form>

<div class="card">
<table class="admin-table">
<thead>
<tr>
<th>تصویر</th>
<th>عنوان</th>
<th>متن</th>
<th>ترتیب</th>
<th>وضعیت</th>
<th></th>
</tr>
</thead>
<tbody>
<?php if (!$stories): ?>
<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:var(--sp-5)">هنوز استوری‌ای ثبت نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($stories as $s): ?>
<tr>
<td><img src="<?= UPLOAD_URL . h($s['image']) ?>" alt="" style="width:52px;height:52px;object-fit:cover;border-radius:50%;border:1px solid var(--border)"></td>
<td><?= h($s['title'] !== '' ? $s['title'] : '—') ?></td>
<td style="max-width:260px"><?= h(mb_strimwidth((string)$s['caption'], 0, 60, '…')) ?></td>
<td><?= (int)$s['sort_order'] ?></td>
<td>
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
    <button type="submit" class="btn btn-sm <?= (int)$s['is_active'] === 1 ? 'btn-primary' : 'btn-outline' ?>">
      <?= (int)$s['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
    </button>
  </form>
</td>
<td>
  <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/admin/stories.php?edit=<?= (int)$s['id'] ?>">ویرایش</a>
  <form method="POST" style="display:inline" onsubmit="return confirm('این استوری حذف شود؟');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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

render_admin_head('استوری‌ها');
render_admin_shell($admin, 'stories', $content);
render_admin_footer();
