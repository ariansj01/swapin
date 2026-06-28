<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();
$id   = (int)($_GET['id'] ?? 0);

$listing = DB::fetch(
    'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.rating_count AS seller_rating_count,
            u.city AS seller_city, u.credit_balance AS seller_credits, u.verification_level,
            u.created_at AS seller_since, c.name AS cat_name, c.slug AS cat_slug
     FROM listings l
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     WHERE l.id = ?',
    [$id]
);

if (!$listing) {
    http_response_code(404);
    render_head('آگهی یافت نشد');
    render_navbar($user);
    echo '<div class="section"><div class="container"><div class="empty-state"><i class="bi bi-exclamation-circle"></i><h3>آگهی یافت نشد</h3><p>این آگهی ممکن است حذف یا معامله شده باشد.</p><a href="' . APP_URL . '/" class="btn btn-primary">مرور آگهی‌ها</a></div></div></div>';
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
     WHERE l.user_id = ? AND l.status = "active" AND l.id != ?
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
     WHERE l.category_id = ? AND l.id != ? AND l.status = "active"
     ORDER BY l.created_at DESC LIMIT 4',
    [$listing['category_id'], $id]
);

// Handle offer submission
$offerError   = '';
$offerSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$user) {
        header('Location: ' . APP_URL . '/auth/login.php?redirect=/listings/view.php%3Fid=' . $id); exit;
    }
    if ($_POST['action'] === 'make_offer') {
        if ($listing['user_id'] == $user['id']) {
            $offerError = 'نمی‌توانید برای آگهی خودتان پیشنهاد بدهید.';
        } elseif ($myOffer) {
            $offerError = 'شما از قبل یک پیشنهاد در انتظار برای این آگهی دارید.';
        } else {
            $offerListingId = (int)($_POST['offer_listing_id'] ?? 0) ?: null;
            $offerCredit    = max(0, (float)($_POST['offer_credit'] ?? 0));
            $message        = clean($_POST['message'] ?? '');

            if (!$offerListingId && $offerCredit <= 0) {
                $offerError = 'لطفاً یک کالا از آگهی‌های خود یا مقداری اعتبار ' . CREDIT_UNIT . ' پیشنهاد دهید.';
            } elseif ($offerCredit > 0 && $offerCredit > $user['credit_balance']) {
                $offerError = 'موجودی ' . CREDIT_UNIT . ' کافی نیست. موجودی شما: ' . fmt_credit((float)$user['credit_balance']);
            } else {
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
                DB::insert('messages', [
                    'thread_id'    => $threadId,
                    'from_user_id' => $user['id'],
                    'to_user_id'   => $listing['user_id'],
                    'offer_id'     => DB::lastId(),
                    'body'         => $message ?: 'برای آگهی «' . $listing['title'] . '» پیشنهاد دادم.',
                ]);
                $offerSuccess = 'پیشنهاد ارسال شد! فروشنده مطلع می‌شود.';
                $myOffer = DB::fetch('SELECT * FROM trade_offers WHERE listing_id = ? AND from_user_id = ? ORDER BY id DESC LIMIT 1', [$id, $user['id']]);
            }
        }
    } elseif ($_POST['action'] === 'buy_now') {
        $mode = $listing['listing_mode'] ?? 'swap';
        if (!in_array($mode, ['sell', 'both'], true)) {
            $offerError = 'این آگهی برای خرید مستقیم در دسترس نیست.';
        } elseif ($listing['user_id'] == $user['id']) {
            $offerError = 'نمی‌توانید آگهی خودتان را بخرید.';
        } elseif ((float)($listing['sell_price'] ?? 0) <= 0) {
            $offerError = 'قیمت فروش برای این آگهی تنظیم نشده است.';
        } else {
            $kbc = max(1, (int)ceil((float)$listing['sell_price'] / 10000));
            if ($kbc > $user['credit_balance']) {
                $offerError = 'موجودی ' . CREDIT_UNIT . ' کافی نیست. نیاز: ' . fmt_credit($kbc) . ' (~' . number_format((float)$listing['sell_price'], 0) . ' تومان).';
            } else {
                $offerId = DB::insert('trade_offers', [
                    'listing_id'       => $id,
                    'from_user_id'     => $user['id'],
                    'offer_listing_id' => null,
                    'offer_credit'     => $kbc,
                    'message'          => 'خرید مستقیم (خرید فوری)',
                    'status'           => 'accepted',
                ]);
                $tradeId = DB::insert('trades', [
                    'offer_id'     => $offerId,
                    'user_a_id'    => $listing['user_id'],
                    'user_b_id'    => $user['id'],
                    'listing_a_id' => $id,
                    'listing_b_id' => null,
                    'credit_diff'  => $kbc,
                    'status'       => 'in_progress',
                ]);
                escrow_hold($tradeId, (int)$user['id'], $kbc, 'سپرده خرید فوری معامله #' . $tradeId);
                create_trade_contract($tradeId);
                DB::query('UPDATE listings SET status = "traded" WHERE id = ?', [$id]);
                header('Location: ' . APP_URL . '/trades.php?trade=' . $tradeId . '&accepted=1');
                exit;
            }
        }
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

$isOwner = $user && $user['id'] == $listing['user_id'];
$canBuy  = false; // سواپین — فقط معاوضه، بدون خرید مستقیم
$buyKbc  = 0;
$inspectionLabels = ['requested' => 'درخواست‌شده', 'pending' => 'در انتظار', 'approved' => 'تأیید‌شده', 'rejected' => 'رد شده'];
$sellerSwapScore    = compute_swap_score((int)$listing['user_id']);

render_head($listing['title'], mb_strimwidth($listing['description'], 0, 160, '…'));
render_navbar($user);
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success" style="border-radius:0;border-inline-start:0;border-inline-end:0">
  <div class="container"><i class="bi bi-check-circle-fill"></i> آگهی شما منتشر شد! برای دریافت پیشنهاد بیشتر آن را به اشتراک بگذارید.</div>
</div>
<?php endif; ?>

<main class="section-sm">
  <div class="container">

    <!-- Breadcrumb -->
    <nav style="font-size:.875rem;color:var(--text-muted);margin-bottom:var(--sp-5)">
      <a href="<?= APP_URL ?>/">خانه</a>
      <i class="bi bi-chevron-left" style="font-size:.7rem;margin:0 var(--sp-2)"></i>
      <a href="<?= APP_URL ?>/?cat=<?= h($listing['cat_slug']) ?>"><?= h($listing['cat_name']) ?></a>
      <i class="bi bi-chevron-left" style="font-size:.7rem;margin:0 var(--sp-2)"></i>
      <span><?= h(mb_strimwidth($listing['title'], 0, 40, '…')) ?></span>
    </nav>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:var(--sp-8);align-items:start">

      <!-- ── Left: Listing Content ─────────────────────────────────── -->
      <div>
        <!-- Image Gallery -->
        <?php if ($images): ?>
        <div style="border-radius:var(--radius-lg);overflow:hidden;margin-bottom:var(--sp-5)">
          <img id="main-img" src="<?= UPLOAD_URL . h($images[0]['filename']) ?>"
               alt="<?= h($listing['title']) ?>"
               style="width:100%;max-height:480px;object-fit:cover;display:block;border-radius:var(--radius-lg)">
        </div>
        <?php if (count($images) > 1): ?>
        <div style="display:flex;gap:var(--sp-3);overflow-x:auto;margin-bottom:var(--sp-5)">
          <?php foreach ($images as $i => $img): ?>
          <img src="<?= UPLOAD_URL . h($img['filename']) ?>"
               alt="تصویر <?= $i+1 ?>"
               onclick="document.getElementById('main-img').src=this.src;document.querySelectorAll('.thumb-img').forEach(t=>t.style.outline='none');this.style.outline='2.5px solid var(--primary)'"
               class="thumb-img"
               style="width:72px;height:72px;object-fit:cover;border-radius:var(--radius-md);cursor:pointer;flex-shrink:0;<?= $i===0 ? 'outline:2.5px solid var(--primary)' : '' ?>">
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div style="background:var(--bg);border-radius:var(--radius-lg);height:280px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:3rem;margin-bottom:var(--sp-5)">
          <i class="bi bi-image"></i>
        </div>
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
                  <?php if ($listing['city']): ?>
                  <span style="font-size:.875rem;color:var(--text-muted)"><i class="bi bi-geo-alt"></i> <?= h($listing['city']) ?></span>
                  <?php endif; ?>
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
        <h3 class="mb-4">آگهی‌های مشابه</h3>
        <div class="listings-grid">
          <?php foreach ($related as $l): ?>
          <?php include __DIR__ . '/../includes/listing_card.php'; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Right: Sidebar ─────────────────────────────────────────── -->
      <div style="position:sticky;top:80px">

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
              <span><i class="bi bi-shield-check" style="color:var(--accent-dark)"></i> Swap Score: <?= $sellerSwapScore['score'] ?>/100</span>
              <span><i class="bi bi-calendar3"></i> از <?= persian_date($listing['seller_since']) ?></span>
            </div>
            <a href="<?= APP_URL ?>/profile.php?id=<?= $listing['user_id'] ?>" class="btn btn-outline w-100 btn-sm">مشاهده پروفایل</a>
          </div>
        </div>

        <!-- Action Card -->
        <?php if ($isOwner): ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="alert alert-info mb-4" style="font-size:.875rem">
              <i class="bi bi-info-circle"></i> این آگهی متعلق به شماست.
            </div>
            <a href="<?= APP_URL ?>/listings/edit.php?id=<?= $id ?>" class="btn btn-outline w-100 mb-3">
              <i class="bi bi-pencil"></i> ویرایش آگهی
            </a>
            <a href="<?= APP_URL ?>/listings/bump.php?id=<?= $id ?>" class="btn btn-accent w-100 mb-3">
              <i class="bi bi-rocket"></i> ارتقای آگهی
            </a>
            <?php if (($listing['inspection_status'] ?? 'none') === 'none'): ?>
            <form method="POST" class="mb-3" onsubmit="return confirm('بازرسی کارشناس با هزینه <?= fmt_credit(INSPECTION_KBC) ?> درخواست شود؟')">
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
            <a href="<?= APP_URL ?>/listings/offers.php?id=<?= $id ?>" class="btn btn-primary w-100">
              <i class="bi bi-inbox"></i> مشاهده پیشنهادها
            </a>
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
            <a href="<?= APP_URL ?>/auth/login.php?redirect=<?= urlencode('/listings/view.php?id='.$id) ?>" class="btn btn-primary w-100">
              ورود برای پیشنهاد
            </a>

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
              <input type="hidden" name="action" value="make_offer">

              <?php if ($myListings): ?>
              <div class="form-group">
                <label class="form-label">پیشنهاد از آگهی‌های شما</label>
                <select class="form-control" name="offer_listing_id" id="offer-listing">
                  <option value="">— یکی از کالاهای خود را انتخاب کنید —</option>
                  <?php foreach ($myListings as $ml): ?>
                  <option value="<?= $ml['id'] ?>"><?= h(mb_strimwidth($ml['title'], 0, 50, '…')) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="text-center fs-sm" style="color:var(--text-muted);margin:-var(--sp-2) 0 var(--sp-4)">— یا —</div>
              <?php endif; ?>

              <div class="form-group">
                <label class="form-label">
                  افزودن اعتبار <?= CREDIT_UNIT ?>
                  <span class="fs-xs" style="color:var(--text-muted)">(موجودی: <?= fmt_credit((float)$user['credit_balance']) ?>)</span>
                </label>
                <input type="number" class="form-control" name="offer_credit" id="offer-credit"
                       placeholder="0" min="0" max="<?= $user['credit_balance'] ?>" step="1">
              </div>

              <div class="form-group">
                <label class="form-label">پیام (اختیاری)</label>
                <textarea class="form-control" name="message" rows="3"
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
              <input type="hidden" name="action" value="save_listing">
              <button type="submit" class="btn <?= $isSaved ? 'btn-danger' : 'btn-outline' ?> w-100 btn-sm">
                <i class="bi bi-<?= $isSaved ? 'heart-fill' : 'heart' ?>"></i>
                <?= $isSaved ? 'ذخیره شد' : 'ذخیره' ?>
              </button>
            </form>
            <button onclick="navigator.share ? navigator.share({title:'<?= h($listing['title']) ?>',url:location.href}) : navigator.clipboard.writeText(location.href).then(()=>showToast('لینک کپی شد!','success'))"
                    class="btn btn-ghost btn-sm" style="flex:1">
              <i class="bi bi-share"></i> اشتراک
            </button>
          </div>
        </div>

      </div>
    </div>

  </div>
</main>

<script>
// Validate offer form
document.getElementById('offer-form')?.addEventListener('submit', function(e) {
  const listing = document.getElementById('offer-listing')?.value || '';
  const credit  = parseFloat(document.getElementById('offer-credit')?.value || 0);
  if (!listing && (!credit || credit <= 0)) {
    e.preventDefault();
    showToast('لطفاً یک کالا یا مقداری اعتبار ' + '<?= CREDIT_UNIT ?>' + ' پیشنهاد دهید', 'error');
  }
});
</script>

<?php render_footer(); ?>
