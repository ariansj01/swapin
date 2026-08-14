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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['store_add_product'])) {
    csrf_verify_or_fail();
    $title           = clean($_POST['title']           ?? '');
    $description     = clean($_POST['description']     ?? '');
    $category_id     = (int)($_POST['category_id']     ?? 0);
    $want_category_id = (int)($_POST['want_category_id'] ?? 0);
    $want_description = clean($_POST['want_description'] ?? '');
    $listing_mode    = clean($_POST['listing_mode']    ?? 'both');
    $sell_price      = max(0, (float)($_POST['sell_price']      ?? 0));
    $estimated_value = max(0, (float)($_POST['estimated_value'] ?? 0));

    if (!in_array($listing_mode, ['sell', 'swap', 'both'], true)) {
        $listing_mode = 'both';
    }

    $validationErrors = [];
    if (mb_strlen($title) < 5) $validationErrors[] = 'نام محصول باید حداقل ۵ کاراکتر باشد';
    if (mb_strlen($description) < 20) $validationErrors[] = 'توضیحات محصول باید حداقل ۲۰ کاراکتر باشد';
    if (!$category_id) $validationErrors[] = 'لطفاً دسته‌بندی محصول را انتخاب کنید';

    if (!empty($validationErrors)) {
        $error = implode(' | ', $validationErrors);
    } else {
        $hasImageUpload = false;
        if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
            foreach ($_FILES['images']['name'] as $i => $name) {
                if ($name && ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $hasImageUpload = true;
                    break;
                }
            }
        }
        if (!$hasImageUpload) {
            $validationErrors[] = 'حداقل یک تصویر برای محصول الزامی است';
        }

        if (empty($validationErrors)) {
            $listingId = DB::insert('listings', [
                'user_id'         => $uid,
                'category_id'     => $category_id,
                'title'           => $title,
                'description'     => $description,
                'want_in_return'  => $want_description,
                'listing_mode'    => $listing_mode,
                'sell_price'      => $sell_price,
                'estimated_value' => $estimated_value,
                'condition'       => 'good',
                'status'          => 'active',
                'review_status'   => 'pending',
                'city'            => $user['city'] ?? null,
            ]);

            $uploadedImages = 0;
            if (!empty($_FILES['images']['name'][0])) {
                foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                    if ($uploadedImages >= MAX_IMAGES) break;
                    if (empty($tmp) || ($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $file = [
                        'name'     => $_FILES['images']['name'][$i],
                        'tmp_name' => $tmp,
                        'error'    => $_FILES['images']['error'][$i],
                        'size'     => $_FILES['images']['size'][$i],
                    ];
                    $filename = upload_image($file, 'listing');
                    if ($filename) {
                        DB::insert('listing_images', [
                            'listing_id' => $listingId,
                            'filename'   => $filename,
                            'is_primary' => $uploadedImages === 0 ? 1 : 0,
                            'sort_order' => $uploadedImages,
                        ]);
                        $uploadedImages++;
                    }
                }
            }

            if ($uploadedImages === 0) {
                DB::query('DELETE FROM listings WHERE id = ? AND user_id = ?', [$listingId, $uid]);
                $error = 'آپلود تصاویر ناموفق بود. لطفاً دوباره تلاش کنید.';
            } else {
                ai_match_clear_cache($uid);
                $success = 'محصول شما با موفقیت ثبت شد.';
                echo '<script>window.location.href = window.location.href;</script>';
                exit;
            }
        } else {
            $error = implode(' | ', $validationErrors);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['store_save_info'])) {
    csrf_verify_or_fail();
    $error = 'مدیریت اطلاعات فروشگاه فقط از طریق پنل مدیریت امکان‌پذیر است. لطفاً با ادمین تماس بگیرید.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_id'])) {
    csrf_verify_or_fail();
    $offerId = (int)($_POST['offer_id'] ?? 0);
    $action  = clean($_POST['action'] ?? '');
    $message = clean($_POST['message'] ?? '');

    if ($offerId && in_array($action, ['accept', 'reject'], true)) {
        $offer = DB::fetch(
            'SELECT o.*, l.user_id AS listing_owner, l.title AS listing_title
             FROM trade_offers o
             JOIN listings l ON l.id = o.listing_id
             WHERE o.id = ? AND l.user_id = ? AND o.status = "pending"',
            [$offerId, $uid]
        );

        if (!$offer) {
            $error = 'پیشنهاد یافت نشد یا دسترسی ندارید.';
        } elseif ($action === 'accept') {
            if (empty($message)) {
                $error = 'لطفاً پیامی برای طرفین بنویسید.';
            } else {
                $result = accept_trade_offer($offerId, $uid, $message);
                if (isset($result['error'])) {
                    $error = $result['error'];
                } else {
                    header('Location: ' . APP_URL . '/trades/view.php?id=' . $result['trade_id'] . '&accepted=1&tab=fee');
                    exit;
                }
            }
        } elseif ($action === 'reject') {
            if (empty($message)) {
                $error = 'لطفاً پیامی برای طرفین بنویسید.';
            } else {
                DB::query('UPDATE trade_offers SET status = "rejected" WHERE id = ?', [$offerId]);
                DB::insert('messages', [
                    'thread_id'    => 'offer_reject_' . $offerId,
                    'from_user_id' => $uid,
                    'to_user_id'   => $offer['from_user_id'],
                    'offer_id'     => $offerId,
                    'body'         => $message,
                ]);
                $success = 'پیشنهاد رد شد.';
            }
        }
    }
}

