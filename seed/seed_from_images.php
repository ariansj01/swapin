<?php
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/config.php';

echo "=== Seed from images ===\n";

$imagesDir = __DIR__ . '/../عکس اگهی ها/v2/';
if (!is_dir($imagesDir)) {
    die("Error: Directory not found: $imagesDir\n");
}

// Step 0: Delete ALL old demo listings and their images
echo "Deleting old demo listings...\n";
try {
    // Get all demo user IDs
    $demoUserIds = array_column(DB::fetchAll("SELECT id FROM users WHERE email LIKE 'demo.user%'"), 'id');
    if (!empty($demoUserIds)) {
        $ph = implode(',', array_fill(0, count($demoUserIds), '?'));
        
        // Get old demo listing images to delete from disk
        $oldImages = DB::fetchAll("SELECT li.filename FROM listing_images li JOIN listings l ON li.listing_id = l.id WHERE l.user_id IN ($ph)", $demoUserIds);
        foreach ($oldImages as $img) {
            $path = UPLOAD_DIR . $img['filename'];
            if (file_exists($path)) {
                unlink($path);
            }
        }
        
        // Delete old demo listings (cascade deletes images from DB)
        DB::query("DELETE FROM listings WHERE user_id IN ($ph)", $demoUserIds);
        echo "Old listings deleted.\n";
    }
} catch (Throwable $e) {
    echo "Note: Couldn't delete old listings (maybe none exist): " . $e->getMessage() . "\n";
}

