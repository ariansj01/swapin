<?php
require_once __DIR__ . '/../includes/config.php';

echo "<pre style='direction:ltr;text-align:left;background:#000;color:#0f0;padding:20px;font-family:monospace;font-size:14px;'>";
echo "=== Admin Login Debug ===\n\n";

$email = ADMIN_EMAIL;
$testPassword = 'Admin@123456';

echo "1. ADMIN_EMAIL constant: " . ADMIN_EMAIL . "\n";
echo "2. DB_NAME: " . DB_NAME . "\n";
echo "3. DB_USER: " . DB_USER . "\n\n";

$user = DB::fetch('SELECT * FROM users WHERE email = ?', [$email]);

if (!$user) {
    echo "❌ USER NOT FOUND with email: {$email}\n";
    echo "\nAll users in DB:\n";
    $all = DB::fetchAll('SELECT id, email, name, role, is_active FROM users LIMIT 20');
    foreach ($all as $u) {
        echo "  [{$u['id']}] {$u['email']} | role={$u['role']} | active={$u['is_active']} | name={$u['name']}\n";
    }
} else {
    echo "✅ User found:\n";
    echo "   ID: {$user['id']}\n";
    echo "   Email: {$user['email']}\n";
    echo "   Role: " . ($user['role'] ?? 'NULL') . "\n";
    echo "   Name: " . ($user['name'] ?? 'NULL') . "\n";
    echo "   Active: {$user['is_active']}\n";
    echo "   Password hash length: " . strlen($user['password_hash']) . "\n";
    echo "   Password hash (first 30): " . substr($user['password_hash'], 0, 30) . "...\n\n";

    $verify = password_verify($testPassword, $user['password_hash']);
    echo "4. Password verify ('{$testPassword}'): " . ($verify ? '✅ MATCH' : '❌ NO MATCH') . "\n\n";

    $isAdmin1 = ($user['email'] === ADMIN_EMAIL);
    $isAdmin2 = (($user['role'] ?? 'user') === 'admin');
    echo "5. Admin check via email match: " . ($isAdmin1 ? '✅ YES' : '❌ NO') . "\n";
    echo "6. Admin check via role='admin': " . ($isAdmin2 ? '✅ YES' : '❌ NO') . "\n";

    if (!$verify) {
        echo "\n⚠️  Password hash mismatch! Re-setting password now...\n";
        $newHash = password_hash($testPassword, PASSWORD_BCRYPT);
        DB::update('users', [
            'password_hash' => $newHash,
            'role' => 'admin',
            'is_active' => 1,
        ], 'id = ?', [(int)$user['id']]);
        echo "   Updated! New hash (first 30): " . substr($newHash, 0, 30) . "...\n";

        $verify2 = password_verify($testPassword, $newHash);
        echo "   Re-verify: " . ($verify2 ? '✅ OK' : '❌ STILL BROKEN') . "\n";
    }
}

echo "\n=== LOGIN WITH THESE ===\n";
echo "Email: " . ADMIN_EMAIL . "\n";
echo "Password: {$testPassword}\n";
echo "\n⚠️  Delete this file after use!\n";
echo "</pre>";
