<?php
require_once __DIR__ . '/../includes/config.php';

$email = ADMIN_EMAIL;
$newPassword = 'Admin@123456';

$user = DB::fetch('SELECT id, email, role, is_active FROM users WHERE email = ?', [$email]);

if ($user) {
    DB::update('users', [
        'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        'role'          => 'admin',
        'is_active'     => 1,
    ], 'id = ?', [(int)$user['id']]);
    echo "✅ کاربر ادمین آپدیت شد.\n";
    echo "📧 ایمیل: {$email}\n";
    echo "🔑 رمز عبور جدید: {$newPassword}\n";
} else {
    DB::insert('users', [
        'name'               => 'مدیر سیستم',
        'email'              => $email,
        'phone'              => '+989000000001',
        'password_hash'      => password_hash($newPassword, PASSWORD_BCRYPT),
        'role'               => 'admin',
        'credit_balance'     => 0,
        'verification_level' => 3,
        'is_active'          => 1,
        'kyc_status'         => 'approved',
        'seller_type'        => 'personal',
        'subscription_plan'  => 'none',
    ]);
    echo "✅ کاربر ادمین جدید ساخته شد.\n";
    echo "📧 ایمیل: {$email}\n";
    echo "🔑 رمز عبور: {$newPassword}\n";
}

echo "\n⚠️  فایل scripts/reset_admin.php را بعد از استفاده حذف کنید!\n";
