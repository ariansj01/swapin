<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/dashboard_layout.php';
require_once __DIR__ . '/includes/sep_payment.php';

$user    = require_auth();
$success = '';
$error   = '';

$plans = DB::fetchAll('SELECT * FROM subscription_plans ORDER BY price_month ASC');
$activeSub = get_active_subscription($user);
$activeCount = (int)(DB::fetch('SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = "active"', [$user['id']])['c'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('subscription', 10, 3600);
    $plan   = clean($_POST['plan'] ?? '');
    $months = max(1, (int)($_POST['months'] ?? 1));
    $paymentMethod = clean($_POST['payment_method'] ?? 'wallet');

    $selectedPlan = null;
    foreach ($plans as $p) {
        if ($p['slug'] === $plan) {
            $selectedPlan = $p;
            break;
        }
    }

    if (!$selectedPlan) {
        $error = 'پلن انتخاب‌شده نامعتبر است';
    } else {
        $price = (int)($selectedPlan['price_month'] * $months);

        // SEP payment
        try {
            $resNum = SEPPayment::generateResNum();
            $meta = json_encode([
                'subscription_plan' => $plan,
                'months' => $months,
            ], JSON_UNESCAPED_UNICODE);
            
            DB::insert('payments', [
                'user_id' => $user['id'],
                'type' => 'subscription_purchase', // New type
                'amount' => $price,
                'res_num' => $resNum,
                'status' => 'pending',
                'meta' => $meta,
            ]);
            
            $redirectUrl = APP_URL . '/sep/callback';
            $tokenResult = SEPPayment::getToken($price, $resNum, $redirectUrl, $user['phone'] ?? null);
            
            if ($tokenResult && isset($tokenResult['token'])) {
                echo SEPPayment::getPaymentForm($tokenResult['token']);
                exit;
            } else {
                $error = 'خطا در اتصال به درگاه پرداخت';
            }
        } catch (Throwable $e) {
            $error = 'خطایی در فرآیند پرداخت: ' . $e->getMessage();
        }
    }
}
render_head('پلن‌های اشتراک');
render_panel_styles();
render_navbar($user);
?>

<?php render_user_panel_open($user, 'subscription'); ?>
  <div class="dash-panel">
    <?php render_panel_page_header('پلن‌های اشتراک', 'آگهی بیشتر، اعتبار بالا بردن و ابزارهای کسب‌وکار'); ?>
    <div class="dash-page-head__actions" style="justify-content:flex-end;margin-bottom:24px">
      <a href="<?= APP_URL ?>/dashboard" class="btn btn-outline btn-sm">
        <i class="bi bi-arrow-right"></i> بازگشت
      </a>
    </div>

    <?php if ($success): ?><div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div><?php endif; ?>

    <div class="alert alert-info mb-6">
      <i class="bi bi-info-circle"></i>
      <div>
        پلن فعلی: <strong><?= $activeSub ? h($activeSub['name']) : 'رایگان' ?></strong>
        — <?= fmt_num($activeCount) ?> / <?= fmt_num(get_listing_limit($user)) ?> آگهی فعال
        <?php if (!empty($user['subscription_until'])): ?>
        — انقضا: <?= persian_date($user['subscription_until']) ?>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:var(--sp-5);align-items:stretch">
      <?php foreach ($plans as $plan):
        $isCurrent = ($user['subscription_plan'] ?? 'none') === $plan['slug'] && $activeSub;
        $features = [
            fmt_num($plan['listings_max']) . ' آگهی فعال',
            fmt_num($plan['bump_credits']) . ' اعتبار بالا بردن رایگان/ماه',
        ];
        if ($plan['has_reports']) $features[] = 'گزارش فروش';
        if ($plan['has_panel'])   $features[] = 'پنل کسب‌وکار';
        if ($plan['has_api'])     $features[] = 'دستیار هوشمند';
      ?>
      <div class="card" style="display:flex;flex-direction:column;<?= $plan['slug'] === 'gold' ? 'border:2px solid var(--accent-dark)' : '' ?>">
        <div class="card-body" style="padding:var(--sp-6);flex:1;display:flex;flex-direction:column">
          <?php if ($plan['slug'] === 'gold'): ?>
          <span class="badge badge-warning mb-3"><i class="bi bi-star-fill"></i> محبوب</span>
          <?php endif; ?>
          <h3><?= h($plan['name']) ?></h3>
          <div style="font-size:2rem;font-weight:800;color:var(--primary);margin:var(--sp-3) 0">
            <?= fmt_num((float)$plan['price_month'], 0) ?> <span class="fs-sm fw-600" style="color:var(--text-muted)"><?= CREDIT_UNIT ?>/ماه</span>
          </div>
          <ul style="margin:var(--sp-4) 0 var(--sp-6);padding:0;flex:1">
            <?php foreach ($features as $f): ?>
            <li style="display:flex;align-items:center;gap:var(--sp-2);margin-bottom:var(--sp-2);font-size:.875rem;color:var(--text-secondary)">
              <i class="bi bi-check-circle-fill" style="color:var(--success)"></i> <?= h($f) ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($isCurrent): ?>
          <button class="btn btn-outline w-100" disabled>پلن فعلی</button>
          <?php else: ?>
          <form method="POST" style="margin-top:auto">
            <?= csrf_field() ?>
            <input type="hidden" name="plan" value="<?= h($plan['slug']) ?>">
            <div style="display:flex;gap:var(--sp-2);align-items:stretch">
              <select name="months" class="form-control" style="flex:1;margin:0">
                <?php foreach ([1,3,6,12] as $m): ?>
                <option value="<?= $m ?>"><?= fmt_num($m) ?> ماه — <?= fmt_credit((float)$plan['price_month'] * $m) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="payment_method" value="wallet" class="payment-method-input">
              <button type="submit" class="btn btn-primary" style="flex-shrink:0;white-space:nowrap">خرید اشتراک</button>
            </div>
          </form>

          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const paymentMethodRadios = document.querySelectorAll('input[name="payment_method_option"]');
  const allForms = document.querySelectorAll('.dash-panel form');

  function updatePaymentMethod() {
    const selectedValue = document.querySelector('input[name="payment_method_option"]:checked').value;
    allForms.forEach(form => {
      const hiddenInput = form.querySelector('.payment-method-input');
      if (hiddenInput) {
        hiddenInput.value = selectedValue;
      }
    });
  }

  paymentMethodRadios.forEach(radio => {
    radio.addEventListener('change', updatePaymentMethod);
  });

  // Initial update on load
  updatePaymentMethod();
});
</script>
