<?php
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/config.php';

echo "=== Updating listing prices...\n";
echo "\n--- Current listings in DB:\n";
$allListings = DB::fetchAll('SELECT id, title FROM listings ORDER BY id');
foreach ($allListings as $l) {
    echo "  #{$l['id']}: {$l['title']}\n";
}
echo "\n--- Updating...\n";

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
    // Use LIKE with wildcards around
    $listing = DB::fetch('SELECT id, title, estimated_value FROM listings WHERE title LIKE ?', ["%$searchTitle%"]);
    if ($listing) {
        DB::query('UPDATE listings SET estimated_value = ? WHERE id = ?', [$price, $listing['id']]);
        echo "✅ Updated: #{$listing['id']} - {$listing['title']}\n   Old: " . number_format($listing['estimated_value']) . " → New: " . number_format($price) . " تومان\n";
        $updatedCount++;
    } else {
        echo "❌ Not found: $searchTitle\n";
    }
}

echo "\n✅ Done! Updated $updatedCount listings.\n";
