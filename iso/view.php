<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/iso.php';

$user = require_auth();
$uid  = (int)$user['id'];
$id   = (int)($_GET['id'] ?? 0);
$msg  = $_GET['msg'] ?? '';

$iso = iso_get_request($id, $uid);

if (!$iso) {
    http_response_code(404);
    render_head('ISO یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container"><div class="empty-state"><i class="bi bi-exclamation-circle"></i><h1>ISO یافت نشد</h1><p>این درخواست ISO وجود ندارد یا متعلق به شما نیست.</p><a href="' . APP_URL . '/iso" class="btn btn-primary">بازگشت به ISOها</a></div></div></main>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_viewed' && !empty($_POST['listing_ids'])) {
        foreach ($_POST['listing_ids'] as $lid) {
            $lid = (int)$lid;
            if ($lid > 0) iso_update_match_status($id, $lid, 'viewed');
        }
        header('Location: ' . APP_URL . '/iso/view?id=' . $id);
        exit;
    }
    if ($action === 'mark_interested') {
        $lid = (int)($_POST['listing_id'] ?? 0);
        if ($lid > 0) {
            iso_update_match_status($id, $lid, 'interested');
            $iso = iso_get_request($id);
            $sourceListingId = $iso ? (int)$iso['listing_id'] : 0;
            $redirectParams = ['id' => $lid];
            if ($sourceListingId > 0) {
                $redirectParams['iso_from'] = $sourceListingId;
            }
            header('Location: ' . APP_URL . '/listings/view?' . http_build_query($redirectParams) . '#iso-offer-start');
            exit;
        }
    }
    if ($action === 'reject') {
        $lid = (int)($_POST['listing_id'] ?? 0);
        if ($lid > 0) iso_update_match_status($id, $lid, 'rejected');
        header('Location: ' . APP_URL . '/iso/view?id=' . $id . '&msg=rejected');
        exit;
    }
}

$matches = iso_get_saved_matches($id, 30);
if (empty($matches)) {
    $found = iso_find_matches_for_iso($id, 30);
    foreach ($found as $m) {
        $score = [
            'total'       => $m['match_score'],
            'distance_km' => $m['distance_km'],
            'reason'      => $m['match_reason'],
        ];
        iso_save_match($id, (int)$m['id'], $score);
    }
    $matches = iso_get_saved_matches($id, 30);
}

$excellent = array_filter($matches, fn($m) => (int)$m['score'] >= 80);
$good      = array_filter($matches, fn($m) => (int)$m['score'] >= 60 && (int)$m['score'] < 80);
$possible  = array_filter($matches, fn($m) => (int)$m['score'] >= 40 && (int)$m['score'] < 60);
$weak      = array_filter($matches, fn($m) => (int)$m['score'] < 40);

