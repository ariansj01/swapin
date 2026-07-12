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
      <h2 class="mt-3">اتاق امن معامله #<?= $tradeId ?> با <?= h($otherName) ?></h2>
    </div>

    <div class="card mb-5">
      <div class="card-body">
        <!-- Timeline -->
        <h4 style="margin-bottom:var(--sp-4)">پیشرفت معامله</h4>
        <div style="display:flex;flex-direction:column;gap:var(--sp-3)">
          <?php
            $steps = [
                ['step' => 1, 'title' => 'پیشنهاد ارسال شد', 'icon' => 'bi-send', 'status' => 'completed'],
                ['step' => 2, 'title' => 'پیشنهاد تایید شد', 'icon' => 'bi-check-lg', 'status' => 'completed'],
                ['step' => 3, 'title' => 'پرداخت کارمزد', 'icon' => 'bi-wallet2', 'status' => $trade['fee_paid'] ? 'completed' : ($trade['step'] >= 2 ? 'current' : 'locked')],
                ['step' => 4, 'title' => 'پرداخت مابه‌التفاوت', 'icon' => 'bi-credit-card', 'status' => $trade['diff_paid'] ? 'completed' : ($trade['step'] >= 3 ? 'current' : 'locked')],
                ['step' => 5, 'title' => 'امضای قرارداد', 'icon' => 'bi-pencil-square', 'status' => 'pending'],
                ['step' => 6, 'title' => 'انتخاب زمان ارسال', 'icon' => 'bi-clock', 'status' => 'pending'],
                ['step' => 7, 'title' => 'ارسال کالا', 'icon' => 'bi-truck', 'status' => 'pending'],
                ['step' => 8, 'title' => 'ثبت کد رهگیری', 'icon' => 'bi-upc-scan', 'status' => 'pending'],
                ['step' => 9, 'title' => 'دریافت کالا', 'icon' => 'bi-box', 'status' => 'pending'],
                ['step' => 10, 'title' => 'تایید دریافت', 'icon' => 'bi-check-circle-fill', 'status' => 'pending'],
                ['step' => 11, 'title' => 'ثبت امتیاز', 'icon' => 'bi-star', 'status' => 'pending'],
                ['step' => 12, 'title' => 'پایان معامله', 'icon' => 'bi-flag', 'status' => 'pending'],
            ];
            foreach ($steps as $s):
              $isCompleted = $s['status'] === 'completed';
              $isCurrent = $s['status'] === 'current';
              $isLocked = $s['status'] === 'locked';
          ?>
            <div style="display:flex;align-items:center;gap:var(--sp-3)">
              <div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;<?= $isCompleted ? 'background:var(--success);color:#fff' : ($isCurrent ? 'background:var(--primary);color:#fff' : 'background:var(--border);color:var(--text-muted)') ?>">
                <i class="bi <?= $s['icon'] ?>"></i>
              </div>
              <div style="flex:1">
                <div style="font-weight:<?= $isCurrent ? '700' : '500' ?>;color:<?= $isCompleted ? 'var(--success)' : ($isCurrent ? 'var(--primary)' : 'var(--text-muted)') ?>"><?= $s['title'] ?></div>
                <div class="fs-xs" style="color:var(--text-muted)"><?= $isCompleted ? 'تکمیل شده' : ($isCurrent ? 'در حال انجام' : 'قفل شده') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
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
                  <?php if ($trade['user_a_shipping_date']): ?>
                    <div style="margin-bottom:var(--sp-2)">
                      <div class="fs-xs" style="color:var(--text-muted)">زمان شما:</div>
                      <div><?= h($trade['user_a_shipping_date']) ?> • <?= h($trade['user_a_shipping_time']) ?></div>
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
                  <?php if ($trade['user_b_shipping_date']): ?>
                    <div style="margin-top:var(--sp-3)">
                      <div class="fs-xs" style="color:var(--text-muted)">زمان طرف مقابل:</div>
                      <div><?= h($trade['user_b_shipping_date']) ?> • <?= h($trade['user_b_shipping_time']) ?></div>
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
