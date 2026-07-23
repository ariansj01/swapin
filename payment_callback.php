<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/sep_payment.php';
require_once __DIR__ . '/includes/promotion_service.php';
require_once __DIR__ . '/includes/v2.php';

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
    if (!$resNum) {
        $error = 'پارامترهای لازم برای پردازش پرداخت ارائه نشده است';
        swapin_debug_log('payment_callback_missing_params', []);
    } else {
        $payment = DB::fetch('SELECT * FROM payments WHERE res_num = ? LIMIT 1', [$resNum]);

        if (!$payment) {
            $error = 'پرداخت یافت نشد';
            swapin_debug_log('payment_callback_payment_not_found', ['res_num' => $resNum]);
        }
    }

    if (!$payment) {
        // If no payment, just render error without heavy exception log
        // Continue to render the error page
    } else {

    // Verify transaction with SEP if state is OK
    if ($state === 'OK' && $refNum) {
        if ($payment['status'] === 'success') {
            $success = true;
            swapin_debug_log('payment_callback_duplicate_ignored', [
                'payment_id' => (int)$payment['id'],
                'res_num' => $resNum,
                'ref_num' => $refNum,
            ]);
        } else {
        $verifyResult = SEPPayment::verifyTransaction($refNum);
        if (!$verifyResult || empty($verifyResult['success'])) {
            DB::update('payments', [
                'status' => 'failed',
                'ref_num' => $refNum,
                'trace_no' => $traceNo,
                'state' => $state,
                'last_error' => 'تایید پرداخت ناموفق بود',
            ], 'id = ?', [$payment['id']]);
            throw new Exception('تایید پرداخت ناموفق بود');
        }

        // Check amount matches
        $txnAmount = (int)($verifyResult['data']['OrginalAmount'] ?? $verifyResult['data']['amount'] ?? 0);
        if ($txnAmount !== (int)$payment['amount']) {
            DB::update('payments', [
                'status' => 'processing_failed',
                'ref_num' => $refNum,
                'trace_no' => $traceNo,
                'state' => $state,
                'last_error' => 'مبلغ پرداخت شده با مبلغ درخواستی مطابقت ندارد',
            ], 'id = ?', [$payment['id']]);
            throw new Exception('مبلغ پرداخت شده با مبلغ درخواستی مطابقت ندارد');
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $payment = DB::fetch('SELECT * FROM payments WHERE id = ? LIMIT 1 FOR UPDATE', [$payment['id']]);
            if (!$payment) {
                throw new Exception('پرداخت یافت نشد');
            }
            if ($payment['status'] === 'success') {
                $pdo->commit();
                $success = true;
                swapin_debug_log('payment_callback_duplicate_locked', [
                    'payment_id' => (int)$payment['id'],
                    'res_num' => $resNum,
                    'ref_num' => $refNum,
                ]);
            } else {
                $meta = json_decode($payment['meta'] ?? '', true) ?: [];

                if ($payment['type'] === 'wallet_topup') {
                    credit_transact(
                        (int)$payment['user_id'],
                        'deposit',
                        (float)$payment['amount'],
                        'شارژ کیف پول via درگاه بانک سامان',
                        [
                            'ref_type' => 'payment',
                            'ref_id' => (int)$payment['id'],
                            'payment_id' => (int)$payment['id'],
                            'bank_ref_num' => $refNum,
                        ]
                    );
                } elseif ($payment['type'] === 'listing_promotion') {
                    if (!isset($meta['listing_id'], $meta['plan'], $meta['duration_hours'])) {
                        throw new Exception('اطلاعات ارتقای آگهی ناقص است');
                    }
                    apply_listing_promotion(
                        (int)$meta['listing_id'],
                        (int)$payment['user_id'],
                        (string)$meta['plan'],
                        (int)$meta['duration_hours'],
                        (float)$payment['amount']
                    );
                } elseif ($payment['type'] === 'subscription_purchase') {
                    if (!isset($meta['subscription_plan'], $meta['months'])) {
                        throw new Exception('اطلاعات اشتراک ناقص است');
                    }
                    $result = subscribe_to_plan(
                        (int)$payment['user_id'],
                        (string)$meta['subscription_plan'],
                        (int)$meta['months'],
                        true
                    );
                    if (empty($result['success'])) {
                        throw new Exception($result['error'] ?? 'خطا در فعال‌سازی اشتراک');
                    }
                } else {
                    throw new Exception('نوع پرداخت نامعتبر');
                }

                $paymentMeta = [
                    'callback' => [
                        'status' => $status,
                        'mid' => $mid,
                    ],
                    'verify' => $verifyResult['data'] ?? [],
                ];
                DB::update('payments', [
                    'status' => 'success',
                    'ref_num' => $refNum,
                    'trace_no' => $traceNo,
                    'state' => $state,
                    'meta' => json_encode($paymentMeta, JSON_UNESCAPED_UNICODE),
                    'processed_at' => date('Y-m-d H:i:s'),
                    'last_error' => null,
                ], 'id = ?', [$payment['id']]);
                $pdo->commit();
                $success = true;
                $payment = DB::fetch('SELECT * FROM payments WHERE id = ? LIMIT 1', [$payment['id']]);
            }
        } catch (Throwable $txError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            DB::update('payments', [
                'status' => 'processing_failed',
                'ref_num' => $refNum,
                'trace_no' => $traceNo,
                'state' => $state,
                'last_error' => $txError->getMessage(),
            ], 'id = ?', [$payment['id']]);
            swapin_debug_log('payment_callback_processing_failed', [
                'payment_id' => (int)$payment['id'],
                'type' => $payment['type'],
                'message' => $txError->getMessage(),
            ]);
            throw $txError;
        }
        }
    } else {
        // Payment failed or canceled
        DB::update('payments', [
            'status' => $state === 'CanceledByUser' ? 'canceled' : 'failed',
            'ref_num' => $refNum,
            'trace_no' => $traceNo,
            'state' => $state,
            'last_error' => 'پرداخت ناموفق بود یا توسط کاربر لغو شد',
        ], 'id = ?', [$payment['id']]);
        $error = 'پرداخت ناموفق بود یا توسط کاربر لغو شد';
    }
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
                <?php if (($payment['type'] ?? '') === 'wallet_topup'): ?>
                    کیف پول شما با موفقیت شارژ شد
                <?php elseif (($payment['type'] ?? '') === 'subscription_purchase'): ?>
                    اشتراک شما با موفقیت فعال شد
                <?php else: ?>
                    ارتقای آگهی شما با موفقیت انجام شد
                <?php endif; ?>
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
