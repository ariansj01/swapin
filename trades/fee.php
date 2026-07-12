<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid = $user['id'];

$offerId = (int)($_GET['offer'] ?? 0);
$error = '';

// Fetch offer
$offer = DB::fetch(
    'SELECT o.*, l.user_id AS listing_owner, l.title AS listing_title, l.estimated_value AS listing_a_value,
            ol.estimated_value AS listing_b_value
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     LEFT JOIN listings ol ON ol.id = o.offer_listing_id
     WHERE o.id = ? AND l.user_id = ? AND o.status = "pending"',
    [$offerId, $uid]
);

if (!$offer) {
    header('Location: ' . APP_URL . '/listings/offers.php');
    exit;
}

// Calculate platform fees (1% of each listing's value)
$valueA = (float)($offer['listing_a_value'] ?? 0);
$valueB = (float)($offer['listing_b_value'] ?? 0);
$feeA = $valueA * PLATFORM_FEE_RATE;
$feeB = $valueB * PLATFORM_FEE_RATE;

// Calculate credit difference
$creditDiff = $valueA - ($valueB + (float)$offer['offer_credit']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    
    // Check if both users have enough balance for fees
    $userA = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$uid]);
    $userB = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$offer['from_user_id']]);
    
    $userABalance = (float)($userA['credit_balance'] ?? 0);
    $userBBalance = (float)($userB['credit_balance'] ?? 0);
    
    if ($userABalance < $feeA) {
        $required = $feeA - $userABalance;
        $error = 'موجودی کیف پول شما برای پرداخت کارمزد کافی نیست. لطفاً ' . fmt_credit($required) . ' به کیف پول خود اضافه کنید.';
    } elseif ($userBBalance < $feeB) {
        $error = 'موجودی کیف پول طرف مقابل برای پرداخت کارمزد کافی نیست.';
    } else {
        // Okay, proceed with creating trade
        DB::query('UPDATE trade_offers SET status = "accepted" WHERE id = ?', [$offerId]);
        
        $tradeId = DB::insert('trades', [
            'offer_id'     => $offerId,
            'user_a_id'    => $uid,
            'user_b_id'    => $offer['from_user_id'],
            'listing_a_id' => $offer['listing_id'],
            'listing_b_id' => $offer['offer_listing_id'] ?: null,
            'credit_diff'  => $creditDiff,
            'status'       => 'in_progress',
            'step'         => 1,
            'fee_paid'     => 0,
        ]);
        
        // Deduct platform fees
        credit_transact($uid, 'fee', -$feeA, 'کارمزد پلتفرم برای معامله #' . $tradeId, [
            'ref_type' => 'trade',
            'ref_id' => $tradeId,
            'trade_id' => $tradeId,
        ]);
        credit_transact($offer['from_user_id'], 'fee', -$feeB, 'کارمزد پلتفرم برای معامله #' . $tradeId, [
            'ref_type' => 'trade',
            'ref_id' => $tradeId,
            'trade_id' => $tradeId,
        ]);
        
        // Update trade to mark fee as paid
        DB::query('UPDATE trades SET fee_paid = 1, step = 2 WHERE id = ?', [$tradeId]);
        
        // Handle credit difference
        if ($creditDiff > 0) {
            $userToPayId = (int)$offer['from_user_id'];
            $amountToPay = $creditDiff;
        } elseif ($creditDiff < 0) {
            $userToPayId = $uid;
            $amountToPay = abs($creditDiff);
        } else {
            $userToPayId = 0;
            $amountToPay = 0;
        }
        
        if ($userToPayId && $amountToPay > 0) {
            $payerUser = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userToPayId]);
            if ((float)$payerUser['credit_balance'] < $amountToPay) {
                // We'll handle this on the trade page
            } else {
                escrow_hold($tradeId, $userToPayId, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
                DB::query('UPDATE trades SET diff_paid = 1, step = 3 WHERE id = ?', [$tradeId]);
            }
        } else {
            DB::query('UPDATE trades SET diff_paid = 1, step = 3 WHERE id = ?', [$tradeId]);
        }
        
        create_trade_contract($tradeId);
        
        // Send message
        $message = $_SESSION['offer_accept_message'] ?? '';
        unset($_SESSION['offer_accept_message']);
        if ($message) {
            DB::insert('messages', [
                'thread_id'    => 'trade_' . $tradeId,
                'from_user_id' => $uid,
                'to_user_id'   => $offer['from_user_id'],
                'offer_id'     => $offerId,
                'body'         => $message,
            ]);
        }
        
        header('Location: ' . APP_URL . '/trades/view.php?id=' . $tradeId . '&accepted=1');
        exit;
    }
}

