<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = (int) $user['id'];
$listingId = (int) ($_GET['listing_id'] ?? $_POST['listing_id'] ?? 0);
$step = (int) ($_GET['step'] ?? $_POST['step'] ?? 1);
$error = '';

$listing = $listingId ? DB::fetch(
    'SELECT l.*, u.seller_type, u.store_name, u.store_slug,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?',
    [$listingId]
) : null;

if (!$listing || !listing_is_store_swappable($listing)) {
    header('Location: ' . APP_URL . '/listings/view?id=' . $listingId);
    exit;
}

if ((int) $listing['user_id'] === $uid) {
    header('Location: ' . APP_URL . '/listings/view?id=' . $listingId);
    exit;
}

if (store_swap_offer_user_has_active($uid, $listingId)) {
    $existing = DB::fetch(
        'SELECT id FROM trade_offers WHERE listing_id = ? AND from_user_id = ? AND flow_type = "user_to_store"
         AND status IN ("pending","negotiating","counter_offered","accepted") ORDER BY id DESC LIMIT 1',
        [$listingId, $uid]
    );
    if ($existing) {
        header('Location: ' . APP_URL . '/store-offers/view.php?id=' . (int) $existing['id']);
        exit;
    }
}

$myListings = DB::fetchAll(
    'SELECT l.id, l.title, l.description, l.estimated_value, l.condition, c.name AS cat_name,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved" AND l.id != ?
     ORDER BY l.created_at DESC',
    [$uid, $listingId]
);

