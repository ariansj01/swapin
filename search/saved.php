<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid  = (int)$user['id'];
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');
    $searchId = (int)($_POST['search_id'] ?? 0);

    if ($action === 'delete' && $searchId) {
        delete_saved_search($uid, $searchId);
        $flash = 'جستجو حذف شد.';
    } elseif ($action === 'toggle_alert' && $searchId) {
        $enabled = !empty($_POST['enabled']);
        toggle_saved_search_alert($uid, $searchId, $enabled);
        $flash = $enabled ? 'هشدار فعال شد.' : 'هشدار غیرفعال شد.';
    }
}

$searches = fetch_user_saved_searches($uid);

render_head('جستجوهای ذخیره‌شده | ' . APP_NAME, 'مدیریت جستجوها و هشدارهای هوشمند', [
    'canonical' => APP_URL . '/search/saved',
    'robots'    => 'noindex, nofollow',
]);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/shops.css?v=<?= @filemtime(__DIR__ . '/../src/css/shops.css') ?: time() ?>">

<main class="section-sm" id="main-content">
  <div class="container-md">
    <header class="saved-search-head d-flex justify-between align-center flex-wrap gap-3 mb-6">
      <div>
        <h1><i class="bi bi-bookmark-star"></i> جستجوهای ذخیره‌شده</h1>
        <p class="text-muted mb-0">با ثبت آگهی جدید مطابق نیاز شما، بلافاصله هشدار دریافت می‌کنید.</p>
      </div>
      <a href="<?= APP_URL ?>/search/ai" class="btn btn-accent"><i class="bi bi-stars"></i> جستجوی جدید</a>
    </header>

    <?php if ($flash): ?>
    <div class="alert alert-<?= h($flashType) ?> mb-5"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if (!$searches): ?>
    <div class="card"><div class="card-body text-center py-8">
      <i class="bi bi-bell-slash" style="font-size:2.5rem;color:var(--text-muted)"></i>
      <h2 class="mt-4">هنوز جستجویی ذخیره نکرده‌اید</h2>
      <p class="text-muted">از جستجوی هوشمند AI استفاده کنید و دکمه «ذخیره + هشدار» را بزنید.</p>
      <a href="<?= APP_URL ?>/search/ai" class="btn btn-primary mt-4">شروع جستجو</a>
    </div></div>
    <?php else: ?>
    <div class="saved-search-list">
      <?php foreach ($searches as $s): ?>
      <article class="card saved-search-item mb-4">
        <div class="card-body">
          <div class="saved-search-item__top">
            <div>
              <h3><?= h($s['title']) ?></h3>
              <p class="saved-search-item__need"><?= h(mb_strimwidth($s['need_text'], 0, 160, '…')) ?></p>
            </div>
            <span class="badge <?= $s['alert_enabled'] ? 'badge-success' : 'badge-secondary' ?>">
              <?= $s['alert_enabled'] ? 'هشدار فعال' : 'هشدار خاموش' ?>
            </span>
          </div>
          <div class="saved-search-item__meta">
            <?php if ($s['city']): ?><span><i class="bi bi-geo-alt"></i> <?= h($s['city']) ?></span><?php endif; ?>
            <?php if ($s['cat_name']): ?><span><i class="bi bi-tag"></i> <?= h($s['cat_name']) ?></span><?php endif; ?>
            <span><i class="bi bi-bell"></i> <?= fmt_num((int)$s['hit_count']) ?> هشدار</span>
            <span><i class="bi bi-clock"></i> <?= timeago($s['created_at']) ?></span>
          </div>
          <div class="saved-search-item__actions">
            <a href="<?= APP_URL ?>/search/ai?need=<?= urlencode($s['need_text']) ?>&city=<?= urlencode($s['city'] ?? '') ?>" class="btn btn-outline btn-sm">
              <i class="bi bi-search"></i> جستجو مجدد
            </a>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="search_id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="action" value="toggle_alert">
              <input type="hidden" name="enabled" value="<?= $s['alert_enabled'] ? '0' : '1' ?>">
              <button type="submit" class="btn btn-outline btn-sm">
                <i class="bi bi-bell<?= $s['alert_enabled'] ? '-slash' : '' ?>"></i>
                <?= $s['alert_enabled'] ? 'خاموش کردن' : 'فعال کردن' ?>
              </button>
            </form>
            <form method="POST" class="d-inline" onsubmit="return confirm('حذف این جستجو؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="search_id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button type="submit" class="btn btn-ghost btn-sm text-danger"><i class="bi bi-trash"></i> حذف</button>
            </form>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card mt-6 saved-search-push-card">
      <div class="card-body">
        <h3><i class="bi bi-bell-fill"></i> اعلان مرورگر</h3>
        <p class="text-muted">برای دریافت پوش‌نوتیفیکیشن حتی وقتی سواَپین باز نیست، اجازه اعلان را فعال کنید.</p>
        <button type="button" class="btn btn-primary" id="enable-push-btn"><i class="bi bi-bell"></i> فعال‌سازی اعلان</button>
        <span id="push-status" class="fs-sm text-muted ms-3"></span>
      </div>
    </div>
  </div>
</main>

<?php
render_footer();
