<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();
$metaTitle = 'دستیار هوشمند سواَپین';
$metaDesc = 'دستیار هوشمند سواَپین با استفاده از هوش مصنوعی، ارزش‌گذاری کالا، پیشنهاد بهترین معاوضه، راهنمای ثبت آگهی و پاسخ به سوالات کاربران را انجام می‌دهد تا تجربه‌ای سریع، دقیق و مطمئن از مبادله کالا داشته باشید.';
$canonical = APP_URL . '/ai-assistant';
$ogImage = APP_URL . '/uploads/photo_6017098746631491339_w.jpg';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $metaTitle,
    'description' => $metaDesc,
    'url' => $canonical,
    'image' => $ogImage,
    'publisher' => [
        '@type' => 'Organization',
        'name' => APP_NAME,
        'logo' => [
            '@type' => 'ImageObject',
            'url' => LOGO_URL,
        ],
    ],
];

$faqJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [
        [
            '@type' => 'Question',
            'name' => 'آیا استفاده از دستیار هوشمند رایگان است؟',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'بله، امکانات پایه دستیار هوشمند برای کاربران سواَپین رایگان است.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'آیا قیمت اعلام‌شده قطعی است؟',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'خیر. قیمت ارائه‌شده یک برآورد هوشمند است و قیمت نهایی با توافق طرفین معامله مشخص می‌شود.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'آیا دستیار هوشمند برای همه دسته‌بندی‌ها قابل استفاده است؟',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'بله، دستیار هوشمند از دسته‌بندی‌های مختلف کالا پشتیبانی می‌کند و برای هر کالا متناسب با اطلاعات ثبت‌شده پیشنهاد ارائه می‌دهد.',
            ],
        ],
    ],
];

