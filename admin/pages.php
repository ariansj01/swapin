<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

$pages = DB::fetchAll(
    'SELECT id,title,slug,status,index_status,show_in_nav,show_in_footer,created_at
     FROM content_pages
     ORDER BY id DESC'
);

ob_start();
?>

<div class="admin-header">
    <h1>مدیریت صفحات سایت</h1>
    <a class="btn btn-primary" href="<?= APP_URL ?>/admin/page_create.php">ایجاد صفحه جدید</a>
</div>

<div class="card">
<table class="admin-table">
<thead>
<tr>
<th>عنوان</th>
<th>Slug</th>
<th>وضعیت</th>
<th>SEO</th>
<th>Navigation</th>
<th>Footer</th>
<th></th>
</tr>
</thead>
<tbody>

<?php foreach ($pages as $p): ?>
<tr>
<td><?= h($p['title']) ?></td>
<td>
    <a href="<?= APP_URL ?>/page/<?= h($p['slug']) ?>" target="_blank">
        <?= h($p['slug']) ?>
    </a>
</td>
<td><?= h($p['status']) ?></td>
<td><?= h($p['index_status']) ?></td>

<td>
    <form method="POST" action="<?= APP_URL ?>/admin/page_visibility.php" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="field" value="show_in_nav">
        <button type="submit" class="btn btn-sm <?= (int)$p['show_in_nav'] === 1 ? 'btn-primary' : 'btn-outline' ?>">
            <?= (int)$p['show_in_nav'] === 1 ? 'فعال' : 'غیرفعال' ?>
        </button>
    </form>
</td>

<td>
    <form method="POST" action="<?= APP_URL ?>/admin/page_visibility.php" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="field" value="show_in_footer">
        <button type="submit" class="btn btn-sm <?= (int)$p['show_in_footer'] === 1 ? 'btn-primary' : 'btn-outline' ?>">
            <?= (int)$p['show_in_footer'] === 1 ? 'فعال' : 'غیرفعال' ?>
        </button>
    </form>
</td>

<td>
    <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/admin/page_edit.php?id=<?= (int)$p['id'] ?>">ویرایش</a>

    <form method="POST" action="<?= APP_URL ?>/admin/page_delete.php" style="display:inline" onsubmit="return confirm('این صفحه حذف شود؟');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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

render_admin_head('صفحات');
render_admin_shell($admin, 'pages', $content);
render_admin_footer();