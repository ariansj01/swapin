<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/content_manager.php';

$admin = require_admin();
[$flash, $flashType] = admin_flash();

$fields = [
    'home_meta_title' => ['label' => 'عنوان متای صفحه اصلی', 'rows' => 2],
    'home_meta_desc' => ['label' => 'توضیح متای صفحه اصلی', 'rows' => 3],
    // 'hero_title_line_1' => ['label' => 'خط اول هدر صفحه اصلی', 'rows' => 2],
    'hero_title_line_2' => ['label' => 'خط دوم هدر صفحه اصلی', 'rows' => 2],
    'hero_subtitle_before' => ['label' => 'متن قبل از کلمه برجسته', 'rows' => 2],
    'hero_subtitle_highlight' => ['label' => 'کلمه برجسته هدر', 'rows' => 2],
    'hero_primary_cta' => ['label' => 'متن دکمه اصلی هدر', 'rows' => 2],
    'hero_secondary_cta' => ['label' => 'متن دکمه دوم هدر', 'rows' => 2],
    'home_ai_badge' => ['label' => 'برچسب بخش AI', 'rows' => 2],
    'home_ai_title' => ['label' => 'عنوان بخش AI', 'rows' => 2],
    'home_ai_desc' => ['label' => 'توضیح بخش AI', 'rows' => 4],
    'home_ai_primary_cta' => ['label' => 'دکمه اصلی بخش AI', 'rows' => 2],
    'home_ai_secondary_cta' => ['label' => 'دکمه دوم بخش AI', 'rows' => 2],
    'footer_brand_tagline' => ['label' => 'شعار فوتر', 'rows' => 3],
    'footer_copy' => ['label' => 'متن کپی‌رایت فوتر', 'rows' => 2],
];

$values = swapin_content_all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    $submitted = [];
    foreach ($fields as $key => $meta) {
        $submitted[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if (swapin_content_save($submitted)) {
        admin_set_flash('متن‌ها و عنوان‌های قابل مدیریت با موفقیت ذخیره شدند.');
        header('Location: ' . APP_URL . '/admin/content.php');
        exit;
    }

    $values = array_merge($values, $submitted);
    $flash = 'ذخیره‌سازی انجام نشد. دسترسی نوشتن روی پوشه storage را بررسی کنید.';
    $flashType = 'error';
}

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1>مدیریت محتوا</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">در این بخش متن‌ها و عنوان‌های اصلی سایت را بدون ویرایش مستقیم کد تغییر می‌دهید.</p>
  </div>
</div>

<div class="alert alert-warning mb-5">
  <i class="bi bi-info-circle"></i>
  فعلاً این بخش روی متن‌های اصلی صفحه خانه و فوتر اعمال می‌شود و زیرساختش برای توسعه بخش‌های بعدی آماده است.
</div>

<form method="POST" class="card" style="padding:var(--sp-6);display:grid;gap:var(--sp-6)">
  <?= csrf_field() ?>

  <section>
    <h3 style="margin:0 0 var(--sp-4)">تنظیمات صفحه اصلی</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
      <?php foreach ([
          'home_meta_title',
          'home_meta_desc',
          'hero_title_line_1',
          'hero_title_line_2',
          'hero_subtitle_before',
          'hero_subtitle_highlight',
          'hero_primary_cta',
          'hero_secondary_cta',
          'home_ai_badge',
          'home_ai_title',
          'home_ai_desc',
          'home_ai_primary_cta',
          'home_ai_secondary_cta',
      ] as $key): ?>
      <div class="form-group">
        <label class="form-label" for="<?= h($key) ?>"><?= h($fields[$key]['label']) ?></label>
        <textarea id="<?= h($key) ?>" name="<?= h($key) ?>" rows="<?= (int) $fields[$key]['rows'] ?>" class="form-control"><?= h($values[$key] ?? '') ?></textarea>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h3 style="margin:0 0 var(--sp-4)">تنظیمات فوتر</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
      <?php foreach (['footer_brand_tagline', 'footer_copy'] as $key): ?>
      <div class="form-group">
        <label class="form-label" for="<?= h($key) ?>"><?= h($fields[$key]['label']) ?></label>
        <textarea id="<?= h($key) ?>" name="<?= h($key) ?>" rows="<?= (int) $fields[$key]['rows'] ?>" class="form-control"><?= h($values[$key] ?? '') ?></textarea>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div style="display:flex;justify-content:flex-end;gap:var(--sp-3)">
    <a href="<?= APP_URL ?>/" class="btn btn-ghost" target="_blank" rel="noreferrer">مشاهده سایت</a>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> ذخیره تغییرات
    </button>
  </div>
</form>
<?php
$content = ob_get_clean();

render_admin_head('مدیریت محتوا');
render_admin_shell($admin, 'content', $content);
render_admin_footer();
