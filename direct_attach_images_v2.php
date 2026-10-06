<?php
// اتصال عکس‌ها از پوشه «عکس های اگهی های 2» بر اساس ID آگهی (نام فایل = ID آگهی)

$host = 'localhost';
$dbnames = ['swapin', 'kala_b_kala'];
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

echo "اتصال به دیتابیس...\n";

$pdo = null;
$connectedDb = '';
foreach ($dbnames as $dbname) {
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $connectedDb = $dbname;
        echo "✅ وصل شد به $connectedDb\n";
        break;
    } catch (PDOException $e) {
        try {
            $pass2 = 'zqesB)3t9k1s%zCN';
            $pdo = new PDO($dsn, $user, $pass2, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $connectedDb = $dbname;
            echo "✅ با پسورد دوم وصل شد به $connectedDb\n";
            break;
        } catch (PDOException $e2) {
            $pdo = null;
        }
    }
}
if (!$pdo) die("❌ اتصال دیتابیس ناموفق\n");

$sourceDir = __DIR__ . '/عکس های اگهی های 2';
if (!is_dir($sourceDir)) die("❌ پوشه پیدا نشد: $sourceDir\n");

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
if (!$imgFiles) die("❌ هیچ عکس عددی پیدا نشد\n");

echo "🖼️  " . count($imgFiles) . " عکس پیدا شد\n";

$ids = array_column($imgFiles, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, title FROM listings WHERE id IN ($placeholders)");
$stmt->execute($ids);
$existing = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$listingsDir = __DIR__ . '/uploads/listings';
if (!is_dir($listingsDir)) @mkdir($listingsDir, 0755, true);

$pdo->beginTransaction();
$success = 0;
$totalImgs = 0;
$notFound = 0;

$byId = [];
foreach ($imgFiles as $img) $byId[$img['id']][] = $img;
ksort($byId);

foreach ($byId as $lid => $imgs) {
    if (!isset($existing[$lid])) {
        echo "  ❌ آگهی #$lid: ناموجود — " . count($imgs) . " عکس رد شد\n";
        $notFound += count($imgs);
        continue;
    }
    $targetDir = $listingsDir . '/' . $lid;
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    if (!is_dir($targetDir)) continue;

    $del = $pdo->prepare("DELETE FROM listing_images WHERE listing_id = ?");
    $del->execute([$lid]);
    foreach (glob($targetDir . '/*') as $old) if (is_file($old)) @unlink($old);

    $sort = 0;
    $attached = 0;
    foreach ($imgs as $img) {
        if (filesize($img['full']) < 500) continue;
        $newName = 'img_' . $lid . '_' . $sort . '_' . substr(md5($img['file'] . microtime(true)), 0, 6) . '.' . $img['ext'];
        $dest = $targetDir . '/' . $newName;
        if (!@copy($img['full'], $dest)) continue;
        if (!file_exists($dest) || filesize($dest) < 500) { @unlink($dest); continue; }
        $rel = "listings/$lid/$newName";
        $ins = $pdo->prepare("INSERT INTO listing_images (listing_id, filename, is_primary, sort_order) VALUES (?,?,?,?)");
        $ins->execute([$lid, $rel, $sort === 0 ? 1 : 0, $sort]);
        $sort++; $attached++; $totalImgs++;
    }
    $success++;
    $t = mb_substr($existing[$lid], 0, 50);
    echo "  ✅ آگهی #$lid: $t — $attached عکس\n";
}
$pdo->commit();

echo "\n━━━ نتیجه ━━━\nآگهی موفق: $success\nعکس وصل شده: $totalImgs\nعکس ردشده (بدون آگهی): $notFound\nتمام شد.\n";
