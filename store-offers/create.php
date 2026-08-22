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
    'SELECT l.id, l.title, l.description, l.estimated_value, l.condition, l.review_status, c.name AS cat_name,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status IN ("approved","offer_only") AND l.id != ?
     ORDER BY (l.review_status = "offer_only") DESC, l.created_at DESC',
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

$diff = $storeValue - $userValue;
$suggestedCash = $diff > 0 ? $diff : 0;

render_head('پیشنهاد معاوضه | ' . h($listing['title']), '', ['robots' => 'noindex, nofollow']);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">

<div class="ex-page">
  <div class="ex-container" style="place-items:center; display:grid;">

    <div class="ex-header" style="width: 60%;">
      <a href="<?= APP_URL ?>/listings/view?id=<?= $listingId ?>" class="ex-header__back">
        <i class="bi bi-arrow-right"></i>
        بازگشت به محصول
      </a>
      <h1 class="ex-header__title">پیشنهاد معاوضه</h1>
      <p class="ex-header__subtitle">کالای خود را انتخاب کرده و پیشنهاد خود را برای فروشگاه ارسال کنید.</p>
    </div>

    <div class="ex-stepper" style="width: 75%;">
      <div class="ex-stepper__progress" style="width:<?= $step > 1 ? '80%' : '50%' ?>"></div>
      <div class="ex-step <?= $step >= 1 ? 'is-done' : '' ?> <?= $step === 1 ? 'is-active' : '' ?>">
        <div class="ex-step__circle">۱</div>
        <div class="ex-step__label">انتخاب کالا</div>
      </div>
      <div class="ex-step <?= $step > 1 ? 'is-done' : '' ?> <?= $step === 2 ? 'is-active' : '' ?>">
        <div class="ex-step__circle">۲</div>
        <div class="ex-step__label">بررسی و ارسال</div>
      </div>
      <div class="ex-step is-locked">
        <div class="ex-step__circle">۳</div>
        <div class="ex-step__label">پاسخ فروشگاه</div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="ex-alert ex-alert--danger">
      <i class="bi bi-exclamation-circle-fill ex-alert__icon"></i>
      <?= h($error) ?>
    </div>
    <?php endif; ?>

    <div class="ex-card" style="width: 40%;">
      <div class="ex-card__label"><i class="bi bi-shop-window"></i> محصول فروشگاه</div>
      <div class="ex-product">
        <?php if (!empty($listing['thumb'])): ?>
        <img src="<?= UPLOAD_URL . h($listing['thumb']) ?>" alt="" class="ex-product__thumb">
        <?php else: ?>
        <div class="ex-product__thumb ex-product__thumb--empty"><i class="bi bi-box"></i></div>
        <?php endif; ?>
        <div class="ex-product__info">
          <div class="ex-product__title"><?= h($listing['title']) ?></div>
          <div class="ex-product__meta">
            <span class="ex-product__chip"><i class="bi bi-shop"></i> <?= h($listing['store_name'] ?? 'فروشگاه') ?></span>
            <?php if (!empty($listing['condition'])): ?>
            <span class="ex-product__chip"><?= condition_label($listing['condition']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($storeValue > 0): ?>
          <div class="ex-product__price">
            <span class="ex-product__price-label">ارزش تقریبی:</span>
            <?= fmt_credit($storeValue) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($step === 1): ?>
    <form method="POST" id="step1Form" style="width: 60%;">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_id" value="<?= $listingId ?>">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="go_review" value="1">
      <input type="hidden" name="offer_listing_id" id="pickedListingId" value="<?= $pickedId ?>">
      <input type="hidden" name="cash_difference" id="cashDiffInput" value="<?= h((string) ($_POST['cash_difference'] ?? '')) ?>">

      <?php if ($myListings): ?>
      <div class="ex-card">
        <div class="ex-flex-between" style="margin-bottom:16px">
          <div style="display:flex;align-items:center;gap:10px">
            <div class="ex-card__label" style="margin-bottom:0"><i class="bi bi-box-seam"></i> کالای شما — انتخاب کنید</div>
            <span class="ex-sort-hint" title="برای تغییر ترتیب، آیکون کشیدن را بکشید">
              <i class="bi bi-grip-vertical"></i> قابل کشیدن
            </span>
          </div>
          <button type="button" class="ex-btn ex-btn--outline ex-btn--sm" id="so-open-quick-listing" style="width:auto">
            <i class="bi bi-plus-lg"></i>
            کالای جدید
          </button>
        </div>

        <div class="ex-picklist" id="exPicklist">
          <?php foreach ($myListings as $mlIdx => $ml): ?>
          <label class="ex-pick<?= (int) $ml['id'] === $pickedId ? ' is-selected' : '' ?><?= ($ml['review_status'] ?? '') === 'offer_only' ? ' is-offer-only' : '' ?>"
                 data-id="<?= (int) $ml['id'] ?>" draggable="true" data-order="<?= $mlIdx ?>">
            <div class="ex-pick__drag" draggable="false" title="کشیدن برای جابجایی">
              <i class="bi bi-grip-vertical"></i>
            </div>
            <input type="radio" class="ex-pick__radio" name="offer_listing_radio" value="<?= (int) $ml['id'] ?>" <?= (int) $ml['id'] === $pickedId ? 'checked' : '' ?>>
            <div class="ex-pick__check"></div>
            <?php if ($ml['thumb']): ?>
            <img src="<?= UPLOAD_URL . h($ml['thumb']) ?>" alt="" class="ex-pick__thumb" draggable="false">
            <?php else: ?>
            <div class="ex-pick__thumb ex-pick__thumb--empty"><i class="bi bi-image"></i></div>
            <?php endif; ?>
            <div class="ex-pick__info">
              <div class="ex-pick__title">
                <?= h($ml['title']) ?>
                <?php if (($ml['review_status'] ?? '') === 'offer_only'): ?>
                <span class="ex-offer-badge">
                  <i class="bi bi-lock-fill"></i> فقط برای این پیشنهاد
                </span>
                <?php endif; ?>
              </div>
              <div class="ex-pick__meta">
                <span><?= h($ml['cat_name']) ?></span>
                <span><?= condition_label($ml['condition']) ?></span>
              </div>
              <?php if ((float) $ml['estimated_value'] > 0): ?>
              <div class="ex-pick__price"><?= fmt_credit((float) $ml['estimated_value']) ?></div>
              <?php endif; ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="ex-card">
        <label class="ex-form-label" for="cash_difference">
          <i class="bi bi-cash-stack"></i>
          مبلغ تکمیلی
          <span style="font-weight:500;color:var(--ex-muted);font-size:.75rem">(در صورت نیاز)</span>
        </label>
        <div class="ex-input-amount">
          <input type="text" class="form-control" id="cash_difference" name="cash_difference_display"
                 inputmode="numeric" placeholder="مثلاً ۲,۰۰۰,۰۰۰"
                 value="<?= h((string) ($_POST['cash_difference'] ?? '')) ?>"
                 style="padding:14px 16px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-size:1rem;font-weight:700;font-family:inherit">
          <div class="ex-input-amount__suffix">تومان</div>
        </div>
        <?php if ($suggestedCash > 0): ?>
        <button type="button" class="ex-chip ex-chip--gold" id="suggestedCashBtn" style="margin-top:10px;cursor:pointer;border:none;font-family:inherit">
          <i class="bi bi-magic"></i>
          پیشنهاد خودکار: مبلغ <?= fmt_credit($suggestedCash) ?>
        </button>
        <?php endif; ?>
        <p class="ex-form-hint">
          <i class="bi bi-info-circle"></i>
          در صورت کمتر بودن ارزش کالای شما نسبت به محصول فروشگاه، مابه‌التفاوت را به صورت مبلغ نقدی وارد کنید.
        </p>
      </div>
      <?php else: ?>
      <div class="ex-card">
        <div class="ex-empty">
          <div class="ex-empty__icon"><i class="bi bi-box-seam"></i></div>
          <h3 class="ex-empty__title">هنوز کالایی برای پیشنهاد ندارید</h3>
          <p class="ex-empty__desc">یک کالا ثبت کنید تا بتوانید پیشنهاد معاوضه بدهید. این فرآیند فقط چند لحظه طول می‌کشد.</p>
          <button type="button" class="ex-btn ex-btn--cta" style="max-width:320px;margin:0 auto" id="so-open-quick-listing-empty">
            <i class="bi bi-plus-circle-fill"></i>
            ثبت کالا و ادامه
          </button>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($myListings): ?>
      <div class="ex-sticky-bar ex-visible-mobile">
        <div class="ex-sticky-bar__inner">
          <button type="submit" class="ex-btn ex-btn--cta ex-btn--lg">
            ادامه — بررسی پیشنهاد
            <i class="bi bi-arrow-left"></i>
          </button>
        </div>
      </div>

      <div class="ex-actions ex-hidden-mobile">
        <button type="submit" class="ex-btn ex-btn--cta ex-btn--lg">
          ادامه — بررسی پیشنهاد
          <i class="bi bi-arrow-left"></i>
        </button>
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

      <div class="ex-card">
        <div class="ex-card__title">بررسی پیشنهاد</div>
        <p class="ex-card__subtitle">پیشنهاد شما به فروشگاه — لطفاً جزئیات را قبل از ارسال بررسی کنید.</p>

        <div class="ex-swap-grid">
          <div class="ex-swap-col">
            <div class="ex-swap-col__label">کالای شما</div>
            <?php if (!empty($pickedListing['thumb'])): ?>
            <img src="<?= UPLOAD_URL . h($pickedListing['thumb']) ?>" alt="" class="ex-swap-col__thumb">
            <?php else: ?>
            <div class="ex-swap-col__thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ex-muted);font-size:1.75rem">
              <i class="bi bi-box"></i>
            </div>
            <?php endif; ?>
            <div class="ex-swap-col__title"><?= h($pickedListing['title'] ?? '—') ?></div>
            <div class="ex-swap-col__price"><?= fmt_credit($userValue) ?></div>
          </div>
          <div class="ex-swap-icon">
            <div class="ex-swap-icon__wrapper">
              <i class="bi bi-arrow-left-right"></i>
            </div>
          </div>
          <div class="ex-swap-col">
            <div class="ex-swap-col__label">محصول فروشگاه</div>
            <?php if (!empty($listing['thumb'])): ?>
            <img src="<?= UPLOAD_URL . h($listing['thumb']) ?>" alt="" class="ex-swap-col__thumb">
            <?php else: ?>
            <div class="ex-swap-col__thumb" style="display:flex;align-items:center;justify-content:center;color:var(--ex-muted);font-size:1.75rem">
              <i class="bi bi-box"></i>
            </div>
            <?php endif; ?>
            <div class="ex-swap-col__title"><?= h($listing['title']) ?></div>
            <div class="ex-swap-col__price"><?= fmt_credit($storeValue) ?></div>
          </div>
        </div>

        <div class="ex-cash-box ex-cash-box--highlight" style="margin-top:20px">
          <div class="ex-cash-row">
            <span>ارزش کالای شما</span>
            <strong><?= fmt_credit($userValue) ?></strong>
          </div>
          <div class="ex-cash-row">
            <span>ارزش محصول فروشگاه</span>
            <strong><?= fmt_credit($storeValue) ?></strong>
          </div>
          <div class="ex-cash-row">
            <span>مبلغ تکمیلی شما</span>
            <strong><?= fmt_credit($cashDiff) ?></strong>
          </div>
          <div class="ex-cash-total">
            <span>مجموع ارزش پیشنهادی شما</span>
            <strong><?= fmt_credit($userValue + $cashDiff) ?></strong>
          </div>
        </div>

        <div class="ex-alert ex-alert--info" style="margin-top:16px;margin-bottom:0">
          <i class="bi bi-info-circle-fill ex-alert__icon"></i>
          با ارسال این پیشنهاد، فروشگاه آن را بررسی خواهد کرد. شما می‌توانید از طریق همین صفحه گفت‌وگو کنید و روند را پیگیری کنید.
        </div>
      </div>

      <div class="ex-sticky-bar ex-visible-mobile">
        <div class="ex-sticky-bar__inner">
          <div class="ex-sticky-bar__row">
            <a href="<?= APP_URL ?>/store-offers/create.php?listing_id=<?= $listingId ?>&step=1&picked=<?= $pickedId ?>" class="ex-btn ex-btn--outline" data-navigate="<?= APP_URL ?>/store-offers/create.php?listing_id=<?= $listingId ?>&step=1&picked=<?= $pickedId ?>">
              ویرایش
            </a>
            <button type="submit" class="ex-btn ex-btn--cta ex-btn--lg">
              <i class="bi bi-send-fill"></i>
              ارسال پیشنهاد
            </button>
          </div>
        </div>
      </div>

      <div class="ex-actions ex-actions--row-sm ex-hidden-mobile">
        <a href="<?= APP_URL ?>/store-offers/create.php?listing_id=<?= $listingId ?>&step=1&picked=<?= $pickedId ?>" class="ex-btn ex-btn--outline" data-navigate="<?= APP_URL ?>/store-offers/create.php?listing_id=<?= $listingId ?>&step=1&picked=<?= $pickedId ?>" style="flex:1">
          <i class="bi bi-pencil"></i>
          بازگشت و ویرایش
        </a>
        <button type="submit" class="ex-btn ex-btn--cta ex-btn--lg" style="flex:2">
          <i class="bi bi-send-fill"></i>
          ارسال پیشنهاد به فروشگاه
        </button>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>

<div class="ex-modal-backdrop" id="so-quick-modal" role="dialog" aria-modal="true">
  <div class="ex-modal">
    <div class="ex-modal__header">
      <h3 class="ex-modal__title"><i class="bi bi-plus-circle" style="color:var(--ex-gold-dark);margin-left:6px"></i> افزودن کالا برای این پیشنهاد</h3>
      <button type="button" class="ex-modal__close" id="so-close-quick-modal" aria-label="بستن">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_id" value="<?= $listingId ?>">
      <input type="hidden" name="quick_listing" value="1">

      <div class="ex-alert ex-alert--info" style="margin-bottom:20px;border-radius:var(--ex-radius-md);font-size:.82rem">
        <i class="bi bi-info-circle-fill ex-alert__icon"></i>
        این کالا <strong>فقط برای همین پیشنهاد</strong> ثبت می‌شود و به عنوان آگهی عمومی در سایت نمایش داده نمی‌شود.
      </div>

      <div class="ex-form-group">
        <label class="ex-form-label">عنوان کالا</label>
        <input type="text" name="title" class="form-control" required minlength="5"
               style="padding:14px 16px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit;font-size:.9375rem">
      </div>

      <div class="ex-form-group">
        <label class="ex-form-label">توضیحات کوتاه</label>
        <textarea name="description" class="form-control" rows="3" required minlength="10"
                  style="padding:14px 16px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit;font-size:.9375rem;resize:vertical"></textarea>
      </div>

      <div class="ex-form-group">
        <label class="ex-form-label">دسته‌بندی</label>
        <select name="category_id" class="form-control" required
                style="padding:14px 16px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit;font-size:.9375rem">
          <option value="">انتخاب کنید</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= (int) $cat['id'] ?>"><?= h($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="ex-form-group">
        <label class="ex-form-label">ارزش تقریبی (تومان)</label>
        <input type="text" name="estimated_value" class="form-control" inputmode="numeric" required
               style="padding:14px 16px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit;font-size:.9375rem">
      </div>

      <div class="ex-form-group">
        <label class="ex-form-label">تصویر کالا</label>
        <input type="file" name="image" class="form-control" accept="image/*"
               style="padding:12px;border-radius:var(--ex-radius-md);border:2px solid var(--ex-border);font-family:inherit">
      </div>

      <div class="ex-actions" style="margin-top:24px">
        <button type="submit" class="ex-btn ex-btn--primary">
          <i class="bi bi-check-lg"></i>
          افزودن به لیست پیشنهاد
        </button>
        <button type="button" class="ex-btn ex-btn--outline" id="so-close-quick-modal-2">
          انصراف
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('[data-navigate]').forEach(function (el) {
  el.addEventListener('click', function (e) {
    const url = el.getAttribute('data-navigate');
    if (!url) return;
    e.preventDefault();
    window.location.href = url;
  });
});

document.querySelectorAll('.ex-pick').forEach(function(el) {
  el.addEventListener('click', function() {
    var id = el.getAttribute('data-id');
    document.querySelectorAll('.ex-pick').forEach(function(x) { x.classList.remove('is-selected'); });
    el.classList.add('is-selected');
    document.getElementById('pickedListingId').value = id;
    var radio = el.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
  });
});

var modal = document.getElementById('so-quick-modal');
var openBtns = [document.getElementById('so-open-quick-listing'), document.getElementById('so-open-quick-listing-empty')];
var closeBtns = [document.getElementById('so-close-quick-modal'), document.getElementById('so-close-quick-modal-2')];

openBtns.forEach(function(btn) {
  btn?.addEventListener('click', function() { modal.classList.add('is-open'); });
});
closeBtns.forEach(function(btn) {
  btn?.addEventListener('click', function() { modal.classList.remove('is-open'); });
});
modal?.addEventListener('click', function(e) { if (e.target === modal) modal.classList.remove('is-open'); });

<?php if (!$myListings && !$error): ?>modal?.classList.add('is-open');<?php endif; ?>

var suggestedBtn = document.getElementById('suggestedCashBtn');
var cashInput = document.getElementById('cash_difference');
var cashDiffInput = document.getElementById('cashDiffInput');
var suggestedCash = <?= $suggestedCash ?>;

suggestedBtn?.addEventListener('click', function() {
  if (cashInput) {
    cashInput.value = suggestedCash.toLocaleString('en-US');
    cashDiffInput.value = suggestedCash;
  }
});

cashInput?.addEventListener('input', function() {
  var val = this.value.replace(/[^\d]/g, '');
  cashDiffInput.value = val;
});

/* ── Drag & Drop Sorting for Pick List ─────────────────────────────── */
(function() {
  var list = document.getElementById('exPicklist');
  if (!list) return;
  var items = list.querySelectorAll('.ex-pick');
  var dragSrcEl = null;

  items.forEach(function(item) {
    item.addEventListener('dragstart', handleDragStart);
    item.addEventListener('dragend', handleDragEnd);
    item.addEventListener('dragover', handleDragOver);
    item.addEventListener('dragleave', handleDragLeave);
    item.addEventListener('drop', handleDrop);
  });

  function handleDragStart(e) {
    dragSrcEl = this;
    this.classList.add('dragging');
    try {
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', this.getAttribute('data-id'));
    } catch (_) {}
  }

  function handleDragEnd() {
    this.classList.remove('dragging');
    list.querySelectorAll('.ex-pick').forEach(function(el) {
      el.classList.remove('drag-over-before', 'drag-over-after');
    });
    dragSrcEl = null;
  }

  function handleDragOver(e) {
    if (e.preventDefault) e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (this === dragSrcEl) return false;

    var rect = this.getBoundingClientRect();
    var offset = e.clientY - rect.top;
    var before = offset < (rect.height / 2);

    list.querySelectorAll('.ex-pick').forEach(function(el) {
      if (el !== this) {
        el.classList.remove('drag-over-before', 'drag-over-after');
      }
    }.bind(this));

    if (before) {
      this.classList.add('drag-over-before');
      this.classList.remove('drag-over-after');
    } else {
      this.classList.add('drag-over-after');
      this.classList.remove('drag-over-before');
    }
    return false;
  }

  function handleDragLeave() {
    this.classList.remove('drag-over-before', 'drag-over-after');
  }

  function handleDrop(e) {
    if (e.preventDefault) e.preventDefault();
    if (e.stopPropagation) e.stopPropagation();
    if (dragSrcEl === this) return;

    var before = this.classList.contains('drag-over-before');
    this.classList.remove('drag-over-before', 'drag-over-after');

    if (before) {
      this.parentNode.insertBefore(dragSrcEl, this);
    } else {
      this.parentNode.insertBefore(dragSrcEl, this.nextSibling);
    }
    return false;
  }
})();
</script>

<?php render_footer(); ?>