$categories = DB::fetchAll('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY sort_order, name');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_listing'])) {
    csrf_verify_or_fail();
    $result = store_swap_quick_listing_create($uid, $_POST, $_FILES['image'] ?? null);
    if (isset($result['error'])) {
        $error = $result['error'];
        $step = 1;
    } else {
        header('Location: ' . APP_URL . '/store-offers/create.php?listing_id=' . $listingId . '&step=1&picked=' . (int) $result['listing_id']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_offer'])) {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('store_swap_offer', 15, 900);
    $userListingId = (int) ($_POST['offer_listing_id'] ?? 0);
    $cashDiff = (float) preg_replace('/[^\d.]/', '', (string) ($_POST['cash_difference'] ?? '0'));
    $message = clean($_POST['message'] ?? '');

    $result = store_swap_offer_create($uid, $listingId, $userListingId, $cashDiff, $message ?: null);
    if (isset($result['error'])) {
        $error = $result['error'];
        $step = 2;
    } else {
        header('Location: ' . APP_URL . '/store-offers/success.php?id=' . (int) $result['offer_id']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['go_review'])) {
    csrf_verify_or_fail();
    $pickedListing = (int) ($_POST['offer_listing_id'] ?? 0);
    if (!$pickedListing) {
        $error = 'لطفاً یک کالا انتخاب کنید.';
        $step = 1;
    } else {
        $step = 2;
    }
}

$pickedId = (int) ($_GET['picked'] ?? $_POST['offer_listing_id'] ?? ($myListings[0]['id'] ?? 0));
$pickedListing = null;
foreach ($myListings as $ml) {
    if ((int) $ml['id'] === $pickedId) {
        $pickedListing = $ml;
        break;
    }
}

$cashDiff = (float) preg_replace('/[^\d.]/', '', (string) ($_POST['cash_difference'] ?? '0'));
$storeValue = (float) ($listing['estimated_value'] ?: $listing['sell_price'] ?: 0);
$userValue = $pickedListing ? (float) $pickedListing['estimated_value'] : 0;

render_head('پیشنهاد معاوضه | ' . h($listing['title']), '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/store-offers.css?v=<?= @filemtime(__DIR__ . '/../src/css/store-offers.css') ?: time() ?>">

<div class="section-sm">
  <div class="so-page">
    <div class="so-head">
      <a href="<?= APP_URL ?>/listings/view?id=<?= $listingId ?>" class="so-back"><i class="bi bi-arrow-right"></i> بازگشت به محصول</a>
      <h1 class="so-title">پیشنهاد معاوضه</h1>
      <p class="so-subtitle">چه کالایی برای معاوضه پیشنهاد می‌دهید؟<br>کالای خود را از لیست انتخاب کنید.</p>
    </div>

    <div class="so-steps" aria-hidden="true">
      <div class="so-step<?= $step >= 1 ? ' is-active' : '' ?><?= $step > 1 ? ' is-done' : '' ?>">۱</div>
      <div class="so-step-line<?= $step > 1 ? ' is-done' : '' ?>"></div>
      <div class="so-step<?= $step >= 2 ? ' is-active' : '' ?><?= $step > 2 ? ' is-done' : '' ?>">۲</div>
    </div>

    <?php if ($error): ?>
    <div class="so-alert so-alert--error"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div class="so-card">
      <div class="so-card__label">محصول فروشگاه</div>
      <div class="so-item">
        <?php if (!empty($listing['thumb'])): ?>
        <img src="<?= UPLOAD_URL . h($listing['thumb']) ?>" alt="" class="so-item__thumb">
        <?php else: ?>
        <div class="so-item__thumb so-item__thumb--empty"><i class="bi bi-box"></i></div>
        <?php endif; ?>
        <div>
          <div class="so-item__title"><?= h($listing['title']) ?></div>
          <div class="so-item__meta"><?= h($listing['store_name'] ?? '') ?></div>
          <?php if ($storeValue > 0): ?>
          <div class="so-item__price">ارزش تقریبی: <?= fmt_credit($storeValue) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($step === 1): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_id" value="<?= $listingId ?>">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="go_review" value="1">

      <?php if ($myListings): ?>
      <div class="so-card">
        <div class="so-card__label">کالای شما</div>
        <?php foreach ($myListings as $ml): ?>
        <label class="so-item-pick<?= (int) $ml['id'] === $pickedId ? ' is-selected' : '' ?>">
          <input type="radio" name="offer_listing_id" value="<?= (int) $ml['id'] ?>" <?= (int) $ml['id'] === $pickedId ? 'checked' : '' ?> required>
          <?php if ($ml['thumb']): ?>
          <img src="<?= UPLOAD_URL . h($ml['thumb']) ?>" alt="" class="so-item__thumb">
          <?php else: ?>
          <div class="so-item__thumb so-item__thumb--empty"><i class="bi bi-image"></i></div>
          <?php endif; ?>
          <div>
            <div class="so-item__title"><?= h($ml['title']) ?></div>
            <div class="so-item__meta"><?= h($ml['cat_name']) ?> · <?= condition_label($ml['condition']) ?></div>
            <?php if ((float) $ml['estimated_value'] > 0): ?>
            <div class="so-item__price"><?= fmt_credit((float) $ml['estimated_value']) ?></div>
            <?php endif; ?>
          </div>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="so-card">
        <label class="so-input-label" for="cash_difference">مبلغ تکمیلی (اختیاری)</label>
        <input type="text" class="form-control" id="cash_difference" name="cash_difference"
               inputmode="numeric" placeholder="مثلاً ۲,۰۰۰,۰۰۰" value="<?= h((string) ($_POST['cash_difference'] ?? '')) ?>">
        <p class="so-item__meta" style="margin-top:8px">در صورت کمتر بودن ارزش کالای شما، مابه‌التفاوت را وارد کنید.</p>
      </div>

      <div class="so-actions">
        <button type="submit" class="so-btn so-btn--primary">ادامه — بررسی پیشنهاد <i class="bi bi-arrow-left"></i></button>
        <button type="button" class="so-btn so-btn--outline" id="so-open-quick-listing">ثبت کالای جدید</button>
      </div>
      <?php else: ?>
      <div class="so-card so-alert--info">
        <p><i class="bi bi-box-seam"></i> هنوز کالایی برای پیشنهاد ندارید. یک کالا ثبت کنید تا بتوانید پیشنهاد معاوضه بدهید.</p>
      </div>
      <div class="so-actions">
        <button type="button" class="so-btn so-btn--gold" id="so-open-quick-listing"><i class="bi bi-plus-circle"></i> ثبت کالا و ادامه</button>
      </div>
      <?php endif; ?>
    </form>
    <?php else: ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_id" value="<?= $listingId ?>">
      <input type="hidden" name="offer_listing_id" value="<?= $pickedId ?>">
      <input type="hidden" name="cash_difference" value="<?= h((string) $cashDiff) ?>">
      <input type="hidden" name="submit_offer" value="1">

      <div class="so-card">
        <h2 class="so-title" style="font-size:1.1rem;margin-bottom:4px">بررسی پیشنهاد</h2>
        <p class="so-subtitle" style="margin-bottom:16px">پیشنهاد شما به فروشگاه — لطفاً جزئیات را قبل از ارسال بررسی کنید.</p>

        <div class="so-swap-row">
          <div>
            <div class="so-card__label">کالای فروشگاه</div>
            <div class="so-item__title"><?= h($listing['title']) ?></div>
            <div class="so-item__price"><?= fmt_credit($storeValue) ?></div>
          </div>
          <div class="so-swap-icon"><i class="bi bi-arrow-left-right"></i></div>
          <div>
            <div class="so-card__label">کالای شما</div>
            <div class="so-item__title"><?= h($pickedListing['title'] ?? '—') ?></div>
            <div class="so-item__price"><?= fmt_credit($userValue) ?></div>
          </div>
        </div>

        <div class="so-cash-box">
          <div class="so-cash-row"><span>ارزش کالای شما</span><strong><?= fmt_credit($userValue) ?></strong></div>
          <div class="so-cash-row"><span>ارزش محصول فروشگاه</span><strong><?= fmt_credit($storeValue) ?></strong></div>
          <div class="so-cash-row"><span>مبلغ تکمیلی شما</span><strong><?= fmt_credit($cashDiff) ?></strong></div>
        </div>

        <div class="so-alert so-alert--info" style="margin-top:16px;margin-bottom:0">
          <i class="bi bi-info-circle"></i> با ارسال این پیشنهاد، فروشگاه آن را بررسی خواهد کرد.
        </div>
      </div>

      <div class="so-actions">
        <button type="submit" class="so-btn so-btn--gold"><i class="bi bi-send"></i> ارسال پیشنهاد</button>
        <a href="<?= APP_URL ?>/store-offers/create.php?listing_id=<?= $listingId ?>&step=1&picked=<?= $pickedId ?>" class="so-btn so-btn--outline">بازگشت و ویرایش</a>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="so-modal-backdrop" id="so-quick-modal" role="dialog" aria-modal="true">
  <div class="so-modal">
    <h2 class="so-modal__title">ثبت کالا برای پیشنهاد</h2>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_id" value="<?= $listingId ?>">
      <input type="hidden" name="quick_listing" value="1">
      <div class="form-group mb-3">
        <label class="so-input-label">عنوان</label>
        <input type="text" name="title" class="form-control" required minlength="5">
      </div>
      <div class="form-group mb-3">
        <label class="so-input-label">توضیحات</label>
        <textarea name="description" class="form-control" rows="3" required minlength="10"></textarea>
      </div>
      <div class="form-group mb-3">
        <label class="so-input-label">دسته‌بندی</label>
        <select name="category_id" class="form-control" required>
          <option value="">انتخاب کنید</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= (int) $cat['id'] ?>"><?= h($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group mb-3">
        <label class="so-input-label">ارزش تقریبی (تومان)</label>
        <input type="text" name="estimated_value" class="form-control" inputmode="numeric" required>
      </div>
      <div class="form-group mb-3">
        <label class="so-input-label">تصویر</label>
        <input type="file" name="image" class="form-control" accept="image/*">
      </div>
      <div class="so-actions">
        <button type="submit" class="so-btn so-btn--primary">ثبت و انتخاب</button>
        <button type="button" class="so-btn so-btn--outline" id="so-close-quick-modal">انصراف</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.so-item-pick').forEach(function(el) {
  el.addEventListener('click', function() {
    document.querySelectorAll('.so-item-pick').forEach(function(x) { x.classList.remove('is-selected'); });
    el.classList.add('is-selected');
    el.querySelector('input[type=radio]').checked = true;
  });
});
var modal = document.getElementById('so-quick-modal');
document.getElementById('so-open-quick-listing')?.addEventListener('click', function() { modal.classList.add('is-open'); });
document.getElementById('so-close-quick-modal')?.addEventListener('click', function() { modal.classList.remove('is-open'); });
modal?.addEventListener('click', function(e) { if (e.target === modal) modal.classList.remove('is-open'); });
<?php if (!$myListings && !$error): ?>modal?.classList.add('is-open');<?php endif; ?>
</script>

<?php render_footer(); ?>
