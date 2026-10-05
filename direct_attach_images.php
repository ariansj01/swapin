<?php
// اسکریپت مستقیم و ساده برای اتصال به دیتابیس و وصل کردن عکس‌ها
// بدون بارگذاری کامل کانفیگ و مایگریشن‌ها

$host = 'localhost';
$dbnames = ['swapin', 'kala_b_kala'];
$user = 'root';
$pass = ''; // تست با پسورد خالی اول
$charset = 'utf8mb4';

echo "تست اتصال به دیتابیس...\n";

$pdo = null;
$connectedDb = '';
foreach ($dbnames as $dbname) {
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];
        $pdo = new PDO($dsn, $user, $pass, $options);
        $connectedDb = $dbname;
        echo "✅ به دیتابیس $dbname وصل شدیم\n";
        break;
    } catch (PDOException $e) {
        echo "⚠️  اتصال به $dbname ناموفق: " . $e->getMessage() . "\n";
    }
}

if (!$pdo) {
    // تلاش با پسورد از فایل swapin.txt
    $pass2 = 'zqesB)3t9k1s%zCN';
    foreach ($dbnames as $dbname) {
        try {
            $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
            $pdo = new PDO($dsn, $user, $pass2, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $connectedDb = $dbname;
            echo "✅ با پسورد دوم به دیتابیس $dbname وصل شدیم\n";
            break;
        } catch (PDOException $e) {
            echo "⚠️  اتصال به $dbname با پسورد دوم ناموفق: " . $e->getMessage() . "\n";
        }
    }
}

if (!$pdo) {
    die("❌ هیچ اتصال دیتابیسی برقرار نشد. لطفاً MySQL را در XAMPP استارت کنید.\n");
}

echo "\nبررسی جدول listings...\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM listings");
    $row = $stmt->fetch();
    echo "📊 مجموع آگهی‌ها در دیتابیس: " . $row['c'] . "\n";

    // آخرین ID آگهی
    $stmt = $pdo->query("SELECT MAX(id) as max_id, MIN(id) as min_id FROM listings");
    $row2 = $stmt->fetch();
    echo "   کمترین ID: {$row2['min_id']} | بیشترین ID: {$row2['max_id']}\n";
} catch (PDOException $e) {
    echo "⚠️  جدول listings پیدا نشد: " . $e->getMessage() . "\n";
}

// بررسی آگهی‌هایی که IDشان با عکس‌ها مطابقت دارد
$sourceDir = __DIR__ . '/عکس های اگهی ها';
$imgFiles = [];
foreach (scandir($sourceDir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $full = $sourceDir . '/' . $f;
    if (!is_file($full)) continue;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $name = pathinfo($f, PATHINFO_FILENAME);
    if (!in_array($ext, ['jpg','jpeg','png','webp','avif','gif'])) continue;
    if (!is_numeric($name)) continue;
    $imgFiles[] = ['id' => (int)$name, 'file' => $f, 'full' => $full, 'ext' => $ext];
}

echo "\n🖼️  " . count($imgFiles) . " عکس با ID عددی در پوشه پیدا شد\n";

// بررسی وجود این IDها در دیتابیس
$ids = array_column($imgFiles, 'id');
sort($ids);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, title FROM listings WHERE id IN ($placeholders)");
$stmt->execute($ids);
$existing = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$found = count($existing);
$notFound = count($ids) - $found;
echo "   آگهی‌های موجود در دیتابیس: $found\n";
echo "   آگهی‌های ناموجود: $notFound\n";
if ($notFound > 0) {
    $missingIds = array_values(array_diff($ids, array_keys($existing)));
    echo "   IDهای ناموجود: " . implode(', ', $missingIds) . "\n";
}

// ساخت پوشه uploads/listings
$uploadBase = __DIR__ . '/uploads';
$listingsDir = $uploadBase . '/listings';
if (!is_dir($listingsDir)) @mkdir($listingsDir, 0755, true);

echo "\nشروع پردازش عکس‌ها...\n\n";

$pdo->beginTransaction();
$success = 0;
$totalImgs = 0;

// گروه‌بندی عکس‌ها بر اساس ID آگهی
$byId = [];
foreach ($imgFiles as $img) {
    $byId[$img['id']][] = $img;
}
ksort($byId);

foreach ($byId as $lid => $imgs) {
    if (!isset($existing[$lid])) {
        echo "  ❌ آگهی #$lid: در دیتابیس نیست — " . count($imgs) . " عکس رد شد\n";
        continue;
    }

    $targetDir = $listingsDir . '/' . $lid;
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    if (!is_dir($targetDir)) {
        echo "  ❌ آگهی #$lid: پوشه ساخته نشد\n";
        continue;
    }

    // حذف عکس‌های قبلی
    $del = $pdo->prepare("DELETE FROM listing_images WHERE listing_id = ?");
    $del->execute([$lid]);
    foreach (glob($targetDir . '/*') as $old) {
        if (is_file($old)) @unlink($old);
    }

    $sort = 0;
    $attached = 0;
    foreach ($imgs as $img) {
        $size = filesize($img['full']);
        if ($size < 500) continue;

        $newName = 'img_' . $lid . '_' . $sort . '_' . substr(md5($img['file'] . microtime(true)), 0, 6) . '.' . $img['ext'];
        $dest = $targetDir . '/' . $newName;
        if (!@copy($img['full'], $dest)) continue;
        if (!file_exists($dest) || filesize($dest) < 500) { @unlink($dest); continue; }

        $rel = "listings/$lid/$newName";
        $ins = $pdo->prepare("INSERT INTO listing_images (listing_id, filename, is_primary, sort_order) VALUES (?,?,?,?)");
        $ins->execute([$lid, $rel, $sort === 0 ? 1 : 0, $sort]);
        $sort++;
        $attached++;
        $totalImgs++;
    }
    $success++;
    $t = mb_substr($existing[$lid], 0, 50);
    echo "  ✅ آگهی #$lid: $t — $attached عکس\n";
}

$pdo->commit();

echo "\n━━━ نتیجه ━━━\n";
echo "  آگهی موفق: $success\n";
echo "  عکس وصل شده: $totalImgs\n";
echo "  آگهی ناموجود: $notFound\n";
echo "\nتمام شد.\n";
