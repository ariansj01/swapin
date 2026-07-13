<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';

$user = require_auth();
$uid  = (int)$user['id'];

$listingId = (int)($_GET['id'] ?? 0);

$listing = DB::fetch(
    'SELECT l.*,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb,
            (SELECT COUNT(*) FROM saved_listings WHERE listing_id = l.id) AS saved_count,
            (SELECT COUNT(*) FROM trade_offers WHERE listing_id = l.id) AS offers_count
     FROM listings l
     WHERE l.id = ? AND l.user_id = ? AND l.status = "active"',
    [$listingId, $uid]
);

if (!$listing) {
    header('Location: ' . APP_URL . '/listings/my.php');
    exit;
}

$planFeatures = [
    'نمایش در صفحه اول',
    'افزایش بازدید',
    'نمایش ویژه',
    'گزارش عملکرد',
    'اولویت در جستجو',
    'افزایش فروش',
];

$plans = [
    'boost' => [
        'name'        => 'Boost',
        'icon'        => 'bi-send',
        'color'       => 'purple',
        'header'      => 'purple',
        'badge'       => null,
        'badge_class' => '',
        'price'       => 50000,
        'duration'    => '24 ساعت',
        'description' => 'آگهی در بالاترین جایگاه لیست قرار می‌گیرد',
    ],
    'featured' => [
        'name'        => 'HOT',
        'icon'        => 'bi-fire',
        'color'       => 'orange',
        'header'      => 'orange',
        'badge'       => null,
        'badge_class' => '',
        'price'       => 100000,
        'duration'    => '7 روز',
        'description' => 'نمایش در بخش آگهی‌های ویژه با برچسب داغ',
    ],
    'vip' => [
        'name'        => 'VIP',
        'icon'        => 'bi-award',
        'color'       => 'violet',
        'header'      => 'violet',
        'badge'       => 'محبوب',
        'badge_class' => 'popular',
        'price'       => 200000,
        'duration'    => '14 روز',
        'description' => 'نمایش در صفحه اول، اولویت در نتایج جستجو',
    ],
    'targeted' => [
        'name'        => 'هدفمند',
        'icon'        => 'bi-bullseye',
        'color'       => 'green',
        'header'      => 'green',
        'badge'       => 'NEW',
        'badge_class' => 'new',
        'price'       => 150000,
        'duration'    => '7 روز',
        'description' => 'نمایش به مخاطبان مرتبط بر اساس شهر و دسته‌بندی',
    ],
    'ai' => [
        'name'        => 'تلگرام هوشمند',
        'icon'        => 'bi-telegram',
        'color'       => 'blue',
        'header'      => 'blue',
        'badge'       => 'PRO',
        'badge_class' => 'pro',
        'price'       => 250000,
        'duration'    => '7 روز',
        'description' => 'ارسال هوشمند آگهی به مخاطبان دقیقاً مرتبط',
    ],
    'gold' => [
        'name'        => 'طلایی',
        'icon'        => 'bi-star-fill',
        'color'       => 'gold',
        'header'      => 'gold',
        'badge'       => 'پیشنهاد ویژه',
        'badge_class' => 'special',
        'price'       => 500000,
        'duration'    => '14 روز',
        'description' => 'شامل تمام امکانات: Boost, HOT, VIP, هدفمند و هوشمند',
        'featured'    => true,
    ],
];

