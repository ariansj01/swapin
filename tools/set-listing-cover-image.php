<?php
/**
 * تنظیم عکس کاور برای یک آگهی (اسکریپت یک‌بارمصرف)
 *
 * اجرا:
 *   php tools/set-listing-cover-image.php
 *   php tools/set-listing-cover-image.php 1315 "آبمیوه‌گیری صنعتی ۷۵۰ وات فیلتردار.jpg"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان (CLI) قابل اجراست.');
}

require_once __DIR__ . '/../includes/config.php';

$listingId  = isset($argv[1]) && $argv[1] !== '' ? (int)$argv[1] : 1315;
$projectRoot = dirname(__DIR__);

$srcPath = $projectRoot . DIRECTORY_SEPARATOR
    . (isset($argv[2]) && $argv[2] !== '' ? $argv[2] : 'آبمیوه‌گیری صنعتی ۷۵۰ وات فیلتردار.jpg');

// اگر نام فایل دقیق پیدا نشد، تنها تصویر موجود در ریشه پروژه انتخاب می‌شود
if (!is_file($srcPath)) {
    $candidates = glob($projectRoot . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
    if (count($candidates) === 1) {
        $srcPath = $candidates[0];
    } else {
        echo "فایل تصویر پیدا نشد: {$srcPath}\n";
        echo "تصاویر موجود در ریشه پروژه:\n";
        foreach ($candidates as $c) {
            echo ' - ' . basename($c) . "\n";
        }
        exit(1);
    }
}

$listing = DB::fetch('SELECT id, title FROM listings WHERE id = ?', [$listingId]);
if (!$listing) {
    echo "آگهی با شناسه {$listingId} پیدا نشد.\n";
    exit(1);
}

$file = [
    'name'     => basename($srcPath),
    'tmp_name' => $srcPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($srcPath),
];

$filename = upload_image($file, 'listing');
if (!$filename) {
    echo "آپلود تصویر ناموفق بود (فرمت/حجم مجاز نیست یا پوشه uploads قابل نوشتن نیست).\n";
    exit(1);
}

// کاور قبلی از حالت اصلی خارج می‌شود
DB::query('UPDATE listing_images SET is_primary = 0 WHERE listing_id = ?', [$listingId]);

DB::insert('listing_images', [
    'listing_id' => $listingId,
    'filename'   => $filename,
    'is_primary' => 1,
    'sort_order' => 0,
]);

echo "انجام شد.\n";
echo "آگهی: #{$listing['id']} — {$listing['title']}\n";
echo "فایل کاور: {$filename}\n";
echo "آدرس تصویر: " . UPLOAD_URL . $filename . "\n";