render_head($metaTitle, $metaDesc, [
    'canonical' => $canonical,
    'og_image'  => $ogImage,
    'og_type'   => 'website',
    'json_ld'   => [$jsonLd, $faqJsonLd],
]);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container-md">

    <div style="text-align:center;padding:var(--sp-6) 0 var(--sp-4)">
      <div style="margin-bottom:var(--sp-5);display: flex;justify-content: center;">
        <img src="<?= h($ogImage) ?>" alt="دستیار هوشمند سواَپین" style="max-width:100%;width:640px;height:auto;border-radius:24px;box-shadow:0 12px 40px rgba(10,37,64,.12)">
      </div>
      <div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent-dark));margin-bottom:var(--sp-5)">
        <i class="bi bi-stars" style="font-size:2rem;color:#fff"></i>
      </div>
      <h1 style="font-size:2.25rem;margin:0 0 var(--sp-3);background:linear-gradient(135deg,var(--primary),var(--accent-dark));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">دستیار هوشمند سواَپین</h1>
      <p style="font-size:1.25rem;color:var(--text-secondary);max-width:640px;margin:0 auto;line-height:1.8;font-weight:500">
        معامله هوشمندتر با کمک هوش مصنوعی
      </p>
    </div>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <p style="color:var(--text-secondary);line-height:2;margin:0;font-size:1.0625rem">
          دستیار هوشمند سواَپین با بهره‌گیری از هوش مصنوعی طراحی شده است تا فرآیند مبادله کالا را ساده‌تر، سریع‌تر و دقیق‌تر کند. فرقی نمی‌کند قصد ثبت کالا، ارزش‌گذاری، پیدا کردن کالای مناسب برای معاوضه یا دریافت راهنمایی درباره روند معامله را داشته باشید؛ دستیار هوشمند در تمام مراحل کنار شماست.
        </p>
      </div>
    </div>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.5rem;margin-bottom:var(--sp-6);text-align:center">
          دستیار هوشمند سواَپین چه کارهایی انجام می‌دهد؟
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:var(--sp-5)">
          <?php
          $features = [
            [
              'icon'  => 'bi-tag-fill',
              'color' => 'primary',
              'title' => 'قیمت‌گذاری هوشمند کالا',
              'desc'  => 'یکی از مهم‌ترین دغدغه‌های کاربران، تعیین ارزش واقعی کالا است. دستیار هوشمند با بررسی اطلاعاتی مانند نوع کالا، برند، مدل، وضعیت ظاهری و مشخصات ثبت‌شده، ارزش تقریبی کالا را پیشنهاد می‌دهد تا بتوانید با اطمینان بیشتری وارد معامله شوید.',
            ],
            [
              'icon'  => 'bi-arrow-left-right',
              'color' => 'accent-dark',
              'title' => 'پیشنهاد بهترین گزینه‌های معاوضه',
              'desc'  => 'اگر نمی‌دانید کالای شما با چه کالاهایی ارزش مبادله دارد، دستیار هوشمند با تحلیل اطلاعات ثبت‌شده، پیشنهادهایی متناسب با ارزش و دسته‌بندی کالا ارائه می‌کند و پیدا کردن معامله مناسب را آسان‌تر می‌سازد.',
            ],
            [
              'icon'  => 'bi-chat-dots-fill',
              'color' => 'info',
              'title' => 'پاسخ به سوالات شما',
              'desc'  => 'از نحوه ثبت کالا و ارسال پیشنهاد معاوضه گرفته تا قوانین و نکات مربوط به معاملات، می‌توانید سوالات خود را از دستیار هوشمند بپرسید و در کمترین زمان پاسخ دریافت کنید.',
            ],
            [
              'icon'  => 'bi-clipboard-check-fill',
              'color' => 'success',
              'title' => 'راهنمای ثبت کالا',
              'desc'  => 'برای اینکه آگهی شما شانس بیشتری برای دیده شدن و انجام معامله داشته باشد، دستیار هوشمند در انتخاب عنوان، توضیحات، دسته‌بندی و مشخصات کالا نیز شما را راهنمایی می‌کند.',
            ],
            [
              'icon'  => 'bi-balance',
              'color' => 'warning',
              'title' => 'کمک به تصمیم‌گیری',
              'desc'  => 'اگر بین چند پیشنهاد مردد هستید، دستیار هوشمند با مقایسه ارزش تقریبی کالاها و بررسی شرایط معامله، اطلاعات لازم را در اختیار شما قرار می‌دهد تا انتخاب آگاهانه‌تری داشته باشید.',
            ],
          ];
          foreach ($features as $f):
          ?>
          <div style="background:var(--bg);border-radius:20px;padding:var(--sp-5);border:1px solid var(--border);transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='0 8px 24px rgba(10,37,64,.08)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
            <div style="width:56px;height:56px;border-radius:16px;background:rgba(26,107,74,.08);display:flex;align-items:center;justify-content:center;margin-bottom:var(--sp-4)">
              <i class="bi <?= $f['icon'] ?>" style="font-size:1.5rem;color:var(--<?= $f['color'] ?>)"></i>
            </div>
            <h3 style="font-size:1.125rem;margin:0 0 var(--sp-3);font-weight:700"><?= $f['title'] ?></h3>
            <p style="color:var(--text-secondary);line-height:1.8;margin:0;font-size:.9375rem"><?= $f['desc'] ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card mb-6" style="border-right:4px solid var(--primary)">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-5)">
          <i class="bi bi-lightbulb-fill" style="color:var(--primary)"></i> چرا از دستیار هوشمند سواَپین استفاده کنیم؟
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--sp-4)">
          <?php
          $benefits = [
            'صرفه‌جویی در زمان',
            'ارزش‌گذاری سریع کالا',
            'پیشنهادهای هوشمند و متناسب با نیاز شما',
            'کاهش احتمال قیمت‌گذاری نادرست',
            'راهنمایی در تمام مراحل مبادله',
            'تجربه کاربری ساده و هوشمند',
          ];
          foreach ($benefits as $b):
          ?>
          <div style="display:flex;gap:var(--sp-3);align-items:flex-start;padding:var(--sp-3);background:var(--bg);border-radius:12px">
            <i class="bi bi-check-circle-fill" style="color:var(--success);font-size:1.25rem;flex-shrink:0;margin-top:2px"></i>
            <span style="color:var(--text-secondary);font-weight:500;line-height:1.7"><?= $b ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-6)">
          <i class="bi bi-list-stars" style="color:var(--accent-dark)"></i> چگونه از دستیار هوشمند استفاده کنیم؟
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--sp-5)">
          <?php
          $steps = [
            ['num' => '۱', 'title' => 'ورود به حساب کاربری', 'desc' => 'وارد حساب کاربری سواَپین شوید.'],
            ['num' => '۲', 'title' => 'باز کردن بخش AI', 'desc' => 'بخش «دستیار هوشمند» را باز کنید.'],
            ['num' => '۳', 'title' => 'ثبت سوال یا اطلاعات', 'desc' => 'سوال خود را بنویسید یا اطلاعات کالای خود را وارد کنید.'],
            ['num' => '۴', 'title' => 'دریافت پیشنهادها', 'desc' => 'پیشنهادها و راهنمایی‌های هوشمند را دریافت کنید.'],
            ['num' => '۵', 'title' => 'انجام معامله مطمئن', 'desc' => 'با اطمینان بیشتری معامله خود را انجام دهید.'],
          ];
          foreach ($steps as $s):
          ?>
          <div style="text-align:center">
            <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent-dark));display:flex;align-items:center;justify-content:center;margin:0 auto var(--sp-3);font-size:1.5rem;font-weight:700;color:#fff"><?= $s['num'] ?></div>
            <h3 style="font-size:1rem;margin:0 0 var(--sp-2);font-weight:700"><?= $s['title'] ?></h3>
            <p class="fs-sm" style="color:var(--text-muted);line-height:1.7;margin:0"><?= $s['desc'] ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card mb-6" style="background:linear-gradient(135deg,rgba(26,107,74,.04),rgba(59,130,108,.06));border:1px solid rgba(26,107,74,.12)">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-4);text-align:center">
          جمع‌بندی
        </h2>
        <p style="color:var(--text-secondary);line-height:2;margin-bottom:var(--sp-5);font-size:1.0625rem;text-align:center">
          سواَپین با استفاده از فناوری هوش مصنوعی، تجربه‌ای متفاوت از مبادله کالا را در اختیار کاربران قرار می‌دهد. هدف ما این است که شما بتوانید بدون اتلاف وقت، با اطلاعات بیشتر و اطمینان بالاتر، کالاهای خود را با بهترین گزینه‌های ممکن معاوضه کنید.
        </p>
        <p style="color:var(--text-secondary);line-height:2;margin:0;font-size:1.0625rem;text-align:center;font-weight:600">
          اگر آماده‌اید مبادله‌ای هوشمند، سریع و مطمئن را تجربه کنید، همین حالا از دستیار هوشمند سواَپین استفاده کنید.
        </p>
        <div style="text-align:center;margin-top:var(--sp-6)">
          <a href="<?= APP_URL ?>/ai/chat" class="btn btn-primary btn-lg" style="font-size:1.0625rem;padding:14px 32px;border-radius:16px">
            <i class="bi bi-stars"></i> شروع استفاده از دستیار هوشمند
          </a>
        </div>
      </div>
    </div>

    <div class="card mb-8" id="faq">
      <div class="card-body" style="padding:var(--sp-8)">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-6);text-align:center">
          <i class="bi bi-question-circle-fill" style="color:var(--info)"></i> سوالات متداول
        </h2>
        <div style="max-width:800px;margin:0 auto;display:flex;flex-direction:column;gap:var(--sp-3)">
          <?php
          $faqs = [
            [
              'q' => 'آیا استفاده از دستیار هوشمند رایگان است؟',
              'a' => 'بله، امکانات پایه دستیار هوشمند برای کاربران سواَپین رایگان است.',
            ],
            [
              'q' => 'آیا قیمت اعلام‌شده قطعی است؟',
              'a' => 'خیر. قیمت ارائه‌شده یک برآورد هوشمند است و قیمت نهایی با توافق طرفین معامله مشخص می‌شود.',
            ],
            [
              'q' => 'آیا دستیار هوشمند برای همه دسته‌بندی‌ها قابل استفاده است؟',
              'a' => 'بله، دستیار هوشمند از دسته‌بندی‌های مختلف کالا پشتیبانی می‌کند و برای هر کالا متناسب با اطلاعات ثبت‌شده پیشنهاد ارائه می‌دهد.',
            ],
          ];
          foreach ($faqs as $i => $faq):
              $itemId = 'faq-item-' . ($i + 1);
              $btnId  = 'faq-btn-' . ($i + 1);
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
              <div style="padding:0 var(--sp-5) var(--sp-5);color:var(--text-secondary);line-height:1.9;font-size:.9375rem">
                <?= h($faq['a']) ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
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
