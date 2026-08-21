<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/store_orders.php';

$user = require_auth();
$uid  = (int)$user['id'];
$listingId = (int)($_GET['listing_id'] ?? $_POST['listing_id'] ?? 0);
$error = '';
$info  = '';

$listing = $listingId ? DB::fetch(
    'SELECT l.*, u.name AS seller_name, u.store_name, u.store_slug, u.seller_type, u.store_lat, u.store_lng,
            (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS thumb
     FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?',
    [$listingId]
) : null;

if (!$listing || !listing_can_cash_buy($listing, $user)) {
    header('Location: ' . APP_URL . '/listings/view?id=' . $listingId);
    exit;
}

$maxStep = 3;
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
if ($step < 1) $step = 1;
if ($step > $maxStep) $step = $maxStep;

$provinces = iran_provinces();
$savedAddresses = user_addresses($uid);
$defaultAddr = user_default_address($uid);

$form = [
    'address_id'        => (int)($_POST['address_id']         ?? ($defaultAddr ? $defaultAddr['id'] : 0)),
    'recipient_name'    => trim((string)($_POST['recipient_name']    ?? ($defaultAddr['recipient_name']  ?? $user['name']  ?? ''))),
    'recipient_phone'   => preg_replace('/^\+98/', '0', (string)($_POST['recipient_phone']   ?? ($defaultAddr['recipient_phone'] ?? $user['phone'] ?? ''))),
    'province'          => trim((string)($_POST['province']          ?? ($defaultAddr['province']        ?? ''))),
    'city'              => trim((string)($_POST['city']              ?? ($defaultAddr['city']            ?? ($user['city'] ?? '')))),
    'shipping_address'  => trim((string)($_POST['shipping_address']  ?? ($defaultAddr['address']         ?? ''))),
    'postal_code'       => trim((string)($_POST['postal_code']       ?? ($defaultAddr['postal_code']     ?? ''))),
    'address_title'     => trim((string)($_POST['address_title']     ?? '')),
    'save_address'      => isset($_POST['save_address']) ? 1 : 0,
    'set_default_address' => isset($_POST['set_default_address']) ? 1 : 0,
    'shipping_method'   => trim((string)($_POST['shipping_method']   ?? 'post')),
    'buyer_note'        => trim((string)($_POST['buyer_note']        ?? '')),
];

$shippingMethods = shipping_method_list();
$productPrice = (int)round((float)$listing['sell_price']);

// --- Step navigation --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['nav'] ?? 'submit');

    if ($action === 'next_step') {
        rate_limit_ip_or_fail('store_checkout', 10, 900);

        if ($step === 1) {
            if ($form['address_id'] > 0) {
                $adr = DB::fetch('SELECT * FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1', [$form['address_id'], $uid]);
                if ($adr) {
                    $form['recipient_name']  = $adr['recipient_name'];
                    $form['recipient_phone'] = $adr['recipient_phone'];
                    $form['province']        = $adr['province'] ?? '';
                    $form['city']            = $adr['city'];
                    $form['shipping_address']= $adr['address'];
                    $form['postal_code']     = $adr['postal_code'] ?? '';
                } else {
                    $error = 'آدرس انتخاب‌شده معتبر نیست.';
                }
            }
            if (!$error) {
                if (mb_strlen($form['recipient_name']) < 3) $error = 'نام گیرنده را وارد کنید.';
                elseif (!preg_match('/^09\d{9}$/', preg_replace('/\D/','',$form['recipient_phone']))) $error = 'شماره موبایل گیرنده معتبر نیست.';
                elseif (mb_strlen($form['province']) < 2) $error = 'استان را انتخاب کنید.';
                elseif (mb_strlen($form['city']) < 2) $error = 'شهر را انتخاب کنید.';
                elseif (mb_strlen($form['shipping_address']) < 10) $error = 'آدرس کامل ارسال را وارد کنید.';
            }
            if (!$error) $step = 2;
        } elseif ($step === 2) {
            $methodValid = false;
            foreach ($shippingMethods as $m) { if ($m['key'] === $form['shipping_method']) { $methodValid = true; break; } }
            if (!$methodValid) $error = 'روش ارسال نامعتبر است.';
            if (!$error) $step = 3;
        }
    } elseif ($action === 'prev_step') {
        $step = max(1, $step - 1);
    } elseif ($action === 'submit') {
        rate_limit_ip_or_fail('store_checkout', 10, 900);
        if ($form['address_id'] > 0) {
            $adr = DB::fetch('SELECT * FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1', [$form['address_id'], $uid]);
            if ($adr) {
                $form['recipient_name']  = $adr['recipient_name'];
                $form['recipient_phone'] = $adr['recipient_phone'];
                $form['province']        = $adr['province'] ?? '';
                $form['city']            = $adr['city'];
                $form['shipping_address']= $adr['address'];
                $form['postal_code']     = $adr['postal_code'] ?? '';
            }
        }
        $result = create_store_order_checkout($listingId, $uid, $form);
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            echo SEPPayment::getPaymentForm($result['token']);
            exit;
        }
    }
}

