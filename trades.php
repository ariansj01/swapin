<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$uid  = $user['id'];

$tab = clean($_GET['tab'] ?? 'active');
if ($tab === 'sent') {
    $tab = 'offers';
}
$success = '';
$error   = '';

// Handle incoming offer actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_id'])) {
    csrf_verify_or_fail();
    $offerId = (int)$_POST['offer_id'];
    $action  = clean($_POST['action'] ?? '');
    $message = clean($_POST['message'] ?? '');

    if ($offerId && in_array($action, ['accept', 'reject'], true)) {
        $offer = DB::fetch(
            'SELECT o.*, l.user_id AS listing_owner
             FROM trade_offers o
             JOIN listings l ON l.id = o.listing_id
             WHERE o.id = ? AND l.user_id = ? AND o.status = "pending"',
            [$offerId, $uid]
        );

        if (!$offer) {
            $error = 'پیشنهاد یافت نشد یا دسترسی ندارید.';
        } elseif ($action === 'accept') {
            if (empty($message)) {
                $error = 'لطفاً پیامی برای طرفین بنویسید.';
            } else {
                $result = accept_trade_offer($offerId, $uid, $message);
                if (isset($result['error'])) {
                    $error = $result['error'];
                } else {
                    header('Location: ' . APP_URL . '/trades/view.php?id=' . $result['trade_id'] . '&accepted=1');
                    exit;
                }
            }
        } elseif ($action === 'reject') {
            if (empty($message)) {
                $error = 'لطفاً پیامی برای طرفین بنویسید.';
            } else {
                DB::query('UPDATE trade_offers SET status = "rejected" WHERE id = ?', [$offerId]);
                DB::insert('messages', [
                    'thread_id'    => 'offer_reject_' . $offerId,
                    'from_user_id' => $uid,
                    'to_user_id'   => $offer['from_user_id'],
                    'offer_id'     => $offerId,
                    'body'         => $message,
                ]);
                $success = 'پیشنهاد رد شد.';
            }
        }
    }
}

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
                    if (isset($result['user_id']) && (int)$result['user_id'] === $uid) {
                        header('Location: ' . WALLET_TOPUP_URL . '?amount=' . ($result['required_amount'] ?? 0));
                        exit;
                    } else {
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
$detailBnpl     = null;
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
        $detailBnpl = null;
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
            ol.title AS my_listing_title,
            (SELECT t.id FROM trades t WHERE t.offer_id = o.id LIMIT 1) AS trade_id
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = l.user_id
     LEFT JOIN listings ol ON ol.id = o.offer_listing_id
     WHERE o.from_user_id = ?
     ORDER BY o.created_at DESC',
    [$uid]
);

// Received offers (on my listings)
$receivedOffers = DB::fetchAll(
    'SELECT o.*, l.title AS listing_title, l.id AS listing_id_v,
            u.name AS from_name, u.rating AS from_rating,
            ol.title AS offer_listing_title, ol.id AS offer_listing_id_v,
            (SELECT filename FROM listing_images WHERE listing_id=ol.id AND is_primary=1 LIMIT 1) AS offer_listing_thumb,
            (SELECT t.id FROM trades t WHERE t.offer_id = o.id LIMIT 1) AS trade_id
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = o.from_user_id
     LEFT JOIN listings ol ON ol.id = o.offer_listing_id
     WHERE l.user_id = ?
     ORDER BY o.status = "pending" DESC, o.created_at DESC',
    [$uid]
);

$pendingReceivedCount = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "pending"',
    [$uid]
)['c'] ?? 0);

