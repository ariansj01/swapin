<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();
$metaTitle = 'سوالات متداول سواَپین | راهنمای کامل معاوضه کالا و پاسخ به پرسش‌های کاربران';
$metaDesc  = 'پاسخ به تمام سوالات شما درباره سواَپین، نحوه ثبت‌نام، ثبت کالا، ارسال پیشنهاد معاوضه، ارزش‌گذاری هوشمند، اتاق امن معامله و قوانین پلتفرم.';
$canonical = APP_URL . '/faq';
$ogImage   = LOGO_URL;

$webPageJsonLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'WebPage',
    'name'     => $metaTitle,
    'description' => $metaDesc,
    'url'      => $canonical,
    'image'    => $ogImage,
    'publisher' => [
        '@type' => 'Organization',
        'name'  => APP_NAME,
        'logo'  => ['@type' => 'ImageObject', 'url' => LOGO_URL],
    ],
];

$faqs = [
    [
        'q' => 'سواَپین چیست؟',
        'a' => 'سواَپین یک پلتفرم هوشمند برای معاوضه کالا با کالا است که به کاربران کمک می‌کند بدون نیاز به فروش کالا، آن را با کالای موردنیاز خود مبادله کنند. در سواَپین علاوه بر معاوضه مستقیم، در صورت وجود اختلاف قیمت، امکان پرداخت یا دریافت مابه‌التفاوت نیز وجود دارد.',
    ],
    [
        'q' => 'چگونه در سواَپین ثبت‌نام کنم؟',
        'a' => 'ثبت‌نام بسیار ساده است.<br><br>۱. شماره موبایل خود را وارد کنید.<br>۲. کد تایید پیامک‌شده را وارد نمایید.<br>۳. حساب کاربری شما فعال خواهد شد.',
    ],
    [
        'q' => 'چگونه کالا ثبت کنم؟',
        'a' => "پس از ورود به حساب کاربری:<br><br>۱. روی «ثبت کالا» کلیک کنید.<br>۲. تصاویر کالا را بارگذاری کنید.<br>۳. مشخصات کالا را وارد نمایید.<br>۴. ارزش تقریبی کالا را ثبت کنید.<br>۵. کالاهای موردنیاز خود برای معاوضه را مشخص نمایید.<br>۶. آگهی را منتشر کنید.",
    ],
    [
        'q' => 'آیا ثبت کالا رایگان است؟',
        'a' => 'بله، ثبت کالا در سواَپین رایگان است.<br><br>ممکن است برخی امکانات ویژه یا خدمات تبلیغاتی در آینده به‌صورت اختیاری ارائه شوند.',
    ],
    [
        'q' => 'آیا می‌توانم کالا را فقط بفروشم؟',
        'a' => 'خیر.<br><br>تمرکز اصلی سواَپین بر معاوضه کالا است و کاربران برای هر کالا، کالای موردنیاز خود را نیز مشخص می‌کنند.',
    ],
    [
        'q' => 'اگر ارزش دو کالا برابر نباشد چه می‌شود؟',
        'a' => 'در صورتی که ارزش دو کالا متفاوت باشد، طرفین می‌توانند مبلغ مابه‌التفاوت را پرداخت یا دریافت کنند.<br><br>این موضوع قبل از نهایی شدن معامله به‌صورت شفاف مشخص خواهد شد.',
    ],
    [
        'q' => 'آیا امکان پرداخت اقساطی اختلاف قیمت وجود دارد؟',
        'a' => 'بله.<br><br>در برخی معاملات، در صورت فعال بودن سرویس الان بخر، بعداً پرداخت کن (BNPL)، امکان پرداخت اقساطی مابه‌التفاوت فراهم خواهد بود.',
    ],
    [
        'q' => 'آیا قیمت اعلام‌شده توسط هوش مصنوعی قطعی است؟',
        'a' => 'خیر.<br><br>قیمت ارائه‌شده صرفاً یک برآورد هوشمند است و قیمت نهایی با توافق دو طرف معامله تعیین می‌شود.',
    ],
    [
        'q' => 'چگونه پیشنهاد معاوضه ارسال کنم؟',
        'a' => 'وارد صفحه کالای موردنظر شوید و روی گزینه ارسال پیشنهاد معاوضه کلیک کنید.<br><br>سپس کالای خود را انتخاب کرده و در صورت نیاز مبلغ مابه‌التفاوت را مشخص نمایید.',
    ],
    [
        'q' => 'آیا می‌توانم چند پیشنهاد برای یک کالا ارسال کنم؟',
        'a' => 'بله.<br><br>شما می‌توانید برای کالاهای مختلف پیشنهاد ارسال کنید و تا قبل از پذیرش نهایی، پیشنهادهای خود را مدیریت نمایید.',
    ],
    [
        'q' => 'اگر پیشنهاد من رد شود چه اتفاقی می‌افتد؟',
        'a' => 'هیچ مشکلی ایجاد نمی‌شود.<br><br>می‌توانید پیشنهاد جدیدی برای همان کالا یا کالاهای دیگر ارسال کنید.',
    ],
    [
        'q' => 'چگونه بهترین پیشنهاد را پیدا کنم؟',
        'a' => 'دستیار هوشمند سواَپین پیشنهادهای مناسب را بر اساس موارد زیر به شما نمایش می‌دهد:<br><br>• ارزش کالا<br>• نیاز کاربران<br>• دسته‌بندی<br>• احتمال موفقیت معامله',
    ],
    [
        'q' => 'معامله چگونه نهایی می‌شود؟',
        'a' => 'پس از پذیرش پیشنهاد توسط هر دو طرف، مراحل هماهنگی، پرداخت مابه‌التفاوت (در صورت وجود) و تحویل کالا انجام می‌شود.',
    ],
    [
        'q' => 'اتاق امن معامله چیست؟',
        'a' => 'اتاق امن معامله محیطی برای مدیریت مراحل معامله است که در آن طرفین می‌توانند وضعیت معامله، پرداخت‌ها و روند تحویل کالا را مشاهده و پیگیری کنند.',
    ],
    [
        'q' => 'آیا سواَپین امنیت معاملات را تضمین می‌کند؟',
        'a' => 'سواَپین تلاش می‌کند با استفاده از ابزارهای امنیتی، احراز هویت کاربران و فرآیندهای نظارتی، محیطی امن برای انجام معاملات فراهم کند. با این حال، کاربران نیز باید اطلاعات کالا و شرایط معامله را با دقت بررسی کرده و مطابق قوانین پلتفرم اقدام کنند.',
    ],
    [
        'q' => 'آیا امکان لغو معامله وجود دارد؟',
        'a' => 'تا پیش از نهایی شدن معامله، امکان انصراف وجود دارد.<br><br>پس از نهایی شدن، شرایط لغو مطابق قوانین سواَپین بررسی خواهد شد.',
    ],
    [
        'q' => 'آیا می‌توانم چند کالا را با یک کالا معاوضه کنم؟',
        'a' => 'بله.<br><br>در صورت توافق طرفین، امکان معاوضه چند کالا با یک کالا نیز وجود دارد.',
    ],
    [
        'q' => 'آیا دستیار هوشمند رایگان است؟',
        'a' => 'بله.<br><br>امکانات پایه دستیار هوشمند برای تمامی کاربران رایگان است.',
    ],
    [
        'q' => 'چگونه با پشتیبانی سواَپین تماس بگیرم؟',
        'a' => 'از طریق بخش پشتیبانی داخل سایت می‌توانید سوالات یا درخواست‌های خود را ثبت کنید و پاسخ دریافت نمایید.',
    ],
    [
        'q' => 'آیا استفاده از سواَپین روی موبایل امکان‌پذیر است؟',
        'a' => 'بله.<br><br>سواَپین روی موبایل، تبلت و رایانه قابل استفاده است و می‌توانید از طریق مرورگر به حساب کاربری خود دسترسی داشته باشید.',
    ],
    [
        'q' => 'هنوز پاسخ سوال خود را پیدا نکرده‌ام',
        'a' => 'اگر پاسخ سوال خود را در این صفحه پیدا نکردید، از طریق بخش پشتیبانی یا دستیار هوشمند سواَپین با ما در ارتباط باشید. تیم پشتیبانی در کوتاه‌ترین زمان ممکن شما را راهنمایی خواهد کرد.',
    ],
];

