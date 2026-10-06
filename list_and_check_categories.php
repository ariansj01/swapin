<?php
// لیست دسته‌بندی‌های سایت + مقایسه با ads-import.json

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

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║           لیست دسته‌بندی‌های سایت (ساختار درختی)          ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$cats = $pdo->query("SELECT id, name, parent_id FROM categories ORDER BY COALESCE(parent_id, 0), id")->fetchAll(PDO::FETCH_ASSOC);
$catMap = [];
$byParent = [];
foreach ($cats as $c) {
    $catMap[$c['id']] = $c;
    $pid = $c['parent_id'] ?? 0;
    if (!isset($byParent[$pid])) $byParent[$pid] = [];
    $byParent[$pid][] = $c;
}

$validCatIds = [];

function printTree($parentId, $level) {
    global $byParent, $validCatIds;
    if (!isset($byParent[$parentId])) return;
    foreach ($byParent[$parentId] as $c) {
        $indent = str_repeat('  ', $level);
        $validCatIds[] = $c['id'];
        echo "{$indent}#{$c['id']}  {$c['name']}\n";
        printTree($c['id'], $level + 1);
    }
}
printTree(0, 0);
$validCatIds = array_unique($validCatIds);
sort($validCatIds);

echo "\n📊 مجموعاً " . count($validCatIds) . " دسته‌بندی فعال در سایت\n\n";

// ────────────────────────────────────────────────────────────
// بررسی ads-import.json
// ────────────────────────────────────────────────────────────
$jsonFile = __DIR__ . '/ads-import.json';
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║              بررسی ads-import.json                        ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

if (!file_exists($jsonFile)) die("❌ ads-import.json پیدا نشد\n");
$ads = json_decode(file_get_contents($jsonFile), true);
if (!is_array($ads)) die("❌ ساختار JSON نامعتبر\n");

echo "📄 " . count($ads) . " آگهی در JSON وجود دارد\n\n";

$usedCatIds = [];
$invalidCatIds = [];
$byCat = [];

foreach ($ads as $idx => $a) {
    $cid = (int)($a['category_id'] ?? 0);
    $title = $a['title'] ?? 'بدون عنوان';
    $line = $idx + 1;

    if (!isset($byCat[$cid])) $byCat[$cid] = [];
    $byCat[$cid][] = ['line' => $line, 'title' => $title];
    $usedCatIds[$cid] = ($usedCatIds[$cid] ?? 0) + 1;

    if (!in_array($cid, $validCatIds, true)) {
        $invalidCatIds[] = $cid;
    }
}

ksort($usedCatIds);
$invalidCatIds = array_values(array_unique($invalidCatIds));

echo "━━━ دسته‌بندی‌های استفاده شده در JSON ━━━\n";
foreach ($usedCatIds as $cid => $cnt) {
    $cname = $catMap[$cid]['name'] ?? '❌ نامعتبر';
    $valid = in_array($cid, $validCatIds, true) ? '✅' : '❌';
    $sample = array_slice($byCat[$cid], 0, 2);
    $sampleStr = implode(' / ', array_column($sample, 'title'));
    echo "  $valid #$cid  $cname  — $cnt آگهی  (مثال: $sampleStr)\n";
}

echo "\n";
if ($invalidCatIds) {
    echo "❌ دسته‌بندی‌های نامعتبر (در سایت وجود ندارند): " . implode(', ', $invalidCatIds) . "\n\n";
    echo "⚠️  آگهی‌هایی که در دسته‌بندی نامعتبر قرار گرفته‌اند:\n";
    foreach ($invalidCatIds as $icid) {
        echo "  ── دسته #$icid (نامعتبر):\n";
        foreach ($byCat[$icid] as $ad) {
            echo "     ردیف {$ad['line']}: {$ad['title']}\n";
        }
    }
} else {
    echo "✅ تمامی دسته‌بندی‌های استفاده شده در JSON معتبر هستند و در سایت وجود دارند.\n";
}
