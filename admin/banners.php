<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/banners.php';

$admin = require_admin();
[$flash, $flashType] = admin_flash();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (banners_delete($id)) {
            admin_set_flash('بنر حذف شد.');
        }
        header('Location: ' . APP_URL . '/admin/banners.php');
        exit;
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = banners_get($id);
        if ($row) {
            DB::update('banners', ['is_active' => ((int)$row['is_active'] === 1 ? 0 : 1)], 'id = ?', [$id]);
            admin_set_flash('وضعیت نمایش بنر تغییر کرد.');
        }
        header('Location: ' . APP_URL . '/admin/banners.php');
        exit;
    }

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $linkUrl   = banners_safe_link((string)($_POST['link_url'] ?? ''));
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        $existing = banners_get($id);

        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $image = upload_image($_FILES['image'], 'banner');
            if (!$image) {
                $errors[] = 'آپلود تصویر ناموفق بود. فرمت (JPG/PNG/WEBP) و حجم تصویر را بررسی کنید.';
            }
        }
        if (!$existing && !$image) {
            $errors[] = 'برای بنر جدید، انتخاب تصویر الزامی است.';
        }
        $rawLink = trim((string)($_POST['link_url'] ?? ''));
        if ($rawLink !== '' && $linkUrl === '') {
            $errors[] = 'لینک باید با http:// یا https:// یا / شروع شود.';
        }

        if (!$errors) {
            $data = [
                'link_url'  => $linkUrl,
                'is_active' => $isActive,
            ];
            if ($image) {
                $data['image'] = $image;
            }

            banners_save($id, $data);
            
            if ($id > 0 && $image && !empty($existing['image']) && $existing['image'] !== $image) {
                $old = UPLOAD_DIR . '/' . $existing['image'];
                if (is_file($old)) { @unlink($old); }
            }
            
            admin_set_flash($id > 0 ? 'بنر به‌روزرسانی شد.' : 'بنر جدید اضافه شد.');
            header('Location: ' . APP_URL . '/admin/banners.php');
            exit;
        }
    }
}

$editing = null;
$editId  = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = banners_get($editId);
}

$banners = banners_all();

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>
<?php if ($errors): ?>
<div class="alert alert-danger mb-5"><i class="bi bi-exclamation-triangle"></i> <?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="admin-header">
  <div>
    <h1>اسلایدر صفحه اصلی</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">بنرهای اسلایدر نمایش‌داده‌شده در صفحه اصلی را مدیریت کنید.</p>
  </div>
</div>

<form method="POST" enctype="multipart/form-data" class="card" style="padding:var(--sp-6);display:grid;gap:var(--sp-5);margin-bottom:var(--sp-6)">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

  <h3 style="margin:0"><?= $editing ? 'ویرایش بنر' : 'افزودن بنر جدید' ?></h3>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
    <div class="form-group">
      <label class="form-label" for="image">تصویر بنر <?= $editing ? '(برای تغییر، فایل جدید انتخاب کنید)' : '' ?></label>
      <input type="file" id="image" name="image" class="form-control" accept="image/png,image/jpeg,image/webp" <?= $editing ? '' : 'required' ?>>
      <p class="fs-xs" style="color:var(--text-muted);margin:6px 0 0">نسبت پیشنهادی تصویر: ۱۶:۵ — مثلاً ۱۶۰۰×۵۰۰ پیکسل</p>
      <?php if ($editing && !empty($editing['image'])): ?>
      <img src="<?= UPLOAD_URL . h($editing['image']) ?>" alt="" style="margin-top:8px;max-width:240px;height:auto;border-radius:12px;border:1px solid var(--border)">
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="link_url">لینک کلیک (URL)</label>
      <input type="text" id="link_url" name="link_url" class="form-control" value="<?= h($editing['link_url'] ?? '') ?>" placeholder="https://swaapin.ir/valuation">
    </div>

    <div class="form-group" style="grid-column: span 2">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer">
        <input type="checkbox" name="is_active" value="1" <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
        نمایش در سایت
      </label>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;gap:var(--sp-3)">
    <?php if ($editing): ?>
    <a href="<?= APP_URL ?>/admin/banners.php" class="btn btn-ghost">انصراف</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> <?= $editing ? 'ذخیره تغییرات' : 'افزودن بنر' ?>
    </button>
  </div>
</form>

<div class="card">
<table class="admin-table">
<thead>
<tr>
<th>تصویر</th>
<th>لینک</th>
<th>وضعیت</th>
<th></th>
</tr>
</thead>
<tbody>
<?php if (!$banners): ?>
<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:var(--sp-5)">هنوز بنری ثبت نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($banners as $b): ?>
<tr>
<td><img src="<?= UPLOAD_URL . h($b['image']) ?>" alt="" style="width:120px;height:60px;object-fit:cover;border-radius:8px;border:1px solid var(--border)"></td>
<td>
    <?php if (!empty($b['link_url'])): ?>
    <a href="<?= h($b['link_url']) ?>" target="_blank" rel="noopener" class="fs-xs"><?= h($b['link_url']) ?></a>
    <?php else: ?>
    <span class="fs-xs" style="color:var(--text-muted)">— لینک ندارد —</span>
    <?php endif; ?>
</td>
<td>
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
    <button type="submit" class="btn btn-sm <?= (int)$b['is_active'] === 1 ? 'btn-primary' : 'btn-outline' ?>">
      <?= (int)$b['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
    </button>
  </form>
</td>
<td>
  <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/admin/banners.php?edit=<?= (int)$b['id'] ?>">ویرایش</a>
  <form method="POST" style="display:inline" onsubmit="return confirm('این بنر حذف شود؟');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
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

render_admin_head('اسلایدر صفحه اصلی');
render_admin_shell($admin, 'banners', $content);
render_admin_footer();
