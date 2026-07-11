<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = $user['id'];

$listingId = (int)($_GET['id'] ?? 0);
$success   = '';
$error     = '';

// Handle quick accept/reject removed — POST + CSRF only

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $offerId = (int)($_POST['offer_id'] ?? 0);
    $action  = clean($_POST['action'] ?? '');

    if ($offerId && in_array($action, ['accept', 'reject'], true)) {
        // Verify this offer belongs to user's listing
        $offer = DB::fetch(
            'SELECT o.*, l.user_id AS listing_owner, l.title AS listing_title
             FROM trade_offers o
             JOIN listings l ON l.id = o.listing_id
             WHERE o.id = ? AND l.user_id = ? AND o.status = "pending"',
            [$offerId, $uid]
        );

        if (!$offer) {
            $error = 'پیشنهاد یافت نشد یا دسترسی ندارید.';
        } elseif ($action === 'accept') {
            DB::query('UPDATE trade_offers SET status = "accepted" WHERE id = ?', [$offerId]);

            // Get both listings' estimated values
            $listingA = DB::fetch('SELECT estimated_value FROM listings WHERE id = ?', [$offer['listing_id']]);
            $listingB = $offer['offer_listing_id'] ? DB::fetch('SELECT estimated_value FROM listings WHERE id = ?', [$offer['offer_listing_id']]) : null;
            
            $valueA = (float)($listingA['estimated_value'] ?? 0);
            $valueB = (float)($listingB['estimated_value'] ?? 0);
            
            // Calculate credit difference (positive if user B needs to pay user A)
            $creditDiff = $valueA - $valueB;

            // Create trade record
            $tradeId = DB::insert('trades', [
                'offer_id'     => $offerId,
                'user_a_id'    => $uid,
                'user_b_id'    => $offer['from_user_id'],
                'listing_a_id' => $offer['listing_id'],
                'listing_b_id' => $offer['offer_listing_id'] ?: null,
                'credit_diff'  => $creditDiff,
                'status'       => 'in_progress',
            ]);

            // Escrow hold + contract instead of direct credit transfer
            if ($creditDiff > 0) {
                // User B needs to pay user A
                escrow_hold($tradeId, (int)$offer['from_user_id'], $creditDiff, 'سپرده معامله #' . $tradeId);
            } elseif ($creditDiff < 0) {
                // User A needs to pay user B
                escrow_hold($tradeId, $uid, abs($creditDiff), 'سپرده معامله #' . $tradeId);
            }

            create_trade_contract($tradeId);

            // Notify both users
            $thread = 'trade_' . $tradeId;
            DB::insert('messages', [
                'thread_id'    => $thread,
                'from_user_id' => $uid,
                'to_user_id'   => $offer['from_user_id'],
                'offer_id'     => $offerId,
                'body'         => 'پیشنهاد شما پذیرفته شد! جزئیات معامله را هماهنگ کنیم.',
            ]);

            $success = 'پیشنهاد پذیرفته شد! معامله ایجاد شد.';
            // Mark listing as traded
            DB::query('UPDATE listings SET status = "traded" WHERE id = ?', [$offer['listing_id']]);
            header('Location: ' . APP_URL . '/trades.php?trade=' . $tradeId . '&accepted=1'); exit;

        } elseif ($action === 'reject') {
            DB::query('UPDATE trade_offers SET status = "rejected" WHERE id = ?', [$offerId]);
            DB::insert('messages', [
                'thread_id'    => 'offer_reject_' . $offerId,
                'from_user_id' => $uid,
                'to_user_id'   => $offer['from_user_id'],
                'offer_id'     => $offerId,
                'body'         => 'از پیشنهاد شما متشکرم، اما این بار آن را نپذیرفتم.',
            ]);
            $success = 'پیشنهاد رد شد.';
        }
    }
}

// Fetch listing and its offers
if ($listingId) {
    $listing = DB::fetch(
        'SELECT * FROM listings WHERE id = ? AND user_id = ?', [$listingId, $uid]
    );
    if (!$listing) {
        header('Location: ' . APP_URL . '/listings/my.php'); exit;
    }
    $offers = DB::fetchAll(
        'SELECT o.*, u.name AS from_name, u.rating AS from_rating, u.city AS from_city,
                ol.title AS offer_listing_title, ol.id AS offer_listing_id_v,
                (SELECT filename FROM listing_images WHERE listing_id=ol.id AND is_primary=1 LIMIT 1) AS offer_listing_thumb
         FROM trade_offers o
         JOIN users u ON u.id = o.from_user_id
         LEFT JOIN listings ol ON ol.id = o.offer_listing_id
         WHERE o.listing_id = ?
         ORDER BY o.status = "pending" DESC, o.created_at DESC',
        [$listingId]
    );
} else {
    // All offers across all my listings
    $listing = null;
    $offers  = DB::fetchAll(
        'SELECT o.*, l.title AS listing_title, l.id AS listing_id_v,
                u.name AS from_name, u.rating AS from_rating,
                ol.title AS offer_listing_title
         FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         JOIN users u ON u.id = o.from_user_id
         LEFT JOIN listings ol ON ol.id = o.offer_listing_id
         WHERE l.user_id = ?
         ORDER BY o.status = "pending" DESC, o.created_at DESC',
        [$uid]
    );
}