$durationHours = [
    'boost'    => 24,
    'featured' => 24 * 7,
    'vip'      => 24 * 14,
    'targeted' => 24 * 7,
    'ai'       => 24 * 7,
    'gold'     => 24 * 14,
];

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('listing_promotion', 10, 3600);

    $plan = clean($_POST['plan'] ?? '');
    if (!isset($plans[$plan])) {
        $error = 'پلن انتخاب‌شده نامعتبر است';
    } else {
        $planData = $plans[$plan];
        $price    = $planData['price'];

        if ((float)$user['credit_balance'] < $price) {
            $error = 'موجودی کیف پول شما کافی نیست. <a href="' . APP_URL . '/wallet">شارژ کیف پول</a>';
        } else {
            credit_transact($uid, 'listing_promotion', -$price, 'پرداخت برای پلن ' . $planData['name'], [
                'ref_type'   => 'listing',
                'ref_id'     => $listingId,
                'listing_id' => $listingId,
            ]);

            $endsAt = date('Y-m-d H:i:s', time() + $durationHours[$plan] * 3600);

            DB::insert('listing_promotions', [
                'listing_id'  => $listingId,
                'user_id'     => $uid,
                'plan'        => $plan,
                'starts_at'   => date('Y-m-d H:i:s'),
                'ends_at'     => $endsAt,
                'amount_paid' => $price,
            ]);

            $updateData = [];
            if ($plan === 'boost' || $plan === 'gold') {
                $updateData['bump_until'] = $endsAt;
            }
            if ($plan === 'featured' || $plan === 'gold') {
                $updateData['featured_until'] = $endsAt;
                $updateData['is_featured']      = 1;
            }
            if ($plan === 'vip' || $plan === 'gold') {
                $updateData['vip_until'] = $endsAt;
            }
            if ($plan === 'targeted' || $plan === 'gold') {
                $updateData['targeted_until'] = $endsAt;
            }
            if ($plan === 'ai' || $plan === 'gold') {
                $updateData['ai_promo_until'] = $endsAt;
            }

            if (!empty($updateData)) {
                DB::update('listings', $updateData, 'id = ?', [$listingId]);
            }

            $user = DB::fetch('SELECT * FROM users WHERE id = ? AND is_active = 1', [$uid]);
            $success = 'پلن «' . $planData['name'] . '» با موفقیت فعال شد!';
        }
    }
}

$thumbUrl = !empty($listing['thumb']) ? UPLOAD_URL . $listing['thumb'] : '';
$growthPct = min(999, max(50, (int)$listing['views'] * 2 + (int)$listing['offers_count'] * 40));

$activePromos = DB::fetchAll(
    'SELECT plan, ends_at FROM listing_promotions
     WHERE listing_id = ? AND ends_at > NOW()
     ORDER BY ends_at DESC',
    [$listingId]
);

ob_start();
?>

