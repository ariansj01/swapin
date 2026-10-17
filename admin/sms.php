<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();

/** Recipients per provider request. */
$smsBatchSize = 100;
$smsMaxLength = 1000;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    $redirect = APP_URL . '/admin/sms.php';
    $mode     = ($_POST['mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $message  = trim((string) ($_POST['message'] ?? ''));

    if ($message === '') {
        admin_set_flash('متن پیامک را وارد کنید.', 'error');
        header('Location: ' . $redirect);
        exit;
    }
    if (mb_strlen($message) > $smsMaxLength) {
        admin_set_flash('متن پیامک بیش از حد مجاز طولانی است.', 'error');
        header('Location: ' . $redirect);
        exit;
    }

    if (!defined('SMS_ENABLED') || !SMS_ENABLED
        || !defined('SMS_IRANPAYAMAK_API_KEY') || sms_is_placeholder((string) SMS_IRANPAYAMAK_API_KEY)) {
        admin_set_flash('سرویس پیامک فعال یا تنظیم نشده است. مقادیر sms_secrets.php را بررسی کنید.', 'error');
        header('Location: ' . $redirect);
        exit;
    }

    $phones = [];

    if ($mode === 'selected') {
        $ids = array_values(array_filter(
            array_map('intval', (array) ($_POST['user_ids'] ?? [])),
            static fn($v) => $v > 0
        ));
        $ids = array_values(array_unique($ids));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::fetchAll(
                "SELECT phone FROM users WHERE id IN ($placeholders) AND phone IS NOT NULL AND phone <> ''",
                $ids
            );
            foreach ($rows as $r) {
                $phones[] = (string) $r['phone'];
            }
        }
        if (!$phones) {
            admin_set_flash('کاربری انتخاب نشده یا برای کاربران انتخابی شماره‌ای ثبت نشده است.', 'error');
            header('Location: ' . $redirect);
            exit;
        }
    } else {
        $where = "phone IS NOT NULL AND phone <> ''";
        if (!empty($_POST['only_active'])) {
            $where .= ' AND is_active = 1';
        }
        if (!empty($_POST['exclude_admins'])) {
            $where .= " AND role <> 'admin'";
        }
        $rows = DB::fetchAll("SELECT phone FROM users WHERE {$where}");
        foreach ($rows as $r) {
            $phones[] = (string) $r['phone'];
        }
    }

    // Normalize + de-duplicate recipients.
    $unique = [];
    foreach ($phones as $p) {
        $digits = preg_replace('/\D+/', '', $p);
        if ($digits !== '') {
            $unique[$digits] = $p;
        }
    }
    $recipients = array_values($unique);

    if (!$recipients) {
        admin_set_flash('شماره معتبری برای ارسال پیدا نشد.', 'error');
        header('Location: ' . $redirect);
        exit;
    }

    $sent = 0;
    $failed = 0;
    $lastError = '';
    foreach (array_chunk($recipients, $smsBatchSize) as $chunk) {
        if (send_simple_sms($chunk, $message)) {
            $sent += count($chunk);
        } else {
            $failed += count($chunk);
            $lastError = last_sms_error();
        }
    }

    sms_log_record(
        (int) ($admin['id'] ?? 0),
        $mode,
        $message,
        count($recipients),
        $sent,
        $failed,
        $lastError
    );

    if ($failed === 0) {
        admin_set_flash("پیامک برای {$sent} شماره ارسال شد.");
    } elseif ($sent > 0) {
        admin_set_flash("پیامک برای {$sent} شماره ارسال شد و {$failed} شماره ناموفق بود." . ($lastError !== '' ? " ({$lastError})" : ''), 'error');
    } else {
        admin_set_flash('ارسال پیامک ناموفق بود: ' . ($lastError !== '' ? $lastError : 'خطای نامشخص'), 'error');
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
    "SELECT id, name, phone, role, is_active
     FROM users
     WHERE {$userWhere}
     ORDER BY created_at DESC
     LIMIT 300",
    $userParams
);

$stats = DB::fetch(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN phone IS NOT NULL AND phone <> '' THEN 1 ELSE 0 END) AS with_phone,
            SUM(CASE WHEN is_active = 1 AND role <> 'admin' AND phone IS NOT NULL AND phone <> '' THEN 1 ELSE 0 END) AS active_nonadmin
     FROM users"
) ?: ['total' => 0, 'with_phone' => 0, 'active_nonadmin' => 0];