$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);

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

$_hasOfferType = db_has_column('trade_offers', 'offer_type');

$_offerBaseSql = 'SELECT o.*, l.title AS listing_title, l.estimated_value AS listing_value, l.id AS listing_id_v,
            (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS listing_thumb,
            u.name AS from_name, u.avatar AS from_avatar, u.rating AS from_rating,
            ol.title AS offer_listing_title, ol.estimated_value AS offer_listing_value, ol.id AS offer_listing_id_v,
            (SELECT filename FROM listing_images WHERE listing_id=ol.id AND is_primary=1 LIMIT 1) AS offer_listing_thumb
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = o.from_user_id
     LEFT JOIN listings ol ON ol.id = o.offer_listing_id
     WHERE l.user_id = ? AND o.status = "pending"
     ORDER BY o.created_at DESC';

$allPendingOffers = DB::fetchAll($_offerBaseSql, [$uid]);
$buyOffersList  = [];
$swapOffersList = [];
foreach ($allPendingOffers as $_offerRow) {
    $_type = $_hasOfferType ? (string)($_offerRow['offer_type'] ?? 'item') : 'item';
    if ($_type === 'buy') {
        $buyOffersList[] = $_offerRow;
    } else {
        $swapOffersList[] = $_offerRow;
    }
}
$pendingBuyOffers  = count($buyOffersList);
$pendingSwapOffers = count($swapOffersList);

$requestsSubtab = clean($_GET['subtab'] ?? '');
if (!in_array($requestsSubtab, ['buy-requests', 'swap-requests'], true)) {
    $requestsSubtab = ($pendingSwapOffers > 0 && $pendingBuyOffers === 0) ? 'swap-requests' : 'buy-requests';
}

$completedTrades = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "accepted"',
    [$uid]
)['c'] ?? 0);

$totalVisits = 0;
foreach ($inventory as $item) {
    $totalVisits += (int)($item['views'] ?? $item['view_count'] ?? 0);
}

$storeReports   = store_reports_stats($uid);
$storeChartData = store_monthly_chart_data($uid);
$storeTopProducts = store_top_products($uid, 5);
$storeCategories  = store_category_breakdown($uid);
$kycUi = kyc_store_status_ui($user);
$chartViews    = array_column($storeChartData, 'views');
$chartRequests = array_column($storeChartData, 'requests');

$notifications = [];
foreach (array_slice($swapOffersList, 0, 5) as $_nOffer) {
    $notifications[] = [
        'icon' => 'bi-arrow-left-right',
        'text' => 'درخواست معاوضه از «' . ($_nOffer['from_name'] ?? '') . '» برای «' . ($_nOffer['listing_title'] ?? '') . '»',
        'time' => timeago($_nOffer['created_at']),
        'link' => APP_URL . '/store/?tab=requests&subtab=swap-requests',
    ];
}
if (empty($notifications)) {
    $notifications[] = [
        'icon' => 'bi-inbox',
        'text' => 'درخواست معاوضه جدیدی ندارید.',
        'time' => '',
        'link' => APP_URL . '/store/?tab=requests',
    ];
}

$storeNotifs = [];

