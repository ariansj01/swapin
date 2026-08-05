<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user   = require_auth();
$uid    = (int)$user['id'];
$isStore = is_store_seller($user);
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_listings'])) {
    csrf_verify_or_fail();
    if (!$isStore) {
        $error = 'برای ثبت گروهی، نوع حساب خود را در پروفایل به «فروشگاه» تغییر دهید.';
    } else {
        $lines   = array_filter(array_map('trim', explode("\n", $_POST['bulk_listings'] ?? '')));
        $created = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if (!can_create_listing($user)) {
                $skipped++;
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 4) {
                $skipped++;
                continue;
            }

            [$title, $catSlug, $value, $want] = $parts;
            $cat = DB::fetch('SELECT id FROM categories WHERE slug = ? AND is_active = 1', [clean($catSlug)]);
            if (!$cat || mb_strlen($title) < 5 || mb_strlen($want) < 5) {
                $skipped++;
                continue;
            }

            $description = clean($title) . ' — ثبت‌شده از پنل فروشگاه.';
            $contentErrors = validate_listing_content([
                'title'           => $title,
                'description'     => $description,
                'want_in_return'  => $want,
            ]);
            if (!empty($contentErrors)) {
                $skipped++;
                continue;
            }

            DB::insert('listings', [
                'user_id'         => $uid,
                'category_id'     => (int)$cat['id'],
                'title'           => clean($title),
                'description'     => $description,
                'condition'       => 'good',
                'estimated_value' => max(0, (float)$value),
                'want_in_return'  => clean($want),
                'want_type'       => 'item',
                'listing_mode'    => 'swap',
                'sell_price'      => 0,
                'city'            => $user['city'] ?? null,
                'status'          => 'active',
                'review_status'   => 'pending',
            ]);
            $created++;
        }

        if ($created > 0) {
            $success = "$created آگهی ثبت شد." . ($skipped ? " ($skipped ردیف رد یا نادیده گرفته شد)" : '');
        } else {
            $error = 'هیچ آگهی‌ای ثبت نشد. فرمت یا سقف آگهی را بررسی کنید.';
        }
    }
}

$activeCount = can_create_listing_count($user);
$limit       = get_listing_limit($user);
$inventory   = DB::fetchAll(
    'SELECT l.*, c.name AS cat_name, c.slug AS cat_slug,
            (SELECT COUNT(*) FROM trade_offers WHERE listing_id = l.id AND status = "pending") AS pending_offers
     FROM listings l
     JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active"
     ORDER BY l.created_at DESC',
    [$uid]
);
$totalValue = array_sum(array_map(fn($r) => (float)$r['estimated_value'], $inventory));

$pendingBuyOffers = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.offer_type = "buy" AND o.status = "pending"',
    [$uid]
)['c'] ?? 0);

$pendingSwapOffers = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.offer_type = "swap" AND o.status = "pending"',
    [$uid]
)['c'] ?? 0);

$completedTrades = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "accepted"',
    [$uid]
)['c'] ?? 0);

$totalVisits = 0;
foreach ($inventory as $item) {
    $totalVisits += (int)($item['view_count'] ?? 0);
}

$notifications = [
    ['icon' => 'bi-arrow-left-right', 'text' => 'یک درخواست معاوضه جدید برای «مبل راحتی» دریافت کردید', 'time' => '۱۰ دقیقه پیش'],
    ['icon' => 'bi-heart-fill', 'text' => 'محصول شما «لپ‌تاپ Dell» توسط ۳ کاربر پسندیده شد', 'time' => '۱ ساعت پیش'],
    ['icon' => 'bi-lightning-charge', 'text' => 'پیشنهاد جدید برای «گوشی iPhone ۱۵» دریافت کردید', 'time' => '۳ ساعت پیش'],
];

$chartData = [12, 19, 25, 22, 30, 28, 35, 42, 38, 45, 50, 48];

render_head('پنل فروشگاه');
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store.css">

