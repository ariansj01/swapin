<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();
$metaTitle = 'مبادله قسطی در سواَپین | معاوضه کالا با اقساط بدون ریسک';
$metaDesc = 'با سرویس مبادله قسطی سواَپین، کالای خود را به صورت اقساطی معاوضه کنید. از خودرو و املاک گرفته تا کالاهای دیجیتال — معامله امن با ضمانت اسباب‌کشی و کنترل قسط‌ها.';
$canonical = APP_URL . '/installment-swap';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $metaTitle,
    'description' => $metaDesc,
    'url' => $canonical,
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
            'name' => 'مبادله قسطی در سواَپین چیست؟',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'مبادله قسطی نوعی معامله است که در آن کالا یا خدمتی با پرداخت مبلغ تفاضلی به صورت اقساط ماهانه معاوضه می‌شود. سواَپین با اسباب‌کشی امن، کنترل سررسید اقساط و امتیازدهی به پرداخت به موقع، این فرآیند را بدون ریسک انجام می‌دهد.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'آیا اقساط در سواَپین سود دارد؟',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'خیر. درصد کمیسیون پلتفرم طبق دسته‌بندی کالا محاسبه می‌شود و سود اضافی برای اقساط دریافت نمی‌شود. مبلغ تفاضلی دقیقاً همان مبلغ توافق‌شده بین طرفین است.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'در صورت پرداخت نشدن قسط چه می‌شود؟',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'در ابتدای معامله، کالا توسط سواَپین اسباب‌کشی می‌شود. اگر طرف خریدار قسط را سررسید پرداخت نکند، پس از اطلاع‌رسانی و مهلت قانونی، کالا به فروشنده بازگردانده می‌شود و امتیاز اعتماد خریدار کسر می‌گردد.',
            ],
        ],
        [
            '@type' => 'Question',
            'name' => 'برای کدام کالاها می‌توان از مبادله قسطی استفاده کرد؟',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => 'تمام کالاهای دارای ارزش بالا شامل خودرو، دوچرخه، مبلمان منزل، لوازم خانگی بزرگ، لپ‌تاپ و گوشی‌های پرچمدار، و حتی خدمات طولانی‌مدت را می‌توان با روش اقساطی معاوضه کرد.',
            ],
        ],
    ],
];

render_head($metaTitle, $metaDesc, [
    'canonical' => $canonical,
    'json_ld'   => [$jsonLd, $faqJsonLd],
]);
render_navbar($user);
?>
<div class="installment-swap-hero-image" style="width: 100%; text-align: center; margin-bottom: var(--sp-6);">
  <img src="<?= APP_URL ?>/src/img/Group%201171277459%20(2).png" alt="خرید قسطی و معاوضه کالا با سواَپین" style="max-width: 50%; height: auto; display: block; margin: 0 auto;">
</div>

