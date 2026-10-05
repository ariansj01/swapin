<?php
define('SKIP_SESSION', true);
define('CLI_MODE', true);

require_once __DIR__ . '/includes/config.php';

$pdo = DB::pdo();

echo "بررسی آگهی‌های موجود در دیتابیس با ID مربوط به عکس‌ها:\n";
echo "====================================================\n\n";

$imgDir = __DIR__ . '/عکس های اگهی ها';
$files = glob($imgDir . '/*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE);

$ids = [];
foreach ($files as $f) {
    $name = pathinfo($f, PATHINFO_FILENAME);
    if (is_numeric($name)) $ids[] = (int)$name;
}
sort($ids);

echo "تعداد عکس‌ها: " . count($ids) . "\n";
echo "IDهای موجود در عکس‌ها: " . implode(', ', $ids) . "\n\n";

echo "بررسی وجود این آگهی‌ها در دیتابیس:\n";
$exists = [];
$missing = [];
foreach ($ids as $id) {
    $row = DB::fetch("SELECT id, title, status FROM listings WHERE id = ?", [$id]);
    if ($row) {
        $exists[] = $row;
        echo "  ✅ ID=$id: {$row['title']} (status: {$row['status']})\n";
    } else {
        $missing[] = $id;
        echo "  ❌ ID=$id: در دیتابیس وجود ندارد\n";
    }
}

echo "\n\nخلاصه:\n";
echo "  آگهی‌های موجود: " . count($exists) . "\n";
echo "  آگهی‌های ناموجود: " . count($missing) . "\n";
if ($missing) echo "  IDهای ناموجود: " . implode(', ', $missing) . "\n";

echo "\n\nساختار جدول listing_images:\n";
$cols = DB::fetchAll("DESCRIBE listing_images");
foreach ($cols as $c) {
    echo "  {$c['Field']} - {$c['Type']} - {$c['Null']} - {$c['Key']}\n";
}

echo "\n\nبررسی عکس‌های موجود برای این آگهی‌ها:\n";
foreach ($ids as $id) {
    $imgs = DB::fetchAll("SELECT id, filename, is_primary, sort_order FROM listing_images WHERE listing_id = ?", [$id]);
    echo "  آگهی #$id: " . count($imgs) . " عکس\n";
}