$smsHistory = [];
if (sms_logs_ensure_table()) {
    try {
        $smsHistory = DB::fetchAll(
            "SELECT id, scope, message, recipients_total, sent_count, failed_count, error, created_at
             FROM sms_logs
             ORDER BY id DESC
             LIMIT 50"
        );
    } catch (Throwable) {
        $smsHistory = [];
    }
}

$smsReady = (defined('SMS_ENABLED') && SMS_ENABLED
    && defined('SMS_IRANPAYAMAK_API_KEY') && !sms_is_placeholder((string) SMS_IRANPAYAMAK_API_KEY));

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1>ارسال پیامک</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">ارسال پیامک گروهی به همه کاربران یا به کاربران انتخاب‌شده.</p>
  </div>
</div>

<?php if (!$smsReady): ?>
<div class="alert alert-warning mb-5">
  <i class="bi bi-exclamation-triangle"></i>
  سرویس پیامک فعال یا تنظیم نشده است. برای ارسال، مقادیر
  <code>includes/sms_secrets.php</code> را تکمیل کنید.
</div>
<?php endif; ?>

<div class="admin-stats">
  <div class="admin-stat">
    <div class="admin-stat__value"><?= number_format((int) $stats['total']) ?></div>
    <div class="admin-stat__label">کل کاربران</div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat__value"><?= number_format((int) $stats['with_phone']) ?></div>
    <div class="admin-stat__label">دارای شماره تماس</div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat__value"><?= number_format((int) $stats['active_nonadmin']) ?></div>
    <div class="admin-stat__label">فعال و بدون نقش مدیر</div>
  </div>
</div>

<form method="POST" id="smsForm" onsubmit="return smsConfirm();">
  <?= csrf_field() ?>

  <div class="card mb-5">
    <div class="card-header"><strong>گیرندگان</strong></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--sp-3);margin-bottom:var(--sp-4)">
        <label class="admin-toggle">
          <input type="radio" name="mode" value="all" <?= $initialMode === 'all' ? 'checked' : '' ?> onchange="smsMode()">
          <span>
            <strong>همه کاربران</strong>
            <small class="fs-xs" style="color:var(--text-muted)">ارسال به تمام شماره‌های ثبت‌شده</small>
          </span>
        </label>
        <label class="admin-toggle">
          <input type="radio" name="mode" value="selected" <?= $initialMode === 'selected' ? 'checked' : '' ?> onchange="smsMode()">
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
          <button type="button" class="btn btn-outline" onclick="smsSearch()">جستجو</button>
        </div>

        <div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-2)">
          <span id="selectedCount">0</span> کاربر انتخاب شده — نمایش
          <?= number_format(count($users)) ?> کاربر برتر
        </div>

        <div style="max-height:420px;overflow:auto;border:1px solid var(--border);border-radius:var(--radius-md)">
          <table class="admin-table">
            <thead>
              <tr>
                <th style="width:44px"><input type="checkbox" onclick="smsToggleAll(this)" title="انتخاب همه"></th>
                <th>نام</th>
                <th>شماره</th>
                <th>نقش</th>
                <th>وضعیت</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$users): ?>
              <tr><td colspan="5" style="text-align:center;color:var(--text-muted)">کاربری یافت نشد.</td></tr>
              <?php endif; ?>
              <?php foreach ($users as $u):
                  $phone = trim((string) ($u['phone'] ?? ''));
                  $hasPhone = $phone !== '';
              ?>
              <tr>
                <td>
                  <input type="checkbox" name="user_ids[]" value="<?= (int) $u['id'] ?>"
                         <?= $hasPhone ? '' : 'disabled' ?> onclick="smsCount()">
                </td>
                <td><?= h((string) ($u['name'] ?: '—')) ?></td>
                <td class="fs-xs" dir="ltr" style="text-align:right"><?= $hasPhone ? h($phone) : '<span style="color:var(--text-muted)">بدون شماره</span>' ?></td>
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
    <div class="card-header"><strong>متن پیامک</strong></div>
    <div class="card-body">
      <div class="form-group" style="margin-bottom:var(--sp-3)">
        <textarea id="smsMessage" name="message" class="form-control" rows="4" maxlength="<?= $smsMaxLength ?>"
                  placeholder="متن پیامک را وارد کنید…" oninput="smsUpdateLen()"></textarea>
      </div>
      <div class="fs-xs" style="color:var(--text-muted)">
        تعداد کاراکتر: <span id="smsLen">0</span> — تعداد پیامک: <span id="smsParts">0</span>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary btn-lg" id="smsSubmit">
    <i class="bi bi-send"></i> ارسال پیامک
  </button>
