<?php
// اسکریپت ساده: آگهی‌ها را با دسته‌بندی فعلیشان می‌گیرد

$host = 'localhost';
$dbnames = ['swapin', 'kala_b_kala'];
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$pdo = null;
foreach ($dbnames as $dbname) {
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5]);
        break;
    } catch (PDOException $e) {
        try {
            $pdo = new PDO($dsn, $user, 'zqesB)3t9k1s%zCN', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5]);
            break;
        } catch (PDOException $e2) { $pdo = null; }
    }
}
if (!$pdo) die("❌ اتصال دیتابیس ناموفق\n");

// چک کردن وجود جدول‌ها
echo "Tables در دیتابیس:\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "  " . implode(", ", $tables) . "\n\n";

// ستون‌های جدول categories
echo "ستون‌های categories:\n";
$cols = $pdo->query("DESCRIBE categories")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  {$c['Field']} - {$c['Type']}\n";
echo "\n";

// ستون‌های جدول listings
echo "ستون‌های listings:\n";
$cols = $pdo->query("DESCRIBE listings")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  {$c['Field']} - {$c['Type']}\n";
echo "\n";

// گرفتن دسته‌ها
echo "═══════════════════════ دسته‌ها ═══════════════════════\n";
$cats = $pdo->query("SELECT id, name, parent_id FROM categories ORDER BY COALESCE(parent_id,0), id")->fetchAll(PDO::FETCH_ASSOC);
$catArr = [];
foreach ($cats as $c) {
    $catArr[(int)$c['id']] = $c;
    $p = $c['parent_id'] ? " (پدر #{$c['parent_id']})" : '';
    echo "  #{$c['id']}  {$c['name']}$p\n";
}
echo "جمع: " . count($cats) . " دسته\n\n";

// گرفتن آگهی‌ها (تا 300 مورد)
echo "═══════════════════════ آگهی‌ها ═══════════════════════\n";
printf("%-5s | %-25s | %s\n", "ID", "دسته فعلی", "عنوان آگهی");
echo str_repeat("-", 100) . "\n";

$ads = $pdo->query("SELECT id, title, category_id FROM listings ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ads as $a) {
    $cid = (int)$a['category_id'];
    $cname = $catArr[$cid]['name'] ?? "ناشناخته#$cid";
    $short = mb_substr($a['title'], 0, 60);
    printf("%-5s | #%-3d %-20s | %s\n", $a['id'], $cid, mb_substr($cname,0,20), $short);
}
echo "\nجمع: " . count($ads) . " آگهی\n";
