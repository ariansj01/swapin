<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = $user['id'];

$tradeId = (int)($_GET['id'] ?? 0);
if (!$tradeId) {
    header('Location: ' . APP_URL . '/trades.php');
    exit;
}

// Fetch trade
$trade = DB::fetch(
    'SELECT t.*, ua.name AS user_a_name, ub.name AS user_b_name,
            la.title AS listing_a_title, lb.title AS listing_b_title
     FROM trades t
     JOIN users ua ON ua.id = t.user_a_id
     JOIN users ub ON ub.id = t.user_b_id
     JOIN listings la ON la.id = t.listing_a_id
     LEFT JOIN listings lb ON lb.id = t.listing_b_id
     WHERE t.id = ? AND (t.user_a_id = ? OR t.user_b_id = ?)',
    [$tradeId, $uid, $uid]
);

if (!$trade) {
    header('Location: ' . APP_URL . '/trades.php');
    exit;
}

$isA = (int)$trade['user_a_id'] === $uid;
$otherName = $isA ? $trade['user_b_name'] : $trade['user_a_name'];
$otherId = $isA ? $trade['user_b_id'] : $trade['user_a_id'];

// Calculate who owes what
$creditDiff = (float)($trade['credit_diff'] ?? 0);

if ($creditDiff > 0) {
    $userToPayId = $trade['user_b_id'];
    $amountToPay = $creditDiff;
} elseif ($creditDiff < 0) {
    $userToPayId = $trade['user_a_id'];
    $amountToPay = abs($creditDiff);
} else {
    $userToPayId = 0;
    $amountToPay = 0;
}

$iOwe = (int)$userToPayId === $uid;

// Get contract
$contract = get_trade_contract($tradeId);
if (!$contract) {
    create_trade_contract($tradeId);
    $contract = get_trade_contract($tradeId);
}

$bothPaid = $trade['fee_paid'] && $trade['diff_paid'];
$myReview = DB::fetch(
    'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
    [$tradeId, $uid]
);

