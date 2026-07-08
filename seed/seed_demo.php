<?php
/**
 * Seed demo users and listings (stage 1 — users + listings only).
 *
 * Usage:
 *   c:\xampp\php\php.exe seed/seed_demo.php
 *   c:\xampp\php\php.exe seed/seed_demo.php --reset   # remove previous demo data first
 *
 * Demo login: demo.user1@swapin.local / Demo1234!
 */
// Skip session for CLI
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/config.php';

require_cli();

header('Content-Type: text/plain; charset=utf-8');

define('DEMO_EMAIL_DOMAIN', '@swapin.local');
define('DEMO_EMAIL_PREFIX', 'demo.user');
define('DEMO_PASSWORD',     'Demo1234!');
define('SEED_TARGET_USERS',    65);
define('SEED_TARGET_LISTINGS', 100);

$reset = in_array('--reset', $argv ?? [], true);

echo "=== Swapin Demo Seed (users + listings) ===\n\n";

if ($reset) {
    echo "Removing previous demo data...\n";
    $demoUserIds = array_column(
        DB::fetchAll('SELECT id FROM users WHERE email LIKE ?', [DEMO_EMAIL_PREFIX . '%' . DEMO_EMAIL_DOMAIN]),
        'id'
    );
    if ($demoUserIds) {
        $ph = implode(',', array_fill(0, count($demoUserIds), '?'));
        
        // First get demo listing IDs for related tables
        $demoListingIds = array_column(
            DB::fetchAll("SELECT id FROM listings WHERE user_id IN ($ph)", $demoUserIds),
            'id'
        );
        $listPh = $demoListingIds ? implode(',', array_fill(0, count($demoListingIds), '?')) : '';
        
        // First get demo trade IDs from related listings/users
        $demoTradeIds = [];
        if ($demoListingIds) {
            $demoTradeIds = array_column(
                DB::fetchAll("SELECT id FROM trades WHERE listing_a_id IN ($listPh) OR listing_b_id IN ($listPh)", array_merge($demoListingIds, $demoListingIds)),
                'id'
            );
        }
        // Also check trades by user IDs
        $demoTradeIdsByUser = array_column(
            DB::fetchAll("SELECT id FROM trades WHERE user_a_id IN ($ph) OR user_b_id IN ($ph)", array_merge($demoUserIds, $demoUserIds)),
            'id'
        );
        $demoTradeIds = array_unique(array_merge($demoTradeIds, $demoTradeIdsByUser));
        $tradePh = $demoTradeIds ? implode(',', array_fill(0, count($demoTradeIds), '?')) : '';
        
        // Delete in reverse foreign key order
        if ($tradePh) {
            // Delete child tables first
            if (db_has_table('disputes')) {
                DB::query("DELETE FROM disputes WHERE trade_id IN ($tradePh)", $demoTradeIds);
            }
            if (db_has_table('trade_contracts')) {
                DB::query("DELETE FROM trade_contracts WHERE trade_id IN ($tradePh)", $demoTradeIds);
            }
            if (db_has_table('escrow_transactions')) {
                DB::query("DELETE FROM escrow_transactions WHERE trade_id IN ($tradePh)", $demoTradeIds);
            }
            if (db_has_table('bnpl_requests')) {
                DB::query("DELETE FROM bnpl_requests WHERE trade_id IN ($tradePh)", $demoTradeIds);
            }
            if (db_has_table('inspection_requests')) {
                DB::query("DELETE FROM inspection_requests WHERE trade_id IN ($tradePh)", $demoTradeIds);
            }
            DB::query("DELETE FROM reviews WHERE trade_id IN ($tradePh)", $demoTradeIds);
            DB::query("DELETE FROM messages WHERE offer_id IN (SELECT id FROM trade_offers WHERE listing_id IN ($listPh))", $demoListingIds);
            DB::query("DELETE FROM trade_offers WHERE listing_id IN ($listPh) OR offer_listing_id IN ($listPh)", array_merge($demoListingIds, $demoListingIds));
            DB::query("DELETE FROM trades WHERE id IN ($tradePh)", $demoTradeIds);
        }
        
        // Delete listing-related tables
        if ($listPh) {
            DB::query("DELETE FROM listing_images WHERE listing_id IN ($listPh)", $demoListingIds);
            if (db_has_table('listing_bumps')) {
                DB::query("DELETE FROM listing_bumps WHERE listing_id IN ($listPh)", $demoListingIds);
            }
            if (db_has_table('inspection_requests')) {
                DB::query("DELETE FROM inspection_requests WHERE listing_id IN ($listPh)", $demoListingIds);
            }
            DB::query("DELETE FROM saved_listings WHERE listing_id IN ($listPh)", $demoListingIds);
            DB::query("DELETE FROM listings WHERE id IN ($listPh)", $demoListingIds);
        }
        
        // Delete user-related tables
        DB::query("DELETE FROM wallet_transactions WHERE user_id IN ($ph)", $demoUserIds);
        if (db_has_table('support_tickets')) {
            DB::query("DELETE FROM support_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE user_id IN ($ph))", $demoUserIds);
            DB::query("DELETE FROM support_tickets WHERE user_id IN ($ph)", $demoUserIds);
        }
        if (db_has_table('error_reports')) {
            DB::query("DELETE FROM error_reports WHERE user_id IN ($ph)", $demoUserIds);
        }
        DB::query("DELETE FROM messages WHERE from_user_id IN ($ph) OR to_user_id IN ($ph)", array_merge($demoUserIds, $demoUserIds));
        DB::query("DELETE FROM notifications WHERE user_id IN ($ph)", $demoUserIds);
        DB::query("DELETE FROM saved_listings WHERE user_id IN ($ph)", $demoUserIds);
        DB::query("DELETE FROM reviews WHERE from_user_id IN ($ph) OR to_user_id IN ($ph)", array_merge($demoUserIds, $demoUserIds));
        
        // Finally delete users
        DB::query("DELETE FROM users WHERE id IN ($ph)", $demoUserIds);
        echo "  Removed " . count($demoUserIds) . " demo users and all related data.\n";
    } else {
        echo "  No demo users found.\n";
    }
    echo "\n";
}