render_head('پرداخت کارمزد');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">
    <div class="mb-6">
      <a href="<?= APP_URL ?>/listings/offers.php" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به پیشنهادها
      </a>
      <h2 class="mt-3">پرداخت کارمزد و تایید معامله</h2>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>
    
    <div class="card mb-5">
      <div class="card-body">
        <h4 style="margin-bottom:var(--sp-4)">جزئیات معامله</h4>
        
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:var(--sp-4)">
          <div>
            <div style="font-weight:600;margin-bottom:var(--sp-2)">آگهی شما:</div>
            <div><?= h($offer['listing_title']) ?></div>
            <div class="fs-sm" style="color:var(--text-muted)">ارزش تقریبی: <?= fmt_credit($valueA) ?></div>
            <div style="margin-top:var(--sp-2);color:var(--primary);font-weight:600">کارمزد شما: <?= fmt_credit($feeA) ?></div>
          </div>
          
          <?php if ($offer['offer_listing_id']): ?>
          <div>
            <div style="font-weight:600;margin-bottom:var(--sp-2)">آگهی طرف مقابل:</div>
            <div><?= h(DB::fetch('SELECT title FROM listings WHERE id = ?', [$offer['offer_listing_id']])['title'] ?? '') ?></div>
            <div class="fs-sm" style="color:var(--text-muted)">ارزش تقریبی: <?= fmt_credit($valueB) ?></div>
          <?php endif; ?>
          
          <?php if ((float)$offer['offer_credit'] > 0): ?>
          <div>
            <div style="font-weight:600;margin-bottom:var(--sp-2)">اعتبار پیشنهادی:</div>
            <div style="color:var(--primary);font-weight:700"><?= fmt_credit((float)$offer['offer_credit']) ?></div>
          </div>
          <?php endif; ?>
          
          <div>
            <div style="font-weight:600;margin-bottom:var(--sp-2)">مابه‌التفاوت:</div>
            <div style="font-weight:700;color:<?= $creditDiff > 0 ? 'var(--accent)' : ($creditDiff < 0 ? 'var(--primary)' : 'var(--text-secondary)') ?>">
              <?= $creditDiff > 0 ? 'شما دریافت می‌کنید ' : ($creditDiff < 0 ? 'شما باید پرداخت کنید ' : '') ?><?= fmt_credit(abs($creditDiff)) ?>
            </div>
          </div>
        </div>
        
        <hr style="margin:var(--sp-5) 0">
        
        <div class="alert alert-info mb-5">
          <i class="bi bi-info-circle"></i>
          <div>
            <strong>چرا کارمزد می‌پردازیم؟</strong><br>
            کارمزد پلتفرم برای پشتیبانی از سیستم‌های امنیتی، حل اختلافات، و ارائه خدمات به شما استفاده می‌شود.
          </div>
        </div>
        
        <div class="alert alert-success mb-5">
          <i class="bi bi-shield-check"></i>
          <div>
            <strong>مزایای پرداخت کارمزد:</strong><br>
            - حمایت از حقوق شما در صورت بروز مشکل<br>
            - دسترسی به سیستم حل اختلافات<br>
            - پشتیبانی ۲۴ ساعته
          </div>
        </div>
        
        <form method="POST">
          <?= csrf_field() ?>
          <div style="display:flex;gap:var(--sp-3);justify-content:flex-end">
            <a href="<?= APP_URL ?>/listings/offers.php" class="btn btn-ghost">انصراف</a>
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="bi bi-check-circle"></i> تایید و پرداخت کارمزد
            </button>
          </div>
        </form>
      </div>
    </div>
    
    <div class="card">
      <div class="card-body">
        <h4>قوانین معامله</h4>
        <ul style="margin-top:var(--sp-3);color:var(--text-secondary);line-height:1.8">
          <li>کارمزد پس از تایید معامله کسر می‌شود و در صورت لغو معامله بازگردانده می‌شود.</li>
          <li>مابه‌التفاوت تا تایید دریافت کالا در امانت نگهداری می‌شود.</li>
          <li>هر دو طرف باید کالا را در زمان مقرر ارسال کنند.</li>
          <li>در صورت بروز مشکل، می‌توانید از طریق پشتیبانی درخواست کمک کنید.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
