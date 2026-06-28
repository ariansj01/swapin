<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();
render_head('درباره ما', 'با ' . APP_NAME . ' — بازار هوشمند تهاتر — آشنا شوید.');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">

    <div style="text-align:center;padding:var(--sp-10) 0 var(--sp-8)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;background:var(--primary);margin-bottom:var(--sp-5)">
        <i class="bi bi-arrow-left-right" style="font-size:2rem;color:#fff"></i>
      </div>
      <h1 style="font-size:2rem;margin:0 0 var(--sp-3)">درباره <?= APP_NAME ?></h1>
      <p style="font-size:1.125rem;color:var(--text-secondary);max-width:540px;margin:0 auto;line-height:1.7">
        بازار هوشمند تهاتر که به مردم امکان می‌دهد کالا و خدمات را مستقیماً با هم مبادله کنند — بدون نیاز به پول نقد.
      </p>
    </div>

    <!-- Story -->
    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">
          <i class="bi bi-lightbulb" style="color:var(--accent-dark)"></i> داستان ما
        </h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          <?= APP_NAME ?> از آنچه در جنگ روسیه و اوکراین رخ داد الهام گرفته — وقتی سیستم‌های بانکی سنتی مختل شدند،
          مردم در پلتفرم‌هایی مثل OLX و شبکه‌های اجتماعی شروع به مبادله مستقیم کالا کردند.
          تهاتر دوباره به روشی قدرتمند و انسانی برای برآوردن نیازها بدون پول نقد تبدیل شد.
        </p>
        <p style="color:var(--text-secondary);line-height:1.8;margin:0">
          ما <?= APP_NAME ?> را ساختیم تا به این تبادل طبیعی خانه‌ای مناسب بدهیم: بازاری ساختاریافته با تطبیق هوشمند،
          اقتصاد اعتباری و زیرساخت اعتماد — تا تهاتر به خوبی (یا بهتر از) تجارت نقدی کار کند.
        </p>
      </div>
    </div>

    <!-- How it works -->
    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-6)">
          <i class="bi bi-diagram-3" style="color:var(--primary)"></i> چگونه کار می‌کند
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--sp-6)">
          <?php
          $steps = [
            ['bi-plus-circle',   'primary',    '۱. ثبت آگهی',    'آنچه دارید — کالا یا خدمات — را ثبت کنید. ارزش تقریبی تعیین کنید و بگویید در ازای آن چه می‌خواهید.'],
            ['bi-search',        'accent-dark', '۲. جستجو و کشف', 'هزاران آگهی را جستجو کنید. بر اساس دسته، شهر یا نوع تهاتر فیلتر کنید تا گزینه مناسب را پیدا کنید.'],
            ['bi-chat-dots',     'success',     '۳. ارسال پیشنهاد',     'کالای خود را پیشنهاد دهید، برای تعادل ارزش اعتبار ' . CREDIT_UNIT . ' اضافه کنید و برای مذاکره پیام بفرستید.'],
            ['bi-check2-all',    'primary',     '۴. تهاتر و امتیاز',      'هر دو طرف مبادله را تأیید می‌کنند. امتیازدهی اعتماد را برای تهاترهای آینده می‌سازد.'],
          ];
          foreach ($steps as [$icon, $color, $title, $desc]):
          ?>
          <div>
            <div style="width:48px;height:48px;border-radius:var(--radius);background:rgba(26,107,74,.08);display:flex;align-items:center;justify-content:center;margin-bottom:var(--sp-3)">
              <i class="bi <?= $icon ?>" style="font-size:1.375rem;color:var(--<?= $color ?>)"></i>
            </div>
            <h3 style="font-size:1rem;margin:0 0 var(--sp-2)"><?= $title ?></h3>
            <p class="fs-sm" style="color:var(--text-secondary);line-height:1.6;margin:0"><?= $desc ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- SWP Credits -->
    <div class="card mb-6" style="border-left:4px solid var(--primary)">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">
          <i class="bi bi-wallet2" style="color:var(--primary)"></i> <?= CREDIT_UNIT ?> — اعتبار <?= APP_NAME ?>
        </h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-5)">
          هر تهاتری دقیقاً برابر ارزش نیست. اعتبار <?= CREDIT_UNIT ?> این فاصله را پر می‌کند — اگر کالای شما کمی کمتر ارزش دارد
          از آنچه می‌خواهید، می‌توانید اعتبار اضافه کنید تا معامله برای هر دو طرف منصفانه شود.
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
          <div style="background:var(--bg);border-radius:var(--radius);padding:var(--sp-4)">
            <div style="font-weight:600;margin-bottom:var(--sp-2)"><i class="bi bi-arrow-down-circle" style="color:var(--success)"></i> کسب اعتبار</div>
            <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.6">وقتی طرف مقابل در معامله اعتبار اضافه می‌کند <?= CREDIT_UNIT ?> دریافت کنید، یا مستقیماً از درگاه پرداخت واریز کنید.</p>
          </div>
          <div style="background:var(--bg);border-radius:var(--radius);padding:var(--sp-4)">
            <div style="font-weight:600;margin-bottom:var(--sp-2)"><i class="bi bi-arrow-up-circle" style="color:var(--primary)"></i> خرج اعتبار</div>
            <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.6"><?= CREDIT_UNIT ?> به پیشنهاد خود اضافه کنید تا فاصله ارزش را ببندید و پیشنهاد را برای طرف دیگر جذاب‌تر کنید.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Trust -->
    <div class="card mb-8">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-6)">
          <i class="bi bi-shield-check" style="color:var(--success)"></i> اعتماد و امنیت
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--sp-5)">
          <?php
          $trust = [
            ['bi-star-fill',        'accent-dark', 'امتیاز و نظرات',  'هر تهاتر تکمیل‌شده قابل امتیازدهی است و امتیاز اعتماد قابل مشاهده می‌سازد.'],
            ['bi-patch-check-fill', 'primary',     'حساب‌های تأییدشده',  'سطح تأیید تلفن و هویت در هر پروفایل نمایش داده می‌شود.'],
            ['bi-clock-history',    'info',        'تاریخچه تهاتر',      'تاریخچه کامل تهاتر در پروفایل کاربران برای شفافیت قابل مشاهده است.'],
            ['bi-chat-left-dots',   'success',     'پیام‌رسانی مستقیم',   'پیام‌رسانی داخلی برای بحث جزئیات قبل از تعهد به تهاتر.'],
          ];
          foreach ($trust as [$icon, $color, $title, $desc]):
          ?>
          <div style="display:flex;gap:var(--sp-3);align-items:flex-start">
            <i class="bi <?= $icon ?>" style="font-size:1.25rem;color:var(--<?= $color ?>);flex-shrink:0;margin-top:2px"></i>
            <div>
              <div style="font-weight:600;margin-bottom:4px"><?= $title ?></div>
              <p class="fs-xs" style="color:var(--text-muted);margin:0;line-height:1.6"><?= $desc ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- CTA -->
    <div style="text-align:center;padding-bottom:var(--sp-10)">
      <h2 style="font-size:1.375rem;margin-bottom:var(--sp-3)">آماده شروع تهاتر هستید؟</h2>
      <p style="color:var(--text-muted);margin-bottom:var(--sp-6)">به هزاران نفری بپیوندید که هر روز هوشمندانه‌تر تهاتر می‌کنند.</p>
      <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
        <?php if ($user): ?>
        <a href="<?= APP_URL ?>/listings/create.php" class="btn btn-primary btn-lg"><i class="bi bi-plus-lg"></i> ثبت آگهی</a>
        <a href="<?= APP_URL ?>/" class="btn btn-outline btn-lg">مرور آگهی‌ها</a>
        <?php else: ?>
        <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-primary btn-lg"><i class="bi bi-person-plus"></i> عضویت رایگان</a>
        <a href="<?= APP_URL ?>/" class="btn btn-outline btn-lg">مرور آگهی‌ها</a>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php render_footer(); ?>
