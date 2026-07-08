<?php
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/config.php';

echo "======================================\n";
echo "   Swapin Demo Deployment Script\n";
echo "======================================\n\n";

// --------------------------
// Step 1: Cleanup old data
// --------------------------
echo "Step 1: Cleaning up old demo listings...\n";
try {
    $demoUsers = DB::fetchAll('SELECT id FROM users WHERE email LIKE ?', ['demo.user%@swapin.local']);
    $demoUserIds = array_column($demoUsers, 'id');
    if (!empty($demoUserIds)) {
        $ph = implode(',', array_fill(0, count($demoUserIds), '?'));
        // Get old images to delete from uploads
        $oldImages = DB::fetchAll("SELECT li.filename FROM listing_images li JOIN listings l ON li.listing_id = l.id WHERE l.user_id IN ($ph)", $demoUserIds);
        foreach ($oldImages as $img) {
            $path = UPLOAD_DIR . $img['filename'];
            if (file_exists($path)) unlink($path);
        }
        // Delete listings
        DB::query("DELETE FROM listings WHERE user_id IN ($ph)", $demoUserIds);
        echo "  ✅ Old demo listings deleted\n";
    }
} catch (Throwable $e) {
    echo "  ⚠️  Note: " . $e->getMessage() . "\n";
}

// --------------------------
// Step 2: Ensure demo users exist
// --------------------------
echo "\nStep 2: Ensuring demo users exist...\n";
$demoUsers = DB::fetchAll('SELECT id, city FROM users WHERE email LIKE ? ORDER BY id', ['demo.user%@swapin.local']);
if (count($demoUsers) < 15) {
    $passwordHash = password_hash('Demo1234!', PASSWORD_BCRYPT);
    $cities = ['تهران', 'اصفهان', 'مشهد', 'شیراز', 'تبریز', 'اهواز', 'کرج', 'قم', 'یزد', 'کرمانشاه'];
    for ($i = count($demoUsers) + 1; $i <= 15; $i++) {
        $userId = DB::insert('users', [
            'name' => 'کاربر دمو ' . $i,
            'email' => 'demo.user' . $i . '@swapin.local',
            'phone' => '+98912' . str_pad((string)(1000000 + $i), 7, '0', STR_PAD_LEFT),
            'city' => $cities[array_rand($cities)],
            'password_hash' => $passwordHash,
            'credit_balance' => 10000000,
            'verification_level' => 2,
            'created_at' => date('Y-m-d H:i:s', time() - rand(0, 86400 * 60)),
        ]);
        $demoUsers[] = ['id' => $userId, 'city' => $cities[array_rand($cities)]];
    }
    echo "  ✅ Created " . (15 - count($demoUsers)) . " new demo users\n";
}
echo "  Total demo users: " . count($demoUsers) . "\n";

// --------------------------
// Step 3: Process listings from images
// --------------------------
echo "\nStep 3: Creating new listings from images...\n";
$imagesDir = __DIR__ . '/../عکس اگهی ها/v2/';
if (!is_dir($imagesDir)) {
    $imagesDir = __DIR__ . '/../عکس اگهی ها/'; // Fallback to old dir
}
if (!is_dir($imagesDir)) {
    die("❌ Error: Image directory not found at $imagesDir\n");
}

$folders = array_diff(scandir($imagesDir), ['.', '..']);
$userIndex = 0;
$createdCount = 0;

