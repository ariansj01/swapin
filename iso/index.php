<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/iso.php';

$user = require_auth();
$uid  = (int)$user['id'];

$action = $_GET['action'] ?? 'list';
$msg = $_GET['msg'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete' || $postAction === 'pause' || $postAction === 'activate' || $postAction === 'complete') {
        $isoId = (int)($_POST['iso_id'] ?? 0);
        $statusMap = [
            'delete'   => 'deleted',
            'pause'    => 'paused',
            'activate' => 'active',
            'complete' => 'completed',
        ];
        if (isset($statusMap[$postAction]) && $isoId > 0) {
            iso_update_request($isoId, $uid, ['status' => $statusMap[$postAction]]);
            header('Location: ' . APP_URL . '/iso?msg=' . urlencode($postAction . '_ok'));
            exit;
        }
    }

    if ($postAction === 'regenerate_matches') {
        $isoId = (int)($_POST['iso_id'] ?? 0);
        if ($isoId > 0 && iso_get_request($isoId, $uid)) {
            iso_generate_and_save_matches($isoId, 30);
            header('Location: ' . APP_URL . '/iso/view?id=' . $isoId . '&msg=matches_updated');
            exit;
        }
    }
}

$activeList    = iso_get_user_requests($uid, 'active');
$pausedList    = iso_get_user_requests($uid, 'paused');
$completedList = iso_get_user_requests($uid, 'completed');
$deletedList   = iso_get_user_requests($uid, 'deleted');

$myActiveListings = DB::fetchAll(
    'SELECT l.id, l.title, c.name AS cat_name,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved"
     ORDER BY l.created_at DESC',
    [$uid]
);

