<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$uid  = $user['id'];

$tab     = clean($_GET['tab'] ?? 'active');
$success = '';
$error   = '';

// Handle trade actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trade_id'])) {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('trade_action', 30, 900);
    $tradeId = (int)$_POST['trade_id'];
    $action  = clean($_POST['action'] ?? '');

    $trade = DB::fetch(
        'SELECT * FROM trades WHERE id = ? AND (user_a_id = ? OR user_b_id = ?)',
        [$tradeId, $uid, $uid]
    );

    if (!$trade) {
        $error = 'معامله یافت نشد.';
    } elseif ($action === 'sign_contract') {
        if (sign_trade_contract($tradeId, $uid)) {
            $success = 'قرارداد با موفقیت امضا شد.';
        } else {
            $error = 'امضای قرارداد ممکن نشد.';
        }
    } elseif ($action === 'set_shipping') {
        $method = clean($_POST['shipping_method'] ?? '');
        if (in_array($method, ['in_person','post','tipax','courier'], true)) {
            DB::update('trades', ['shipping_method' => $method], 'id = ?', [$tradeId]);
            $success = 'روش ارسال به‌روزرسانی شد.';
        }
    } elseif ($action === 'update_tracking') {
        $code = clean($_POST['tracking_code'] ?? '');
        $isA  = (int)$trade['user_a_id'] === $uid;
        DB::update('trades',
            $isA ? ['tracking_code_a' => $code] : ['tracking_code_b' => $code],
            'id = ?', [$tradeId]
        );
        $success = 'کد رهگیری ذخیره شد.';
    } elseif ($action === 'request_bnpl') {
        // $months = (int)($_POST['bnpl_months'] ?? 3);
        // $amount = (float)$trade['escrow_amount'] ?: (float)$trade['credit_diff'];
        // $result = request_bnpl($tradeId, $uid, $amount, $months);
        // if (isset($result['error'])) {
        //     $error = $result['error'];
        // } else {
        //     $success = 'درخواست BNPL ثبت شد — در انتظار تأیید.';
        // }
        $error = 'قابلیت پرداخت اقساطی فعلاً غیرفعال است.';
    } elseif ($action === 'confirm') {
        if (!contract_fully_signed($tradeId)) {
            $error = 'هر دو طرف باید ابتدا قرارداد دیجیتال را امضا کنند.';
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
                    // Redirect the user who needs to top up
                    if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                        header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                        exit;
                    } else {
                        // If the other user needs to top up, just show error and don't redirect current user
                        header('Location: ' . APP_URL . '/trades.php?trade=' . $tradeId);
                        exit;
                    }
                }
                $feeMsg = ($result['fee'] ?? 0) > 0
                    ? ' کارمزد پلتفرم: ' . fmt_credit((float)$result['fee']) . '.'
                    : '';
                $success = 'معامله تکمیل شد! سپرده آزاد شد. هر کدام ' . fmt_credit(10) . ' پاداش دریافت کردید.' . $feeMsg;
            } else {
                $success = 'تأیید ثبت شد! در انتظار طرف مقابل.';
            }
        }
    } elseif ($action === 'dispute') {
        DB::query('UPDATE trades SET status = "disputed" WHERE id = ?', [$tradeId]);
        $reason = clean($_POST['dispute_reason'] ?? 'other');
        $desc   = clean($_POST['dispute_desc'] ?? 'اختلاف از صفحه معاملات ثبت شد');
        if (in_array($reason, ['wrong_item','damaged','missing','fraud','other'], true)) {
            $against = (int)$trade['user_a_id'] === $uid ? (int)$trade['user_b_id'] : (int)$trade['user_a_id'];
            DB::insert('disputes', [
                'trade_id'    => $tradeId,
                'filed_by'    => $uid,
                'against'     => $against,
                'reason'      => $reason,
                'description' => $desc,
            ]);
        }
        $success = 'اختلاف ثبت شد. سپرده تا پایان بررسی نگه‌داری می‌شود. تیم ظرف ۲۴ ساعت رسیدگی می‌کند.';
    }
}

$escrowLabels = ['held' => 'نگهداری‌شده', 'released' => 'آزاد شده', 'refunded' => 'بازگشت داده شده'];