$shippingCost = 0;
foreach ($shippingMethods as $m) {
    if ($m['key'] === $form['shipping_method']) {
        $shippingCost = calculate_shipping_cost(
            $form['shipping_method'],
            (float)$productPrice,
            $listing['store_lat'] ?? null,
            $listing['store_lng'] ?? null,
            $form['province'] ?: null
        );
        break;
    }
}
$totalAmount = $productPrice + $shippingCost;

$stepTitles = [
    1 => 'اطلاعات ارسال',
    2 => 'روش ارسال',
    3 => 'بررسی و پرداخت',
];

render_head($stepTitles[$step] . ' | ' . h($listing['title']));
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">
<style>
  /* Custom overrides for checkout flow to perfectly match design reference */
  .ex-page { padding-bottom: 140px; }
  .ex-step-line-active {
    position: absolute;
    top: 40px;
    right: 10%;
    height: 3px;
    background: var(--ex-navy);
    z-index: 0;
    transition: width 0.4s ease;
  }
  @media (max-width: 992px) {
    .ex-step-line-active { top: 40px; }
  }
  .addr-card__radio { position: absolute; top: 16px; left: 16px; width: 20px; height: 20px; accent-color: var(--ex-navy); cursor: pointer; }
  .ex-pick.is-selected .addr-card__radio { border-color: var(--ex-navy); }
  
  /* Responsive Grids */
  .ex-form-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
  @media (min-width: 640px) {
    .ex-form-grid { grid-template-columns: 1fr 1fr; }
  }
</style>