render_head('معاملات من', '', ['canonical' => APP_URL . '/trades']);
render_navbar($user);
?>
<style>
.rating-stars { display:flex;gap:.5rem;justify-content:center; }
.rating-stars i { font-size:2rem;color:#ddd;cursor:pointer;transition:color .2s,transform .1s; }
.rating-stars i.active, .rating-stars i:hover { color:var(--accent-dark);transform:scale(1.1); }
.rating-stars i:hover ~ i { color:#ddd; }
.rating-stars:hover i { color:var(--accent-dark); }
</style>

<div class="section-sm">
  <div class="container-md">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/dashboard.php" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به داشبورد
      </a>
      <h2 class="mt-3">معاملات من</h2>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <div class="mb-6" style="display:flex;gap:.5rem;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:.5rem">
      <a href="?tab=active" class="btn <?= $tab === 'active' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:999px">
        معاملات فعال
      </a>
      <a href="?tab=received" class="btn <?= $tab === 'received' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:999px">
        پیشنهادهای دریافتی
        <?php if ($pendingReceivedCount > 0): ?>
        <span class="badge badge-warning" style="margin-inline-start:4px"><?= $pendingReceivedCount ?></span>
        <?php endif; ?>
      </a>
      <a href="?tab=completed" class="btn <?= $tab === 'completed' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:999px">
        تکمیل شده
      </a>
      <a href="?tab=offers" class="btn <?= $tab === 'offers' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:999px">
        پیشنهادهای ارسالی
      </a>
    </div>



    <!-- List of trades -->
    <?php if ($tab === 'active'): ?>
      <?php if (empty($activeTrades)): ?>
        <div class="empty-state">
          <i class="bi bi-inbox"></i>
          <h3>معامله فعالی نیست</h3>
          <p>وقتی پیشنهادی را بپذیرید یا پیشنهاد شما پذیرفته شود، اینجا نمایش داده می‌شود.</p>
          <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور آگهی‌ها</a>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:1rem">
          <?php foreach ($activeTrades as $t):
            $isA = (int)$t['user_a_id'] === $uid;
            $otherName = $isA ? $t['user_b_name'] : $t['user_a_name'];
            $otherId = $isA ? $t['user_b_id'] : $t['user_a_id'];
          ?>
          <a href="<?= APP_URL ?>/trades/view.php?id=<?= $t['id'] ?>" class="card" style="text-decoration:none;color:inherit">
            <div class="card-body" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
              <?php if ($t['la_thumb']): ?>
              <img src="<?= UPLOAD_URL . h($t['la_thumb']) ?>" alt="" style="width:60px;height:60px;border-radius:12px;object-fit:cover">
              <?php endif; ?>
              <div style="flex:1;min-width:0">
                <div style="font-weight:700">معامله با <?= h($otherName) ?></div>
                <div class="fs-sm" style="color:var(--text-muted)"><?= h($t['listing_a_title']) ?></div>
              </div>
              <span class="badge badge-<?= str_contains($t['status'], 'confirmed') ? 'success' : ($t['status'] === 'disputed' ? 'danger' : 'warning') ?>">
                <?= trade_status_label($t['status']) ?>
              </span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'completed'): ?>
      <?php if (empty($completedTrades)): ?>
        <div class="empty-state">
          <i class="bi bi-inbox"></i>
          <h3>معامله تکمیل‌شده‌ای نیست</h3>
          <p>وقتی معاملات تکمیل شوند، اینجا نمایش داده می‌شوند.</p>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:1rem">
          <?php foreach ($completedTrades as $t):
            $isA = (int)$t['user_a_id'] === $uid;
            $otherName = $isA ? $t['user_b_name'] : $t['user_a_name'];
            $otherId = $isA ? $t['user_b_id'] : $t['user_a_id'];
            $myRating = $t['my_rating'];
          ?>
          <a href="<?= APP_URL ?>/trades/view.php?id=<?= $t['id'] ?>" class="card" style="text-decoration:none;color:inherit">
            <div class="card-body" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
              <div style="flex:1;min-width:0">
                <div style="font-weight:700">معامله با <?= h($otherName) ?></div>
                <div class="fs-sm" style="color:var(--text-muted)"><?= h($t['listing_a_title']) ?></div>
              </div>
              <div style="display:flex;align-items:center;gap:.5rem">
                <?php if ($myRating): ?>
                  <span style="color:var(--accent-dark)"><i class="bi bi-star-fill"></i> <?= (int)$myRating ?></span>
                <?php else: ?>
                  <span class="badge badge-ghost">بدون امتیاز</span>
                <?php endif; ?>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'received'): ?>
      <?php if (empty($receivedOffers)): ?>
        <div class="empty-state">
          <i class="bi bi-inbox"></i>
          <h3>هنوز پیشنهادی نیست</h3>
          <p>وقتی دیگران برای آگهی‌های شما پیشنهاد بدهند، اینجا نمایش داده می‌شود.</p>
          <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور آگهی‌ها</a>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
          <?php foreach ($receivedOffers as $offer):
            $statusColors = ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', 'cancelled' => 'info', 'completed' => 'success'];
            $statusColor  = $statusColors[$offer['status']] ?? 'info';
          ?>
          <div class="card" style="<?= $offer['status'] === 'pending' ? 'border-inline-start:4px solid var(--warning)' : '' ?>">
            <div class="card-body">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">
                <div style="flex:1;min-width:0">
                  <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                    <div class="avatar avatar-md"><?= strtoupper(substr($offer['from_name'], 0, 1)) ?></div>
                    <div>
                      <div style="font-weight:700;font-size:1.0625rem"><?= h($offer['from_name']) ?></div>
                      <?php if ($offer['from_rating'] > 0): ?>
                      <div class="fs-xs" style="color:var(--accent-dark)">
                        <i class="bi bi-star-fill"></i> <?= number_format((float)$offer['from_rating'], 1) ?>
                      </div>
                      <?php endif; ?>
                    </div>
                    <span class="badge badge-<?= $statusColor ?> fs-xs" style="margin-inline-start:auto"><?= offer_status_label($offer['status']) ?></span>
                  </div>

                  <div class="fs-sm mb-2" style="color:var(--text-muted)">
                    برای: <strong><?= h($offer['listing_title'] ?? '') ?></strong>
                  </div>

                  <?php if ($offer['offer_listing_title'] || (float)$offer['offer_credit'] > 0): ?>
                  <div style="background:rgba(0,174,239,.04);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-4);margin-bottom:var(--sp-3)">
                    <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-2)">پیشنهاد:</div>
                    <?php if ($offer['offer_listing_title']): ?>
                    <div style="display:flex;align-items:center;gap:var(--sp-3)">
                      <?php if ($offer['offer_listing_thumb'] ?? false): ?>
                      <img src="<?= UPLOAD_URL . h($offer['offer_listing_thumb']) ?>" alt="<?= h($offer['offer_listing_title']) ?>"
                           style="width:60px;height:60px;border-radius:var(--radius-md);object-fit:cover">
                      <?php endif; ?>
                      <div style="font-weight:600"><i class="bi bi-box"></i> <?= h($offer['offer_listing_title']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ((float)$offer['offer_credit'] > 0): ?>
                    <div class="fs-md mt-2" style="color:var(--primary);font-weight:700">
                      <i class="bi bi-wallet2"></i> + <?= fmt_credit((float)$offer['offer_credit']) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>

                  <?php if ($offer['message']): ?>
                  <div style="background:var(--bg);border-radius:var(--radius-md);padding:var(--sp-4);font-size:.9375rem;color:var(--text-secondary)">
                    <div class="fs-xs mb-2" style="color:var(--text-muted)">پیام پیشنهاد‌دهنده:</div>
                    "<?= h($offer['message']) ?>"
                  </div>
                  <?php endif; ?>

                  <div class="fs-xs mt-4" style="color:var(--text-muted)">
                    <i class="bi bi-clock"></i> <?= persian_datetime($offer['created_at']) ?>
                  </div>
                </div>

                <?php if ($offer['status'] === 'pending'): ?>
                <div style="width:100%;min-width:280px;max-width:420px">
                  <form method="POST" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="accept">
                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                    <div class="form-group">
                      <label class="form-label">پیام پذیرش:</label>
                      <textarea name="message" class="form-control" rows="2" required placeholder="مثلاً: سلام! پیشنهاد شما را می‌پذیرم."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                      <i class="bi bi-check-lg"></i> پذیرش و ورود به اتاق امن
                    </button>
                  </form>
                  <form method="POST" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                    <div class="form-group">
                      <label class="form-label">پیام رد:</label>
                      <textarea name="message" class="form-control" rows="2" required placeholder="مثلاً: متشکرم، اما این بار نمی‌تونم."></textarea>
                    </div>
                    <button type="submit" class="btn btn-ghost w-100" style="color:var(--danger)">
                      <i class="bi bi-x-lg"></i> رد پیشنهاد
                    </button>
                  </form>
                </div>
                <?php elseif ($offer['status'] === 'accepted' && $offer['trade_id']): ?>
                <div style="width:100%">
                  <a href="<?= APP_URL ?>/trades/view.php?id=<?= (int)$offer['trade_id'] ?>" class="btn btn-primary w-100">
                    <i class="bi bi-shield-lock"></i> ورود به اتاق امن
                  </a>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'offers'): ?>
      <!-- Sent offers -->
      <?php if (empty($sentOffers)): ?>
        <div class="empty-state">
          <i class="bi bi-inbox"></i>
          <h3>پیشنهادی ارسال نکرده‌اید</h3>
          <p>وقتی برای آگهی‌ها پیشنهاد ارسال کنید، اینجا نمایش داده می‌شوند.</p>
          <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور آگهی‌ها</a>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:1rem">
          <?php foreach ($sentOffers as $o):
            $statusColors = ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', 'cancelled' => 'info', 'completed' => 'success'];
            $statusColor  = $statusColors[$o['status']] ?? 'info';
          ?>
          <div class="card">
            <div class="card-body">
              <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:space-between">
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700">پیشنهاد برای: <?= h($o['listing_title']) ?></div>
                  <div class="fs-sm" style="color:var(--text-muted)">برای <?= h($o['seller_name']) ?></div>
                </div>
                <span class="badge badge-<?= $statusColor ?>"><?= offer_status_label($o['status']) ?></span>
              </div>
              <?php if ($o['status'] === 'accepted' && $o['trade_id']): ?>
                <div class="mt-3">
                  <a href="<?= APP_URL ?>/trades/view.php?id=<?= (int)$o['trade_id'] ?>" class="btn btn-primary btn-sm">ورود به اتاق امن</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</div>

