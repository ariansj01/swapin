<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();
$id   = (int)($_GET['id'] ?? 0);

$listing = DB::fetch(
    'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.rating_count AS seller_rating_count,
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
    'SELECT l.id, l.title, i.filename AS thumb
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
    'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating,
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
                    'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.rating_count AS seller_rating_count,
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

<main id="main-content" class="section-sm">
  <div class="container">

    <!-- Breadcrumb -->
    <nav aria-label="مسیر صفحه" style="font-size:.875rem;color:var(--text-muted);margin-bottom:var(--sp-5)">
      <ol style="display:flex;flex-wrap:wrap;align-items:center;gap:var(--sp-2);list-style:none;margin:0;padding:0">
        <li><a href="<?= APP_URL ?>/">خانه</a></li>
        <li aria-hidden="true"><i class="bi bi-chevron-left" style="font-size:.7rem"></i></li>
        <li><a href="<?= APP_URL ?>/?cat=<?= h($listing['cat_slug']) ?>"><?= h($listing['cat_name']) ?></a></li>
        <li aria-hidden="true"><i class="bi bi-chevron-left" style="font-size:.7rem"></i></li>
        <li aria-current="page"><?= h(mb_strimwidth($listing['title'], 0, 40, '…')) ?></li>
      </ol>
    </nav>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:var(--sp-8);align-items:start">

      <!-- ── Left: Listing Content ─────────────────────────────────── -->
      <article>
        <!-- Image Gallery -->
        <?php if ($images): ?>
        <figure class="listing-gallery__main is-loading" style="margin-bottom:var(--sp-5)">
          <div class="skeleton skeleton-block skeleton-block--hero" aria-hidden="true"></div>
          <img id="main-img" src="<?= UPLOAD_URL . h($images[0]['filename']) ?>"
               alt="<?= h($listing['title']) ?>"
               style="width:100%;max-height:480px;object-fit:cover;display:block;border-radius:var(--radius-lg)">
        </figure>
        <?php if (count($images) > 1): ?>
        <nav aria-label="تصاویر آگهی" style="display:flex;gap:var(--sp-3);overflow-x:auto;margin-bottom:var(--sp-5)">
          <?php foreach ($images as $i => $img): ?>
          <img src="<?= UPLOAD_URL . h($img['filename']) ?>"
               alt="تصویر <?= $i+1 ?>"
               onclick="document.getElementById('main-img').src=this.src;document.querySelectorAll('.thumb-img').forEach(t=>t.style.outline='none');this.style.outline='2.5px solid var(--primary)'"
               class="thumb-img"
               style="width:72px;height:72px;object-fit:cover;border-radius:var(--radius-md);cursor:pointer;flex-shrink:0;<?= $i===0 ? 'outline:2.5px solid var(--primary)' : '' ?>">
          <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <?php else: ?>
        <figure style="background:var(--bg);border-radius:var(--radius-lg);height:280px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:3rem;margin-bottom:var(--sp-5)" aria-label="بدون تصویر">
          <i class="bi bi-image" aria-hidden="true"></i>
        </figure>
        <?php endif; ?>

        <!-- Listing Details -->
        <div class="card mb-5">
          <div class="card-body">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4)">
              <div>
                <h1 style="font-size:1.5rem;margin-bottom:var(--sp-2)"><?= h($listing['title']) ?></h1>
                <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;align-items:center">
                  <span class="badge badge-<?= ['new'=>'success','like_new'=>'success','good'=>'info','fair'=>'warning','poor'=>'danger'][$listing['condition']] ?>">
                    <?= condition_label($listing['condition']) ?>
                  </span>
                  <span class="badge badge-primary"><i class="bi bi-tag"></i> <?= h($listing['cat_name']) ?></span>
                  <span class="badge badge-gold"><i class="bi bi-arrow-left-right"></i> معاوضه</span>
                  <?php if (!empty($listing['inspection_status']) && $listing['inspection_status'] !== 'none'): ?>
                  <span class="badge badge-warning"><i class="bi bi-search"></i> <?= $inspectionLabels[$listing['inspection_status']] ?? $listing['inspection_status'] ?></span>
                  <?php endif; ?>
                  <?= listing_promotion_badges_html($listing) ?>
                  <?php if ($listing['city']): ?>
                  <span style="font-size:.875rem;color:var(--text-muted)"><i class="bi bi-geo-alt"></i> <?= h($listing['city']) ?></span>
                  <?php endif; ?>
                  <span style="font-size:.8125rem;color:var(--text-muted)"><i class="bi bi-clock"></i> <?= timeago($listing['created_at']) ?></span>
                  <span style="font-size:.8125rem;color:var(--text-muted)"><i class="bi bi-eye"></i> <?= number_format($listing['views']) ?> بازدید</span>
                </div>
              </div>
              <?php if ($listing['estimated_value'] > 0): ?>
              <div style="text-align:left;flex-shrink:0">
                <div style="font-size:.75rem;color:var(--text-muted)">ارزش تقریبی</div>
                <div style="font-size:1.5rem;font-weight:800;color:var(--accent-dark)"><?= fmt_credit((float)$listing['estimated_value']) ?></div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Description -->
        <div class="card mb-5">
          <div class="card-header"><h3 style="margin:0;font-size:1rem">توضیحات</h3></div>
          <div class="card-body">
            <p style="white-space:pre-wrap;line-height:1.7"><?= h($listing['description']) ?></p>
          </div>
        </div>

        <!-- What Seller Wants -->
        <?php if (($listing['listing_mode'] ?? 'swap') !== 'sell'): ?>
        <div class="card mb-5" style="border-color:rgba(0,174,239,.25);background:rgba(0,174,239,.02)">
          <div class="card-header" style="border-color:rgba(0,174,239,.2)">
            <div style="display:flex;align-items:center;gap:var(--sp-3)">
              <div style="width:36px;height:36px;background:var(--gradient-brand);border-radius:50%;display:flex;align-items:center;justify-content:center">
                <i class="bi bi-arrow-left-right" style="color:#fff"></i>
              </div>
              <div>
                <h3 style="margin:0;font-size:1rem">در ازای آن می‌خواهد</h3>
                <div class="fs-xs" style="color:var(--text-muted)">
                  <?= ['item'=>'به دنبال یک کالا','service'=>'به دنبال یک خدمت','credit'=>'به دنبال اعتبار ' . CREDIT_UNIT,'any'=>'به هر نوع معامله‌ای باز است'][$listing['want_type']] ?>
                </div>
              </div>
            </div>
          </div>
          <div class="card-body">
            <p style="font-size:1.0625rem;font-weight:500;line-height:1.6"><?= h($listing['want_in_return']) ?></p>
          </div>
        </div>
        <?php endif; ?>

        <!-- Related Listings -->
        <?php if ($related): ?>
        <section aria-label="آگهی‌های مشابه">
        <h3 class="mb-4">آگهی‌های مشابه</h3>
        <div class="listings-grid">
          <?php foreach ($related as $l): ?>
          <?php include __DIR__ . '/../includes/listing_card.php'; ?>
          <?php endforeach; ?>
        </div>
        </section>
        <?php endif; ?>
      </article>

      <!-- ── Right: Sidebar ─────────────────────────────────────────── -->
      <aside style="position:sticky;top:80px" aria-label="اطلاعات فروشنده و اقدامات">

        <!-- Seller Card -->
        <div class="card mb-4">
          <div class="card-body">
            <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-4)">
              <div class="avatar avatar-md"><?= strtoupper(substr($listing['seller_name'], 0, 1)) ?></div>
              <div>
                <div style="font-weight:700"><?= h($listing['seller_name']) ?></div>
                <?php if ($listing['seller_city']): ?>
                <div class="fs-sm" style="color:var(--text-muted)"><i class="bi bi-geo-alt"></i> <?= h($listing['seller_city']) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($listing['verification_level'] >= 2): ?>
              <span class="badge badge-success" style="margin-inline-start:auto"><i class="bi bi-patch-check"></i> تأیید‌شده</span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:var(--sp-4);font-size:.875rem;color:var(--text-secondary);margin-bottom:var(--sp-3);flex-wrap:wrap">
              <?php if ($listing['seller_rating'] > 0): ?>
              <span><i class="bi bi-star-fill" style="color:var(--accent)"></i> <?= number_format((float)$listing['seller_rating'], 1) ?> (<?= $listing['seller_rating_count'] ?>)</span>
              <?php endif; ?>
              <span><i class="bi bi-shield-check" style="color:var(--accent-dark)"></i>نمره سواَپین: <?= $sellerSwapScore['score'] ?>/100</span>
              <span><i class="bi bi-calendar3"></i> از <?= persian_date($listing['seller_since']) ?></span>
            </div>
            <a href="<?= APP_URL ?>/profile?id=<?= $listing['user_id'] ?>" class="btn btn-outline w-100 btn-sm">مشاهده پروفایل</a>
          </div>
        </div>

        <!-- Action Card -->
        <?php if ($isOwner): ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="alert alert-info mb-4" style="font-size:.875rem">
              <i class="bi bi-info-circle"></i> این آگهی متعلق به شماست.
            </div>
            <a href="<?= APP_URL ?>/listings/edit?id=<?= $id ?>" class="btn btn-outline w-100 mb-3">
              <i class="bi bi-pencil"></i> ویرایش آگهی
            </a>
            <a href="<?= APP_URL ?>/listings/promote?id=<?= $id ?>" class="btn btn-accent w-100 mb-3">
              <i class="bi bi-rocket"></i> ارتقای آگهی
            </a>
            <?php if (($listing['inspection_status'] ?? 'none') === 'none'): ?>
            <form method="POST" class="mb-3" onsubmit="return confirm('بازرسی کارشناس با هزینه <?= fmt_credit(INSPECTION_KBC) ?> درخواست شود؟')">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="request_inspection">
              <button type="submit" class="btn btn-outline w-100">
                <i class="bi bi-search"></i> درخواست بازرسی کارشناس
              </button>
            </form>
            <?php else: ?>
            <div class="alert alert-info mb-3 fs-sm">
              <i class="bi bi-search"></i> بازرسی: <?= $inspectionLabels[$listing['inspection_status']] ?? $listing['inspection_status'] ?>
            </div>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/listings/offers?id=<?= $id ?>" class="btn btn-primary w-100">
              <i class="bi bi-inbox"></i> مشاهده پیشنهادها
            </a>
          </div>
        </div>
 <?php endif; ?>
        <?php if ($listing['status'] === 'traded' && $isOwner): ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="alert alert-success" style="font-size:.875rem">
              <i class="bi bi-check-circle"></i> پیشنهاد برای این آگهی پذیرفته شده و معامله در جریان است.
              <?php if ($listing['trade_id']): ?>
                <a href="<?= APP_URL ?>/trades.php?trade=<?= $listing['trade_id'] ?>" class="alert-link">مشاهده جزئیات معامله</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php elseif ($listing['status'] !== 'active'): ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="alert alert-warning" style="font-size:.875rem">
              <i class="bi bi-exclamation-triangle"></i> این آگهی دیگر برای معامله در دسترس نیست.
            </div>
          </div>
        </div>

        <?php else: ?>

        <?php if (false && $canBuy && $user): ?>
        <div class="card mb-4" style="border:2px solid var(--accent-dark)">
          <div class="card-body text-center">
            <div class="fs-sm" style="color:var(--text-muted)">خرید فوری</div>
            <div style="font-size:1.75rem;font-weight:800;color:var(--accent-dark);margin:var(--sp-2) 0">
              <?= number_format((float)$listing['sell_price'], 0) ?> تومان
            </div>
            <div class="fs-xs mb-4" style="color:var(--text-muted)">≈ <?= fmt_credit($buyKbc) ?> (در سپرده نگهداری می‌شود)</div>
            <form method="POST" onsubmit="return confirm('خرید با ~<?= $buyKbc ?> <?= CREDIT_UNIT ?>؟')">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="buy_now">
              <button type="submit" class="btn btn-accent w-100 btn-lg">
                <i class="bi bi-cart-check"></i> خرید فوری
              </button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <!-- Offer Form -->
        <div class="card mb-4">
          <div class="card-header">
            <h3 style="margin:0;font-size:1rem"><i class="bi bi-send"></i> ارسال پیشنهاد</h3>
          </div>
          <div class="card-body">

            <?php if (!$user): ?>
            <p class="fs-sm mb-4" style="color:var(--text-muted)">برای ارسال پیشنهاد وارد شوید.</p>
            <a href="<?= APP_URL ?>/auth/login?redirect=<?= urlencode('/listings/view?id='.$id) ?>" class="btn btn-primary w-100">
              ورود برای پیشنهاد
            </a>

            <?php elseif ($listing['trade_id'] && ($user['id'] == $listing['user_a_id'] || $user['id'] == $listing['user_b_id'])): ?>
            <div class="alert alert-success mb-0">
              <i class="bi bi-check-circle"></i>
              <div>
                <strong>پیشنهاد شما پذیرفته شد!</strong><br>
                <span class="fs-sm">معامله در جریان است.</span>
                <a href="<?= APP_URL ?>/trades.php?trade=<?= $listing['trade_id'] ?>" class="alert-link">مشاهده جزئیات معامله</a>
              </div>
            </div>

            <?php elseif ($myOffer): ?>
            <div class="alert alert-success mb-0">
              <i class="bi bi-check-circle"></i>
              <div>
                <strong>پیشنهاد در انتظار</strong><br>
                <span class="fs-sm">در انتظار پاسخ فروشنده.</span>
              </div>
            </div>

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

              <div class="mb-4">
                <label class="form-label fw-600 mb-3">نوع پیشنهاد خود را انتخاب کنید</label>

                <div class="offer-type-grid" style="display:grid; gap:16px;">
                <div class="card offer-type-card" id="has-item-card" style="border: 2px solid var(--primary);">
                  <div class="card-body" style="padding:24px;">
                    <div class="d-flex align-items-center gap-3 mb-3">
                      <div style="width:56px; height:56px; border-radius:16px; background:var(--primary); display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-box-seam" style="font-size:28px; color:white;"></i>
                      </div>
                      <div class="d-flex gap-2 align-items-center">
                        <input type="radio" id="has-item" name="offer_type" value="item" class="mt-0" <?= $myListings ? 'checked' : '' ?>>
                        <label for="has-item" class="fw-700 mb-0" style="font-size:1.0625rem;">کالا دارم</label>
                      </div>
                    </div>
                    <?php if ($myListings): ?>
                    <select class="form-control" name="offer_listing_id" id="offer-listing">
                      <option value="">— یکی از کالاهای خود را انتخاب کنید —</option>
                      <?php foreach ($myListings as $ml): ?>
                      <option value="<?= $ml['id'] ?>"><?= h(mb_strimwidth($ml['title'], 0, 50, '…')) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <div class="no-listings-notice" id="no-listings-notice">
                      <p><i class="bi bi-box-seam"></i> شما کالایی ندارید</p>
                      <a href="<?= APP_URL ?>/listings/create.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> ثبت کالا
                      </a>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card offer-type-card" id="no-item-card" style="border: 2px solid var(--border);">
                  <div class="card-body" style="padding:24px;">
                    <div class="d-flex align-items-center gap-3 mb-3">
                      <div style="width:56px; height:56px; border-radius:16px; background:var(--border); display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-chat-dots" style="font-size:28px; color:var(--text-muted);"></i>
                      </div>
                      <div class="d-flex gap-2 align-items-center">
                        <input type="radio" id="no-item" name="offer_type" value="message" class="mt-0" <?= !$myListings ? 'checked' : '' ?>>
                        <label for="no-item" class="fw-700 mb-0" style="font-size:1.0625rem;">کالا ندارم</label>
                      </div>
                    </div>
                    <p class="fs-sm mb-0" style="color:var(--text-muted);">فقط پیام برای فروشنده بفرستید</p>
                  </div>
                </div>
                </div>
              </div>



              <div class="form-group mb-4">
                <label class="form-label">پیام (الزامی)</label>
                <textarea class="form-control" name="message" rows="3" required
                          placeholder="درباره پیشنهاد خود توضیح دهید…"></textarea>
              </div>

              <button type="submit" class="btn btn-primary w-100 btn-lg">
                <i class="bi bi-send"></i> ارسال پیشنهاد
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Save + Share -->
        <div class="card">
          <div class="card-body" style="display:flex;gap:var(--sp-3)">
            <form method="POST" style="flex:1">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_listing">
              <button type="submit" class="btn <?= $isSaved ? 'btn-danger' : 'btn-outline' ?> w-100 btn-sm">
                <i class="bi bi-<?= $isSaved ? 'heart-fill' : 'heart' ?>"></i>
                <?= $isSaved ? 'ذخیره شد' : 'ذخیره' ?>
              </button>
            </form>
            <button type="button" id="share-listing-btn" data-title="<?= h($listing['title']) ?>"
                    class="btn btn-ghost btn-sm" style="flex:1">
              <i class="bi bi-share"></i> اشتراک
            </button>
          </div>
        </div>

      </aside>
    </div>

  </div>