render_head('پیشنهادهای معامله');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">

    <div class="mb-6">
      <a href="<?= APP_URL ?>/dashboard.php" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به داشبورد
      </a>
      <h2 class="mt-3">
        <?php if ($listing): ?>
        پیشنهادها برای: <?= h($listing['title']) ?>
        <?php else: ?>
        همه پیشنهادهای دریافتی
        <?php endif; ?>
      </h2>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <?php if (empty($offers)): ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <h3>هنوز پیشنهادی نیست</h3>
      <p>وقتی دیگران برای آگهی‌های شما پیشنهاد بدهند، اینجا نمایش داده می‌شود.</p>
      <a href="<?= APP_URL ?>/" class="btn btn-primary">مرور آگهی‌ها</a>
    </div>
    <?php else: ?>

    <div style="display:flex;flex-direction:column;gap:var(--sp-4)">
      <?php foreach ($offers as $offer):
        $statusColors = ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', 'cancelled' => 'info', 'completed' => 'success'];
        $statusColor  = $statusColors[$offer['status']] ?? 'info';
      ?>
      <div class="card <?= $offer['status'] === 'pending' ? '' : '' ?>" style="<?= $offer['status'] === 'pending' ? 'border-inline-start:3px solid var(--warning)' : '' ?>">
        <div class="card-body">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">

            <!-- Offer Left -->
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                <div class="avatar avatar-sm"><?= strtoupper(substr($offer['from_name'], 0, 1)) ?></div>
                <div>
                  <span style="font-weight:700"><?= h($offer['from_name']) ?></span>
                  <?php if ($offer['from_rating'] > 0): ?>
                  <span class="fs-xs" style="color:var(--accent-dark);margin-inline-start:var(--sp-2)">
                    <i class="bi bi-star-fill"></i> <?= number_format((float)$offer['from_rating'], 1) ?>
                  </span>
                  <?php endif; ?>
                </div>
                <span class="badge badge-<?= $statusColor ?>" style="margin-inline-start:auto"><?= offer_status_label($offer['status']) ?></span>
              </div>

              <?php if (!$listing): ?>
              <div class="fs-sm mb-2" style="color:var(--text-muted)">
                برای: <strong><?= h($offer['listing_title'] ?? '') ?></strong>
              </div>
              <?php endif; ?>

              <!-- What they're offering -->
              <div style="background:rgba(0,174,239,.04);border:1px solid rgba(0,174,239,.15);border-radius:var(--radius-md);padding:var(--sp-3) var(--sp-4);margin-bottom:var(--sp-3)">
                <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-1)">پیشنهاد آن‌ها:</div>
                <?php if ($offer['offer_listing_title']): ?>
                <div style="display:flex;align-items:center;gap:var(--sp-2)">
                  <?php if ($offer['offer_listing_thumb'] ?? false): ?>
                  <img src="<?= UPLOAD_URL . h($offer['offer_listing_thumb']) ?>" alt="<?= h($offer['offer_listing_title']) ?>"
                       style="width:36px;height:36px;border-radius:var(--radius-sm);object-fit:cover">
                  <?php endif; ?>
                  <div>
                    <div style="font-weight:600"><i class="bi bi-box"></i> <?= h($offer['offer_listing_title']) ?></div>
                    <?php if ((float)$offer['offer_credit'] > 0): ?>
                    <div class="fs-sm" style="color:var(--primary)">+ <?= fmt_credit((float)$offer['offer_credit']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php elseif ((float)$offer['offer_credit'] > 0): ?>
                <div style="font-weight:700;font-size:1.125rem;color:var(--primary)">
                  <i class="bi bi-wallet2"></i> <?= fmt_credit((float)$offer['offer_credit']) ?>
                </div>
                <?php else: ?>
                <div class="fs-sm" style="color:var(--text-muted)">پیشنهاد مشخصی ثبت نشده</div>
                <?php endif; ?>
              </div>

              <?php if ($offer['message']): ?>
              <div style="background:var(--bg);border-radius:var(--radius-md);padding:var(--sp-3) var(--sp-4);font-size:.875rem;color:var(--text-secondary);font-style:italic">
                "<?= h($offer['message']) ?>"
              </div>
              <?php endif; ?>

              <div class="fs-xs mt-3" style="color:var(--text-muted)">
                <i class="bi bi-clock"></i> <?= persian_datetime($offer['created_at']) ?>
              </div>
            </div>

            <!-- Actions -->
            <?php if ($offer['status'] === 'pending'): ?>
            <div style="display:flex;flex-direction:column;gap:var(--sp-3);min-width:140px">
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action"   value="accept">
                <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                <?php if ($listingId): ?>
                <input type="hidden" name="id" value="<?= $listingId ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-100">
                  <i class="bi bi-check-lg"></i> پذیرش
                </button>
              </form>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action"   value="reject">
                <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                <button type="submit" class="btn btn-ghost w-100" style="color:var(--danger)">
                  <i class="bi bi-x-lg"></i> رد
                </button>
              </form>
              <a href="<?= APP_URL ?>/messages.php?to=<?= $offer['from_user_id'] ?>"
                 class="btn btn-outline w-100 btn-sm">
                <i class="bi bi-chat"></i> پیام
              </a>
            </div>
            <?php elseif ($offer['status'] === 'accepted'): ?>
            <div>
              <span class="badge badge-success" style="font-size:.875rem;padding:var(--sp-2) var(--sp-3)">
                <i class="bi bi-check-circle"></i> پذیرفته شد — معامله ایجاد شد
              </span>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>

  </div>
</div>

<?php render_footer(); ?>