<div class="store-page">
  <div class="store-container">

    <div class="store-header">
      <div class="store-header__info">
        <div class="store-header__icon">
          <i class="bi bi-shop"></i>
        </div>
        <div>
          <h1 class="store-header__title">پنل فروشگاه</h1>
          <p class="store-header__subtitle">مدیریت کامل فروشگاه، محصولات، درخواست‌ها و آمار فروش</p>
        </div>
      </div>
      <div class="store-header__actions">
        <?php if ($isStore && !empty($user['store_name'])): ?>
        <span class="store-badge-gold"><i class="bi bi-building"></i> <?= h($user['store_name']) ?></span>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/listings/create" class="store-btn store-btn--gradient">
          <i class="bi bi-plus-lg"></i> افزودن محصول جدید
        </a>
      </div>
    </div>

    <?php if (!$isStore): ?>
    <div class="store-alert store-alert--info">
      <i class="bi bi-info-circle-fill"></i>
      <div>
        برای استفاده از پنل فروشگاه، در
        <a href="<?= APP_URL ?>/profile/edit.php">ویرایش پروفایل</a>
        نوع حساب را «فروشگاه / کسب‌وکار» انتخاب کنید.
      </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="store-alert store-alert--success"><i class="bi bi-check-circle-fill"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="store-alert store-alert--danger"><i class="bi bi-exclamation-circle-fill"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div class="store-tabs">
      <button class="store-tab is-active" data-tab="dashboard">
        <i class="bi bi-speedometer2"></i> داشبورد اصلی
      </button>
      <button class="store-tab" data-tab="products">
        <i class="bi bi-box-seam"></i> محصولات
      </button>
      <button class="store-tab" data-tab="requests">
        <i class="bi bi-inbox"></i> درخواست‌ها
        <?php if ($pendingBuyOffers + $pendingSwapOffers > 0): ?>
        <span class="store-tab__badge"><?= $pendingBuyOffers + $pendingSwapOffers ?></span>
        <?php endif; ?>
      </button>
      <button class="store-tab" data-tab="messages">
        <i class="bi bi-chat-dots"></i> پیام‌ها
      </button>
      <button class="store-tab" data-tab="management">
        <i class="bi bi-gear"></i> مدیریت فروشگاه
      </button>
      <button class="store-tab" data-tab="categories">
        <i class="bi bi-grid-3x3-gap"></i> دسته‌بندی‌ها
      </button>
      <button class="store-tab" data-tab="reports">
        <i class="bi bi-graph-up-arrow"></i> گزارش‌ها
      </button>
      <button class="store-tab" data-tab="notifications">
        <i class="bi bi-bell"></i> اعلان‌ها
      </button>
      <button class="store-tab" data-tab="settings">
        <i class="bi bi-sliders"></i> تنظیمات
      </button>
    </div>

    <!-- ========== TAB: DASHBOARD ========== -->
    <div class="store-tab-panel is-active" data-tab-panel="dashboard">

      <div class="store-stats-grid">
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--navy">
            <i class="bi bi-box-seam"></i>
          </div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= $activeCount ?> <span class="store-stat-card__total">/ <?= $limit ?></span></div>
            <div class="store-stat-card__label">تعداد محصولات فعال</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--blue">
            <i class="bi bi-eye"></i>
          </div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= number_format($totalVisits) ?></div>
            <div class="store-stat-card__label">تعداد کل بازدید</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--purple">
            <i class="bi bi-arrow-left-right"></i>
          </div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= $pendingSwapOffers ?></div>
            <div class="store-stat-card__label">درخواست‌های معاوضه</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--orange">
            <i class="bi bi-cart-check"></i>
          </div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= $pendingBuyOffers ?></div>
            <div class="store-stat-card__label">درخواست‌های خرید</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--green">
            <i class="bi bi-check-circle"></i>
          </div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= $completedTrades ?></div>
            <div class="store-stat-card__label">فروش نهایی</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--gold">
            <i class="bi bi-cash-stack"></i>
          </div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= fmt_credit($totalValue) ?></div>
            <div class="store-stat-card__label">ارزش کل موجودی</div>
          </div>
        </div>
      </div>

      <div class="store-grid-2">
        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-graph-up"></i> نمودار بازدید و درخواست‌ها</h3>
            <div class="store-card__legend">
              <span class="store-legend"><span class="store-legend__dot store-legend__dot--blue"></span> بازدید</span>
              <span class="store-legend"><span class="store-legend__dot store-legend__dot--gold"></span> درخواست‌ها</span>
            </div>
          </div>
          <div class="store-card__body">
            <div class="store-chart">
              <canvas id="storeChartCanvas" width="800" height="280"></canvas>
            </div>
            <div class="store-chart__labels">
              <span>فروردین</span><span>اردیبهشت</span><span>خرداد</span><span>تیر</span>
              <span>مرداد</span><span>شهریور</span><span>مهر</span><span>آبان</span>
              <span>آذر</span><span>دی</span><span>بهمن</span><span>اسفند</span>
            </div>
          </div>
        </div>

        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-bell"></i> اعلان‌های اخیر</h3>
            <a href="#" class="store-card__link">مشاهده همه</a>
          </div>
          <div class="store-card__body">
            <div class="store-notifications">
              <?php foreach ($notifications as $n): ?>
              <div class="store-notification">
                <div class="store-notification__icon">
                  <i class="bi <?= $n['icon'] ?>"></i>
                </div>
                <div class="store-notification__content">
                  <div class="store-notification__text"><?= $n['text'] ?></div>
                  <div class="store-notification__time"><?= $n['time'] ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="store-grid-2">
        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-stars"></i> امکانات ویژه فروشگاه</h3>
          </div>
          <div class="store-card__body">
            <div class="store-features-grid">
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-building-gear"></i></div>
                <div class="store-feature-card__title">مدیریت شعب</div>
                <div class="store-feature-card__desc">برای فروشگاه‌های زنجیره‌ای</div>
              </div>
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-upc-scan"></i></div>
                <div class="store-feature-card__title">اسکن بارکد/QR</div>
                <div class="store-feature-card__desc">ثبت سریع محصول با اسکن</div>
              </div>
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-filetype-xlsx"></i></div>
                <div class="store-feature-card__title">ثبت گروهی اکسل</div>
                <div class="store-feature-card__desc">وارد کردن لیست محصولات</div>
              </div>
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-magic"></i></div>
                <div class="store-feature-card__title">تولید توضیحات AI</div>
                <div class="store-feature-card__desc">هوش مصنوعی برای توضیحات</div>
              </div>
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-currency-exchange"></i></div>
                <div class="store-feature-card__title">قیمت بازار</div>
                <div class="store-feature-card__desc">پیشنهاد قیمت بازار کالا</div>
              </div>
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-people"></i></div>
                <div class="store-feature-card__title">تحلیل رقبا</div>
                <div class="store-feature-card__desc">قیمت کالاهای مشابه</div>
              </div>
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-star-half"></i></div>
                <div class="store-feature-card__title">امتیاز مشتریان</div>
                <div class="store-feature-card__desc">نظرات و امتیازات کاربران</div>
              </div>
              <div class="store-feature-card">
                <div class="store-feature-card__icon"><i class="bi bi-trophy"></i></div>
                <div class="store-feature-card__title">محصولات ویژه</div>
                <div class="store-feature-card__desc">تبلیغ داخل سواپین</div>
              </div>
            </div>
          </div>
        </div>

        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-wallet2"></i> داشبورد مالی</h3>
            <a href="<?= APP_URL ?>/wallet" class="store-card__link">مشاهده جزئیات</a>
          </div>
          <div class="store-card__body">
            <div class="store-financial-grid">
              <div class="store-financial-item">
                <div class="store-financial-item__value"><?= fmt_credit($totalValue) ?></div>
                <div class="store-financial-item__label">ارزش کالاهای موجود</div>
              </div>
              <div class="store-financial-item">
                <div class="store-financial-item__value"><?= $completedTrades + 5 ?></div>
                <div class="store-financial-item__label">تعداد معاملات</div>
              </div>
              <div class="store-financial-item">
                <div class="store-financial-item__value"><?= fmt_credit($totalValue * 0.15) ?></div>
                <div class="store-financial-item__label">درآمد ماهانه</div>
              </div>
              <div class="store-financial-item">
                <div class="store-financial-item__value"><?= fmt_credit($totalValue * 0.02) ?></div>
                <div class="store-financial-item__label">کمیسیون‌ها</div>
              </div>
            </div>
            <div class="store-financial-progress">
              <div class="store-financial-progress__label">
                <span>عملکرد ماهانه</span>
                <span>۷۵٪</span>
              </div>
              <div class="store-financial-progress__bar">
                <div class="store-financial-progress__fill" style="width:75%"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: PRODUCTS ========== -->
    <div class="store-tab-panel" data-tab-panel="products">
      <div class="store-grid-2">
        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-plus-circle"></i> افزودن محصول جدید</h3>
          </div>
          <div class="store-card__body">
            <div class="store-form-group">
              <label class="store-form-label">نام محصول</label>
              <input type="text" class="store-form-input" placeholder="نام محصول را وارد کنید...">
            </div>
            <div class="store-form-grid-2">
              <div class="store-form-group">
                <label class="store-form-label">دسته‌بندی</label>
                <select class="store-form-input">
                  <option>انتخاب دسته‌بندی...</option>
                  <option>💍 طلا و جواهر</option>
                  <option>📱 موبایل</option>
                  <option>💻 لپ‌تاپ</option>
                  <option>🚲 دوچرخه</option>
                  <option>📷 دوربین</option>
                  <option>🏠 لوازم منزل</option>
                  <option>🚗 خودرو</option>
                </select>
              </div>
              <div class="store-form-group">
                <label class="store-form-label">قیمت (تومان)</label>
                <input type="text" class="store-form-input" placeholder="۰">
              </div>
            </div>
            <div class="store-form-group">
              <label class="store-form-label">نوع معامله</label>
              <div class="store-radio-group">
                <label class="store-radio"><input type="radio" name="dealType"> فقط فروش</label>
                <label class="store-radio"><input type="radio" name="dealType"> فقط معاوضه</label>
                <label class="store-radio"><input type="radio" name="dealType" checked> فروش و معاوضه</label>
              </div>
            </div>
            <div class="store-form-grid-2">
              <div class="store-form-group">
                <label class="store-form-label">موجودی</label>
                <input type="number" class="store-form-input" value="1">
              </div>
              <div class="store-form-group">
                <label class="store-form-label">وضعیت</label>
                <select class="store-form-input">
                  <option>فعال</option>
                  <option>ناموجود</option>
                  <option>فروخته شد</option>
                </select>
              </div>
            </div>
            <div class="store-form-group">
              <label class="store-form-label">توضیحات محصول</label>
              <textarea class="store-form-input" rows="4" placeholder="توضیحات کامل محصول..."></textarea>
            </div>
            <button class="store-btn store-btn--gradient w-100">
              <i class="bi bi-save"></i> ذخیره محصول
            </button>

            <div class="store-divider"><span>یا ثبت گروهی</span></div>

            <div class="store-bulk-upload">
              <div class="store-bulk-upload__options">
                <button class="store-btn store-btn--outline"><i class="bi bi-filetype-xlsx"></i> آپلود اکسل</button>
                <button class="store-btn store-btn--outline"><i class="bi bi-upc-scan"></i> اسکن بارکد</button>
              </div>
            </div>
          </div>
        </div>

        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-boxes"></i> لیست محصولات (<?= count($inventory) ?>)</h3>
            <div class="store-card__filters">
              <select class="store-form-input store-form-input--sm">
                <option>همه وضعیت‌ها</option>
                <option>فعال</option>
                <option>ناموجود</option>
                <option>فروخته شد</option>
              </select>
              <input type="text" class="store-form-input store-form-input--sm" placeholder="جستجو...">
            </div>
          </div>
          <div class="store-card__body store-card__body--nopad">
            <div class="store-products">
              <?php if (empty($inventory)): ?>
              <div class="store-empty">
                <i class="bi bi-inbox"></i>
                <p>هنوز محصولی ثبت نشده</p>
              </div>
              <?php else: ?>
              <?php foreach ($inventory as $item): ?>
              <div class="store-product">
                <div class="store-product__info">
                  <div class="store-product__thumb">
                    <i class="bi bi-image"></i>
                  </div>
                  <div class="store-product__details">
                    <div class="store-product__name"><?= h(mb_strimwidth($item['title'], 0, 40, '…')) ?></div>
                    <div class="store-product__meta">
                      <span class="store-product__cat"><?= h(category_label($item['cat_slug'] ?? '', $item['cat_name'] ?? '')) ?></span>
                      <span class="store-product__price"><?= fmt_credit((float)$item['estimated_value']) ?></span>
                      <span class="store-product__status store-product__status--active">فعال</span>
                    </div>
                  </div>
                </div>
                <div class="store-product__actions">
                  <?php if ((int)$item['pending_offers'] > 0): ?>
                  <span class="store-product__offers"><i class="bi bi-inbox"></i> <?= (int)$item['pending_offers'] ?></span>
                  <?php endif; ?>
                  <a href="<?= APP_URL ?>/listings/edit?id=<?= $item['id'] ?>" class="store-btn store-btn--sm store-btn--outline"><i class="bi bi-pencil"></i></a>
                  <a href="<?= APP_URL ?>/listings/view?id=<?= $item['id'] ?>" class="store-btn store-btn--sm store-btn--navy"><i class="bi bi-eye"></i></a>
                  <button class="store-btn store-btn--sm store-btn--danger"><i class="bi bi-trash"></i></button>
                </div>
              </div>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: REQUESTS ========== -->
    <div class="store-tab-panel" data-tab-panel="requests">
      <div class="store-card">
        <div class="store-subtabs">
          <button class="store-subtab is-active" data-subtab="buy-requests">
            <i class="bi bi-cart-check"></i> درخواست‌های خرید (<?= $pendingBuyOffers ?>)
          </button>
          <button class="store-subtab" data-subtab="swap-requests">
            <i class="bi bi-arrow-left-right"></i> درخواست‌های معاوضه (<?= $pendingSwapOffers ?>)
          </button>
        </div>

        <div class="store-subtab-panel is-active" data-subtab-panel="buy-requests">
          <div class="store-requests">
            <div class="store-request">
              <div class="store-request__buyer">
                <div class="store-avatar">ع</div>
                <div>
                  <div class="store-request__name">علی رضایی</div>
                  <div class="store-request__time">امروز، ساعت ۱۴:۳۰</div>
                </div>
              </div>
              <div class="store-request__product">
                <div class="store-request__label">محصول درخواستی</div>
                <div class="store-request__title">لپ‌تاپ Dell XPS ۱۵</div>
              </div>
              <div class="store-request__price">
                <div class="store-request__label">مبلغ پیشنهادی</div>
                <div class="store-request__amount">۴۵,۰۰۰,۰۰۰ تومان</div>
              </div>
              <div class="store-request__status store-request__status--pending">در انتظار</div>
              <div class="store-request__actions">
                <a href="<?= APP_URL ?>/trades" class="store-btn store-btn--sm store-btn--navy"><i class="bi bi-chat-dots"></i> چت</a>
                <button class="store-btn store-btn--sm store-btn--success"><i class="bi bi-check"></i> قبول</button>
                <button class="store-btn store-btn--sm store-btn--danger"><i class="bi bi-x"></i> رد</button>
              </div>
            </div>
            <div class="store-request">
              <div class="store-request__buyer">
                <div class="store-avatar store-avatar--orange">م</div>
                <div>
                  <div class="store-request__name">مریم احمدی</div>
                  <div class="store-request__time">دیروز، ساعت ۱۹:۱۵</div>
                </div>
              </div>
              <div class="store-request__product">
                <div class="store-request__label">محصول درخواستی</div>
                <div class="store-request__title">گوشی iPhone ۱۵ پرو</div>
              </div>
              <div class="store-request__price">
                <div class="store-request__label">مبلغ پیشنهادی</div>
                <div class="store-request__amount">۷۸,۰۰۰,۰۰۰ تومان</div>
              </div>
              <div class="store-request__status store-request__status--pending">در انتظار</div>
              <div class="store-request__actions">
                <a href="<?= APP_URL ?>/trades" class="store-btn store-btn--sm store-btn--navy"><i class="bi bi-chat-dots"></i> چت</a>
                <button class="store-btn store-btn--sm store-btn--success"><i class="bi bi-check"></i> قبول</button>
                <button class="store-btn store-btn--sm store-btn--danger"><i class="bi bi-x"></i> رد</button>
              </div>
            </div>
          </div>
        </div>

        <div class="store-subtab-panel" data-subtab-panel="swap-requests">
          <div class="store-requests">
            <div class="store-request store-request--swap">
              <div class="store-request__swap-cols">
                <div class="store-request__swap-col">
                  <div class="store-request__label">محصول شما</div>
                  <div class="store-swap-product">
                    <div class="store-swap-product__thumb"><i class="bi bi-laptop"></i></div>
                    <div>
                      <div class="store-swap-product__name">لپ‌تاپ Dell XPS ۱۵</div>
                      <div class="store-swap-product__price">ارزش: ۴۵,۰۰۰,۰۰۰ تومان</div>
                    </div>
                  </div>
                </div>
                <div class="store-request__swap-arrow"><i class="bi bi-arrow-left-right"></i></div>
                <div class="store-request__swap-col">
                  <div class="store-request__label">کالای پیشنهادی مشتری</div>
                  <div class="store-swap-product">
                    <div class="store-swap-product__thumb store-swap-product__thumb--gold"><i class="bi bi-gem"></i></div>
                    <div>
                      <div class="store-swap-product__name">دستبند طلای ۱۸ عیار</div>
                      <div class="store-swap-product__price">ارزش پیشنهادی: ۴۲,۰۰۰,۰۰۰ تومان</div>
                    </div>
                  </div>
                  <div class="store-swap-gallery">
                    <div class="store-swap-gallery__item"><i class="bi bi-image"></i></div>
                    <div class="store-swap-gallery__item"><i class="bi bi-image"></i></div>
                    <div class="store-swap-gallery__item"><i class="bi bi-image"></i></div>
                  </div>
                </div>
              </div>
              <div class="store-request__footer">
                <div class="store-request__buyer store-request__buyer--inline">
                  <div class="store-avatar">ک</div>
                  <div>
                    <div class="store-request__name">کیا محمدی</div>
                    <div class="store-request__time">۲ ساعت پیش</div>
                  </div>
                </div>
                <div class="store-request__actions">
                  <a href="<?= APP_URL ?>/trades" class="store-btn store-btn--sm store-btn--navy"><i class="bi bi-chat-dots"></i> چت</a>
                  <button class="store-btn store-btn--sm store-btn--outline"><i class="bi bi-lightning"></i> پیشنهاد جدید</button>
                  <button class="store-btn store-btn--sm store-btn--success"><i class="bi bi-check"></i> قبول</button>
                  <button class="store-btn store-btn--sm store-btn--danger"><i class="bi bi-x"></i> رد</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: MESSAGES ========== -->
    <div class="store-tab-panel" data-tab-panel="messages">
      <div class="store-card">
        <div class="store-card__header">
          <h3 class="store-card__title"><i class="bi bi-chat-dots"></i> پیام‌های مشتریان</h3>
        </div>
        <div class="store-card__body store-card__body--nopad">
          <div class="store-messages">
            <div class="store-message is-unread">
              <div class="store-message__avatar">ع</div>
              <div class="store-message__content">
                <div class="store-message__header">
                  <span class="store-message__name">علی رضایی</span>
                  <span class="store-message__time">۱۴:۳۰</span>
                </div>
                <div class="store-message__preview">سلام، میشه در مورد قیمت لپ‌تاپ کمی توضیح بدید؟</div>
              </div>
              <div class="store-message__attachment">
                <i class="bi bi-chat-left-text"></i>
              </div>
            </div>
            <div class="store-message is-unread">
              <div class="store-message__avatar store-avatar--orange">م</div>
              <div class="store-message__content">
                <div class="store-message__header">
                  <span class="store-message__name">مریم احمدی</span>
                  <span class="store-message__time">۱۲:۱۵</span>
                </div>
                <div class="store-message__preview">عکس کالا رو میتونستم بفرستم براتون ببینید؟</div>
              </div>
              <div class="store-message__attachment store-message__attachment--image">
                <i class="bi bi-image"></i>
              </div>
            </div>
            <div class="store-message">
              <div class="store-message__avatar store-avatar--green">ر</div>
              <div class="store-message__content">
                <div class="store-message__header">
                  <span class="store-message__name">رضا کریمی</span>
                  <span class="store-message__time">دیروز</span>
                </div>
                <div class="store-message__preview">ممنون، کالا دستم رسید و عالیه!</div>
              </div>
              <div class="store-message__attachment">
                <i class="bi bi-geo-alt"></i>
              </div>
            </div>
          </div>
          <div class="store-chat-actions">
            <div class="store-chat-tools">
              <button class="store-btn store-btn--icon store-btn--outline"><i class="bi bi-image"></i></button>
              <button class="store-btn store-btn--icon store-btn--outline"><i class="bi bi-geo-alt"></i></button>
              <button class="store-btn store-btn--icon store-btn--outline"><i class="bi bi-paperclip"></i></button>
            </div>
            <input type="text" class="store-form-input store-chat-input" placeholder="پیام خود را بنویسید...">
            <button class="store-btn store-btn--gradient"><i class="bi bi-send"></i> ارسال</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: MANAGEMENT ========== -->
    <div class="store-tab-panel" data-tab-panel="management">
      <div class="store-grid-2">
        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-gear"></i> اطلاعات فروشگاه</h3>
          </div>
          <div class="store-card__body">
            <div class="store-upload">
              <div class="store-upload__logo">
                <i class="bi bi-shop"></i>
                <button class="store-btn store-btn--sm store-btn--gradient"><i class="bi bi-upload"></i> تغییر لوگو</button>
              </div>
            </div>
            <div class="store-upload-banner">
              <i class="bi bi-card-image"></i>
              <div>
                <div class="store-upload-banner__title">بنر فروشگاه</div>
                <button class="store-btn store-btn--sm store-btn--outline"><i class="bi bi-upload"></i> آپلود بنر</button>
              </div>
            </div>
            <div class="store-form-group">
              <label class="store-form-label">توضیحات فروشگاه</label>
              <textarea class="store-form-input" rows="4" placeholder="معرفی کوتاه فروشگاه شما..."></textarea>
            </div>
            <div class="store-form-group">
              <label class="store-form-label">آدرس فروشگاه</label>
              <input type="text" class="store-form-input" placeholder="آدرس کامل فروشگاه...">
            </div>
            <div class="store-form-grid-2">
              <div class="store-form-group">
                <label class="store-form-label">ساعات کاری</label>
                <input type="text" class="store-form-input" placeholder="شنبه تا پنج‌شنبه ۹-۱۸">
              </div>
              <div class="store-form-group">
                <label class="store-form-label">شماره تماس</label>
                <input type="text" class="store-form-input" placeholder="۰۲۱-۱۲۳۴۵۶۷۸">
              </div>
            </div>
            <div class="store-form-grid-2">
              <div class="store-form-group">
                <label class="store-form-label"><i class="bi bi-whatsapp" style="color:#25D366"></i> واتساپ</label>
                <input type="text" class="store-form-input" placeholder="۹۸۹۱۲۳۴۵۶۷۸۹">
              </div>
              <div class="store-form-group">
                <label class="store-form-label"><i class="bi bi-instagram" style="color:#E1306C"></i> اینستاگرام</label>
                <input type="text" class="store-form-input" placeholder="@your_store">
              </div>
            </div>
            <button class="store-btn store-btn--gradient w-100">
              <i class="bi bi-check-lg"></i> ذخیره تغییرات
            </button>
          </div>
        </div>

        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-geo-alt"></i> موقعیت و شعب</h3>
          </div>
          <div class="store-card__body">
            <div class="store-map-placeholder">
              <i class="bi bi-map"></i>
              <div>نقشه موقعیت فروشگاه</div>
            </div>
            <div class="store-branches">
              <div class="store-branches__title">شعب فروشگاه (امکان ویژه)</div>
              <div class="store-branch">
                <div class="store-branch__info">
                  <i class="bi bi-geo-alt-fill"></i>
                  <div>
                    <div class="store-branch__name">شعبه مرکزی</div>
                    <div class="store-branch__address">تهران، خیابان ولیعصر</div>
                  </div>
                </div>
                <span class="store-branch__status store-branch__status--active">فعال</span>
              </div>
              <button class="store-btn store-btn--outline w-100"><i class="bi bi-plus-circle"></i> افزودن شعبه جدید</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: CATEGORIES ========== -->
    <div class="store-tab-panel" data-tab-panel="categories">
      <div class="store-card">
        <div class="store-card__header">
          <h3 class="store-card__title"><i class="bi bi-grid-3x3-gap"></i> دسته‌بندی‌های فروشگاه</h3>
        </div>
        <div class="store-card__body">
          <div class="store-categories-grid">
            <?php
            $categories = [
              ['icon' => 'bi-gem', 'name' => 'طلا و جواهر', 'count' => 12, 'color' => 'gold'],
              ['icon' => 'bi-phone', 'name' => 'موبایل', 'count' => 28, 'color' => 'blue'],
              ['icon' => 'bi-laptop', 'name' => 'لپ‌تاپ', 'count' => 15, 'color' => 'navy'],
              ['icon' => 'bi-bicycle', 'name' => 'دوچرخه', 'count' => 8, 'color' => 'green'],
              ['icon' => 'bi-camera', 'name' => 'دوربین', 'count' => 6, 'color' => 'purple'],
              ['icon' => 'bi-house', 'name' => 'لوازم منزل', 'count' => 35, 'color' => 'orange'],
              ['icon' => 'bi-car-front', 'name' => 'خودرو', 'count' => 4, 'color' => 'red'],
              ['icon' => 'bi-plus-lg', 'name' => 'افزودن دسته', 'count' => null, 'color' => 'empty'],
            ];
            foreach ($categories as $cat):
            ?>
            <div class="store-category-card <?= $cat['color'] === 'empty' ? 'is-empty' : '' ?>">
              <div class="store-category-card__icon store-category-card__icon--<?= $cat['color'] ?>">
                <i class="bi <?= $cat['icon'] ?>"></i>
              </div>
              <div class="store-category-card__name"><?= $cat['name'] ?></div>
              <?php if ($cat['count'] !== null): ?>
              <div class="store-category-card__count"><?= $cat['count'] ?> محصول</div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: REPORTS ========== -->
    <div class="store-tab-panel" data-tab-panel="reports">
      <div class="store-stats-grid">
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--blue"><i class="bi bi-eye"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= number_format($totalVisits) ?></div>
            <div class="store-stat-card__label">تعداد بازدید کل محصولات</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--green"><i class="bi bi-percent"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value">۱۲٪</div>
            <div class="store-stat-card__label">نرخ تبدیل بازدید به درخواست</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--gold"><i class="bi bi-trophy"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value">لپ‌تاپ Dell</div>
            <div class="store-stat-card__label">محبوب‌ترین محصول</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--purple"><i class="bi bi-arrow-left-right"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value">مبل راحتی</div>
            <div class="store-stat-card__label">بیشترین درخواست معاوضه</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--orange"><i class="bi bi-telephone"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value">۴۷</div>
            <div class="store-stat-card__label">تعداد تماس‌ها</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--navy"><i class="bi bi-bar-chart"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value">۳۲٪</div>
            <div class="store-stat-card__label">رشد نسبت به ماه قبل</div>
          </div>
        </div>
      </div>

      <div class="store-card">
        <div class="store-card__header">
          <h3 class="store-card__title"><i class="bi bi-list-ol"></i> پرفروش‌ترین محصولات</h3>
        </div>
        <div class="store-card__body store-card__body--nopad">
          <div class="store-top-products">
            <?php
            $topProducts = [
              ['rank' => 1, 'name' => 'گوشی iPhone ۱۵ پرو', 'views' => 1250, 'offers' => 24],
              ['rank' => 2, 'name' => 'لپ‌تاپ Dell XPS ۱۵', 'views' => 980, 'offers' => 18],
              ['rank' => 3, 'name' => 'مبل راحتی ۶ نفره', 'views' => 870, 'offers' => 31],
              ['rank' => 4, 'name' => 'ساعت هوشمند Apple Watch', 'views' => 650, 'offers' => 12],
              ['rank' => 5, 'name' => 'دوربین کانن EOS R', 'views' => 540, 'offers' => 9],
            ];
            foreach ($topProducts as $p):
            ?>
            <div class="store-top-product">
              <div class="store-top-product__rank store-top-product__rank--<?= $p['rank'] <= 3 ? 'top' : 'normal' ?>">
                <?= $p['rank'] === 1 ? '🥇' : ($p['rank'] === 2 ? '🥈' : ($p['rank'] === 3 ? '🥉' : $p['rank'])) ?>
              </div>
              <div class="store-top-product__name"><?= $p['name'] ?></div>
              <div class="store-top-product__stat">
                <i class="bi bi-eye"></i> <?= number_format($p['views']) ?>
              </div>
              <div class="store-top-product__stat">
                <i class="bi bi-inbox"></i> <?= $p['offers'] ?> درخواست
              </div>
              <div class="store-top-product__bar"><div style="width:<?= (100 - ($p['rank']-1)*18) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: NOTIFICATIONS ========== -->
    <div class="store-tab-panel" data-tab-panel="notifications">
      <div class="store-card">
        <div class="store-card__header">
          <h3 class="store-card__title"><i class="bi bi-bell"></i> اعلان‌ها</h3>
          <div class="store-card__filters">
            <button class="store-btn store-btn--sm store-btn--navy">همه</button>
            <button class="store-btn store-btn--sm store-btn--outline">خوانده نشده</button>
            <button class="store-btn store-btn--sm store-btn--outline">خوانده شده</button>
          </div>
        </div>
        <div class="store-card__body store-card__body--nopad">
          <div class="store-notifications-list">
            <?php
            $allNotifs = [
              ['icon' => 'bi-arrow-left-right', 'type' => 'swap', 'title' => 'درخواست معاوضه جدید', 'desc' => 'کاربر «کیا محمدی» برای «مبل راحتی» درخواست معاوضه فرستاد.', 'time' => '۱۰ دقیقه پیش', 'unread' => true],
              ['icon' => 'bi-heart-fill', 'type' => 'like', 'title' => 'محصول پسندیده شد', 'desc' => '«لپ‌تاپ Dell XPS ۱۵» توسط ۳ کاربر جدید پسندیده شد.', 'time' => '۱ ساعت پیش', 'unread' => true],
              ['icon' => 'bi-lightning-charge', 'type' => 'offer', 'title' => 'پیشنهاد جدید', 'desc' => 'پیشنهاد جدید برای «گوشی iPhone ۱۵ پرو» دریافت کردید.', 'time' => '۳ ساعت پیش', 'unread' => true],
              ['icon' => 'bi-check-circle-fill', 'type' => 'success', 'title' => 'معامله موفق', 'desc' => 'معامله «ساعت هوشمند» با موفقیت به پایان رسید.', 'time' => 'دیروز', 'unread' => false],
              ['icon' => 'bi-star-half', 'type' => 'review', 'title' => 'نظر جدید', 'desc' => 'مشتری «رضا کریمی» نظر ۵ ستاره برای شما ثبت کرد.', 'time' => '۲ روز پیش', 'unread' => false],
              ['icon' => 'bi-upload', 'type' => 'info', 'title' => 'آگهی تایید شد', 'desc' => 'آگهی «دوربین کانن» توسط کارشناسان تایید گردید.', 'time' => '۳ روز پیش', 'unread' => false],
            ];
            foreach ($allNotifs as $n):
            ?>
            <div class="store-notification-item <?= $n['unread'] ? 'is-unread' : '' ?>">
              <div class="store-notification-item__icon store-notification-item__icon--<?= $n['type'] ?>">
                <i class="bi <?= $n['icon'] ?>"></i>
              </div>
              <div class="store-notification-item__content">
                <div class="store-notification-item__title"><?= $n['title'] ?></div>
                <div class="store-notification-item__desc"><?= $n['desc'] ?></div>
                <div class="store-notification-item__time"><?= $n['time'] ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: SETTINGS ========== -->
    <div class="store-tab-panel" data-tab-panel="settings">
      <div class="store-grid-2">
        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-info-circle"></i> اطلاعات فروشگاه</h3>
          </div>
          <div class="store-card__body">
            <div class="store-form-group">
              <label class="store-form-label">نام فروشگاه</label>
              <input type="text" class="store-form-input" value="<?= h($user['store_name'] ?? 'فروشگاه من') ?>">
            </div>
            <div class="store-form-grid-2">
              <div class="store-form-group">
                <label class="store-form-label">نوع کسب‌وکار</label>
                <select class="store-form-input">
                  <option>خرده‌فروش</option>
                  <option>عمده‌فروش</option>
                  <option>تولیدی</option>
                  <option>خدماتی</option>
                </select>
              </div>
              <div class="store-form-group">
                <label class="store-form-label">شماره اقتصادی</label>
                <input type="text" class="store-form-input" placeholder="شماره اقتصادی (اختیاری)">
              </div>
            </div>
            <div class="store-form-grid-2">
              <div class="store-form-group">
                <label class="store-form-label">استان</label>
                <input type="text" class="store-form-input" placeholder="تهران">
              </div>
              <div class="store-form-group">
                <label class="store-form-label">شهر</label>
                <input type="text" class="store-form-input" placeholder="تهران">
              </div>
            </div>
            <button class="store-btn store-btn--gradient w-100"><i class="bi bi-save"></i> ذخیره اطلاعات</button>
          </div>
        </div>

        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-shield-check"></i> احراز هویت و حساب بانکی</h3>
          </div>
          <div class="store-card__body">
            <div class="store-kyc-status store-kyc-status--verified">
              <i class="bi bi-patch-check-fill"></i>
              <div>
                <div class="store-kyc-status__title">وضعیت احراز هویت</div>
                <div class="store-kyc-status__desc">مدارک شما تایید شده است</div>
              </div>
            </div>
            <div class="store-divider"></div>
            <div class="store-form-group">
              <label class="store-form-label">شماره شبا</label>
              <input type="text" class="store-form-input" dir="ltr" placeholder="IRXXXXXXXXXXXXXXXXXXXX">
            </div>
            <div class="store-form-grid-2">
              <div class="store-form-group">
                <label class="store-form-label">صاحب حساب</label>
                <input type="text" class="store-form-input" value="<?= h($user['full_name'] ?? '') ?>">
              </div>
              <div class="store-form-group">
                <label class="store-form-label">نام بانک</label>
                <select class="store-form-input">
                  <option>ملی</option><option>ملت</option><option>پاسارگاد</option>
                  <option>قرض‌الحسنه رسالت</option><option>سامان</option>
                </select>
              </div>
            </div>
            <div class="store-divider"></div>
            <div class="store-form-group">
              <label class="store-form-label"><i class="bi bi-people"></i> مدیریت کاربران فروشگاه (امکان ویژه)</label>
              <div class="store-users">
                <div class="store-user">
                  <div class="store-message__avatar">ص</div>
                  <div>
                    <div class="store-user__name">صالح امانی</div>
                    <div class="store-user__role">مدیر فروشگاه</div>
                  </div>
                  <span class="store-user__badge">فعال</span>
                </div>
              </div>
              <button class="store-btn store-btn--outline w-100 mt-3"><i class="bi bi-person-plus"></i> دعوت کاربر جدید</button>
            </div>
          </div>
        </div>
      </div>

      <div class="store-card">
        <div class="store-card__header">
          <h3 class="store-card__title"><i class="bi bi-bell"></i> تنظیمات اعلان‌ها</h3>
        </div>
        <div class="store-card__body">
          <div class="store-notification-settings">
            <div class="store-notif-setting">
              <div>
                <div class="store-notif-setting__title">درخواست‌های جدید</div>
                <div class="store-notif-setting__desc">اعلان هنگام دریافت درخواست خرید یا معاوضه جدید</div>
              </div>
              <label class="store-switch"><input type="checkbox" checked><span></span></label>
            </div>
            <div class="store-notif-setting">
              <div>
                <div class="store-notif-setting__title">پیام‌های جدید</div>
                <div class="store-notif-setting__desc">اعلان هنگام دریافت پیام از مشتریان</div>
              </div>
              <label class="store-switch"><input type="checkbox" checked><span></span></label>
            </div>
            <div class="store-notif-setting">
              <div>
                <div class="store-notif-setting__title">پسندیده شدن محصولات</div>
                <div class="store-notif-setting__desc">اعلان وقتی محصول شما توسط کاربران پسندیده شود</div>
              </div>
              <label class="store-switch"><input type="checkbox"><span></span></label>
            </div>
            <div class="store-notif-setting">
              <div>
                <div class="store-notif-setting__title">گزارش هفتگی عملکرد</div>
                <div class="store-notif-setting__desc">ارسال گزارش عملکرد فروشگاه هر هفته</div>
              </div>
              <label class="store-switch"><input type="checkbox" checked><span></span></label>
            </div>
            <div class="store-notif-setting">
              <div>
                <div class="store-notif-setting__title">اخبار و پیشنهادات ویژه</div>
                <div class="store-notif-setting__desc">دریافت اخبار سواپین و پیشنهادات تبلیغاتی</div>
              </div>
              <label class="store-switch"><input type="checkbox"><span></span></label>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
