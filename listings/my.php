<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';

$user = require_auth();
$uid  = $user['id'];

$success = '';
$error   = '';

// ─── POST actions: delete / mark-traded / reactivate ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action    = clean($_POST['action']     ?? '');
    $listingId = (int)($_POST['listing_id'] ?? 0);

    $owned = $listingId ? DB::fetch(
        'SELECT id, status, title FROM listings WHERE id = ? AND user_id = ?',
        [$listingId, $uid]
    ) : null;

    if (!$owned) {
        $error = 'آگهی یافت نشد یا اجازه ویرایش ندارید.';
    } elseif ($action === 'delete') {
        if (in_array($owned['status'], ['active', 'expired'])) {
            DB::query('UPDATE listings SET status = "deleted" WHERE id = ?', [$listingId]);
            $success = '"' . $owned['title'] . '" حذف شد.';
        } else {
            $error = 'فقط آگهی‌های فعال یا منقضی قابل حذف هستند.';
        }
    } elseif ($action === 'mark_traded') {
        if ($owned['status'] === 'active') {
            DB::query('UPDATE listings SET status = "traded" WHERE id = ?', [$listingId]);
            $success = '"' . $owned['title'] . '" به‌عنوان معامله‌شده علامت خورد.';
        } else {
            $error = 'فقط آگهی‌های فعال را می‌توان معامله‌شده علامت زد.';
        }
    } elseif ($action === 'reactivate') {
        if (in_array($owned['status'], ['traded', 'expired'])) {
            DB::query('UPDATE listings SET status = "active", updated_at = NOW() WHERE id = ?', [$listingId]);
            $success = '"' . $owned['title'] . '" دوباره فعال شد.';
        } else {
            $error = 'فقط آگهی‌های معامله‌شده یا منقضی قابل فعال‌سازی مجدد هستند.';
        }
    }
}

// ─── Active tab ───────────────────────────────────────────────────────────────
$validTabs = ['active', 'traded', 'expired', 'deleted'];
$tab       = in_array($_GET['tab'] ?? '', $validTabs) ? $_GET['tab'] : 'active';
$page      = max(1, (int)($_GET['page'] ?? 1));

// ─── Per-status counts ────────────────────────────────────────────────────────
$counts = [];
foreach ($validTabs as $s) {
    $row        = DB::fetch('SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = ?', [$uid, $s]);
    $counts[$s] = (int)($row['c'] ?? 0);
}

$pag = paginate($counts[$tab], LISTINGS_PER_PAGE, $page);

// ─── Listings for current tab ─────────────────────────────────────────────────
$listings = DB::fetchAll(
    "SELECT l.*,
            c.name AS cat_name, c.slug AS cat_slug, c.icon AS cat_icon,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb,
            (SELECT COUNT(*) FROM trade_offers o WHERE o.listing_id = l.id AND o.status = 'pending') AS pending_offers,
            (SELECT COUNT(*) FROM trade_offers o WHERE o.listing_id = l.id)                           AS total_offers
     FROM listings l
     JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = ?
     ORDER BY l.updated_at DESC
     LIMIT ? OFFSET ?",
    [$uid, $tab, LISTINGS_PER_PAGE, $pag['offset']]
);

$statusLabels = ['active' => 'فعال', 'traded' => 'معامله‌شده', 'expired' => 'منقضی', 'deleted' => 'حذف‌شده'];
$condColors = ['new' => 'success', 'like_new' => 'success', 'good' => 'info', 'fair' => 'warning', 'poor' => 'danger'];

$tabMeta = [
    'active'  => ['icon' => 'bi-broadcast',    'color' => 'success', 'label' => 'فعال'],
    'traded'  => ['icon' => 'bi-check2-circle', 'color' => 'info',    'label' => 'معامله‌شده'],
    'expired' => ['icon' => 'bi-clock-history', 'color' => 'warning', 'label' => 'منقضی'],
    'deleted' => ['icon' => 'bi-trash3',        'color' => 'danger',  'label' => 'حذف‌شده'],
];

