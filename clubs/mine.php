<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$user = require_auth();
$uid = (int)$user['id'];
clubs_ensure_tables();

$tab = clean($_GET['tab'] ?? 'clubs');
if (!in_array($tab, ['clubs', 'requests', 'joined'], true)) {
    $tab = 'clubs';
}

$flash = '';
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');
    $clubId = (int)($_POST['club_id'] ?? 0);
    $memberId = (int)($_POST['member_id'] ?? 0);
    if ($action === 'approve_join' || $action === 'reject_join') {
        $res = clubs_review_join($clubId, $memberId, $action === 'approve_join' ? 'approve' : 'reject', $uid);
        if (empty($res['ok'])) {
            $flash = $res['error'] ?? 'بررسی ناموفق بود.';
            $flashType = 'error';
        } else {
            $flash = $action === 'approve_join' ? 'عضویت تأیید شد.' : 'درخواست رد شد.';
        }
    }
    $tab = 'requests';
}

$managed = clubs_managed_for_user($uid);
$joined = clubs_memberships_for_user($uid);
$requests = clubs_pending_for_manager($uid);
$pendingCount = count($requests);

render_head('کلاب‌های من', 'مدیریت کلاب‌ها و درخواست‌های عضویت');
render_navbar($user);
render_panel_styles();
render_user_panel_open($user, 'clubs');
?>
<div class="dash-panel">
  <?php render_panel_page_header('کلاب‌های من', 'کلاب‌هایی که مدیریت می‌کنید یا عضو آن‌ها هستید'); ?>
  <div class="dash-page-head__actions" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= APP_URL ?>/clubs" class="btn btn-outline btn-sm">صفحه کلاب‌ها</a>
    <a href="<?= APP_URL ?>/clubs/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> ساخت کلاب</a>
  </div>

  <?php if ($flash !== ''): ?>
  <div class="alert alert-<?= $flashType === 'error' ? 'danger' : 'success' ?> mb-5"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="my-tab-pills">
    <a href="?tab=clubs" class="my-tab-pill <?= $tab === 'clubs' ? 'is-active' : '' ?>">کلاب‌های من <?= $managed ? '(' . fmt_num(count($managed)) . ')' : '' ?></a>
    <a href="?tab=requests" class="my-tab-pill <?= $tab === 'requests' ? 'is-active' : '' ?>">
      درخواست‌ها
      <?php if ($pendingCount > 0): ?><span class="badge badge-warning"><?= fmt_num($pendingCount) ?></span><?php endif; ?>
    </a>
    <a href="?tab=joined" class="my-tab-pill <?= $tab === 'joined' ? 'is-active' : '' ?>">عضو هستم <?= $joined ? '(' . fmt_num(count($joined)) . ')' : '' ?></a>
  </div>

  <?php if ($tab === 'requests'): ?>
    <?php if (!$requests): ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <h3>درخواست معلقی نیست</h3>
      <p>وقتی کسی برای ورود به کلاب شما درخواست بدهد، اینجا با مشخصات کاربر و آگهی‌اش دیده می‌شود.</p>
    </div>
    <?php else: ?>
    <div class="card" style="overflow:auto">
      <table class="admin-table">
        <thead>
          <tr>
            <th>کاربر</th>
            <th>کلاب</th>
            <th>آگهی پیوستن</th>
            <th>تاریخ</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <?= avatar_html($r['avatar'] ?? null, $r['name'] ?? '', 'sm') ?>
                <div>
                  <strong><?= h($r['name']) ?></strong>
                  <?php if (!empty($r['phone'])): ?><div class="fs-xs text-muted"><?= h($r['phone']) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= h($r['club_name']) ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <?php if (!empty($r['listing_thumb'])): ?>
                <img src="<?= h(UPLOAD_URL . $r['listing_thumb']) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px">
                <?php endif; ?>
                <span><?= h($r['listing_title'] ?? '—') ?></span>
              </div>
            </td>
            <td class="fs-sm text-muted"><?= h($r['created_at']) ?></td>
            <td>
              <div style="display:flex;gap:6px">
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve_join">
                  <input type="hidden" name="club_id" value="<?= (int)$r['club_id'] ?>">
                  <input type="hidden" name="member_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-primary">تأیید</button>
                </form>
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reject_join">
                  <input type="hidden" name="club_id" value="<?= (int)$r['club_id'] ?>">
                  <input type="hidden" name="member_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger">رد</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  <?php elseif ($tab === 'joined'): ?>
    <?php if (!$joined): ?>
    <div class="empty-state">
      <i class="bi bi-people"></i>
      <h3>هنوز عضو هیچ کلابی نیستید</h3>
      <p><a href="<?= APP_URL ?>/clubs">از صفحه کلاب‌ها عضو شوید.</a></p>
    </div>
    <?php else: ?>
    <div class="card p-4">
      <label class="form-label" for="club-switch">سوییچ بین کلاب‌ها</label>
      <select id="club-switch" class="form-control" onchange="if(this.value) location.href=this.value">
        <option value="">انتخاب کلاب</option>
        <?php foreach ($joined as $jc): ?>
        <option value="<?= h(clubs_url($jc)) ?>"><?= h($jc['name']) ?><?= ($jc['role'] ?? '') === 'owner' ? ' (مدیر)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:grid;gap:12px;margin-top:16px">
      <?php foreach ($joined as $jc): ?>
      <a class="card" style="padding:16px;display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit" href="<?= h(clubs_url($jc)) ?>">
        <?php $av = clubs_image_url($jc['avatar_image'] ?? ''); ?>
        <?php if ($av): ?><img src="<?= h($av) ?>" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover"><?php endif; ?>
        <div>
          <strong><?= h($jc['name']) ?></strong>
          <div class="fs-sm text-muted"><?= h($jc['role'] === 'owner' ? 'مدیر' : ($jc['role'] === 'moderator' ? 'ناظم' : 'عضو')) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <?php if (!$managed): ?>
    <div class="empty-state">
      <i class="bi bi-plus-circle"></i>
      <h3>کلابی برای مدیریت ندارید</h3>
      <p>کلاب بسازید یا اگر مدیر هستید، اینجا نمایش داده می‌شود.</p>
      <a class="btn btn-primary" href="<?= APP_URL ?>/clubs/create">ساخت کلاب</a>
    </div>
    <?php else: ?>
    <div style="display:grid;gap:12px">
      <?php foreach ($managed as $mc): ?>
      <div class="card" style="padding:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <?php $av = clubs_image_url($mc['avatar_image'] ?? ''); ?>
        <?php if ($av): ?><img src="<?= h($av) ?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover"><?php endif; ?>
        <div style="flex:1">
          <strong><?= h($mc['name']) ?></strong>
          <div class="fs-sm text-muted"><?= h($mc['role'] === 'owner' ? 'مدیر' : 'ناظم') ?>
            <?php if ((int)($mc['pending_count'] ?? 0) > 0): ?> — <?= fmt_num((int)$mc['pending_count']) ?> درخواست<?php endif; ?>
          </div>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= h(clubs_url($mc)) ?>">ورود به کلاب</a>
        <a class="btn btn-primary btn-sm" href="?tab=requests">درخواست‌ها</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
render_user_panel_close();
render_footer();
render_panel_scripts();