render_head('تطابق‌های ISO: ' . $iso['title'], 'تطابق‌های پیدا شده برای نیاز شما در ' . APP_NAME, [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
render_navbar($user);
?>

<?php if ($msg === 'matches_updated'): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-stars"></i> تطابق‌ها به‌روز شد.</div>
</div>
<?php elseif ($msg === 'rejected'): ?>
<div class="alert alert-info" style="border-radius:0;border-left:0;border-right:0">
  <div class="container"><i class="bi bi-x-circle"></i> این تطابق رد شد و دیگر نمایش داده نمی‌شود.</div>
</div>
<?php endif; ?>

<?php render_user_panel_open($user, 'iso'); ?>
<div class="dash-panel">
  <?php render_panel_page_header('تطابق‌های پیدا شده', 'بر اساس ISO: ' . $iso['title'], APP_URL . '/iso', 'بازگشت به لیست ISO'); ?>

  <div class="dash-page-head__actions" style="justify-content:flex-end;margin-bottom:24px;display:flex;gap:.5rem;flex-wrap:wrap">
    <form method="post" style="display:inline">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="regenerate_matches">
      <input type="hidden" name="iso_id" value="<?= (int)$id ?>">
      <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-arrow-clockwise"></i> بروزرسانی تطابق‌ها</button>
    </form>
    <a href="<?= APP_URL ?>/iso/edit?id=<?= (int)$id ?>" class="btn btn-outline btn-sm"><i class="bi bi-pencil"></i> ویرایش ISO</a>
  </div>

  <!-- ISO Summary -->
  <div class="card mb-6">
    <div style="display:flex;gap:var(--sp-4);padding:var(--sp-5);align-items:center;flex-wrap:wrap">
      <div style="width:88px;height:88px;border-radius:14px;overflow:hidden;background:var(--border);flex-shrink:0">
        <?php if (!empty($iso['listing_thumb'])): ?>
          <img src="<?= UPLOAD_URL . h($iso['listing_thumb']) ?>" alt="<?= h($iso['listing_title']) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><i class="bi bi-image" style="font-size:2rem"></i></div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:var(--sp-2);flex-wrap:wrap;margin-bottom:6px">
          <h2 style="margin:0;font-size:1.2rem"><?= h($iso['title']) ?></h2>
          <span class="badge badge-<?= $iso['status'] === 'active' ? 'success' : 'warning' ?>">
            <?= $iso['status'] === 'active' ? 'فعال' : ($iso['status'] === 'paused' ? 'متوقف' : ($iso['status'] === 'completed' ? 'تکمیل شده' : 'حذف شده')) ?>
          </span>
        </div>
        <div class="fs-sm" style="color:var(--text-secondary);margin-bottom:6px">
          <i class="bi bi-arrow-left-right"></i> من: <strong><?= h($iso['listing_title']) ?></strong> رو دارم
          <span class="mx-2">·</span>
          دنبال <strong><?= h($iso['category_name']) ?></strong> می‌گردم
          <?php if (!empty($iso['city'])): ?>
          <span class="mx-2">·</span>
          <i class="bi bi-geo-alt"></i> <?= h($iso['city']) ?><?php if (!empty($iso['neighborhood'])): ?> - <?= h($iso['neighborhood']) ?><?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($iso['description'])): ?>
        <div class="fs-sm" style="color:var(--text-muted)"><?= h($iso['description']) ?></div>
        <?php endif; ?>
        <div style="display:flex;gap:var(--sp-4);margin-top:var(--sp-3);flex-wrap:wrap">
          <div><span style="font-weight:700;font-size:1.1rem;color:var(--primary)"><?= fmt_num(count($matches)) ?></span><span class="fs-sm" style="color:var(--text-muted);margin-inline-start:6px">تطابق پیدا شده</span></div>
          <div><span style="font-weight:700;font-size:1.1rem;color:var(--accent)"><?= fmt_num(count($excellent) + count($good)) ?></span><span class="fs-sm" style="color:var(--text-muted);margin-inline-start:6px">تطابق خوب و عالی</span></div>
        </div>
      </div>
    </div>
  </div>

  <?php if (empty($matches)): ?>
  <div class="card mb-6">
    <div class="card-body" style="padding:var(--sp-8);text-align:center">
      <i class="bi bi-search" style="font-size:3rem;color:var(--text-muted);opacity:.3"></i>
      <h3 style="margin:var(--sp-4) 0 var(--sp-2)">هنوز تطابقی پیدا نشده</h3>
      <p style="color:var(--text-muted);max-width:520px;margin:0 auto var(--sp-5)">
        سیستم دائماً در حال جستجو است. می‌توانید عنوان ISO را دقیق‌تر کنید یا صبر کنید تا آگهی‌های جدید ثبت شوند.
      </p>
      <div style="display:flex;gap:var(--sp-2);justify-content:center;flex-wrap:wrap">
        <form method="post" style="display:inline">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="regenerate_matches">
          <input type="hidden" name="iso_id" value="<?= (int)$id ?>">
          <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise"></i> جستجوی مجدد</button>
        </form>
        <a href="<?= APP_URL ?>/iso/edit?id=<?= (int)$id ?>" class="btn btn-outline">ویرایش ISO</a>
      </div>
    </div>
  </div>
  <?php else: ?>

  <?php foreach ([
      ['عالی', 'success', $excellent, '80 تا ۱۰۰٪ تطابق'],
      ['خوب', 'primary', $good,      '6۰ تا ۷۹٪ تطابق'],
      ['احتمالی', 'warning', $possible, '۴۰ تا ۵۹٪ تطابق'],
  ] as [$label, $color, $list, $subtitle]): if (empty($list)) continue; ?>
  <div class="dash-panel-card mb-6">
    <div class="dash-panel-card__head">
      <h3><i class="bi bi-lightning-charge" style="color:var(--<?= $color ?>)"></i> تطابق‌های <?= $label ?></h3>
      <span class="badge badge-<?= $color ?> fs-xs"><?= $subtitle ?></span>
    </div>
    <div style="padding:14px 18px">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:var(--sp-4)">
      <?php foreach ($list as $m):
        $score = (int)$m['score'];
      ?>
        <div class="card" style="margin:0;overflow:hidden;display:flex;flex-direction:column">
          <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$m['listing_id'] ?>" style="display:block">
            <div style="position:relative;aspect-ratio:16/10;background:var(--border)">
              <?php if (!empty($m['thumb'])): ?>
                <img src="<?= UPLOAD_URL . h($m['thumb']) ?>" alt="<?= h($m['title']) ?>" style="width:100%;height:100%;object-fit:cover">
              <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><i class="bi bi-image" style="font-size:2.5rem"></i></div>
              <?php endif; ?>
              <div style="position:absolute;top:10px;inset-inline-end:10px">
                <span class="match-score-pill match-score-pill--<?= $score >= 80 ? 'excellent' : ($score >= 60 ? 'good' : 'possible') ?>">
                  <?= $score ?>٪
                </span>
              </div>
              <?php if ($m['status'] === 'interested'): ?>
              <div style="position:absolute;top:10px;inset-inline-start:10px"><span class="badge badge-success"><i class="bi bi-hand-thumbs-up"></i> علاقه‌مند شدید</span></div>
              <?php elseif ($m['status'] === 'viewed'): ?>
              <div style="position:absolute;top:10px;inset-inline-start:10px"><span class="badge badge-info fs-xs"><i class="bi bi-eye"></i> مشاهده شده</span></div>
              <?php endif; ?>
            </div>
          </a>
          <div style="padding:var(--sp-4);display:flex;flex-direction:column;gap:var(--sp-2);flex:1">
            <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$m['listing_id'] ?>" style="font-weight:700;font-size:.95rem;color:inherit;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= h($m['title']) ?></a>
            <div class="fs-xs" style="color:var(--text-secondary)">
              <i class="bi bi-person"></i> <?= h($m['seller_name']) ?>
              <?php if (!empty($m['city'])): ?> · <i class="bi bi-geo-alt"></i> <?= h($m['city']) ?><?php endif; ?>
              <?php if (isset($m['distance_km']) && $m['distance_km'] !== null): ?> · <?= fmt_num($m['distance_km']) ?>km<?php endif; ?>
            </div>
            <?php if (!empty($m['estimated_value']) && $m['estimated_value'] > 0): ?>
            <div class="fs-sm" style="color:var(--primary);font-weight:600"><i class="bi bi-tags"></i> ارزش تقریبی: <?= fmt_num($m['estimated_value']) ?></div>
            <?php endif; ?>
            <?php if (!empty($m['match_reason'])): ?>
            <div class="match-reason-box" style="margin-top:auto"><?= h($m['match_reason']) ?></div>
            <?php endif; ?>
            <div style="display:flex;gap:var(--sp-2);margin-top:var(--sp-3);flex-wrap:wrap">
              <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$m['listing_id'] ?>" class="btn btn-primary btn-sm flex-1">مشاهده آگهی</a>
              <form method="post" style="display:inline;flex:1">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="mark_interested">
                <input type="hidden" name="listing_id" value="<?= (int)$m['listing_id'] ?>">
                <button type="submit" class="btn btn-accent btn-sm w-100"<?= $m['status'] === 'interested' ? ' disabled' : '' ?>>
                  <i class="bi bi-hand-thumbs-up"></i> پیشنهاد معاوضه
                </button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('این تطابق رد شود؟')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="listing_id" value="<?= (int)$m['listing_id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)" title="رد کردن"><i class="bi bi-x-lg"></i></button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!empty($weak)): ?>
  <details class="dash-panel-card mb-6" style="padding:0">
    <summary class="dash-panel-card__head" style="cursor:pointer;user-select:none">
      <h3 style="display:flex;align-items:center;gap:var(--sp-2)"><i class="bi bi-dash-circle" style="color:var(--text-muted)"></i> تطابق‌های ضعیف (کمتر از ۴۰٪)</h3>
      <span class="badge fs-xs"><?= fmt_num(count($weak)) ?> مورد</span>
    </summary>
    <div style="padding:14px 18px">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:var(--sp-3);opacity:.8">
      <?php foreach ($weak as $m): ?>
        <div class="card" style="margin:0;padding:var(--sp-3);display:flex;gap:var(--sp-3);align-items:center">
          <div style="width:64px;height:64px;border-radius:10px;overflow:hidden;background:var(--border);flex-shrink:0">
            <?php if (!empty($m['thumb'])): ?>
              <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$m['listing_id'] ?>"><img src="<?= UPLOAD_URL . h($m['thumb']) ?>" style="width:100%;height:100%;object-fit:cover"></a>
            <?php else: ?>
              <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><i class="bi bi-image"></i></div>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$m['listing_id'] ?>" style="font-weight:600;font-size:.85rem;color:inherit;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($m['title']) ?></a>
            <div class="fs-xs" style="color:var(--text-muted)"><?= h($m['seller_name']) ?><?php if (!empty($m['city'])): ?> · <?= h($m['city']) ?><?php endif; ?></div>
          </div>
          <span class="match-score-pill match-score-pill--possible" style="font-size:.7rem;padding:2px 8px"><?= (int)$m['score'] ?>٪</span>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </details>
  <?php endif; ?>

  <?php endif; ?>
</div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