// Step 1: Make sure we have demo users
echo "Checking for demo users...\n";
$demoUsers = DB::fetchAll("SELECT id, city FROM users WHERE email LIKE 'demo.user%' ORDER BY id");
if (empty($demoUsers)) {
    echo "Creating demo users first...\n";
    // Create basic demo users manually
    $passwordHash = password_hash('Demo1234!', PASSWORD_BCRYPT);
    $cities = ['تهران', 'اصفهان', 'مشهد', 'شیراز', 'تبریز', 'اهواز', 'کرج', 'قم'];
    for ($i = 1; $i <= 15; $i++) {
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
    echo count($demoUsers) . " demo users created.\n";
}

// Step 2: Get categories
$categories = DB::fetchAll("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY id");
$catMap = [];
$defaultCatId = !empty($categories) ? $categories[0]['id'] : 11;
foreach ($categories as $cat) {
    $catMap[mb_strtolower($cat['name'])] = $cat['id'];
}

// Smart listing details generator based on title
function generate_listing_details($title) {
    $lowerTitle = mb_strtolower($title);
    
    // Default values
    $details = [
        'condition' => 'good',
        'estimatedValue' => rand(2000000, 30000000),
        'wantInReturn' => 'چیز مفید و ارزشمند',
        'wantType' => 'any',
        'description' => ''
    ];
    
    // Phones
    if (mb_strpos($lowerTitle, 'آیفون') !== false || mb_strpos($lowerTitle, 'گوشی') !== false || mb_strpos($lowerTitle, 'سامسونگ') !== false) {
        $details['condition'] = ['new', 'like_new', 'good'][array_rand(['new', 'like_new', 'good'])];
        $details['estimatedValue'] = rand(15000000, 80000000);
        $details['wantInReturn'] = 'لپ تاپ، تبلت یا ساعت هوشمند';
        $details['wantType'] = 'item';
        $details['description'] = "گوشی $title در وضعیت عالی، بدون خط و خش. برای معاوضه آماده است. باتری سلامت دارد و تمامی قطعات اصلی است.";
    }
    // Cars
    elseif (mb_strpos($lowerTitle, 'پژو') !== false || mb_strpos($lowerTitle, 'خودرو') !== false || mb_strpos($lowerTitle, 'کوییک') !== false || mb_strpos($lowerTitle, 'بنلی') !== false) {
        $details['condition'] = ['good', 'fair'][array_rand(['good', 'fair'])];
        $details['estimatedValue'] = rand(100000000, 800000000);
        $details['wantInReturn'] = 'خانه، خودرو دیگر یا کالای ارزشمند';
        $details['wantType'] = 'item';
        $details['description'] = "خودرو $title، سالم و سالم. موتور و گیربکس سالم، بدون تصادف. فقط معاوضه با پیشنهاد مناسب.";
    }
    // Houses
    elseif (mb_strpos($lowerTitle, 'خانه') !== false || mb_strpos($lowerTitle, 'اپارتمان') !== false) {
        $details['condition'] = 'good';
        $details['estimatedValue'] = rand(500000000, 5000000000);
        $details['wantInReturn'] = 'خانه دیگر، خودرو یا کالاهای ارزشمند';
        $details['wantType'] = 'item';
        $details['description'] = "$title، آماده تحویل. امکان معاوضه با خانه‌های دیگر یا خودروهای جدید وجود دارد.";
    }
    // Computers
    elseif (mb_strpos($lowerTitle, 'لپ') !== false || mb_strpos($lowerTitle, 'کامپیوتر') !== false) {
        $details['condition'] = ['like_new', 'good'][array_rand(['like_new', 'good'])];
        $details['estimatedValue'] = rand(20000000, 100000000);
        $details['wantInReturn'] = 'گوشی پرچمدار، تبلت یا کنسول بازی';
        $details['wantType'] = 'item';
        $details['description'] = "لپ تاپ $title، مناسب کار و بازی. قطعات اصلی و سالم، گارانتی یا فاکتور موجود است.";
    }
    // Food/agriculture
    elseif (mb_strpos($lowerTitle, 'عسل') !== false || mb_strpos($lowerTitle, 'زعفران') !== false || mb_strpos($lowerTitle, 'خروس') !== false) {
        $details['condition'] = 'new';
        $details['estimatedValue'] = rand(500000, 10000000);
        $details['wantInReturn'] = 'لوازم خانگی، مواد غذایی یا اعتبار';
        $details['wantType'] = 'any';
        $details['description'] = "$title با کیفیت عالی، تازه و سالم. مناسب معاوضه با لوازم خانگی یا سایر کالاها.";
    }
    // Home appliances
    elseif (mb_strpos($lowerTitle, 'کولر') !== false || mb_strpos($lowerTitle, 'یخچال') !== false || mb_strpos($lowerTitle, 'ماشین') !== false) {
        $details['condition'] = ['good', 'like_new'][array_rand(['good', 'like_new'])];
        $details['estimatedValue'] = rand(10000000, 50000000);
        $details['wantInReturn'] = 'لوازم خانگی دیگر، مبلمان یا گوشی';
        $details['wantType'] = 'item';
        $details['description'] = "$title سالم و کم کارکرد. کامل و با تمامی قطعات، آماده استفاده.";
    }
    // Default
    else {
        $details['description'] = "$title برای معاوضه. وضعیت عالی، آماده تحویل. پیشنهاد خودتون رو بدید!";
        $details['wantInReturn'] = 'چیز مفید و ارزشمند';
    }
    
    return $details;
}

// Step 3: Process each folder
echo "Processing image folders...\n";
$folders = array_diff(scandir($imagesDir), ['.', '..']);
$listingCount = 0;
$userIndex = 0;

foreach ($folders as $folder) {
    $folderPath = $imagesDir . $folder;
    if (!is_dir($folderPath)) continue;
    
    $title = trim($folder);
    if (empty($title)) continue;
    
    // Get images in this folder
    $images = [];
    $files = array_diff(scandir($folderPath), ['.', '..']);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $images[] = $file;
        }
    }
    
    if (empty($images)) continue;
    
    echo "Processing: $title (" . count($images) . " images)\n";
    
    // Select user for this listing
    $user = $demoUsers[$userIndex % count($demoUsers)];
    $userIndex++;
    
    // Generate smart details
    $details = generate_listing_details($title);
    
    // Find matching category
    $catId = $defaultCatId;
    $lowerTitle = mb_strtolower($title);
    foreach ($catMap as $catName => $cid) {
        if (mb_strpos($lowerTitle, $catName) !== false) {
            $catId = $cid;
            break;
        }
    }
    
    // Create listing in DB
    try {
        $listingId = DB::insert('listings', db_filter_row('listings', [
            'user_id'         => $user['id'],
            'category_id'     => $catId,
            'title'           => $title,
            'description'     => $details['description'],
            'condition'       => $details['condition'],
            'estimated_value' => $details['estimatedValue'],
            'want_in_return'  => $details['wantInReturn'],
            'want_type'       => $details['wantType'],
            'listing_mode'    => 'swap',
            'review_status'   => 'approved',
            'city'            => $user['city'],
            'status'          => 'active',
            'views'           => rand(5, 300),
            'created_at'      => date('Y-m-d H:i:s', time() - rand(0, 86400 * 30)),
        ]));
        
        // Copy images to uploads folder and link to listing
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
        
        $listingCount++;
        echo "  ✅ Created listing #$listingId for user #{$user['id']} with $sortOrder images\n";
    } catch (Throwable $e) {
        echo "  ❌ Failed for $title: " . $e->getMessage() . "\n";
    }
}

echo "\n✅ Done! Created $listingCount listings.\n";
echo "Visit your site to see them: " . APP_URL . "\n";