</form>

<div class="card">
  <div class="card-header"><strong>تاریخچه ارسال‌ها</strong> <span class="fs-xs" style="color:var(--text-muted)">(۵۰ مورد آخر)</span></div>
  <div class="card-body" style="padding:0">
    <table class="admin-table">
      <thead>
        <tr>
          <th>#</th>
          <th>تاریخ</th>
          <th>گیرندگان</th>
          <th>متن پیامک</th>
          <th>کل</th>
          <th>موفق</th>
          <th>ناموفق</th>
          <th>خطا</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$smsHistory): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">هنوز پیامکی ارسال نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($smsHistory as $row): ?>
        <tr>
          <td><?= (int) $row['id'] ?></td>
          <td class="fs-xs" dir="ltr" style="text-align:right"><?= h((string) $row['created_at']) ?></td>
          <td><?= ($row['scope'] ?? 'all') === 'selected' ? 'کاربران خاص' : 'همه کاربران' ?></td>
          <td class="fs-xs"><?= h(mb_strimwidth((string) $row['message'], 0, 80, '…')) ?></td>
          <td><?= (int) $row['recipients_total'] ?></td>
          <td><span class="badge badge-success"><?= (int) $row['sent_count'] ?></span></td>
          <td><?= (int) $row['failed_count'] > 0 ? '<span class="badge badge-danger">' . (int) $row['failed_count'] . '</span>' : '0' ?></td>
          <td class="fs-xs" style="color:var(--danger)"><?= h((string) ($row['error'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function smsMode() {
  var mode = document.querySelector('input[name="mode"]:checked').value;
  document.getElementById('blockAll').style.display = mode === 'all' ? 'grid' : 'none';
  document.getElementById('blockSelected').style.display = mode === 'selected' ? 'block' : 'none';
}
function smsSearch() {
  var q = document.getElementById('userSearch').value.trim();
  var url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('mode', 'selected');
  if (q) { url.searchParams.set('q', q); }
  window.location.href = url.toString();
}
function smsToggleAll(el) {
  document.querySelectorAll('input[name="user_ids[]"]:not(:disabled)').forEach(function (c) { c.checked = el.checked; });
  smsCount();
}
function smsCount() {
  var n = document.querySelectorAll('input[name="user_ids[]"]:checked').length;
  var out = document.getElementById('selectedCount');
  if (out) { out.textContent = n; }
}
function smsUpdateLen() {
  var len = document.getElementById('smsMessage').value.length;
  var parts = len === 0 ? 0 : (len <= 70 ? 1 : Math.ceil(len / 67));
  document.getElementById('smsLen').textContent = len;
  document.getElementById('smsParts').textContent = parts;
}
function smsConfirm() {
  var mode = document.querySelector('input[name="mode"]:checked').value;
  if (document.getElementById('smsMessage').value.trim() === '') {
    alert('متن پیامک را وارد کنید.');
    return false;
  }
  var msg;
  if (mode === 'selected') {
    var n = document.querySelectorAll('input[name="user_ids[]"]:checked').length;
    if (n === 0) { alert('حداقل یک کاربر را انتخاب کنید.'); return false; }
    msg = 'ارسال پیامک به ' + n + ' کاربر انتخاب‌شده؟';
  } else {
    msg = 'پیامک برای همه کاربران واجد شرط ارسال شود؟';
  }
  return confirm(msg);
}
document.addEventListener('DOMContentLoaded', function () { smsUpdateLen(); smsCount(); });
</script>
<?php
$content = ob_get_clean();
render_admin_head('ارسال پیامک');
render_admin_shell($admin, 'sms', $content);
render_admin_footer();
