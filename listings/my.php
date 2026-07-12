<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

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
render_navbar($user);
?>

<div class="section-sm">
  <div class="container">

    <!-- Page header -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:var(--sp-4);margin-bottom:var(--sp-6)">
      <div>
        <a href="<?= APP_URL ?>/dashboard.php" style="color:var(--text-muted);font-size:.875rem">
          <i class="bi bi-arrow-right"></i> داشبورد
        </a>
        <h2 class="mt-3" style="margin-bottom:4px">آگهی‌های من</h2>
        <p style="color:var(--text-muted);margin:0">مدیریت همه آگهی‌های تعویض شما</p>
      </div>
      <a href="<?= APP_URL ?>/listings/create.php" class="btn btn-primary">
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

    <!-- Summary stat cards -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:var(--sp-3);margin-bottom:var(--sp-6)">
      <?php foreach ($tabMeta as $s => $meta): ?>
      <a href="?tab=<?= $s ?>" style="text-decoration:none">
        <div class="card" style="border-top:3px solid var(--<?= $meta['color'] ?>);padding:var(--sp-4);text-align:center;transition:box-shadow var(--duration);<?= $tab === $s ? 'box-shadow:var(--shadow-md)' : '' ?>">
          <div style="font-size:1.75rem;font-weight:800;color:var(--<?= $meta['color'] ?>)"><?= $counts[$s] ?></div>
          <div class="fs-xs" style="color:var(--text-muted);margin-top:2px">
            <i class="bi <?= $meta['icon'] ?>"></i> <?= $meta['label'] ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Tab strip -->
    <div class="tabs mb-6">
      <?php foreach ($tabMeta as $s => $meta): ?>
      <button class="tab-btn <?= $tab === $s ? 'active' : '' ?>"
              onclick="location.href='?tab=<?= $s ?>'">
        <?= $meta['label'] ?>
        <?php if ($counts[$s] > 0): ?>
        <span class="badge badge-<?= $meta['color'] ?>" style="margin-inline-start:var(--sp-2)"><?= $counts[$s] ?></span>
        <?php endif; ?>
      </button>
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

    <!-- Listings list -->
    <div class="card" style="overflow:hidden">
      <?php foreach ($listings as $idx => $l):
        $hasPending  = $l['pending_offers'] > 0;
        $borderStyle = $hasPending ? 'border-inline-start:3px solid var(--warning);' : '';
      ?>
      <div style="display:flex;align-items:flex-start;gap:var(--sp-4);padding:var(--sp-4) var(--sp-5);<?= $idx > 0 ? 'border-top:1px solid var(--border);' : '' ?><?= $borderStyle ?>">

        <!-- Thumbnail -->
        <div style="width:80px;height:72px;flex-shrink:0;border-radius:var(--radius-md);overflow:hidden;background:var(--bg);border:1px solid var(--border)">
          <?php if ($l['thumb']): ?>
          <img src="<?= UPLOAD_URL . h($l['thumb']) ?>" alt="<?= h($l['title']) ?>"
               style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;opacity:.3;color:var(--text-muted)">
            <i class="bi bi-image"></i>
          </div>
          <?php endif; ?>
        </div>

        <!-- Body -->
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-3);flex-wrap:wrap">
            <div style="min-width:0;flex:1">
              <a href="<?= APP_URL ?>/listings/view?id=<?= $l['id'] ?>"
                 style="font-weight:700;font-size:1rem;color:var(--text-primary);text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= h($l['title']) ?>
              </a>
              <div class="fs-xs" style="color:var(--text-muted);margin-top:var(--sp-1);display:flex;gap:var(--sp-3);flex-wrap:wrap">
                <span><i class="<?= h($l['cat_icon']) ?>"></i> <?= h($l['cat_name']) ?></span>
                <?php if ($l['city']): ?>
                <span><i class="bi bi-geo-alt"></i> <?= h($l['city']) ?></span>
                <?php endif; ?>
                <span><i class="bi bi-eye"></i> <?= number_format((int)$l['views']) ?> بازدید</span>
                <span><i class="bi bi-clock"></i> <?= timeago($l['created_at']) ?></span>
              </div>
            </div>
            <?php $sc = ['active'=>'success','traded'=>'info','expired'=>'warning','deleted'=>'danger'][$l['status']] ?? 'info'; ?>
            <span class="badge badge-<?= $sc ?>" style="flex-shrink:0"><?= $statusLabels[$l['status']] ?? $l['status'] ?></span>
            <?php if (($l['review_status'] ?? 'approved') === 'pending'): ?>
            <span class="badge badge-warning" style="flex-shrink:0">در انتظار تأیید</span>
            <?php elseif (($l['review_status'] ?? '') === 'rejected'): ?>
            <span class="badge badge-danger" style="flex-shrink:0">رد شده</span>
            <?php endif; ?>
          </div>

          <div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-top:var(--sp-3)">
            <span class="badge badge-<?= $condColors[$l['condition']] ?? 'info' ?>">
              <?= condition_label($l['condition']) ?>
            </span>
            <?php if ($l['estimated_value'] > 0): ?>
            <span style="font-size:.875rem;font-weight:700;color:var(--primary)">
              ~<?= fmt_credit((float)$l['estimated_value']) ?>
            </span>
            <?php endif; ?>
            <span class="fs-sm" style="color:var(--text-secondary)">
              <i class="bi bi-arrow-left-right"></i>
              <?= h(listing_mode_label($l['listing_mode'] ?? 'swap')) ?>
              · می‌خواهد: <strong><?= want_type_label($l['want_type']) ?></strong>
            </span>
            <?php if (listing_is_featured($l)): ?>
            <span class="badge badge-warning"><i class="bi bi-star-fill"></i> ویژه</span>
            <?php endif; ?>
            <?php if (listing_is_bumped($l)): ?>
            <span class="badge badge-info"><i class="bi bi-arrow-up-circle-fill"></i> بالا برده</span>
            <?php endif; ?>
            <?php if ($l['total_offers'] > 0): ?>
            <a href="<?= APP_URL ?>/listings/offers.php?id=<?= $l['id'] ?>"
               style="font-size:.875rem;font-weight:600;text-decoration:none;color:<?= $hasPending ? 'var(--warning)' : 'var(--text-muted)' ?>">
              <i class="bi bi-inbox"></i> <?= $l['total_offers'] ?> پیشنهاد
              <?php if ($hasPending): ?>
              <span class="badge badge-warning" style="margin-inline-start:2px"><?= $l['pending_offers'] ?> جدید</span>
              <?php endif; ?>
            </a>
            <?php endif; ?>
          </div>

          <div class="fs-sm" style="color:var(--text-muted);margin-top:var(--sp-2);font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            "<?= h(mb_strimwidth($l['want_in_return'], 0, 100, '…')) ?>"
          </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;flex-direction:column;gap:var(--sp-2);align-items:flex-end;flex-shrink:0">

          <a href="<?= APP_URL ?>/listings/view.php?id=<?= $l['id'] ?>"
             class="btn btn-ghost btn-sm" title="مشاهده"><i class="bi bi-eye"></i></a>

          <?php if ($l['status'] === 'active'): ?>

          <a href="<?= APP_URL ?>/listings/edit.php?id=<?= $l['id'] ?>"
             class="btn btn-outline btn-sm" title="ویرایش"><i class="bi bi-pencil"></i></a>

          <a href="<?= APP_URL ?>/listings/promote.php?id=<?= $l['id'] ?>"
             class="btn btn-accent btn-sm" title="ارتقا"><i class="bi bi-rocket"></i></a>

          <?php if ($l['total_offers'] > 0): ?>
          <a href="<?= APP_URL ?>/listings/offers.php?id=<?= $l['id'] ?>"
             class="btn btn-<?= $hasPending ? 'accent' : 'ghost' ?> btn-sm" title="پیشنهادها">
            <i class="bi bi-inbox"></i>
            <?php if ($hasPending): ?>
            <span class="badge badge-warning" style="margin-inline-start:2px;font-size:.65rem"><?= $l['pending_offers'] ?></span>
            <?php endif; ?>
          </a>
          <?php endif; ?>

          <form method="POST" style="display:contents">
            <?= csrf_field() ?>
                onsubmit="return confirm('«<?= addslashes($l['title']) ?>» به‌عنوان معامله‌شده علامت بخورد؟')">
            <input type="hidden" name="action"     value="mark_traded">
            <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--success)" title="علامت معامله‌شده">
              <i class="bi bi-check2-circle"></i>
            </button>
          </form>

          <form method="POST" style="display:contents">
            <?= csrf_field() ?>
                onsubmit="return confirm('«<?= addslashes($l['title']) ?>» حذف شود؟ دیگر در نتایج جستجو نمایش داده نمی‌شود.')">
            <input type="hidden" name="action"     value="delete">
            <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="حذف">
              <i class="bi bi-trash3"></i>
            </button>
          </form>

          <?php elseif (in_array($l['status'], ['traded', 'expired'])): ?>

          <form method="POST" style="display:contents">
            <?= csrf_field() ?>
                onsubmit="return confirm('«<?= addslashes($l['title']) ?>» دوباره فعال شود؟ در بازار دوباره نمایش داده می‌شود.')">
            <input type="hidden" name="action"     value="reactivate">
            <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
            <button type="submit" class="btn btn-outline btn-sm">
              <i class="bi bi-arrow-counterclockwise"></i> فعال‌سازی مجدد
            </button>
          </form>

          <?php endif; ?>
        </div>

      </div>
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
</div>

<?php render_footer(); ?>
