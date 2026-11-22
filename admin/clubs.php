<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/clubs.php';

$admin = require_admin();
admin_require_permission('clubs');
clubs_ensure_tables();

$id = (int)($_GET['id'] ?? 0);
$statusFilter = clean($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');
    $clubId = (int)($_POST['club_id'] ?? 0);
    $note = trim((string)($_POST['admin_note'] ?? ''));

    if ($action === 'approve' && $clubId > 0) {
        if (clubs_set_status($clubId, 'approved', (int)$admin['id'], $note)) {
            admin_set_flash('کلاب تأیید شد.');
        } else {
            admin_set_flash('تأیید کلاب ناموفق بود.', 'error');
        }
        header('Location: ' . APP_URL . '/admin/clubs.php?status=approved');
        exit;
    }
    if ($action === 'reject' && $clubId > 0) {
        if (clubs_set_status($clubId, 'rejected', (int)$admin['id'], $note)) {
            admin_set_flash('کلاب رد شد.');
        } else {
            admin_set_flash('رد کلاب ناموفق بود.', 'error');
        }
        header('Location: ' . APP_URL . '/admin/clubs.php?id=' . $clubId);
        exit;
    }
    if ($action === 'save_rules' && $clubId > 0) {
        $rules = trim((string)($_POST['rules'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if (mb_strlen($rules) > 5000) {
            admin_set_flash('متن قوانین خیلی بلند است.', 'error');
        } else {
            clubs_update_content($clubId, $rules, $description);
            admin_set_flash('قوانین و توضیحات کلاب ذخیره شد.');
        }
        header('Location: ' . APP_URL . '/admin/clubs.php?id=' . $clubId . '&status=' . urlencode($statusFilter));
        exit;
    }

    $adsRedirect = APP_URL . '/admin/clubs.php?status=' . urlencode($statusFilter) . '#club-ads';
    if ($action === 'delete_banner') {
        clubs_banner_delete((int)($_POST['banner_id'] ?? 0));
        admin_set_flash('بنر تبلیغاتی حذف شد.');
        header('Location: ' . $adsRedirect);
        exit;
    }
    if ($action === 'toggle_banner') {
        $bannerId = (int)($_POST['banner_id'] ?? 0);
        $row = clubs_banner_get($bannerId);
        if ($row) {
            clubs_banner_save($bannerId, ['is_active' => ((int)$row['is_active'] === 1 ? 0 : 1)]);
            admin_set_flash('وضعیت بنر تغییر کرد.');
        }
        header('Location: ' . $adsRedirect);
        exit;
    }
    if ($action === 'save_banner') {
        $bannerId = (int)($_POST['banner_id'] ?? 0);
        $existing = clubs_banner_get($bannerId);
        $rawLink = trim((string)($_POST['link_url'] ?? ''));
        $link = clubs_safe_link($rawLink);
        $image = null;
        $errors = [];
        if ($rawLink !== '' && $link === '') {
            $errors[] = 'لینک باید با http:// یا https:// یا / شروع شود.';
        }
        if (!empty($_FILES['image']['name'])) {
            $stored = clubs_store_image($_FILES['image'], 'banner');
            if (empty($stored['filename'])) {
                $errors[] = $stored['error'] ?? 'آپلود تصویر بنر ناموفق بود.';
            } else {
                $image = $stored['filename'];
            }
        }
        if (!$existing && !$image) {
            $errors[] = 'برای بنر جدید، تصویر الزامی است.';
        }
        if ($errors) {
            admin_set_flash(implode(' ', $errors), 'error');
            header('Location: ' . $adsRedirect);
            exit;
        }
        $data = [
            'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150),
            'subtitle' => mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 255),
            'cta_label' => mb_substr(trim((string)($_POST['cta_label'] ?? '')), 0, 80),
            'link_url' => $link,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($image) {
            $data['image'] = $image;
        }
        clubs_banner_save($bannerId, $data);
        if ($bannerId > 0 && $image && !empty($existing['image']) && $existing['image'] !== $image) {
            $old = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $existing['image'];
            if (is_file($old)) {
                @unlink($old);
            }
        }
        admin_set_flash($bannerId > 0 ? 'بنر تبلیغاتی ذخیره شد.' : 'بنر تبلیغاتی اضافه شد.');
        header('Location: ' . $adsRedirect);
        exit;
    }
}

[$flash, $flashType] = admin_flash();
$statusLabels = clubs_status_labels();
$clubBanners = clubs_banners_all();
$editingBanner = clubs_banner_get((int)($_GET['edit_banner'] ?? 0));

$detail = null;
if ($id > 0) {
    $detail = DB::fetch(
        'SELECT cl.*, u.name AS user_name, u.phone AS user_phone, c.name AS category_name
         FROM clubs cl
         JOIN users u ON u.id = cl.owner_id
         LEFT JOIN categories c ON c.id = cl.category_id
         WHERE cl.id = ?',
        [$id]
    );
}

$where = '1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND cl.status = ?';
    $params[] = $statusFilter;
}

$rows = DB::fetchAll(
    "SELECT cl.*, u.name AS user_name, c.name AS category_name
     FROM clubs cl
     JOIN users u ON u.id = cl.owner_id
     LEFT JOIN categories c ON c.id = cl.category_id
     WHERE {$where}
     ORDER BY FIELD(cl.status,'pending','approved','rejected','archived'), cl.created_at DESC
     LIMIT 100",
    $params
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <h1>کلاب‌ها</h1>
</div>

<div class="admin-tabs mb-5">
  <?php foreach (['pending' => 'در انتظار', 'approved' => 'تأیید شده', 'rejected' => 'رد شده', 'all' => 'همه'] as $key => $label): ?>
  <a href="?status=<?= h($key) ?>" class="admin-tab<?= $statusFilter === $key ? ' admin-tab--active' : '' ?>">
    <?= h($label) ?>
    <?php if ($key === 'pending' && ($cnt = clubs_pending_count()) > 0): ?>
    <span class="admin-nav__badge"><?= $cnt ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="card mb-6" id="club-ads" style="padding:24px">
  <h2 style="margin:0 0 8px;font-size:1.1rem">بنر تبلیغاتی صفحه کلاب‌ها</h2>
  <p class="fs-sm" style="color:var(--text-muted);margin:0 0 16px">این بنر بالای صفحه همه کلاب‌ها نمایش داده می‌شود. فقط یک بنر می‌تواند فعال باشد.</p>

  <form method="POST" enctype="multipart/form-data" style="display:grid;gap:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_banner">
    <input type="hidden" name="banner_id" value="<?= $editingBanner ? (int)$editingBanner['id'] : 0 ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div class="form-group">
        <label class="form-label">عنوان</label>
        <input type="text" class="form-control" name="title" maxlength="150" value="<?= h((string)($editingBanner['title'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">متن دکمه</label>
        <input type="text" class="form-control" name="cta_label" maxlength="80" value="<?= h((string)($editingBanner['cta_label'] ?? '')) ?>" placeholder="مشاهده">
      </div>
      <div class="form-group" style="grid-column:span 2">
        <label class="form-label">زیرعنوان</label>
        <input type="text" class="form-control" name="subtitle" maxlength="255" value="<?= h((string)($editingBanner['subtitle'] ?? '')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">لینک</label>
        <input type="text" class="form-control" name="link_url" value="<?= h((string)($editingBanner['link_url'] ?? '')) ?>" placeholder="https:// یا /path">
      </div>
      <div class="form-group">
        <label class="form-label">تصویر <?= $editingBanner ? '(برای تغییر، فایل جدید انتخاب کنید)' : '' ?></label>
        <input type="file" class="form-control" name="image" accept="image/png,image/jpeg" <?= $editingBanner ? '' : 'required' ?>>
        <?php if ($editingBanner && !empty($editingBanner['image'])): ?>
        <img src="<?= h(clubs_image_url($editingBanner['image'])) ?>" alt="" style="margin-top:8px;max-height:80px;border-radius:8px;border:1px solid var(--border)">
        <?php endif; ?>
      </div>
      <div class="form-group" style="grid-column:span 2">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer">
          <input type="checkbox" name="is_active" value="1" <?= (!$editingBanner || (int)$editingBanner['is_active'] === 1) ? 'checked' : '' ?>>
          نمایش در صفحه کلاب‌ها
        </label>
      </div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <?php if ($editingBanner): ?>
      <a class="btn btn-ghost" href="<?= APP_URL ?>/admin/clubs.php?status=<?= h($statusFilter) ?>#club-ads">انصراف</a>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary"><?= $editingBanner ? 'ذخیره بنر' : 'افزودن بنر' ?></button>
    </div>
  </form>

  <table class="admin-table" style="margin-top:18px">
    <thead>
      <tr>
        <th>تصویر</th>
        <th>عنوان</th>
        <th>وضعیت</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$clubBanners): ?>
      <tr><td colspan="4" class="text-muted">هنوز بنری ثبت نشده است.</td></tr>
      <?php endif; ?>
      <?php foreach ($clubBanners as $b): ?>
      <tr>
        <td>
          <?php if (!empty($b['image'])): ?>
          <img src="<?= h(clubs_image_url($b['image'])) ?>" alt="" style="width:96px;height:48px;object-fit:cover;border-radius:8px">
          <?php endif; ?>
        </td>
        <td><?= h($b['title'] ?: '—') ?></td>
        <td>
          <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_banner">
            <input type="hidden" name="banner_id" value="<?= (int)$b['id'] ?>">
            <button type="submit" class="btn btn-sm <?= (int)$b['is_active'] === 1 ? 'btn-primary' : 'btn-outline' ?>">
              <?= (int)$b['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
            </button>
          </form>
        </td>
        <td>
          <a class="btn btn-sm btn-outline" href="?status=<?= h($statusFilter) ?>&edit_banner=<?= (int)$b['id'] ?>#club-ads">ویرایش</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('این بنر حذف شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_banner">
            <input type="hidden" name="banner_id" value="<?= (int)$b['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($detail): ?>
<div class="card mb-6" style="padding:24px">
  <h2 style="margin:0 0 16px;font-size:1.1rem"><?= h($detail['name']) ?></h2>
  <div class="fs-sm" style="line-height:2;color:var(--text-muted)">
    <div><strong>سازنده:</strong> <?= h($detail['user_name']) ?></div>
    <div><strong>تلفن:</strong> <?= h($detail['user_phone'] ?? '—') ?></div>
    <div><strong>دسته‌بندی:</strong> <?= h($detail['category_name'] ?? '—') ?></div>
    <div><strong>حریم خصوصی:</strong> <?= h(clubs_privacy_labels()[$detail['privacy']] ?? $detail['privacy']) ?></div>
    <div><strong>تأیید عضویت:</strong> <?= !empty($detail['require_approval']) ? 'فعال' : 'غیرفعال' ?></div>
    <div><strong>وضعیت:</strong> <?= h($statusLabels[$detail['status']] ?? $detail['status']) ?></div>
    <div><strong>تاریخ:</strong> <?= h($detail['created_at']) ?></div>
  </div>

  <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">
    <?php if (!empty($detail['cover_image'])): ?>
    <div>
      <div class="fs-sm text-muted">کاور</div>
      <img src="<?= h(UPLOAD_URL . $detail['cover_image']) ?>" alt="" style="max-height:120px;border-radius:8px;border:1px solid var(--border)">
    </div>
    <?php endif; ?>
    <?php if (!empty($detail['avatar_image'])): ?>
    <div>
      <div class="fs-sm text-muted">پروفایل</div>
      <img src="<?= h(UPLOAD_URL . $detail['avatar_image']) ?>" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--border)">
    </div>
    <?php endif; ?>
  </div>

  <form method="POST" class="mt-4">
    <?= csrf_field() ?>
    <input type="hidden" name="club_id" value="<?= (int)$detail['id'] ?>">
    <input type="hidden" name="action" value="save_rules">
    <div class="form-group">
      <label class="form-label">درباره کلاب</label>
      <textarea class="form-control" name="description" rows="3" maxlength="500"><?= h((string)($detail['description'] ?? '')) ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">قوانین کلاب (تب قوانین در صفحه کلاب)</label>
      <textarea class="form-control" name="rules" rows="8" maxlength="5000" placeholder="قوانین نمایش‌داده‌شده در تب قوانین را اینجا وارد کنید."><?= h((string)($detail['rules'] ?? '')) ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">ذخیره قوانین</button>
    <?php if (!empty($detail['slug'])): ?>
    <a class="btn btn-outline" href="<?= APP_URL ?>/clubs/<?= h($detail['slug']) ?>?tab=rules" target="_blank">مشاهده تب قوانین</a>
    <?php endif; ?>
  </form>

  <?php if (!empty($detail['admin_note'])): ?>
  <div class="alert alert-warning mt-4"><strong>یادداشت ادمین:</strong> <?= h($detail['admin_note']) ?></div>
  <?php endif; ?>

  <?php if (($detail['status'] ?? '') === 'pending'): ?>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="club_id" value="<?= (int)$detail['id'] ?>">
      <input type="hidden" name="action" value="approve">
      <button type="submit" class="btn btn-primary" onclick="return confirm('کلاب تأیید شود؟')">تأیید کلاب</button>
    </form>
    <form method="POST" style="flex:1;min-width:280px">
      <?= csrf_field() ?>
      <input type="hidden" name="club_id" value="<?= (int)$detail['id'] ?>">
      <input type="hidden" name="action" value="reject">
      <div style="display:flex;gap:8px">
        <input type="text" class="form-control" name="admin_note" placeholder="دلیل رد (اختیاری)">
        <button type="submit" class="btn btn-danger">رد</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" style="overflow:auto">
  <table class="admin-table">
    <thead>
      <tr>
        <th>نام</th>
        <th>سازنده</th>
        <th>دسته</th>
        <th>حریم</th>
        <th>وضعیت</th>
        <th>تاریخ</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
      <tr><td colspan="7" class="text-muted">موردی یافت نشد.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= h($row['name']) ?></td>
        <td><?= h($row['user_name']) ?></td>
        <td><?= h($row['category_name'] ?? '—') ?></td>
        <td><?= h(clubs_privacy_labels()[$row['privacy']] ?? $row['privacy']) ?></td>
        <td><?= h($statusLabels[$row['status']] ?? $row['status']) ?></td>
        <td><?= h($row['created_at']) ?></td>
        <td><a href="?id=<?= (int)$row['id'] ?>&status=<?= h($statusFilter) ?>">مشاهده</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('کلاب‌ها');
render_admin_shell($admin, 'clubs', $content);
render_admin_footer();
