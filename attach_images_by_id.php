<?php
define('SKIP_SESSION', true);
define('CLI_MODE', true);

require_once __DIR__ . '/includes/config.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║      سواپین — وصل کردن عکس‌ها بر اساس ID آگهی          ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$sourceDir = __DIR__ . '/عکس های اگهی ها';
$listingsBaseDir = UPLOAD_DIR . '/listings';

if (!is_dir($sourceDir)) {
    die("❌ پوشه منبع پیدا نشد: $sourceDir\n");
}

$supportedExts = ['jpg','jpeg','png','webp','avif','gif'];
$files = [];
foreach (scandir($sourceDir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $full = $sourceDir . '/' . $f;
    if (!is_file($full)) continue;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $name = pathinfo($f, PATHINFO_FILENAME);
    if (!in_array($ext, $supportedExts, true)) {
        echo "  ⚠️ فایل با پسوند نامعتبر رد شد: $f\n";
        continue;
    }
    if (!is_numeric($name)) {
        echo "  ⚠️ نام فایل عددی نیست — رد شد: $f\n";
        continue;
    }
    $files[] = [
        'filename' => $f,
        'listing_id' => (int)$name,
        'ext' => $ext,
        'fullpath' => $full,
        'size' => filesize($full),
    ];
}

if (!$files) {
    die("❌ هیچ عکس معتبری در پوشه پیدا نشد\n");
}

usort($files, fn($a, $b) => $a['listing_id'] <=> $b['listing_id']);

echo "🗂️  مجموعاً " . count($files) . " عکس برای پردازش پیدا شد.\n\n";

$pdo = DB::pdo();
$pdo->beginTransaction();

$success = 0;
$skipNoListing = 0;
$skipBadFile = 0;
$imgCount = 0;

try {
    // گروه‌بندی عکس‌ها بر اساس listing_id
    $byListing = [];
    foreach ($files as $f) {
        $byListing[$f['listing_id']][] = $f;
    }

    foreach ($byListing as $lid => $imgList) {
        // بررسی وجود آگهی
        $listing = DB::fetch("SELECT id, title FROM listings WHERE id = ?", [$lid]);
        if (!$listing) {
            echo "  ❌ آگهی #$lid: در دیتابیس وجود ندارد — " . count($imgList) . " عکس رد شد\n";
            $skipNoListing += count($imgList);
            continue;
        }

        // ساخت پوشه آگهی
        $targetDir = $listingsBaseDir . '/' . $lid;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            echo "  ❌ آگهی #$lid: امکان ساخت پوشه وجود ندارد — رد شد\n";
            $skipBadFile += count($imgList);
            continue;
        }

        // حذف عکس‌های قبلی برای این آگهی (جایگزینی کامل)
        DB::query("DELETE FROM listing_images WHERE listing_id = ?", [$lid]);
        // پاک کردن فایل‌های قبلی در پوشه
        foreach (glob($targetDir . '/*') as $old) {
            if (is_file($old)) @unlink($old);
        }

        $sort = 0;
        $attached = 0;
        foreach ($imgList as $img) {
            // بررسی سایز عکس
            if ($img['size'] < 500) {
                echo "      ⚠️ عکس {$img['filename']} خیلی کوچک است — رد شد\n";
                $skipBadFile++;
                continue;
            }

            // نام فایل مقصد
            $newFname = 'img_' . $lid . '_' . $sort . '_' . substr(md5($img['filename'] . microtime(true)), 0, 6) . '.' . $img['ext'];
            $destPath = $targetDir . '/' . $newFname;

            if (!@copy($img['fullpath'], $destPath)) {
                echo "      ⚠️ کپی ناموفق برای {$img['filename']}\n";
                $skipBadFile++;
                continue;
            }
            if (!file_exists($destPath) || filesize($destPath) < 500) {
                @unlink($destPath);
                $skipBadFile++;
                continue;
            }

            $relPath = "listings/$lid/$newFname";
            DB::insert('listing_images', [
                'listing_id' => $lid,
                'filename'   => $relPath,
                'is_primary' => $sort === 0 ? 1 : 0,
                'sort_order' => $sort,
            ]);

            $sort++;
            $attached++;
            $imgCount++;
        }

        $success++;
        echo "  ✅ آگهی #$lid: {$listing['title']} — $attached عکس وصل شد\n";
    }

    $pdo->commit();

    echo "\n━━━ نتیجه نهایی ━━━\n";
    echo "  آگهی‌های پردازش شده (موفق): $success\n";
    echo "  مجموع عکس‌های وصل شده    : $imgCount\n";
    echo "  عکس‌های ردشده (بدون آگهی): $skipNoListing\n";
    echo "  عکس‌های ردشده (مشکل فایل): $skipBadFile\n";
    echo "\n🎉 عملیات به پایان رسید.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌ خطا: " . $e->getMessage() . "\n";
    echo "   فایل: " . $e->getFile() . " خط " . $e->getLine() . "\n";
    exit(1);
}
