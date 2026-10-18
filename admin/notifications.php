<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

/** Maximum stored title length (matches notifications.title). */
$notifMaxTitle = 200;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    $redirect = APP_URL . '/admin/notifications.php';
    $mode     = ($_POST['mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $title    = trim((string) ($_POST['title'] ?? ''));
    $body     = trim((string) ($_POST['body'] ?? ''));
    $link     = trim((string) ($_POST['link'] ?? ''));

    if ($title === '') {
        admin_set_flash('عنوان اعلان را وارد کنید.', 'error');
        header('Location: ' . $redirect);
        exit;
    }
    if (mb_strlen($title) > $notifMaxTitle) {
        admin_set_flash('عنوان اعلان بیش از حد مجاز طولانی است.', 'error');
        header('Location: ' . $redirect);
        exit;
    }
    if (!db_has_table('notifications')) {
        admin_set_flash('جدول اعلان‌ها ساخته نشده است. ابتدا صفحه مهاجرت را باز کنید.', 'error');
        header('Location: ' . $redirect);
        exit;
    }

    $linkValue = null;
    if ($link !== '') {
        $linkValue = preg_match('~^https?://~i', $link) ? $link : APP_URL . '/' . ltrim($link, '/');
    }

    $row = [
        'type'    => 'announcement',
        'title'   => mb_strimwidth($title, 0, $notifMaxTitle),
        'body'    => $body !== '' ? $body : null,
        'link'    => $linkValue,
        'is_read' => 0,
    ];

    $sent = 0;

    if ($mode === 'selected') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['user_ids'] ?? [])),
            static fn($v) => $v > 0
        )));
        if (!$ids) {
            admin_set_flash('کاربری انتخاب نشده است.', 'error');
            header('Location: ' . $redirect);
            exit;
        }
        foreach ($ids as $id) {
            DB::insert('notifications', ['user_id' => $id] + $row);
            $sent++;
        }
    } else {
        $where = '1=1';
        if (!empty($_POST['only_active'])) {
            $where .= ' AND is_active = 1';
        }
        if (!empty($_POST['exclude_admins'])) {
            $where .= " AND role <> 'admin'";
        }
        $stmt = DB::query(
            "INSERT INTO notifications (user_id, type, title, body, link, is_read, created_at)
             SELECT id, ?, ?, ?, ?, 0, NOW() FROM users WHERE {$where}",
            [$row['type'], $row['title'], $row['body'], $row['link']]
        );
        $sent = $stmt->rowCount();
    }

    if ($sent > 0) {
        admin_set_flash("اعلان برای {$sent} کاربر ثبت شد.");
    } else {
        admin_set_flash('کاربری برای دریافت اعلان پیدا نشد.', 'error');
    }

    header('Location: ' . $redirect);
    exit;
}

[$flash, $flashType] = admin_flash();

$q = clean($_GET['q'] ?? '');
$initialMode = ($_GET['mode'] ?? '') === 'selected' ? 'selected' : 'all';

$userParams = [];
$userWhere  = '1=1';
if ($q !== '') {
    $userWhere .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $userParams = ["%{$q}%", "%{$q}%", "%{$q}%"];
}
$users = DB::fetchAll(
    "SELECT id, name, email, role, is_active
     FROM users
     WHERE {$userWhere}
     ORDER BY created_at DESC
     LIMIT 300",
    $userParams
);

$stats = DB::fetch(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN is_active = 1 AND role <> 'admin' THEN 1 ELSE 0 END) AS active_nonadmin
     FROM users"
) ?: ['total' => 0, 'active_nonadmin' => 0];

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1>ارسال اعلان</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">ارسال اعلان درون‌سایتی به همه کاربران یا به کاربران انتخاب‌شده.</p>
  </div>
</div>

<div class="admin-stats">
  <div class="admin-stat">
    <div class="admin-stat__value"><?= number_format((int) $stats['total']) ?></div>
    <div class="admin-stat__label">کل کاربران</div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat__value"><?= number_format((int) $stats['active_nonadmin']) ?></div>
    <div class="admin-stat__label">فعال و بدون نقش مدیر</div>
  </div>
</div>

