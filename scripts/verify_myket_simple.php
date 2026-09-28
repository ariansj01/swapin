<?php
require_once __DIR__ . '/db_direct.php';

$result = $mysqli->query("SELECT id, name, phone, email, phone_verified_at, email_verified_at,
            kyc_status, seller_type, store_login, store_name, store_slug,
            verification_level, subscription_plan, onboarding_completed, is_active, city
     FROM users WHERE store_login = 'myket_reviewer' OR phone = '+989000000000' OR store_slug = 'myket-test-store' LIMIT 5");

echo "Found: " . $result->num_rows . " user(s)\n\n";

while ($u = $result->fetch_assoc()) {
    echo "==== User ID: {$u['id']} ====\n";
    echo "name: {$u['name']}\n";
    echo "phone: {$u['phone']}\n";
    echo "email: {$u['email']}\n";
    echo "city: {$u['city']}\n";
    echo "phone_verified_at: {$u['phone_verified_at']}\n";
    echo "email_verified_at: {$u['email_verified_at']}\n";
    echo "kyc_status: {$u['kyc_status']}\n";
    echo "seller_type: {$u['seller_type']}\n";
    echo "store_login: {$u['store_login']}\n";
    echo "store_name: {$u['store_name']}\n";
    echo "store_slug: {$u['store_slug']}\n";
    echo "verification_level: {$u['verification_level']}\n";
    echo "subscription_plan: {$u['subscription_plan']}\n";
    echo "onboarding_completed: {$u['onboarding_completed']}\n";
    echo "is_active: {$u['is_active']}\n";
}