// Function to get listing details based on title
function getListingDetails($title) {
    $lowerTitle = mb_strtolower($title);
    $details = [
        'condition' => 'good',
        'estimated_value' => rand(2000000, 30000000),
        'want_in_return' => 'چیز مفید و ارزشمند',
        'want_type' => 'any',
        'description' => "$title برای معاوضه. وضعیت عالی، آماده تحویل.",
    ];

    if (mb_strpos($lowerTitle, 'آیفون') !== false || mb_strpos($lowerTitle, 'گوشی') !== false || mb_strpos($lowerTitle, 'سامسونگ') !== false) {
        $details['condition'] = ['new', 'like_new', 'good'][array_rand(['new', 'like_new', 'good'])];
        $details['want_in_return'] = 'لپ تاپ، تبلت یا ساعت هوشمند';
        $details['want_type'] = 'item';
        $details['description'] = "گوشی $title در وضعیت عالی، بدون خط و خش. برای معاوضه آماده است.";
    } elseif (mb_strpos($lowerTitle, 'پژو') !== false || mb_strpos($lowerTitle, 'خودرو') !== false || mb_strpos($lowerTitle, 'پراید') !== false || mb_strpos($lowerTitle, 'سانتافه') !== false) {
        $details['condition'] = ['good', 'fair'][array_rand(['good', 'fair'])];
        $details['want_in_return'] = 'خانه، خودرو دیگر یا کالای ارزشمند';
        $details['want_type'] = 'item';
        $details['description'] = "خودرو $title، سالم و بدون تصادف. موتور و گیربکس سالم.";
    } elseif (mb_strpos($lowerTitle, 'خانه') !== false || mb_strpos($lowerTitle, 'اپارتمان') !== false || mb_strpos($lowerTitle, 'زمین') !== false || mb_strpos($lowerTitle, 'پردیس') !== false || mb_strpos($lowerTitle, 'دریای نور') !== false) {
        $details['condition'] = 'good';
        $details['want_in_return'] = 'خانه دیگر، خودرو یا کالاهای ارزشمند';
        $details['want_type'] = 'item';
        $details['description'] = "$title، آماده تحویل. امکان معاوضه با پیشنهاد مناسب وجود دارد.";
    } elseif (mb_strpos($lowerTitle, 'لپ') !== false || mb_strpos($lowerTitle, 'کامپیوتر') !== false || mb_strpos($lowerTitle, 'laptop') !== false || mb_strpos($lowerTitle, 'i7') !== false) {
        $details['condition'] = ['like_new', 'good'][array_rand(['like_new', 'good'])];
        $details['want_in_return'] = 'گوشی پرچمدار، تبلت یا کنسول بازی';
        $details['want_type'] = 'item';
        $details['description'] = "لپ تاپ $title، مناسب کار و بازی. قطعات اصلی و سالم.";
    } elseif (mb_strpos($lowerTitle, 'مبل') !== false || mb_strpos($lowerTitle, 'کاجی') !== false) {
        $details['condition'] = 'good';
        $details['want_in_return'] = 'لوازم خانگی، مبلمان دیگر یا خودرو';
        $details['want_type'] = 'item';
        $details['description'] = "$title، با کیفیت عالی و کم استفاده.";
    } elseif (mb_strpos($lowerTitle, 'خدمات') !== false || mb_strpos($lowerTitle, 'سرویس') !== false || mb_strpos($lowerTitle, 'حمل') !== false) {
        $details['condition'] = 'new';
        $details['want_in_return'] = 'اعتبار، کالا یا خدمات دیگر';
        $details['want_type'] = 'any';
        $details['description'] = "خدمات $title با کیفیت عالی و سریع.";
    } elseif (mb_strpos($lowerTitle, 'شربت') !== false || mb_strpos($lowerTitle, 'عناب') !== false) {
        $details['condition'] = 'new';
        $details['want_in_return'] = 'لوازم خانگی، مواد غذایی یا اعتبار';
        $details['want_type'] = 'any';
        $details['description'] = "$title با کیفیت عالی، تازه و خوشمزه.";
    }

    return $details;
}