$faqMainEntity = [];
foreach ($faqs as $item) {
    $faqMainEntity[] = [
        '@type'          => 'Question',
        'name'           => $item['q'],
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text'  => strip_tags(str_replace('<br>', "\n", $item['a'])),
        ],
    ];
}

$faqJsonLd = [
    '@context'   => 'https://schema.org',
    '@type'    => 'FAQPage',
    'mainEntity' => $faqMainEntity,
];

render_head($metaTitle, $metaDesc, [
    'canonical' => $canonical,
    'og_image'  => $ogImage,
    'og_type'   => 'website',
    'json_ld'   => [$webPageJsonLd, $faqJsonLd],
]);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container-md">

    <div style="text-align:center;padding:var(--sp-8) 0 var(--sp-6)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--info),#4f86f7);margin-bottom:var(--sp-5)">
        <i class="bi bi-question-octagon" style="font-size:2rem;color:#fff"></i>
      </div>
      <h1 style="font-size:2.25rem;margin:0 0 var(--sp-3)">سوالات متداول (FAQ)</h1>
      <p style="font-size:1.125rem;color:var(--text-secondary);max-width:640px;margin:0 auto;line-height:1.8">
        پاسخ تمام پرسش‌های رایج شما درباره معاوضه کالا در سواَپین را در این صفحه پیدا کنید.
      </p>
    </div>

    <div class="card mb-8">
      <div class="card-body" style="padding:var(--sp-8)">
      <div style="max-width:860px;margin:0 auto;display:flex;flex-direction:column;gap:var(--sp-3)">
          <?php foreach ($faqs as $i => $faq):
              $itemId  = 'faq-item-' . ($i + 1);
              $btnId   = 'faq-btn-' . ($i + 1);
              $panelId = 'faq-panel-' . ($i + 1);
          ?>
          <div class="faq-item" id="<?= $itemId ?>" style="border:1px solid var(--border);border-radius:16px;overflow:hidden;background:#fff;transition:box-shadow .2s">
            <button
              type="button"
              class="faq-toggle"
              id="<?= $btnId ?>"
              aria-expanded="false"
              aria-controls="<?= $panelId ?>"
              onclick="toggleFaq('<?= $itemId ?>','<?= $btnId ?>','<?= $panelId ?>')"
              style="width:100%;padding:var(--sp-4) var(--sp-5);border:0;background:none;display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3);cursor:pointer;font:inherit;text-align:right"
            >
              <span style="font-weight:600;font-size:1rem;color:var(--text)"><?= h($faq['q']) ?></span>
              <i class="bi bi-chevron-down faq-icon" style="font-size:1.125rem;color:var(--text-muted);flex-shrink:0;transition:transform .25s ease"></i>
            </button>
            <div
              id="<?= $panelId ?>"
              role="region"
              aria-labelledby="<?= $btnId ?>"
              class="faq-panel"
              style="max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease"
            >
              <div style="padding:0 var(--sp-5) var(--sp-5);color:var(--text-secondary);line-height:2;font-size:.9375rem">
                <?= $faq['a'] ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card mb-8" style="background:linear-gradient(135deg,rgba(26,107,74,.04),rgba(79,134,247,.06));border:1px solid rgba(79,134,247,.15)">
      <div class="card-body" style="padding:var(--sp-8);text-align:center">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-4)">
          <i class="bi bi-chat-dots-fill" style="color:var(--info)"></i> هنوز سوالی پاسخ نگرفته‌اید؟
        </h2>
        <p style="color:var(--text-secondary);line-height:1.9;margin-bottom:var(--sp-6)">
          تیم پشتیبانی سواَپین و دستیار هوشمند ما آماده پاسخگویی به شما هستند.
        </p>
        <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
          <a href="<?= APP_URL ?>/support/index.php" class="btn btn-primary btn-lg"><i class="bi bi-headset"></i> ثبت تیکت پشتیبانی</a>
          <a href="<?= APP_URL ?>/ai/chat" class="btn btn-outline btn-lg"><i class="bi bi-stars"></i> چت با دستیار هوشمند</a>
        </div>
      </div>
    </div>

  </div>
</main>

<script>
function toggleFaq(itemId, btnId, panelId) {
  const item  = document.getElementById(itemId);
  const btn   = document.getElementById(btnId);
  const panel = document.getElementById(panelId);
  const icon  = btn.querySelector('.faq-icon');
  const expanded = btn.getAttribute('aria-expanded') === 'true';

  if (expanded) {
    btn.setAttribute('aria-expanded', 'false');
    panel.style.maxHeight = panel.scrollHeight + 'px';
    requestAnimationFrame(() => {
      panel.style.maxHeight = '0';
    });
    item.style.boxShadow = '';
    if (icon) icon.style.transform = 'rotate(0deg)';
  } else {
    btn.setAttribute('aria-expanded', 'true');
    panel.style.maxHeight = panel.scrollHeight + 'px';
    item.style.boxShadow = '0 4px 16px rgba(10,37,64,.06)';
    if (icon) icon.style.transform = 'rotate(180deg)';
    panel.addEventListener('transitionend', function handler() {
      if (btn.getAttribute('aria-expanded') === 'true') {
        panel.style.maxHeight = 'none';
      }
      panel.removeEventListener('transitionend', handler);
    });
  }
}
</script>

<?php render_footer(); ?>