<main id="main-content" class="section-sm">
  <div class="container-md">

    <div style="text-align:center;padding:var(--sp-8) 0 var(--sp-6)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#38b2ac);margin-bottom:var(--sp-5);box-shadow:0 8px 24px rgba(26,107,74,.18)">
        <i class="bi bi-calendar-check" style="font-size:2rem;color:#fff"></i>
      </div>
      <h1 style="font-size:2.125rem;margin:0 0 var(--sp-3);background:linear-gradient(135deg,var(--primary),var(--accent-dark));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">
        مبادله قسطی در سواَپین
      </h1>
      <p style="font-size:1.125rem;color:var(--text-secondary);margin:0 auto;line-height:1.9;font-weight:500">
        کالا یا خدمات خود را بدون نیاز به یکدستی، به صورت اقساط ماهانه معاوضه کنید. با ضمانت اسباب‌کشی امن سواَپین و کنترل هوشمند سررسید اقساط، ریسک معامله نزدیک صفر است.
      </p>
    </div>

    <!-- Overview -->
    <div class="card mb-6" style="border-right:4px solid var(--primary);border-radius:20px;overflow:hidden">
      <div class="card-body" style="padding:var(--sp-10)">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-5)">
          <i class="bi bi-info-circle-fill" style="color:var(--primary)"></i> مبادله قسطی چیست؟
        </h2>
        <div style="display:grid;grid-template-columns:1.1fr 1fr;gap:var(--sp-6);align-items:center">
          <div>
            <p style="color:var(--text-secondary);line-height:2;margin-bottom:var(--sp-4);font-size:1rem">
              در مبادله‌های معمول، دو کالا ممکن است ارزش یکسانی نداشته باشند. برای مثال شما یک پراید ۹۰ دارید و می‌خواهید آن را با یک پژو ۲۰۶ معاوضه کنید؛ تفاوت ارزش مثلاً ۱۵۰ میلیون تومان است.
              <br><br>
              در <strong>مبادله قسطی سواَپین</strong>، به‌جای یک‌باره پرداخت این تفاوت، می‌توانید آن را به اقساط ماهانه ۶، ۱۲ یا ۲۴ ماهه تقسیم کنید و در عین حال کالای طرف مقابل را همین حالا دریافت کنید.
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)">
              <div style="padding:var(--sp-4);background:var(--bg);border-radius:14px;border:1px solid var(--border)">
                <div style="font-weight:700;margin-bottom:6px;color:var(--primary)"><i class="bi bi-shield-check"></i> بدون ریسک فروشنده</div>
                <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.7">کالا تا قبل از پرداخت آخرین قسط به امانت سواَپین است.</p>
              </div>
              <div style="padding:var(--sp-4);background:var(--bg);border-radius:14px;border:1px solid var(--border)">
                <div style="font-weight:700;margin-bottom:6px;color:var(--accent-dark)"><i class="bi bi-piggy-bank"></i> بدون سود اضافی</div>
                <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.7">فقط کمیسیون پلتفرم طبق دسته‌بندی کالا دریافت می‌شود.</p>
              </div>
            </div>
          </div>
          <div style="background:linear-gradient(135deg,rgba(26,107,74,.06),rgba(56,178,172,.08));padding:var(--sp-6);border-radius:20px;border:1px dashed rgba(26,107,74,.2)">
            <div style="font-weight:700;font-size:1.125rem;margin-bottom:var(--sp-4);text-align:center">نمودار یک معامله اقساطی</div>
            <div class="flowchart-steps" style="display:flex;flex-direction:column;gap:var(--sp-3);position:relative;">
              <div style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);background:#fff;border-radius:12px">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700">۱</div>
                <div>
                  <div style="font-weight:600">ثبت آگهی با گزینه «اقساطی»</div>
                  <div class="fs-sm" style="color:var(--text-muted)">فروشنده نوع مبادله و تعداد اقساط را مشخص می‌کند</div>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);background:#fff;border-radius:12px">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--accent-dark);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700">۲</div>
                <div>
                  <div style="font-weight:600">ارسال پیشنهاد قسطی</div>
                  <div class="fs-sm" style="color:var(--text-muted)">خریدار مبلغ و تعداد ماه اقساط را پیشنهاد می‌دهد</div>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);background:#fff;border-radius:12px">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--success);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700">۳</div>
                <div>
                  <div style="font-weight:600">اسباب‌کشی و تحویل کالا</div>
                  <div class="fs-sm" style="color:var(--text-muted)">کالا توسط سواَپین بررسی و به خریدار تحویل داده می‌شود</div>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-4);background:#fff;border-radius:12px">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--warning);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700">۴</div>
                <div>
                  <div style="font-weight:600">پرداخت ماهانه و آزادسازی کالا</div>
                  <div class="fs-sm" style="color:var(--text-muted)">پس از آخرین قسط، سند مالکیت نهایی تحویل خریدار می‌گردد</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Use Cases -->
    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-10)">
        <h2 style="font-size:1.5rem;margin-bottom:var(--sp-6);text-align:center">
          چه مواردی برای مبادله قسطی مناسب‌اند؟
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--sp-5)">
          <?php
          $cases = [
            ['icon' => 'bi-car-front-fill',    'color' => 'primary',     'title' => 'خودرو',           'desc' => 'تجارت پراید با پژو، ایران خودرو با صبا و هر خودروی دیگری با پرداخت تفاوت به اقساط ۶ تا ۲۴ ماهه.'],
            ['icon' => 'bi-house-heart-fill',  'color' => 'accent-dark', 'title' => 'املاک و مسکونی', 'desc' => 'مبادله زمین، آپارتمان یا واحد تجاری با توجه به سند و سابقه اعتباری خریدار.'],
            ['icon' => 'bi-phone-fill',        'color' => 'info',        'title' => 'کالاهای دیجیتال', 'desc' => 'گوشی پرچمدار، لپ‌تاپ، کنسول بازی و تجهیزات گران‌قیمت با اقساط ۳ تا ۱۲ ماهه.'],
            ['icon' => 'bi-truck-flatbed',     'color' => 'success',     'title' => 'لوازم خانگی بزرگ', 'desc' => 'یخچال، ماشین لباسشویی، کولر گازی و مبلمان منزل با پرداخت اقساطی آسان.'],
            ['icon' => 'bi-music-player-fill', 'color' => 'warning',     'title' => 'سرگرمی و بازی',   'desc' => 'پیانو، تجهیزات استودیویی، دوچرخه‌های گران‌قیمت و تجهیزات ورزشی حرفه‌ای.'],
            ['icon' => 'bi-briefcase-fill',    'color' => 'primary',     'title' => 'خدمات بلندمدت',  'desc' => 'مبادله خدمات آموزشی، فنی یا اداری ماهانه به صورت تعاونی بین ارائه‌دهندگان.'],
          ];
          foreach ($cases as $c):
          ?>
          <div style="background:var(--bg);padding:var(--sp-5);border-radius:18px;border:1px solid var(--border);transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='0 10px 28px rgba(10,37,64,.08)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
            <div style="width:56px;height:56px;border-radius:16px;background:rgba(26,107,74,.08);display:flex;align-items:center;justify-content:center;margin-bottom:var(--sp-4)">
              <i class="bi <?= $c['icon'] ?>" style="font-size:1.5rem;color:var(--<?= $c['color'] ?>)"></i>
            </div>
            <h3 style="font-size:1.125rem;margin:0 0 var(--sp-2);font-weight:700"><?= $c['title'] ?></h3>
            <p style="color:var(--text-secondary);line-height:1.8;margin:0;font-size:.9375rem"><?= $c['desc'] ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- How It Works Steps -->
    <div class="card mb-6" id="how" style="border-radius:20px;overflow:hidden">
      <div class="card-body" style="padding:var(--sp-10)">
        <h2 style="font-size:1.5rem;margin-bottom:var(--sp-7);text-align:center;margin-bottom: 30px;">
          <i class="bi bi-diagram-3" style="color:var(--primary)"></i> مراحل انجام مبادله قسطی
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--sp-6)">
          <?php
          $steps = [
            [
              'num'   => '۱',
              'icon'  => 'bi-pencil-square',
              'color' => 'primary',
              'title' => 'انتخاب گزینه اقساطی در ثبت آگهی',
              'desc'  => 'هنگام ثبت آگهی خود، در بخش نوع معامله گزینه «قابل معاوضه با اقساط» را فعال کرده و تعداد ماه و پیش پرداخت را وارد کنید.',
            ],
            [
              'num'   => '۲',
              'icon'  => 'bi-send-fill',
              'color' => 'accent-dark',
              'title' => 'پیشنهاد دادن یا دریافت قسطی',
              'desc'  => 'پیشنهادهای قسطی کاربران را بررسی کنید یا برای یک آگهی پیشنهاد خودتان را با تعداد اقساط و مبلغ ماهانه ارسال کنید.',
            ],
            [
              'num'   => '۳',
              'icon'  => 'bi-handshake-fill',
              'color' => 'success',
              'title' => 'توافق و تأیید طرفین',
              'desc'  => 'هر دو طرف شرایط قسطی (پیش پرداخت، مبلغ ماهانه، تعداد قسط، جریمه تاخیر) را در اتاق معامله تأیید می‌کنند.',
            ],
            [
              'num'   => '۴',
              'icon'  => 'bi-box-seam-fill',
              'color' => 'warning',
              'title' => 'اسباب‌کشی کالا توسط سواَپین',
              'desc'  => 'کالای مورد معامله به صورت حضوری یا از طریق پست به امانت سواَپین تحویل داده می‌شود و کیفیت آن تایید می‌گردد.',
            ],
            [
              'num'   => '۵',
              'icon'  => 'bi-calendar2-week-fill',
              'color' => 'info',
              'title' => 'پرداخت‌های ماهانه منظم',
              'desc'  => 'خریدار اقساط را در سررسید مشخص از طریق کیف پول سواَپین پرداخت می‌کند و هر ماه اطلاع‌رسانی پیامکی دریافت می‌نماید.',
            ],
            [
              'num'   => '۶',
              'icon'  => 'bi-patch-check-fill',
              'color' => 'primary',
              'title' => 'آزادسازی نهایی',
              'desc'  => 'پس از واریز کامل آخرین قسط، سند مالکیت یا تحویل نهایی کالا توسط سواَپین به خریدار آزاد می‌گردد.',
            ],
          ];
          foreach ($steps as $s):
          ?>
          <div>
            <div style="position:relative;margin-bottom:var(--sp-3)">
              <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--<?= $s['color'] ?>),#38b2ac);display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;color:#fff;box-shadow:0 6px 16px rgba(26,107,74,.14)">
                <?= $s['num'] ?>
              </div>
              <i class="bi <?= $s['icon'] ?>" style="position:absolute;bottom:-4px;right:38px;font-size:1.25rem;color:var(--<?= $s['color'] ?>);background:#fff;border-radius:50%;padding:4px;box-shadow:0 2px 8px rgba(0,0,0,.08)"></i>
            </div>
            <h3 style="font-size:1rem;margin:0 0 var(--sp-2);font-weight:700"><?= $s['title'] ?></h3>
            <p class="fs-sm" style="color:var(--text-secondary);line-height:1.7;margin:0"><?= $s['desc'] ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Security & Escrow -->
    <div class="card mb-6" style="background:linear-gradient(135deg,rgba(25,135,84,.04),rgba(13,110,253,.05));border:1px solid rgba(25,135,84,.12);border-radius:20px">
      <div class="card-body" style="padding:var(--sp-10)">
        <h2 style="font-size:1.5rem;margin-bottom:var(--sp-6);text-align:center">
          <i class="bi bi-shield-lock-fill" style="color:var(--success)"></i> چرا مبادله قسطی در سواَپین امن است؟
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--sp-5)">
          <div style="display:flex;gap:var(--sp-4);align-items:flex-start;background:rgba(255,255,255,.6);padding:var(--sp-5);border-radius:16px;border:1px solid rgba(25,135,84,.1)">
            <div style="width:48px;height:48px;border-radius:14px;background:rgba(25,135,84,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-box-seam-fill" style="font-size:1.375rem;color:var(--success)"></i>
            </div>
            <div>
              <div style="font-weight:700;margin-bottom:6px">اسباب‌کشی و بازرسی فیزیکی</div>
              <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.7">قبل از شروع اقساط، کالا توسط تیم سواَپین از نظر فیزیکی و اسناد بررسی می‌شود تا مغایرتی در تحویل نهایی نداشته باشید.</p>
            </div>
          </div>
          <div style="display:flex;gap:var(--sp-4);align-items:flex-start;background:rgba(255,255,255,.6);padding:var(--sp-5);border-radius:16px;border:1px solid rgba(25,135,84,.1)">
            <div style="width:48px;height:48px;border-radius:14px;background:rgba(13,110,253,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-bell-fill" style="font-size:1.375rem;color:var(--info)"></i>
            </div>
            <div>
              <div style="font-weight:700;margin-bottom:6px">یادآوری سررسید و پیگیری</div>
              <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.7">۳ روز قبل از سررسید هر قسط، پیامک و اعلان در سیستم برای خریدار ارسال می‌شود تا از تأخیر پرداخت جلوگیری گردد.</p>
            </div>
          </div>
          <div style="display:flex;gap:var(--sp-4);align-items:flex-start;background:rgba(255,255,255,.6);padding:var(--sp-5);border-radius:16px;border:1px solid rgba(25,135,84,.1)">
            <div style="width:48px;height:48px;border-radius:14px;background:rgba(220,53,69,.08);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-arrow-repeat" style="font-size:1.375rem;color:#dc3545"></i>
            </div>
            <div>
              <div style="font-weight:700;margin-bottom:6px">گزینه بازگشت کالا</div>
              <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.7">چنانچه خریدار بیش از دو سررسید متوالی اقساط را پرداخت نکند، کالا به فروشنده بازگردانده می‌شود و از امتیاز خریدار کسر می‌گردد.</p>
            </div>
          </div>
          <div style="display:flex;gap:var(--sp-4);align-items:flex-start;background:rgba(255,255,255,.6);padding:var(--sp-5);border-radius:16px;border:1px solid rgba(25,135,84,.1)">
            <div style="width:48px;height:48px;border-radius:14px;background:rgba(255,193,7,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-stars" style="font-size:1.375rem;color:var(--warning)"></i>
            </div>
            <div>
              <div style="font-weight:700;margin-bottom:6px">امتیاز اعتماد قسطی</div>
              <p class="fs-sm" style="color:var(--text-muted);margin:0;line-height:1.7">هر پرداخت به‌موقع، امتیاز «پرداخت‌مند» کاربر را افزایش می‌دهد و در مبادلات قسطی آتی به عنوان سابقه مثبت ثبت می‌گردد.</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Commission Info -->
    <div class="card mb-6" style="border-left:4px solid var(--accent-dark)">
      <div class="card-body" style="padding:var(--sp-10)">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-4)">
          <i class="bi bi-cash-stack" style="color:var(--accent-dark)"></i> هزینه مبادله قسطی در سواَپین
        </h2>
        <p style="color:var(--text-secondary);line-height:2;margin-bottom:var(--sp-5);font-size:1rem">
          کمیسیون پلتفرم در مبادله قسطی، دقیقاً مشابه معامله عادی سواَپین محاسبه می‌شود؛ یعنی بر اساس درصد اختصاصی دسته‌بندی کالا (مثلاً ۱.۲۵٪ برای خودرو و ۰.۷۵٪ برای ملک) و تنها یک‌بار در شروع معامله دریافت می‌گردد.
        </p>
        <div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:var(--sp-4)">
          <?php
          $fees = [
            ['auto', 'خودرو',       '۱.۲۵٪', 'bi-car-front-fill', 'primary'],
            ['home', 'املاک',         '۰.۷۵٪', 'bi-house-fill',     'accent-dark'],
            ['elec', 'الکترونیک',     '۳.۵٪',  'bi-phone-fill',     'info'],
            ['home_k', 'خانه و آشپزخانه', '۳٪', 'bi-house-gear-fill','success'],
            ['serv', 'خدمات',         '۵٪',    'bi-person-gear',    'warning'],
            ['def',  'سایر دسته‌ها', '۱٪',     'bi-collection',     'primary'],
          ];
          foreach ($fees as [$k, $label, $pct, $icon, $color]):
          ?>
          <div style="padding:var(--sp-4);border-radius:14px;background:var(--bg);text-align:center;border:1px solid var(--border)">
            <div style="width:44px;height:44px;border-radius:12px;background:rgba(26,107,74,.08);display:flex;align-items:center;justify-content:center;margin:0 auto var(--sp-3)">
              <i class="bi <?= $icon ?>" style="font-size:1.125rem;color:var(--<?= $color ?>)"></i>
            </div>
            <div style="font-weight:600;margin-bottom:4px"><?= $label ?></div>
            <div style="font-size:1.5rem;font-weight:800;color:var(--<?= $color ?>)"><?= $pct ?></div>
            <div class="fs-xs" style="color:var(--text-muted);margin-top:4px">بدون سود قسطی</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- FAQ -->
    <div class="card mb-8" id="faq">
      <div class="card-body" style="padding:var(--sp-10)">
        <h2 style="font-size:1.375rem;margin-bottom:var(--sp-6);text-align:center">
          <i class="bi bi-question-circle-fill" style="color:var(--info)"></i> سوالات متداول مبادله قسطی
        </h2>
        <div style="max-width:820px;margin:0 auto;display:flex;flex-direction:column;gap:var(--sp-3)">
          <?php
          $faqs = [
            [
              'q' => 'مبادله قسطی در سواَپین چیست؟',
              'a' => 'مبادله قسطی نوعی معامله است که در آن کالا یا خدمتی با پرداخت مبلغ تفاضلی به صورت اقساط ماهانه معاوضه می‌شود. سواَپین با اسباب‌کشی امن، کنترل سررسید اقساط و امتیازدهی به پرداخت به موقع، این فرآیند را بدون ریسک انجام می‌دهد.',
            ],
            [
              'q' => 'آیا اقساط در سواَپین سود دارد؟',
              'a' => 'خیر. درصد کمیسیون پلتفرم طبق دسته‌بندی کالا محاسبه می‌شود و سود اضافی برای اقساط دریافت نمی‌شود. مبلغ تفاضلی دقیقاً همان مبلغ توافق‌شده بین طرفین است.',
            ],
            [
              'q' => 'در صورت پرداخت نشدن قسط چه می‌شود؟',
              'a' => 'در ابتدای معامله، کالا توسط سواَپین اسباب‌کشی می‌شود. اگر طرف خریدار قسط را سررسید پرداخت نکند، پس از اطلاع‌رسانی و مهلت قانونی، کالا به فروشنده بازگردانده می‌شود و امتیاز اعتماد خریدار کسر می‌گردد. در صورت جبران دیرکرد نیز جریمه‌ای طبق توافق اولیه محاسبه می‌گردد.',
            ],
            [
              'q' => 'برای کدام کالاها می‌توان از مبادله قسطی استفاده کرد؟',
              'a' => 'تمام کالاهای دارای ارزش بالا شامل خودرو، دوچرخه، مبلمان منزل، لوازم خانگی بزرگ، لپ‌تاپ و گوشی‌های پرچمدار، و حتی خدمات طولانی‌مدت را می‌توان با روش اقساطی معاوضه کرد. املاک نیز با سند امانی در این طرح قرار می‌گیرند.',
            ],
            [
              'q' => 'آیا پیش پرداخت الزامی است؟',
              'a' => 'پیش پرداخت الزامی نیست و کاملاً توسط فروشنده مشخص می‌گردد. این مبلغ می‌تواند صفر درصد تا ۵۰ درصد ارزش تفاضلی باشد و در نهایت توافق طرفین است.',
            ],
            [
              'q' => 'چگونه می‌توانم آگهی خود را با گزینه اقساطی ثبت کنم؟',
              'a' => 'در فرم ثبت آگهی جدید سواَپین، در مرحله نوع معامله گزینه «تمایل به اقساط داشتن تفاوت ارزش» را فعال کنید و تعداد ماه و میزان پیش پرداخت دلخواه را وارد نمایید.',
            ],
          ];
          foreach ($faqs as $i => $faq):
              $itemId  = 'faq-item-'  . ($i + 1);
              $btnId   = 'faq-btn-'   . ($i + 1);
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

    <!-- CTA -->
    <div style="text-align:center;padding-bottom:var(--sp-10)">
      <h2 style="font-size:1.5rem;margin-bottom:var(--sp-3)">آماده شروع اولین مبادله قسطی خود هستید؟</h2>
      <p style="color:var(--text-muted);margin-bottom:var(--sp-6);font-size:1.0625rem">با ثبت اولین آگهی اقساطی خود، امتیاز ویژه «تاجر مقدماتی» سواَپین را دریافت کنید.</p>
      <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
        <?php if ($user): ?>
        <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary btn-lg" style="padding:14px 32px;font-size:1.0625rem;border-radius:14px"><i class="bi bi-plus-lg"></i> ثبت آگهی اقساطی</a>
        <a href="<?= APP_URL ?>/listings/all.php" class="btn btn-outline btn-lg" style="border-radius:14px;padding:14px 32px"><i class="bi bi-grid"></i> مرور آگهی‌های اقساطی</a>
        <?php else: ?>
        <a href="<?= APP_URL ?>/auth/login" class="btn btn-primary btn-lg" style="padding:14px 32px;font-size:1.0625rem;border-radius:14px"><i class="bi bi-person-plus"></i> ورود / ثبت‌نام رایگان</a>
        <a href="<?= APP_URL ?>/" class="btn btn-outline btn-lg" style="border-radius:14px;padding:14px 32px"><i class="bi bi-arrow-left-right"></i> آشنایی با نحوه کار سواَپین</a>
        <?php endif; ?>
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
