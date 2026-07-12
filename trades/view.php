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
$valueA = (float)($trade['value_a'] ?? 0);
$valueB = (float)($trade['value_b'] ?? 0);
$creditDiff = (float)($trade['credit_diff'] ?? 0);

if ($creditDiff > 0) {
    // User A gets money, User B owes
    $userToPayId = $trade['user_b_id'];
    $amountToPay = $creditDiff;
} elseif ($creditDiff < 0) {
    // User B gets money, User A owes
    $userToPayId = $trade['user_a_id'];
    $amountToPay = abs($creditDiff);
} else {
    $userToPayId = 0;
    $amountToPay = 0;
}

$iOwe = (int)$userToPayId === $uid;

// Handle paying difference
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_diff' && !$trade['diff_paid']) {
    csrf_verify_or_fail();
    // Check if user has enough balance
    $myBalance = (float)($user['credit_balance'] ?? 0);
    if ($myBalance < $amountToPay) {
        $_SESSION['error'] = 'موجودی کیف پول شما کافی نیست. لطفاً ' . fmt_credit($amountToPay - $myBalance) . ' به کیف پول خود اضافه کنید.';
    } else {
        escrow_hold($tradeId, $uid, $amountToPay, 'سپرده مابه‌التفاوت معامله #' . $tradeId);
        DB::query('UPDATE trades SET diff_paid = 1, step = 3 WHERE id = ?', [$tradeId]);
        // Refresh data
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
    }
}

// Handle sending messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    csrf_verify_or_fail();
    $body = clean($_POST['body'] ?? '');
    $type = clean($_POST['type'] ?? 'text');
    if (mb_strlen($body) > 0) {
        DB::insert('secure_room_messages', [
            'trade_id' => $tradeId,
            'user_id' => $uid,
            'type' => $type,
            'body' => $body,
        ]);
    }
    header('Location: ' . APP_URL . '/trades/view.php?id=' . $tradeId . '#messages');
    exit;
}