render_head('چیزهایی که دنبالش هستم', 'مدیریت نیازها و ISOهای خود در ' . APP_NAME, [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
render_navbar($user);
?>

<?php if ($msg === 'created_ok'): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-check-circle"></i> ISO جدید شما با موفقیت ثبت شد و در حال پیدا کردن تطابق است.</div>
</div>
<?php elseif ($msg === 'updated_ok'): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-check-circle"></i> تغییرات ذخیره شد.</div>
</div>
<?php elseif ($msg === 'matches_updated'): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-stars"></i> تطابق‌های جدید با موفقیت تولید شد.</div>
</div>
<?php elseif ($msg === 'pause_ok'): ?>
<div class="alert alert-info" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-pause-circle"></i> ISO موقتاً غیرفعال شد.</div>
</div>
<?php elseif ($msg === 'activate_ok'): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-play-circle"></i> ISO دوباره فعال شد.</div>
</div>
<?php elseif ($msg === 'complete_ok'): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-check2-all"></i> ISO به‌عنوان تکمیل‌شده علامت‌گذاری شد.</div>
</div>
<?php elseif ($msg === 'delete_ok'): ?>
<div class="alert alert-warning" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-trash"></i> ISO حذف شد.</div>
</div>
<?php endif; ?>

<?php render_user_panel_open($user, 'iso'); ?>
<div class="dash-panel">
  <?php render_panel_page_header('چیزهایی که دنبالش هستم', 'نیازهای خود را ثبت کنید تا سیستم کالاهای مناسب را پیدا کند', APP_URL . '/', 'بازگشت به خانه'); ?>
  <div class="dash-page-head__actions" style="justify-content:flex-end;margin-bottom:24px;display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="<?= APP_URL ?>/iso/create" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg"></i> ثبت نیاز جدید
    </a>
  </div>

  <?php if (empty($myActiveListings)): ?>
  <div class="card mb-6">
    <div class="card-body" style="padding:var(--sp-8);text-align:center">
      <i class="bi bi-box-seam" style="font-size:3rem;color:var(--text-muted);opacity:.3"></i>
      <h3 style="margin:var(--sp-4) 0 var(--sp-2)">هنوز آگهی فعال ندارید</h3>
      <p style="color:var(--text-muted);max-width:480px;margin:0 auto var(--sp-5)">
        برای ثبت نیاز (ISO) ابتدا باید یک آگهی فعال داشته باشید. ISO به آگهی شما متصل می‌شود و مشخص می‌کند شما چه چیزی را در ازای آن کالا می‌پذیرید.
      </p>
      <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary">ثبت اولین آگهی</a>
    </div>
  </div>
  <?php else: ?>

  <!-- Active ISOs -->
  <div class="dash-panel-card mb-6">
    <div class="dash-panel-card__head">
      <h3><i class="bi bi-lightning-charge" style="color:var(--accent)"></i> ISOهای فعال</h3>
      <span class="badge badge-primary"><?= fmt_num(count($activeList)) ?></span>
    </div>
    <?php if (empty($activeList)): ?>
    <div class="card-body">
      <div class="empty-state" style="padding:var(--sp-8) var(--sp-4)">
        <i class="bi bi-search" style="font-size:2.5rem"></i>
        <h3 style="font-size:1.125rem">هنوز ISO فعالی ثبت نکرده‌اید</h3>
        <p style="font-size:.875rem">ثبت کنید به دنبال چه چیزی هستید تا سیستم براشون تطابق پیدا کنه!</p>
        <a href="<?= APP_URL ?>/iso/create" class="btn btn-primary btn-sm">ثبت نیاز جدید</a>
      </div>
    </div>
    <?php else: ?>
    <div style="display:grid;gap:var(--sp-4);padding:14px 18px">
    <?php foreach ($activeList as $iso):
      $matchCount = (int)(DB::fetch('SELECT COUNT(*) AS c FROM iso_matches WHERE iso_id = ? AND score >= 40', [(int)$iso['id']])['c'] ?? 0);
    ?>
      <div class="card" style="margin:0">
        <div style="display:flex;gap:var(--sp-4);padding:var(--sp-4) var(--sp-5);align-items:flex-start;flex-wrap:wrap">
          <div style="width:72px;height:72px;border-radius:12px;overflow:hidden;background:var(--border);flex-shrink:0">
            <?php if (!empty($iso['listing_thumb'])): ?>
              <img src="<?= UPLOAD_URL . h($iso['listing_thumb']) ?>" alt="<?= h($iso['listing_title']) ?>" style="width:100%;height:100%;object-fit:cover">
            <?php else: ?>
              <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><i class="bi bi-image" style="font-size:1.5rem"></i></div>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="display:flex;gap:var(--sp-2);align-items:center;flex-wrap:wrap;margin-bottom:4px">
              <span style="font-weight:700;font-size:1.05rem"><?= h($iso['title']) ?></span>
              <span class="badge badge-success">فعال</span>
            </div>
            <div class="fs-sm" style="color:var(--text-secondary);margin-bottom:6px">
              <i class="bi bi-box"></i> در ازای: <?= h($iso['listing_title']) ?>
              <span class="mx-2">·</span>
              <i class="bi bi-folder2"></i> <?= h($iso['category_name']) ?>
              <?php if (!empty($iso['city'])): ?>
              <span class="mx-2">·</span>
              <i class="bi bi-geo-alt"></i> <?= h($iso['city']) ?>
              <?php endif; ?>
            </div>
            <?php if (!empty($iso['description'])): ?>
            <div class="fs-sm" style="color:var(--text-muted);margin-bottom:8px"><?= h(mb_strimwidth($iso['description'], 0, 140, '…')) ?></div>
            <?php endif; ?>
            <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;align-items:center">
              <a href="<?= APP_URL ?>/iso/view?id=<?= (int)$iso['id'] ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-stars"></i> مشاهده تطابق‌ها
                <?php if ($matchCount > 0): ?><span class="badge badge-warning" style="margin-inline-start:6px"><?= fmt_num($matchCount) ?></span><?php endif; ?>
              </a>
              <a href="<?= APP_URL ?>/iso/edit?id=<?= (int)$iso['id'] ?>" class="btn btn-outline btn-sm"><i class="bi bi-pencil"></i> ویرایش</a>
              <form method="post" style="display:inline" onsubmit="return confirm('مطمئنید می‌خواهید این ISO را متوقف کنید؟')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="pause">
                <input type="hidden" name="iso_id" value="<?= (int)$iso['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-pause"></i> توقف</button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('علامت‌گذاری به‌عنوان تکمیل‌شده؟')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="iso_id" value="<?= (int)$iso['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm"><i class="bi bi-check2-all"></i> تکمیل شد</button>
              </form>
              <form method="post" style="display:inline;margin-inline-start:auto" onsubmit="return confirm('حذف شود؟ این عمل قابل‌بازگشت نیست.')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="iso_id" value="<?= (int)$iso['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="bi bi-trash"></i> حذف</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Paused -->
  <?php if (!empty($pausedList)): ?>
  <div class="dash-panel-card mb-6">
    <div class="dash-panel-card__head">
      <h3><i class="bi bi-pause-circle" style="color:var(--warning)"></i> ISOهای متوقف‌شده</h3>
      <span class="badge badge-warning"><?= fmt_num(count($pausedList)) ?></span>
    </div>
    <div style="display:grid;gap:var(--sp-3);padding:14px 18px">
    <?php foreach ($pausedList as $iso): ?>
      <div class="card" style="margin:0;opacity:.85">
        <div style="display:flex;gap:var(--sp-4);padding:var(--sp-3) var(--sp-5);align-items:center;flex-wrap:wrap">
          <div style="font-weight:600;flex:1;min-width:0"><?= h($iso['title']) ?> <span class="fs-xs" style="color:var(--text-muted)">· در ازای: <?= h($iso['listing_title']) ?></span></div>
          <span class="badge badge-warning fs-xs">متوقف</span>
          <form method="post" style="display:inline">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="activate">
            <input type="hidden" name="iso_id" value="<?= (int)$iso['id'] ?>">
            <button type="submit" class="btn btn-primary btn-sm">فعال‌سازی</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="iso_id" value="<?= (int)$iso['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Completed -->
  <?php if (!empty($completedList)): ?>
  <div class="dash-panel-card mb-6">
    <div class="dash-panel-card__head">
      <h3><i class="bi bi-check2-all" style="color:var(--success)"></i> ISOهای تکمیل‌شده</h3>
      <span class="badge badge-success"><?= fmt_num(count($completedList)) ?></span>
    </div>
    <div style="display:grid;gap:var(--sp-3);padding:14px 18px">
    <?php foreach ($completedList as $iso): ?>
      <div class="card" style="margin:0;opacity:.75">
        <div style="display:flex;gap:var(--sp-4);padding:var(--sp-3) var(--sp-5);align-items:center;flex-wrap:wrap">
          <div style="font-weight:600;flex:1;min-width:0"><?= h($iso['title']) ?> <span class="fs-xs" style="color:var(--text-muted)">· در ازای: <?= h($iso['listing_title']) ?></span></div>
          <span class="badge badge-success fs-xs">تکمیل شده</span>
          <span class="fs-xs" style="color:var(--text-muted)"><?= persian_date($iso['updated_at']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
