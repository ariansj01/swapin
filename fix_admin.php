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

    // 2. Search for any user with this email first
    $existingUser = DB::fetch("SELECT id FROM users WHERE email = ?", [$newEmail]);

    if ($existingUser) {
        $adminId = $existingUser['id'];
        DB::update('users', [
            'role' => 'admin',
            'permissions' => json_encode(['*']),
            'password_hash' => $passHash,
            'is_active' => 1,
            'verification_level' => 3
        ], 'id = ?', [$adminId]);
        echo "- Found user with this email (ID: $adminId). Updated to Admin.\n";
    } else {
        // No user has this email, let's update ID 1 or create
        $admin1 = DB::fetch("SELECT id FROM users WHERE id = 1");
        if ($admin1) {
            DB::update('users', [
                'email' => $newEmail,
                'password_hash' => $passHash,
                'role' => 'admin',
                'permissions' => json_encode(['*']),
                'is_active' => 1,
                'verification_level' => 3
            ], 'id = 1');
            echo "- Updated user ID 1 to new email and credentials.\n";
        } else {
            $adminId = DB::insert('users', [
                'id' => 1,
                'name' => 'مدیر اصلی',
                'email' => $newEmail,
                'password_hash' => $passHash,
                'role' => 'admin',
                'permissions' => json_encode(['*']),
                'is_active' => 1,
                'verification_level' => 3,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo "- Created new admin account ID 1.\n";
        }
    }

    echo "\nSUCCESS! You can now login at: " . APP_URL . "/admin/login.php\n";
    echo "Email: $newEmail\n";
    echo "Password: $newPass\n";
    echo "\nIMPORTANT: Delete this file (fix_admin.php) after successful login for security.";

} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString();
}