$detailTradeId = (int)($_GET['trade'] ?? 0);
$detailTrade   = null;
$detailContract = null;
$detailBnpl     = null; // BNPL is currently inactive, so we force null
if ($detailTradeId) {
    $detailTrade = DB::fetch(
        'SELECT t.*, ua.name AS user_a_name, ub.name AS user_b_name,
                la.title AS listing_a_title, lb.title AS listing_b_title
         FROM trades t
         JOIN users ua ON ua.id = t.user_a_id
         JOIN users ub ON ub.id = t.user_b_id
         JOIN listings la ON la.id = t.listing_a_id
         LEFT JOIN listings lb ON lb.id = t.listing_b_id
         WHERE t.id = ? AND (t.user_a_id = ? OR t.user_b_id = ?)',
        [$detailTradeId, $uid, $uid]
    );
    if ($detailTrade) {
        $detailContract = get_trade_contract($detailTradeId);
        if (!$detailContract) {
            create_trade_contract($detailTradeId);
            $detailContract = get_trade_contract($detailTradeId);
        }
        $detailBnpl = DB::fetch(
            'SELECT * FROM bnpl_requests WHERE trade_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1',
            [$detailTradeId, $uid]
        );
    }
}

// Active trades
$activeTrades = DB::fetchAll(
    'SELECT t.*, 
            ua.name AS user_a_name, ub.name AS user_b_name,
            la.title AS listing_a_title, lb.title AS listing_b_title,
            (SELECT filename FROM listing_images WHERE listing_id=la.id AND is_primary=1 LIMIT 1) AS la_thumb
     FROM trades t
     JOIN users ua ON ua.id = t.user_a_id
     JOIN users ub ON ub.id = t.user_b_id
     JOIN listings la ON la.id = t.listing_a_id
     LEFT JOIN listings lb ON lb.id = t.listing_b_id
     WHERE (t.user_a_id = ? OR t.user_b_id = ?)
       AND t.status IN ("in_progress","user_a_confirmed","user_b_confirmed","disputed")
     ORDER BY t.created_at DESC',
    [$uid, $uid]
);

// Completed trades
$completedTrades = DB::fetchAll(
    'SELECT t.*,
            ua.name AS user_a_name, ub.name AS user_b_name,
            la.title AS listing_a_title, lb.title AS listing_b_title,
            (SELECT rating FROM reviews WHERE trade_id = t.id AND from_user_id = ?) AS my_rating
     FROM trades t
     JOIN users ua ON ua.id = t.user_a_id
     JOIN users ub ON ub.id = t.user_b_id
     JOIN listings la ON la.id = t.listing_a_id
     LEFT JOIN listings lb ON lb.id = t.listing_b_id
     WHERE (t.user_a_id = ? OR t.user_b_id = ?)
       AND t.status = "completed"
     ORDER BY t.completed_at DESC',
    [$uid, $uid, $uid]
);

// Sent offers
$sentOffers = DB::fetchAll(
    'SELECT o.*, l.title AS listing_title, u.name AS seller_name,
            ol.title AS my_listing_title
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = l.user_id
     LEFT JOIN listings ol ON ol.id = o.offer_listing_id
     WHERE o.from_user_id = ?
     ORDER BY o.created_at DESC',
    [$uid]
);

