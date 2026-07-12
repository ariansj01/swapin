<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();
render_head('حریم خصوصی | ' . APP_NAME, 'سیاست حریم خصوصی پلتفرم ' . APP_NAME . ' را مطالعه کنید.', [
    'canonical' => APP_URL . '/privacy',
]);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container-md">

    <div style="text-align:center;padding:var(--sp-10) 0 var(--sp-8)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;background:var(--primary);margin-bottom:var(--sp-5)">
        <i class="bi bi-shield-lock" style="font-size:2rem;color:#fff"></i>
      </div>
      <h1 style="font-size:2rem;margin:0 0 var(--sp-3)">حریم خصوصی</h1>
      <p style="font-size:1.125rem;color:var(--text-secondary);max-width:540px;margin:0 auto;line-height:1.7">
          آخرین بروزرسانی: ۱ تیر ۱۴۰۵
        </p>
    </div>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۱. جمع‌آوری اطلاعات</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • <strong>اطلاعات شخصی</strong>: شماره تلفن، نام، نام خانوادگی، شهر، تصویر پروفایل و اطلاعات حساب بانکی (در صورت نیاز).<br>
          • <strong>اطلاعات آگهی‌ها</strong>: توضیحات، تصاویر، قیمت تخمینی و سایر اطلاعات مرتبط با آگهی‌های شما.<br>
          • <strong>اطلاعات استفاده</strong>: لاگ‌های دسترسی، تراکنش‌ها و پیام‌های شما در پلتفرم.<br>
          • <strong>کوکی‌ها</strong>: برای بهبود تجربه کاربری و تحلیل استفاده از پلتفرم.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۲. استفاده از اطلاعات</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • ارائه خدمات پلتفرم و امکان‌پذیری مبادلات.<br>
          • برقراری ارتباط با شما در مورد حساب کاربری و سرویس‌ها.<br>
          • بهبود و توسعه پلتفرم.<br>
          • رعایت الزامات قانونی و نظارتی.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۳. اشتراک‌گذاری اطلاعات</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • <strong>دیگر کاربران</strong>: اطلاعات پروفایل و آگهی‌های شما برای سایر کاربران قابل مشاهده است.<br>
          • <strong>پروویدرهای سرویس</strong>: ممکن است اطلاعاتی را با سرویس‌دهندگان همکار (مانند پردازشگرهای پرداخت و SMS) به اشتراک بگذاریم.<br>
          • <strong>مقررات قانونی</strong>: ممکن است اطلاعات شما را در پاسخ به درخواست‌های قانونی یا مقررات دولتی افشا کنیم.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۴. امنیت اطلاعات</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • ما اقدامات امنیتی منطقی را برای محافظت از اطلاعات شما انجام می‌دهیم.<br>
          • با این حال، هیچ روش انتقال داده از طریق اینترنت یا روش ذخیره‌سازی الکترونیکی ۱۰۰٪ امن نیست.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۵. حقوق شما</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • دسترسی، تصحیح یا حذف اطلاعات شخصی خود.<br>
          • خروج از دریافت پیام‌های تبلیغاتی.<br>
          • غیرفعال کردن کوکی‌ها در مرورگر خود (اگرچه این ممکن است بر عملکرد پلتفرم تأثیر بگذارد).
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۶. کودکان</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          سرویس‌های <?= APP_NAME ?> برای افراد زیر ۱۸ سال مناسب نیستند و ما عمداً اطلاعاتی را از کودکان جمع‌آوری نمی‌کنیم.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۷. تغییرات</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin:0">
          • ما ممکن است این سیاست حریم خصوصی را از زمان به زمان به‌روزرسانی کنیم.<br>
          • ادامه استفاده از پلتفرم پس از انتشار تغییرات به معنای پذیرش آنهاست.
        </p>
      </div>
    </div>

  </div>
</main>

<?php render_footer(); ?>

