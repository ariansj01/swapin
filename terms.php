<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();
render_head('قوانین و مقررات | ' . APP_NAME, 'قوانین و مقررات استفاده از پلتفرم ' . APP_NAME . ' را مطالعه کنید.', [
    'canonical' => APP_URL . '/terms',
]);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container-md">

    <div style="text-align:center;padding:var(--sp-10) 0 var(--sp-8)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;background:var(--primary);margin-bottom:var(--sp-5)">
        <i class="bi bi-file-text" style="font-size:2rem;color:#fff"></i>
      </div>
      <h1 style="font-size:2rem;margin:0 0 var(--sp-3)">قوانین و مقررات</h1>
      <p style="font-size:1.125rem;color:var(--text-secondary);max-width:540px;margin:0 auto;line-height:1.7">
          آخرین بروزرسانی: ۱ تیر ۱۴۰۵
        </p>
    </div>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۱. پذیرش شرایط</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          با استفاده از پلتفرم <?= APP_NAME ?>، شما با این قوانین و مقررات موافق هستید. اگر با هر بخشی از این شرایط موافق نیستید، لطفاً از استفاده از پلتفرم صرف‌نظر کنید.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۲. حساب کاربری</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • شما مسئول حفظ محرمانگی اطلاعات حساب کاربری خود هستید.<br>
          • شما مسئول تمام فعالیت‌هایی است که تحت حساب کاربری شما انجام می‌شود.<br>
          • <?= APP_NAME ?> حق تعلیق یا خاتمه حساب کاربری را در صورت نقض قوانین محفوظ می‌دارد.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۳. ثبت آگهی و مبادله</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • شما متعهد هستید که اطلاعات دقیق و صادقانه در آگهی‌های خود ارائه دهید.<br>
          • <?= APP_NAME ?> هیچ‌گاه مالک کالاها و خدمات ثبت‌شده در پلتفرم نیست.<br>
          • مسئولیت صحت و قانونی کالاها و خدمات متعلق به کاربر ثبت‌کننده است.<br>
          • <?= APP_NAME ?> کارمزد ۱ درصد از هر دو طرف معامله بر اساس ارزش تخمینی کالا دریافت می‌کند.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۴. اعتبار پلتفرم (<?= CREDIT_UNIT ?>)</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • اعتبار <?= CREDIT_UNIT ?> تنها در پلتفرم <?= APP_NAME ?> قابل استفاده است و قابل برداشت نقدی نیست.<br>
          • <?= APP_NAME ?> حق تغییر نرخ یا قوانین استفاده از اعتبار را در هر زمان محفوظ می‌دارد.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۵. محتوای کاربر</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • شما مسئول محتوایی هستید که در پلتفرم آپلود یا منتشر می‌کنید.<br>
          • شما تضمین می‌کنید که مالک یا دارای مجوز استفاده از محتوای منتشر‌شده هستید.<br>
          • محتوای غیرقانونی، توهین‌آمیز، مضر یا خلاف اخلاق ممنوع است.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۶. محدودیت مسئولیت</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin-bottom:var(--sp-4)">
          • <?= APP_NAME ?> هیچ‌گونه تضمینی در مورد کیفیت، صحت یا قانونی بودن کالاها و خدمات ارائه نمی‌دهد.<br>
          • <?= APP_NAME ?> مسئولیتی در قبال خسارت‌های مستقیم یا غیرمستقیم ناشی از استفاده از پلتفرم نمی‌پذیرد.
        </p>

        <h2 style="font-size:1.25rem;margin-bottom:var(--sp-4)">۷. تغییرات</h2>
        <p style="color:var(--text-secondary);line-height:1.8;margin:0">
          • <?= APP_NAME ?> حق تغییر یا ویرایش این قوانین را در هر زمان محفوظ می‌دارد.<br>
          • ادامه استفاده از پلتفرم پس از انتشار تغییرات به معنای پذیرش آنهاست.
        </p>
      </div>
    </div>

  </div>
</main>

<?php render_footer(); ?>

