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

/**
 * Validate the shared discount fields (everything except the code itself) so
 * single and bulk creation reuse exactly the same rules.
 *
 * @return array{0:string[],1:array} [errors, data]
 */
function admin_discount_validate_fields(array $post): array {
    $errors = [];

    $type     = (string)($post['type'] ?? 'percent');
    $value    = (int)($post['value'] ?? 0);
    $capRaw   = trim((string)($post['max_discount'] ?? ''));
    $usesRaw  = trim((string)($post['max_uses'] ?? ''));
    $startRaw = trim((string)($post['starts_at'] ?? ''));
    $expRaw   = trim((string)($post['expires_at'] ?? ''));
    $isActive = array_key_exists('is_active', $post) ? (int)(bool)$post['is_active'] : 0;

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
    if ($startRaw !== '') {
        $parsed = parse_jalali_date_input($startRaw);
        if ($parsed === null) {
            $errors[] = 'تاریخ شروع نامعتبر است (نمونه: ۱۴۰۴/۰۴/۲۳).';
        } else {
            $startsAt = $parsed . ' 00:00:00';
        }
    }

    $expiresAt = null;
    if ($expRaw !== '') {
        $parsed = parse_jalali_date_input($expRaw);
        if ($parsed === null) {
            $errors[] = 'تاریخ پایان نامعتبر است (نمونه: ۱۴۰۴/۰۴/۲۳).';
        } else {
            $expiresAt = $parsed . ' 23:59:59';
        }
    }

    return [$errors, [
        'type'         => $type,
        'value'        => $value,
        'max_discount' => $maxDiscount,
        'max_uses'     => $maxUses,
        'starts_at'    => $startsAt,
        'expires_at'   => $expiresAt,
        'is_active'    => $isActive,
    ]];
}

/**
 * Render the shared discount inputs used by both the single and bulk forms.
 * Date values are expected as Jalali strings.
 */