render_head('آگهی‌های من');
render_panel_styles();
render_navbar($user);
render_user_panel_open($user, 'my');
?>

  <div class="dash-panel">
    <span class="badge badge-warning">آگهی شما بعد از ۱۵ روز دیگر منقضی میشود</span>
    <?php render_panel_page_header('آگهی‌های من', 'مدیریت همه آگهی‌های تعویض شما'); ?>
    <div class="dash-page-head__actions">
      <a href="<?= APP_URL ?>/listings/create.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> آگهی جدید
      </a>
    </div>

    <?php if (isset($_GET['promoted'])): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-rocket"></i> آگهی با موفقیت ارتقا یافت!</div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5">
      <i class="bi bi-check-circle-fill"></i>
      <span><?= h($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger mb-5">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Tab pills -->
    <div class="my-tab-pills">
      <?php foreach ($tabMeta as $s => $meta): ?>
      <a href="?tab=<?= $s ?>" class="my-tab-pill <?= $tab === $s ? 'is-active' : '' ?>">
        <?= $meta['label'] ?>
        <?php if ($counts[$s] > 0): ?>
        <span class="badge badge-<?= $meta['color'] ?>"><?= fmt_num($counts[$s]) ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Empty state -->
    <?php if (empty($listings)): ?>
    <div class="empty-state">
      <i class="bi <?= $tabMeta[$tab]['icon'] ?>"></i>
      <h3>آگهی <?= $tabMeta[$tab]['label'] ?>ی وجود ندارد</h3>
      <p>
        <?php if ($tab === 'active'): ?>
          آگهی فعالی ندارید. برای شروع معامله، یک آگهی ثبت کنید!
        <?php elseif ($tab === 'traded'): ?>
          آگهی‌هایی که با موفقیت معامله شده‌اند اینجا نمایش داده می‌شوند.
        <?php elseif ($tab === 'expired'): ?>
          آگهی‌های منقضی اینجا هستند — می‌توانید دوباره فعالشان کنید.
        <?php else: ?>
          آگهی‌هایی که حذف کرده‌اید اینجا نمایش داده می‌شوند.
        <?php endif; ?>
      </p>
      <?php if ($tab === 'active'): ?>
      <a href="<?= APP_URL ?>/listings/create.php" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> ثبت آگهی
      </a>
      <?php endif; ?>
    </div>

    <?php else: ?>

    <!-- Listings card grid -->
    <div class="my-listings-grid">
      <?php foreach ($listings as $l):
        $hasPending = $l['pending_offers'] > 0;
        $sc = ['active'=>'success','traded'=>'info','expired'=>'warning','deleted'=>'danger'][$l['status']] ?? 'info';
      ?>
      <article class="my-listing-card <?= $hasPending ? 'my-listing-card--pending' : '' ?>">
        <div class="my-listing-card__media">
          <a href="<?= APP_URL ?>/listings/view?id=<?= $l['id'] ?>" class="my-listing-card__media-link" aria-label="<?= h($l['title']) ?>"></a>
          <?php if ($l['thumb']): ?>
          <img src="<?= UPLOAD_URL . h($l['thumb']) ?>" alt="<?= h($l['title']) ?>">
          <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2rem;opacity:.25;color:var(--text-muted)"><i class="bi bi-image"></i></div>
          <?php endif; ?>
          <div class="my-listing-card__badges">
            <span class="badge badge-<?= $sc ?>"><?= $statusLabels[$l['status']] ?? $l['status'] ?></span>
            <?php if ($hasPending): ?><span class="badge badge-warning"><?= fmt_num($l['pending_offers']) ?> جدید</span><?php endif; ?>
          </div>
        </div>
        <div class="my-listing-card__body">
          <h3 class="my-listing-card__title"><?= h($l['title']) ?></h3>
          <div class="my-listing-card__meta">
            <span><i class="<?= h($l['cat_icon']) ?>"></i> <?= h($l['cat_name']) ?></span>
            <?php if ($l['city']): ?><span><i class="bi bi-geo-alt"></i> <?= h($l['city']) ?></span><?php endif; ?>
            <span><i class="bi bi-eye"></i> <?= fmt_num((int)$l['views']) ?></span>
          </div>
          <?php if ($l['estimated_value'] > 0): ?>
          <div style="font-size:.8125rem;font-weight:700;color:var(--primary)">~<?= fmt_credit((float)$l['estimated_value']) ?></div>
          <?php endif; ?>
          <p class="my-listing-card__want">"<?= h(mb_strimwidth($l['want_in_return'], 0, 60, '…')) ?>"</p>
          <div class="my-listing-card__actions">
            <a href="<?= APP_URL ?>/listings/view?id=<?= $l['id'] ?>" class="btn btn-ghost btn-sm" title="مشاهده"><i class="bi bi-eye"></i></a>
            <?php if ($l['status'] === 'active'): ?>
            <a href="<?= APP_URL ?>/listings/edit.php?id=<?= $l['id'] ?>" class="btn btn-outline btn-sm" title="ویرایش"><i class="bi bi-pencil"></i></a>
            <a href="<?= APP_URL ?>/listings/promote.php?id=<?= $l['id'] ?>" class="btn btn-accent btn-sm" title="ارتقا"><i class="bi bi-rocket"></i></a>
            <?php if ($l['total_offers'] > 0): ?>
            <a href="<?= APP_URL ?>/listings/offers.php?id=<?= $l['id'] ?>" class="btn btn-<?= $hasPending ? 'accent' : 'ghost' ?> btn-sm"><i class="bi bi-inbox"></i></a>
            <?php endif; ?>
            <?php elseif (in_array($l['status'], ['traded', 'expired'])): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('دوباره فعال شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reactivate">
              <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-arrow-counterclockwise"></i></button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($pag['pages'] > 1): ?>
    <div style="display:flex;justify-content:center;align-items:center;gap:var(--sp-2);margin-top:var(--sp-8)">
      <?php if ($pag['has_prev']): ?>
      <a href="?tab=<?= $tab ?>&page=<?= $pag['page']-1 ?>" class="btn btn-outline btn-sm">
        <i class="bi bi-chevron-right"></i> قبلی
      </a>
      <?php endif; ?>
      <?php for ($p = max(1, $pag['page']-2); $p <= min($pag['pages'], $pag['page']+2); $p++): ?>
      <a href="?tab=<?= $tab ?>&page=<?= $p ?>"
         class="btn <?= $p === $pag['page'] ? 'btn-primary' : 'btn-outline' ?> btn-sm"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($pag['has_next']): ?>
      <a href="?tab=<?= $tab ?>&page=<?= $pag['page']+1 ?>" class="btn btn-outline btn-sm">
        بعدی <i class="bi bi-chevron-left"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

  </div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