$existingDemo = (int)DB::fetch(
    'SELECT COUNT(*) AS c FROM users WHERE email LIKE ?',
    [DEMO_EMAIL_PREFIX . '%' . DEMO_EMAIL_DOMAIN]
)['c'];

$usersToAdd = max(0, SEED_TARGET_USERS - $existingDemo);
echo "Demo users: existing=$existingDemo, adding=$usersToAdd (target=" . SEED_TARGET_USERS . ")\n";

$profiles   = require __DIR__ . '/data/users.php';
$templates  = require __DIR__ . '/data/listings.php';
$passwordHash = password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT);

$userIds = [];
$addedUsers = 0;

for ($i = 0; $i < $usersToAdd; $i++) {
    $idx     = $existingDemo + $i;
    $profile = $profiles[$idx % count($profiles)];
    $num     = $idx + 1;
    $email   = DEMO_EMAIL_PREFIX . $num . DEMO_EMAIL_DOMAIN;
    $phone   = '+98912' . str_pad((string)(1000000 + $num), 7, '0', STR_PAD_LEFT);

    if (DB::fetch('SELECT id FROM users WHERE email = ? OR phone = ?', [$email, $phone])) {
        echo "SKIP user $email (already exists)\n";
        continue;
    }

    $rating      = round(rand(30, 50) / 10, 2);
    $ratingCount = rand(0, 20);
    $daysAgo     = rand(1, 90);
    $createdAt   = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days -" . rand(0, 86400) . ' seconds'));

    $uid = DB::insert('users', db_filter_row('users', [
        'name'               => $profile['name'],
        'email'              => $email,
        'phone'              => $phone,
        'city'               => $profile['city'],
        'bio'                => $profile['bio'],
        'password_hash'      => $passwordHash,
        'credit_balance'     => 0,
        'rating'             => $rating,
        'rating_count'       => $ratingCount,
        'verification_level' => $profile['verification_level'],
        'kyc_status'         => $profile['kyc_status'],
        'seller_type'        => $profile['seller_type'],
        'store_name'         => $profile['store_name'] ?? null,
        'last_seen'          => rand(0, 3) === 0 ? null : date('Y-m-d H:i:s', strtotime('-' . rand(1, 72) . ' hours')),
        'created_at'         => $createdAt,
    ]));

    credit_transact($uid, 'deposit', WELCOME_BONUS, 'پاداش خوش‌آمدگویی (داده نمونه)', ['ref_type' => 'none']);

    $userIds[] = $uid;
    $addedUsers++;
}

$allDemoUsers = DB::fetchAll(
    'SELECT id, city FROM users WHERE email LIKE ? ORDER BY id',
    [DEMO_EMAIL_PREFIX . '%' . DEMO_EMAIL_DOMAIN]
);

echo "Users seeded: +$addedUsers (total demo: " . count($allDemoUsers) . ")\n\n";

$existingListings = (int)DB::fetch(
    'SELECT COUNT(*) AS c FROM listings l JOIN users u ON u.id = l.user_id WHERE u.email LIKE ?',
    [DEMO_EMAIL_PREFIX . '%' . DEMO_EMAIL_DOMAIN]
)['c'];