// Handle setting shipping date/time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_shipping') {
    csrf_verify_or_fail();
    $shippingDate = clean($_POST['shipping_date'] ?? '');
    $shippingTime = clean($_POST['shipping_time'] ?? '');
    
    if ($shippingDate && $shippingTime) {
        if ($isA) {
            DB::update('trades', ['user_a_shipping_date' => $shippingDate, 'user_a_shipping_time' => $shippingTime], 'id = ?', [$tradeId]);
        } else {
            DB::update('trades', ['user_b_shipping_date' => $shippingDate, 'user_b_shipping_time' => $shippingTime], 'id = ?', [$tradeId]);
        }
        
        // Refresh trade data
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
    }
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
    <div class="alert alert-danger mb-5">
      <i class="bi bi-exclamation-circle"></i>
      <?= h($_SESSION['error']) ?>
      <?php unset($_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="card mb-5" style="background:linear-gradient(135deg, rgba(var(--primary-rgb),0.05), rgba(var(--accent-rgb),0.05));">
      <div class="card-body">
        <h4 style="margin-bottom:var(--sp-5);display:flex;align-items:center;gap:.5rem">
          <i class="bi bi-clock-history" style="color:var(--primary)"></i>
          پیشرفت معامله
        </h4>
        <?php
          // Build steps array properly with actual state
          $step = (int)($trade['step'] ?? 1);
          $steps = [
            ['id' => 1, 'title' => 'پیشنهاد ارسال شد', 'icon' => 'bi-send', 'status' => 'completed'],
            ['id' => 2, 'title' => 'پیشنهاد تایید شد', 'icon' => 'bi-check-lg', 'status' => 'completed'],
            ['id' => 3, 'title' => 'پرداخت کارمزد', 'icon' => 'bi-wallet2', 'status' => $trade['fee_paid'] ? 'completed' : ($step >= 2 ? 'current' : 'locked')],
            ['id' => 4, 'title' => 'پرداخت مابه‌التفاوت', 'icon' => 'bi-credit-card', 'status' => $trade['diff_paid'] ? 'completed' : ($step >= 2 ? 'current' : 'locked')],
            ['id' => 5, 'title' => 'امضای قرارداد', 'icon' => 'bi-pencil-square', 'status' => 'pending'],
            ['id' => 6, 'title' => 'انتخاب زمان ارسال', 'icon' => 'bi-clock', 'status' => 'pending'],
            ['id' => 7, 'title' => 'ارسال کالا', 'icon' => 'bi-truck', 'status' => 'pending'],
            ['id' => 8, 'title' => 'ثبت کد رهگیری', 'icon' => 'bi-upc-scan', 'status' => 'pending'],
            ['id' => 9, 'title' => 'دریافت کالا', 'icon' => 'bi-box', 'status' => 'pending'],
            ['id' => 10, 'title' => 'تایید دریافت', 'icon' => 'bi-check-circle-fill', 'status' => 'pending'],
            ['id' => 11, 'title' => 'ثبت امتیاز', 'icon' => 'bi-star', 'status' => 'pending'],
            ['id' => 12, 'title' => 'پایان معامله', 'icon' => 'bi-flag', 'status' => 'pending'],
          ];

          // Render steps horizontally for top part
          echo '<div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;margin-bottom:var(--sp-5);padding:var(--sp-4);background:#fff;border-radius:var(--radius-md);">';
          foreach ($steps as $i => $s):
            $isCompleted = $s['status'] === 'completed';
            $isCurrent = $s['status'] === 'current';
            $isLocked = $s['status'] === 'locked';
            $isPending = $s['status'] === 'pending';

            $stepBg = $isCompleted ? 'var(--success)' : ($isCurrent ? 'var(--primary)' : ($isLocked ? 'var(--border)' : 'var(--text-muted)'));
            $stepText = $isCompleted || $isCurrent ? '#fff' : 'var(--text-muted)';
            $stepShadow = $isCurrent ? '0 4px 12px rgba(var(--primary-rgb),0.3)' : 'none';
            $stepBorder = $isCurrent ? '2px solid var(--primary)' : 'none';
            $fontWeight = $isCurrent ? '600' : '500';
            $titleColor = $isCompleted ? 'var(--success)' : ($isCurrent ? 'var(--primary)' : 'var(--text-muted)');
            echo '<div style="display:flex;flex-direction:column;align-items:center;gap:.5rem;flex:1;min-width:80px;">';
              echo '<div style="width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:'.$stepBg.';color:'.$stepText.';box-shadow:'.$stepShadow.';border:'.$stepBorder.';">';
                echo '<i class="bi '.$s['icon'].'" style="font-size:1.25rem"></i>';
              echo '</div>';
              echo '<div class="fs-xs" style="text-align:center;line-height:1.3;font-weight:'.$fontWeight.';color:'.$titleColor.'">';
                echo h($s['title']);
              echo '</div>';
            echo '</div>';
            if ($i < count($steps)-1):
              $lineColor = $isCompleted ? 'var(--success)' : 'var(--border)';
              echo '<div style="flex:0 0 auto;width:40px;height:2px;background:'.$lineColor.'"></div>';
            endif;
          endforeach;
          echo '</div>';

          // Render current step action card
          $currentStep = null;
          foreach ($steps as $s):
            if ($s['status'] === 'current'):
              $currentStep = $s;
              break;
            endif;
          endforeach;

          if ($currentStep):
            echo '<div class="card" style="margin:0;background:#fff;border:2px solid var(--primary);">';
              echo '<div class="card-body">';
                echo '<h5 style="display:flex;align-items:center;gap:.5rem;margin-bottom:var(--sp-4);">';
                  echo '<i class="bi '.$currentStep['icon'].'" style="color:var(--primary)"></i>';
                  echo 'مرحله فعلی: ' . h($currentStep['title']);
                echo '</h5>';

                if ($currentStep['id'] === 3 && !$trade['fee_paid']):
                  // Fee payment (though fee should already be paid, but just in case)
                  echo '<p style="margin-bottom:var(--sp-3);color:var(--text-secondary)">کارمزد پلتفرم باید پرداخت شود تا ادامه مسیر باز شود.</p>';
                elseif ($currentStep['id'] === 4 && !$trade['diff_paid']):
                  // Difference payment
                  echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-4);">';
                    echo '<div class="card" style="margin:0;background:rgba(var(--accent-rgb),0.05);">';
                      echo '<div class="card-body">';
                        echo '<div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-2)">وضعیت مابه‌التفاوت</div>';
                        if ($iOwe):
                          echo '<h4 style="color:var(--accent);margin-bottom:var(--sp-2);">شما باید پرداخت کنید</h4>';
                          echo '<div style="font-size:1.75rem;font-weight:800;color:var(--accent);">';
                            echo fmt_credit($amountToPay);
                          echo '</div>';
                          echo '<form method="POST" style="margin-top:var(--sp-4);">';
                            echo csrf_field();
                            echo '<input type="hidden" name="action" value="pay_diff">';
                            echo '<button type="submit" class="btn btn-accent btn-lg" style="width:100%;">';
                              echo '<i class="bi bi-credit-card"></i> پرداخت مابه‌التفاوت';
                            echo '</button>';
                          echo '</form>';
                        else:
                          echo '<h4 style="color:var(--primary);margin-bottom:var(--sp-2);">شما دریافت می‌کنید</h4>';
                          echo '<div style="font-size:1.75rem;font-weight:800;color:var(--primary);">';
                            echo fmt_credit($amountToPay);
                          echo '</div>';
                          echo '<div style="margin-top:var(--sp-3);color:var(--text-secondary);">در انتظار پرداخت طرف مقابل هستید...</div>';
                        endif;
                      echo '</div>';
                    echo '</div>';
                  echo '</div>';
                endif;

              echo '</div>';
            echo '</div>';
          endif;
        ?>
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
          <div id="messages" style="max-height:500px;overflow-y:auto;margin-bottom:var(--sp-4);padding:var(--sp-3);background:var(--bg);border-radius:var(--radius-md)">
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
                <div class="card" style="margin:0;max-width:70%;<?= $isMe ? 'border-right:4px solid var(--primary)' : 'border-left:4px solid var(--accent)' ?>">
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
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:var(--sp-4)">
            <div class="card" style="margin:0">
              <div class="card-body">
                <h5>کالاهای معامله</h5>
                <div style="margin-top:var(--sp-3)">
                  <div style="font-weight:600;margin-bottom:var(--sp-1)">شما:</div>
                  <div><?= h($isA ? $trade['listing_a_title'] : $trade['listing_b_title']) ?></div>
                </div>
                <div style="margin-top:var(--sp-3)">
                  <div style="font-weight:600;margin-bottom:var(--sp-1)"><?= h($otherName) ?>:</div>
                  <div><?= h($isA ? $trade['listing_b_title'] : $trade['listing_a_title']) ?></div>
                </div>
              </div>
            </div>
            
            <div class="card" style="margin:0">
              <div class="card-body">
                <h5>وضعیت پرداخت</h5>
                <div style="margin-top:var(--sp-3)">
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
            
            <div class="card" style="margin:0">
              <div class="card-body">
                <h5>زمان ارسال</h5>
                <div style="margin-top:var(--sp-3)">
                  <?php if (($isA && $trade['user_a_shipping_date']) || (!$isA && $trade['user_b_shipping_date'])): ?>
                    <div style="margin-bottom:var(--sp-2)">
                      <div class="fs-xs" style="color:var(--text-muted)">زمان شما:</div>
                      <div><?= h($isA ? $trade['user_a_shipping_date'] : $trade['user_b_shipping_date']) ?> • <?= h($isA ? $trade['user_a_shipping_time'] : $trade['user_b_shipping_time']) ?></div>
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
                  <?php if ((!$isA && $trade['user_a_shipping_date']) || ($isA && $trade['user_b_shipping_date'])): ?>
                    <div style="margin-top:var(--sp-3)">
                      <div class="fs-xs" style="color:var(--text-muted)">زمان طرف مقابل:</div>
                      <div><?= h($isA ? $trade['user_b_shipping_date'] : $trade['user_a_shipping_date']) ?> • <?= h($isA ? $trade['user_b_shipping_time'] : $trade['user_a_shipping_time']) ?></div>
                    </div>
                  <?php endif; ?>
                  <?php if ($trade['proposed_shipping_date']): ?>
                    <div style="margin-top:var(--sp-3);padding:var(--sp-3);background:rgba(var(--primary-rgb),0.05);border-radius:var(--radius-md)">
                      <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-1)">زمان پیشنهادی سیستم:</div>
                      <div><?= h($trade['proposed_shipping_date']) ?> • <?= h($trade['proposed_shipping_time']) ?></div>
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