function admin_discount_fields_html(array $v): string {
    $type   = ($v['type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
    $value  = $v['value'] ?? '';
    $cap    = ($v['max_discount'] ?? '') === '' ? '' : (int)$v['max_discount'];
    $uses   = ($v['max_uses'] ?? '') === '' ? '' : (int)$v['max_uses'];
    $starts = (string)($v['starts_at'] ?? '');
    $expires = (string)($v['expires_at'] ?? '');
    $active = array_key_exists('is_active', $v) ? (int)$v['is_active'] === 1 : true;
    $usedCount = $v['used_count'] ?? null;

    ob_start(); ?>
    <div class="form-group">
      <label class="form-label" for="type">نوع تخفیف</label>
      <select id="type" name="type" class="form-control" onchange="discountToggleCap()">
        <option value="percent" <?= $type === 'percent' ? 'selected' : '' ?>>درصدی</option>
        <option value="amount" <?= $type === 'amount' ? 'selected' : '' ?>>مبلغ ثابت (تومان)</option>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label" for="value">مقدار تخفیف</label>
      <input type="number" id="value" name="value" class="form-control" min="1" step="1" required
             value="<?= $value === '' ? '' : (int)$value ?>" placeholder="۱۰">
      <p class="fs-xs" style="color:var(--text-muted);margin:6px 0 0">اگر نوع «درصدی» است، عدد بین ۱ تا ۱۰۰ وارد کنید.</p>
    </div>

    <div class="form-group" id="cap-group">
      <label class="form-label" for="max_discount">سقف تخفیف (تومان) — اختیاری</label>
      <input type="number" id="max_discount" name="max_discount" class="form-control" min="1" step="1000"
             value="<?= $cap === '' ? '' : $cap ?>" placeholder="حداکثر مبلغ تخفیف درصدی">
    </div>

    <div class="form-group">
      <label class="form-label" for="max_uses">سقف تعداد استفاده — اختیاری</label>
      <input type="number" id="max_uses" name="max_uses" class="form-control" min="1" step="1"
             value="<?= $uses === '' ? '' : $uses ?>" placeholder="خالی = نامحدود">
      <?php if ($usedCount !== null): ?>
      <p class="fs-xs" style="color:var(--text-muted);margin:6px 0 0">تاکنون <?= fmt_num((int)$usedCount) ?> بار استفاده شده است.</p>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="starts_at">تاریخ شروع اعتبار — اختیاری</label>
      <input type="text" id="starts_at" name="starts_at" class="form-control" dir="ltr"
             value="<?= h($starts) ?>" placeholder="۱۴۰۴/۰۴/۲۳">
    </div>

    <div class="form-group">
      <label class="form-label" for="expires_at">تاریخ پایان اعتبار — اختیاری</label>
      <input type="text" id="expires_at" name="expires_at" class="form-control" dir="ltr"
             value="<?= h($expires) ?>" placeholder="۱۴۰۴/۰۵/۳۰">
    </div>

    <div class="form-group" style="grid-column: span 2">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;margin:0;cursor:pointer">
        <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
        فعال باشد
      </label>
    </div>
    <?php return (string)ob_get_clean();
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
        $id   = (int)($_POST['id'] ?? 0);
        $code = discount_code_normalize((string)($_POST['code'] ?? ''));

        if (!preg_match('/^[A-Z0-9_-]{3,64}$/', $code)) {
            $errors[] = 'کد باید ۳ تا ۶۴ کاراکتر و شامل حروف انگلیسی، عدد، خط تیره یا آندرلاین باشد.';
        }

        [$fieldErrors, $fields] = admin_discount_validate_fields($_POST);
        $errors = array_merge($errors, $fieldErrors);

        if (!$errors) {
            $dupe = DB::fetch('SELECT id FROM discount_codes WHERE code = ? AND id <> ?', [$code, $id]);
            if ($dupe) {
                $errors[] = 'این کد تخفیف قبلاً ثبت شده است.';
            }
        }

        if (!$errors) {
            $data = ['code' => $code] + $fields;

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

    if ($action === 'bulk_save') {
        $codesArr   = (array)($_POST['code'] ?? []);
        $typesArr   = (array)($_POST['type'] ?? []);
        $valuesArr  = (array)($_POST['value'] ?? []);
        $capsArr    = (array)($_POST['max_discount'] ?? []);
        $usesArr    = (array)($_POST['max_uses'] ?? []);
        $startsArr  = (array)($_POST['starts_at'] ?? []);
        $expiresArr = (array)($_POST['expires_at'] ?? []);
        $activesArr = (array)($_POST['is_active'] ?? []);

        $prepared   = [];   // code => validated fields, ready to insert
        $bulkRows   = [];   // rows kept for re-rendering after an error
        $batchSeen  = [];
        $duplicates = [];

        for ($i = 0, $n = count($codesArr); $i < $n; $i++) {
            $row = [
                'code'         => (string)($codesArr[$i] ?? ''),
                'type'         => (string)($typesArr[$i] ?? 'percent'),
                'value'        => (string)($valuesArr[$i] ?? ''),
                'max_discount' => (string)($capsArr[$i] ?? ''),
                'max_uses'     => (string)($usesArr[$i] ?? ''),
                'starts_at'    => (string)($startsArr[$i] ?? ''),
                'expires_at'   => (string)($expiresArr[$i] ?? ''),
                'is_active'    => (string)($activesArr[$i] ?? '1'),
                'errors'       => [],
            ];

            // Skip rows the admin left completely empty.
            if (trim($row['code']) === '' && trim($row['value']) === ''
                && trim($row['max_discount']) === '' && trim($row['max_uses']) === ''
                && trim($row['starts_at']) === '' && trim($row['expires_at']) === '') {
                continue;
            }

            $ri = count($bulkRows);
            $bulkRows[] = $row;

            $norm = discount_code_normalize($row['code']);
            if (!preg_match('/^[A-Z0-9_-]{3,64}$/', $norm)) {
                $bulkRows[$ri]['errors'][] = 'کد نامعتبر است (۳ تا ۶۴ کاراکتر: حروف انگلیسی، عدد، خط تیره یا آندرلاین).';
            }

            [$fieldErrors, $fields] = admin_discount_validate_fields($row);
            if ($fieldErrors) {
                $bulkRows[$ri]['errors'] = array_merge($bulkRows[$ri]['errors'], $fieldErrors);
            }

            if ($bulkRows[$ri]['errors']) {
                continue;
            }
            if (isset($batchSeen[$norm])) {
                $bulkRows[$ri]['errors'][] = 'این کد در همین لیست تکرار شده است.';
                continue;
            }
            $batchSeen[$norm] = true;
            $prepared[$norm] = $fields;
        }

        if (!$bulkRows) {
            $errors[] = 'حداقل یک کد تخفیف وارد کنید.';
        } elseif (count($bulkRows) > 100) {
            $errors[] = 'حداکثر ۱۰۰ کد در هر بار قابل افزودن است (' . fmt_num(count($bulkRows)) . ' کد وارد شده).';
        }

        $hasRowErrors = false;
        foreach ($bulkRows as $r) {
            if (!empty($r['errors'])) { $hasRowErrors = true; break; }
        }

        if (!$errors && !$hasRowErrors) {
            if ($prepared) {
                $ph = implode(', ', array_fill(0, count($prepared), '?'));
                $existing = array_column(
                    DB::fetchAll("SELECT code FROM discount_codes WHERE code IN ($ph)", array_keys($prepared)),
                    'code'
                );
                foreach ($existing as $ex) {
                    unset($prepared[$ex]);
                    $duplicates[] = $ex;
                }
            }

            foreach ($prepared as $code => $fields) {
                DB::insert('discount_codes', ['code' => $code] + $fields);
            }

            $msg = fmt_num(count($prepared)) . ' کد تخفیف اضافه شد.';
            if ($duplicates) {
                $msg .= ' ' . fmt_num(count($duplicates)) . ' کد تکراری نادیده گرفته شد.';
            }
            admin_set_flash($msg);
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

// Active tab: editing always shows the single form, otherwise the bulk form
// stays open when its own POST fails validation.
$tab = (string)($_GET['tab'] ?? 'single');
if ($editId > 0) {
    $tab = 'single';
} elseif (($_POST['action'] ?? '') === 'bulk_save') {
    $tab = 'bulk';
} elseif (($_POST['action'] ?? '') === 'save') {
    $tab = 'single';
}
if (!in_array($tab, ['single', 'bulk'], true)) {
    $tab = 'single';
}

// Values for the single form.
$singleValues = [
    'type'         => $editing['type'] ?? 'percent',
    'value'        => $editing ? (int)$editing['value'] : '',
    'max_discount' => ($editing && $editing['max_discount'] !== null) ? (int)$editing['max_discount'] : '',
    'max_uses'     => ($editing && $editing['max_uses'] !== null) ? (int)$editing['max_uses'] : '',
    'starts_at'    => admin_discount_jalali_input($editing['starts_at'] ?? null),
    'expires_at'   => admin_discount_jalali_input($editing['expires_at'] ?? null),
    'is_active'    => !$editing || (int)$editing['is_active'] === 1 ? 1 : 0,
    'used_count'   => $editing['used_count'] ?? null,
];

// Rows for the bulk form — keep the submitted rows after a validation error,
// otherwise start with a single blank row.
if (empty($bulkRows)) {
    $bulkRows = [[
        'code' => '', 'type' => 'percent', 'value' => '', 'max_discount' => '',
        'max_uses' => '', 'starts_at' => '', 'expires_at' => '', 'is_active' => '1',
        'errors' => [],
    ]];
}

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

<?php if (!$editing): ?>
<div class="admin-tabs mb-5">
  <a href="?tab=single" class="admin-tab<?= $tab === 'single' ? ' admin-tab--active' : '' ?>">افزودن تکی</a>
  <a href="?tab=bulk" class="admin-tab<?= $tab === 'bulk' ? ' admin-tab--active' : '' ?>">افزودن گروهی (تا ۱۰۰ کد)</a>
</div>
<?php endif; ?>

<?php if ($tab === 'bulk' && !$editing): ?>
<form method="POST" class="card" style="padding:var(--sp-6);display:grid;gap:var(--sp-5);margin-bottom:var(--sp-6)" id="bulkForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_save">

  <div>
    <h3 style="margin:0 0 6px">افزودن گروهی کد تخفیف</h3>
    <p class="fs-xs" style="color:var(--text-muted);margin:0">هر کد یک ردیف با جزئیات مستقل خودش دارد (نوع، مقدار، سقف، تعداد استفاده، تاریخ‌ها و وضعیت). حداکثر ۱۰۰ کد در هر بار.</p>
  </div>

  <div style="border:1px dashed var(--border);border-radius:12px;padding:var(--sp-4);background:var(--bg)">
    <div class="fs-sm" style="font-weight:700;margin-bottom:var(--sp-3)"><i class="bi bi-lightning-charge"></i> افزودن سریع</div>
    <textarea id="bulkQuickCodes" class="form-control" rows="2" dir="ltr"
              placeholder="چند کد را با کاما یا خط جدید بچسبانید: SWAPIN10, SWAPIN11, SWAPIN12"></textarea>
    <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-top:var(--sp-3)">
      <select id="bulkQuickType" class="form-control" style="max-width:150px">
        <option value="percent">درصدی</option>
        <option value="amount">مبلغ ثابت</option>
      </select>
      <input type="number" id="bulkQuickValue" class="form-control" min="1" step="1"
             placeholder="مقدار پیش‌فرض (اختیاری)" style="max-width:220px">
      <button type="button" class="btn btn-outline" id="bulkQuickAdd"><i class="bi bi-plus-lg"></i> افزودن به جدول</button>
    </div>
  </div>

  <div style="overflow-x:auto">
    <table class="admin-table" id="bulkTable" style="min-width:960px">
      <thead>
        <tr>
          <th>کد</th>
          <th>نوع</th>
          <th>مقدار</th>
          <th>سقف تخفیف</th>
          <th>تعداد استفاده</th>
          <th>از تاریخ</th>
          <th>تا تاریخ</th>
          <th>وضعیت</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="bulkBody">
        <?php foreach ($bulkRows as $row): ?>
        <tr>
          <td>
            <input type="text" name="code[]" class="form-control" dir="ltr" value="<?= h($row['code']) ?>" placeholder="SWAPIN10">
            <?php if (!empty($row['errors'])): ?>
            <p class="fs-xs" style="color:var(--danger);margin:6px 0 0"><?= h(implode(' ', $row['errors'])) ?></p>
            <?php endif; ?>
          </td>
          <td>
            <select name="type[]" class="form-control">
              <option value="percent" <?= $row['type'] === 'percent' ? 'selected' : '' ?>>درصدی</option>
              <option value="amount" <?= $row['type'] === 'amount' ? 'selected' : '' ?>>مبلغ</option>
            </select>
          </td>
          <td><input type="number" name="value[]" class="form-control" min="1" step="1" value="<?= h($row['value']) ?>" placeholder="۱۰"></td>
          <td><input type="number" name="max_discount[]" class="form-control" min="1" step="1000" value="<?= h($row['max_discount']) ?>" placeholder="—"></td>
          <td><input type="number" name="max_uses[]" class="form-control" min="1" step="1" value="<?= h($row['max_uses']) ?>" placeholder="∞"></td>
          <td><input type="text" name="starts_at[]" class="form-control" dir="ltr" value="<?= h($row['starts_at']) ?>" placeholder="۱۴۰۴/۰۴/۲۳"></td>
          <td><input type="text" name="expires_at[]" class="form-control" dir="ltr" value="<?= h($row['expires_at']) ?>" placeholder="۱۴۰۴/۰۵/۳۰"></td>
          <td>
            <select name="is_active[]" class="form-control">
              <option value="1" <?= (string)$row['is_active'] === '1' ? 'selected' : '' ?>>فعال</option>
              <option value="0" <?= (string)$row['is_active'] === '0' ? 'selected' : '' ?>>غیرفعال</option>
            </select>
          </td>
          <td><button type="button" class="btn btn-sm btn-danger" data-row-remove aria-label="حذف ردیف"><i class="bi bi-x-lg"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-3);flex-wrap:wrap">
    <button type="button" class="btn btn-outline btn-sm" id="bulkAddRow"><i class="bi bi-plus-lg"></i> افزودن ردیف</button>
    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-square"></i> افزودن کدها</button>
  </div>
</form>
<?php else: ?>
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

    <?= admin_discount_fields_html($singleValues) ?>
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
<?php endif; ?>

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
  var typeEl = document.getElementById('type');
  var capEl  = document.getElementById('cap-group');
  if (!typeEl || !capEl) return;
  capEl.style.display = (typeEl.value === 'percent') ? '' : 'none';
}
discountToggleCap();

(function () {
  var body  = document.getElementById('bulkBody');
  if (!body) return;
  var MAX = 100;

  function rowCount() { return body.rows.length; }

  function buildRow(code, type, value) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" name="code[]" class="form-control" dir="ltr" placeholder="SWAPIN10"></td>' +
      '<td><select name="type[]" class="form-control">' +
        '<option value="percent">درصدی</option><option value="amount">مبلغ</option>' +
      '</select></td>' +
      '<td><input type="number" name="value[]" class="form-control" min="1" step="1" placeholder="۱۰"></td>' +
      '<td><input type="number" name="max_discount[]" class="form-control" min="1" step="1000" placeholder="—"></td>' +
      '<td><input type="number" name="max_uses[]" class="form-control" min="1" step="1" placeholder="∞"></td>' +
      '<td><input type="text" name="starts_at[]" class="form-control" dir="ltr" placeholder="۱۴۰۴/۰۴/۲۳"></td>' +
      '<td><input type="text" name="expires_at[]" class="form-control" dir="ltr" placeholder="۱۴۰۴/۰۵/۳۰"></td>' +
      '<td><select name="is_active[]" class="form-control">' +
        '<option value="1" selected>فعال</option><option value="0">غیرفعال</option>' +
      '</select></td>' +
      '<td><button type="button" class="btn btn-sm btn-danger" data-row-remove aria-label="حذف ردیف"><i class="bi bi-x-lg"></i></button></td>';
    tr.querySelector('input[name="code[]"]').value = code || '';
    tr.querySelector('select[name="type[]"]').value = type || 'percent';
    if (value !== undefined && value !== null && value !== '') {
      tr.querySelector('input[name="value[]"]').value = value;
    }
    return tr;
  }

  document.getElementById('bulkAddRow').addEventListener('click', function () {
    if (rowCount() >= MAX) { alert('حداکثر ۱۰۰ کد در هر بار قابل افزودن است.'); return; }
    var tr = buildRow('', 'percent', '');
    body.appendChild(tr);
    tr.querySelector('input[name="code[]"]').focus();
  });

  document.getElementById('bulkQuickAdd').addEventListener('click', function () {
    var raw   = document.getElementById('bulkQuickCodes').value || '';
    var defType = document.getElementById('bulkQuickType').value;
    var defValue = document.getElementById('bulkQuickValue').value;
    var codes = raw.split(/[\s,;]+/).map(function (c) { return c.trim(); }).filter(Boolean);
    if (!codes.length) return;
    var added = 0;
    codes.forEach(function (code) {
      if (rowCount() >= MAX) return;
      body.appendChild(buildRow(code.toUpperCase(), defType, defValue));
      added++;
    });
    document.getElementById('bulkQuickCodes').value = '';
    if (added < codes.length) alert('حداکثر ۱۰۰ کد مجاز است؛ بقیه اضافه نشدند.');
  });

  body.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-row-remove]');
    if (!btn) return;
    var tr = btn.closest('tr');
    if (tr) tr.remove();
    if (!rowCount()) body.appendChild(buildRow('', 'percent', ''));
  });
})();
</script>
<?php
$content = ob_get_clean();

render_admin_head('کد تخفیف');
render_admin_shell($admin, 'discounts', $content);
render_admin_footer();
