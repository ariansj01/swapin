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
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/orders.css?v=<?= @filemtime(__DIR__ . '/../src/css/orders.css') ?: time() ?>">
<style>
  .co-stepper{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 28px;padding:16px 20px;background:#fff;border-radius:20px;box-shadow:var(--card-shadow,0 1px 2px rgba(16,24,40,.05));}
  .co-step{display:flex;align-items:center;gap:10px;flex:1;}
  .co-step__dot{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.9rem;flex-shrink:0;background:#E5E7EB;color:#6B7280;border:2px solid #E5E7EB;transition:all .2s ease;}
  .co-step.is-done .co-step__dot{background:#10B981;border-color:#10B981;color:#fff;}
  .co-step.is-active .co-step__dot{background:var(--primary,#2563eb);border-color:var(--primary,#2563eb);color:#fff;box-shadow:0 0 0 6px rgba(37,99,235,.1);}
  .co-step__label{font-size:.875rem;font-weight:700;color:#6B7280;white-space:nowrap;}
  .co-step.is-done .co-step__label{color:#065F46;}
  .co-step.is-active .co-step__label{color:var(--dash-navy,#0B1F4D);}
  .co-step__line{flex:1;height:2px;background:#E5E7EB;border-radius:2px;}
  .co-step.is-done + .co-step__line,
  .co-step__line.is-done{background:#10B981;}

  .addr-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:16px;}
  .addr-card{border:2px solid var(--border);border-radius:16px;padding:14px 16px;cursor:pointer;transition:all .15s;position:relative;}
  .addr-card:hover{border-color:var(--primary,.25);}
  .addr-card.is-selected{border-color:var(--primary,#2563eb);background:rgba(37,99,235,.04);}
  .addr-card__title{font-weight:800;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
  .addr-card__def{font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:999px;background:rgba(16,185,129,.12);color:#059669;}
  .addr-card__meta{font-size:.8125rem;color:var(--text-muted);line-height:1.7;}
  .addr-card__radio{position:absolute;top:12px;left:12px;width:18px;height:18px;accent-color:var(--primary,#2563eb);}

  .ship-methods{display:grid;gap:12px;}
  .ship-method{border:2px solid var(--border);border-radius:16px;padding:16px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:14px;}
  .ship-method:hover{border-color:rgba(37,99,235,.4);}
  .ship-method.is-selected{border-color:var(--primary,#2563eb);background:rgba(37,99,235,.04);}
  .ship-method__icon{width:44px;height:44px;border-radius:12px;background:rgba(37,99,235,.08);color:var(--primary,#2563eb);display:grid;place-items:center;flex-shrink:0;font-size:1.2rem;}
  .ship-method__body{flex:1;min-width:0;}
  .ship-method__label{font-weight:800;margin-bottom:2px;}
  .ship-method__eta{font-size:.8125rem;color:var(--text-muted);}
  .ship-method__price{font-weight:800;color:var(--dash-navy);font-size:1rem;flex-shrink:0;}
  .ship-method__price.is-free{color:#10B981;}

  .chk-summary{border:1px solid var(--border);border-radius:16px;padding:16px;background:#FAFBFC;}
  .chk-summary__row{display:flex;justify-content:space-between;padding:8px 0;font-size:.95rem;}
  .chk-summary__row:last-child{padding-top:14px;margin-top:4px;border-top:1px dashed var(--border);font-weight:800;font-size:1.1rem;color:var(--dash-navy);}
  .chk-summary__row.muted span:first-child{color:var(--text-muted);}

  .chk-nav-btns{display:flex;gap:10px;margin-top:20px;}
  .chk-nav-btns .btn{flex:1;}

  .review-address{background:#f9fafb;border:1px dashed var(--border);border-radius:14px;padding:16px;}
  .review-address h4{font-size:.875rem;color:var(--text-muted);margin-bottom:8px;font-weight:600;}
  .review-address p{margin:4px 0;font-size:.92rem;}

  @media (max-width:640px){
    .co-stepper{padding:12px 10px;gap:4px;}
    .co-step__label{display:none;}
    .chk-nav-btns{flex-direction:column-reverse;}
  }
</style>

<div class="section-sm">
  <div class="container-md">
    <div class="order-page-head">
      <a href="<?= APP_URL ?>/listings/view?id=<?= $listingId ?>" class="order-back"><i class="bi bi-arrow-right"></i> بازگشت به محصول</a>
      <h1>خرید نقدی از فروشگاه</h1>
      <p class="order-page-sub">مراحل خرید را به‌ترتیب تکمیل کنید و در پایان با خیال راحت پرداخت کنید.</p>
    </div>

    <!-- Stepper -->
    <div class="co-stepper" aria-label="مراحل خرید">
      <?php for ($i = 1; $i <= $maxStep; $i++): ?>
        <div class="co-step <?= $i < $step ? 'is-done' : '' ?> <?= $i === $step ? 'is-active' : '' ?>">
          <div class="co-step__dot"><?= $i < $step ? '<i class="bi bi-check-lg"></i>' : (persian_digits((string)$i)) ?></div>
          <div class="co-step__label"><?= h($stepTitles[$i]) ?></div>
        </div>
        <?php if ($i < $maxStep): ?>
          <div class="co-step__line <?= $i < $step ? 'is-done' : '' ?>"></div>
        <?php endif; ?>
      <?php endfor; ?>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
    <div class="alert alert-info mb-5"><i class="bi bi-info-circle"></i> <?= h($info) ?></div>
    <?php endif; ?>

    <form method="POST" class="order-checkout-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="listing_id" value="<?= $listingId ?>">
      <input type="hidden" name="step" value="<?= $step ?>">
      <input type="hidden" name="province" value="<?= h($form['province']) ?>" id="hiddenProvince">
      <input type="hidden" name="city" value="<?= h($form['city']) ?>" id="hiddenCity">

      <!-- Left side: Summary card -->
      <div class="card order-summary-card">
        <div class="card-body">
          <h2 class="order-card-title"><i class="bi bi-bag-check"></i> خلاصه سفارش</h2>
          <div class="order-product">
            <?php if (!empty($listing['thumb'])): ?>
            <img src="<?= UPLOAD_URL . h($listing['thumb']) ?>" alt="<?= h($listing['title']) ?>" class="order-product__thumb">
            <?php endif; ?>
            <div>
              <div class="order-product__title"><?= h($listing['title']) ?></div>
              <?php if (!empty($listing['store_name'])): ?>
              <div class="order-product__store"><i class="bi bi-shop"></i> <?= h($listing['store_name']) ?></div>
              <?php endif; ?>
              <div class="order-product__price"><?= fmt_credit((float)$productPrice) ?></div>
            </div>
          </div>

          <div class="chk-summary">
            <div class="chk-summary__row muted">
              <span>قیمت کالا</span>
              <span><?= fmt_credit((float)$productPrice) ?></span>
            </div>
            <div class="chk-summary__row muted">
              <span>هزینه ارسال<?= $step >= 2 && $form['shipping_method'] ? ' ('.h(shipping_label($form['shipping_method'])).')' : '' ?></span>
              <span><?= $shippingCost === 0 ? '<span style="color:#10B981;font-weight:700">رایگان</span>' : fmt_credit((float)$shippingCost) ?></span>
            </div>
            <div class="chk-summary__row">
              <span>مبلغ قابل پرداخت</span>
              <span><?= fmt_credit((float)$totalAmount) ?></span>
            </div>
          </div>

          <?php if ($step === 3): ?>
          <div style="margin-top:18px;" class="review-address">
            <h4><i class="bi bi-geo-alt"></i> آدرس ارسال</h4>
            <p><strong><?= h($form['recipient_name']) ?></strong> — <span dir="ltr"><?= h($form['recipient_phone']) ?></span></p>
            <p><?= h($form['province']) ?> — <?= h($form['city']) ?><?= $form['postal_code'] ? ' — کد پستی: <span dir="ltr">'.h($form['postal_code']).'</span>' : '' ?></p>
            <p style="color:var(--text-muted);font-size:.875rem;"><?= nl2br(h($form['shipping_address'])) ?></p>
          </div>
          <?php if ($form['shipping_method']): ?>
          <div style="margin-top:14px;padding:12px 14px;border-radius:14px;background:#EFF6FF;border:1px solid #BFDBFE;">
            <div style="font-size:.8125rem;color:#1E40AF;font-weight:700;margin-bottom:4px;"><i class="bi bi-truck"></i> روش ارسال انتخاب‌شده</div>
            <div style="font-weight:800;"><?= h(shipping_label($form['shipping_method'])) ?></div>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right side: Step content -->
      <div class="card order-shipping-card">
        <div class="card-body">

          <!-- ================= STEP 1: Shipping Address ================= -->
          <?php if ($step === 1): ?>
            <h2 class="order-card-title"><i class="bi bi-geo-alt"></i> اطلاعات ارسال</h2>

            <?php if ($savedAddresses): ?>
              <div style="margin-bottom:18px;">
                <div style="font-size:.92rem;font-weight:700;margin-bottom:10px;">انتخاب از آدرس‌های ذخیره‌شده</div>
                <div class="addr-list">
                  <label class="addr-card <?= $form['address_id'] === 0 ? 'is-selected' : '' ?>">
                    <input type="radio" name="address_id" value="0" class="addr-card__radio" <?= $form['address_id'] === 0 ? 'checked' : '' ?>>
                    <div class="addr-card__title"><i class="bi bi-plus-circle" style="color:var(--primary)"></i> آدرس جدید</div>
                    <div class="addr-card__meta" style="color:var(--primary);font-weight:600;">کلیک کنید و یک آدرس جدید وارد کنید</div>
                  </label>
                  <?php foreach ($savedAddresses as $a): ?>
                    <label class="addr-card <?= $form['address_id'] === (int)$a['id'] ? 'is-selected' : '' ?>">
                      <input type="radio" name="address_id" value="<?= (int)$a['id'] ?>" class="addr-card__radio" <?= $form['address_id'] === (int)$a['id'] ? 'checked' : '' ?>>
                      <div class="addr-card__title">
                        <?= h($a['title'] ?: 'آدرس من') ?>
                        <?php if (!empty($a['is_default'])): ?><span class="addr-card__def">پیش‌فرض</span><?php endif; ?>
                      </div>
                      <div class="addr-card__meta">
                        <div><strong><?= h($a['recipient_name']) ?></strong></div>
                        <div dir="ltr"><?= h($a['recipient_phone']) ?></div>
                        <div><?= h($a['province'] ? $a['province'].' — ' : '') ?><?= h($a['city']) ?></div>
                        <div style="font-size:.75rem;line-height:1.5;"><?= h(mb_substr($a['address'],0,70)) ?><?= mb_strlen($a['address'])>70 ? '…' : '' ?></div>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <div id="newAddressFields" style="<?= $form['address_id'] > 0 && $savedAddresses ? 'display:none;' : '' ?>">
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">نام و نام خانوادگی گیرنده</label>
                  <input type="text" name="recipient_name" class="form-control" required maxlength="120"
                         value="<?= h($form['recipient_name']) ?>">
                </div>
                <div class="form-group">
                  <label class="form-label">شماره موبایل گیرنده</label>
                  <input type="tel" name="recipient_phone" class="form-control" required dir="ltr"
                         placeholder="09123456789" value="<?= h($form['recipient_phone']) ?>">
                </div>
              </div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">استان</label>
                  <select name="province_select" id="provinceSelect" class="form-control" required>
                    <option value="">انتخاب کنید...</option>
                    <?php foreach ($provinces as $p): ?>
                      <option value="<?= h($p) ?>" <?= $form['province'] === $p ? 'selected' : '' ?>><?= h($p) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">شهر</label>
                  <select name="city_select" id="citySelect" class="form-control" required>
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
              <div class="form-group">
                <label class="form-label">آدرس کامل</label>
                <textarea name="shipping_address" class="form-control" rows="3" required placeholder="خیابان، کوچه، پلاک، واحد..."><?= h($form['shipping_address']) ?></textarea>
              </div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">کد پستی (اختیاری)</label>
                  <input type="text" name="postal_code" class="form-control" dir="ltr" placeholder="۱۰ رقمی"
                         value="<?= h($form['postal_code']) ?>">
                </div>
                <div class="form-group">
                  <label class="form-label">عنوان آدرس (اختیاری)</label>
                  <input type="text" name="address_title" class="form-control" placeholder="مثل: منزل یا سرکار"
                         value="<?= h($form['address_title']) ?>">
                </div>
              </div>
              <?php if ($form['address_id'] === 0 || !$savedAddresses): ?>
              <div style="display:flex;gap:18px;flex-wrap:wrap;margin:4px 0 8px;">
                <label class="form-check-inline">
                  <input type="checkbox" name="save_address" value="1" <?= $form['save_address'] ? 'checked' : '' ?>>
                  ذخیره آدرس در حساب کاربری
                </label>
                <label class="form-check-inline">
                  <input type="checkbox" name="set_default_address" value="1" <?= $form['set_default_address'] ? 'checked' : '' ?>>
                  تنظیم به‌عنوان آدرس پیش‌فرض
                </label>
              </div>
              <?php endif; ?>
              <div class="form-group">
                <label class="form-label">توضیحات برای فروشنده (اختیاری)</label>
                <textarea name="buyer_note" class="form-control" rows="2" placeholder="مثل: زنگ بزنید قبل از آمدن"><?= h($form['buyer_note']) ?></textarea>
              </div>
            </div>

            <div class="chk-nav-btns">
              <button type="submit" name="nav" value="next_step" class="btn btn-primary btn-lg">
                ادامه: روش ارسال <i class="bi bi-arrow-left"></i>
              </button>
            </div>

          <!-- ================= STEP 2: Shipping Method ================= -->
          <?php elseif ($step === 2): ?>
            <h2 class="order-card-title"><i class="bi bi-truck"></i> روش ارسال</h2>
            <p style="color:var(--text-muted);font-size:.875rem;margin-bottom:16px;">
              ارسال رایگان برای خرید‌های بالای ۵ میلیون تومان (پست پیشتاز) و ۱۰ میلیون تومان (تیپاکس) اعمال می‌شود.
            </p>
            <div class="ship-methods">
              <?php foreach ($shippingMethods as $i => $m):
                $cost = calculate_shipping_cost($m['key'], (float)$productPrice, $listing['store_lat']??null, $listing['store_lng']??null, $form['province']?:null);
                $isFree = $cost === 0;
              ?>
                <label class="ship-method <?= $form['shipping_method'] === $m['key'] ? 'is-selected' : '' ?>">
                  <input type="radio" name="shipping_method" value="<?= h($m['key']) ?>" style="display:none;"
                         <?= $form['shipping_method'] === $m['key'] ? 'checked' : '' ?>>
                  <div class="ship-method__icon">
                    <i class="bi bi-<?= $m['key']==='post'?'box-seam':($m['key']==='tipax'?'truck':($m['key']==='courier'?'bicycle':'person-check')) ?>"></i>
                  </div>
                  <div class="ship-method__body">
                    <div class="ship-method__label"><?= h($m['label']) ?></div>
                    <div class="ship-method__eta"><i class="bi bi-clock"></i> زمان تقریبی: <?= h($m['eta']) ?></div>
                  </div>
                  <div class="ship-method__price <?= $isFree ? 'is-free' : '' ?>">
                    <?= $isFree ? '✓ رایگان' : fmt_credit((float)$cost) ?>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

            <div class="chk-nav-btns">
              <button type="submit" name="nav" value="prev_step" class="btn btn-outline btn-lg">
                <i class="bi bi-arrow-right"></i> بازگشت
              </button>
              <button type="submit" name="nav" value="next_step" class="btn btn-primary btn-lg">
                ادامه: بررسی و پرداخت <i class="bi bi-arrow-left"></i>
              </button>
            </div>

          <!-- ================= STEP 3: Review & Pay ================= -->
          <?php elseif ($step === 3): ?>
            <h2 class="order-card-title"><i class="bi bi-credit-card-2-front"></i> بررسی نهایی و پرداخت</h2>
            <p style="color:var(--text-muted);font-size:.875rem;margin-bottom:20px;">
              قبل از پرداخت، تمامی موارد بالا را بررسی کنید. پس از کلیک روی دکمه زیر به درگاه امن بانک سامان منتقل می‌شوید.
            </p>

            <div class="review-address" style="margin-bottom:16px;">
              <h4><i class="bi bi-geo-alt"></i> آدرس ارسال</h4>
              <p><strong><?= h($form['recipient_name']) ?></strong> — <span dir="ltr"><?= h($form['recipient_phone']) ?></span></p>
              <p><?= h($form['province']) ?> — <?= h($form['city']) ?><?= $form['postal_code'] ? ' — کد پستی: <span dir="ltr">'.h($form['postal_code']).'</span>' : '' ?></p>
              <p style="color:var(--text-muted);font-size:.875rem;"><?= nl2br(h($form['shipping_address'])) ?></p>
              <?php if (!empty($form['buyer_note'])): ?>
                <p style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);"><strong style="font-size:.875rem;">توضیح خریدار:</strong> <?= h($form['buyer_note']) ?></p>
              <?php endif; ?>
            </div>

            <div style="padding:14px 16px;border-radius:14px;background:#ECFDF5;border:1px solid #A7F3D0;margin-bottom:20px;">
              <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div>
                  <div style="font-size:.8125rem;color:#065F46;font-weight:700;"><i class="bi bi-shield-check"></i> پرداخت امن از طریق درگاه بانک سامان</div>
                  <div style="font-weight:800;color:#064E3B;">مبلغ کل: <?= fmt_credit((float)$totalAmount) ?></div>
                </div>
              </div>
            </div>

            <div class="chk-nav-btns">
              <button type="submit" name="nav" value="prev_step" class="btn btn-outline btn-lg">
                <i class="bi bi-arrow-right"></i> بازگشت
              </button>
              <button type="submit" name="nav" value="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-credit-card"></i> پرداخت و ثبت سفارش
              </button>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </form>
  </div>
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