<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
admin_require_permission('discounts');
[$flash, $flashType] = admin_flash();

if (!db_has_table('discount_codes')) {
    header('Location: ' . APP_URL . '/migrate.php');
    exit;
}

$errors = [];

/** Convert a Gregorian datetime to a Jalali input string (1404/04/23). */
function admin_discount_jalali_input(?string $datetime): string {
    if (empty($datetime)) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            DB::query('DELETE FROM discount_codes WHERE id = ?', [$id]);
            admin_set_flash('کد تخفیف حذف شد.');
        }
        header('Location: ' . APP_URL . '/admin/discounts.php');
        exit;
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = DB::fetch('SELECT * FROM discount_codes WHERE id = ?', [$id]);
        if ($row) {
            DB::update('discount_codes', ['is_active' => ((int)$row['is_active'] === 1 ? 0 : 1)], 'id = ?', [$id]);
            admin_set_flash('وضعیت کد تخفیف تغییر کرد.');
        }
        header('Location: ' . APP_URL . '/admin/discounts.php');
        exit;
    }

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $code   = discount_code_normalize((string)($_POST['code'] ?? ''));
        $type   = (string)($_POST['type'] ?? 'percent');
        $value  = (int)($_POST['value'] ?? 0);
        $capRaw = trim((string)($_POST['max_discount'] ?? ''));
        $usesRaw = trim((string)($_POST['max_uses'] ?? ''));
        $startsRaw = trim((string)($_POST['starts_at'] ?? ''));
        $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!preg_match('/^[A-Z0-9_-]{3,64}$/', $code)) {
            $errors[] = 'کد باید ۳ تا ۶۴ کاراکتر و شامل حروف انگلیسی، عدد، خط تیره یا آندرلاین باشد.';
        }
        if (!in_array($type, ['amount', 'percent'], true)) {
            $type = 'percent';
        }
        if ($value <= 0) {
            $errors[] = 'مقدار تخفیف باید بزرگ‌تر از صفر باشد.';
        }
        if ($type === 'percent' && $value > 100) {
            $errors[] = 'درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.';
        }

        $maxDiscount = null;
        if ($type === 'percent' && $capRaw !== '') {
            $maxDiscount = (int)$capRaw;
            if ($maxDiscount <= 0) {
                $errors[] = 'سقف تخفیف نامعتبر است.';
                $maxDiscount = null;
            }
        }

        $maxUses = null;
        if ($usesRaw !== '') {
            $maxUses = (int)$usesRaw;
            if ($maxUses <= 0) {
                $errors[] = 'سقف تعداد استفاده نامعتبر است.';
                $maxUses = null;
            }
        }

        $startsAt = null;
        if ($startsRaw !== '') {
            $parsed = parse_jalali_date_input($startsRaw);
            if ($parsed === null) {
                $errors[] = 'تاریخ شروع نامعتبر است (نمونه: ۱۴۰۴/۰۴/۲۳).';
            } else {
                $startsAt = $parsed . ' 00:00:00';
            }
        }
        $expiresAt = null;
        if ($expiresRaw !== '') {
            $parsed = parse_jalali_date_input($expiresRaw);
            if ($parsed === null) {
                $errors[] = 'تاریخ پایان نامعتبر است (نمونه: ۱۴۰۴/۰۴/۲۳).';
            } else {
                $expiresAt = $parsed . ' 23:59:59';
            }
        }

        if (!$errors) {
            $dupe = DB::fetch('SELECT id FROM discount_codes WHERE code = ? AND id <> ?', [$code, $id]);
            if ($dupe) {
                $errors[] = 'این کد تخفیف قبلاً ثبت شده است.';
            }
        }

        if (!$errors) {
            $data = [
                'code'         => $code,
                'type'         => $type,
                'value'        => $value,
                'max_discount' => $maxDiscount,
                'max_uses'     => $maxUses,
                'starts_at'    => $startsAt,
                'expires_at'   => $expiresAt,
                'is_active'    => $isActive,
            ];

            if ($id > 0) {
                DB::update('discount_codes', $data, 'id = ?', [$id]);
                admin_set_flash('کد تخفیف به‌روزرسانی شد.');
            } else {
                DB::insert('discount_codes', $data);
                admin_set_flash('کد تخفیف جدید اضافه شد.');
            }
            header('Location: ' . APP_URL . '/admin/discounts.php');
            exit;
        }
    }
}

$editing = null;
$editId  = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = DB::fetch('SELECT * FROM discount_codes WHERE id = ?', [$editId]);
}

$codes = DB::fetchAll('SELECT * FROM discount_codes ORDER BY id DESC');

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>
<?php if ($errors): ?>
<div class="alert alert-danger mb-5"><i class="bi bi-exclamation-triangle"></i> <?= h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="admin-header">
  <div>
    <h1>کد تخفیف</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">کدهای تخفیف عددی یا درصدی برای کیف پول، ارتقای آگهی و اشتراک.</p>
  </div>
</div>

