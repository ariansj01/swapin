<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();
$id   = (int)($_GET['id'] ?? 0);

$listing = DB::fetch(
    'SELECT l.*, u.name AS seller_name, u.avatar AS seller_avatar, u.rating AS seller_rating, u.rating_count AS seller_rating_count,
            u.city AS seller_city, u.credit_balance AS seller_credits, u.verification_level,
            u.created_at AS seller_since, c.name AS cat_name, c.slug AS cat_slug,
            t.id AS trade_id, t.status AS trade_status, t.user_a_id, t.user_b_id
     FROM listings l
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     LEFT JOIN trades t ON (t.listing_a_id = l.id OR t.listing_b_id = l.id) AND t.status IN (\'in_progress\', \'user_a_confirmed\', \'user_b_confirmed\')
     WHERE l.id = ?',
    [$id]
);

if (!$listing) {
    http_response_code(404);
    render_head('آگهی یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container"><div class="empty-state"><i class="bi bi-exclamation-circle"></i><h1>آگهی یافت نشد</h1><p>این آگهی ممکن است حذف یا معامله شده باشد.</p><a href="' . APP_URL . '/" class="btn btn-primary">مرور آگهی‌ها</a></div></div></main>';
    render_footer();
    exit;
}

$isOwner = $user && (int)$user['id'] === (int)$listing['user_id'];
$isAdmin = $user && is_admin_user($user);
$reviewStatus = $listing['review_status'] ?? 'approved';

if ($listing['status'] !== 'active' && !$isOwner && !$isAdmin) {
    http_response_code(404);
    render_head('آگهی یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container"><div class="empty-state"><i class="bi bi-exclamation-circle"></i><h1>آگهی یافت نشد</h1><p>این آگهی دیگر در دسترس نیست.</p><a href="' . APP_URL . '/" class="btn btn-primary">مرور آگهی‌ها</a></div></div></main>';
    render_footer();
    exit;
}

if ($reviewStatus !== 'approved' && !$isOwner && !$isAdmin) {
    http_response_code(404);
    render_head('آگهی یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container"><div class="empty-state"><i class="bi bi-hourglass-split"></i><h1>آگهی در دسترس نیست</h1><p>این آگهی هنوز تأیید نشده یا رد شده است.</p><a href="' . APP_URL . '/" class="btn btn-primary">مرور آگهی‌ها</a></div></div></main>';
    render_footer();
    exit;
}

// Increment views
DB::query('UPDATE listings SET views = views + 1 WHERE id = ?', [$id]);

// Images
$images = DB::fetchAll('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order', [$id]);

// Existing offers from current user
$myOffer = $user ? DB::fetch(
    'SELECT * FROM trade_offers WHERE listing_id = ? AND from_user_id = ? AND status = "pending" LIMIT 1',
    [$id, $user['id']]
) : null;

// User's active listings for offer
$myListings = $user ? DB::fetchAll(
    'SELECT l.id, l.title, l.condition, l.estimated_value, i.filename AS thumb
     FROM listings l
     LEFT JOIN listing_images i ON i.listing_id = l.id AND i.is_primary = 1
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved" AND l.id != ?
     ORDER BY l.created_at DESC',
    [$user['id'], $id]
) : [];

// Saved?
$isSaved = $user ? (bool)DB::fetch(
    'SELECT 1 FROM saved_listings WHERE user_id = ? AND listing_id = ?', [$user['id'], $id]
) : false;

// Related listings
$related = DB::fetchAll(
    'SELECT l.*, u.name AS seller_name, u.avatar AS seller_avatar, u.rating AS seller_rating,
            c.name AS cat_name,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     WHERE l.category_id = ? AND l.id != ? AND l.status = "active" AND l.review_status = "approved"
     ORDER BY l.created_at DESC LIMIT 4',
    [$listing['category_id'], $id]
);

// Handle offer submission
$offerError   = '';
$offerSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('listing_action', 40, 900);
    if (!$user) {
        header('Location: ' . APP_URL . '/auth/login?redirect=/listings/view%3Fid=' . $id); exit;
    }
    if ($_POST['action'] === 'make_offer') {
        if ($listing['user_id'] == $user['id']) {
            $offerError = 'نمی‌توانید برای آگهی خودتان پیشنهاد بدهید.';
        } elseif ($myOffer) {
            $offerError = 'شما از قبل یک پیشنهاد در انتظار برای این آگهی دارید.';
        } else {
            $offerType = clean($_POST['offer_type'] ?? 'message');
            $offerListingId = (int)($_POST['offer_listing_id'] ?? 0) ?: null;
            $message        = clean($_POST['message'] ?? '');
            $creditDirection = clean($_POST['credit_direction'] ?? 'none');
            $creditAmount    = (float)preg_replace('/[^\d.]/', '', (string)($_POST['credit_amount'] ?? '0'));
            $offerCredit     = 0.0;
            if ($creditDirection === 'pay' && $creditAmount > 0) {
                $offerCredit = $creditAmount;
            } elseif ($creditDirection === 'receive' && $creditAmount > 0) {
                $offerCredit = -$creditAmount;
            }

            if ($offerType === 'item') {
                if (!$myListings) {
                    $offerError = 'شما کالایی ندارید. ابتدا کالای خود را ثبت کنید.';
                } elseif (!$offerListingId) {
                    $offerError = 'لطفاً یکی از کالاهای خود را انتخاب کنید.';
                } elseif (!DB::fetch(
                    'SELECT id FROM listings WHERE id = ? AND user_id = ? AND status = "active" AND review_status = "approved"',
                    [$offerListingId, $user['id']]
                )) {
                    $offerError = 'آگهی انتخاب‌شده برای پیشنهاد معتبر نیست یا متعلق به شما نیست.';
                }
            } elseif ($offerType === 'message') {
                if (empty($message)) {
                    $offerError = 'برای ارسال پیشنهاد بدون کالا، لطفاً پیامی وارد کنید.';
                }
            }
            


            if (!$offerError) {
                DB::insert('trade_offers', [
                    'listing_id'       => $id,
                    'from_user_id'     => $user['id'],
                    'offer_listing_id' => $offerListingId,
                    'offer_credit'     => $offerCredit,
                    'message'          => $message ?: null,
                    'status'           => 'pending',
                ]);
                // Notify listing owner via message
                $threadId = 'offer_' . uniqid();
                $offerBody = $message ?: 'برای آگهی «' . $listing['title'] . '» پیشنهاد دادم.';

                DB::insert('messages', [
                    'thread_id'    => $threadId,
                    'from_user_id' => $user['id'],
                    'to_user_id'   => $listing['user_id'],
                    'offer_id'     => DB::lastId(),
                    'body'         => $offerBody,
                ]);
                $offerSuccess = 'پیشنهاد ارسال شد! فروشنده مطلع می‌شود.';
                $myOffer = DB::fetch('SELECT * FROM trade_offers WHERE listing_id = ? AND from_user_id = ? ORDER BY id DESC LIMIT 1', [$id, $user['id']]);
            }
        }
    } elseif ($_POST['action'] === 'buy_now') {
        $offerError = 'خرید مستقیم در سواپین غیرفعال است؛ فقط معاوضه مجاز است.';
    } elseif ($_POST['action'] === 'request_inspection') {
        if (!$user || (int)$listing['user_id'] !== (int)$user['id']) {
            $offerError = 'فقط صاحب آگهی می‌تواند بازرسی درخواست کند.';
        } else {
            $result = request_expert_inspection($id, $user['id']);
            if (isset($result['error'])) {
                $offerError = $result['error'];
            } else {
                $offerSuccess = 'درخواست بازرسی کارشناس ثبت شد! تیم با شما تماس می‌گیرد.';
                $listing = DB::fetch(
                    'SELECT l.*, u.name AS seller_name, u.avatar AS seller_avatar, u.rating AS seller_rating, u.rating_count AS seller_rating_count,
                            u.city AS seller_city, u.credit_balance AS seller_credits, u.verification_level,
                            u.created_at AS seller_since, c.name AS cat_name, c.slug AS cat_slug
                     FROM listings l JOIN users u ON u.id = l.user_id JOIN categories c ON c.id = l.category_id WHERE l.id = ?',
                    [$id]
                );
            }
        }
    } elseif ($_POST['action'] === 'save_listing') {
        if ($isSaved) {
            DB::query('DELETE FROM saved_listings WHERE user_id = ? AND listing_id = ?', [$user['id'], $id]);
            $isSaved = false;
        } else {
            DB::query('INSERT IGNORE INTO saved_listings (user_id, listing_id) VALUES (?,?)', [$user['id'], $id]);
            $isSaved = true;
        }
    }
}

$canBuy  = false; // سواپین — فقط معاوضه، بدون خرید مستقیم
$buyKbc  = 0;
$inspectionLabels = ['requested' => 'درخواست‌شده', 'pending' => 'در انتظار', 'approved' => 'تأیید‌شده', 'rejected' => 'رد شده'];
$sellerSwapScore    = compute_swap_score((int)$listing['user_id']);

$listingUrl = APP_URL . '/listings/view?id=' . $id;
$ogImage    = $images ? UPLOAD_URL . $images[0]['filename'] : LOGO_URL;
$metaDesc   = mb_strimwidth(strip_tags($listing['description']), 0, 160, '…');

$wantChips = array_values(array_filter(array_map(
    'trim',
    preg_split('/[,،]+/u', (string)($listing['want_in_return'] ?? ''))
)));
$tradeAccepted = $user && $listing['trade_id']
    && ((int)$user['id'] === (int)$listing['user_a_id'] || (int)$user['id'] === (int)$listing['user_b_id']);
$canOfferMobile = !$isOwner && $listing['status'] === 'active' && !$myOffer && !$tradeAccepted;
$loginRedirect = APP_URL . '/auth/login?redirect=' . urlencode('/listings/view?id=' . $id);

render_head($listing['title'], $metaDesc, [
    'canonical' => $listingUrl,
    'og_type'   => 'product',
    'og_image'  => $ogImage,
    'keywords'  => implode(', ', array_filter([$listing['cat_name'], $listing['city'], 'معاوضه', 'مبادله کالا'])),
    'json_ld'   => [
        seo_json_ld_product($listing, $ogImage, $listingUrl),
        seo_json_ld_breadcrumbs([
            ['name' => 'خانه', 'url' => APP_URL . '/'],
            ['name' => $listing['cat_name'], 'url' => APP_URL . '/?cat=' . $listing['cat_slug']],
            ['name' => $listing['title']],
        ]),
    ],
]);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/listing-view-mobile.css?v=<?= filemtime(__DIR__ . '/../src/css/listing-view-mobile.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/listing-view-desktop-new.css?v=<?= filemtime(__DIR__ . '/../src/css/listing-view-desktop-new.css') ?>">

<?php if (isset($_GET['pending']) || $reviewStatus === 'pending'): ?>
<div class="alert alert-warning" style="border-radius:0;border-inline-start:0;border-inline-end:0">
  <div class="container"><i class="bi bi-hourglass-split"></i> آگهی شما ثبت شد و در انتظار تأیید تیم <?= APP_NAME ?> است. پس از بررسی، در لیست آگهی‌ها نمایش داده می‌شود.</div>
</div>
<?php elseif ($reviewStatus === 'rejected'): ?>
<div class="alert alert-danger" style="border-radius:0;border-inline-start:0;border-inline-end:0">
  <div class="container"><i class="bi bi-x-circle"></i> این آگهی رد شده است.
    <?php if (!empty($listing['review_note'])): ?> دلیل: <?= h($listing['review_note']) ?><?php endif; ?>
    — <a href="<?= APP_URL ?>/listings/edit?id=<?= $id ?>">ویرایش و ارسال مجدد</a>
  </div>
</div>
<?php endif; ?>

<!-- ── Mobile layout ─────────────────────────────────────────────────────── -->
<main id="main-content" class="lv-mobile" data-listing-id="<?= $id ?>">

  <?php if ($offerError): ?>
  <div class="lv-alert-mobile lv-alert-mobile--error"><i class="bi bi-exclamation-circle"></i> <?= h($offerError) ?></div>
  <?php endif; ?>
  <?php if ($offerSuccess): ?>
  <div class="lv-alert-mobile lv-alert-mobile--success"><i class="bi bi-check-circle"></i> <?= h($offerSuccess) ?></div>
  <?php endif; ?>

  <!-- Gallery -->
  <div class="lv-gallery" aria-label="گالری تصاویر">
    <div class="lv-gallery__toolbar">
      <button type="button" class="lv-gallery__btn lv-gallery__btn--back" aria-label="بازگشت">
        <i class="bi bi-arrow-right"></i>
      </button>
      <div class="lv-gallery__actions">
        <button type="button" id="lv-share-btn" class="lv-gallery__btn" data-title="<?= h($listing['title']) ?>" aria-label="اشتراک‌گذاری">
          <i class="bi bi-share"></i>
        </button>
        <button type="button"
                class="lv-gallery__btn <?= $isSaved ? 'lv-gallery__btn--saved' : '' ?>"
                data-save-toggle="<?= $isSaved ? 'true' : 'false' ?>"
                data-listing-id="<?= $id ?>"
                aria-label="<?= $isSaved ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها' ?>">
          <i class="bi bi-<?= $isSaved ? 'heart-fill' : 'heart' ?>"></i>
        </button>
      </div>
    </div>

    <?php if ($images): ?>
    <div class="lv-gallery__track">
      <?php foreach ($images as $img): ?>
      <div class="lv-gallery__slide" style="cursor: zoom-in;" onclick="openImageLightbox('<?= UPLOAD_URL . h($img['filename']) ?>')">
        <img src="<?= UPLOAD_URL . h($img['filename']) ?>" alt="<?= h($listing['title']) ?>" loading="lazy">
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($images) > 1): ?>
    <div class="lv-gallery__counter" aria-live="polite">۱ از <?= count($images) ?></div>
    <?php endif; ?>
    <?php else: ?>
    <div class="lv-gallery__empty"><i class="bi bi-image"></i></div>
    <?php endif; ?>
  </div>

  <div class="lv-body">
    <h1 class="lv-title"><?= h($listing['title']) ?></h1>

    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:.875rem;color:var(--text-muted);">
      <span>فروشنده:</span>
      <strong style="color:var(--dash-navy);"><?= h($listing['seller_name']) ?></strong>
      <?php if ($listing['seller_city']): ?>
      <span>(<?= h($listing['seller_city']) ?>)</span>
      <?php endif; ?>
      <?php if ($listing['verification_level'] >= 2): ?>
      <span class="badge badge-success"><i class="bi bi-patch-check-fill"></i> تأییدشده</span>
      <?php endif; ?>
    </div>
    <div class="lv-price-row">
      <?php if ((float)$listing['estimated_value'] > 0): ?>
      <div>
        <span class="lv-price-label">ارزش تقریبی</span>
        <span class="lv-price"><?= fmt_credit((float)$listing['estimated_value']) ?></span>
      </div>
      <?php endif; ?>
      <?php if (($listing['listing_mode'] ?? 'swap') !== 'sell'): ?>
      <span class="lv-badge-swap"><i class="bi bi-arrow-left-right"></i> امکان معاوضه</span>
      <?php endif; ?>
    </div>

    <!-- Seller -->
    <div class="lv-seller">
      <?= avatar_html($listing['seller_avatar'] ?? null, $listing['seller_name'], 'md') ?>
      <div class="lv-seller__info">
        <div class="lv-seller__name"><a href="<?= APP_URL ?>/profile?id=<?= $listing['user_id'] ?>" class="lv-seller-name-link"><?= h($listing['seller_name']) ?></a></div>
        <div class="lv-seller__meta">
          <?php if ($listing['seller_city']): ?><span><?= h($listing['seller_city']) ?></span><?php endif; ?>
          <span><?= timeago($listing['created_at']) ?></span>
          <?php if ($listing['seller_rating'] > 0): ?>
          <span class="lv-chip lv-chip--gold"><i class="bi bi-star-fill"></i> <?= fmt_num((float)$listing['seller_rating'], 1) ?></span>
          <?php endif; ?>
          <span class="lv-chip lv-chip--info"><i class="bi bi-star"></i> <?= fmt_num((int)$sellerSwapScore['score']) ?></span>
        </div>
      </div>
      <a href="<?= APP_URL ?>/profile?id=<?= $listing['user_id'] ?>" class="lv-seller__link" aria-label="پروفایل فروشنده">
        <i class="bi bi-chevron-left"></i>
      </a>
    </div>

    <!-- Description -->
    <section class="lv-section">
      <h2 class="lv-section__title">درباره کالا</h2>
      <div class="lv-section__card">
        <p class="lv-desc is-collapsed"><?= h($listing['description']) ?></p>
        <button type="button" class="lv-read-more">مشاهده بیشتر <i class="bi bi-chevron-down"></i></button>
      </div>
    </section>

    <!-- Features -->
    <section class="lv-section">
      <h2 class="lv-section__title">ویژگی‌های کالا</h2>
      <div class="lv-chips">
        <span class="lv-chip"><?= condition_label($listing['condition']) ?></span>
        <span class="lv-chip"><i class="bi bi-tag"></i> <?= h($listing['cat_name']) ?></span>
        <?php if (($listing['listing_mode'] ?? 'swap') !== 'sell'): ?>
        <span class="lv-chip lv-chip--gold"><i class="bi bi-arrow-left-right"></i> معاوضه</span>
        <?php endif; ?>
        <?php if (!empty($listing['inspection_status']) && $listing['inspection_status'] !== 'none'): ?>
        <span class="lv-chip"><i class="bi bi-search"></i> <?= $inspectionLabels[$listing['inspection_status']] ?? $listing['inspection_status'] ?></span>
        <?php endif; ?>
        <?php if ($listing['city']): ?>
        <span class="lv-chip"><i class="bi bi-geo-alt"></i> <?= h($listing['city']) ?></span>
        <?php endif; ?>
      </div>
    </section>

    <!-- Wants -->
    <?php if (($listing['listing_mode'] ?? 'swap') !== 'sell' && !empty($listing['want_in_return'])): ?>
    <section class="lv-want-section">
      <h2 class="lv-section__title"><?= h($listing['seller_name']) ?> در ازای این کالا به دنبال چه چیزی هست؟</h2>
      <div class="lv-chips">
        <?php if ($wantChips): ?>
          <?php foreach ($wantChips as $chip): ?>
          <span class="lv-chip lv-chip--gold"><?= h($chip) ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="lv-chip lv-chip--gold"><?= h($listing['want_in_return']) ?></span>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($isOwner): ?>
    <section class="lv-section">
      <div class="lv-section__card">
        <p class="fs-sm mb-3" style="color:var(--text-muted)"><i class="bi bi-info-circle"></i> این آگهی متعلق به شماست.</p>
        <a href="<?= APP_URL ?>/listings/edit?id=<?= $id ?>" class="btn btn-outline w-100 mb-2"><i class="bi bi-pencil"></i> ویرایش</a>
        <a href="<?= APP_URL ?>/listings/offers?id=<?= $id ?>" class="btn btn-primary w-100"><i class="bi bi-inbox"></i> پیشنهادها</a>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <?php if ($related): ?>
  <section class="lv-related" aria-label="آگهی‌های مشابه">
    <h2 class="lv-section__title" style="padding-inline:16px">آگهی‌های مشابه</h2>
    <div class="listings-grid">
      <?php foreach ($related as $l): ?>
      <?php include __DIR__ . '/../includes/listing_card.php'; ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Bottom bar -->
  <?php if ($canOfferMobile): ?>
  <div class="lv-bottom-bar">
    <?php if (!$user): ?>
    <button type="button" class="lv-bottom-bar__btn" id="lv-open-offer" data-login-href="<?= h($loginRedirect) ?>">
      <i class="bi bi-send"></i> ارسال پیشنهاد
    </button>
    <?php elseif ($myListings): ?>
    <button type="button" class="lv-bottom-bar__btn" id="lv-open-offer">
      <i class="bi bi-send"></i> ارسال پیشنهاد
    </button>
    <?php else: ?>
    <a href="<?= APP_URL ?>/listings/create.php" class="lv-bottom-bar__btn" style="text-decoration:none">
      <i class="bi bi-plus-circle"></i> ثبت کالا برای پیشنهاد
    </a>
    <?php endif; ?>
  </div>
  <?php elseif ($myOffer): ?>
  <div class="lv-bottom-bar">
    <div class="lv-bottom-bar__status"><i class="bi bi-check-circle"></i> پیشنهاد شما در انتظار پاسخ فروشنده است</div>
  </div>
  <?php elseif ($tradeAccepted): ?>
  <div class="lv-bottom-bar">
    <a href="<?= APP_URL ?>/trades.php?trade=<?= $listing['trade_id'] ?>" class="lv-bottom-bar__btn" style="text-decoration:none">
      <i class="bi bi-arrow-left-right"></i> مشاهده معامله
    </a>
  </div>
  <?php endif; ?>
</main>

<!-- Offer sheet modal -->
<div class="lv-sheet" id="lv-offer-sheet" role="dialog" aria-modal="true" aria-labelledby="lv-sheet-title">
  <div class="lv-sheet__backdrop"></div>
  <div class="lv-sheet__panel">
    <div class="lv-sheet__header">
      <div class="lv-sheet__header-top">
        <button type="button" class="lv-sheet__close" aria-label="بستن">&times;</button>
        <h2 class="lv-sheet__title" id="lv-sheet-title">ارسال پیشنهاد برای <?= h($listing['seller_name']) ?></h2>
      </div>
      <p class="lv-sheet__subtitle">شما در ازای این کالا چه پیشنهادی دارید؟</p>
    </div>

    <form method="POST" id="lv-offer-form" class="lv-sheet__form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="make_offer">
      <input type="hidden" name="offer_type" value="item">
      <input type="hidden" name="credit_amount" value="">

      <div class="lv-sheet__body">
        <div class="lv-sheet__section">
          <h3 class="lv-sheet__section-title">انتخاب کالای پیشنهادی</h3>
          <?php if ($myListings): ?>
          <div class="lv-offer-items">
            <?php foreach ($myListings as $i => $ml): ?>
            <label class="lv-offer-item<?= $i === 0 ? ' is-selected' : '' ?>" data-listing-id="<?= $ml['id'] ?>">
              <input type="radio" class="lv-offer-item__radio" name="offer_listing_id"
                     value="<?= $ml['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
              <?php if ($ml['thumb']): ?>
              <img src="<?= UPLOAD_URL . h($ml['thumb']) ?>" alt="" class="lv-offer-item__thumb">
              <?php else: ?>
              <div class="lv-offer-item__thumb lv-offer-item__thumb--empty"><i class="bi bi-image"></i></div>
              <?php endif; ?>
              <div class="lv-offer-item__info">
                <div class="lv-offer-item__title"><?= h($ml['title']) ?></div>
                <div class="lv-offer-item__meta">
                  <span><?= condition_label($ml['condition']) ?></span>
                  <?php if ((float)$ml['estimated_value'] > 0): ?>
                  <span class="lv-offer-item__price"> · <?= fmt_credit((float)$ml['estimated_value']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="lv-empty-offer">
            <p><i class="bi bi-box-seam"></i> برای ارسال پیشنهاد، ابتدا یک کالا ثبت کنید.</p>
            <a href="<?= APP_URL ?>/listings/create.php" class="btn btn-primary">ثبت کالا</a>
          </div>
          <?php endif; ?>
        </div>

        <div class="lv-sheet__section">
          <h3 class="lv-sheet__section-title">آیا مایل به پرداخت یا دریافت اختلاف قیمت هستید؟</h3>
          <div class="lv-radio-group">
            <div class="lv-radio-option">
              <input type="radio" name="credit_direction" id="lv-credit-pay" value="pay">
              <label for="lv-credit-pay">پرداخت می‌کنم</label>
            </div>
            <div class="lv-radio-option">
              <input type="radio" name="credit_direction" id="lv-credit-receive" value="receive">
              <label for="lv-credit-receive">دریافت می‌کنم</label>
            </div>
            <div class="lv-radio-option">
              <input type="radio" name="credit_direction" id="lv-credit-none" value="none" checked>
              <label for="lv-credit-none">بدون اختلاف قیمت</label>
            </div>
          </div>
          <div class="lv-credit-amount">
            <input type="text" class="form-control" id="lv-credit-amount" inputmode="numeric"
                   placeholder="مبلغ به تومان" autocomplete="off">
            <p class="lv-credit-amount__hint">مبلغ اختلاف قیمت را وارد کنید</p>
          </div>
        </div>

        <div class="lv-sheet__section">
          <h3 class="lv-sheet__section-title">توضیحات (اختیاری)</h3>
          <textarea class="lv-sheet__textarea" name="message" rows="4"
                    placeholder="پیام خود را برای فروشنده بنویسید..."></textarea>
        </div>
      </div>

      <div class="lv-sheet__footer">
        <button type="submit" class="lv-sheet__submit" <?= $myListings ? '' : 'disabled' ?>>
          <i class="bi bi-send"></i> ارسال پیشنهاد
        </button>
      </div>
    </form>
  </div>
</div>

<main id="main-content-desktop" class="lv-desktop">
  <div class="container">
    <nav class="lv-breadcrumb" aria-label="مسیر صفحه">
      <a href="<?= APP_URL ?>/">خانه</a>
      <i class="bi bi-chevron-left"></i>
      <a href="<?= APP_URL ?>/?cat=<?= h($listing['cat_slug']) ?>"><?= h($listing['cat_name']) ?></a>
      <i class="bi bi-chevron-left"></i>
      <span><?= h(mb_strimwidth($listing['title'], 0, 40, '…')) ?></span>
    </nav>

    <div class="lv-main-grid">
      <div class="lv-left-col">
        <section class="lv-gallery-section">
          <div class="lv-gallery-main" style="cursor: zoom-in;" onclick="openImageLightbox(this.querySelector('img').src)">
            <?php if ($images): ?>
            <img id="lv-main-img" src="<?= UPLOAD_URL . h($images[0]['filename']) ?>" alt="<?= h($listing['title']) ?>" style="object-fit: contain;">
            <?php else: ?>
            <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--lv-muted);font-size:3rem"><i class="bi bi-image"></i></div>
            <?php endif; ?>
          </div>
          <?php if ($images && count($images) > 1): ?>
          <div class="lv-gallery-thumbs" aria-label="تصاویر آگهی">
            <?php foreach ($images as $i => $img): ?>
            <img src="<?= UPLOAD_URL . h($img['filename']) ?>"
                 alt="تصویر <?= $i + 1 ?>"
                 class="lv-gallery-thumb<?= $i === 0 ? ' active' : '' ?>"
                 onclick="event.stopPropagation();document.getElementById('lv-main-img').src='<?= UPLOAD_URL . h($img['filename']) ?>';document.querySelectorAll('.lv-gallery-thumb').forEach(function(t){t.classList.remove('active')});this.classList.add('active')">
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="lv-details-section">
          <h1 class="lv-title"><?= h($listing['title']) ?></h1>

          <div class="lv-meta-top">
            <span class="lv-chip lv-chip--primary"><i class="bi bi-tag"></i> <?= h($listing['cat_name']) ?></span>
            <span class="lv-chip lv-chip--success"><i class="bi bi-check-circle"></i> <?= condition_label($listing['condition']) ?></span>
            <?php if (($listing['listing_mode'] ?? 'swap') !== 'sell'): ?>
            <span class="lv-chip lv-chip--gold"><i class="bi bi-arrow-left-right"></i> قابل معاوضه</span>
            <?php endif; ?>
            <?php if (!empty($listing['inspection_status']) && $listing['inspection_status'] !== 'none'): ?>
            <span class="lv-chip lv-chip--info"><i class="bi bi-search"></i> <?= $inspectionLabels[$listing['inspection_status']] ?? $listing['inspection_status'] ?></span>
            <?php endif; ?>
            <?php if ($listing['city']): ?>
            <span class="lv-chip lv-chip--primary"><i class="bi bi-geo-alt"></i> <?= h($listing['city']) ?></span>
            <?php endif; ?>
            <span class="lv-chip lv-chip--primary"><i class="bi bi-eye"></i> <?= fmt_num((int)$listing['views']) ?> بازدید</span>
            <span class="lv-chip lv-chip--primary"><i class="bi bi-clock"></i> <?= timeago($listing['created_at']) ?></span>
          </div>

          <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:.875rem;color:var(--text-muted);">
            <span>فروشنده:</span>
            <a href="<?= APP_URL ?>/profile?id=<?= $listing['user_id'] ?>" style="color:var(--dash-navy);text-decoration:none;font-weight:bold;"><?= h($listing['seller_name']) ?></a>
            <?php if ($listing['seller_city']): ?>
            <span>(<?= h($listing['seller_city']) ?>)</span>
            <?php endif; ?>
            <?php if ($listing['verification_level'] >= 2): ?>
            <span class="badge badge-success"><i class="bi bi-patch-check-fill"></i> تأییدشده</span>
            <?php endif; ?>
            <?php if ($listing['seller_rating'] > 0): ?>
            <span class="badge badge-primary"><i class="bi bi-star-fill"></i> <?= fmt_num((float)$listing['seller_rating'], 1) ?></span>
            <?php endif; ?>
          </div>
          <?php if ((float)$listing['estimated_value'] > 0): ?>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
            <span style="font-size:.8125rem;color:var(--lv-muted)">ارزش تقریبی</span>
            <strong style="font-size:1.35rem;color:var(--lv-navy)"><?= fmt_credit((float)$listing['estimated_value']) ?></strong>
          </div>
          <?php endif; ?>

          <div class="lv-divider"></div>
          <h3 class="lv-section-title">توضیحات</h3>
          <div class="lv-description"><?= nl2br(h($listing['description'])) ?></div>

          <?php if (($listing['listing_mode'] ?? 'swap') !== 'sell' && !empty($listing['want_in_return'])): ?>
          <div class="lv-divider"></div>
          <div class="lv-wants">
            <div class="lv-wants-label"><i class="bi bi-stars"></i> <?= h($listing['seller_name']) ?> در ازای این کالا به دنبال چه چیزی است؟</div>
            <p class="lv-wants-text"><?= h($listing['want_in_return']) ?></p>
          </div>
          <?php endif; ?>
        </section>

        <?php if ($related): ?>
        <section class="lv-related-section" aria-label="آگهی‌های مشابه">
          <h3 class="lv-related-title">آگهی‌های مشابه</h3>
          <div class="listings-grid">
            <?php foreach ($related as $l): ?>
            <?php include __DIR__ . '/../includes/listing_card.php'; ?>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>
      </div>

      <aside class="lv-right-col" aria-label="اطلاعات فروشنده و اقدامات">
        <?php if ($isOwner): ?>
        <section class="lv-offer-card">
          <h3 class="lv-offer-title"><i class="bi bi-gear"></i> مدیریت آگهی</h3>
          <div class="lv-seller-actions">
            <a href="<?= APP_URL ?>/listings/edit?id=<?= $id ?>" class="lv-btn lv-btn--outline"><i class="bi bi-pencil"></i> ویرایش آگهی</a>
            <a href="<?= APP_URL ?>/listings/promote?id=<?= $id ?>" class="lv-btn lv-btn--outline"><i class="bi bi-rocket"></i> ارتقای آگهی</a>
            <?php if (($listing['inspection_status'] ?? 'none') === 'none'): ?>
            <form method="POST" onsubmit="return confirm('بازرسی کارشناس با هزینه <?= fmt_credit(INSPECTION_KBC) ?> درخواست شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="request_inspection">
              <button type="submit" class="lv-btn lv-btn--outline"><i class="bi bi-search"></i> درخواست بازرسی</button>
            </form>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/listings/offers?id=<?= $id ?>" class="lv-btn lv-btn--primary"><i class="bi bi-inbox"></i> مشاهده پیشنهادها</a>
          </div>
        </section>
        <?php elseif ($tradeAccepted): ?>
        <section class="lv-offer-card">
          <h3 class="lv-offer-title"><i class="bi bi-check-circle"></i> وضعیت پیشنهاد</h3>
          <div class="alert alert-success" style="margin:0">
            پیشنهاد شما پذیرفته شده است.
            <a href="<?= APP_URL ?>/trades.php?trade=<?= $listing['trade_id'] ?>" class="alert-link">مشاهده معامله</a>
          </div>
        </section>
        <?php elseif ($myOffer): ?>
        <section class="lv-offer-card">
          <h3 class="lv-offer-title"><i class="bi bi-hourglass-split"></i> وضعیت پیشنهاد</h3>
          <div class="alert alert-success" style="margin:0">پیشنهاد شما در انتظار پاسخ فروشنده است.</div>
        </section>
        <?php elseif ($listing['status'] !== 'active'): ?>
        <section class="lv-offer-card">
          <h3 class="lv-offer-title"><i class="bi bi-exclamation-triangle"></i> وضعیت آگهی</h3>
          <div class="alert alert-warning" style="margin:0">این آگهی فعلاً برای معامله در دسترس نیست.</div>
        </section>
        <?php else: ?>
        <section class="lv-offer-card">
          <h3 class="lv-offer-title"><i class="bi bi-send"></i> ارسال پیشنهاد</h3>

          <?php if (!$user): ?>
          <p class="fs-sm mb-4" style="color:var(--text-muted)">برای ارسال پیشنهاد وارد شوید.</p>
          <a href="<?= $loginRedirect ?>" class="lv-btn lv-btn--primary">ورود برای پیشنهاد</a>
          <?php else: ?>
          <?php if ($offerError): ?>
          <div class="alert alert-danger mb-4"><i class="bi bi-exclamation-circle"></i> <?= h($offerError) ?></div>
          <?php endif; ?>
          <?php if ($offerSuccess): ?>
          <div class="alert alert-success mb-4"><i class="bi bi-check-circle"></i> <?= h($offerSuccess) ?></div>
          <?php endif; ?>

          <form method="POST" id="offer-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="make_offer">
            <input type="hidden" name="offer_type" id="lv-offer-type-input" value="<?= $myListings ? 'item' : 'message' ?>">

            <div class="lv-offer-types">
              <button type="button" class="lv-offer-type<?= $myListings ? ' active' : '' ?>" data-type="item">
                <div class="lv-offer-icon"><i class="bi bi-box-seam"></i></div>
                <div class="lv-offer-type-info">
                  <div class="lv-offer-type-title">کالا دارم</div>
                  <p class="lv-offer-type-desc">یک کالای ثبت‌شده را برای معاوضه انتخاب کنید</p>
                </div>
              </button>
              <button type="button" class="lv-offer-type<?= !$myListings ? ' active' : '' ?>" data-type="message">
                <div class="lv-offer-icon"><i class="bi bi-chat-dots"></i></div>
                <div class="lv-offer-type-info">
                  <div class="lv-offer-type-title">فقط پیام می‌فرستم</div>
                  <p class="lv-offer-type-desc">اگر کالایی ندارید، مستقیم پیام بگذارید</p>
                </div>
              </button>
            </div>

            <div id="lv-offer-item-section">
              <?php if ($myListings): ?>
              <select class="form-control lv-offer-select" name="offer_listing_id" id="offer-listing">
                <option value="">یکی از کالاهای خود را انتخاب کنید</option>
                <?php foreach ($myListings as $ml): ?>
                <option value="<?= $ml['id'] ?>"><?= h(mb_strimwidth($ml['title'], 0, 50, '…')) ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <div class="alert alert-info mb-3">
                <i class="bi bi-box-seam"></i> هنوز کالایی برای پیشنهاد ندارید.
                <a href="<?= APP_URL ?>/listings/create.php" class="btn btn-primary btn-sm" style="margin-top:8px">ثبت کالا</a>
              </div>
              <?php endif; ?>
            </div>

            <div class="form-group" style="margin-top:12px">
              <label class="form-label">پیام</label>
              <textarea class="lv-offer-textarea" name="message" id="lv-offer-message" placeholder="در مورد پیشنهادتان کمی توضیح بدهید..."></textarea>
            </div>

            <button type="submit" class="lv-btn lv-btn--primary">
              <i class="bi bi-send"></i> ارسال پیشنهاد
            </button>
          </form>
          <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="lv-action-card">
          <form method="POST" style="flex:1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_listing">
            <button type="submit" class="lv-action-btn<?= $isSaved ? ' saved' : '' ?>">
              <i class="bi bi-<?= $isSaved ? 'heart-fill' : 'heart' ?>"></i>
              <?= $isSaved ? 'ذخیره شده' : 'ذخیره' ?>
            </button>
          </form>
          <button type="button" id="share-listing-btn" class="lv-action-btn">
            <i class="bi bi-share"></i>
            اشتراک
          </button>
        </section>
      </aside>
    </div>
  </div>
</main>

<script>
const offerTypeInput = document.getElementById('lv-offer-type-input');
const offerItemSection = document.getElementById('lv-offer-item-section');
const offerTypeButtons = document.querySelectorAll('.lv-offer-type');

function lvSetOfferType(type) {
  if (!offerTypeInput) return;
  offerTypeInput.value = type;
  offerTypeButtons.forEach(function (button) {
    button.classList.toggle('active', button.dataset.type === type);
  });
  if (offerItemSection) {
    offerItemSection.style.display = type === 'item' ? '' : 'none';
  }
}

offerTypeButtons.forEach(function (button) {
  button.addEventListener('click', function () {
    lvSetOfferType(button.dataset.type || 'message');
  });
});

if (offerTypeInput) {
  lvSetOfferType(offerTypeInput.value || 'message');
}

document.getElementById('offer-form')?.addEventListener('submit', function (e) {
  const type = offerTypeInput?.value || 'message';
  const message = document.getElementById('lv-offer-message')?.value.trim() || '';
  if (type === 'item') {
    const listing = document.getElementById('offer-listing');
    if (!listing || !listing.value) {
      e.preventDefault();
      showToast('لطفاً یکی از کالاهای خود را انتخاب کنید.', 'error');
      return;
    }
  }
  if (type === 'message' && !message) {
    e.preventDefault();
    showToast('برای ارسال پیشنهاد بدون کالا، پیام لازم است.', 'error');
  }
});

document.getElementById('share-listing-btn')?.addEventListener('click', function () {
  const title = '<?= addslashes($listing['title']) ?>';
  if (navigator.share) {
    navigator.share({ title, url: location.href }).catch(() => {});
  } else {
    navigator.clipboard.writeText(location.href).then(() => showToast('لینک کپی شد!', 'success'));
  }
});
</script>
<?php if ($offerError && ($_POST['action'] ?? '') === 'make_offer' && ($_POST['offer_type'] ?? '') === 'item'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.matchMedia('(max-width: 768px)').matches) return;
  var sheet = document.getElementById('lv-offer-sheet');
  if (sheet) {
    sheet.classList.add('is-open');
    document.body.classList.add('lv-modal-open');
  }
});
</script>
<?php endif; ?>
<!-- Image Lightbox Modal -->
<div id="image-lightbox" class="image-lightbox" onclick="closeImageLightbox()" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.9);z-index:9999;justify-content:center;align-items:center;cursor:zoom-out;">
  <button type="button" onclick="event.stopPropagation();closeImageLightbox()" style="position:absolute;top:20px;right:20px;background:none;border:none;color:white;font-size:2rem;cursor:pointer;">
    <i class="bi bi-x-lg"></i>
  </button>
  <img id="lightbox-img" src="" style="max-width:90%;max-height:90%;object-fit:contain;">
</div>

<script>
function openImageLightbox(imgSrc) {
  const lightbox = document.getElementById('image-lightbox');
  const img = document.getElementById('lightbox-img');
  img.src = imgSrc;
  lightbox.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeImageLightbox() {
  const lightbox = document.getElementById('image-lightbox');
  lightbox.style.display = 'none';
  document.body.style.overflow = '';
}

// Close lightbox on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeImageLightbox();
  }
});
</script>
<script src="<?= APP_URL ?>/src/js/listing-view-mobile.js?v=<?= filemtime(__DIR__ . '/../src/js/listing-view-mobile.js') ?>"></script>

<?php render_footer(); ?>
