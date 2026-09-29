<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/config.php';

echo "<pre style='direction:ltr;text-align:left;background:#000;color:#0f0;padding:20px;font-family:monospace;font-size:13px;'>";
echo "=== Admin Login LIVE Debug ===\n\n";

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['email'] = 'info@swaapin.ir';
$_POST['password'] = 'Admin@123456';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    echo "✅ Session started. ID: " . session_id() . "\n";
} else {
    echo "ℹ️  Session already active. ID: " . session_id() . "\n";
}

$_SESSION = [];
echo "✅ Session cleared\n\n";

$email = clean($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

echo "1. POST email: {$email}\n";
echo "2. POST pass (len): " . strlen($pass) . "\n\n";

$user = DB::fetch('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);

if (!$user) {
    echo "❌ Step 1 FAILED: no user from SELECT\n";
    exit;
}
echo "3. Step 1 ✅ User found (ID={$user['id']}, email={$user['email']})\n";

$passOk = password_verify($pass, $user['password_hash']);
if (!$passOk) {
    echo "❌ Step 2 FAILED: password_verify returned false\n";
    echo "   Input pass hash test: " . password_hash($pass, PASSWORD_BCRYPT) . "\n";
    exit;
}
echo "4. Step 2 ✅ password_verify passed\n";

$isAdmin = ($user['email'] === ADMIN_EMAIL) || (($user['role'] ?? 'user') === 'admin');
echo "5. Step 3 isAdmin: " . ($isAdmin ? '✅ YES' : '❌ NO') . "\n";
echo "   ADMIN_EMAIL = " . ADMIN_EMAIL . "\n";
echo "   user email match: " . ($user['email'] === ADMIN_EMAIL ? 'YES' : 'NO') . "\n";
echo "   user role = " . var_export($user['role'] ?? null, true) . "\n\n";

if (!$isAdmin) {
    echo "❌ NOT ADMIN - stopping\n";
    exit;
}

echo "6. Calling login_user({$user['id']})...\n";
login_user((int)$user['id']);
echo "   ✅ login_user() done\n";
echo "   SESSION user_id = " . var_export($_SESSION['user_id'] ?? null, true) . "\n\n";

$check = auth_admin();
echo "7. auth_admin() check after login: " . ($check ? "✅ YES (name={$check['name']})" : "❌ NO") . "\n";

echo "\n=== SESSION DUMP ===\n";
print_r($_SESSION);

echo "\n=== SUCCESS! REDIRECT WOULD BE: " . APP_URL . "/admin/\n";
echo "\n⚠️  Delete this file!\n";
echo "</pre>";
