<?php
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/config.php';

$rows = DB::fetchAll(
    'SELECT id, name, phone, email, phone_verified_at, email_verified_at,
            kyc_status, seller_type, store_login, store_name, store_slug,
            verification_level, subscription_plan, onboarding_completed, is_active, city
     FROM users WHERE store_login = ? OR phone = ? OR store_slug = ?',
    ['myket_reviewer', '+989000000000', 'myket-test-store']
);

echo "Found: " . count($rows) . " user(s)" . PHP_EOL . PHP_EOL;

foreach ($rows as $u) {
    echo "==== User ID: {$u['id']} ====" . PHP_EOL;
    echo "name: {$u['name']}" . PHP_EOL;
    echo "phone: {$u['phone']}" . PHP_EOL;
    echo "email: {$u['email']}" . PHP_EOL;
    echo "city: {$u['city']}" . PHP_EOL;
    echo "phone_verified_at: {$u['phone_verified_at']}" . PHP_EOL;
    echo "email_verified_at: {$u['email_verified_at']}" . PHP_EOL;
    echo "kyc_status: {$u['kyc_status']}" . PHP_EOL;
    echo "seller_type: {$u['seller_type']}" . PHP_EOL;
    echo "store_login: {$u['store_login']}" . PHP_EOL;
    echo "store_name: {$u['store_name']}" . PHP_EOL;
    echo "store_slug: {$u['store_slug']}" . PHP_EOL;
    echo "verification_level: {$u['verification_level']}" . PHP_EOL;
    echo "subscription_plan: {$u['subscription_plan']}" . PHP_EOL;
    echo "onboarding_completed: {$u['onboarding_completed']}" . PHP_EOL;
    echo "is_active: {$u['is_active']}" . PHP_EOL;
    echo PHP_EOL;
    echo "---- LOGIN CREDENTIALS ----" . PHP_EOL;
    echo "Username: myket_reviewer" . PHP_EOL;
    echo "Password: Myket@Review#2026" . PHP_EOL;
    echo "Phone (for OTP): 09000000000" . PHP_EOL;
    echo "Store panel URL: " . APP_URL . "/auth/store-login" . PHP_EOL;
    echo "Store page URL: " . APP_URL . "/shops/{$u['store_slug']}" . PHP_EOL;
    echo "API base: " . APP_URL . "/api/v1" . PHP_EOL;
}
