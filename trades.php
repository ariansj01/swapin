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
      <a href="?tab=completed" class="btn <?= $tab === 'completed' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:999px">
        تکمیل شده
      </a>
      <a href="?tab=offers" class="btn <?= $tab === 'offers' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:999px">
        پیشنهادهای ارسالی
      </a>
    </div>

    <!-- Detail Trade View -->
    <?php if ($detailTrade):
      $isA = (int)$detailTrade['user_a_id'] === $uid;
      $otherName = $isA ? $detailTrade['user_b_name'] : $detailTrade['user_a_name'];
      $otherId = $isA ? $detailTrade['user_b_id'] : $detailTrade['user_a_id'];
      $myItem = $isA ? $detailTrade['listing_a_title'] : $detailTrade['listing_b_title'];
      $otherItem = $isA ? $detailTrade['listing_b_title'] : $detailTrade['listing_a_title'];
    ?>
    <div class="card mb-6" style="border-right:4px solid var(--primary)">
      <div class="card-body">
        <div class="mb-4" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
          <h3 style="margin:0">معامله #<?= $detailTrade['id'] ?> با <?= h($otherName) ?></h3>
          <span class="badge badge-<?= str_contains($detailTrade['status'], 'confirmed') || $detailTrade['status'] === 'completed' ? 'success' : ($detailTrade['status'] === 'disputed' ? 'danger' : 'warning') ?>">
            <?= trade_status_label($detailTrade['status']) ?>
          </span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:2rem">
          <div class="card" style="margin:0;background:rgba(0,174,239,.04);border-color:rgba(0,174,239,.15)">
            <div class="card-body">
              <div class="fs-xs" style="color:var(--text-muted);margin-bottom:.5rem">من</div>
              <div style="font-weight:700"><?= h($myItem ?: '—') ?></div>
            </div>
          </div>
          <div class="card" style="margin:0;background:rgba(113,78,255,.04);border-color:rgba(113,78,255,.15)">
            <div class="card-body">
              <div class="fs-xs" style="color:var(--text-muted);margin-bottom:.5rem"><?= h($otherName) ?></div>
              <div style="font-weight:700"><?= h($otherItem ?: '—') ?></div>
            </div>
          </div>
        </div>

        <?php if ($detailTrade['credit_diff'] != 0):
          $iPay = ($isA && $detailTrade['credit_diff'] < 0) || (!$isA && $detailTrade['credit_diff'] > 0);
        ?>
        <div class="alert alert-<?= $iPay ? 'warning' : 'success' ?> mb-5">
          <i class="bi bi-wallet2"></i>
          <?php if ($iPay): ?>شما باید مبلغ <?= fmt_credit(abs((float)$detailTrade['credit_diff'])) ?> را پرداخت کنید<?php else: ?>شما مبلغ <?= fmt_credit(abs((float)$detailTrade['credit_diff'])) ?> را دریافت می‌کنید<?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($detailTrade['status'] !== 'completed'): ?>
        <div style="display:flex;gap:1rem;flex-wrap:wrap">
          <a href="<?= APP_URL ?>/messages.php?to=<?= $otherId ?>" class="btn btn-primary">
            <i class="bi bi-chat"></i> چت با <?= h($otherName) ?>
          </a>
          <?php if ($detailContract && !contract_fully_signed($detailTradeId)):
            $iSigned = ($isA && $detailContract['user_a_signed_at']) || (!$isA && $detailContract['user_b_signed_at']);
          ?>
            <?php if (!$iSigned): ?>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="trade_id" value="<?= $detailTrade['id'] ?>">
              <input type="hidden" name="action" value="sign_contract">
              <button type="submit" class="btn btn-outline">
                <i class="bi bi-pencil-square"></i> امضای قرارداد
              </button>
            </form>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (in_array($detailTrade['status'], ['in_progress', 'user_a_confirmed', 'user_b_confirmed'], true)): ?>
          <form method="POST" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="trade_id" value="<?= $detailTrade['id'] ?>">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-circle"></i> تأیید دریافت کالا
            </button>
          </form>
          <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#dispute-modal">
            <i class="bi bi-x-circle"></i> گزارش اختلاف
          </button>
          <?php endif; ?>
        </div>

        <!-- Dispute Modal -->
        <div class="modal fade" id="dispute-modal" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">گزارش اختلاف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="trade_id" value="<?= $detailTrade['id'] ?>">
                <input type="hidden" name="action" value="dispute">
                <div class="modal-body">
                  <div class="form-group mb-3">
                    <label class="form-label">دلیل</label>
                    <select name="dispute_reason" class="form-select">
                      <option value="wrong_item">کالا اشتباه</option>
                      <option value="damaged">آسیب‌دیده</option>
                      <option value="missing">موجود نیست</option>
                      <option value="fraud">کلاهبرداری</option>
                      <option value="other" selected>دیگری</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="form-label">توضیحات</label>
                    <textarea name="dispute_desc" class="form-control" rows="3" required></textarea>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">انصراف</button>
                  <button type="submit" class="btn btn-danger">ثبت گزارش</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <?php else: ?>
          <!-- Completed: Show rating button -->
          <?php
            $myRating = DB::fetch(
                'SELECT * FROM reviews WHERE trade_id = ? AND from_user_id = ?',
                [$detailTradeId, $uid]
            );
          ?>
          <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
            <?php if (!$myRating): ?>
              <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rating-modal" data-trade="<?= $detailTradeId ?>" data-other="<?= $otherId ?>">
                <i class="bi bi-star"></i> امتیازدهی
              </button>
            <?php else: ?>
              <div style="display:flex;align-items:center;gap:.5rem;color:var(--text-muted)">
                <i class="bi bi-check-circle-fill" style="color:var(--success)"></i>
                شما امتیاز <?= (int)$myRating['rating'] ?> دادید
              </div>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/messages.php?to=<?= $otherId ?>" class="btn btn-outline">
              <i class="bi bi-chat"></i> چت
            </a>
          </div>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

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
          <a href="?tab=active&trade=<?= $t['id'] ?>" class="card" style="text-decoration:none;color:inherit">
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
          <a href="?tab=completed&trade=<?= $t['id'] ?>" class="card" style="text-decoration:none;color:inherit">
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

    <?php else: ?>
      <!-- Offers -->
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
              <?php if ($o['status'] === 'accepted'): ?>
                <div class="mt-3">
                  <a href="<?= APP_URL ?>/trades.php?trade=" class="btn btn-primary btn-sm">مشاهده معامله</a>
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