</main>

<script>
// Highlight selected offer type
function updateOfferTypeCards() {
  const hasItem = document.getElementById('has-item')?.checked;
  const hasCard = document.getElementById('has-item-card');
  const noCard  = document.getElementById('no-item-card');
  
  if (!hasCard || !noCard) return;
  
  const hasIconDiv = hasCard.querySelector('.card-body > div > div:first-child');
  const noIconDiv = noCard.querySelector('.card-body > div > div:first-child');
  const hasIcon = hasIconDiv?.querySelector('i');
  const noIcon = noIconDiv?.querySelector('i');

  // Update has item card
  hasCard.style.borderColor = hasItem ? 'var(--primary)' : 'var(--border)';
  hasCard.style.background  = hasItem ? 'rgba(7, 26, 51, 0.03)' : '';
  if (hasIconDiv) hasIconDiv.style.background = hasItem ? 'var(--primary)' : 'var(--border)';
  if (hasIcon) hasIcon.style.color = hasItem ? 'white' : 'var(--text-muted)';
  
  // Update no item card
  noCard.style.borderColor  = hasItem ? 'var(--border)' : 'var(--primary)';
  noCard.style.background   = hasItem ? '' : 'rgba(7, 26, 51, 0.03)';
  if (noIconDiv) noIconDiv.style.background = hasItem ? 'var(--border)' : 'var(--primary)';
  if (noIcon) noIcon.style.color = hasItem ? 'var(--text-muted)' : 'white';
}
// Make entire offer cards clickable
document.getElementById('has-item-card')?.addEventListener('click', () => {
  const radio = document.getElementById('has-item');
  if (radio) {
    radio.checked = true;
    updateOfferTypeCards();
  }
});
document.getElementById('no-item-card')?.addEventListener('click', () => {
  const radio = document.getElementById('no-item');
  if (radio) {
    radio.checked = true;
    updateOfferTypeCards();
  }
});