<!-- Rating Modal -->
<div class="modal fade" id="rating-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">امتیازدهی</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="rating-form" method="POST" action="<?= APP_URL ?>/api/review.php">
        <?= csrf_field() ?>
        <input type="hidden" name="trade_id" id="rating-trade-id">
        <input type="hidden" name="to_user_id" id="rating-to-user-id">
        <div class="modal-body">
          <div class="mb-4">
            <label class="form-label" style="display:block;text-align:center;margin-bottom:.75rem;font-weight:700">امتیاز به معامله</label>
            <div class="rating-stars" data-target="trade_rating">
              <i class="bi bi-star" data-value="1"></i>
              <i class="bi bi-star" data-value="2"></i>
              <i class="bi bi-star" data-value="3"></i>
              <i class="bi bi-star" data-value="4"></i>
              <i class="bi bi-star" data-value="5"></i>
            </div>
            <input type="hidden" name="trade_rating" id="trade_rating" value="0">
          </div>
          <div class="mb-4">
            <label class="form-label" style="display:block;text-align:center;margin-bottom:.75rem;font-weight:700">امتیاز به طرف مقابل</label>
            <div class="rating-stars" data-target="user_rating">
              <i class="bi bi-star" data-value="1"></i>
              <i class="bi bi-star" data-value="2"></i>
              <i class="bi bi-star" data-value="3"></i>
              <i class="bi bi-star" data-value="4"></i>
              <i class="bi bi-star" data-value="5"></i>
            </div>
            <input type="hidden" name="user_rating" id="user_rating" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">نظر (اختیاری)</label>
            <textarea class="form-control" name="comment" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">انصراف</button>
          <button type="submit" class="btn btn-primary">ثبت امتیاز</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Set rating fields when modal opens
  document.getElementById('rating-modal').addEventListener('show.bs.modal', (e) => {
    const btn = e.relatedTarget;
    document.getElementById('rating-trade-id').value = btn.dataset.trade;
    document.getElementById('rating-to-user-id').value = btn.dataset.other;
  });

  // Rating stars
  document.querySelectorAll('.rating-stars').forEach(container => {
    const target = container.dataset.target;
    const stars = container.querySelectorAll('i');
    const input = document.getElementById(target);

    stars.forEach(star => {
      star.addEventListener('click', () => {
        const val = parseInt(star.dataset.value);
        input.value = val;
        updateStars(stars, val);
      });
      star.addEventListener('mouseover', () => updateStars(stars, parseInt(star.dataset.value)));
    });
    container.addEventListener('mouseleave', () => updateStars(stars, parseInt(input.value || '0')));
  });

  function updateStars(stars, value) {
    stars.forEach(star => {
      const starVal = parseInt(star.dataset.value);
      star.classList.toggle('active', starVal <= value);
    });
  }
});
</script>

<?php render_footer(); ?>
