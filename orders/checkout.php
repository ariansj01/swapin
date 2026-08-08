<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = (int)$user['id'];
$listingId = (int)($_GET['listing_id'] ?? $_POST['listing_id'] ?? 0);
$error = '';

$listing = $listingId ? DB::fetch(
    'SELECT l.*, u.name AS seller_name, u.store_name, u.store_slug, u.seller_type,
            (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS thumb
     FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?',
    [$listingId]
) : null;

if (!$listing || !listing_can_cash_buy($listing, $user)) {
    header('Location: ' . APP_URL . '/listings/view?id=' . $listingId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('store_checkout', 10, 900);
    $result = create_store_order_checkout($listingId, $uid, $_POST);
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        echo SEPPayment::getPaymentForm($result['token']);
        exit;
    }
}

$defaults = [
    'recipient_name'  => trim((string)($user['name'] ?? '')),
    'recipient_phone' => preg_replace('/^\+98/', '0', (string)($user['phone'] ?? '')),
    'shipping_city'   => trim((string)($user['city'] ?? '')),
];

render_head('تسویه و پرداخت | ' . h($listing['title']));
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/orders.css?v=<?= @filemtime(__DIR__ . '/../src/css/orders.css') ?: time() ?>">

<div class="section-sm">
  <div class="container-md">
    <div class="order-page-head">
      <a href="<?= APP_URL ?>/listings/view?id=<?= $listingId ?>" class="order-back"><i class="bi bi-arrow-right"></i> بازگشت به محصول</a>
      <h1>تسویه نقدی و ثبت آدرس ارسال</h1>
      <p class="order-page-sub">پس از پرداخت، فروشگاه سفارش را آماده و ارسال می‌کند و وضعیت مرحله‌به‌مرحله نمایش داده می‌شود.</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div class="order-checkout-grid">
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
              <div class="order-product__price"><?= fmt_credit((float)$listing['sell_price']) ?></div>
            </div>
          </div>
          <div class="order-summary-row">
            <span>مبلغ قابل پرداخت</span>
            <strong><?= fmt_credit((float)$listing['sell_price']) ?></strong>
          </div>
        </div>
      </div>

      <form method="POST" class="card order-shipping-card">
        <div class="card-body">
          <?= csrf_field() ?>
          <input type="hidden" name="listing_id" value="<?= $listingId ?>">
          <h2 class="order-card-title"><i class="bi bi-truck"></i> اطلاعات ارسال</h2>

          <div class="form-group">
            <label class="form-label">نام و نام خانوادگی گیرنده</label>
            <input type="text" name="recipient_name" class="form-control" required maxlength="120"
                   value="<?= h($_POST['recipient_name'] ?? $defaults['recipient_name']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">شماره موبایل گیرنده</label>
            <input type="tel" name="recipient_phone" class="form-control" required dir="ltr"
                   placeholder="09123456789" value="<?= h($_POST['recipient_phone'] ?? $defaults['recipient_phone']) ?>">
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">شهر</label>
              <input type="text" name="shipping_city" class="form-control" required
                     value="<?= h($_POST['shipping_city'] ?? $defaults['shipping_city']) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">کد پستی</label>
              <input type="text" name="postal_code" class="form-control" dir="ltr"
                     value="<?= h($_POST['postal_code'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">آدرس کامل</label>
            <textarea name="shipping_address" class="form-control" rows="3" required placeholder="خیابان، پلاک، واحد..."><?= h($_POST['shipping_address'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">توضیحات (اختیاری)</label>
            <textarea name="buyer_note" class="form-control" rows="2" placeholder="مثلاً زمان مناسب تحویل"><?= h($_POST['buyer_note'] ?? '') ?></textarea>
          </div>

          <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="bi bi-credit-card"></i> پرداخت و ثبت سفارش
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php render_footer(); ?>