<form method="POST" class="card" style="padding:var(--sp-6);display:grid;gap:var(--sp-5);margin-bottom:var(--sp-6)">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

  <h3 style="margin:0"><?= $editing ? 'ویرایش کد تخفیف' : 'افزودن کد تخفیف جدید' ?></h3>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5)">
    <div class="form-group">
      <label class="form-label" for="code">کد تخفیف</label>
      <input type="text" id="code" name="code" class="form-control" dir="ltr" required
             value="<?= h($editing['code'] ?? '') ?>" placeholder="SWAPIN10">
      <p class="fs-xs" style="color:var(--text-muted);margin:6px 0 0">حروف انگلیسی، عدد، خط تیره و آندرلاین — بدون فاصله.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="type">نوع تخفیف</label>
      <select id="type" name="type" class="form-control" onchange="discountToggleCap()">
        <option value="percent" <?= ($editing['type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>درصدی</option>
        <option value="amount" <?= ($editing['type'] ?? '') === 'amount' ? 'selected' : '' ?>>مبلغ ثابت (تومان)</option>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label" for="value">مقدار تخفیف</label>
      <input type="number" id="value" name="value" class="form-control" min="1" step="1" required
             value="<?= $editing ? (int)$editing['value'] : '' ?>" placeholder="۱۰">
      <p class="fs-xs" style="color:var(--text-muted);margin:6px 0 0">اگر نوع «درصدی» است، عدد بین ۱ تا ۱۰۰ وارد کنید.</p>
    </div>

    <div class="form-group" id="cap-group">
      <label class="form-label" for="max_discount">سقف تخفیف (تومان) — اختیاری</label>
      <input type="number" id="max_discount" name="max_discount" class="form-control" min="1" step="1000"
             value="<?= ($editing && $editing['max_discount'] !== null) ? (int)$editing['max_discount'] : '' ?>"
             placeholder="حداکثر مبلغ تخفیف درصدی">
    </div>

    <div class="form-group">
      <label class="form-label" for="max_uses">سقف تعداد استفاده — اختیاری</label>
      <input type="number" id="max_uses" name="max_uses" class="form-control" min="1" step="1"
             value="<?= ($editing && $editing['max_uses'] !== null) ? (int)$editing['max_uses'] : '' ?>"
             placeholder="خالی = نامحدود">
      <?php if ($editing): ?>
      <p class="fs-xs" style="color:var(--text-muted);margin:6px 0 0">تاکنون <?= fmt_num((int)$editing['used_count']) ?> بار استفاده شده است.</p>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="starts_at">تاریخ شروع اعتبار — اختیاری</label>
      <input type="text" id="starts_at" name="starts_at" class="form-control" dir="ltr"
             value="<?= h(admin_discount_jalali_input($editing['starts_at'] ?? null)) ?>" placeholder="۱۴۰۴/۰۴/۲۳">
    </div>

    <div class="form-group">
      <label class="form-label" for="expires_at">تاریخ پایان اعتبار — اختیاری</label>
      <input type="text" id="expires_at" name="expires_at" class="form-control" dir="ltr"
             value="<?= h(admin_discount_jalali_input($editing['expires_at'] ?? null)) ?>" placeholder="۱۴۰۴/۰۵/۳۰">
    </div>

    <div class="form-group" style="grid-column: span 2">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer">
        <input type="checkbox" name="is_active" value="1" <?= (!$editing || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
        فعال باشد
      </label>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;gap:var(--sp-3)">
    <?php if ($editing): ?>
    <a href="<?= APP_URL ?>/admin/discounts.php" class="btn btn-ghost">انصراف</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> <?= $editing ? 'ذخیره تغییرات' : 'افزودن کد تخفیف' ?>
    </button>
  </div>
</form>

<div class="card">
<table class="admin-table">
<thead>
<tr>
<th>کد</th>
<th>تخفیف</th>
<th>استفاده</th>
<th>اعتبار</th>
<th>وضعیت</th>
<th></th>
</tr>
</thead>
<tbody>
<?php if (!$codes): ?>
<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:var(--sp-5)">هنوز کد تخفیفی ثبت نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($codes as $c): ?>
<tr>
<td><code dir="ltr" style="font-weight:700"><?= h($c['code']) ?></code></td>
<td><?= h(discount_code_description($c)) ?></td>
<td><?= fmt_num((int)$c['used_count']) ?><?= $c['max_uses'] !== null ? ' / ' . fmt_num((int)$c['max_uses']) : '' ?></td>
<td class="fs-xs">
  <?php if (!empty($c['starts_at'])): ?>از <?= persian_date($c['starts_at']) ?><br><?php endif; ?>
  <?php if (!empty($c['expires_at'])): ?>تا <?= persian_date($c['expires_at']) ?><?php endif; ?>
  <?php if (empty($c['starts_at']) && empty($c['expires_at'])): ?><span style="color:var(--text-muted)">— بدون محدودیت —</span><?php endif; ?>
</td>
<td>
  <form method="POST" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <button type="submit" class="btn btn-sm <?= (int)$c['is_active'] === 1 ? 'btn-primary' : 'btn-outline' ?>">
      <?= (int)$c['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
    </button>
  </form>
</td>
<td>
  <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/admin/discounts.php?edit=<?= (int)$c['id'] ?>">ویرایش</a>
  <form method="POST" style="display:inline" onsubmit="return confirm('این کد تخفیف حذف شود؟');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <button type="submit" class="btn btn-sm btn-danger">حذف</button>
  </form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<script>
function discountToggleCap() {
  var type = document.getElementById('type').value;
  document.getElementById('cap-group').style.display = (type === 'percent') ? '' : 'none';
}
discountToggleCap();
</script>
<?php
$content = ob_get_clean();

render_admin_head('کد تخفیف');
render_admin_shell($admin, 'discounts', $content);
render_admin_footer();