$sellerOrders = store_orders_enabled()
    ? DB::fetchAll(
        'SELECT o.*, l.title AS listing_title,
                (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS listing_thumb,
                u.name AS buyer_name
         FROM store_orders o
         JOIN listings l ON l.id = o.listing_id
         JOIN users u ON u.id = o.buyer_id
         WHERE o.seller_id = ? AND o.status NOT IN ("pending_payment","canceled")
         ORDER BY o.created_at DESC
         LIMIT 50',
        [$uid]
    )
    : [];
$pendingSellerOrders = count(array_filter($sellerOrders, fn($o) => in_array($o['status'], ['paid', 'preparing'], true)));

foreach ($allPendingOffers as $_nOffer) {
    $_type = $_hasOfferType ? (string)($_nOffer['offer_type'] ?? 'item') : 'item';
    $storeNotifs[] = [
        'icon'  => $_type === 'buy' ? 'bi-cart-check' : 'bi-arrow-left-right',
        'type'  => $_type === 'buy' ? 'offer' : 'swap',
        'title' => $_type === 'buy' ? 'درخواست خرید جدید' : 'درخواست معاوضه جدید',
        'desc'  => 'کاربر «' . ($_nOffer['from_name'] ?? '') . '» برای «' . ($_nOffer['listing_title'] ?? '') . '» درخواست فرستاد.',
        'time'  => timeago($_nOffer['created_at']),
        'unread'=> true,
        'link'  => APP_URL . '/store/?tab=requests&subtab=' . ($_type === 'buy' ? 'buy-requests' : 'swap-requests'),
    ];
}

foreach (array_slice($sellerOrders, 0, 10) as $_sOrder) {
    if (!in_array($_sOrder['status'], ['paid', 'preparing', 'shipped'], true)) {
        continue;
    }
    $storeNotifs[] = [
        'icon'  => 'bi-bag-check',
        'type'  => 'offer',
        'title' => 'سفارش خرید نقدی',
        'desc'  => 'خریدار «' . ($_sOrder['buyer_name'] ?? '') . '» — «' . ($_sOrder['listing_title'] ?? '') . '» (' . store_order_status_label($_sOrder['status']) . ')',
        'time'  => timeago($_sOrder['created_at']),
        'unread'=> in_array($_sOrder['status'], ['paid', 'preparing'], true),
        'link'  => APP_URL . '/orders/view.php?id=' . (int)$_sOrder['id'],
    ];
}

$chartData = $chartViews;

render_head('پنل فروشگاه');
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store.css?v=<?= @filemtime(__DIR__ . '/../src/css/store.css') ?: time() ?>">

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
        <?php if (!empty($user['store_slug'])): ?>
        <a href="<?= APP_URL ?>/shop/<?= h($user['store_slug']) ?>" target="_blank" class="store-btn store-btn--outline">
          <i class="bi bi-box-arrow-up-right"></i> مشاهده صفحه فروشگاه
        </a>
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
      <button class="store-tab" data-tab="orders">
        <i class="bi bi-bag-check"></i> سفارش‌های فروش
        <?php if ($pendingSellerOrders > 0): ?>
        <span class="store-tab__badge"><?= $pendingSellerOrders ?></span>
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
            <div class="store-chart" style="position: relative;">
              <canvas id="storeChartCanvas" width="800" height="280" style="cursor: crosshair;"></canvas>
              <div id="storeChartTooltip" style="
                position: absolute;
                pointer-events: none;
                background: rgba(11,31,77,0.95);
                color: #fff;
                padding: 10px 14px;
                border-radius: 12px;
                font-size: 13px;
                line-height: 1.7;
                white-space: nowrap;
                box-shadow: 0 8px 24px rgba(0,0,0,0.18);
                opacity: 0;
                transform: translate(-50%, -120%);
                transition: opacity 0.15s ease;
                z-index: 10;
                font-family: inherit;
              ">
                <div id="storeChartTooltip-title" style="font-weight: 700; margin-bottom: 4px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 4px;"></div>
                <div style="display: flex; gap: 12px;">
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="display:inline-block; width:10px; height:10px; background:#0B1F4D; border-radius: 50%;"></span>
                    <span>بازدید: <span id="storeChartTooltip-views" style="font-weight: 700;"></span></span>
                  </div>
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="display:inline-block; width:10px; height:10px; background:#F5B400; border-radius: 50%;"></span>
                    <span>درخواست: <span id="storeChartTooltip-reqs" style="font-weight: 700;"></span></span>
                  </div>
                </div>
              </div>
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
            <a href="<?= APP_URL ?>/store/?tab=notifications" class="store-card__link">مشاهده همه</a>
          </div>
          <div class="store-card__body">
            <div class="store-notifications">
              <?php foreach ($notifications as $n): ?>
              <a href="<?= h($n['link'] ?? '#') ?>" class="store-notification" style="text-decoration:none;color:inherit">
                <div class="store-notification__icon">
                  <i class="bi <?= $n['icon'] ?>"></i>
                </div>
                <div class="store-notification__content">
                  <div class="store-notification__text"><?= h($n['text']) ?></div>
                  <?php if (!empty($n['time'])): ?>
                  <div class="store-notification__time"><?= h($n['time']) ?></div>
                  <?php endif; ?>
                </div>
              </a>
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
            <form method="POST" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <div class="store-form-group">
                <label class="store-form-label">نام محصول</label>
                <input type="text" class="store-form-input" name="title" placeholder="نام محصول را وارد کنید..." value="<?= h($_POST['title'] ?? '') ?>">
              </div>
              <div class="store-form-grid-2">
                <div class="store-form-group">
                  <label class="store-form-label">دسته‌بندی محصول</label>
                  <select class="store-form-input" name="category_id">
                    <option value="">انتخاب دسته‌بندی...</option>
                    <?php
                    $lastParent = null;
                    foreach ($categories as $c):
                      $isChild = !empty($c['parent_id']);
                      $label = $isChild ? ('&nbsp;&nbsp;&nbsp; ' . $c['name']) : ($c['name']);
                      $sel = ((int)($_POST['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '';
                      if (!$isChild && $lastParent !== null) {
                        echo '</optgroup>';
                      }
                      if (!$isChild) {
                        $lastParent = (int)$c['id'];
                        echo '<optgroup label="' . h($c['name']) . '">';
                      } elseif ($lastParent === null) {
                        $lastParent = 0;
                      }
                      echo '<option value="' . (int)$c['id'] . '" ' . $sel . '>' . $label . '</option>';
                    endforeach;
                    if ($lastParent !== null && $lastParent !== 0) {
                      echo '</optgroup>';
                    }
                    ?>
                  </select>
                </div>
                <div class="store-form-group">
                  <label class="store-form-label">قیمت محصول (فروش) تومان</label>
                  <input type="text" class="store-form-input" name="sell_price" placeholder="۰" value="<?= h($_POST['sell_price'] ?? '') ?>">
                </div>
              </div>
              <div class="store-form-group">
                <label class="store-form-label">نوع معامله</label>
                <div class="store-radio-group">
                  <label class="store-radio"><input type="radio" name="listing_mode" value="sell" <?= (($_POST['listing_mode'] ?? 'both') === 'sell') ? 'checked' : '' ?>> فقط فروش</label>
                  <label class="store-radio"><input type="radio" name="listing_mode" value="swap" <?= (($_POST['listing_mode'] ?? 'both') === 'swap') ? 'checked' : '' ?>> فقط معاوضه</label>
                  <label class="store-radio"><input type="radio" name="listing_mode" value="both" <?= (($_POST['listing_mode'] ?? 'both') === 'both') ? 'checked' : '' ?>> فروش و معاوضه</label>
                </div>
              </div>
              <div class="store-form-grid-2">
                <div class="store-form-group">
                  <label class="store-form-label">دسته‌بندی کالای موردنیاز برای معاوضه</label>
                  <select class="store-form-input" name="want_category_id">
                    <option value="">انتخاب دسته‌بندی (اختیاری)...</option>
                    <?php
                    $lastParent2 = null;
                    foreach ($categories as $c):
                      $isChild = !empty($c['parent_id']);
                      $label = $isChild ? ('&nbsp;&nbsp;&nbsp; ' . $c['name']) : ($c['name']);
                      $sel = ((int)($_POST['want_category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '';
                      if (!$isChild && $lastParent2 !== null) {
                        echo '</optgroup>';
                      }
                      if (!$isChild) {
                        $lastParent2 = (int)$c['id'];
                        echo '<optgroup label="' . h($c['name']) . '">';
                      } elseif ($lastParent2 === null) {
                        $lastParent2 = 0;
                      }
                      echo '<option value="' . (int)$c['id'] . '" ' . $sel . '>' . $label . '</option>';
                    endforeach;
                    if ($lastParent2 !== null && $lastParent2 !== 0) {
                      echo '</optgroup>';
                    }
                    ?>
                  </select>
                </div>
                <div class="store-form-group">
                  <label class="store-form-label">قیمت تخمینی (برای معاوضه) تومان</label>
                  <input type="text" class="store-form-input" name="estimated_value" placeholder="۰" value="<?= h($_POST['estimated_value'] ?? '') ?>">
                </div>
              </div>
              <div class="store-form-group">
                <label class="store-form-label">توضیحات محصول</label>
                <textarea class="store-form-input" name="description" rows="4" placeholder="توضیحات کامل محصول..."><?= h($_POST['description'] ?? '') ?></textarea>
              </div>
              <div class="store-form-group">
                <label class="store-form-label">توضیحات کالای موردنیاز</label>
                <textarea class="store-form-input" name="want_description" rows="3" placeholder="مثلا: فقط گوشی آیفون ۱۴ به بالا با سلامت باتری بالای ۹۰٪..."><?= h($_POST['want_description'] ?? '') ?></textarea>
              </div>
              <div class="store-form-group">
                <label class="store-form-label">تصاویر محصول (حداقل ۱، حداکثر <?= MAX_IMAGES ?> تصویر)</label>
                <input type="file" class="store-form-input" name="images[]" multiple accept="image/jpeg,image/png,image/webp">
              </div>
              <button type="submit" name="store_add_product" class="store-btn store-btn--gradient w-100">
                <i class="bi bi-save"></i> ذخیره محصول
              </button>
            </form>

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
                  <a href="<?= APP_URL ?>/store/?tab=requests&amp;subtab=swap-requests" class="store-product__offers" title="مشاهده درخواست‌های معاوضه"><i class="bi bi-inbox"></i> <?= (int)$item['pending_offers'] ?></a>
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
          <button class="store-subtab<?= $requestsSubtab === 'buy-requests' ? ' is-active' : '' ?>" data-subtab="buy-requests">
            <i class="bi bi-cart-check"></i> درخواست‌های خرید (<?= $pendingBuyOffers ?>)
          </button>
          <button class="store-subtab<?= $requestsSubtab === 'swap-requests' ? ' is-active' : '' ?>" data-subtab="swap-requests">
            <i class="bi bi-arrow-left-right"></i> درخواست‌های معاوضه (<?= $pendingSwapOffers ?>)
          </button>
        </div>

        <div class="store-subtab-panel<?= $requestsSubtab === 'buy-requests' ? ' is-active' : '' ?>" data-subtab-panel="buy-requests">
          <div class="store-requests">
            <?php if (empty($buyOffersList)): ?>
            <div class="store-empty">
              <i class="bi bi-inbox"></i>
              <p>هنوز درخواست خریدی دریافت نشده است.</p>
            </div>
            <?php else: ?>
            <?php foreach ($buyOffersList as $offer): ?>
            <div class="store-request">
              <div class="store-request__buyer">
                <div class="store-avatar"><?= h(user_initial($offer['from_name'])) ?></div>
                <div>
                  <div class="store-request__name"><?= h($offer['from_name']) ?></div>
                  <div class="store-request__time"><?= persian_datetime($offer['created_at']) ?></div>
                </div>
              </div>
              <div class="store-request__product">
                <div class="store-request__label">محصول درخواستی</div>
                <div class="store-request__title"><?= h($offer['listing_title']) ?></div>
              </div>
              <div class="store-request__price">
                <div class="store-request__label">مبلغ پیشنهادی</div>
                <div class="store-request__amount"><?= fmt_credit(abs((float)$offer['offer_credit'])) ?></div>
              </div>
              <div class="store-request__status store-request__status--pending">در انتظار</div>
              <div class="store-request__actions store-request__actions--forms">
                <form method="POST" class="store-offer-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                  <input type="hidden" name="action" value="accept">
                  <textarea name="message" class="store-form-input store-form-input--sm" rows="2" required placeholder="پیام پذیرش..."></textarea>
                  <button type="submit" class="store-btn store-btn--sm store-btn--success"><i class="bi bi-check"></i> قبول و ورود به اتاق امن</button>
                </form>
                <form method="POST" class="store-offer-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <textarea name="message" class="store-form-input store-form-input--sm" rows="2" required placeholder="پیام رد..."></textarea>
                  <button type="submit" class="store-btn store-btn--sm store-btn--danger"><i class="bi bi-x"></i> رد</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="store-subtab-panel<?= $requestsSubtab === 'swap-requests' ? ' is-active' : '' ?>" data-subtab-panel="swap-requests">
          <div class="store-requests">
            <?php if (empty($swapOffersList)): ?>
            <div class="store-empty">
              <i class="bi bi-arrow-left-right"></i>
              <p>هنوز درخواست معاوضه‌ای دریافت نشده است.</p>
            </div>
            <?php else: ?>
            <?php foreach ($swapOffersList as $offer):
              $offerImages = !empty($offer['offer_listing_id_v'])
                  ? DB::fetchAll(
                      'SELECT filename FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order LIMIT 3',
                      [(int)$offer['offer_listing_id_v']]
                  )
                  : [];
            ?>
            <div class="store-request store-request--swap">
              <div class="store-request__swap-cols">
                <div class="store-request__swap-col">
                  <div class="store-request__label">محصول شما</div>
                  <div class="store-swap-product">
                    <div class="store-swap-product__thumb">
                      <?php if (!empty($offer['listing_thumb'])): ?>
                      <img src="<?= UPLOAD_URL . h($offer['listing_thumb']) ?>" alt="<?= h($offer['listing_title']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">
                      <?php else: ?>
                      <i class="bi bi-box"></i>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="store-swap-product__name"><?= h($offer['listing_title']) ?></div>
                      <div class="store-swap-product__price">ارزش: <?= fmt_credit((float)$offer['listing_value']) ?></div>
                    </div>
                  </div>
                </div>
                <div class="store-request__swap-arrow"><i class="bi bi-arrow-left-right"></i></div>
                <div class="store-request__swap-col">
                  <div class="store-request__label">کالای پیشنهادی مشتری</div>
                  <?php if ($offer['offer_listing_title']): ?>
                  <div class="store-swap-product">
                    <div class="store-swap-product__thumb store-swap-product__thumb--gold">
                      <?php if (!empty($offer['offer_listing_thumb'])): ?>
                      <img src="<?= UPLOAD_URL . h($offer['offer_listing_thumb']) ?>" alt="<?= h($offer['offer_listing_title']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">
                      <?php else: ?>
                      <i class="bi bi-gem"></i>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="store-swap-product__name"><?= h($offer['offer_listing_title']) ?></div>
                      <div class="store-swap-product__price">ارزش: <?= fmt_credit((float)($offer['offer_listing_value'] ?? 0)) ?></div>
                    </div>
                  </div>
                  <?php if ($offerImages): ?>
                  <div class="store-swap-gallery">
                    <?php foreach ($offerImages as $img): ?>
                    <div class="store-swap-gallery__item">
                      <img src="<?= UPLOAD_URL . h($img['filename']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                  <?php else: ?>
                  <div class="store-swap-product">
                    <div class="store-swap-product__thumb store-swap-product__thumb--gold"><i class="bi bi-chat-left-text"></i></div>
                    <div>
                      <div class="store-swap-product__name">پیشنهاد بدون کالا</div>
                      <?php if ($offer['message']): ?>
                      <div class="store-swap-product__price"><?= h(mb_strimwidth($offer['message'], 0, 80, '…')) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if ((float)$offer['offer_credit'] != 0.0): ?>
                  <div class="store-swap-product__price" style="margin-top:8px">
                    <?= (float)$offer['offer_credit'] > 0 ? 'افزایش اعتبار: ' : 'کسر اعتبار: ' ?><?= fmt_credit(abs((float)$offer['offer_credit'])) ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="store-request__footer">
                <div class="store-request__buyer store-request__buyer--inline">
                  <div class="store-avatar"><?= h(user_initial($offer['from_name'])) ?></div>
                  <div>
                    <div class="store-request__name"><?= h($offer['from_name']) ?></div>
                    <div class="store-request__time"><?= persian_datetime($offer['created_at']) ?></div>
                  </div>
                </div>
                <div class="store-request__actions store-request__actions--forms">
                  <form method="POST" class="store-offer-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                    <input type="hidden" name="action" value="accept">
                    <textarea name="message" class="store-form-input store-form-input--sm" rows="2" required placeholder="پیام پذیرش..."></textarea>
                    <button type="submit" class="store-btn store-btn--sm store-btn--success"><i class="bi bi-check"></i> قبول و ورود به اتاق امن</button>
                  </form>
                  <form method="POST" class="store-offer-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <textarea name="message" class="store-form-input store-form-input--sm" rows="2" required placeholder="پیام رد..."></textarea>
                    <button type="submit" class="store-btn store-btn--sm store-btn--danger"><i class="bi bi-x"></i> رد</button>
                  </form>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== TAB: ORDERS (cash sales) ========== -->
    <div class="store-tab-panel" data-tab-panel="orders">
      <div class="store-card">
        <div class="store-card__header">
          <h3 class="store-card__title"><i class="bi bi-bag-check"></i> سفارش‌های خرید نقدی</h3>
        </div>
        <div class="store-card__body store-card__body--nopad">
          <?php if (empty($sellerOrders)): ?>
          <div class="store-empty">
            <i class="bi bi-bag"></i>
            <p>هنوز سفارش نقدی ثبت نشده است.</p>
          </div>
          <?php else: ?>
          <div class="store-requests">
            <?php foreach ($sellerOrders as $order): ?>
            <div class="store-request">
              <div class="store-request__buyer">
                <div class="store-avatar"><?= h(user_initial($order['buyer_name'])) ?></div>
                <div>
                  <div class="store-request__name"><?= h($order['buyer_name']) ?></div>
                  <div class="store-request__time"><?= h($order['order_code']) ?> — <?= timeago($order['created_at']) ?></div>
                </div>
              </div>
              <div class="store-request__product">
                <div class="store-request__label">محصول</div>
                <div class="store-request__title"><?= h($order['listing_title']) ?></div>
              </div>
              <div class="store-request__price">
                <div class="store-request__label">مبلغ</div>
                <div class="store-request__amount"><?= fmt_credit((float)$order['amount']) ?></div>
              </div>
              <div class="store-request__status store-request__status--<?= in_array($order['status'], ['paid','preparing'], true) ? 'pending' : ($order['status'] === 'delivered' ? 'accepted' : 'pending') ?>">
                <?= h(store_order_status_label($order['status'])) ?>
              </div>
              <div class="store-request__actions">
                <a href="<?= APP_URL ?>/orders/view.php?id=<?= (int)$order['id'] ?>" class="store-btn store-btn--sm store-btn--navy">
                  <i class="bi bi-eye"></i> مدیریت و ارسال
                </a>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
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
      <div class="store-grid-2" style="grid-template-columns:1fr">
        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-info-circle-fill"></i> اطلاعات فروشگاه</h3>
          </div>
          <div class="store-card__body">
            <div class="store-alert store-alert--info" style="margin-bottom:24px">
              <i class="bi bi-shield-lock-fill"></i>
              <div style="flex:1">
                <strong>مدیریت اطلاعات فروشگاه فقط از طریق پنل مدیریت امکان‌پذیر است.</strong><br>
                برای ایجاد فروشگاه یا ویرایش اطلاعات آن، لطفاً با ادمین سایت تماس بگیرید.
              </div>
            </div>

            <?php if (!empty($user['store_name'])): ?>
            <div style="background:var(--bg-soft);border-radius:16px;padding:24px;border:1px solid var(--border)">
              <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)">
                <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px">
                  <i class="bi bi-shop"></i>
                </div>
                <div>
                  <div style="font-size:18px;font-weight:800;color:var(--text)"><?= h($user['store_name']) ?></div>
                  <?php if (!empty($user['store_slug'])): ?>
                  <a href="<?= APP_URL ?>/shop/<?= h($user['store_slug']) ?>" target="_blank" style="font-size:13px;color:var(--primary);font-weight:600;text-decoration:none;margin-top:4px;display:inline-flex;align-items:center;gap:4px">
                    <i class="bi bi-box-arrow-up-right"></i> مشاهده صفحه فروشگاه
                  </a>
                  <?php endif; ?>
                </div>
              </div>

              <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">
                <?php if (!empty($user['store_phone'])): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-2)">
                  <i class="bi bi-telephone-fill" style="color:var(--primary)"></i>
                  <span><?= h($user['store_phone']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user['store_address'])): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-2)">
                  <i class="bi bi-geo-alt-fill" style="color:#E5484D"></i>
                  <span><?= h($user['store_address']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user['store_opening_hours'])): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-2)">
                  <i class="bi bi-clock-fill" style="color:var(--accent)"></i>
                  <span><?= h($user['store_opening_hours']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user['store_website'])): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-2)">
                  <i class="bi bi-globe" style="color:var(--primary)"></i>
                  <span><?= h($user['store_website']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user['store_instagram'])): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-2)">
                  <i class="bi bi-instagram" style="color:#E1306C"></i>
                  <span><?= h($user['store_instagram']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user['store_telegram'])): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-2)">
                  <i class="bi bi-telegram" style="color:#229ED9"></i>
                  <span><?= h($user['store_telegram']) ?></span>
                </div>
                <?php endif; ?>
              </div>

              <?php if (!empty($user['store_description'])): ?>
              <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:8px">درباره فروشگاه</div>
                <p style="font-size:14px;color:var(--text-2);line-height:1.8;margin:0"><?= h($user['store_description']) ?></p>
              </div>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="background:var(--bg-soft);border-radius:16px;padding:40px;text-align:center;border:1px dashed var(--border)">
              <div style="width:72px;height:72px;border-radius:50%;background:#eef2ff;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
                <i class="bi bi-shop" style="font-size:32px;color:var(--primary)"></i>
              </div>
              <h3 style="font-size:18px;font-weight:800;color:var(--text);margin:0 0 8px">حساب فروشگاهی برای شما فعال نشده است</h3>
              <p style="font-size:14px;color:var(--text-2);margin:0 0 20px;line-height:1.8">
                برای فعال‌سازی حساب فروشگاهی و ایجاد صفحه اختصاصی فروشگاه،<br>
                لطفاً از طریق بخش پشتیبانی درخواست خود را ثبت کنید یا با ادمین سایت تماس بگیرید.
              </p>
              <a href="<?= APP_URL ?>/auth/store-login" class="store-btn store-btn--outline">
                <i class="bi bi-box-arrow-in-right"></i> ورود پنل فروشگاه (نام کاربری و رمز)
              </a>
            </div>
            <?php endif; ?>
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
            foreach ($storeCategories as $cat):
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
            <div class="store-stat-card__value"><?= $storeReports['conversion_rate'] ?>٪</div>
            <div class="store-stat-card__label">نرخ تبدیل بازدید به درخواست</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--gold"><i class="bi bi-trophy"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= h(mb_strimwidth($storeReports['top_product'], 0, 28, '…')) ?></div>
            <div class="store-stat-card__label">محبوب‌ترین محصول</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--purple"><i class="bi bi-arrow-left-right"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= h(mb_strimwidth($storeReports['top_swap_product'], 0, 28, '…')) ?></div>
            <div class="store-stat-card__label">بیشترین درخواست معاوضه</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--orange"><i class="bi bi-telephone"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= number_format($storeReports['contact_count']) ?></div>
            <div class="store-stat-card__label">تعداد تماس‌ها</div>
          </div>
        </div>
        <div class="store-stat-card">
          <div class="store-stat-card__icon store-stat-card__icon--navy"><i class="bi bi-bar-chart"></i></div>
          <div class="store-stat-card__content">
            <div class="store-stat-card__value"><?= ($storeReports['growth_percent'] >= 0 ? '+' : '') . $storeReports['growth_percent'] ?>٪</div>
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
            <?php if (empty($storeTopProducts)): ?>
            <div class="store-empty">
              <i class="bi bi-box-seam"></i>
              <p>هنوز محصولی برای نمایش آمار ندارید.</p>
            </div>
            <?php else: ?>
            <?php foreach ($storeTopProducts as $p): ?>
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
            <?php endif; ?>
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
            <?php if (empty($storeNotifs)): ?>
            <div class="store-empty">
              <i class="bi bi-bell"></i>
              <p>اعلان جدیدی ندارید.</p>
            </div>
            <?php else: ?>
            <?php foreach ($storeNotifs as $n): ?>
            <a href="<?= h($n['link']) ?>" class="store-notification-item <?= $n['unread'] ? 'is-unread' : '' ?>" style="text-decoration:none;color:inherit;display:flex">
              <div class="store-notification-item__icon store-notification-item__icon--<?= h($n['type']) ?>">
                <i class="bi <?= h($n['icon']) ?>"></i>
              </div>
              <div class="store-notification-item__content">
                <div class="store-notification-item__title"><?= h($n['title']) ?></div>
                <div class="store-notification-item__desc"><?= h($n['desc']) ?></div>
                <div class="store-notification-item__time"><?= h($n['time']) ?></div>
              </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
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
            <div class="store-alert store-alert--info" style="margin-bottom:20px">
              <i class="bi bi-shield-lock-fill"></i>
              <div style="flex:1">
                <strong>مدیریت اطلاعات فروشگاه فقط از طریق پنل مدیریت امکان‌پذیر است.</strong><br>
                برای تغییرات، لطفاً با ادمین سایت تماس بگیرید.
              </div>
            </div>

            <?php if (!empty($user['store_name'])): ?>
            <div style="background:var(--bg-soft);border-radius:12px;padding:18px;border:1px solid var(--border)">
              <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
                <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;color:#fff">
                  <i class="bi bi-shop" style="font-size:22px"></i>
                </div>
                <div>
                  <div style="font-size:16px;font-weight:800;color:var(--text)"><?= h($user['store_name']) ?></div>
                  <?php if (!empty($user['store_slug'])): ?>
                  <a href="<?= APP_URL ?>/shop/<?= h($user['store_slug']) ?>" target="_blank" style="font-size:12px;color:var(--primary);font-weight:600;text-decoration:none">
                    <i class="bi bi-box-arrow-up-right"></i> مشاهده صفحه فروشگاه
                  </a>
                  <?php endif; ?>
                </div>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px;color:var(--text-2)">
                <div><i class="bi bi-telephone-fill" style="color:var(--primary);margin-left:4px"></i> تلفن: <?= !empty($user['store_phone']) ? h($user['store_phone']) : '—' ?></div>
                <div><i class="bi bi-geo-alt-fill" style="color:#E5484D;margin-left:4px"></i> آدرس: <?= !empty($user['store_address']) ? h(mb_substr($user['store_address'],0,25)) . (mb_strlen($user['store_address'])>25?'...':'') : '—' ?></div>
                <div><i class="bi bi-clock-fill" style="color:var(--accent);margin-left:4px"></i> ساعات: <?= !empty($user['store_opening_hours']) ? h($user['store_opening_hours']) : '—' ?></div>
                <div><i class="bi bi-globe" style="color:var(--primary);margin-left:4px"></i> وب‌سایت: <?= !empty($user['store_website']) ? h($user['store_website']) : '—' ?></div>
              </div>
            </div>
            <?php else: ?>
            <div style="background:var(--bg-soft);border-radius:12px;padding:30px;text-align:center;border:1px dashed var(--border)">
              <i class="bi bi-shop" style="font-size:36px;color:var(--primary);opacity:.5;margin-bottom:10px;display:block"></i>
              <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px">حساب فروشگاهی فعال نیست</div>
              <div style="font-size:12px;color:var(--text-2)">برای فعال‌سازی با ادمین تماس بگیرید</div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="store-card">
          <div class="store-card__header">
            <h3 class="store-card__title"><i class="bi bi-shield-check"></i> احراز هویت و حساب بانکی</h3>
          </div>
          <div class="store-card__body">
            <div class="store-kyc-status <?= h($kycUi['class']) ?>">
              <i class="bi <?= h($kycUi['icon']) ?>"></i>
              <div>
                <div class="store-kyc-status__title"><?= h($kycUi['title']) ?></div>
                <div class="store-kyc-status__desc"><?= h($kycUi['desc']) ?></div>
              </div>
            </div>
            <?php if (!empty($user['bank_account'])): ?>
            <div class="store-divider"></div>
            <div class="store-form-group">
              <label class="store-form-label">شماره شبا</label>
              <input type="text" class="store-form-input" dir="ltr" value="<?= h(mask_bank_account($user['bank_account'])) ?>" readonly>
            </div>
            <?php endif; ?>
            <div class="store-form-group" style="margin-top:12px">
              <a href="<?= APP_URL ?>/profile/edit.php" class="store-btn store-btn--outline w-100">
                <i class="bi bi-shield-check"></i> مدیریت احراز هویت
              </a>
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

  const requestsCard = document.querySelector('[data-tab-panel="requests"] .store-card');
  if (requestsCard) {
    const subtabs = requestsCard.querySelectorAll('.store-subtab');
    const subpanels = requestsCard.querySelectorAll('.store-subtab-panel');
    subtabs.forEach(st => {
      st.addEventListener('click', () => {
        const target = st.dataset.subtab;
        subtabs.forEach(s => s.classList.remove('is-active'));
        subpanels.forEach(sp => sp.classList.remove('is-active'));
        st.classList.add('is-active');
        const sp = requestsCard.querySelector(`[data-subtab-panel="${target}"]`);
        if (sp) sp.classList.add('is-active');
      });
    });
  }

  const urlParams = new URLSearchParams(window.location.search);
  const tabParam = urlParams.get('tab');
  if (tabParam) {
    const tabBtn = document.querySelector(`.store-tab[data-tab="${tabParam}"]`);
    if (tabBtn) tabBtn.click();
  }
  const subtabParam = urlParams.get('subtab');
  if (subtabParam && requestsCard) {
    const subtabBtn = requestsCard.querySelector(`.store-subtab[data-subtab="${subtabParam}"]`);
    if (subtabBtn) subtabBtn.click();
  }

  const canvas = document.getElementById('storeChartCanvas');
  if (canvas && canvas.getContext) {
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    const monthLabels = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    const data1 = <?= json_encode(array_map('intval', $chartViews), JSON_UNESCAPED_UNICODE) ?>;
    const data2 = <?= json_encode(array_map('intval', $chartRequests), JSON_UNESCAPED_UNICODE) ?>;
    const max = Math.max(...data1, ...data2, 1) * 1.15;
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

    const points = [];

    function drawLine(data, color, fill, dotHighlight) {
      ctx.beginPath();
      ctx.moveTo(pad.l, pad.t + chartH - (data[0] / max * chartH));
      points[0] = {x: pad.l, ys: pad.t + chartH - (data[0] / max * chartH)};
      for (let i = 1; i < data.length; i++) {
        const x = pad.l + i * xStep;
        const y = pad.t + chartH - (data[i] / max * chartH);
        ctx.lineTo(x, y);
        points[i] = points[i] || {};
        points[i].x = x;
        if (dotHighlight === '#0B1F4D') points[i].y1 = y;
        else points[i].y2 = y;
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

    drawLine(data1, '#0B1F4D', 'rgba(11, 31, 77, 0.06)', '#0B1F4D');
    drawLine(data2, '#F5B400', null, '#F5B400');

    const tooltip = document.getElementById('storeChartTooltip');
    const ttTitle = document.getElementById('storeChartTooltip-title');
    const ttViews = document.getElementById('storeChartTooltip-views');
    const ttReqs  = document.getElementById('storeChartTooltip-reqs');

    function fmtFa(num) {
      return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, '،');
    }

    function showTooltip(idx, clientX, clientY) {
      if (!tooltip || idx < 0 || idx >= monthLabels.length) return hideTooltip();
      ttTitle.textContent = monthLabels[idx];
      ttViews.textContent = fmtFa(data1[idx]) + ' بازدید';
      ttReqs.textContent  = fmtFa(data2[idx]) + ' درخواست';
      const rect = canvas.getBoundingClientRect();
      const wrapRect = canvas.parentElement.getBoundingClientRect();
      const xRatio = canvas.width / rect.width;
      const canX = (clientX - rect.left) * xRatio;
      const relX = (clientX - wrapRect.left);
      const firstY = pad.t + chartH - Math.min(data1[idx], data2[idx]) / max * chartH - 14;
      const relY = (firstY / canvas.height) * rect.height + (rect.top - wrapRect.top);
      tooltip.style.left = relX + 'px';
      tooltip.style.top  = relY + 'px';
      tooltip.style.opacity = '1';
    }
    function hideTooltip() {
      if (tooltip) tooltip.style.opacity = '0';
    }
    function findNearestIdx(clientX) {
      const rect = canvas.getBoundingClientRect();
      const xRatio = canvas.width / rect.width;
      const canX = (clientX - rect.left) * xRatio;
      let minIdx = -1, minDist = Infinity;
      for (let i = 0; i < points.length; i++) {
        if (!points[i]) continue;
        const d = Math.abs((points[i].x) - canX);
        if (d < minDist) { minDist = d; minIdx = i; }
      }
      return (minDist <= (xStep / 2) + 4) ? minIdx : -1;
    }

    canvas.addEventListener('mousemove', (e) => {
      const idx = findNearestIdx(e.clientX);
      if (idx !== -1) showTooltip(idx, e.clientX, e.clientY);
      else hideTooltip();
    });
    canvas.addEventListener('mouseleave', hideTooltip);
  }

  window.addEventListener('load', () => window.scrollTo(0, 0));
  window.addEventListener('pageshow', () => window.scrollTo(0, 0));
})();
</script>

<?php render_footer(); ?>
