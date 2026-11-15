<?php
/**
 * سواپین — ابزار تشخیصی درخت دسته‌بندی (فقط‌خواندنی)
 *
 * این اسکریپت هیچ داده‌ای را تغییر نمی‌دهد و عمداً «includes/config.php» را
 * لود نمی‌کند، تا مایگریشن‌های خودکار CLI (که در config.php تعریف شده‌اند)
 * اجرا نشوند. فقط «.env» خوانده می‌شود و صرفاً کوئری‌های SELECT زده می‌شود.
 *
 * اجرا روی سرور، از ریشه‌ی پروژه:
 *   php tools/category-tree-audit.php
 *   php tools/category-tree-audit.php --csv=/var/www/swapin-staging/storage
 *
 * خروجی:
 *   ۱) محیط و خلاصه‌ی وضعیت
 *   ۲) جدول کامل دسته‌ها (id | والد | فعال | عمق | نام | اسلاگ | مسیر)
 *   ۳) تعداد آگهی هر دسته
 *   ۴) دسته‌های خارج از درخت (orphan) و دلیل آن
 *   ۵) آگهی‌های عمومی خارج از درخت
 *   ۶) بررسی یکپارچگی (اسلاگ/نام تکراری، والد یا دسته‌ی ناموجود)
 *
 * در صورت دادن «--csv=مسیر»، سه فایل CSV هم نوشته می‌شود:
 *   categories.csv — همه‌ی دسته‌ها با مسیر و شمارش آگهی
 *   orphan-categories.csv — دسته‌های خارج از درخت
 *   orphan-listings.csv — آگهی‌های خارج از درخت
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان (CLI) قابل اجراست.');
}

require_once __DIR__ . '/../includes/load_env.php';
load_env(__DIR__ . '/../.env');

$csvDir = null;
$templatePath = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csvDir = rtrim(substr($arg, 6), "/\\");
    } elseif (str_starts_with($arg, '--template=')) {
        $templatePath = trim(substr($arg, 11));
    }
}

$dbHost = getenv('SWAPIN_DB_HOST');
$dbName = getenv('SWAPIN_DB_NAME');
$dbUser = getenv('SWAPIN_DB_USER');
$dbPass = getenv('SWAPIN_DB_PASS');
$dbHost = ($dbHost !== false && $dbHost !== '') ? $dbHost : 'localhost';
$dbName = ($dbName !== false && $dbName !== '') ? $dbName : 'kala_b_kala';
$dbUser = ($dbUser !== false && $dbUser !== '') ? $dbUser : 'root';
$dbPass = ($dbPass === false) ? '' : $dbPass;

$appEnv = strtolower(trim((string)(getenv('SWAPIN_ENV') ?: 'development')));

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "اتصال به دیتابیس ناموفق بود: " . $e->getMessage() . "\n");
    exit(1);
}

function a_table_exists(PDO $pdo, string $table): bool
{
    $t = preg_replace('/[^a-z0-9_]/i', '', $table);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$t]);
    return (int)$stmt->fetchColumn() > 0;
}

function a_table_columns(PDO $pdo, string $table): array
{
    $t = preg_replace('/[^a-z0-9_]/i', '', $table);
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$t]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function a_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function a_fetch_one(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function a_line(string $ch = '─', int $n = 78): string
{
    return str_repeat($ch, $n);
}

function a_tsv(array $cells): string
{
    $out = [];
    foreach ($cells as $c) {
        $s = (string)$c;
        $s = str_replace(["\t", "\r", "\n"], ' ', $s);
        $out[] = $s;
    }
    return implode("\t", $out);
}

$now = date('Y-m-d H:i');
echo a_line('=') . "\n";
echo "سواپین — گزارش تشخیصی درخت دسته‌بندی\n";
echo "زمان اجرا: {$now}\n";
echo a_line('=') . "\n\n";

// ─── ۱) محیط ───────────────────────────────────────────────────────────────
echo "[۱] محیط\n";
echo "  APP_ENV   : {$appEnv}\n";
echo "  DB_HOST   : {$dbHost}\n";
echo "  DB_NAME   : {$dbName}\n";
echo "  PHP       : " . PHP_VERSION . "\n";

if (!a_table_exists($pdo, 'categories')) {
    echo "\nخطا: جدول «categories» در دیتابیس وجود ندارد.\n";
    exit(1);
}
if (!a_table_exists($pdo, 'listings')) {
    echo "\nخطا: جدول «listings» در دیتابیس وجود ندارد.\n";
    exit(1);
}

$listingCols = a_table_columns($pdo, 'listings');
$hasReviewStatus = in_array('review_status', $listingCols, true);
$hasCategoryId   = in_array('category_id', $listingCols, true);
$hasTitle        = in_array('title', $listingCols, true);

$publicSql = $hasReviewStatus
    ? "status = 'active' AND review_status = 'approved'"
    : "status = 'active'";

echo "\n";

// ─── دسته‌ها ───────────────────────────────────────────────────────────────
$catRows = a_fetch_all(
    $pdo,
    'SELECT id, parent_id, name, slug, is_active, sort_order
       FROM categories
      ORDER BY (parent_id IS NULL OR parent_id = 0) DESC, parent_id ASC, sort_order ASC, id ASC'
);

$cats = [];
foreach ($catRows as $r) {
    $id = (int)$r['id'];
    $cats[$id] = [
        'id'        => $id,
        'parent_id' => (int)($r['parent_id'] ?? 0),
        'name'      => (string)($r['name'] ?? ''),
        'slug'      => (string)($r['slug'] ?? ''),
        'is_active' => (int)($r['is_active'] ?? 0),
        'sort'      => (int)($r['sort_order'] ?? 0),
    ];
}

// زنجیره‌ی والدها و مسیر اسلاگ
$childCount = [];
$pathSlug   = [];
$pathIds    = [];
$pathActive = [];
$maxDepth   = 0;

foreach ($cats as $id => $c) {
    $pid = $c['parent_id'];
    if ($pid > 0) {
        $childCount[$pid] = ($childCount[$pid] ?? 0) + 1;
    }
}

foreach ($cats as $id => $c) {
    $chain = [];
    $cur = $id;
    $guard = 0;
    while (isset($cats[$cur]) && $guard++ < 50) {
        array_unshift($chain, $cur);
        $p = $cats[$cur]['parent_id'];
        if ($p <= 0) {
            break;
        }
        $cur = $p;
    }
    $slugs = [];
    $ids = [];
    $allActive = true;
    $broken = false;
    foreach ($chain as $cid) {
        if (!isset($cats[$cid])) {
            $broken = true;
            continue;
        }
        $slugs[] = $cats[$cid]['slug'];
        $ids[]   = $cid;
        if ((int)$cats[$cid]['is_active'] !== 1) {
            $allActive = false;
        }
    }
    // والد مستقیم ناموجود است؟
    if ($c['parent_id'] > 0 && !isset($cats[$c['parent_id']])) {
        $broken = true;
    }
    $pathSlug[$id]   = '/' . implode('/', $slugs);
    $pathIds[$id]    = $ids;
    $pathActive[$id] = $allActive && !$broken;
    $maxDepth        = max($maxDepth, count($chain));
}

// ─── شمارش آگهی‌ها ──────────────────────────────────────────────────────────
$totalListings = 0;
$publicListings = 0;
$withCategory = 0;
$withoutCategory = 0;
$perCatTotal = [];
$perCatPublic = [];
$brokenRefListings = 0;

if ($hasCategoryId) {
    $totalListings = (int)a_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM listings')['c'];
    $publicListings = (int)a_fetch_one($pdo, "SELECT COUNT(*) AS c FROM listings WHERE {$publicSql}")['c'];

    foreach (a_fetch_all($pdo, 'SELECT category_id, COUNT(*) AS c FROM listings GROUP BY category_id') as $r) {
        $cid = (int)($r['category_id'] ?? 0);
        $perCatTotal[$cid] = (int)$r['c'];
    }
    foreach (a_fetch_all($pdo, "SELECT category_id, COUNT(*) AS c FROM listings WHERE {$publicSql} GROUP BY category_id") as $r) {
        $cid = (int)($r['category_id'] ?? 0);
        $perCatPublic[$cid] = (int)$r['c'];
    }

    foreach ($perCatTotal as $cid => $cnt) {
        if ($cid <= 0 || !isset($cats[$cid])) {
            $withoutCategory += ($cid <= 0) ? $cnt : 0;
            if ($cid > 0) {
                $brokenRefListings += $cnt;
            }
        } else {
            $withCategory += $cnt;
        }
    }
}

$activeCats   = array_filter($cats, fn($c) => (int)$c['is_active'] === 1);
$inactiveCats = array_filter($cats, fn($c) => (int)$c['is_active'] !== 1);
$rootCats     = array_filter($activeCats, fn($c) => (int)$c['parent_id'] === 0);
$leafCats     = array_filter($activeCats, fn($c) => empty($childCount[$c['id']]));

echo "[۲] خلاصه\n";
echo "  دسته‌ها: کل=" . count($cats)
    . " | فعال=" . count($activeCats)
    . " | غیرفعال=" . count($inactiveCats)
    . " | ریشه‌ی فعال=" . count($rootCats)
    . " | برگ فعال=" . count($leafCats)
    . " | بیشترین عمق=" . $maxDepth . "\n";
echo "  آگهی‌ها: کل={$totalListings} | عمومی(فعال+تأیید)={$publicListings}"
    . " | دارای دسته‌ی موجود={$withCategory}"
    . " | بدون دسته={$withoutCategory}"
    . " | ارجاع به دسته‌ی ناموجود={$brokenRefListings}\n";
echo "\n";

// ─── ۳) جدول کامل دسته‌ها ──────────────────────────────────────────────────
echo "[۳] جدول کامل دسته‌ها (TSV)\n";
echo a_tsv(['id', 'parent_id', 'is_active', 'depth', 'name', 'slug', 'path']);
echo "\n";
foreach ($cats as $id => $c) {
    echo a_tsv([
        $c['id'],
        $c['parent_id'],
        $c['is_active'],
        count($pathIds[$id]),
        $c['name'],
        $c['slug'],
        $pathSlug[$id],
    ]) . "\n";
}
echo "\n";

// ─── ۴) تعداد آگهی هر دسته ─────────────────────────────────────────────────
echo "[۴] تعداد آگهی هر دسته (TSV)\n";
echo a_tsv(['id', 'is_active', 'name', 'slug', 'public_listings', 'total_listings']);
echo "\n";
foreach ($cats as $id => $c) {
    $pub = $perCatPublic[$id] ?? 0;
    $tot = $perCatTotal[$id] ?? 0;
    if ($pub === 0 && $tot === 0) {
        continue;
    }
    echo a_tsv([$c['id'], $c['is_active'], $c['name'], $c['slug'], $pub, $tot]) . "\n";
}
echo "\n";

// ─── ۵) دسته‌های خارج از درخت ──────────────────────────────────────────────
$orphanCats = [];
foreach ($cats as $id => $c) {
    $reason = null;
    if ((int)$c['is_active'] !== 1) {
        $reason = 'inactive';
    } elseif ($c['parent_id'] > 0 && !isset($cats[$c['parent_id']])) {
        $reason = 'broken_parent';
    } elseif (!$pathActive[$id]) {
        $reason = 'inactive_ancestor';
    }
    if ($reason !== null) {
        $orphanCats[$id] = $reason;
    }
}

echo "[۵] دسته‌های خارج از درخت (orphan) — مجموع " . count($orphanCats) . "\n";
echo a_tsv(['id', 'parent_id', 'is_active', 'name', 'slug', 'path', 'public_listings', 'total_listings', 'reason']);
echo "\n";
$orphanPublicListings = 0;
$orphanTotalListings  = 0;
$rowsForSort = [];
foreach ($orphanCats as $id => $reason) {
    $rowsForSort[] = [
        'id'   => $id,
        'pub'  => $perCatPublic[$id] ?? 0,
        'tot'  => $perCatTotal[$id] ?? 0,
        'why'  => $reason,
    ];
}
usort($rowsForSort, fn($a, $b) => $b['pub'] <=> $a['pub']);
foreach ($rowsForSort as $r) {
    $id = $r['id'];
    $c  = $cats[$id];
    $orphanPublicListings += $r['pub'];
    $orphanTotalListings  += $r['tot'];
    echo a_tsv([
        $c['id'],
        $c['parent_id'],
        $c['is_active'],
        $c['name'],
        $c['slug'],
        $pathSlug[$id],
        $r['pub'],
        $r['tot'],
        $r['why'],
    ]) . "\n";
}
echo "\n";

// ─── قالب نگاشت (اختیاری) ──────────────────────────────────────────────────
// فایل CSV با ستون خالی «new_leaf_slug» که برای کارت «نگاشت دسته‌های فعلی»
// با شیت مقایسه و پر می‌شود.
if ($templatePath !== null) {
    $fh = @fopen($templatePath, 'w');
    if ($fh === false) {
        echo "هشدار: نوشتن قالب نگاشت در «{$templatePath}» ممکن نشد.\n\n";
    } else {
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['old_id', 'old_name', 'old_slug', 'public_listings', 'total_listings', 'new_leaf_slug']);
        foreach ($rowsForSort as $r) {
            $id = $r['id'];
            $c  = $cats[$id];
            fputcsv($fh, [$c['id'], $c['name'], $c['slug'], $r['pub'], $r['tot'], '']);
        }
        fclose($fh);
        echo "قالب نگاشت نوشته شد: {$templatePath} (" . count($rowsForSort) . " ردیف)\n";
        echo "ستون new_leaf_slug را از شیت «نگاشت دسته‌های فعلی» پر کن.\n\n";
    }
}

// ─── ۶) آگهی‌های عمومی خارج از درخت ────────────────────────────────────────
echo "[۶] آگهی‌های عمومی خارج از درخت\n";
$pct = $publicListings > 0 ? round($orphanPublicListings * 100 / $publicListings, 1) : 0;
echo "  آگهی عمومی خارج از درخت={$orphanPublicListings} از {$publicListings} ({$pct}%)\n";
echo "  شمارش همه‌ی وضعیت‌ها در دسته‌های orphan={$orphanTotalListings}\n";
echo "\n";

// ─── ۷) یکپارچگی ───────────────────────────────────────────────────────────
echo "[۷] بررسی یکپارچگی\n";
$dupSlug = a_fetch_all(
    $pdo,
    'SELECT slug, COUNT(*) AS c FROM categories GROUP BY slug HAVING c > 1 ORDER BY c DESC, slug ASC'
);
echo "  اسلاگ تکراری: " . count($dupSlug) . "\n";
foreach ($dupSlug as $r) {
    $ids = a_fetch_all($pdo, 'SELECT id FROM categories WHERE slug = ?', [$r['slug']]);
    $idList = implode(',', array_map(fn($x) => (int)$x['id'], $ids));
    echo "    - «{$r['slug']}» × {$r['c']}  (id: {$idList})\n";
}

$dupName = a_fetch_all(
    $pdo,
    'SELECT name, COUNT(*) AS c FROM categories GROUP BY name HAVING c > 1 ORDER BY c DESC, name ASC'
);
echo "  نام تکراری: " . count($dupName) . "\n";
foreach ($dupName as $r) {
    $ids = a_fetch_all($pdo, 'SELECT id FROM categories WHERE name = ?', [$r['name']]);
    $idList = implode(',', array_map(fn($x) => (int)$x['id'], $ids));
    echo "    - «{$r['name']}» × {$r['c']}  (id: {$idList})\n";
}

$orphanParents = a_fetch_all(
    $pdo,
    'SELECT c.id, c.parent_id, c.name, c.slug
       FROM categories c
       LEFT JOIN categories p ON p.id = c.parent_id
      WHERE c.parent_id > 0 AND p.id IS NULL'
);
echo "  دسته با والد ناموجود: " . count($orphanParents) . "\n";
foreach ($orphanParents as $r) {
    echo "    - id={$r['id']} «{$r['name']}» والد={$r['parent_id']}\n";
}

if ($brokenRefListings > 0) {
    $badRef = a_fetch_all(
        $pdo,
        'SELECT l.category_id, COUNT(*) AS c
           FROM listings l
           LEFT JOIN categories c ON c.id = l.category_id
          WHERE l.category_id > 0 AND c.id IS NULL
          GROUP BY l.category_id
          ORDER BY c DESC'
    );
    echo "  آگهی با category_id ناموجود: {$brokenRefListings}\n";
    foreach ($badRef as $r) {
        echo "    - category_id={$r['category_id']} → {$r['c']} آگهی\n";
    }
} else {
    echo "  آگهی با category_id ناموجود: 0\n";
}
echo "\n";

// ─── خروجی CSV اختیاری ─────────────────────────────────────────────────────
if ($csvDir !== null) {
    if ($csvDir === '' || !is_dir($csvDir)) {
        echo "هشدار: مسیر CSV «{$csvDir}» یک پوشه‌ی معتبر نیست؛ از خروجی CSV صرف‌نظر شد.\n";
    } else {
        echo "[۸] خروجی CSV در: {$csvDir}\n";

        $fh = fopen($csvDir . '/categories.csv', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['id', 'parent_id', 'is_active', 'depth', 'name', 'slug', 'path', 'public_listings', 'total_listings']);
        foreach ($cats as $id => $c) {
            fputcsv($fh, [
                $c['id'], $c['parent_id'], $c['is_active'], count($pathIds[$id]),
                $c['name'], $c['slug'], $pathSlug[$id],
                $perCatPublic[$id] ?? 0, $perCatTotal[$id] ?? 0,
            ]);
        }
        fclose($fh);
        echo "    - categories.csv (" . count($cats) . " ردیف)\n";

        $fh = fopen($csvDir . '/orphan-categories.csv', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['id', 'parent_id', 'is_active', 'name', 'slug', 'path', 'public_listings', 'total_listings', 'reason']);
        foreach ($rowsForSort as $r) {
            $id = $r['id'];
            $c  = $cats[$id];
            fputcsv($fh, [
                $c['id'], $c['parent_id'], $c['is_active'], $c['name'], $c['slug'],
                $pathSlug[$id], $r['pub'], $r['tot'], $r['why'],
            ]);
        }
        fclose($fh);
        echo "    - orphan-categories.csv (" . count($rowsForSort) . " ردیف)\n";

        if ($hasCategoryId) {
            $orphanIds = array_keys($orphanCats);
            $fh = fopen($csvDir . '/orphan-listings.csv', 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['listing_id', 'title', 'status', 'review_status', 'category_id', 'category_name', 'category_slug']);
            if ($orphanIds !== []) {
                $ph = implode(',', array_fill(0, count($orphanIds), '?'));
                $sel = 'SELECT l.id, ' . ($hasTitle ? 'l.title' : '"" AS title')
                    . ', l.status, ' . ($hasReviewStatus ? 'l.review_status' : '"" AS review_status')
                    . ', l.category_id FROM listings l WHERE l.category_id IN (' . $ph . ') ORDER BY l.category_id, l.id';
                foreach (a_fetch_all($pdo, $sel, $orphanIds) as $lr) {
                    $cid = (int)$lr['category_id'];
                    fputcsv($fh, [
                        $lr['id'], $lr['title'], $lr['status'], $lr['review_status'],
                        $cid, $cats[$cid]['name'] ?? '', $cats[$cid]['slug'] ?? '',
                    ]);
                }
            }
            fclose($fh);
            echo "    - orphan-listings.csv\n";
        }
        echo "\n";
    }
}

echo a_line('=') . "\n";
echo "پایان گزارش. (هیچ داده‌ای تغییر نکرد)\n";
echo a_line('=') . "\n";
