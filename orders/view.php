<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = (int)$user['id'];
$orderId = (int)($_GET['id'] ?? 0);
$success = '';
$error   = '';

$order = $orderId ? fetch_store_order($orderId, $uid) : null;
if (!$order) {
    header('Location: ' . APP_URL . '/orders/');
    exit;
}

$isSeller = (int)$order['seller_id'] === $uid;
$isBuyer  = (int)$order['buyer_id'] === $uid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($isSeller && $action === 'ship') {
        $result = update_store_order_by_seller($orderId, $uid, 'ship', $_POST);
        if (isset($result['error'])) $error = $result['error'];
        else $success = 'اطلاعات ارسال ثبت شد.';
    } elseif ($isSeller && $action === 'deliver') {
        $result = update_store_order_by_seller($orderId, $uid, 'deliver');
        if (isset($result['error'])) $error = $result['error'];
        else $success = 'سفارش به‌عنوان تحویل‌شده ثبت شد.';
    } elseif ($isBuyer && $action === 'confirm_delivery') {
        $result = buyer_mark_order_delivered($orderId, $uid);
        if (isset($result['error'])) $error = $result['error'];
        else $success = 'تحویل سفارش تأیید شد. ممنون از خرید شما!';
    }

    $order = fetch_store_order($orderId, $uid);
}

$timeline = store_order_timeline($order);

render_head('پیگیری سفارش ' . h($order['order_code']));
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/orders.css?v=<?= @filemtime(__DIR__ . '/../src/css/orders.css') ?: time() ?>">

<div class="section-sm">
  <div class="container-md">
    <div class="order-page-head">
      <a href="<?= $isSeller ? APP_URL . '/store/?tab=orders' : APP_URL . '/orders/' ?>" class="order-back">
        <i class="bi bi-arrow-right"></i> <?= $isSeller ? 'بازگشت به پنل فروشگاه' : 'بازگشت به سفارش‌های من' ?>
      </a>
      <h1>پیگیری سفارش</h1>
      <div class="order-code-chip">کد سفارش: <?= h($order['order_code']) ?></div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div class="order-detail-grid">
      <div class="card">
        <div class="card-body">
          <div class="order-status-banner order-status-banner--<?= h($order['status']) ?>">
            <i class="bi bi-box-seam"></i>
            <?= h(store_order_status_label($order['status'])) ?>
          </div>

          <div class="order-product order-product--lg">
            <?php if (!empty($order['listing_thumb'])): ?>
            <img src="<?= UPLOAD_URL . h($order['listing_thumb']) ?>" alt="" class="order-product__thumb">
            <?php endif; ?>
            <div>
              <div class="order-product__title"><?= h($order['listing_title']) ?></div>
              <div class="order-product__store">
                <?= $isBuyer ? 'فروشگاه: ' . h($order['store_name'] ?: $order['seller_name']) : 'خریدار: ' . h($order['buyer_name']) ?>
              </div>
              <div class="order-product__price"><?= fmt_credit((float)$order['amount']) ?></div>
            </div>
          </div>

          <div class="order-info-block">
            <h3>آدرس ارسال</h3>
            <p><?= h($order['recipient_name']) ?> — <?= h($order['recipient_phone']) ?></p>
            <p><?= h($order['shipping_city']) ?><?= $order['postal_code'] ? ' — ' . h($order['postal_code']) : '' ?></p>
            <p><?= nl2br(h($order['shipping_address'])) ?></p>
            <?php if ($order['buyer_note']): ?>
            <p class="order-muted"><strong>توضیح خریدار:</strong> <?= h($order['buyer_note']) ?></p>
            <?php endif; ?>
          </div>

          <?php if ($order['shipping_method']): ?>
          <div class="order-info-block">
            <h3>اطلاعات ارسال</h3>
            <p>روش: <?= h(shipping_label($order['shipping_method'])) ?></p>
            <?php if ($order['tracking_code']): ?>
            <p>کد رهگیری: <strong dir="ltr"><?= h($order['tracking_code']) ?></strong></p>
            <?php endif; ?>
            <?php if ($order['shipped_at']): ?>
            <p class="order-muted">زمان ارسال: <?= persian_datetime($order['shipped_at']) ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h2 class="order-card-title"><i class="bi bi-signpost-split"></i> مراحل سفارش</h2>
          <ol class="order-timeline">
            <?php foreach ($timeline as $step): ?>
            <li class="order-timeline__item<?= !empty($step['done']) ? ' is-done' : '' ?><?= !empty($step['active']) ? ' is-active' : '' ?>">
              <div class="order-timeline__dot"></div>
              <div class="order-timeline__content">
                <div class="order-timeline__title"><?= h($step['title']) ?></div>
                <div class="order-timeline__desc"><?= h($step['desc']) ?></div>
                <?php if (!empty($step['time'])): ?>
                <div class="order-timeline__time"><?= persian_datetime($step['time']) ?></div>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ol>

          <?php if ($isSeller && in_array($order['status'], ['paid', 'preparing'], true)): ?>
          <form method="POST" class="order-action-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ship">
            <h3 class="order-form-title">ثبت ارسال</h3>
            <div class="form-group">
              <label class="form-label">روش ارسال</label>
              <select name="shipping_method" class="form-control" required>
                <option value="">انتخاب کنید...</option>
                <option value="post">پست</option>
                <option value="tipax">تیپاکس</option>
                <option value="courier">پیک</option>
                <option value="in_person">تحویل حضوری</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">کد رهگیری</label>
              <input type="text" name="tracking_code" class="form-control" dir="ltr" placeholder="برای تحویل حضوری اختیاری">
            </div>
            <div class="form-group">
              <label class="form-label">یادداشت برای خریدار (اختیاری)</label>
              <textarea name="seller_note" class="form-control" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-truck"></i> ثبت ارسال</button>
          </form>
          <?php elseif ($isSeller && $order['status'] === 'shipped'): ?>
          <form method="POST" class="order-action-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="deliver">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2-circle"></i> تأیید تحویل به خریدار</button>
          </form>
          <?php elseif ($isBuyer && $order['status'] === 'shipped'): ?>
          <form method="POST" class="order-action-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_delivery">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-hand-thumbs-up"></i> کالا را دریافت کردم</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
