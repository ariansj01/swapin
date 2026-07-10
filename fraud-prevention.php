<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();

render_head('راهنمای امنیت سواَپین | پیشگیری از کلاهبرداری', 'نکات امنیتی معاملات در سواَپین - کلاهبرداری را بشناسید و معاملات امن انجام دهید.', [
    'canonical' => APP_URL . '/fraud-prevention',
]);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container-sm">

    <div style="text-align:center;padding:var(--sp-6) 0 var(--sp-5)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#d97706);margin-bottom:var(--sp-4)">
        <i class="bi bi-shield-exclamation" style="font-size:1.75rem;color:#fff"></i>
      </div>
      <h1 style="font-size:1.875rem;margin:0 0 var(--sp-3)">پیشگیری از کلاهبرداری</h1>
      <p style="color:var(--text-muted);max-width:540px;margin:0 auto">معامله امن نیاز به هوشیاری دارد. این راهنما به شما کمک می‌کند کلاهبرداران را بشناسید و از خودتان محافظت کنید.</p>
    </div>

    <!-- Warning banner -->
    <div class="alert alert-warning mb-6" style="border-inline-start:4px solid var(--warning)">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div><strong>هشدار:</strong> هرگز پول یا کالا را <em>خارج از پلتفرم</em> و بدون سپرده امانی (Escrow) مبادله نکنید. کلاهبرداران معمولاً شما را به واتساپ، تلگرام یا پرداخت مستقیم هدایت می‌کنند.</div>
    </div>

    <!-- Common scams -->
    <section class="card mb-5">
      <div class="card-header"><h2 style="margin:0;font-size:1.125rem"><i class="bi bi-eye"></i> روش‌های رایج کلاهبرداری</h2></div>
      <div class="card-body">
        <div class="fraud-grid">
          <div class="fraud-item">
            <div class="fraud-item__icon"><i class="bi bi-cash-stack"></i></div>
            <h3>پیش‌پرداخت خارج از سایت</h3>
            <p>درخواست واریز به حساب شخصی، کارت به کارت یا رمز ارز قبل از دریافت کالا. پس از واریز، فروشنده ناپدید می‌شود.</p>
          </div>
          <div class="fraud-item">
            <div class="fraud-item__icon"><i class="bi bi-image"></i></div>
            <h3>عکس و توضیحات جعلی</h3>
            <p>استفاده از تصاویر اینترنتی، قیمت غیرواقعی یا ادعای «نو و پلمپ» برای کالای دست‌دوم. همیشه از چند زاویه عکس واقعی بخواهید.</p>
          </div>
          <div class="fraud-item">
            <div class="fraud-item__icon"><i class="bi bi-chat-dots"></i></div>
            <h3>انتقال گفتگو به پیام‌رسان</h3>
            <p>«برای سریع‌تر شدن، به تلگرام/واتساپ بیایید» — خارج از <?= APP_NAME ?> هیچ سابقه و حمایتی ندارید.</p>
          </div>
          <div class="fraud-item">
            <div class="fraud-item__icon"><i class="bi bi-truck"></i></div>
            <h3>ارسال با پست جعلی</h3>
            <p>ارسال بسته خالی، سنگ یا کالای متفاوت. بدون بازرسی یا سپرده امانی، پول شما در خطر است.</p>
          </div>
          <div class="fraud-item">
            <div class="fraud-item__icon"><i class="bi bi-person-badge"></i></div>
            <h3>هویت جعلی</h3>
            <p>حساب تازه‌ساخت بدون سابقه معامله، بدون احراز هویت (KYC) یا با امتیاز مشکوک. به پروفایل و امتیاز «سواپ اسکور» توجه کنید.</p>
          </div>
          <div class="fraud-item">
            <div class="fraud-item__icon"><i class="bi bi-lightning"></i></div>
            <h3>عجله و فشار روانی</h3>
            <p>«فقط امروز»، «یک نفر دیگر هم می‌خواهد»، «همین الان واریز کن» — کلاسیک فریب برای تصمیم عجولانه.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Safe trading -->
    <section class="card mb-5">
      <div class="card-header"><h2 style="margin:0;font-size:1.125rem"><i class="bi bi-shield-check"></i> چطور امن معامله کنیم؟</h2></div>
      <div class="card-body">
        <ol class="fraud-steps">
          <li><strong>فقط داخل <?= APP_NAME ?>:</strong> پیشنهاد، مذاکره و پرداخت را از بخش معاملات انجام دهید.</li>
          <li><strong>سپرده امانی (Escrow):</strong> مبلغ تا تأیید دریافت کالا نزد پلتفرم نگه داشته می‌شود.</li>
          <li><strong>احراز هویت:</strong> با فروشندگان دارای نشان KYC معامله کنید — <a href="<?= APP_URL ?>/profile/edit">احراز هویت خودتان</a> هم اعتماد می‌سازد.</li>
          <li><strong>بازرسی کارشناس:</strong> برای کالاهای گران‌قیمت، از <a href="<?= APP_URL ?>/about">بازرسی <?= APP_NAME ?></a> استفاده کنید.</li>
          <li><strong>بررسی پروفایل:</strong> امتیاز، تعداد معاملات موفق و نظرات دیگران را ببینید.</li>
          <li><strong>مستندسازی:</strong> عکس بسته‌بندی، رسید پست و وضعیت کالا را نگه دارید.</li>
        </ol>
      </div>
    </section>

    <!-- What to do -->
    <section class="card mb-5">
      <div class="card-header"><h2 style="margin:0;font-size:1.125rem"><i class="bi bi-flag"></i> اگر مشکوک شدید چه کنید؟</h2></div>
      <div class="card-body">
        <div style="display:grid;gap:var(--sp-4)">
          <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
            <span class="badge badge-danger" style="font-size:1rem;padding:var(--sp-2) var(--sp-3)">۱</span>
            <div><strong>معامله نکنید</strong> — اگر چیزی غیرعادی بود، فوراً قطع کنید.</div>
          </div>
          <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
            <span class="badge badge-warning" style="font-size:1rem;padding:var(--sp-2) var(--sp-3)">۲</span>
            <div><strong>آگهی را گزارش دهید</strong> — از صفحه آگهی یا <a href="<?= APP_URL ?>/support">تیکت پشتیبانی</a> با دسته «آگهی» ثبت کنید.</div>
          </div>
          <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
            <span class="badge badge-info" style="font-size:1rem;padding:var(--sp-2) var(--sp-3)">۳</span>
            <div><strong>در معامله جاری:</strong> از بخش <a href="<?= APP_URL ?>/trades">معاملات</a> «ثبت اختلاف» کنید — تیم بررسی می‌کند.</div>
          </div>
          <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
            <span class="badge badge-primary" style="font-size:1rem;padding:var(--sp-2) var(--sp-3)">۴</span>
            <div><strong>کلاهبرداری مالی:</strong> به پلیس فتا (<a href="https://www.cyberpolice.ir" target="_blank" rel="noopener">cyberpolice.ir</a>) هم گزارش دهید.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA -->
    <div style="text-align:center;padding:var(--sp-6) 0">
      <p style="color:var(--text-muted);margin-bottom:var(--sp-4)">سؤال دارید یا آگهی مشکوک دیدید؟</p>
      <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
        <a href="<?= APP_URL ?>/support" class="btn btn-primary"><i class="bi bi-headset"></i> تماس با پشتیبانی</a>
        <?php if ($user): ?>
        <a href="<?= APP_URL ?>/trades" class="btn btn-outline"><i class="bi bi-exclamation-triangle"></i> ثبت اختلاف معامله</a>
        <?php endif; ?>
      </div>
    </div>

  </div>
</main>

<?php render_footer(); ?>