<div class="promote-page">

  <?php if ($success): ?>
  <div class="promote-alert promote-alert--success"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="promote-alert promote-alert--error"><i class="bi bi-exclamation-circle"></i> <?= $error ?></div>
  <?php endif; ?>

  <?php if ($activePromos): ?>
  <div class="promote-alert promote-alert--success">
    <i class="bi bi-lightning-charge"></i>
    پلن‌های فعال:
    <?php foreach ($activePromos as $i => $ap): ?>
      <?= $i > 0 ? '، ' : '' ?><?= h($plans[$ap['plan']]['name'] ?? $ap['plan']) ?>
      (تا <?= persian_date($ap['ends_at']) ?>)
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="promote-header">
    <div>
      <a href="<?= APP_URL ?>/listings/my" class="promote-back-link">
        <i class="bi bi-arrow-right"></i> بازگشت به آگهی‌های من
      </a>
      <h1 class="promote-header__title"><i class="bi bi-rocket-takeoff"></i> ارتقای آگهی</h1>
      <p class="promote-header__desc">
        با ارتقای آگهی «<?= h(mb_strimwidth($listing['title'], 0, 50, '…')) ?>» بازدید بیشتری بگیرید و سریع‌تر معامله کنید.
      </p>
    </div>
    <div class="promote-header__actions">
      <span class="promote-wallet-chip">
        <i class="bi bi-wallet2"></i> <?= fmt_credit((float)$user['credit_balance']) ?>
      </span>
      <a href="<?= APP_URL ?>/support" class="promote-guide-btn">
        <i class="bi bi-book"></i> راهنمای ارتقا
      </a>
    </div>
  </div>

  <!-- Benefits -->
  <div class="promote-benefits">
    <div class="promote-benefits__grid">
      <div class="promote-benefit">
        <div class="promote-benefit__icon promote-benefit__icon--eye"><i class="bi bi-eye"></i></div>
        <h4>دیده شدن بیشتر</h4>
        <p>آگهی شما در جایگاه‌های برتر نمایش داده می‌شود</p>
      </div>
      <div class="promote-benefit">
        <div class="promote-benefit__icon promote-benefit__icon--rocket"><i class="bi bi-rocket"></i></div>
        <h4>فروش سریع‌تر</h4>
        <p>احتمال دریافت پیشنهاد تا ۷۰٪ افزایش می‌یابد</p>
      </div>
      <div class="promote-benefit">
        <div class="promote-benefit__icon promote-benefit__icon--shield"><i class="bi bi-shield-check"></i></div>
        <h4>اعتماد بیشتر</h4>
        <p>نشان ویژه اعتماد خریداران را جلب می‌کند</p>
      </div>
      <div class="promote-benefit">
        <div class="promote-benefit__icon promote-benefit__icon--chart"><i class="bi bi-bar-chart-line"></i></div>
        <h4>گزارش اختصاصی</h4>
        <p>آمار بازدید و عملکرد آگهی را دنبال کنید</p>
      </div>
    </div>
  </div>

  <!-- Plans -->
  <section>
    <h2 class="promote-section-title">روش‌های ارتقای آگهی</h2>
    <div class="promote-plans">
      <?php foreach ($plans as $key => $plan):
          $isGold = !empty($plan['featured']);
          $cardCls = 'promote-plan' . ($isGold ? ' promote-plan--gold' : '');
      ?>
      <article class="<?= $cardCls ?>">
        <div class="promote-plan__header promote-plan__header--<?= h($plan['header']) ?>"></div>
        <div class="promote-plan__body">
          <div class="promote-plan__icon promote-plan__icon--<?= h($plan['color']) ?>">
            <i class="bi <?= h($plan['icon']) ?>"></i>
          </div>
          <h3 class="promote-plan__name"><?= h($plan['name']) ?></h3>
          <?php if ($plan['badge']): ?>
          <span class="promote-plan__badge promote-plan__badge--<?= h($plan['badge_class']) ?>"><?= h($plan['badge']) ?></span>
          <?php endif; ?>
          <p class="promote-plan__desc"><?= h($plan['description']) ?></p>

          <ul class="promote-plan__features">
            <?php foreach ($planFeatures as $feat): ?>
            <li><i class="bi bi-check-circle-fill"></i> <?= h($feat) ?></li>
            <?php endforeach; ?>
          </ul>

          <div class="promote-plan__divider"></div>

          <div class="promote-plan__duration">
            <label>انتخاب مدت</label>
            <select disabled aria-label="مدت پلن">
              <option selected><?= h($plan['duration']) ?></option>
            </select>
          </div>

          <div class="promote-plan__price"><?= fmt_credit($plan['price']) ?></div>

          <form method="POST" class="promote-plan__form"
                data-plan-name="<?= h($plan['name']) ?>"
                data-plan-price="<?= h(fmt_credit($plan['price'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="plan" value="<?= h($key) ?>">
            <button type="submit" class="promote-plan__btn <?= $isGold ? 'promote-plan__btn--gold' : 'promote-plan__btn--default' ?>">
              <i class="bi bi-check2"></i> انتخاب
            </button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Banner -->
  <div class="promote-banner">
    <div class="promote-banner__icon"><i class="bi bi-graph-up-arrow"></i></div>
    <p>با ارتقای آگهی، احتمال فروش کالای شما تا <strong>۷۰٪</strong> افزایش پیدا می‌کند.</p>
  </div>

  <!-- Bottom -->
  <div class="promote-bottom">
    <!-- Performance -->
    <div class="promote-card">
      <h3 class="promote-card__title"><i class="bi bi-speedometer2"></i> عملکرد آگهی</h3>
      <div class="promote-performance__product">
        <?php if ($thumbUrl): ?>
        <img src="<?= h($thumbUrl) ?>" alt="<?= h($listing['title']) ?>" class="promote-performance__thumb">
        <?php else: ?>
        <div class="promote-performance__thumb promote-performance__thumb--empty"><i class="bi bi-image"></i></div>
        <?php endif; ?>
        <div>
          <p class="promote-performance__name"><?= h($listing['title']) ?></p>
          <span class="promote-performance__meta">آگهی #<?= (int)$listing['id'] ?></span>
        </div>
      </div>

      <div class="promote-stats">
        <div class="promote-stat">
          <span class="promote-stat__value"><?= number_format((int)$listing['views']) ?></span>
          <span class="promote-stat__label">بازدید</span>
        </div>
        <div class="promote-stat">
          <span class="promote-stat__value"><?= number_format((int)$listing['saved_count']) ?></span>
          <span class="promote-stat__label">علاقه‌مندی</span>
        </div>
        <div class="promote-stat">
          <span class="promote-stat__value"><?= number_format((int)$listing['offers_count']) ?></span>
          <span class="promote-stat__label">پیشنهاد</span>
        </div>
      </div>

      <div class="promote-growth">
        <div class="promote-growth__chart" aria-hidden="true">
          <?php
          $bars = [30, 45, 38, 55, 48, 70, 65, 85, 78, 100];
          foreach ($bars as $h):
          ?>
          <div class="promote-growth__bar" style="height:<?= $h ?>%"></div>
          <?php endforeach; ?>
        </div>
        <div class="promote-growth__pct">
          +<?= $growthPct ?>%
          <small>رشد بازدید</small>
        </div>
      </div>
    </div>

    <!-- FAQ + Support -->
    <div>
      <div class="promote-card promote-faq">
        <h3 class="promote-card__title"><i class="bi bi-question-circle"></i> سوالات متداول</h3>
        <div class="promote-accordion">
          <div class="promote-accordion__item">
            <button type="button" class="promote-accordion__trigger">
              ارتقای آگهی چه تأثیری دارد؟
              <i class="bi bi-chevron-down"></i>
            </button>
            <div class="promote-accordion__body">
              آگهی ارتقا یافته در جایگاه‌های بالاتر نمایش داده می‌شود و بازدید و احتمال دریافت پیشنهاد را افزایش می‌دهد.
            </div>
          </div>
          <div class="promote-accordion__item">
            <button type="button" class="promote-accordion__trigger">
              پرداخت از کجا انجام می‌شود؟
              <i class="bi bi-chevron-down"></i>
            </button>
            <div class="promote-accordion__body">
              هزینه از موجودی کیف پول شما کسر می‌شود. در صورت کمبود موجودی، ابتدا کیف پول را شارژ کنید.
            </div>
          </div>
          <div class="promote-accordion__item">
            <button type="button" class="promote-accordion__trigger">
              آیا می‌توانم چند پلن همزمان فعال کنم؟
              <i class="bi bi-chevron-down"></i>
            </button>
            <div class="promote-accordion__body">
              بله، می‌توانید پلن‌های مختلف را برای یک آگهی فعال کنید. پکیج طلایی شامل تمام امکانات است.
            </div>
          </div>
        </div>
      </div>

      <div class="promote-card promote-support">
        <h4>نیاز به راهنمایی دارید؟</h4>
        <p>تیم پشتیبانی سواپین آماده کمک به شما در انتخاب بهترین پلن ارتقا است.</p>
        <a href="<?= APP_URL ?>/support" class="promote-support__btn">
          <i class="bi bi-headset"></i> تماس با پشتیبانی
        </a>
      </div>
    </div>
  </div>

</div>

<?php
$content = ob_get_clean();

render_head('ارتقای آگهی', 'ارتقای آگهی و افزایش بازدید در سواپین', ['robots' => 'noindex, nofollow']);
render_panel_styles();
render_navbar($user);
render_user_panel_open($user, 'promote', [
    'promote' => APP_URL . '/listings/promote?id=' . $listingId,
]);
echo $content;
render_user_panel_close();
render_panel_scripts(['src/js/promote.js']);
render_footer();