document.querySelectorAll('input[name="offer_type"]').forEach(r => r.addEventListener('change', updateOfferTypeCards));
updateOfferTypeCards();

// Validate offer form
document.getElementById('offer-form')?.addEventListener('submit', function(e) {
      const offerType = document.querySelector('input[name="offer_type"]:checked')?.value || '';
      const message = document.querySelector('textarea[name="message"]').value.trim();
      const offerCredit = 0;
      
      // If "has item" is selected, require an item
      if (offerType === 'item') {
        const listingSelect = document.getElementById('offer-listing');
        if (!listingSelect) {
          e.preventDefault();
          showToast('شما کالایی ندارید. ابتدا کالای خود را ثبت کنید.', 'error');
          return;
        }
        const listing = listingSelect.value || '';
        if (!listing) {
          e.preventDefault();
          showToast('لطفاً یکی از کالاهای خود را انتخاب کنید', 'error');
          return;
        }
      }
      
      // If "no item" is selected, require either a message or offer credit
      if (offerType === 'message') {
        if (!message) {
          e.preventDefault();
          showToast('برای ارسال پیشنهاد بدون کالا، لطفاً پیامی وارد کنید.', 'error');
          return;
        }
      }


    });
document.getElementById('share-listing-btn')?.addEventListener('click', function () {
  const title = this.dataset.title || '';
  if (navigator.share) {
    navigator.share({ title, url: location.href }).catch(() => {});
  } else {
    navigator.clipboard.writeText(location.href).then(() => showToast('لینک کپی شد!', 'success'));
  }
});
</script>

<?php render_footer(); ?>