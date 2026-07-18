<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/sep_payment.php';

// Get parameters from callback (POST or GET)
$params = array_merge($_POST, $_GET);
$refNum = $params['RefNum'] ?? null;
$resNum = $params['ResNum'] ?? null;
$state = $params['State'] ?? null;
$status = $params['Status'] ?? null;
$traceNo = $params['TraceNo'] ?? null;
$mid = $params['MID'] ?? null;

$error = null;
$success = false;
$payment = null;

try {
    // Find payment by res_num
    if ($resNum) {
        $payment = DB::fetch('SELECT * FROM payments WHERE res_num = ? LIMIT 1', [$resNum]);
    }

    if (!$payment) {
        throw new Exception('پرداخت یافت نشد');
    }

    // Verify transaction with SEP if state is OK
    if ($state === 'OK' && $refNum) {
        $verifyResult = SEPPayment::verifyTransaction($refNum);
        
        if ($verifyResult && $verifyResult['success']) {
            // Check amount matches
            $txnAmount = (int)($verifyResult['data']['OrginalAmount'] ?? $verifyResult['data']['amount'] ?? 0);
            if ($txnAmount !== (int)$payment['amount']) {
                throw new Exception('مبلغ پرداخت شده با مبلغ درخواستی مطابقت ندارد');
            }

            // Mark payment as success
            DB::update('payments', [
                'status' => 'success',
                'ref_num' => $refNum,
                'trace_no' => $traceNo,
                'state' => $state,
                'meta' => json_encode($verifyResult['data'], JSON_UNESCAPED_UNICODE),
            ], 'id = ?', [$payment['id']]);

            // Process payment based on type
            if ($payment['type'] === 'wallet_topup') {
                // Add to user wallet
                credit_transact(
                    $payment['user_id'],
                    'deposit',
                    $payment['amount'],
                    'شارژ کیف پول via درگاه بانک سامان',
                    ['ref_type' => 'external', 'ref_id' => $refNum]
                );
                $success = true;
            } elseif ($payment['type'] === 'listing_promotion') {
                // Process listing promotion
                $meta = json_decode($payment['meta'], true) ?: [];
                if (isset($meta['listing_id'], $meta['plan'], $meta['duration_hours'])) {
                    $listingId = $meta['listing_id'];
                    $plan = $meta['plan'];
                    $durationHours = $meta['duration_hours'];
                    $planData = $GLOBALS['plans'][$plan] ?? null;

                    if ($planData) {
                        require_once __DIR__ . '/listings/promote.php'; // to get $plans variable
                        $plans = $GLOBALS['plans'];
                        
                        $endsAt = date('Y-m-d H:i:s', time() + $durationHours * 3600);
                        $amountPaid = $payment['amount'];

                        // Insert promotion
                        DB::insert('listing_promotions', [
                            'listing_id' => $listingId,
                            'user_id' => $payment['user_id'],
                            'plan' => $plan,
                            'starts_at' => date('Y-m-d H:i:s'),
                            'ends_at' => $endsAt,
                            'amount_paid' => $amountPaid,
                        ]);

                        // Update listing
                        $updateData = [
                            'bump_until' => $endsAt,
                            'featured_until' => $endsAt,
                            'is_featured' => 1,
                            'vip_until' => $endsAt,
                        ];

                        if ($plan === 'targeted' || $plan === 'gold') {
                            $updateData['targeted_until'] = $endsAt;
                        }
                        if ($plan === 'ai' || $plan === 'gold') {
                            $updateData['ai_promo_until'] = $endsAt;
                        }

                        DB::update('listings', $updateData, 'id = ?', [$listingId]);
                        $success = true;
                    }
                }
            }
        } else {
            throw new Exception('تایید پرداخت ناموفق بود');
        }
    } else {
        // Payment failed or canceled
        DB::update('payments', [
            'status' => $state === 'CanceledByUser' ? 'canceled' : 'failed',
            'ref_num' => $refNum,
            'trace_no' => $traceNo,
            'state' => $state,
        ], 'id = ?', [$payment['id']]);
        $error = 'پرداخت ناموفق بود یا توسط کاربر لغو شد';
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
    swapin_debug_log('payment_callback_error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

render_head('نتیجه پرداخت');
render_navbar();
?>

<div class="container" style="padding: 60px 20px; max-width: 600px; margin: 0 auto;">
    <?php if ($success): ?>
        <div class="card" style="text-align: center; padding: 40px;">
            <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
            <h2 style="margin-bottom: 16px; color: #10b981;">پرداخت موفق</h2>
            <p style="color: var(--text-muted); margin-bottom: 24px;">
                <?= $payment['type'] === 'wallet_topup' ? 'کیف پول شما با موفقیت شارژ شد' : 'ارتقای آگهی شما با موفقیت انجام شد' ?>
            </p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <?php if ($payment['type'] === 'wallet_topup'): ?>
                    <a href="<?= APP_URL ?>/wallet.php" class="btn btn-primary">مشاهده کیف پول</a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/listings/my.php" class="btn btn-primary">مشاهده آگهی‌ها</a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/" class="btn btn-outline">صفحه اصلی</a>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="text-align: center; padding: 40px;">
            <div style="font-size: 64px; margin-bottom: 20px;">❌</div>
            <h2 style="margin-bottom: 16px; color: #ef4444;">پرداخت ناموفق</h2>
            <p style="color: var(--text-muted); margin-bottom: 24px;"><?= h($error ?: 'خطایی در فرآیند پرداخت رخ داد') ?></p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="<?= APP_URL ?>/wallet.php" class="btn btn-primary">تلاش مجدد</a>
                <a href="<?= APP_URL ?>/" class="btn btn-outline">صفحه اصلی</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