<form method="POST" onsubmit="return notifConfirm();">
  <?= csrf_field() ?>

  <div class="card mb-5">
    <div class="card-header"><strong>گیرندگان</strong></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--sp-3);margin-bottom:var(--sp-4)">
        <label class="admin-toggle">
          <input type="radio" name="mode" value="all" <?= $initialMode === 'all' ? 'checked' : '' ?> onchange="notifMode()">
          <span>
            <strong>همه کاربران</strong>
            <small class="fs-xs" style="color:var(--text-muted)">اعلان برای همه حساب‌های کاربری</small>
          </span>
        </label>
        <label class="admin-toggle">
          <input type="radio" name="mode" value="selected" <?= $initialMode === 'selected' ? 'checked' : '' ?> onchange="notifMode()">
          <span>
            <strong>کاربران خاص</strong>
            <small class="fs-xs" style="color:var(--text-muted)">انتخاب دستی از فهرست کاربران</small>
          </span>
        </label>
      </div>

      <div id="blockAll" style="display:<?= $initialMode === 'all' ? 'grid' : 'none' ?>;gap:var(--sp-2)">
        <label class="admin-toggle">
          <input type="checkbox" name="only_active" value="1" checked>
          <span><strong>فقط کاربران فعال</strong></span>
        </label>
        <label class="admin-toggle">
          <input type="checkbox" name="exclude_admins" value="1" checked>
          <span><strong>مدیران دریافت نکنند</strong></span>
        </label>
      </div>

      <div id="blockSelected" style="display:<?= $initialMode === 'selected' ? 'block' : 'none' ?>">
        <div style="display:flex;gap:var(--sp-2);margin-bottom:var(--sp-3)">
          <input type="search" id="userSearch" class="form-control" value="<?= h($q) ?>" placeholder="جستجوی نام، ایمیل یا شماره…">
          <button type="button" class="btn btn-outline" onclick="notifSearch()">جستجو</button>
        </div>

        <div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-2)">
          <span id="selectedCount">0</span> کاربر انتخاب شده — نمایش
          <?= number_format(count($users)) ?> کاربر برتر
        </div>

        <div style="max-height:420px;overflow:auto;border:1px solid var(--border);border-radius:var(--radius-md)">
          <table class="admin-table">
            <thead>
              <tr>
                <th style="width:44px"><input type="checkbox" onclick="notifToggleAll(this)" title="انتخاب همه"></th>
                <th>نام</th>
                <th>ایمیل</th>
                <th>نقش</th>
                <th>وضعیت</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$users): ?>
              <tr><td colspan="5" style="text-align:center;color:var(--text-muted)">کاربری یافت نشد.</td></tr>
              <?php endif; ?>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><input type="checkbox" name="user_ids[]" value="<?= (int) $u['id'] ?>" onclick="notifCount()"></td>
                <td><?= h((string) ($u['name'] ?: '—')) ?></td>
                <td class="fs-xs" dir="ltr" style="text-align:right"><?= h((string) ($u['email'] ?? '')) ?></td>
                <td><?= ($u['role'] ?? 'user') === 'admin' ? '<span class="badge badge-primary">مدیر</span>' : 'کاربر' ?></td>
                <td><?= $u['is_active'] ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-5">
    <div class="card-header"><strong>متن اعلان</strong></div>
    <div class="card-body">
      <div class="form-group" style="margin-bottom:var(--sp-3)">
        <label class="form-label">عنوان</label>
        <input type="text" name="title" id="notifTitle" class="form-control"
               maxlength="<?= $notifMaxTitle ?>" placeholder="مثال: به‌روزرسانی قوانین سایت" required>
      </div>
      <div class="form-group" style="margin-bottom:var(--sp-3)">
        <label class="form-label">متن</label>
        <textarea name="body" class="form-control" rows="4" placeholder="توضیحات اعلان…"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">لینک (اختیاری)</label>
        <input type="text" name="link" class="form-control" dir="ltr" placeholder="/listings یا https://swaapin.ir/listings">
        <div class="fs-xs" style="color:var(--text-muted);margin-top:var(--sp-1)">
          کاربر با کلیک روی اعلان به این آدرس می‌رود. اگر مسیر داخلی بنویسید، به‌صورت خودکار به دامنه سایت اضافه می‌شود.
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary btn-lg">
    <i class="bi bi-megaphone"></i> ارسال اعلان
  </button>
</form>

<script>
function notifMode() {
  var mode = document.querySelector('input[name="mode"]:checked').value;
  document.getElementById('blockAll').style.display = mode === 'all' ? 'grid' : 'none';
  document.getElementById('blockSelected').style.display = mode === 'selected' ? 'block' : 'none';
}
function notifSearch() {
  var q = document.getElementById('userSearch').value.trim();
  var url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('mode', 'selected');
  if (q) { url.searchParams.set('q', q); }
  window.location.href = url.toString();
}
function notifToggleAll(el) {
  document.querySelectorAll('input[name="user_ids[]"]').forEach(function (c) { c.checked = el.checked; });
  notifCount();
}
function notifCount() {
  var n = document.querySelectorAll('input[name="user_ids[]"]:checked').length;
  var out = document.getElementById('selectedCount');
  if (out) { out.textContent = n; }
}
function notifConfirm() {
  var mode = document.querySelector('input[name="mode"]:checked').value;
  if (document.getElementById('notifTitle').value.trim() === '') {
    alert('عنوان اعلان را وارد کنید.');
    return false;
  }
  var msg;
  if (mode === 'selected') {
    var n = document.querySelectorAll('input[name="user_ids[]"]:checked').length;
    if (n === 0) { alert('حداقل یک کاربر را انتخاب کنید.'); return false; }
    msg = 'ارسال اعلان به ' + n + ' کاربر انتخاب‌شده؟';
  } else {
    msg = 'اعلان برای همه کاربران واجد شرط ارسال شود؟';
  }
  return confirm(msg);
}
document.addEventListener('DOMContentLoaded', function () { notifCount(); });
</script>
<?php
$content = ob_get_clean();
render_admin_head('ارسال اعلان');
render_admin_shell($admin, 'notifications', $content);
render_admin_footer();
