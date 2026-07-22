<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin=require_admin();

if($_SERVER['REQUEST_METHOD']==='POST'){

csrf_verify_or_fail();

DB::insert('content_pages',[
'title'=>clean($_POST['title']),
'slug'=>clean($_POST['slug']),
'content'=>clean($_POST['content']),
'meta_title'=>clean($_POST['meta_title']),
'meta_description'=>clean($_POST['meta_description']),
'canonical_url'=>clean($_POST['canonical_url']),
'status'=>$_POST['status'],
'index_status'=>$_POST['index_status'],
'faq_json'=>json_encode($_POST['faq'] ?? [], JSON_UNESCAPED_UNICODE),
'created_by'=>$admin['id']
]);

header('Location: '.APP_URL.'/admin/pages.php');
exit;

}

ob_start();
?>

<h1>ایجاد صفحه جدید</h1>

<form method="POST">

<?= csrf_field() ?>

<input class="form-control" name="title" placeholder="عنوان صفحه">

<br>

<input class="form-control" name="slug" placeholder="about-us">

<br>

<textarea class="form-control" name="content" rows="10"
placeholder="متن صفحه"></textarea>

<br>

<input class="form-control" name="meta_title" placeholder="Meta Title">

<br>

<textarea class="form-control" name="meta_description"
placeholder="Meta Description"></textarea>

<br>

<input class="form-control" name="canonical_url"
placeholder="Canonical URL">

<br>

<select name="status">
<option value="published">Published</option>
<option value="draft">Draft</option>
</select>


<select name="index_status">
<option value="index">Index</option>
<option value="noindex">Noindex</option>
</select>


<br><br>

<button class="btn btn-primary">
ذخیره صفحه
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

$content=ob_get_clean();

render_admin_head('ایجاد صفحه');
render_admin_shell($admin,'pages',$content);
render_admin_footer();

