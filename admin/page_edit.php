<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    header('Location: '.APP_URL.'/admin/pages.php');
    exit;
}


$page = DB::fetch(
    'SELECT * FROM content_pages WHERE id = ?',
    [$id]
);


if (!$page) {
    http_response_code(404);
    exit('Page not found');
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify_or_fail();


    DB::update(
        'content_pages',
        [
            'title'             => clean($_POST['title'] ?? ''),
            'slug'              => clean($_POST['slug'] ?? ''),
            'content'           => clean($_POST['content'] ?? ''),
            'meta_title'        => clean($_POST['meta_title'] ?? ''),
            'meta_description'  => clean($_POST['meta_description'] ?? ''),
            'canonical_url'     => clean($_POST['canonical_url'] ?? ''),
            'status'            => clean($_POST['status'] ?? 'draft'),
            'index_status'      => clean($_POST['index_status'] ?? 'index'),
        ],
        'id = ?',
        [$id]
    );


    header('Location: '.APP_URL.'/admin/pages.php');
    exit;

}



ob_start();

?>


<div class="admin-header">
<h1>ویرایش صفحه</h1>
</div>


<form method="POST" class="card" style="padding:30px">

<?= csrf_field() ?>


<div class="form-group">
<label>عنوان صفحه</label>

<input 
class="form-control"
name="title"
value="<?=h($page['title'])?>">
</div>



<div class="form-group">
<label>Slug</label>

<input 
class="form-control"
name="slug"
value="<?=h($page['slug'])?>">
</div>




<div class="form-group">

<label>محتوا</label>

<textarea
class="form-control"
name="content"
rows="12"><?=h($page['content'])?></textarea>

</div>




<div class="form-group">

<label>Meta Title</label>

<input
class="form-control"
name="meta_title"
value="<?=h($page['meta_title'])?>">

</div>




<div class="form-group">

<label>Meta Description</label>

<textarea
class="form-control"
name="meta_description"
rows="4"><?=h($page['meta_description'])?></textarea>

</div>




<div class="form-group">

<label>Canonical URL</label>

<input
class="form-control"
name="canonical_url"
value="<?=h($page['canonical_url'])?>">

</div>




<div class="form-group">

<label>وضعیت انتشار</label>

<select name="status">

<option value="published"
<?= $page['status']=='published'?'selected':'' ?>>
منتشر شده
</option>


<option value="draft"
<?= $page['status']=='draft'?'selected':'' ?>>
پیش نویس
</option>


</select>

</div>



<div class="form-group">

<label>ایندکس گوگل</label>

<select name="index_status">

<option value="index"
<?= $page['index_status']=='index'?'selected':'' ?>>
Index
</option>


<option value="noindex"
<?= $page['index_status']=='noindex'?'selected':'' ?>>
NoIndex
</option>


</select>

</div>



<button class="btn btn-primary">
ذخیره تغییرات
</button>




<hr>

<h3>FAQ</h3>

<div id="faq-box">

<div class="faq-item">

<input class="form-control"
name="faq[0][question]"
placeholder="سوال FAQ">

<br>

<textarea class="form-control"
name="faq[0][answer]"
placeholder="پاسخ FAQ"></textarea>

</div>

</div>

<button type="button"
class="btn btn-secondary"
onclick="addFaq()">
+ افزودن سوال
</button>


<script>

let faqIndex = 1;

function addFaq(){

document.getElementById('faq-box').insertAdjacentHTML(
'beforeend',
`
<div class="faq-item">

<input class="form-control"
name="faq[${faqIndex}][question]"
placeholder="سوال FAQ">

<br>

<textarea class="form-control"
name="faq[${faqIndex}][answer]"
placeholder="پاسخ FAQ"></textarea>

</div>
`
);

faqIndex++;

}

</script>

</form>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/ckeditor/ckeditor5.css">

<script src="<?= APP_URL ?>/assets/vendor/ckeditor/ckeditor5.umd.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function(){

    const textarea = document.querySelector("textarea[name='content']");

    if(!textarea) return;

    
CKEDITOR.ClassicEditor
.create(textarea,{
    toolbar:{
        items:[
            'undo',
            'redo',
            '|',
            'heading',
            '|',
            'bold',
            'italic',
            'link',
            'insertTable',
            'bulletedList',
            'numberedList'
        ]
    }
})
.catch(error=>{
    console.error(error);
});


});
</script>




<?php

$content = ob_get_clean();


render_admin_head('ویرایش صفحه');

render_admin_shell(
    $admin,
    'pages',
    $content
);


render_admin_footer();