$listingsToAdd = max(0, SEED_TARGET_LISTINGS - $existingListings);
echo "Demo listings: existing=$existingListings, adding=$listingsToAdd (target=" . SEED_TARGET_LISTINGS . ")\n";

if (!$allDemoUsers) {
    echo "No demo users available. Abort.\n";
    exit(1);
}

$categoryIds = array_keys($templates);
$userListingCount = [];

foreach ($allDemoUsers as $u) {
    $cnt = DB::fetch(
        'SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = ?',
        [$u['id'], 'active']
    )['c'];
    $userListingCount[$u['id']] = (int)$cnt;
}

$addedListings = 0;
$attempts = 0;
$maxAttempts = $listingsToAdd * 5;

while ($addedListings < $listingsToAdd && $attempts < $maxAttempts) {
    $attempts++;

    $eligible = array_filter($allDemoUsers, fn($u) => ($userListingCount[$u['id']] ?? 0) < FREE_LISTING_MAX);
    if (!$eligible) {
        echo "All demo users at listing limit ($FREE_LISTING_MAX).\n";
        break;
    }
    $eligible = array_values($eligible);
    $user     = $eligible[array_rand($eligible)];

    $catId    = $categoryIds[array_rand($categoryIds)];
    $items    = $templates[$catId];
    $item     = $items[array_rand($items)];

    $suffix   = $addedListings > 0 ? ' #' . ($addedListings + 1) : '';
    $title    = mb_substr($item['title'] . $suffix, 0, 200);
    $daysAgo  = rand(0, 60);
    $created  = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days -" . rand(0, 86400) . ' seconds'));

    $listingId = DB::insert('listings', db_filter_row('listings', [
        'user_id'         => $user['id'],
        'category_id'     => $catId,
        'title'           => $title,
        'description'     => $item['description'],
        'condition'       => $item['condition'],
        'estimated_value' => $item['estimated_value'],
        'want_in_return'  => $item['want_in_return'],
        'want_type'       => $item['want_type'],
        'listing_mode'    => 'swap',
        'review_status'   => 'approved',
        'city'            => $user['city'],
        'status'          => 'active',
        'views'           => rand(5, 800),
        'created_at'      => $created,
    ]));

    // Insert listing images
    if (!empty($item['images'])) {
        foreach ($item['images'] as $index => $filename) {
            // Create placeholder image if it doesn't exist
            $imagePath = UPLOAD_DIR . $filename;
            if (!file_exists($imagePath)) {
                // Create a simple placeholder image with GD
                $img = imagecreatetruecolor(800, 600);
                $bgColor = imagecolorallocate($img, rand(100, 200), rand(100, 200), rand(100, 200));
                imagefill($img, 0, 0, $bgColor);
                $textColor = imagecolorallocate($img, 255, 255, 255);
                // Add some text
                $text = $item['title'];
                $fontSize = 5;
                $textWidth = imagefontwidth($fontSize) * strlen($text);
                $x = (800 - $textWidth) / 2;
                $y = 300 - imagefontheight($fontSize) / 2;
                imagestring($img, $fontSize, $x, $y, $text, $textColor);
                // Save the image
                imagejpeg($img, $imagePath, 85);
                imagedestroy($img);
            }
            DB::insert('listing_images', [
                'listing_id' => $listingId,
                'filename'   => $filename,
                'is_primary' => $index === 0 ? 1 : 0,
                'sort_order' => $index,
            ]);
        }
    }

    $userListingCount[$user['id']]++;
    $addedListings++;
}

$totalUsers    = (int)DB::fetch('SELECT COUNT(*) AS c FROM users')['c'];
$totalListings = (int)DB::fetch('SELECT COUNT(*) AS c FROM listings WHERE status = ?', ['active'])['c'];
$totalDemoList = (int)DB::fetch(
    'SELECT COUNT(*) AS c FROM listings l JOIN users u ON u.id = l.user_id WHERE u.email LIKE ?',
    [DEMO_EMAIL_PREFIX . '%' . DEMO_EMAIL_DOMAIN]
)['c'];

echo "\nListings seeded: +$addedListings (demo total: $totalDemoList)\n";
echo "\n=== Summary ===\n";
echo "All users in DB:     $totalUsers\n";
echo "Active listings:     $totalListings\n";
echo "Demo login:          " . DEMO_EMAIL_PREFIX . "1" . DEMO_EMAIL_DOMAIN . " / " . DEMO_PASSWORD . "\n";
echo "\nDone.\n";
