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
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/exchange-flow.css?v=<?= @filemtime(__DIR__ . '/../src/css/exchange-flow.css') ?: time() ?>">
<style>
  .ex-timeline { list-style: none; padding: 0; margin: 0; position: relative; }
  .ex-timeline::before { content: ''; position: absolute; right: 11px; top: 0; bottom: 0; width: 2px; background: var(--ex-border-soft); z-index: 0; }
  .ex-timeline-item { position: relative; padding-right: 36px; padding-bottom: 24px; z-index: 1; }
  .ex-timeline-item:last-child { padding-bottom: 0; }
  .ex-timeline-dot { position: absolute; right: 0; top: 0; width: 24px; height: 24px; border-radius: 50%; background: #fff; border: 2px solid var(--ex-border); display: flex; align-items: center; justify-content: center; font-size: 0.75rem; color: var(--ex-muted); transition: all 0.3s; }
  .ex-timeline-item.is-done .ex-timeline-dot { background: var(--ex-navy); border-color: var(--ex-navy); color: #fff; }
  .ex-timeline-item.is-active .ex-timeline-dot { border-color: var(--ex-gold); background: #fff; color: var(--ex-gold-dark); box-shadow: 0 0 0 4px rgba(245, 184, 0, 0.15); }
  .ex-timeline-content { padding-top: 2px; }
  .ex-timeline-title { font-size: 0.9375rem; font-weight: 800; color: var(--ex-navy); margin-bottom: 4px; }
  .ex-timeline-desc { font-size: 0.8125rem; color: var(--ex-muted); line-height: 1.5; }
  .ex-timeline-time { font-size: 0.75rem; color: var(--ex-muted); margin-top: 6px; opacity: 0.8; }
  
  .ex-order-summary-box { background: var(--ex-bg); border-radius: var(--ex-radius-md); padding: 16px; border: 1px solid var(--ex-border-soft); }
  .ex-order-summary-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 0.875rem; }
  .ex-order-summary-row.total { border-top: 1px dashed var(--ex-border); margin-top: 8px; padding-top: 12px; font-weight: 900; color: var(--ex-navy); font-size: 1.125rem; }
</style>

<div class="ex-page">
  <div class="ex-container">
    <div class="ex-header">
      <a href="<?= $isSeller ? APP_URL . '/store/?tab=orders' : APP_URL . '/orders/' ?>" class="ex-header__back">
        <i class="bi bi-arrow-right"></i> <?= $isSeller ? 'بازگشت به پنل فروشگاه' : 'بازگشت به سفارش‌های من' ?>
      </a>
      <div class="ex-header__row">
        <div>
          <h1 class="ex-header__title">جزئیات سفارش</h1>
          <p class="ex-header__subtitle">مشاهده وضعیت و پیگیری مراحل ارسال سفارش.</p>
        </div>
        <div class="ex-header__status ex-status--info">
          <span class="ex-status__dot"></span>
          کد سفارش: <?= h($order['order_code']) ?>
        </div>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="ex-alert ex-alert--success">
        <i class="bi bi-check-circle ex-alert__icon"></i>
        <div><?= h($success) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="ex-alert ex-alert--danger">
        <i class="bi bi-exclamation-circle ex-alert__icon"></i>
        <div><?= h($error) ?></div>
      </div>
    <?php endif; ?>

    <!-- Main Order Info -->
    <div class="ex-card">
      <div class="ex-card__label"><i class="bi bi-box-seam"></i> وضعیت سفارش</div>
      <div class="ex-hero" style="padding: 16px 0 32px;">
        <?php
          $statusIcon = 'box-seam';
          $statusHeroClass = 'ex-hero__icon--info';
          if (in_array($order['status'], ['paid', 'preparing', 'shipped'])) { $statusIcon = 'truck'; $statusHeroClass = 'ex-hero__icon--pending'; }
          if (in_array($order['status'], ['delivered', 'completed'])) { $statusIcon = 'check-lg'; $statusHeroClass = 'ex-hero__icon--success'; }
          if ($order['status'] === 'canceled') { $statusIcon = 'x-lg'; $statusHeroClass = 'ex-hero__icon--danger'; }
        ?>
        <div class="ex-hero__icon <?= $statusHeroClass ?>">
          <i class="bi bi-<?= $statusIcon ?>"></i>
        </div>
        <h2 class="ex-hero__title"><?= h(store_order_status_label($order['status'])) ?></h2>
        <p class="ex-hero__subtitle">سفارش شما در مرحله <?= mb_strtolower(h(store_order_status_label($order['status']))) ?> قرار دارد.</p>
      </div>

      <div class="ex-card__divider"></div>

      <div class="ex-product ex-product--lg">
        <?php if (!empty($order['listing_thumb'])): ?>
          <img src="<?= UPLOAD_URL . h($order['listing_thumb']) ?>" alt="" class="ex-product__thumb" style="width: 80px; height: 80px;">
        <?php else: ?>
          <div class="ex-product__thumb ex-product__thumb--empty" style="width: 80px; height: 80px;"><i class="bi bi-image"></i></div>
        <?php endif; ?>
        <div class="ex-product__info">
          <h3 class="ex-product__title"><?= h($order['listing_title']) ?></h3>
          <div class="ex-product__meta">
            <span><i class="bi bi-shop"></i> <?= $isBuyer ? h($order['store_name'] ?: $order['seller_name']) : 'خریدار: ' . h($order['buyer_name']) ?></span>
          </div>
        </div>
      </div>

      <div class="ex-order-summary-box ex-mt-4">
        <?php
          $itemPrice   = (float)$order['amount'];
          $shipCost    = (float)($order['shipping_cost'] ?? 0);
          $orderTotal  = $itemPrice + $shipCost;
        ?>
        <div class="ex-order-summary-row">
          <span>قیمت کالا</span>
          <strong><?= fmt_credit($itemPrice) ?></strong>
        </div>
        <div class="ex-order-summary-row">
          <span>هزینه ارسال</span>
          <strong><?= $shipCost > 0 ? fmt_credit($shipCost) : '<span class="ex-chip ex-chip--success">رایگان</span>' ?></strong>
        </div>
        <div class="ex-order-summary-row total">
          <span>مبلغ کل پرداخت‌شده</span>
          <strong><?= fmt_credit($orderTotal) ?></strong>
        </div>
      </div>
    </div>

    <!-- Timeline & Details -->
    <div class="ex-card">
      <div class="ex-card__label"><i class="bi bi-signpost-split"></i> مراحل سفارش</div>
      <ul class="ex-timeline">
        <?php foreach ($timeline as $step): ?>
          <li class="ex-timeline-item <?= !empty($step['done']) ? 'is-done' : '' ?> <?= !empty($step['active']) ? 'is-active' : '' ?>">
            <div class="ex-timeline-dot">
              <?php if (!empty($step['done'])): ?><i class="bi bi-check-lg"></i><?php endif; ?>
            </div>
            <div class="ex-timeline-content">
              <div class="ex-timeline-title"><?= h($step['title']) ?></div>
              <div class="ex-timeline-desc"><?= h($step['desc']) ?></div>
              <?php if (!empty($step['time'])): ?>
                <div class="ex-timeline-time"><i class="bi bi-clock"></i> <?= persian_datetime($step['time']) ?></div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- Shipping Info -->
    <div class="ex-card">
      <div class="ex-card__label"><i class="bi bi-geo-alt"></i> اطلاعات ارسال</div>
      <div class="ex-cash-box" style="margin-top: 0;">
        <div style="font-weight: 800; font-size: 1rem; margin-bottom: 4px;"><?= h($order['recipient_name']) ?></div>
        <div style="color: var(--ex-muted); font-size: 0.875rem; margin-bottom: 8px;"><i class="bi bi-phone"></i> <?= h($order['recipient_phone']) ?></div>
        <div style="line-height: 1.6; font-size: 0.9375rem;">
          <?= !empty($order['shipping_province']) ? h($order['shipping_province']) . '، ' : '' ?>
          <?= h($order['shipping_city']) ?>
          <?= $order['postal_code'] ? ' — کد پستی: ' . h($order['postal_code']) : '' ?>
          <br><?= nl2br(h($order['shipping_address'])) ?>
        </div>
        
        <?php if ($order['shipping_method']): ?>
          <div class="ex-card__divider"></div>
          <div style="display: flex; gap: 16px; flex-wrap: wrap;">
            <div>
              <span style="font-size: 0.75rem; font-weight: 700; color: var(--ex-muted); display: block; margin-bottom: 4px;">روش ارسال:</span>
              <span class="ex-chip ex-chip--navy"><?= h(shipping_label($order['shipping_method'])) ?></span>
            </div>
            <?php if ($order['tracking_code']): ?>
              <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: var(--ex-muted); display: block; margin-bottom: 4px;">کد رهگیری:</span>
                <strong dir="ltr" style="font-size: 0.9375rem;"><?= h($order['tracking_code']) ?></strong>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($order['buyer_note']): ?>
          <div style="margin-top: 12px; padding: 10px; background: #fff; border-radius: 8px; border: 1px dashed var(--ex-border);">
            <span style="font-size: 0.75rem; font-weight: 700; color: var(--ex-muted); display: block; margin-bottom: 4px;">توضیحات خریدار:</span>
            <div style="font-size: 0.875rem;"><?= nl2br(h($order['buyer_note'])) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Actions -->
    <?php if ($isSeller && in_array($order['status'], ['paid', 'preparing'], true)): ?>
      <div class="ex-card">
        <div class="ex-card__label"><i class="bi bi-truck"></i> ثبت اطلاعات ارسال</div>
        <form method="POST" class="ex-mt-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="ship">
          <div class="ex-form-group">
            <label class="ex-form-label">روش ارسال</label>
            <select name="shipping_method" class="form-control" required>
              <option value="">انتخاب کنید...</option>
              <option value="post">پست</option>
              <option value="tipax">تیپاکس</option>
              <option value="courier">پیک</option>
              <option value="in_person">تحویل حضوری</option>
            </select>
          </div>
          <div class="ex-form-group">
            <label class="ex-form-label">کد رهگیری پستی</label>
            <input type="text" name="tracking_code" class="form-control" dir="ltr" placeholder="برای تحویل حضوری اختیاری است">
          </div>
          <div class="ex-form-group">
            <label class="ex-form-label">یادداشت برای خریدار (اختیاری)</label>
            <textarea name="seller_note" class="form-control" rows="2" placeholder="مثلاً: بسته تحویل مامور پست شد."></textarea>
          </div>
          <button type="submit" class="ex-btn ex-btn--primary">ثبت و اعلام ارسال به خریدار</button>
        </form>
      </div>
    <?php elseif ($isSeller && $order['status'] === 'shipped'): ?>
      <div class="ex-card">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="deliver">
          <button type="submit" class="ex-btn ex-btn--primary"><i class="bi bi-check2-circle"></i> تأیید تحویل نهایی به خریدار</button>
        </form>
      </div>
    <?php elseif ($isBuyer && $order['status'] === 'shipped'): ?>
      <div class="ex-card">
        <div class="ex-alert ex-alert--info">
          <i class="bi bi-info-circle ex-alert__icon"></i>
          <div>اگر کالا را صحیح و سالم دریافت کرده‌اید، لطفاً تحویل آن را تأیید کنید تا تسویه با فروشنده انجام شود.</div>
        </div>
        <form method="POST" class="ex-mt-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="confirm_delivery">
          <button type="submit" class="ex-btn ex-btn--primary"><i class="bi bi-hand-thumbs-up"></i> کالا را دریافت کردم (تأیید تحویل)</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