render_head('معاملات من', '', ['canonical' => APP_URL . '/trades']);
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/dashboard" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به داشبورد
      </a>
      <h2 class="mt-3">معاملات من</h2>
    </div>

    <?php if (isset($_GET['accepted'])): ?>
    <div class="alert alert-success mb-5">
      <i class="bi bi-check-circle-fill"></i>
      <strong>پیشنهاد پذیرفته شد!</strong> معامله شما ایجاد شد. برای تکمیل تبادل با طرف مقابل هماهنگ کنید.
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($detailTrade): ?>
    <?php
      $dt = $detailTrade;
      $isA = (int)$dt['user_a_id'] === $uid;
      $partner = $isA ? $dt['user_b_name'] : $dt['user_a_name'];
      $mySigned = $isA ? ($detailContract['user_a_signed'] ?? 0) : ($detailContract['user_b_signed'] ?? 0);
    ?>
    <div class="card mb-6">
      <div class="card-header">
        <div class="d-flex align-center gap-3" style="justify-content:space-between;flex-wrap:wrap">
          <h3 style="margin:0">معامله #<?= $dt['id'] ?> — <?= h($partner) ?></h3>
          <a href="<?= APP_URL ?>/trades?tab=active" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-right"></i> بازگشت به فهرست</a>
        </div>
      </div>
      <div class="card-body">

        <!-- Trade Items -->
        <div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-6)">
          <div style="flex:1;min-width:150px;background:rgba(0,174,239,.04);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-4);text-align:center">
            <div class="fs-xs mb-2" style="color:var(--text-muted)">کالای شما</div>
            <div style="font-weight:600;font-size:.9375rem"><?= h($isA ? $dt['listing_a_title'] : ($dt['listing_b_title'] ?? 'نامشخص')) ?></div>
          </div>
          <div style="font-size:1.5rem;color:var(--accent-dark);font-weight:700">⇄</div>
          <div style="flex:1;min-width:150px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:var(--sp-4);text-align:center">
            <div class="fs-xs mb-2" style="color:var(--text-muted)">کالای طرف مقابل</div>
            <div style="font-weight:600;font-size:.9375rem"><?= h($isA ? ($dt['listing_b_title'] ?? 'پیشنهاد اعتباری') : $dt['listing_a_title']) ?></div>
          </div>
        </div>

        <?php if ((float)$dt['credit_diff'] != 0): ?>
        <div class="alert alert-info mb-6" style="font-size:.875rem">
          <i class="bi bi-wallet2"></i>
          <?= (float)$dt['credit_diff'] > 0 ? 'دریافت می‌کنید' : 'پرداخت کردید' ?>
          <strong><?= fmt_credit(abs((float)$dt['credit_diff'])) ?></strong>
          در این معامله.
        </div>
        <?php endif; ?>

        <!-- Escrow -->
        <?php if (($dt['escrow_status'] ?? 'none') !== 'none'): ?>
        <div class="alert alert-info mb-6">
          <i class="bi bi-shield-lock"></i>
          سپرده: <strong><?= $escrowLabels[$dt['escrow_status']] ?? $dt['escrow_status'] ?></strong>
          — <?= fmt_credit((float)$dt['escrow_amount']) ?> به‌صورت امن نگهداری می‌شود
        </div>
        <?php endif; ?>

        <!-- Progress Steps -->
        <div class="mb-6">
          <h4 class="mb-4" style="font-size:1rem">مراحل معامله</h4>
          <div class="steps">
            <?php
            $steps = [
              ['in_progress', 'ایجاد معامله', true],
              ['contract', 'امضای قرارداد', contract_fully_signed($dt['id'])],
              ['shipping', 'ارسال کالا', (bool)($dt['shipping_method'])],
              ['confirm', 'تأیید دریافت', in_array($dt['status'], ['user_a_confirmed', 'user_b_confirmed', 'completed'], true)],
              ['completed', 'تکمیل', $dt['status'] === 'completed'],
            ];
            $order = ['in_progress'=>0,'contract'=>1,'shipping'=>2,'confirm'=>3,'completed'=>4];
            foreach ($steps as $i => [$sKey, $sName, $completed]):
              $cls = $completed ? 'complete' : '';
            ?>
            <div class="step-item <?= $cls ?>">
              <div class="step-num"><?= $completed ? '✓' : ($i+1) ?></div>
              <div style="font-size:.7rem;color:<?= $cls === 'complete' ? 'var(--success)' : 'var(--text-muted)' ?>;margin-inline-start:4px;white-space:nowrap"><?= $sName ?></div>
              <?php if ($i < 4): ?><div class="step-line"></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Action Cards -->
        <!-- 1. Contract -->
        <?php if (!contract_fully_signed($dt['id'])): ?>
        <div class="card mb-4" style="border:1px solid var(--primary-light)">
          <div class="card-header"><h4 style="margin:0;font-size:.9375rem"><i class="bi bi-file-earmark-text"></i> قرارداد دیجیتال</h4></div>
          <div class="card-body">
            <pre style="white-space:pre-wrap;font-size:.8125rem;background:var(--bg);padding:var(--sp-4);border-radius:var(--radius-md);margin-bottom:var(--sp-4)"><?= h($detailContract['terms'] ?? '') ?></pre>
            <div style="display:flex;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-4)">
              <span class="badge badge-<?= $detailContract['user_a_signed'] ? 'success' : 'warning' ?>">
                طرف الف: <?= $detailContract['user_a_signed'] ? 'امضا شده' : 'در انتظار' ?>
              </span>
              <span class="badge badge-<?= $detailContract['user_b_signed'] ? 'success' : 'warning' ?>">
                طرف ب: <?= $detailContract['user_b_signed'] ? 'امضا شده' : 'در انتظار' ?>
              </span>
            </div>
            <?php if (!$mySigned): ?>
            <form method="POST">
            <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $dt['id'] ?>">
              <input type="hidden" name="action" value="sign_contract">
              <button type="submit" class="btn btn-primary"><i class="bi bi-pen"></i> امضای قرارداد</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- 2. Shipping -->
        <?php if (contract_fully_signed($dt['id']) && $dt['status'] !== 'completed'): ?>
        <div class="card mb-4">
          <div class="card-header"><h4 style="margin:0;font-size:.9375rem"><i class="bi bi-truck"></i> ارسال کالا</h4></div>
          <div class="card-body">
            <form method="POST" class="mb-4">
            <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $dt['id'] ?>">
              <input type="hidden" name="action" value="set_shipping">
              <div class="form-group">
                <label class="form-label">روش ارسال</label>
                <select name="shipping_method" class="form-control">
                  <?php foreach (['in_person','post','tipax','courier'] as $v): ?>
                  <option value="<?= $v ?>" <?= ($dt['shipping_method'] ?? '') === $v ? 'selected' : '' ?>><?= shipping_label($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-outline btn-sm">ذخیره روش</button>
            </form>
            <form method="POST">
            <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $dt['id'] ?>">
              <input type="hidden" name="action" value="update_tracking">
              <div class="form-group">
                <label class="form-label">کد رهگیری شما</label>
                <input type="text" name="tracking_code" class="form-control"
                       value="<?= h($isA ? ($dt['tracking_code_a'] ?? '') : ($dt['tracking_code_b'] ?? '')) ?>"
                       placeholder="کد رهگیری را وارد کنید">
              </div>
              <button type="submit" class="btn btn-outline btn-sm">ذخیره رهگیری</button>
            </form>
            <?php if ($dt['tracking_code_a'] || $dt['tracking_code_b']): ?>
            <div class="fs-sm mt-4" style="color:var(--text-muted)">
              الف: <?= h($dt['tracking_code_a'] ?: '—') ?> · ب: <?= h($dt['tracking_code_b'] ?: '—') ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- 3. Confirm -->
        <?php if (contract_fully_signed($dt['id']) && in_array($dt['status'], ['in_progress','user_a_confirmed'], true)): ?>
        <div style="display:flex;gap:var(--sp-3);flex-wrap:wrap">
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="trade_id" value="<?= $dt['id'] ?>">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check-circle"></i> تأیید دریافت معامله
            </button>
          </form>
          <a href="<?= APP_URL ?>/messages?to=<?= $isA ? $dt['user_b_id'] : $dt['user_a_id'] ?>" class="btn btn-outline">پیام</a>
        </div>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs mb-6">
      <button class="tab-btn <?= $tab === 'active' ? 'active' : '' ?>" onclick="switchTab('active')">
        <i class="bi bi-arrow-left-right"></i> معاملات فعال
        <?php if (count($activeTrades) > 0): ?><span class="badge badge-warning" style="margin-inline-start:6px"><?= count($activeTrades) ?></span><?php endif; ?>
      </button>
      <button class="tab-btn <?= $tab === 'sent' ? 'active' : '' ?>" onclick="switchTab('sent')">
        <i class="bi bi-send"></i> پیشنهادهای ارسالی
        <?php $pending = array_filter($sentOffers, fn($o) => $o['status'] === 'pending'); ?>
        <?php if (count($pending) > 0): ?><span class="badge badge-info" style="margin-inline-start:6px"><?= count($pending) ?></span><?php endif; ?>
      </button>
      <button class="tab-btn <?= $tab === 'completed' ? 'active' : '' ?>" onclick="switchTab('completed')">
        <i class="bi bi-check-circle"></i> تکمیل‌شده
      </button>
    </div>

    <!-- ── Active Trades ────────────────────────────────────────── -->
    <div class="tab-panel <?= $tab === 'active' ? 'active' : '' ?>" id="panel-active">
      <?php if (empty($activeTrades)): ?>
      <div class="empty-state">
        <i class="bi bi-arrow-left-right"></i>
        <h3>معامله فعالی نیست</h3>
        <p>یک پیشنهاد بپذیرید یا ارسال کنید تا معامله را شروع کنید!</p>
        <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور آگهی‌ها</a>
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
        <?php foreach ($activeTrades as $trade):
          $isA = $trade['user_a_id'] == $uid;
          $partner = $isA ? $trade['user_b_name'] : $trade['user_a_name'];
          $myItem  = $isA ? $trade['listing_a_title'] : ($trade['listing_b_title'] ?? 'نامشخص');
          $theirItem = $isA ? ($trade['listing_b_title'] ?? 'پیشنهاد اعتباری') : $trade['listing_a_title'];

          $canConfirm = ($trade['status'] === 'in_progress')
            || ($trade['status'] === 'user_a_confirmed' && !$isA);

          $statusMap = [
            'in_progress'       => ['warning',  'در جریان — در انتظار تأیید'],
            'user_a_confirmed'  => ['info',     $isA ? 'شما تأیید کردید — منتظر طرف مقابل' : 'طرف مقابل تأیید کرد — نوبت شماست'],
            'user_b_confirmed'  => ['success',  'هر دو تأیید کردند!'],
            'disputed'          => ['danger',   'اختلاف — در حال بررسی'],
          ];
          [$sColor, $sLabel] = $statusMap[$trade['status']] ?? ['info', ucfirst($trade['status'])];
        ?>
        <div class="card">
          <div class="card-header">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-3)">
              <div>
                <span class="fw-700">معامله #<?= $trade['id'] ?></span>
                <span style="margin:0 var(--sp-2);color:var(--text-muted)">با</span>
                <span class="fw-600"><?= h($partner) ?></span>
              </div>
              <span class="badge badge-<?= $sColor ?>"><?= $sLabel ?></span>
            </div>
          </div>
          <div class="card-body">

            <!-- Trade Visual -->
            <div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap">
              <div style="flex:1;min-width:150px;background:rgba(0,174,239,.04);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-4);text-align:center">
                <div class="fs-xs mb-2" style="color:var(--text-muted)">کالای شما</div>
                <div style="font-weight:600;font-size:.9375rem"><?= h($myItem) ?></div>
              </div>
              <div style="font-size:1.5rem;color:var(--accent-dark);font-weight:700">⇄</div>
              <div style="flex:1;min-width:150px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:var(--sp-4);text-align:center">
                <div class="fs-xs mb-2" style="color:var(--text-muted)">کالای طرف مقابل</div>
                <div style="font-weight:600;font-size:.9375rem"><?= h($theirItem) ?></div>
              </div>
            </div>

            <?php if ((float)$trade['credit_diff'] != 0): ?>
            <div class="alert alert-info mt-4" style="font-size:.875rem">
              <i class="bi bi-wallet2"></i>
              <?= (float)$trade['credit_diff'] > 0 ? 'دریافت می‌کنید' : 'پرداخت کردید' ?>
              <strong><?= fmt_credit(abs((float)$trade['credit_diff'])) ?></strong>
              در این معامله.
            </div>
            <?php endif; ?>

            <!-- Progress Bar -->
            <div style="margin-top:var(--sp-5)">
              <div class="steps" style="margin-bottom:var(--sp-3)">
                <?php
                $steps = [
                  ['in_progress', 'ایجاد معامله'],
                  ['user_a_confirmed', 'اولین تأیید'],
                  ['user_b_confirmed', 'تأیید هر دو'],
                  ['completed', 'تکمیل'],
                ];
                $order = ['in_progress'=>0,'user_a_confirmed'=>1,'user_b_confirmed'=>2,'completed'=>3,'disputed'=>-1];
                $cur   = $order[$trade['status']] ?? 0;
                foreach ($steps as $i => [$sKey, $sName]):
                  $cls = $i < $cur ? 'complete' : ($i === $cur ? 'active' : '');
                ?>
                <div class="step-item <?= $cls ?>">
                  <div class="step-num"><?= $i < $cur ? '✓' : ($i+1) ?></div>
                  <div style="font-size:.7rem;color:<?= $cls === 'active' ? 'var(--primary)' : 'var(--text-muted)' ?>;margin-inline-start:4px;white-space:nowrap"><?= $sName ?></div>
                  <?php if ($i < 3): ?><div class="step-line"></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

          </div>
          <div class="card-footer" style="display:flex;gap:var(--sp-3);flex-wrap:wrap">
            <?php if ($canConfirm): ?>
            <form method="POST">
            <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $trade['id'] ?>">
              <input type="hidden" name="action" value="confirm">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle"></i> تأیید دریافت معامله
              </button>
            </form>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/messages?to=<?= $isA ? $trade['user_b_id'] : $trade['user_a_id'] ?>"
               class="btn btn-outline btn-sm">
              <i class="bi bi-chat"></i> پیام
            </a>
            <a href="<?= APP_URL ?>/trades?trade=<?= $trade['id'] ?>" class="btn btn-accent btn-sm">
              <i class="bi bi-gear"></i> مدیریت
            </a>
            <?php if (($trade['escrow_status'] ?? 'none') === 'held'): ?>
            <span class="badge badge-info"><i class="bi bi-shield-lock"></i> سپرده</span>
            <?php endif; ?>
            <?php if ($trade['status'] !== 'disputed'): ?>
            <form method="POST" onsubmit="return confirm('اختلاف ثبت شود؟ تیم ظرف ۲۴ ساعت بررسی می‌کند.')">
            <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $trade['id'] ?>">
              <input type="hidden" name="action"   value="dispute">
              <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)">
                <i class="bi bi-flag"></i> اختلاف
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Sent Offers ──────────────────────────────────────────── -->
    <div class="tab-panel <?= $tab === 'sent' ? 'active' : '' ?>" id="panel-sent">
      <?php if (empty($sentOffers)): ?>
      <div class="empty-state">
        <i class="bi bi-send"></i>
        <h3>هنوز پیشنهادی ارسال نکرده‌اید</h3>
        <p>آگهی‌ها را مرور کنید و پیشنهاد بفرستید!</p>
        <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور آگهی‌ها</a>
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
        <?php foreach ($sentOffers as $offer):
          $statusColors = ['pending'=>'warning','accepted'=>'success','rejected'=>'danger','cancelled'=>'info','completed'=>'success'];
          $sc = $statusColors[$offer['status']] ?? 'info';
        ?>
        <div class="card">
          <div class="card-body">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">
              <div style="flex:1">
                <div class="fs-sm" style="color:var(--text-muted)">پیشنهاد شما برای:</div>
                <div style="font-weight:700;font-size:1rem;margin-bottom:var(--sp-2)">
                  <a href="<?= APP_URL ?>/listings/view.php?id=<?= $offer['listing_id'] ?>"><?= h($offer['listing_title']) ?></a>
                </div>
                <div style="font-size:.875rem;color:var(--text-secondary)">
                  فروشنده: <strong><?= h($offer['seller_name']) ?></strong>
                </div>
                <?php if ($offer['my_listing_title']): ?>
                <div class="fs-sm mt-2" style="color:var(--text-secondary)">
                  <i class="bi bi-box"></i> پیشنهاد شده: <?= h($offer['my_listing_title']) ?>
                </div>
                <?php endif; ?>
                <?php if ((float)$offer['offer_credit'] > 0): ?>
                <div class="fs-sm mt-1" style="color:var(--primary)">
                  <i class="bi bi-wallet2"></i> + <?= fmt_credit((float)$offer['offer_credit']) ?>
                </div>
                <?php endif; ?>
                <div class="fs-xs mt-2" style="color:var(--text-muted)">
                  <?= persian_date($offer['created_at']) ?>
                </div>
              </div>
              <span class="badge badge-<?= $sc ?>" style="font-size:.875rem"><?= offer_status_label($offer['status']) ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Completed Trades ─────────────────────────────────────── -->
    <div class="tab-panel <?= $tab === 'completed' ? 'active' : '' ?>" id="panel-completed">
      <?php if (empty($completedTrades)): ?>
      <div class="empty-state">
        <i class="bi bi-check-circle"></i>
        <h3>هنوز معامله تکمیل‌شده‌ای نیست</h3>
        <p>تاریخچه معاملات شما اینجا نمایش داده می‌شود.</p>
      </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
        <?php foreach ($completedTrades as $trade):
          $isA = $trade['user_a_id'] == $uid;
          $partner   = $isA ? $trade['user_b_name'] : $trade['user_a_name'];
          $partnerId = $isA ? $trade['user_b_id']   : $trade['user_a_id'];
        ?>
        <div class="card">
          <div class="card-body">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-3)">
              <div>
                <span class="badge badge-success mb-2"><i class="bi bi-check-circle"></i> تکمیل‌شده</span>
                <div class="fw-700">معامله #<?= $trade['id'] ?> با <?= h($partner) ?></div>
                <div class="fs-sm" style="color:var(--text-muted)">
                  <?= $trade['completed_at'] ? persian_date($trade['completed_at']) : '' ?>
                </div>
              </div>
              <?php if (!$trade['my_rating']): ?>
              <a href="<?= APP_URL ?>/trades.php?review=<?= $trade['id'] ?>&for=<?= $partnerId ?>"
                 class="btn btn-outline btn-sm">
                <i class="bi bi-star"></i> ثبت نظر
              </a>
              <?php else: ?>
              <div style="color:var(--accent-dark)">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                <i class="bi bi-star<?= $s <= $trade['my_rating'] ? '-fill' : '' ?>"></i>
                <?php endfor; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Review Modal ─────────────────────────────────────────── -->
    <?php if (isset($_GET['review']) && isset($_GET['for'])): ?>
    <?php
    $reviewTradeId = (int)$_GET['review'];
    $reviewForId   = (int)$_GET['for'];
    $reviewFor     = DB::fetch('SELECT name FROM users WHERE id = ?', [$reviewForId]);
    ?>
    <div class="modal-overlay show" id="review-modal">
      <div class="modal-box">
        <div class="modal-header">
          <h3 style="margin:0">ثبت نظر</h3>
          <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('show')">&times;</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/api/review.php">
        <?= csrf_field() ?>
          <div class="modal-body">
            <p class="mb-4">معامله با <strong><?= h($reviewFor['name'] ?? '') ?></strong> چطور بود؟</p>
            <input type="hidden" name="trade_id"  value="<?= $reviewTradeId ?>">
            <input type="hidden" name="to_user_id" value="<?= $reviewForId ?>">
            <div class="form-group">
              <label class="form-label">امتیاز</label>
              <div style="display:flex;gap:var(--sp-3);font-size:2rem" id="star-picker">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                <label style="cursor:pointer">
                  <input type="radio" name="rating" value="<?= $s ?>" style="display:none">
                  <i class="bi bi-star" style="color:var(--border)" id="star-<?= $s ?>"></i>
                </label>
                <?php endfor; ?>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">نظر (اختیاری)</label>
              <textarea class="form-control" name="comment" rows="3" placeholder="تجربه خود را بنویسید…"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('review-modal').classList.remove('show')">انصراف</button>
            <button type="submit" class="btn btn-primary">ثبت نظر</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    document.querySelectorAll('#star-picker input[type=radio]').forEach((r, i) => {
      r.addEventListener('change', () => {
        document.querySelectorAll('#star-picker i').forEach((star, j) => {
          star.className = 'bi bi-star' + (j <= i ? '-fill' : '');
          star.style.color = j <= i ? 'var(--accent)' : 'var(--border)';
        });
      });
    });
    </script>
    <?php endif; ?>

  </div>
</div>

<script>
function switchTab(t) {
  document.querySelectorAll('.tab-btn').forEach((b, i) => {
    b.classList.toggle('active', ['active','sent','completed'][i] === t);
  });
  document.querySelectorAll('.tab-panel').forEach((p, i) => {
    p.classList.toggle('active', ['panel-active','panel-sent','panel-completed'][i] === 'panel-' + t);
  });
  history.replaceState(null,'','?tab='+t);
}
</script>

<?php render_footer(); ?>
