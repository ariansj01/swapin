<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$id    = (int)($_GET['id'] ?? 0);
$filter = clean($_GET['filter'] ?? 'pending');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $listingId = (int)($_POST['listing_id'] ?? 0);
    $action    = clean($_POST['action'] ?? '');
    $note      = clean($_POST['note'] ?? '');

    if ($action === 'approve' && $listingId) {
        admin_approve_listing($listingId, $note);
        admin_set_flash('آگهی تأیید و منتشر شد.');
    } elseif ($action === 'reject' && $listingId) {
        if (mb_strlen($note) < 5) {
            admin_set_flash('برای رد آگهی، دلیل را بنویسید (حداقل ۵ کاراکتر).', 'error');
        } else {
            admin_reject_listing($listingId, $note);
            admin_set_flash('آگهی رد شد.');
        }
    }
    header('Location: ' . APP_URL . '/admin/listings.php' . ($listingId ? "?id=$listingId" : ''));
    exit;
}

[$flash, $flashType] = admin_flash();

if ($id) {
    $listing = DB::fetch(
        'SELECT l.*, u.name AS seller_name, u.email AS seller_email, c.name AS cat_name
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         WHERE l.id = ?',
        [$id]
    );
    $images = $listing
        ? DB::fetchAll('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC', [$id])
        : [];
}

$where = match ($filter) {
    'approved' => 'l.review_status = "approved"',
    'rejected' => 'l.review_status = "rejected"',
    default    => 'l.review_status = "pending"',
};

$list = DB::fetchAll(
    "SELECT l.id, l.title, l.review_status, l.status, l.created_at, u.name AS seller_name
     FROM listings l JOIN users u ON u.id = l.user_id
     WHERE {$where} AND l.status != 'deleted'
     ORDER BY l.created_at DESC LIMIT 100"
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1>مدیریت آگهی‌ها</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">تأیید یا رد آگهی‌های ثبت‌شده</p>
  </div>
  <div class="admin-actions">
    <a href="?filter=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-primary' : 'btn-outline' ?>">در انتظار</a>
    <a href="?filter=approved" class="btn btn-sm <?= $filter === 'approved' ? 'btn-primary' : 'btn-outline' ?>">تأیید شده</a>
    <a href="?filter=rejected" class="btn btn-sm <?= $filter === 'rejected' ? 'btn-primary' : 'btn-outline' ?>">رد شده</a>
  </div>
</div>

<?php if ($id && !empty($listing)): ?>
<div class="admin-detail-grid mb-6">
  <div class="card">
    <div class="card-header"><h3 style="margin:0"><?= h($listing['title']) ?></h3></div>
    <div class="card-body">
      <p style="line-height:1.8;white-space:pre-wrap"><?= h($listing['description']) ?></p>
      <hr style="border-color:var(--border);margin:var(--sp-4) 0">
      <div class="fs-sm" style="display:grid;gap:var(--sp-2)">
        <div><strong>دسته:</strong> <?= h($listing['cat_name']) ?></div>
        <div><strong>وضعیت کالا:</strong> <?= condition_label($listing['condition']) ?></div>
        <div><strong>ارزش:</strong> <?= $listing['estimated_value'] > 0 ? fmt_credit((float)$listing['estimated_value']) : '—' ?></div>
        <div><strong>در ازای:</strong> <?= h($listing['want_in_return']) ?></div>
        <div><strong>فروشنده:</strong> <?= h($listing['seller_name']) ?> (<?= h($listing['seller_email']) ?>)</div>
        <div><strong>شهر:</strong> <?= h($listing['city'] ?: '—') ?></div>
        <div><strong>وضعیت بررسی:</strong>
          <span class="badge badge-<?= listing_review_badge($listing['review_status']) ?>">
            <?= listing_review_label($listing['review_status']) ?>
          </span>
        </div>
        <?php if ($listing['review_note']): ?>
        <div><strong>یادداشت قبلی:</strong> <?= h($listing['review_note']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($images): ?>
      <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-top:var(--sp-4)">
        <?php foreach ($images as $img): ?>
        <a href="<?= UPLOAD_URL . h($img['filename']) ?>" target="_blank">
          <img src="<?= UPLOAD_URL . h($img['filename']) ?>" alt="" style="width:100px;height:80px;object-fit:cover;border-radius:var(--radius-md);border:1px solid var(--border)">
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">اقدام مدیر</h3></div>
    <div class="card-body">
      <?php if ($listing['review_status'] === 'pending'): ?>
      <form method="POST" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="approve">
        <div class="form-group">
          <label class="form-label">یادداشت (اختیاری)</label>
          <textarea class="form-control" name="note" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-lg"></i> تأیید و انتشار</button>
      </form>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="reject">
        <div class="form-group">
          <label class="form-label">دلیل رد <span class="required">*</span></label>
          <textarea class="form-control" name="note" rows="3" required placeholder="مثلاً: توضیحات ناقص یا نامعتبر"></textarea>
        </div>
        <button type="submit" class="btn btn-danger w-100" onclick="return confirm('آگهی رد شود؟')"><i class="bi bi-x-lg"></i> رد آگهی</button>
      </form>
      <?php else: ?>
      <p class="fs-sm" style="color:var(--text-muted)">این آگهی قبلاً بررسی شده است.</p>
      <?php if ($listing['review_status'] === 'rejected'): ?>
      <form method="POST" class="mt-4">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-outline w-100">تأیید مجدد</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/listings/view?id=<?= $id ?>" class="btn btn-ghost w-100 mt-3" target="_blank">مشاهده در سایت</a>
    </div>
  </div>
</div>
<?php elseif ($id): ?>
<div class="alert alert-danger">آگهی یافت نشد.</div>
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>عنوان</th>
        <th>فروشنده</th>
        <th>وضعیت</th>
        <th>تاریخ</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($list)): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:var(--sp-6)">موردی یافت نشد</td></tr>
      <?php else: foreach ($list as $l): ?>
      <tr>
        <td><?= $l['id'] ?></td>
        <td><?= h(mb_strimwidth($l['title'], 0, 50, '…')) ?></td>
        <td><?= h($l['seller_name']) ?></td>
        <td><span class="badge badge-<?= listing_review_badge($l['review_status']) ?>"><?= listing_review_label($l['review_status']) ?></span></td>
        <td class="fs-xs"><?= persian_date($l['created_at']) ?></td>
        <td><a href="?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline">بررسی</a></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('آگهی‌ها');
render_admin_shell($admin, 'listings', $content);
render_admin_footer();
