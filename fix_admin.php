<?php
/**
 * Standalone Admin Fix Script
 * Run this via browser: https://swaapin.ir/fix_admin.php
 * Or via CLI: php fix_admin.php
 */

require_once __DIR__ . '/includes/config.php';

$newEmail = 'Yekta.akramsani@gmail.com';
$newPass = 'Yekta@2026!Secure#Swapin';
$passHash = password_hash($newPass, PASSWORD_BCRYPT);

header('Content-Type: text/plain; charset=utf-8');

try {
    echo "Starting admin fix...\n";

    // 1. Ensure 'permissions' column exists
    $cols = db_table_columns('users');
    if (!in_array('permissions', $cols)) {
        DB::query("ALTER TABLE `users` ADD COLUMN `permissions` JSON DEFAULT NULL AFTER `role` ");
        echo "- Added 'permissions' column.\n";
    }

    // 2. Try to find admin by email or ID 1
    $admin = DB::fetch("SELECT id FROM users WHERE id = 1 OR email = ?", [$newEmail]);

    if ($admin) {
        $adminId = $admin['id'];
        DB::update('users', [
            'email' => $newEmail,
            'password_hash' => $passHash,
            'role' => 'admin',
            'permissions' => json_encode(['*']),
            'is_active' => 1,
            'verification_level' => 3
        ], 'id = ?', [$adminId]);
        echo "- Updated existing admin (ID: $adminId) with new credentials.\n";
    } else {
        // Create new admin if none found
        $adminId = DB::insert('users', [
            'name' => 'مدیر اصلی',
            'email' => $newEmail,
            'password_hash' => $passHash,
            'role' => 'admin',
            'permissions' => json_encode(['*']),
            'is_active' => 1,
            'verification_level' => 3,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo "- Created new admin account (ID: $adminId).\n";
    }

    echo "\nSUCCESS! You can now login at: " . APP_URL . "/admin/login.php\n";
    echo "Email: $newEmail\n";
    echo "Password: $newPass\n";
    echo "\nIMPORTANT: Delete this file (fix_admin.php) after successful login for security.";

} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString();
}