(function() {
  const tabs = document.querySelectorAll('.store-tab');
  const panels = document.querySelectorAll('.store-tab-panel');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.tab;
      tabs.forEach(t => t.classList.remove('is-active'));
      panels.forEach(p => p.classList.remove('is-active'));
      tab.classList.add('is-active');
      const panel = document.querySelector(`[data-tab-panel="${target}"]`);
      if (panel) panel.classList.add('is-active');
      window.scrollTo({top: 0, behavior: 'smooth'});
    });
  });

  const subtabs = document.querySelectorAll('.store-subtab');
  const subpanels = document.querySelectorAll('.store-subtab-panel');
  subtabs.forEach(st => {
    st.addEventListener('click', () => {
      const target = st.dataset.subtab;
      subtabs.forEach(s => s.classList.remove('is-active'));
      subpanels.forEach(sp => sp.classList.remove('is-active'));
      st.classList.add('is-active');
      const sp = document.querySelector(`[data-subtab-panel="${target}"]`);
      if (sp) sp.classList.add('is-active');
    });
  });

  const canvas = document.getElementById('storeChartCanvas');
  if (canvas && canvas.getContext) {
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    const data1 = [12, 19, 25, 22, 30, 28, 35, 42, 38, 45, 50, 48];
    const data2 = [4, 7, 9, 8, 12, 11, 14, 18, 16, 20, 23, 22];
    const max = Math.max(...data1) * 1.15;
    const pad = {t: 30, r: 30, b: 30, l: 40};
    const chartW = W - pad.l - pad.r;
    const chartH = H - pad.t - pad.b;
    const xStep = chartW / (data1.length - 1);

    ctx.strokeStyle = '#E5E7EB';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y = pad.t + (chartH * i / 4);
      ctx.beginPath();
      ctx.moveTo(pad.l, y);
      ctx.lineTo(W - pad.r, y);
      ctx.stroke();
    }

    function drawLine(data, color, fill) {
      ctx.beginPath();
      ctx.moveTo(pad.l, pad.t + chartH - (data[0] / max * chartH));
      for (let i = 1; i < data.length; i++) {
        const x = pad.l + i * xStep;
        const y = pad.t + chartH - (data[i] / max * chartH);
        ctx.lineTo(x, y);
      }
      if (fill) {
        ctx.lineTo(pad.l + (data.length-1) * xStep, pad.t + chartH);
        ctx.lineTo(pad.l, pad.t + chartH);
        ctx.closePath();
        ctx.fillStyle = fill;
        ctx.fill();
      }
      ctx.strokeStyle = color;
      ctx.lineWidth = 3;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.stroke();

      for (let i = 0; i < data.length; i++) {
        const x = pad.l + i * xStep;
        const y = pad.t + chartH - (data[i] / max * chartH);
        ctx.beginPath();
        ctx.arc(x, y, 4, 0, Math.PI * 2);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2.5;
        ctx.stroke();
      }
    }

    drawLine(data1, '#0B1F4D', 'rgba(11, 31, 77, 0.06)');
    drawLine(data2, '#F5B400', null);
  }

  window.addEventListener('load', () => window.scrollTo(0, 0));
  window.addEventListener('pageshow', () => window.scrollTo(0, 0));
})();
</script>

<?php render_footer(); ?>
