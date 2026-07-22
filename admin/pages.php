<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

$pages = DB::fetchAll(
    'SELECT id,title,slug,status,index_status,created_at 
     FROM content_pages 
     ORDER BY id DESC'
);

ob_start();
?>

<div class="admin-header">
    <h1>مدیریت صفحات سایت</h1>
    <a class="btn btn-primary" href="<?= APP_URL ?>/admin/page_create.php">
        ایجاد صفحه جدید
    </a>
</div>

<div class="card">
<table class="admin-table">
<thead>
<tr>
<th>عنوان</th>
<th>Slug</th>
<th>وضعیت</th>
<th>SEO</th>
<th></th>
</tr>
</thead>

<tbody>

<?php foreach($pages as $p): ?>

<tr>
<td><?= h($p['title']) ?></td>
<td><?= h($p['slug']) ?></td>
<td><?= h($p['status']) ?></td>
<td><?= h($p['index_status']) ?></td>

<td>
<a class="btn btn-sm btn-outline"
href="<?= APP_URL ?>/admin/page_edit.php?id=<?= $p['id'] ?>">
ویرایش
</a>
</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>

<?php

$content = ob_get_clean();

render_admin_head('صفحات');
render_admin_shell($admin,'pages',$content);
render_admin_footer();
