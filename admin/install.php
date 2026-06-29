<?php
/**
 * One-time local setup: sync admin role + password from config.
 * Visit once: /admin/install.php
 * Delete or restrict this file in production.
 */
require_once __DIR__ . '/../includes/config.php';

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1'], true)
    || str_starts_with($host, 'localhost:')
    || str_starts_with($host, '127.0.0.1:');

header('Content-Type: text/html; charset=utf-8');

if (!$isLocal) {
    http_response_code(403);
    echo '<p>این صفحه فقط در محیط local در دسترس است.</p>';
    exit;
}

$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Ensure schema columns exist (ignore if already applied)
        $pdo = DB::pdo();
        foreach (file(__DIR__ . '/../migration_admin.sql') as $line) {
            // skip — run statements via admin_sync only if role missing
        }
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `role` ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER `is_active`");
        } catch (Throwable) {}
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `kyc_note` TEXT NULL AFTER `kyc_status`");
        } catch (Throwable) {}
        try {
            $pdo->exec("ALTER TABLE `listings` ADD COLUMN `review_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `status`");
        } catch (Throwable) {}
        try {
            $pdo->exec("ALTER TABLE `listings` ADD COLUMN `review_note` TEXT NULL AFTER `review_status`");
        } catch (Throwable) {}

        admin_sync_credentials();
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$admin = DB::fetch('SELECT email, role, is_active FROM users WHERE email = ?', [ADMIN_EMAIL]);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>نصب پنل ادمین</title>
<style>
  body { font-family: Tahoma, sans-serif; max-width: 520px; margin: 40px auto; padding: 20px; line-height: 1.7; }
  .ok { background: #d4edda; padding: 12px; border-radius: 8px; }
  .err { background: #f8d7da; padding: 12px; border-radius: 8px; }
  button { padding: 10px 20px; font-size: 1rem; cursor: pointer; }
  code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; }
</style>
</head>
<body>
<h1>نصب / همگام‌سازی ادمین</h1>

<?php if ($done): ?>
<div class="ok">
  <p><strong>انجام شد.</strong> حالا وارد شوید:</p>
  <p>ایمیل: <code><?= htmlspecialchars(ADMIN_EMAIL) ?></code><br>
  رمز: <code><?= htmlspecialchars(ADMIN_DEFAULT_PASS) ?></code></p>
  <p><a href="<?= APP_URL ?>/admin/login.php">رفتن به صفحه ورود</a></p>
</div>
<?php else: ?>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<p>این صفحه نقش <strong>admin</strong> و رمز عبور را برای حساب زیر تنظیم می‌کند:</p>
<ul>
  <li>ایمیل: <code><?= htmlspecialchars(ADMIN_EMAIL) ?></code></li>
  <li>رمز: <code><?= htmlspecialchars(ADMIN_DEFAULT_PASS) ?></code></li>
</ul>

<?php if ($admin): ?>
<p>وضعیت فعلی: نقش=<code><?= htmlspecialchars($admin['role'] ?? '—') ?></code>، فعال=<?= $admin['is_active'] ? 'بله' : 'خیر' ?></p>
<?php else: ?>
<p>کاربر با این ایمیل هنوز در دیتابیس نیست — ساخته می‌شود.</p>
<?php endif; ?>

<form method="POST">
  <button type="submit">همگام‌سازی اکانت ادمین</button>
</form>
<?php endif; ?>
</body>
</html>
