<?php
/**
 * فایل یکبارمصرف: دادن دسترسی تام (Super Admin) به ادمین اصلی.
 * بعد از اجرا، این فایل را حذف کنید.
 *
 * اجرا از مرورگر:
 *   http://localhost/swaapin/grant_admin_full_access.php?key=SWAPIN-GRANT-2026
 *
 * اجرا از خط فرمان:
 *   php grant_admin_full_access.php SWAPIN-GRANT-2026
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/admin.php';

const GRANT_KEY    = 'SWAPIN-GRANT-2026';
const TARGET_EMAIL = 'Yekta.akramsani@gmail.com';

$isCli = (PHP_SAPI === 'cli');
$eol   = $isCli ? "\n" : "<br>\n";
$key   = $isCli ? ($argv[1] ?? '') : ($_GET['key'] ?? '');

header('Content-Type: text/html; charset=utf-8');

if (!hash_equals(GRANT_KEY, (string)$key)) {
    $url = APP_URL . '/grant_admin_full_access.php?key=' . GRANT_KEY;
    echo "کلید نامعتبر است.$eol";
    echo 'لینک صحیح اجرا: <a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a>';
    exit(1);
}

$user = DB::fetch('SELECT id, name, email, role FROM users WHERE email = ?', [TARGET_EMAIL]);

if (!$user) {
    echo "کاربری با ایمیل " . htmlspecialchars(TARGET_EMAIL) . " پیدا نشد.$eol";
    exit(1);
}

DB::update('users', [
    'role'        => 'admin',
    'permissions' => json_encode(['*'], JSON_UNESCAPED_UNICODE),
    'is_active'   => 1,
    'updated_at'  => date('Y-m-d H:i:s'),
], 'id = ?', [(int)$user['id']]);

echo "دسترسی تام با موفقیت اعمال شد.$eol";
echo "کاربر: " . htmlspecialchars($user['name']) . " (ID: {$user['id']}) — " . htmlspecialchars($user['email']) . $eol;
echo "نتیجه: role=admin | permissions=[\"*\"] | is_active=1$eol";
echo "برای اعمال، از حساب خارج و دوباره وارد شوید. سپس این فایل را حذف کنید.";