// Handle ALL actions here
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($action === 'send_message') {
        $body = clean($_POST['body'] ?? '');
        if ($body !== '') {
            DB::insert('secure_room_messages', [
                'trade_id' => $tradeId,
                'user_id' => $uid,
                'type' => 'text',
                'body' => $body,
            ]);
            $success = 'پیام ارسال شد.';
        }
    } elseif ($action === 'pay_diff' && !$trade['diff_paid']) {
        $myBalance = (float)($user['credit_balance'] ?? 0);
        if ($myBalance < $amountToPay) {
            $_SESSION['error'] = 'موجودی کیف پول شما کافی نیست — نیاز: ' . fmt_credit($amountToPay - $myBalance);
        } else {
            escrow_hold($tradeId, $uid, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
            DB::query('UPDATE trades SET diff_paid = 1, step = 4 WHERE id = ?', [$tradeId]);
            $success = 'مابه‌التفاوت با موفقیت پرداخت شد!';
        }
    } elseif ($action === 'sign_contract') {
        if (sign_trade_contract($tradeId, $uid)) {
            $success = 'قرارداد با موفقیت امضا شد!';
            if (contract_fully_signed($tradeId)) {
                DB::query('UPDATE trades SET step = 5 WHERE id = ?', [$tradeId]);
            }
        } else {
            $error = 'امضای قرارداد ممکن نشد.';
        }
    } elseif ($action === 'set_shipping') {
        $shippingDate = clean($_POST['shipping_date'] ?? '');
        $shippingTime = clean($_POST['shipping_time'] ?? '');
        if ($shippingDate && $shippingTime) {
            if ($isA) {
                DB::update('trades', ['user_a_shipping_date' => $shippingDate, 'user_a_shipping_time' => $shippingTime], 'id = ?', [$tradeId]);
            } else {
                DB::update('trades', ['user_b_shipping_date' => $shippingDate, 'user_b_shipping_time' => $shippingTime], 'id = ?', [$tradeId]);
            }
            $success = 'زمان ارسال ثبت شد!';
            // If both set dates are set, move to step 6
            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
            if ($trade['user_a_shipping_date'] && $trade['user_b_shipping_date']) {
                DB::query('UPDATE trades SET step = 6 WHERE id = ?', [$tradeId]);
            }
        }
    } elseif ($action === 'mark_shipped') {
        if ($isA) {
            DB::update('trades', ['user_a_delivered' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_delivered' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'ارسال کالا ثبت شد.';
        $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
        if (($trade['user_a_delivered'] ?? 0) && ($trade['user_b_delivered'] ?? 0)) {
            DB::query('UPDATE trades SET step = 7 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'save_tracking') {
        $code = clean($_POST['tracking_code'] ?? '');
        if ($code === '') {
            $error = 'کد رهگیری را وارد کنید.';
        } elseif ($isA) {
            DB::update('trades', ['tracking_code_a' => $code], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['tracking_code_b' => $code], 'id = ?', [$tradeId]);
        }
        if ($error === '') {
            $success = 'کد رهگیری ثبت شد!';
            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
            if (($trade['tracking_code_a'] ?? '') !== '' && ($trade['tracking_code_b'] ?? '') !== '') {
                DB::query('UPDATE trades SET step = 8 WHERE id = ?', [$tradeId]);
            }
        }
    } elseif ($action === 'confirm_received') {
        if ($isA) {
            DB::update('trades', ['user_a_received' => 1], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_received' => 1], 'id = ?', [$tradeId]);
        }
        $success = 'دریافت کالا تایید شد!';
        $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
        if (($trade['user_a_received'] ?? 0) && ($trade['user_b_received'] ?? 0)) {
            DB::query('UPDATE trades SET step = 9 WHERE id = ?', [$tradeId]);
        }
    } elseif ($action === 'confirm_trade') {
        if (!contract_fully_signed($tradeId)) {
            $error = 'اول باید قرارداد امضا بشه.';
        } else {
            $isA = (int)$trade['user_a_id'] === $uid;
            if ($isA) {
                DB::query('UPDATE trades SET status = "user_a_confirmed" WHERE id = ? AND status = "in_progress"', [$tradeId]);
            } else {
                DB::query('UPDATE trades SET status = "user_b_confirmed" WHERE id = ? AND status = "user_a_confirmed"', [$tradeId]);
            }

            $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);

            if ($trade['status'] === 'user_b_confirmed') {
                $result = complete_trade($tradeId);
                if (isset($result['error'])) {
                    $_SESSION['error'] = $result['error'];
                    if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                        header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                        exit;
                    } else {
                        header('Location: ' . APP_URL . '/trades.php?trade=' . $tradeId);
                        exit;
                    }
                }
                DB::query('UPDATE trades SET step = 10 WHERE id = ?', [$tradeId]);
                $success = 'معامله تکمیل شد! سپرده آزاد شد!';
            } else {
                $success = 'تایید ثبت شد! در انتظار طرف مقابل.';
            }
        }
    }

    // Refresh trade after any action
    $trade = DB::fetch(
        'SELECT t.*, ua.name AS user_a_name, ub.name AS user_b_name,
                la.title AS listing_a_title, lb.title AS listing_b_title
         FROM trades t
         JOIN users ua ON ua.id = t.user_a_id
         JOIN users ub ON ub.id = t.user_b_id
         JOIN listings la ON la.id = t.listing_a_id
         LEFT JOIN listings lb ON lb.id = t.listing_b_id
         WHERE t.id = ? AND (t.user_a_id = ? OR t.user_b_id = ?)',
        [$tradeId, $uid, $uid]
    );
    $contract = get_trade_contract($tradeId) ?? $contract;
    $myReview = DB::fetch(
        'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ? LIMIT 1',
        [$tradeId, $uid]
    );
}

// Fetch messages
$messages = DB::fetchAll(
    'SELECT m.*, u.name AS user_name
     FROM secure_room_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.trade_id = ?
     ORDER BY m.created_at ASC',
    [$tradeId]
);

// Mark messages as read
DB::query(
    'UPDATE secure_room_messages SET is_read = 1 WHERE trade_id = ? AND user_id != ?',
    [$tradeId, $uid]
);

function get_step_status(int $stepId, array $trade, array $contract, bool $hasReview): string {
    $feePaid = (bool)($trade['fee_paid'] ?? 0);
    $diffPaid = (bool)($trade['diff_paid'] ?? 0);
    $contractSigned = !empty($contract) && !empty($contract['user_a_signed']) && !empty($contract['user_b_signed']);
    $shippingReady = !empty($trade['user_a_shipping_date']) && !empty($trade['user_b_shipping_date']);
    $shipped = !empty($trade['user_a_delivered']) && !empty($trade['user_b_delivered']);
    $trackingReady = !empty($trade['tracking_code_a']) && !empty($trade['tracking_code_b']);
    $received = !empty($trade['user_a_received']) && !empty($trade['user_b_received']);
    $completed = ($trade['status'] ?? '') === 'completed';

    if ($stepId === 1 || $stepId === 2) return 'completed';
    if ($stepId === 3) return $feePaid ? 'completed' : 'current';
    if ($stepId === 4) return $diffPaid ? 'completed' : ($feePaid ? 'current' : 'locked');
    if ($stepId === 5) return $contractSigned ? 'completed' : (($feePaid && $diffPaid) ? 'current' : 'locked');
    if ($stepId === 6) return $shippingReady ? 'completed' : ($contractSigned ? 'current' : 'locked');
    if ($stepId === 7) return $shipped ? 'completed' : ($shippingReady ? 'current' : 'locked');
    if ($stepId === 8) return $trackingReady ? 'completed' : ($shipped ? 'current' : 'locked');
    if ($stepId === 9) return $received ? 'completed' : ($trackingReady ? 'current' : 'locked');
    if ($stepId === 10) return $completed ? 'completed' : ($received ? 'current' : 'locked');
    if ($stepId === 11) return $hasReview ? 'completed' : ($completed ? 'current' : 'locked');
    if ($stepId === 12) return ($completed && $hasReview) ? 'completed' : 'locked';
    return 'pending';
}

$tab = clean($_GET['tab'] ?? 'chat');

render_head('اتاق امن معامله #' . $tradeId);
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-lg">
    <div class="mb-6">
      <a href="<?= APP_URL ?>/trades.php" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به معاملات
      </a>
      <h2 class="mt-3" style="display:flex;align-items:center;gap:.5rem">
        <i class="bi bi-shield-check" style="color:var(--primary)"></i>
        اتاق امن معامله #<?= $tradeId ?> با <?= h($otherName) ?>
      </h2>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="card mb-5" style="background:linear-gradient(135deg, rgba(var(--primary-rgb),0.05), rgba(var(--accent-rgb),0.05));">
      <div class="card-body">
        <h4 style="margin-bottom:var(--sp-5);display:flex;align-items:center;gap:.5rem">
          <i class="bi bi-clock-history" style="color:var(--primary)"></i>
          پیشرفت معامله
        </h4>
        <?php
          $steps = [
            ['id' => 1, 'title' => 'پیشنهاد ارسال شد', 'icon' => 'bi-send'],
            ['id' => 2, 'title' => 'پیشنهاد تایید شد', 'icon' => 'bi-check-lg'],
            ['id' => 3, 'title' => 'پرداخت کارمزد', 'icon' => 'bi-wallet2'],
            ['id' => 4, 'title' => 'پرداخت مابه‌التفاوت', 'icon' => 'bi-credit-card'],
            ['id' => 5, 'title' => 'امضای قرارداد', 'icon' => 'bi-pencil-square'],
            ['id' => 6, 'title' => 'انتخاب زمان ارسال', 'icon' => 'bi-clock'],
            ['id' => 7, 'title' => 'ارسال کالا', 'icon' => 'bi-truck'],
            ['id' => 8, 'title' => 'ثبت کد رهگیری', 'icon' => 'bi-upc-scan'],
            ['id' => 9, 'title' => 'دریافت کالا', 'icon' => 'bi-box'],
            ['id' =>10, 'title' => 'تایید نهایی معامله', 'icon' => 'bi-check-circle-fill'],
            ['id' =>11, 'title' => 'ثبت امتیاز', 'icon' => 'bi-star'],
            ['id' =>12, 'title' => 'پایان معامله', 'icon' => 'bi-flag'],
          ];
          echo '<div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;margin-bottom:var(--sp-5);padding:var(--sp-4);background:#fff;border-radius:var(--radius-md);">';
          foreach ($steps as $i => $s):
            $status = get_step_status($s['id'], $trade, $contract, (bool)$myReview);
            $stepBg = $status === 'completed' ? 'var(--success)' : ($status === 'current' ? 'var(--primary)' : ($status === 'locked' ? 'var(--border)' : 'var(--text-muted)'));
            $stepText = $status === 'completed' || $status === 'current' ? '#fff' : 'var(--text-muted)';
            $stepShadow = $status === 'current' ? '0 4px 12px rgba(var(--primary-rgb),0.3)' : 'none';
            $stepBorder = $status === 'current' ? '2px solid var(--primary)' : 'none';
            $fontWeight = $status === 'current' ? '600' : '500';
            $titleColor = $status === 'completed' ? 'var(--success)' : ($status === 'current' ? 'var(--primary)' : 'var(--text-muted)');
            echo '<div style="display:flex;flex-direction:column;align-items:center;gap:.5rem;flex:1;min-width:80px;">';
              echo '<div style="width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:'.$stepBg.';color:'.$stepText.';box-shadow:'.$stepShadow.';border:'.$stepBorder.';">';
                echo '<i class="bi '.$s['icon'].'" style="font-size:1.25rem"></i>';
              echo '</div>';
              echo '<div class="fs-xs" style="text-align:center;line-height:1.3;font-weight:'.$fontWeight.';color:'.$titleColor.'">';
                echo h($s['title']);
              echo '</div>';
            echo '</div>';
            if ($i < count($steps)-1):
              $lineColor = $status === 'completed' ? 'var(--success)' : 'var(--border)';
              echo '<div style="flex:0 0 auto;width:40px;height:2px;background:'.$lineColor.'"></div>';
            endif;
          endforeach;
          echo '</div>';

          // Render current step action card
          $currentStep = null;
          foreach ($steps as $s):
            if (get_step_status($s['id'], $trade, $contract, (bool)$myReview) === 'current') {
                $currentStep = $s;
                break;
            }
          endforeach;

          if ($currentStep): ?>
        <div class="card" style="margin:0;background:#fff;border:2px solid var(--primary);">
          <div class="card-body">
            <h5 style="display:flex;align-items:center;gap:.5rem;margin-bottom:var(--sp-4);">
              <i class="bi <?= $currentStep['icon'] ?>" style="color:var(--primary)"></i>
              مرحله فعلی: <?= h($currentStep['title']) ?>
            </h5>

            <?php if ($currentStep['id'] == 3 && !$trade['fee_paid']): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">کارمزد باید پرداخت بشه. از صفحه <a href="<?= APP_URL ?>/trades.php" style="color:var(--primary)">پرداخت کارمزد</a> انجام بشه.</p>
            <?php elseif ($currentStep['id'] ==4 && !$trade['diff_paid']): ?>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-4);">
                <div class="card" style="margin:0;background:rgba(var(--accent-rgb),0.05);">
                  <div class="card-body">
                    <div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-2)">وضعیت مابه‌التفاوت</div>
                    <?php if ($iOwe): ?>
                      <h4 style="color:var(--accent);margin-bottom:var(--sp-2);">شما باید پرداخت کنید</h4>
                      <div style="font-size:1.75rem;font-weight:800;color:var(--accent);">
                        <?= fmt_credit($amountToPay) ?></div>
                      <form method="POST" style="margin-top:var(--sp-4);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="pay_diff">
                        <button type="submit" class="btn btn-accent btn-lg" style="width:100%;">
                          <i class="bi bi-credit-card"></i> پرداخت مابه‌التفاوت
                        </button>
                      </form>
                    <?php else: ?>
                      <h4 style="color:var(--primary);margin-bottom:var(--sp-2);">شما دریافت می‌کنید</h4>
                      <div style="font-size:1.75rem;font-weight:800;color:var(--primary);">
                        <?= fmt_credit($amountToPay) ?></div>
                      <div style="margin-top:var(--sp-3);color:var(--text-secondary);">در انتظار پرداخت طرف هستیم...</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php elseif ($currentStep['id'] == 5): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">قرارداد دیجیتال رو امضا کنید.</p>
              <div class="card" style="background:rgba(var(--primary-rgb),0.05);margin-bottom:var(--sp-4);">
                <div class="card-body">
                  <h6>قرارداد معامله</h6>
                  <div style="color:var(--text-secondary);font-size:.875rem;line-height:1.8;margin-bottom:var(--sp-3);">
                    این قرارداد برای معامله بین شما و <?= h($otherName) ?> هست. با کلیک روی «امضا» با شرایط موافقید.
                  </div>
                  <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:var(--sp-3);">
                    <span class="badge badge-<?= $contract['user_a_signed'] ? 'success' : 'warning' ?>">
                      <?= h($trade['user_a_name']) ?>: <?= $contract['user_a_signed'] ? 'امضا کرده' : 'امضا نکرده' ?>
                    </span>
                    <span class="badge badge-<?= $contract['user_b_signed'] ? 'success' : 'warning' ?>">
                      <?= h($trade['user_b_name']) ?>: <?= $contract['user_b_signed'] ? 'امضا کرده' : 'امضا نکرده' ?>
                    </span>
                  </div>
                  <?php if (($isA && !$contract['user_a_signed']) || (!$isA && !$contract['user_b_signed'])): ?>
                    <form method="POST">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="sign_contract">
                      <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-pencil"></i> امضای قرارداد
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php elseif ($currentStep['id'] == 6): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">زمان ارسال کالا رو ثبت کنید.</p>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-4);">
                <div class="card" style="margin:0;">
                  <div class="card-body">
                    <h6>زمان شما</h6>
                    <?php if (($isA && $trade['user_a_shipping_date']) || (!$isA && $trade['user_b_shipping_date'])): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i>
                        ثبت شده:
                        <?= h($isA ? $trade['user_a_shipping_date'] : $trade['user_b_shipping_date']) ?>
                        •
                        <?= h($isA ? $trade['user_a_shipping_time'] : $trade['user_b_shipping_time']) ?>
                      </div>
                    <?php else: ?>
                      <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_shipping">
                        <div class="form-group">
                          <label class="form-label">تاریخ ارسال</label>
                          <input type="date" name="shipping_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                          <label class="form-label">ساعت ارسال</label>
                          <input type="time" name="shipping_time" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mt-2">ثبت زمان ارسال</button>
                      </form>
                    <?php endif; ?>
                  </div>
                  <div class="card-body">
                    <h6>زمان طرف مقابل</h6>
                    <?php if ((!$isA && $trade['user_a_shipping_date']) || ($isA && $trade['user_b_shipping_date'])): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i>
                        ثبت شده:
                        <?= h($isA ? $trade['user_b_shipping_date'] : $trade['user_a_shipping_date']) ?>
                        •
                        <?= h($isA ? $trade['user_b_shipping_time'] : $trade['user_a_shipping_time']) ?>
                      </div>
                    <?php else: ?>
                      <div style="color:var(--text-secondary);">در انتظار طرف مقابل...</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php elseif ($currentStep['id'] ==7): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">بعد از ارسال واقعی کالا، وضعیت ارسال را ثبت کنید.</p>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-4);">
                <div class="card" style="margin:0;">
                  <div class="card-body">
                    <h6>وضعیت شما</h6>
                    <?php if (($isA && !empty($trade['user_a_delivered'])) || (!$isA && !empty($trade['user_b_delivered']))): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i>
                        ارسال شما ثبت شده است
                      </div>
                    <?php else: ?>
                      <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_shipped">
                        <button type="submit" class="btn btn-primary btn-sm mt-2">کالا را ارسال کردم</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card" style="margin:0;">
                  <div class="card-body">
                    <h6>وضعیت طرف مقابل</h6>
                    <?php if ((!$isA && !empty($trade['user_a_delivered'])) || ($isA && !empty($trade['user_b_delivered']))): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i>
                        ارسال طرف مقابل ثبت شده است
                      </div>
                    <?php else: ?>
                      <div style="color:var(--text-secondary);">در انتظار طرف مقابل...</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php elseif ($currentStep['id'] ==8): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">بعد از ارسال، کد رهگیری هر دو طرف را ثبت کنید.</p>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-4);">
                <div class="card" style="margin:0;">
                  <div class="card-body">
                    <h6>کد رهگیری شما</h6>
                    <?php if (($isA && !empty($trade['tracking_code_a'])) || (!$isA && !empty($trade['tracking_code_b']))): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i>
                        <?= h($isA ? $trade['tracking_code_a'] : $trade['tracking_code_b']) ?>
                      </div>
                    <?php else: ?>
                      <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_tracking">
                        <div class="form-group">
                          <label class="form-label">کد رهگیری</label>
                          <input type="text" name="tracking_code" class="form-control" required placeholder="کد رهگیری را وارد کنید">
                        </div>
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-upc-scan"></i> ثبت کد رهگیری
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card" style="margin:0;">
                  <div class="card-body">
                    <h6>کد رهگیری طرف مقابل</h6>
                    <?php if ((!$isA && !empty($trade['tracking_code_a'])) || ($isA && !empty($trade['tracking_code_b']))): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i>
                        <?= h($isA ? $trade['tracking_code_b'] : $trade['tracking_code_a']) ?>
                      </div>
                    <?php else: ?>
                      <div style="color:var(--text-secondary);">در انتظار طرف مقابل...</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php elseif ($currentStep['id'] ==9): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">بعد از رسیدن کالا، دریافت را تایید کنید.</p>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-4);">
                <div class="card" style="margin:0;">
                  <div class="card-body">
                    <h6>وضعیت شما</h6>
                    <?php if (($isA && !empty($trade['user_a_received'])) || (!$isA && !empty($trade['user_b_received']))): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i> دریافت شما تایید شده است
                      </div>
                    <?php else: ?>
                      <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="confirm_received">
                        <button type="submit" class="btn btn-primary">
                          <i class="bi bi-box"></i> کالا را دریافت کردم
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card" style="margin:0;">
                  <div class="card-body">
                    <h6>وضعیت طرف مقابل</h6>
                    <?php if ((!$isA && !empty($trade['user_a_received'])) || ($isA && !empty($trade['user_b_received']))): ?>
                      <div style="color:var(--success);">
                        <i class="bi bi-check-circle"></i> طرف مقابل هم دریافت را تایید کرده
                      </div>
                    <?php else: ?>
                      <div style="color:var(--text-secondary);">در انتظار تایید طرف مقابل...</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php elseif ($currentStep['id'] ==10): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">هر دو طرف کالا را گرفته‌اند. حالا معامله را نهایی کنید.</p>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_trade">
                <button type="submit" class="btn btn-accent btn-lg">
                  <i class="bi bi-check-circle"></i> تایید نهایی معامله
                </button>
              </form>
            <?php elseif ($currentStep['id'] ==11): ?>
              <p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">معامله کامل شده؛ امتیاز شما باقی مانده است.</p>
              <form method="POST" action="<?= APP_URL ?>/api/review.php" style="display:grid;gap:var(--sp-3);max-width:420px;">
                <?= csrf_field() ?>
                <input type="hidden" name="trade_id" value="<?= $tradeId ?>">
                <input type="hidden" name="to_user_id" value="<?= $otherId ?>">
                <div class="form-group">
                  <label class="form-label">امتیاز به معامله</label>
                  <select name="trade_rating" class="form-control" required>
                    <option value="5">5</option>
                    <option value="4">4</option>
                    <option value="3">3</option>
                    <option value="2">2</option>
                    <option value="1">1</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">امتیاز به طرف مقابل</label>
                  <select name="user_rating" class="form-control" required>
                    <option value="5">5</option>
                    <option value="4">4</option>
                    <option value="3">3</option>
                    <option value="2">2</option>
                    <option value="1">1</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">نظر</label>
                  <textarea name="comment" class="form-control" rows="3" placeholder="اختیاری"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">ثبت امتیاز</button>
              </form>
            <?php endif; ?>

          </div>
        </div>
          <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header" style="display:flex;gap:var(--sp-2)">
        <a href="?id=<?= $tradeId ?>&tab=chat" class="btn <?= $tab === 'chat' ? 'btn-primary' : 'btn-ghost' ?>">
          <i class="bi bi-chat"></i> گفتگو
        </a>
        <a href="?id=<?= $tradeId ?>&tab=details" class="btn <?= $tab === 'details' ? 'btn-primary' : 'btn-ghost' ?>">
          <i class="bi bi-info-circle"></i> جزئیات معامله
        </a>
      </div>
      
      <div class="card-body">
        <?php if ($tab === 'chat'): ?>
          <!-- Chat Section -->
          <div id="messages" style="max-height:500px;overflow-y:auto;margin-bottom:var(--sp-4);padding:var(--sp-3);background:var(--bg);border-radius:var(--radius-md);">
            <?php if (empty($messages)): ?>
              <div class="empty-state" style="padding:var(--sp-6);text-align:center">
                <i class="bi bi-chat-dots" style="font-size:3rem;color:var(--text-muted)"></i>
                <p class="mt-3" style="color:var(--text-muted)">هنوز پیامی ارسال نشده است</p>
              </div>
            <?php else:
              foreach ($messages as $msg):
                $isMe = (int)$msg['user_id'] === $uid;
            ?>
              <div style="display:flex;justify-content:<?= $isMe ? 'flex-start' : 'flex-end' ?>;margin-bottom:var(--sp-3)">
                <div class="card" style="margin:0;max-width:70%;<?= $isMe ? 'border-right:4px solid var(--primary)' : 'border-left:4px solid var(--accent)' ?>;">
                  <div class="card-body" style="padding:var(--sp-3)">
                    <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-1)"><?= h($msg['user_name']) ?> • <?= persian_datetime($msg['created_at']) ?></div>
                    <div><?= h($msg['body']) ?></div>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
          
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_message">
            <div style="display:flex;gap:var(--sp-2)">
              <input type="text" name="body" class="form-control" placeholder="پیام خود را بنویسید..." required>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-send"></i>
              </button>
            </div>
          </form>
        <?php else: ?>
          <!-- Details Section -->
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--sp-4);">
            <div class="card" style="margin:0;">
              <div class="card-body">
                <h5>کالاهای معامله</h5>
                <div style="margin-top:var(--sp-3);">
                  <div style="font-weight:600;margin-bottom:var(--sp-1)">شما:</div>
                  <div><?= h($isA ? $trade['listing_a_title'] : $trade['listing_b_title']) ?></div>
                </div>
                <div style="margin-top:var(--sp-3);">
                  <div style="font-weight:600;margin-bottom:var(--sp-1)"><?= h($otherName) ?>:</div>
                  <div><?= h($isA ? $trade['listing_b_title'] : $trade['listing_a_title']) ?></div>
                </div>
              </div>
            </div>
            
            <div class="card" style="margin:0;">
              <div class="card-body">
                <h5>وضعیت پرداخت</h5>
                <div style="margin-top:var(--sp-3);">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--sp-2)">
                    <span>کارمزد:</span>
                    <span class="badge badge-<?= $trade['fee_paid'] ? 'success' : 'warning' ?>"><?= $trade['fee_paid'] ? 'پرداخت شده' : 'پرداخت نشده' ?></span>
                  </div>
                  <div style="display:flex;justify-content:space-between;align-items:center">
                    <span>مابه‌التفاوت:</span>
                    <span class="badge badge-<?= $trade['diff_paid'] ? 'success' : 'warning' ?>"><?= $trade['diff_paid'] ? 'پرداخت شده' : 'پرداخت نشده' ?></span>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="card" style="margin:0;">
              <div class="card-body">
                <h5>زمان ارسال</h5>
                <div style="margin-top:var(--sp-3);">
                  <?php if (($isA && $trade['user_a_shipping_date']) || (!$isA && $trade['user_b_shipping_date'])): ?>
                    <div style="margin-bottom:var(--sp-2);">
                      <div class="fs-xs" style="color:var(--text-muted)">زمان شما:</div>
                      <div><?= h($isA ? $trade['user_a_shipping_date'] : $trade['user_b_shipping_date']) ?> • <?= h($isA ? $trade['user_a_shipping_time'] : $trade['user_b_shipping_time']) ?></div>
                    </div>
                  <?php endif; ?>
                  <?php if ((!$isA && $trade['user_a_shipping_date']) || ($isA && $trade['user_b_shipping_date'])): ?>
                    <div style="margin-top:var(--sp-3);">
                      <div class="fs-xs" style="color:var(--text-muted)">زمان طرف مقابل:</div>
                      <div><?= h($isA ? $trade['user_b_shipping_date'] : $trade['user_a_shipping_date']) ?> • <?= h($isA ? $trade['user_b_shipping_time'] : $trade['user_a_shipping_time']) ?></div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    
  </div>
</div>

<?php render_footer(); ?>
