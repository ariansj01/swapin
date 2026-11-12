<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$user = auth_user();
clubs_ensure_tables();
$clubs = clubs_approved_list(48);

render_head('کلاب‌های سواپین | ' . APP_NAME, 'کلاب‌های سواپین؛ جایی برای جمع شدن آدم‌های هم‌علاقه و معامله بهتر.', [
    'canonical' => APP_URL . '/clubs',
]);
render_navbar($user);
?>
<main id="main-content" class="section-sm">
  <div class="container" style="max-width:960px;padding-top:28px;padding-bottom:48px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px">
      <div>
        <h1 style="margin:0 0 8px;font-size:1.6rem">کلاب‌های سواپین</h1>
        <p style="margin:0;color:var(--text-muted);line-height:1.8">آدم‌های هم‌علاقه را دور هم جمع کن؛ خرید، فروش و معاوضه را شروع کن.</p>
      </div>
      <a href="<?= APP_URL ?>/clubs/create" class="btn btn-accent"><i class="bi bi-plus-lg"></i> ساخت کلاب</a>
    </div>

    <?php if (!$clubs): ?>
    <div class="card" style="padding:36px;text-align:center">
      <p style="color:var(--text-muted);margin:0 0 16px">هنوز کلاب تأییدشده‌ای وجود ندارد. اولین کلاب را بساز.</p>
      <a href="<?= APP_URL ?>/clubs/create" class="btn btn-accent">ساخت کلاب</a>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">
      <?php foreach ($clubs as $club): ?>
      <article class="card" style="overflow:hidden">
        <div style="height:92px;background:#071A33">
          <?php if (!empty($club['cover_image'])): ?>
          <img src="<?= h(UPLOAD_URL . $club['cover_image']) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
          <?php endif; ?>
        </div>
        <div style="padding:14px 16px 16px">
          <h2 style="font-size:1rem;margin:0 0 6px"><?= h($club['name']) ?></h2>
          <div class="fs-sm" style="color:var(--text-muted);margin-bottom:8px"><?= h($club['category_name'] ?? '') ?></div>
          <span class="fs-sm" style="display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:var(--primary);font-weight:800;padding:3px 10px;border-radius:999px">
            <?= ($club['privacy'] ?? '') === 'private' ? 'کلاب خصوصی' : 'کلاب عمومی' ?>
          </span>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
<?php
render_footer();
