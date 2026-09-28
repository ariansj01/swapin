<?php
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/v2.php';

$testPhoneIntl = '+989000000000';
$testUsername  = 'myket_reviewer';
$testPassword  = 'Myket@Review#2026';
$testName      = 'تیم بررسی مایکت';
$testStoreName = 'فروشگاه تستی مایکت';
$testStoreSlug = 'myket-test-store';
$testCity      = 'تهران';
$testEmail     = 'developer@myket.ir';

echo "=== ساخت حساب تست احراز شده برای تیم مایکت ===\n\n";

$existing = DB::fetch('SELECT id, store_login, phone FROM users WHERE phone = ? OR store_login = ? OR store_slug = ? LIMIT 1', [
    $testPhoneIntl, $testUsername, $testStoreSlug
]);

$userId = null;

if ($existing) {
    $userId = (int)$existing['id'];
    echo "حساب با شناسه {$userId} از قبل وجود دارد. به‌روزرسانی...\n";
} else {
    $userId = (int)DB::insert('users', db_filter_row('users', [
        'name'                  => $testName,
        'email'                 => $testEmail,
        'phone'                 => $testPhoneIntl,
        'phone_verified_at'     => date('Y-m-d H:i:s'),
        'email_verified_at'     => date('Y-m-d H:i:s'),
        'city'                  => $testCity,
        'password_hash'         => password_hash($testPassword, PASSWORD_BCRYPT),
        'verification_level'    => 3,
        'credit_balance'        => 0,
        'is_active'             => 1,
        'role'                  => 'user',
        'kyc_status'            => 'approved',
        'seller_type'           => 'store',
        'provider_type'         => 'normal_store',
        'store_name'            => $testStoreName,
        'store_slug'            => $testStoreSlug,
        'store_login'           => $testUsername,
        'store_description'     => 'حساب تستی تیم بررسی مایکت برای دسترسی به تمام بخش‌های برنامه',
        'store_address'         => 'تهران، خیابان ولیعصر',
        'store_phone'           => '021-12345678',
        'subscription_plan'     => 'gold',
        'subscription_until'    => date('Y-m-d', strtotime('+1 year')),
        'onboarding_completed'  => 1,
        'primary_goal'          => 'any',
        'can_ship'              => 1,
        'created_at'            => date('Y-m-d H:i:s'),
        'updated_at'            => date('Y-m-d H:i:s'),
        'last_seen'             => date('Y-m-d H:i:s'),
    ]));
    echo "حساب جدید با شناسه {$userId} ساخته شد.\n";
}

DB::update('users', db_filter_row('users', [
    'name'                  => $testName,
    'email'                 => $testEmail,
    'phone'                 => $testPhoneIntl,
    'phone_verified_at'     => date('Y-m-d H:i:s'),
    'email_verified_at'     => date('Y-m-d H:i:s'),
    'city'                  => $testCity,
    'password_hash'         => password_hash($testPassword, PASSWORD_BCRYPT),
    'verification_level'    => 3,
    'is_active'             => 1,
    'role'                  => 'user',
    'kyc_status'            => 'approved',
    'seller_type'           => 'store',
    'provider_type'         => 'normal_store',
    'store_name'            => $testStoreName,
    'store_slug'            => $testStoreSlug,
    'store_login'           => $testUsername,
    'store_description'     => 'حساب تستی تیم بررسی مایکت برای دسترسی به تمام بخش‌های برنامه',
    'subscription_plan'     => 'gold',
    'subscription_until'    => date('Y-m-d', strtotime('+1 year')),
    'onboarding_completed'  => 1,
    'primary_goal'          => 'any',
    'can_ship'              => 1,
    'updated_at'            => date('Y-m-d H:i:s'),
]), 'id = ?', [$userId]);

echo PHP_EOL;
echo "===== اطلاعات حساب تست =====" . PHP_EOL;
echo "نام کاربری (store_login): {$testUsername}" . PHP_EOL;
echo "رمز عبور: {$testPassword}" . PHP_EOL;
echo "شماره تلفن: 09000000000" . PHP_EOL;
echo "ایمیل: {$testEmail}" . PHP_EOL;
echo "نام نمایشی: {$testName}" . PHP_EOL;
echo "نام فروشگاه: {$testStoreName}" . PHP_EOL;
echo "شهر: {$testCity}" . PHP_EOL;
echo PHP_EOL;
echo "===== وضعیت احراز هویت =====" . PHP_EOL;
echo "تأیید شماره تلفن: ✓ (phone_verified_at)" . PHP_EOL;
echo "تأیید ایمیل: ✓ (email_verified_at)" . PHP_EOL;
echo "احراز هویت KYC: ✓ (kyc_status = approved)" . PHP_EOL;
echo "سطح تأیید: 3 (بالاترین سطح)" . PHP_EOL;
echo "نوع فروشنده: فروشگاه (seller_type = store)" . PHP_EOL;
echo "اشتراک: Gold تا یک سال بعد" . PHP_EOL;
echo "آماده‌سازی اولیه: کامل شده (onboarding_completed = 1)" . PHP_EOL;
echo PHP_EOL;
echo "===== نقاط دسترسی =====" . PHP_EOL;
echo "ورود وب (پنل فروشگاه): " . APP_URL . "/auth/store-login" . PHP_EOL;
echo "ورود وب (موبایل/کاربر عادی با OTP): " . APP_URL . "/auth/login  (شماره 09000000000)" . PHP_EOL;
echo "صفحه فروشگاه: " . APP_URL . "/shops/{$testStoreSlug}" . PHP_EOL;
echo "پنل فروشگاه: " . APP_URL . "/store" . PHP_EOL;
echo "API موبایل: " . APP_URL . "/api/v1/auth/otp/send  (شماره 09000000000)" . PHP_EOL;