<div class="ex-page">
  <div class="ex-container">
    <div class="ex-header">
      <a href="<?= APP_URL ?>/listings/view?id=<?= $listingId ?>" class="ex-header__back">
        <i class="bi bi-arrow-right"></i> بازگشت به محصول
      </a>
      <div class="ex-header__row">
        <div>
          <h1 class="ex-header__title">خرید نقدی</h1>
          <p class="ex-header__subtitle">مراحل خرید را تکمیل و سفارش خود را ثبت کنید.</p>
        </div>
      </div>
    </div>

    <!-- Stepper -->
    <div class="ex-stepper" aria-label="مراحل خرید">
      <div class="ex-stepper__progress" style="width: <?= (($step - 1) / ($maxStep - 1)) * 80 ?>%; right: 10%;"></div>
      <?php for ($i = 1; $i <= $maxStep; $i++): ?>
        <div class="ex-step <?= $i < $step ? 'is-done' : '' ?> <?= $i === $step ? 'is-active' : '' ?>">
          <div class="ex-step__circle">
            <?php if ($i < $step): ?>
              <i class="bi bi-check-lg"></i>
            <?php else: ?>
              <?= persian_digits((string)$i) ?>
            <?php endif; ?>
          </div>
          <div class="ex-step__label"><?= h($stepTitles[$i]) ?></div>
        </div>
      <?php endfor; ?>
    </div>

    <?php if ($error): ?>
      <div class="ex-alert ex-alert--danger">
        <i class="bi bi-exclamation-circle ex-alert__icon"></i>
        <div><?= h($error) ?></div>
      </div>
    <?php endif; ?>

    <form method="POST" id="checkoutForm">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_id" value="<?= $listingId ?>">
      <input type="hidden" name="step" value="<?= $step ?>">
      <input type="hidden" name="province" value="<?= h($form['province']) ?>" id="hiddenProvince">
      <input type="hidden" name="city" value="<?= h($form['city']) ?>" id="hiddenCity">

      <!-- Product Summary Card -->
      <div class="ex-card">
        <div class="ex-card__label"><i class="bi bi-bag-check"></i> خلاصه سفارش</div>
        <div class="ex-product">
          <?php if (!empty($listing['thumb'])): ?>
            <img src="<?= UPLOAD_URL . h($listing['thumb']) ?>" alt="<?= h($listing['title']) ?>" class="ex-product__thumb">
          <?php else: ?>
            <div class="ex-product__thumb ex-product__thumb--empty"><i class="bi bi-image"></i></div>
          <?php endif; ?>
          <div class="ex-product__info">
            <h3 class="ex-product__title"><?= h($listing['title']) ?></h3>
            <div class="ex-product__meta">
              <?php if (!empty($listing['store_name'])): ?>
                <span class="ex-product__chip"><i class="bi bi-shop"></i> <?= h($listing['store_name']) ?></span>
              <?php endif; ?>
            </div>
            <div class="ex-product__price">
              <span class="ex-product__price-label">قیمت واحد:</span>
              <?= fmt_credit((float)$productPrice) ?>
            </div>
          </div>
        </div>

        <div class="ex-cash-box ex-mt-4">
          <div class="ex-cash-row">
            <span>قیمت کالا</span>
            <strong><?= fmt_credit((float)$productPrice) ?></strong>
          </div>
          <div class="ex-cash-row">
            <span>هزینه ارسال</span>
            <?php if ($step >= 2 && $form['shipping_method']): ?>
              <span class="ex-product__chip" style="margin-right: 8px;"><?= h(shipping_label($form['shipping_method'])) ?></span>
            <?php endif; ?>
            <strong><?= $shippingCost === 0 ? '<span class="ex-chip ex-chip--success">رایگان</span>' : fmt_credit((float)$shippingCost) ?></strong>
          </div>
          <div class="ex-cash-total">
            <span>مبلغ قابل پرداخت</span>
            <strong><?= fmt_credit((float)$totalAmount) ?></strong>
          </div>
        </div>
      </div>

      <!-- Step Content -->
      <div class="ex-card">
        <!-- ================= STEP 1: Shipping Address ================= -->
        <?php if ($step === 1): ?>
          <h2 class="ex-card__title">اطلاعات ارسال</h2>
          <p class="ex-card__subtitle">آدرس محل تحویل سفارش را انتخاب یا وارد کنید.</p>

          <?php if ($savedAddresses): ?>
            <div class="ex-form-group">
              <label class="ex-form-label">آدرس‌های ذخیره‌شده</label>
              <div class="ex-picklist">
                <label class="ex-pick <?= $form['address_id'] === 0 ? 'is-selected' : '' ?>">
                  <input type="radio" name="address_id" value="0" class="addr-card__radio" <?= $form['address_id'] === 0 ? 'checked' : '' ?>>
                  <div class="ex-pick__check"></div>
                  <div class="ex-pick__info">
                    <div class="ex-pick__title"><i class="bi bi-plus-circle"></i> آدرس جدید</div>
                    <div class="ex-pick__meta">وارد کردن اطلاعات گیرنده و آدرس جدید</div>
                  </div>
                </label>
                <?php foreach ($savedAddresses as $a): ?>
                  <label class="ex-pick <?= $form['address_id'] === (int)$a['id'] ? 'is-selected' : '' ?>">
                    <input type="radio" name="address_id" value="<?= (int)$a['id'] ?>" class="addr-card__radio" <?= $form['address_id'] === (int)$a['id'] ? 'checked' : '' ?>>
                    <div class="ex-pick__check"></div>
                    <div class="ex-pick__info">
                      <div class="ex-pick__title">
                        <?= h($a['title'] ?: 'آدرس من') ?>
                        <?php if (!empty($a['is_default'])): ?><span class="ex-chip ex-chip--success" style="margin-right: 8px; font-size: 0.65rem;">پیش‌فرض</span><?php endif; ?>
                      </div>
                      <div class="ex-pick__meta">
                        <span><i class="bi bi-person"></i> <?= h($a['recipient_name']) ?></span>
                        <span><i class="bi bi-phone"></i> <?= h($a['recipient_phone']) ?></span>
                      </div>
                      <div class="ex-pick__meta">
                        <span><i class="bi bi-geo-alt"></i> <?= h($a['province'] ? $a['province'].'، ' : '') ?><?= h($a['city']) ?></span>
                      </div>
                      <div style="font-size: 0.8125rem; color: var(--ex-muted); margin-top: 4px;"><?= h($a['address']) ?></div>
                    </div>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div id="newAddressFields" style="<?= $form['address_id'] > 0 && $savedAddresses ? 'display:none;' : '' ?>">
            <div class="ex-form-group">
              <div class="ex-form-grid">
                <div>
                  <label class="ex-form-label">نام و نام خانوادگی گیرنده</label>
                  <input type="text" name="recipient_name" class="form-control" maxlength="120" value="<?= h($form['recipient_name']) ?>">
                </div>
                <div>
                  <label class="ex-form-label">شماره موبایل گیرنده</label>
                  <input type="tel" name="recipient_phone" class="form-control" dir="ltr" placeholder="09123456789" value="<?= h($form['recipient_phone']) ?>">
                </div>
              </div>
            </div>

            <div class="ex-form-group">
              <div class="ex-form-grid">
                <div>
                  <label class="ex-form-label">استان</label>
                  <select name="province_select" id="provinceSelect" class="form-control">
                    <option value="">انتخاب کنید...</option>
                    <?php foreach ($provinces as $p): ?>
                      <option value="<?= h($p) ?>" <?= $form['province'] === $p ? 'selected' : '' ?>><?= h($p) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="ex-form-label">شهر</label>
                  <select name="city_select" id="citySelect" class="form-control">
                    <?php if ($form['province'] && ($cities = iran_cities_by_province($form['province']))): ?>
                      <option value="">انتخاب کنید...</option>
                      <?php foreach ($cities as $c): ?>
                        <option value="<?= h($c) ?>" <?= $form['city'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <option value="">ابتدا استان را انتخاب کنید</option>
                    <?php endif; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="ex-form-group">
              <label class="ex-form-label">آدرس کامل پستی</label>
              <textarea name="shipping_address" class="form-control" rows="3" placeholder="نام خیابان، کوچه، پلاک و واحد را وارد کنید"><?= h($form['shipping_address']) ?></textarea>
            </div>

            <div class="ex-form-group">
              <div class="ex-form-grid">
                <div>
                  <label class="ex-form-label">کد پستی (۱۰ رقمی)</label>
                  <input type="text" name="postal_code" class="form-control" dir="ltr" value="<?= h($form['postal_code']) ?>">
                </div>
                <div>
                  <label class="ex-form-label">عنوان آدرس</label>
                  <input type="text" name="address_title" class="form-control" placeholder="مثلاً منزل یا محل کار" value="<?= h($form['address_title']) ?>">
                </div>
              </div>
            </div>

            <?php if ($form['address_id'] === 0 || !$savedAddresses): ?>
              <div class="ex-form-group">
                <label class="ex-pick" style="padding: 10px 14px; border-width: 1px;">
                  <input type="checkbox" name="save_address" value="1" <?= $form['save_address'] ? 'checked' : '' ?> style="width: 18px; height: 18px; margin-left: 10px;">
                  <span style="font-size: 0.875rem; font-weight: 600;">ذخیره این آدرس در لیست آدرس‌های من</span>
                </label>
              </div>
            <?php endif; ?>
          </div>

          <div class="ex-form-group">
            <label class="ex-form-label">توضیحات سفارش (اختیاری)</label>
            <textarea name="buyer_note" class="form-control" rows="2" placeholder="اگر نکته خاصی برای ارسال وجود دارد اینجا بنویسید"><?= h($form['buyer_note']) ?></textarea>
          </div>

        <!-- ================= STEP 2: Shipping Method ================= -->
        <?php elseif ($step === 2): ?>
          <h2 class="ex-card__title">روش ارسال</h2>
          <p class="ex-card__subtitle">مناسب‌ترین روش ارسال را با توجه به هزینه و زمان انتخاب کنید.</p>

          <div class="ex-picklist">
            <?php foreach ($shippingMethods as $m):
              $cost = calculate_shipping_cost($m['key'], (float)$productPrice, $listing['store_lat']??null, $listing['store_lng']??null, $form['province']?:null);
              $isFree = $cost === 0;
            ?>
              <label class="ex-pick <?= $form['shipping_method'] === $m['key'] ? 'is-selected' : '' ?>">
                <input type="radio" name="shipping_method" value="<?= h($m['key']) ?>" class="addr-card__radio" <?= $form['shipping_method'] === $m['key'] ? 'checked' : '' ?>>
                <div class="ex-pick__check"></div>
                <div style="width: 48px; height: 48px; border-radius: 12px; background: var(--ex-bg); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--ex-navy);">
                  <i class="bi bi-<?= $m['key']==='post'?'box-seam':($m['key']==='tipax'?'truck':($m['key']==='courier'?'bicycle':'person-check')) ?>"></i>
                </div>
                <div class="ex-pick__info">
                  <div class="ex-pick__title"><?= h($m['label']) ?></div>
                  <div class="ex-pick__meta"><i class="bi bi-clock"></i> زمان تقریبی: <?= h($m['eta']) ?></div>
                </div>
                <div class="ex-pick__price">
                  <?= $isFree ? '<span class="ex-chip ex-chip--success">رایگان</span>' : fmt_credit((float)$cost) ?>
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="ex-alert ex-alert--info ex-mt-4">
            <i class="bi bi-info-circle ex-alert__icon"></i>
            <div>ارسال رایگان برای خریدهای بالای مبالغ مشخص شده در قوانین سایت اعمال می‌شود.</div>
          </div>

        <!-- ================= STEP 3: Review & Pay ================= -->
        <?php elseif ($step === 3): ?>
          <h2 class="ex-card__title">بررسی نهایی</h2>
          <p class="ex-card__subtitle">اطلاعات سفارش خود را بررسی کنید و برای پرداخت اقدام کنید.</p>

          <div class="ex-cash-box ex-mt-4">
            <div class="ex-card__label"><i class="bi bi-geo-alt"></i> آدرس و اطلاعات گیرنده</div>
            <div style="padding: 10px 0;">
              <div style="font-weight: 800; font-size: 1rem; margin-bottom: 4px;"><?= h($form['recipient_name']) ?></div>
              <div style="color: var(--ex-muted); font-size: 0.875rem; margin-bottom: 8px;"><i class="bi bi-phone"></i> <?= h($form['recipient_phone']) ?></div>
              <div style="line-height: 1.6; font-size: 0.9375rem;">
                <?= h($form['province']) ?>، <?= h($form['city']) ?>، <?= h($form['shipping_address']) ?>
                <?php if ($form['postal_code']): ?>
                  <br><span style="color: var(--ex-muted);">کد پستی: <?= h($form['postal_code']) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($form['buyer_note']): ?>
                <div style="margin-top: 12px; padding: 10px; background: #fff; border-radius: 8px; border: 1px dashed var(--ex-border);">
                  <span style="font-size: 0.75rem; font-weight: 700; color: var(--ex-muted); display: block; margin-bottom: 4px;">توضیحات شما:</span>
                  <div style="font-size: 0.875rem;"><?= nl2br(h($form['buyer_note'])) ?></div>
                </div>
              <?php endif; ?>
            </div>

            <div class="ex-card__divider"></div>

            <div class="ex-card__label"><i class="bi bi-credit-card"></i> روش پرداخت</div>
            <div class="ex-pick is-selected" style="margin-top: 8px;">
              <div class="ex-pick__check"></div>
              <div style="width: 40px; height: 40px; border-radius: 10px; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #1E40AF;">
                <i class="bi bi-shield-check"></i>
              </div>
              <div class="ex-pick__info">
                <div class="ex-pick__title">درگاه امن بانکی</div>
                <div class="ex-pick__meta">پرداخت آنلاین با تمامی کارت‌های عضو شتاب</div>
              </div>
            </div>
          </div>

          <div class="ex-alert ex-alert--success ex-mt-4">
            <i class="bi bi-shield-lock ex-alert__icon"></i>
            <div>پرداخت شما در بستر امن بانکی انجام می‌شود و اطلاعات شما کاملاً محفوظ است.</div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Navigation Buttons (Desktop) -->
      <div class="ex-actions ex-actions--row ex-hidden-mobile">
        <?php if ($step > 1): ?>
          <button type="submit" name="nav" value="prev_step" class="ex-btn ex-btn--outline" style="flex: 0 0 160px;">
            <i class="bi bi-arrow-right"></i> بازگشت
          </button>
        <?php endif; ?>

        <?php if ($step < $maxStep): ?>
          <button type="submit" name="nav" value="next_step" class="ex-btn ex-btn--primary">
            ادامه فرایند خرید <i class="bi bi-arrow-left"></i>
          </button>
        <?php else: ?>
          <button type="submit" name="nav" value="submit" class="ex-btn ex-btn--cta">
            تأیید و پرداخت نهایی <i class="bi bi-credit-card"></i>
          </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Sticky Bottom Bar (Mobile) -->
<div class="ex-sticky-bar ex-visible-mobile">
  <div class="ex-sticky-bar__inner">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
      <span style="font-size: 0.8125rem; color: var(--ex-muted); font-weight: 600;">مبلغ نهایی:</span>
      <span style="font-size: 1.125rem; font-weight: 900; color: var(--ex-navy);"><?= fmt_credit((float)$totalAmount) ?></span>
    </div>
    <div class="ex-sticky-bar__row">
      <?php if ($step > 1): ?>
        <button type="button" onclick="document.querySelector('button[value=prev_step]').click()" class="ex-btn ex-btn--outline" style="flex: 0 0 60px; padding: 0;">
          <i class="bi bi-arrow-right" style="font-size: 1.25rem;"></i>
        </button>
      <?php endif; ?>

      <?php if ($step < $maxStep): ?>
        <button type="button" onclick="document.querySelector('button[value=next_step]').click()" class="ex-btn ex-btn--primary">
          ادامه خرید <i class="bi bi-arrow-left"></i>
        </button>
      <?php else: ?>
        <button type="button" onclick="document.querySelector('button[value=submit]').click()" class="ex-btn ex-btn--cta">
          پرداخت نهایی <i class="bi bi-credit-card"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Invisible buttons for JS trigger -->
<div style="display:none;">
  <button type="submit" form="checkoutForm" name="nav" value="prev_step" id="btnPrev"></button>
  <button type="submit" form="checkoutForm" name="nav" value="next_step" id="btnNext"></button>
  <button type="submit" form="checkoutForm" name="nav" value="submit" id="btnSubmit"></button>
</div>

<script>
  (function () {
    const provinceSelect = document.getElementById('provinceSelect');
    const citySelect     = document.getElementById('citySelect');
    const hiddenProv     = document.getElementById('hiddenProvince');
    const hiddenCity     = document.getElementById('hiddenCity');
    const newAddrWrap    = document.getElementById('newAddressFields');
    const addrRadios     = document.querySelectorAll('input[name="address_id"]');

    const citiesByProvince = <?= json_encode(array_combine(iran_provinces(), array_map(function($p){return iran_cities_by_province($p);}, iran_provinces())), JSON_UNESCAPED_UNICODE) ?>;

    function syncHidden() {
      if (provinceSelect) hiddenProv.value = provinceSelect.value;
      if (citySelect)     hiddenCity.value = citySelect.value;
    }
    if (provinceSelect) {
      provinceSelect.addEventListener('change', function () {
        citySelect.innerHTML = '';
        const list = citiesByProvince[this.value] || [];
        if (list.length === 0) {
          citySelect.innerHTML = '<option value="">ابتدا استان را انتخاب کنید</option>';
        } else {
          citySelect.innerHTML = '<option value="">انتخاب کنید...</option>' +
            list.map(c => `<option value="${c}">${c}</option>`).join('');
        }
        syncHidden();
      });
      citySelect && citySelect.addEventListener('change', syncHidden);
    }
    syncHidden();

    addrRadios.forEach(r => r.addEventListener('change', function () {
      if (!newAddrWrap) return;
      if (this.value === '0') {
        newAddrWrap.style.display = '';
        const inputs = newAddrWrap.querySelectorAll('input, select, textarea');
        inputs.forEach(i => i.disabled = false);
      } else {
        newAddrWrap.style.display = 'none';
      }
    }));

    const selAddr = document.querySelector('input[name="address_id"]:checked');
    if (selAddr && selAddr.value !== '0' && newAddrWrap) {
      const inputs = newAddrWrap.querySelectorAll('input, select, textarea');
      inputs.forEach(i => i.disabled = true);
    }
  })();
</script>

<?php render_footer(); ?>