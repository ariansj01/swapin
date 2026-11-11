<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();

$directApkUrl = APP_URL . '/downloads/swaapin.apk';
$bazaarUrl    = 'https://cafebazaar.ir/app/ir.swaapin.mobile';
$pwaUrl       = APP_URL . '/pwa';

render_head(
    'دانلود اپلیکیشن سواَپین | ' . APP_NAME,
    'دانلود اپلیکیشن اندروید سواَپین — دانلود مستقیم APK، دانلود از کافه‌بازار و نصب نسخه وب (PWA).',
    ['canonical' => APP_URL . '/app']
);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container" style="max-width:920px">

    <div style="text-align:center;padding:var(--sp-6) 0 var(--sp-5)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:20px;background:linear-gradient(135deg,var(--primary),var(--accent));margin-bottom:var(--sp-3)">
        <i class="bi bi-phone" style="font-size:2rem;color:#fff"></i>
      </div>
      <h1 style="font-size:1.75rem;margin:0 0 var(--sp-2)">دانلود اپلیکیشن سواَپین</h1>
      <p style="color:var(--text-muted);line-height:1.8;margin:0">
        اپلیکیشن سواَپین را از هر روشی که راحت‌ترید نصب کنید؛ تجربه‌ای سریع‌تر و روان‌تر برای معاوضه کالا.
      </p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--sp-5)">

      <div class="card" style="text-align:center">
        <div class="card-body" style="padding:var(--sp-6)">
          <div style="font-size:2.25rem;color:var(--primary);margin-bottom:var(--sp-3)"><i class="bi bi-download"></i></div>
          <h2 style="font-size:1.1rem;margin:0 0 var(--sp-2)">دانلود مستقیم (APK)</h2>
          <p style="color:var(--text-muted);font-size:.9rem;line-height:1.9;min-height:56px">
            دریافت فایل نصبی اندروید به‌صورت مستقیم از سرور سواَپین، بدون نیاز به فروشگاه.
          </p>
          <a href="<?= h($directApkUrl) ?>" class="btn btn-primary w-100" download>
            <i class="bi bi-download"></i> دانلود مستقیم
          </a>
        </div>
      </div>

      <div class="card" style="text-align:center">
        <div class="card-body" style="padding:var(--sp-6)">
          <div style="font-size:2.25rem;color:var(--accent);margin-bottom:var(--sp-3)"><i class="bi bi-google-play"></i></div>
          <h2 style="font-size:1.1rem;margin:0 0 var(--sp-2)">دانلود از کافه‌بازار</h2>
          <p style="color:var(--text-muted);font-size:.9rem;line-height:1.9;min-height:56px">
            نصب و بروزرسانی خودکار از طریق کافه‌بازار، مطمئن‌ترین راه نصب روی اندروید.
          </p>
          <a href="<?= h($bazaarUrl) ?>" class="btn btn-outline w-100" target="_blank" rel="noopener">
            <i class="bi bi-bag"></i> کافه‌بازار
          </a>
        </div>
      </div>

      <div class="card" style="text-align:center">
        <div class="card-body" style="padding:var(--sp-6)">
          <div style="font-size:2.25rem;color:var(--primary);margin-bottom:var(--sp-3)"><i class="bi bi-globe2"></i></div>
          <h2 style="font-size:1.1rem;margin:0 0 var(--sp-2)">نسخه وب (PWA)</h2>
          <p style="color:var(--text-muted);font-size:.9rem;line-height:1.9;min-height:56px">
            بدون دانلود فایل، سواَپین را مثل یک اپلیکیشن روی صفحه‌خانه نصب کنید.
          </p>
          <a href="<?= h($pwaUrl) ?>" class="btn btn-outline w-100">
            <i class="bi bi-plus-square"></i> نصب نسخه وب
          </a>
        </div>
      </div>

    </div>

  </div>
</main>

<?php render_footer(); ?>
