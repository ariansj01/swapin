<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    $status = in_array($_POST['status'] ?? 'draft', ['draft', 'published'], true) ? $_POST['status'] : 'draft';
    $indexStatus = in_array($_POST['index_status'] ?? 'index', ['index', 'noindex'], true) ? $_POST['index_status'] : 'index';

    $faqs = array_values(array_filter($_POST['faq'] ?? [], function ($faq) {
        return !empty(trim($faq['question'] ?? '')) && !empty(trim($faq['answer'] ?? ''));
    }));

    DB::insert('content_pages', [
        'title' => clean($_POST['title'] ?? ''),
        'slug' => clean($_POST['slug'] ?? ''),
        'content' => clean_page_html($_POST['content'] ?? ''),
        'featured_image' => clean($_POST['featured_image'] ?? ''),
        'meta_title' => clean($_POST['meta_title'] ?? ''),
        'meta_description' => clean($_POST['meta_description'] ?? ''),
        'canonical_url' => seo_sanitize_stored_canonical(clean($_POST['canonical_url'] ?? '')),
        'faq_json' => json_encode($faqs, JSON_UNESCAPED_UNICODE),
        'internal_links' => clean($_POST['internal_links'] ?? ''),
        'status' => $status,
        'show_in_nav' => isset($_POST['show_in_nav']) ? 1 : 0,
        'show_in_footer' => isset($_POST['show_in_footer']) ? 1 : 0,
        'index_status' => $indexStatus,
        'created_by' => $admin['id'],
    ]);

    header('Location: ' . APP_URL . '/admin/pages.php');
    exit;
}

ob_start();
?>

<h1>ایجاد صفحه جدید</h1>

<form method="POST" class="card" style="padding:30px">
<?= csrf_field() ?>

<div class="form-group">
<label>عنوان صفحه</label>
<input class="form-control" name="title" required>
</div>

<div class="form-group">
<label>Slug</label>
<input class="form-control" name="slug" placeholder="about-us" required>
</div>

<div class="form-group">
<label>محتوا</label>
<textarea class="form-control" name="content" rows="12"></textarea>
</div>

<div class="form-group">
<label>تصویر شاخص / آدرس تصویر</label>
<input class="form-control" name="featured_image" placeholder="https://swaapin.ir/uploads/example.jpg">
</div>

<div class="form-group">
<label>Meta Title</label>
<input class="form-control" name="meta_title">
</div>

<div class="form-group">
<label>Meta Description</label>
<textarea class="form-control" name="meta_description" rows="4"></textarea>
</div>

<div class="form-group">
<label>Canonical URL</label>
<input class="form-control" name="canonical_url" placeholder="https://swaapin.ir/page/about-us — خالی = آدرس پیش‌فرض">
</div>

<div class="form-group">
<label>لینک‌های داخلی</label>
<textarea class="form-control" name="internal_links" rows="4" placeholder="هر خط: عنوان | لینک"></textarea>
</div>

<div class="form-group">
<label>وضعیت انتشار</label>
<select name="status">
<option value="published">منتشر شده</option>
<option value="draft">پیش‌نویس</option>
</select>
</div>

<div class="form-group">
<label>ایندکس گوگل</label>
<select name="index_status">
<option value="index">Index</option>
<option value="noindex">NoIndex</option>
</select>
</div>

<label><input type="checkbox" name="show_in_nav" value="1"> نمایش در Navbar</label>
<br>
<label><input type="checkbox" name="show_in_footer" value="1"> نمایش در Footer</label>

<hr>

<h3>FAQ</h3>
<div id="faq-box">
<div class="faq-item">
<input class="form-control" name="faq[0][question]" placeholder="سوال FAQ">
<br>
<textarea class="form-control" name="faq[0][answer]" placeholder="پاسخ FAQ"></textarea>
</div>
</div>

<button type="button" class="btn btn-secondary" onclick="addFaq()">+ افزودن سوال</button>
<br><br>

<button class="btn btn-primary">ذخیره صفحه</button>
</form>

<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/ckeditor/ckeditor5.css">
<script src="<?= APP_URL ?>/assets/vendor/ckeditor/ckeditor5.umd.js"></script>

<script>
let faqIndex = 1;
function addFaq(){
    document.getElementById('faq-box').insertAdjacentHTML('beforeend', `
        <div class="faq-item">
            <br>
            <input class="form-control" name="faq[${faqIndex}][question]" placeholder="سوال FAQ">
            <br>
            <textarea class="form-control" name="faq[${faqIndex}][answer]" placeholder="پاسخ FAQ"></textarea>
        </div>
    `);
    faqIndex++;
}

document.addEventListener("DOMContentLoaded", function(){
    const textarea = document.querySelector("textarea[name='content']");
    if (!textarea || !window.CKEDITOR) return;

    CKEDITOR.ClassicEditor.create(textarea, {
        toolbar: {
            items: ['undo','redo','|','heading','|','bold','italic','link','insertTable','bulletedList','numberedList']
        }
    }).catch(error => console.error(error));
});
</script>

<?php
$content = ob_get_clean();

render_admin_head('ایجاد صفحه');
render_admin_shell($admin, 'pages', $content);
render_admin_footer();