foreach ($folders as $folder) {
    $folderPath = $imagesDir . $folder;
    if (!is_dir($folderPath)) continue;
    $title = trim($folder);
    if (empty($title)) continue;

    // Get images
    $images = [];
    $files = array_diff(scandir($folderPath), ['.', '..']);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) $images[] = $file;
    }
    if (empty($images)) continue;

    // Select user
    $user = $demoUsers[$userIndex % count($demoUsers)];
    $userIndex++;

    // Get details
    $details = getListingDetails($title);

    // Get category
    $catId = 11; // Default category
    $categories = DB::fetchAll('SELECT id, name FROM categories WHERE is_active = 1');
    foreach ($categories as $cat) {
        if (mb_strpos(mb_strtolower($title), mb_strtolower($cat['name'])) !== false) {
            $catId = $cat['id'];
            break;
        }
    }

    // Create listing
    $listingId = DB::insert('listings', db_filter_row('listings', [
        'user_id'         => $user['id'],
        'category_id'     => $catId,
        'title'           => $title,
        'description'     => $details['description'],
        'condition'       => $details['condition'],
        'estimated_value' => $details['estimated_value'],
        'want_in_return'  => $details['want_in_return'],
        'want_type'       => $details['want_type'],
        'listing_mode'    => 'swap',
        'review_status'   => 'approved',
        'city'            => $user['city'],
        'status'          => 'active',
        'views'           => rand(5, 300),
        'created_at'      => date('Y-m-d H:i:s', time() - rand(0, 86400 * 30)),
    ]));

    // Copy images
    $sortOrder = 0;
    foreach ($images as $imgFile) {
        $srcPath = $folderPath . DIRECTORY_SEPARATOR . $imgFile;
        $ext = strtolower(pathinfo($imgFile, PATHINFO_EXTENSION));
        $newFilename = 'demo_' . $listingId . '_' . uniqid('', true) . '.' . $ext;
        $destPath = UPLOAD_DIR . $newFilename;
        if (copy($srcPath, $destPath)) {
            DB::insert('listing_images', [
                'listing_id' => $listingId,
                'filename'   => $newFilename,
                'is_primary' => $sortOrder === 0 ? 1 : 0,
                'sort_order' => $sortOrder,
            ]);
            $sortOrder++;
        }
    }

    echo "  ✅ Created listing #$listingId: $title (".count($images)." images)\n";
    $createdCount++;
}

// --------------------------
// Step 4: Update prices
// --------------------------
echo "\nStep 4: Updating listing prices to correct values...\n";
$priceMap = [
    'آیفون 13 پرومکس' => 130000000,
    'لپ‌تاپ Dell' => 47000000,
    'مبل راحتی' => 110000000,
    'لپتاپ hp مدل 840 g8' => 60000000,
    'آیفون 14 نرمال' => 183000000,
    '60 متر + پارکینگ' => 2300000000,
    'S24 Ultra با شرایط اقساط سفید' => 50000000,
    'آبگرمکن 160 لیتر ایستاده برقی' => 43000000,
    'آیفون 14‌ پرومکس ریجستر' => 210000000,
    'باغچه ۲۵۰متری که ۳۰۰مترنشون میده.مناسب زندگی دائم' => 2020000000,
    'حمل گاوصندوق و یخچال ساید اعزام کارگر و «وانت بار»' => 10000000,
    'دوچرخه مدل کمک دار دنده دار نو آکبند' => 30000000,
    'دوچرخه ۲۶ آماتو' => 22000000,
    'زمین ۲۹۳ متر با مشاعات بندپی ویو بینظیر' => 4400000000,
    'سانتافه ۲۰۰۸ فول' => 2500000000,
    'شربت عناب روشنا' => 300000,
    'لپتاپ لنوو i7' => 67000000,
    'مبل کاجی' => 324000000,
    'پراید بدون رنگ مدل 89 دوگانه شرکتی سفید' => 350000000,
    'پردیس فاز ۱۱ زون ۳ خیابان برنا' => 5000000000,
    '۱۹۰م دریای نور‌فاز ۲ نقشه ال هوشمند شخص مرکزی' => 3000000000,
];

$updatedCount = 0;
foreach ($priceMap as $searchTitle => $price) {
    $listing = DB::fetch('SELECT id, title FROM listings WHERE title LIKE ?', ["%$searchTitle%"]);
    if ($listing) {
        DB::query('UPDATE listings SET estimated_value = ? WHERE id = ?', [$price, $listing['id']]);
        $updatedCount++;
    }
}
echo "  ✅ Updated $updatedCount listing prices\n";

// --------------------------
// Finish
// --------------------------
echo "\n======================================\n";
echo "   ✅ DEPLOYMENT COMPLETED SUCCESSFULLY\n";
echo "======================================\n";
echo "   - Created: $createdCount new listings\n";
echo "   - Updated: $updatedCount prices\n";
echo "   - Demo users: " . count($demoUsers) . "\n";
echo "   - Login: demo.user1@swapin.local / Demo1234!\n";
echo "\n";
