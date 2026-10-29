<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
// مدیر اصلی (ID 1) یا هر ادمینی با دسترسی «مدیریت ادمین‌ها»
if (!admin_has_permission('manage_admins', $admin)) {
    http_response_code(403);
    include __DIR__ . '/forbidden.php';
    exit;
}

$msg = '';
$msgType = 'success';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name = clean($_POST['name'] ?? '');
        $email = clean($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $permissions = $_POST['permissions'] ?? [];
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($name && $email) {
            $data = [
                'name' => $name,
                'email' => $email,
                'role' => 'admin',
                'permissions' => json_encode($permissions),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($password) {
                $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            }

            if ($action === 'add') {
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['is_active'] = 1;
                $data['verification_level'] = 3;
                try {
                    DB::insert('users', $data);
                    $msg = 'ادمین جدید با موفقیت اضافه شد.';
                } catch (Throwable $e) {
                    $msg = 'خطا در افزودن ادمین: ' . $e->getMessage();
                    $msgType = 'error';
                }
            } else {
                try {
                    DB::update('users', $data, 'id = ? AND id != 1', [$userId]);
                    $msg = 'اطلاعات ادمین به‌روزرسانی شد.';
                } catch (Throwable $e) {
                    $msg = 'خطا در به‌روزرسانی: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 1) {
            DB::query("UPDATE users SET role = 'user', permissions = NULL WHERE id = ?", [$userId]);
            $msg = 'دسترسی ادمین از کاربر سلب شد.';
        }
    }
}

$admins = DB::fetchAll("SELECT * FROM users WHERE role = 'admin' AND id != 1 ORDER BY id DESC");

// Available sections for permissions
$sections = [
    'index'          => 'داشبورد',
    'create_listing' => 'ایجاد آگهی',
    'pages'          => 'صفحات سایت',
    'content'        => 'مدیریت محتوا',
    'banners'        => 'اسلایدر',
    'stories'        => 'استوری‌ها',
    'listings'       => 'آگهی‌ها',
    'ai_moderation'  => 'نظارت AI',
    'stores'         => 'فروشگاه‌ها',
    'store_requests' => 'درخواست فروشگاه',
    'kyc'            => 'احراز هویت',
    'inspections'    => 'بازرسی',
    'disputes'       => 'اختلافات',
    'tickets'        => 'پشتیبانی',
    'live_chat'      => 'چت زنده',
    'users'          => 'کاربران',
    'sms'            => 'پیامک',
    'notifications'  => 'اعلان‌ها',
    'manage_admins'  => 'مدیریت ادمین‌ها (فقط مدیر اصلی)'
];

ob_start();
?>
<div class="admin-header mb-5">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="admin-title">مدیریت ادمین‌ها</h1>
            <p class="text-muted">افزودن مدیر جدید و تعیین سطح دسترسی</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="bi bi-plus-lg"></i> افزودن ادمین جدید
        </button>
    </div>
</div>

<?= admin_alert_html($msg, $msgType) ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="px-4">نام</th>
                        <th>ایمیل</th>
                        <th>دسترسی‌ها</th>
                        <th>آخرین بازدید</th>
                        <th class="text-end px-4">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $a): 
                        $perms = json_decode($a['permissions'] ?? '[]', true);
                    ?>
                    <tr>
                        <td class="px-4"><strong><?= h($a['name']) ?></strong></td>
                        <td><?= h($a['email']) ?></td>
                        <td>
                            <?php if (in_array('*', $perms)): ?>
                                <span class="badge bg-success">دسترسی کامل</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php 
                                    $count = 0;
                                    foreach ($perms as $p) {
                                        if (isset($sections[$p])) {
                                            echo '<span class="badge bg-info text-dark" style="font-size: 0.7rem;">' . h($sections[$p]) . '</span>';
                                            $count++;
                                        }
                                        if ($count >= 3) {
                                            if (count($perms) > 3) echo '<span class="badge bg-light text-dark">...</span>';
                                            break;
                                        }
                                    }
                                    if ($count === 0) echo '<small class="text-muted">بدون دسترسی</small>';
                                    ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $a['last_seen'] ? timeago($a['last_seen']) : '---' ?></td>
                        <td class="text-end px-4">
                            <button class="btn btn-sm btn-outline-primary me-1" 
                                    onclick="editAdmin(<?= h(json_encode($a)) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('آیا از سلب دسترسی این ادمین مطمئن هستید؟')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($admins)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">هیچ ادمین دیگری تعریف نشده است.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="user_id" id="modalUserId" value="">
            
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">افزودن ادمین جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">نام و نام خانوادگی</label>
                        <input type="text" name="name" id="modalName" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ایمیل</label>
                        <input type="email" name="email" id="modalEmail" class="form-control" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">رمز عبور (خالی بگذارید تا تغییر نکند)</label>
                        <input type="password" name="password" id="modalPassword" class="form-control">
                    </div>
                </div>

                <label class="form-label mb-3">دسترسی به بخش‌ها:</label>
                <div class="row g-2">
                    <div class="col-12 mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="permissions[]" value="*" id="perm_all">
                            <label class="form-check-label fw-bold text-primary" for="perm_all">دسترسی کامل (مدیر کل)</label>
                        </div>
                        <hr>
                    </div>
                    <?php foreach ($sections as $key => $label): ?>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input perm-check" type="checkbox" name="permissions[]" value="<?= $key ?>" id="perm_<?= $key ?>">
                            <label class="form-check-label" for="perm_<?= $key ?>"><?= $label ?></label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<script>
function editAdmin(admin) {
    document.getElementById('modalTitle').innerText = 'ویرایش ادمین: ' + admin.name;
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalUserId').value = admin.id;
    document.getElementById('modalName').value = admin.name;
    document.getElementById('modalEmail').value = admin.email;
    document.getElementById('modalPassword').value = '';
    
    const perms = JSON.parse(admin.permissions || '[]');
    const isFullAccess = perms.includes('*');
    
    document.getElementById('perm_all').checked = isFullAccess;
    document.querySelectorAll('.perm-check').forEach(el => {
        el.checked = !isFullAccess && perms.includes(el.value);
        el.disabled = isFullAccess;
    });
    
    new bootstrap.Modal(document.getElementById('addAdminModal')).show();
}

// Toggle permissions checkboxes based on "Full Access"
document.getElementById('perm_all').addEventListener('change', function() {
    const isChecked = this.checked;
    document.querySelectorAll('.perm-check').forEach(el => {
        el.disabled = isChecked;
        if (isChecked) el.checked = false;
    });
});

// Reset modal on close
document.getElementById('addAdminModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modalTitle').innerText = 'افزودن ادمین جدید';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalUserId').value = '';
    document.getElementById('modalName').value = '';
    document.getElementById('modalEmail').value = '';
    document.getElementById('modalPassword').value = '';
    document.querySelectorAll('.form-check-input').forEach(el => el.checked = false);
});
</script>

<?php
$content = ob_get_clean();
render_admin_head('مدیریت ادمین‌ها');
render_admin_shell($admin, 'manage_admins', $content);
render_admin_footer();
?>
