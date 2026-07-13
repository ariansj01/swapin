<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = $user['id'];

$listingId = (int)($_GET['id'] ?? 0);
$success   = '';
$error     = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $offerId = (int)($_POST['offer_id'] ?? 0);
    $action  = clean($_POST['action'] ?? '');
    $message = clean($_POST['message'] ?? '');

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
            if (empty($message)) {
                $error = 'لطفاً پیامی برای طرفین بنویسید.';
            } else {
                $result = accept_trade_offer($offerId, $uid, $message);
                if (isset($result['error'])) {
                    $error = $result['error'];
                } else {
                    header('Location: ' . APP_URL . '/trades/view.php?id=' . $result['trade_id'] . '&accepted=1&tab=fee');
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

// Fetch listing and its offers
if ($listingId) {
    $listing = DB::fetch(
        'SELECT * FROM listings WHERE id = ? AND user_id = ?', [$listingId, $uid]
    );
    if (!$listing) {
        header('Location: ' . APP_URL . '/listings/my.php'); exit;
    }
    $offers = DB::fetchAll(
        'SELECT o.*, u.name AS from_name, u.avatar AS from_avatar, u.rating AS from_rating, u.city AS from_city,
                ol.title AS offer_listing_title, ol.id AS offer_listing_id_v,
                (SELECT filename FROM listing_images WHERE listing_id=ol.id AND is_primary=1 LIMIT 1) AS offer_listing_thumb,
                (SELECT t.id FROM trades t WHERE t.offer_id = o.id LIMIT 1) AS trade_id
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
                u.name AS from_name, u.avatar AS from_avatar, u.rating AS from_rating,
                ol.title AS offer_listing_title,
                (SELECT t.id FROM trades t WHERE t.offer_id = o.id LIMIT 1) AS trade_id
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
      <a href="<?= APP_URL ?>/trades?tab=received" style="color:var(--text-muted);font-size:.875rem">
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
      <div class="card" style="<?= $offer['status'] === 'pending' ? 'border-inline-start:4px solid var(--warning)' : '' ?>">
        <div class="card-body">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">

            <!-- Offer Left -->
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
                <?= avatar_html($offer['from_avatar'] ?? null, $offer['from_name'], 'md') ?>
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

              <?php if (!$listing): ?>
              <div class="fs-sm mb-2" style="color:var(--text-muted)">
                برای: <strong><?= h($offer['listing_title'] ?? '') ?></strong>
              </div>
              <?php endif; ?>

              <!-- What they're offering -->
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

            <!-- Actions -->
            <?php if ($offer['status'] === 'pending'): ?>
            <div style="width:100%;min-width:280px;max-width:420px">
              <form method="POST" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                <?php if ($listingId): ?>
                <input type="hidden" name="id" value="<?= $listingId ?>">
                <?php endif; ?>
                <div class="form-group">
                  <label class="form-label">پیام پذیرش:</label>
                  <textarea name="message" class="form-control" rows="2" required placeholder="مثلاً: سلام! پیشنهاد شما را می‌پذیرم. برای هماهنگی بیشتر پیام بده."></textarea>
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

  </div>
</div>

<?php render_footer(); ?>
