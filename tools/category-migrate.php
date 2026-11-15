<?php
/**
 * سواپین — مهاجرت دسته‌بندی (اتصال دو فهرست دسته به هم)
 *
 * این اسکریپت دو کار انجام می‌دهد:
 *   گام ۱ — ساخت/اصلاح درخت دسته‌ها از روی «swaapin_category_tree()» تا همه‌ی
 *           دسته‌های سطح ۱ و برگ شیت با اسلاگ و والد درست در دیتابیس باشند و
 *           دسته‌های تکراری/قدیمی غیرفعال شوند.
 *   گام ۲ — انتقال آگهی‌های موجود در دسته‌های خارج از درخت به برگ معادل،
 *           با نقشه‌ی «swaapin_legacy_category_aliases()» (اسلاگ قدیم → برگ جدید).
 *   گام ۳ — تأیید: شمارش آگهی‌های فعال خارج از برگ‌های درخت باید صفر شود.
 *
 * پیش‌فرض «dry-run» است و هیچ چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 *
 * عمداً «includes/config.php» لود نمی‌شود تا مایگریشن‌های خودکار CLI اجرا نشوند؛
 * فقط «.env» خوانده می‌شود و کلاس DB با PDO مستقیم شبیه‌سازی می‌شود.
 *
 * اجرا روی سرور (از ریشه‌ی پروژه):
 *   php tools/category-migrate.php
 *   php tools/category-migrate.php --csv=/var/www/swapin-staging/storage
 *   php tools/category-migrate.php --apply
 *   php tools/category-migrate.php --apply --map=/path/to/map.csv
 *
 * «--map=» یک CSV دوستونی (slug_قدیم, slug_برگ_جدید) برای پرکردن نگاشت‌های
 * جاافتاده بدون دست‌زدن به کد است. ردیف سرصفحه اختیاری است.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان (CLI) قابل اجراست.');
}

require_once __DIR__ . '/../includes/load_env.php';
load_env(__DIR__ . '/../.env');

$apply   = false;
$csvDir  = null;
$mapPath = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--dry-run') {
        $apply = false;
    } elseif (str_starts_with($arg, '--csv=')) {
        $csvDir = rtrim(substr($arg, 6), "/\\");
    } elseif (str_starts_with($arg, '--map=')) {
        $mapPath = trim(substr($arg, 6));
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

// ── شبیه‌سازی کلاس DB (تا توابع درخت بدون config.php کار کنند) ────────────────
// نام محلی عمداً «CliDb» است و با class_alias به «DB» وصل می‌شود؛ در غیر این صورت
// ابزار تحلیل کد این کلاس را با «DB» در includes/config.php قاطی می‌کند.
if (!class_exists('DB')) {
    class CliDb
    {
        public static ?PDO $pdo = null;

        public static function query(string $sql, array $params = []): PDOStatement
        {
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }

        public static function fetch(string $sql, array $params = []): ?array
        {
            $row = self::query($sql, $params)->fetch();
            return $row ?: null;
        }

        public static function fetchAll(string $sql, array $params = []): array
        {
            return self::query($sql, $params)->fetchAll();
        }

        public static function lastId(): int
        {
            return (int)self::$pdo->lastInsertId();
        }
    }
    class_alias(CliDb::class, 'DB');
    CliDb::$pdo = $pdo;
}

if (!function_exists('swapin_debug_log')) {
    function swapin_debug_log($message, $context = null): void
    {
    }
}

require_once __DIR__ . '/../includes/i18n.php';

// ── کمکی‌ها ─────────────────────────────────────────────────────────────────
function m_table_exists(PDO $pdo, string $table): bool
{
    $t = preg_replace('/[^a-z0-9_]/i', '', $table);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$t]);
    return (int)$stmt->fetchColumn() > 0;
}

function m_table_columns(PDO $pdo, string $table): array
{
    $t = preg_replace('/[^a-z0-9_]/i', '', $table);
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$t]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function m_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function m_line(string $ch = '─', int $n = 78): string
{
    return str_repeat($ch, $n);
}

function m_tsv(array $cells): string
{
    $out = [];
    foreach ($cells as $c) {
        $s = (string)$c;
        $s = str_replace(["\t", "\r", "\n"], ' ', $s);
        $out[] = $s;
    }
    return implode("\t", $out);
}

/** عکس‌برداری از جدول دسته‌ها: bySlug و byId */
function m_snapshot(PDO $pdo): array
{
    $bySlug = [];
    $byId   = [];
    foreach (m_fetch_all($pdo, 'SELECT id, parent_id, name, slug, is_active, sort_order FROM categories') as $r) {
        $row = [
            'id'        => (int)$r['id'],
            'parent_id' => (int)($r['parent_id'] ?? 0),
            'name'      => (string)($r['name'] ?? ''),
            'slug'      => (string)($r['slug'] ?? ''),
            'is_active' => (int)($r['is_active'] ?? 0),
        ];
        $byId[$row['id']] = $row;
        if ($row['slug'] !== '' && !isset($bySlug[$row['slug']])) {
            $bySlug[$row['slug']] = $row;
        }
    }
    return ['bySlug' => $bySlug, 'byId' => $byId];
}

// ── اتصال به دیتابیس ────────────────────────────────────────────────────────
if (!m_table_exists($pdo, 'categories') || !m_table_exists($pdo, 'listings')) {
    fwrite(STDERR, "جدول «categories» یا «listings» در دیتابیس وجود ندارد.\n");
    exit(1);
}

$listingCols     = m_table_columns($pdo, 'listings');
$hasReviewStatus = in_array('review_status', $listingCols, true);
$hasCategoryId   = in_array('category_id', $listingCols, true);
$publicSql       = $hasReviewStatus
    ? "status = 'active' AND review_status = 'approved'"
    : "status = 'active'";

echo m_line('=') . "\n";
echo "سواپین — مهاجرت دسته‌بندی (" . ($apply ? 'APPLY — تغییر می‌دهد' : 'DRY-RUN — فقط گزارش') . ")\n";
echo "زمان اجرا: " . date('Y-m-d H:i') . "\n";
echo "محیط: {$appEnv} | DB: {$dbName} | PHP: " . PHP_VERSION . "\n";
echo m_line('=') . "\n\n";

// ── نگاشت اسلاگ قدیم → برگ جدید ─────────────────────────────────────────────
$aliasMap = swaapin_legacy_category_aliases();
$extraMap = [];
if ($mapPath !== null) {
    if (!is_file($mapPath)) {
        echo "هشدار: فایل نگاشت «{$mapPath}» پیدا نشد؛ نادیده گرفته شد.\n";
    } else {
        $fh = fopen($mapPath, 'r');
        while (($row = fgetcsv($fh)) !== false) {
            $k = trim((string)($row[0] ?? ''));
            $v = trim((string)($row[1] ?? ''));
            if ($k === '' || $v === '' || in_array(strtolower($k), ['slug', 'old_slug'], true)) {
                continue;
            }
            $extraMap[$k] = $v;
        }
        fclose($fh);
        echo "نگاشت افزوده از «{$mapPath}»: " . count($extraMap) . " ردیف\n\n";
    }
}
$resolveOut = function (string $slug) use ($aliasMap, $extraMap): ?string {
    return $extraMap[$slug] ?? $aliasMap[$slug] ?? null;
};

// ── درخت مقصد ───────────────────────────────────────────────────────────────
$tree    = swaapin_category_tree();
$desired = [];
foreach ($tree as $topSlug => $top) {
    $desired[$topSlug] = ['name' => (string)$top['name'], 'parent' => null];
    foreach (($top['children'] ?? []) as $child) {
        $cSlug = (string)($child['slug'] ?? '');
        if ($cSlug === '') {
            continue;
        }
        $desired[$cSlug] = ['name' => (string)($child['name'] ?? ''), 'parent' => $topSlug];
    }
}
$leafSlugs = [];
foreach ($desired as $slug => $d) {
    if ($d['parent'] !== null) {
        $leafSlugs[$slug] = true;
    }
}

$before = m_snapshot($pdo);

// ─── گام ۱: تفاوت درخت فعلی با درخت مقصد ───────────────────────────────────
$toCreate = [];
$toFix    = [];
foreach ($desired as $slug => $d) {
    $row = $before['bySlug'][$slug] ?? null;
    if ($row === null) {
        $toCreate[] = $slug;
        continue;
    }
    $expectedPid = 0;
    if ($d['parent'] !== null) {
        $p = $before['bySlug'][$d['parent']] ?? null;
        $expectedPid = $p ? (int)$p['id'] : -1; // -1 یعنی والد هنوز ساخته نشده
    }
    $needs = ((int)$row['is_active'] !== 1)
        || ($row['name'] !== $d['name'])
        || ($expectedPid !== -1 && (int)$row['parent_id'] !== $expectedPid);
    if ($needs) {
        $toFix[] = $slug;
    }
}

$toDeactivate = [];
foreach ($before['bySlug'] as $slug => $row) {
    if (!isset($desired[$slug]) && (int)$row['is_active'] === 1) {
        $toDeactivate[] = $slug;
    }
}

echo "[۱] گام ۱ — ساخت/اصلاح درخت دسته‌ها\n";
echo "  برگ‌های مقصد: " . count($leafSlugs) . " | سطح ۱: " . (count($desired) - count($leafSlugs)) . "\n";
echo "  ساخت جدید: " . count($toCreate)
    . " | اصلاح (والد/نام/فعال‌سازی): " . count($toFix)
    . " | غیرفعال‌سازی خارج از درخت: " . count($toDeactivate) . "\n";
if ($toCreate !== []) {
    echo "    ساختنی: " . implode(', ', array_slice($toCreate, 0, 40))
        . (count($toCreate) > 40 ? ' ...' : '') . "\n";
}
if ($toDeactivate !== []) {
    echo "    غیرفعال‌شونده: " . implode(', ', array_slice($toDeactivate, 0, 40))
        . (count($toDeactivate) > 40 ? ' ...' : '') . "\n";
}
echo "\n";

// ─── گام ۲: برنامه‌ی انتقال آگهی‌ها ──────────────────────────────────────────
$moves       = []; // old_id => ['slug'=>, 'target'=>, 'public'=>, 'total'=>]
$unmapped    = []; // old_id => ['slug'=>, 'public'=>, 'total'=>, 'reason'=>]
$nonLeaf     = []; // old_id => ['slug'=>, 'public'=>, 'total'=>]
$perCatPub   = [];
$perCatTotal = [];

if ($hasCategoryId) {
    foreach (m_fetch_all($pdo, 'SELECT category_id, COUNT(*) AS c FROM listings GROUP BY category_id') as $r) {
        $perCatTotal[(int)($r['category_id'] ?? 0)] = (int)$r['c'];
    }
    foreach (m_fetch_all($pdo, "SELECT category_id, COUNT(*) AS c FROM listings WHERE {$publicSql} GROUP BY category_id") as $r) {
        $perCatPub[(int)($r['category_id'] ?? 0)] = (int)$r['c'];
    }
}

foreach ($perCatTotal as $cid => $total) {
    $pub = $perCatPub[$cid] ?? 0;
    if ($cid <= 0) {
        $unmapped[$cid] = ['slug' => '(بدون دسته)', 'public' => $pub, 'total' => $total, 'reason' => 'no_category'];
        continue;
    }
    $row = $before['byId'][$cid] ?? null;
    if ($row === null) {
        $unmapped[$cid] = ['slug' => "(id={$cid})", 'public' => $pub, 'total' => $total, 'reason' => 'broken_ref'];
        continue;
    }
    $slug = $row['slug'];
    if (isset($desired[$slug])) {
        if (!isset($leafSlugs[$slug])) {
            $nonLeaf[$cid] = ['slug' => $slug, 'public' => $pub, 'total' => $total];
        }
        continue;
    }
    $target = $resolveOut($slug);
    if ($target === null) {
        $unmapped[$cid] = ['slug' => $slug, 'public' => $pub, 'total' => $total, 'reason' => 'no_alias'];
        continue;
    }
    if (!isset($desired[$target])) {
        $unmapped[$cid] = ['slug' => $slug, 'public' => $pub, 'total' => $total, 'reason' => "alias_to_unknown:{$target}"];
        continue;
    }
    $moves[$cid] = ['slug' => $slug, 'target' => $target, 'public' => $pub, 'total' => $total];
}

$movePublic = array_sum(array_column($moves, 'public'));
$moveTotal  = array_sum(array_column($moves, 'total'));
$unmapPublic = array_sum(array_column($unmapped, 'public'));
$unmapTotal  = array_sum(array_column($unmapped, 'total'));
$nonLeafPublic = array_sum(array_column($nonLeaf, 'public'));

echo "[۲] گام ۲ — انتقال آگهی‌های خارج از درخت\n";
echo "  قابل انتقال: " . count($moves) . " دسته → {$movePublic} آگهی فعال ({$moveTotal} کل)\n";
echo "  بدون نگاشت: " . count($unmapped) . " دسته → {$unmapPublic} آگهی فعال ({$unmapTotal} کل)\n";
echo "  در دسته‌ی سطح ۱ (بدون تصمیم خودکار): " . count($nonLeaf) . " دسته → {$nonLeafPublic} آگهی فعال\n\n";

if ($moves !== []) {
    echo "  نگاشت‌های برنامه‌ریزی‌شده (TSV):\n";
    echo m_tsv(['old_id', 'old_slug', 'new_leaf', 'public', 'total']) . "\n";
    $sorted = $moves;
    uasort($sorted, fn($a, $b) => $b['public'] <=> $a['public']);
    foreach ($sorted as $cid => $m) {
        echo m_tsv([$cid, $m['slug'], $m['target'], $m['public'], $m['total']]) . "\n";
    }
    echo "\n";
}

if ($unmapped !== []) {
    echo "  دسته‌های بدون نگاشت (نیاز به افزودن به --map یا alias):\n";
    echo m_tsv(['old_id', 'old_slug', 'public', 'total', 'reason']) . "\n";
    foreach ($unmapped as $cid => $u) {
        echo m_tsv([$cid, $u['slug'], $u['public'], $u['total'], $u['reason']]) . "\n";
    }
    echo "\n";
}

if ($nonLeaf !== []) {
    echo "  آگهی‌های چسبیده به دسته‌ی سطح ۱ (باید دستی به یک برگ منتقل شوند):\n";
    echo m_tsv(['cat_id', 'slug', 'public', 'total']) . "\n";
    foreach ($nonLeaf as $cid => $n) {
        echo m_tsv([$cid, $n['slug'], $n['public'], $n['total']]) . "\n";
    }
    echo "\n";
}

// ─── اعمال تغییرات ──────────────────────────────────────────────────────────
if (!$apply) {
    echo m_line('=') . "\n";
    echo "DRY-RUN: هیچ تغییری اعمال نشد. برای اجرای واقعی:\n";
    echo "  php tools/category-migrate.php --apply\n";
    echo m_line('=') . "\n";
    exit(0);
}

echo "[۳] اعمال تغییرات\n";
$updatedRows = 0;
try {
    $pdo->beginTransaction();

    $idsBySlug = swaapin_ensure_category_tree();
    echo "  درخت ساخته/اصلاح شد (خلاف: دسته‌های خارج از درخت غیرفعال شدند).\n";

    $after = m_snapshot($pdo);
    $resolveId = function (string $slug) use ($idsBySlug, $after): int {
        if (isset($idsBySlug[$slug])) {
            return (int)$idsBySlug[$slug];
        }
        return (int)($after['bySlug'][$slug]['id'] ?? 0);
    };

    $stmt = $pdo->prepare('UPDATE listings SET category_id = ? WHERE category_id = ?');
    foreach ($moves as $oldId => $m) {
        $targetId = $resolveId($m['target']);
        if ($targetId <= 0 || $targetId === (int)$oldId) {
            continue;
        }
        $stmt->execute([$targetId, $oldId]);
        $updatedRows += $stmt->rowCount();
    }
    echo "  آگهی‌های منتقل‌شده: {$updatedRows} ردیف\n";

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "خطا در اعمال مهاجرت (همه‌چیز برگردانده شد): " . $e->getMessage() . "\n");
    exit(1);
}
echo "\n";

// ─── گام ۳: تأیید ───────────────────────────────────────────────────────────
$after = m_snapshot($pdo);
$afterLeafIds = [];
$afterParentIds = [];
foreach ($after['bySlug'] as $slug => $row) {
    if (!isset($desired[$slug])) {
        continue;
    }
    if (isset($leafSlugs[$slug])) {
        $afterLeafIds[$row['id']] = $slug;
    } else {
        $afterParentIds[$row['id']] = $slug;
    }
}

$badLeaves = [];
$badParents = [];
if ($hasCategoryId) {
    foreach (m_fetch_all($pdo, "SELECT category_id, COUNT(*) AS c FROM listings WHERE {$publicSql} GROUP BY category_id") as $r) {
        $cid = (int)($r['category_id'] ?? 0);
        $cnt = (int)$r['c'];
        if (isset($afterLeafIds[$cid])) {
            continue;
        }
        if (isset($afterParentIds[$cid])) {
            $badParents[$cid] = $cnt;
        } else {
            $badLeaves[$cid] = $cnt;
        }
    }
}

$outsideCount = array_sum($badLeaves);
$parentCount  = array_sum($badParents);

echo "[۴] گام ۳ — تأیید\n";
echo "  آگهی فعال بدون برگ معتبر: {$outsideCount}\n";
foreach ($badLeaves as $cid => $cnt) {
    $name = $after['byId'][$cid]['slug'] ?? "(id={$cid})";
    echo "    - {$name} → {$cnt} آگهی\n";
}
if ($parentCount > 0) {
    echo "  آگهی فعال چسبیده به دسته‌ی سطح ۱: {$parentCount}\n";
    foreach ($badParents as $cid => $cnt) {
        echo "    - {$afterParentIds[$cid]} → {$cnt} آگهی\n";
    }
}

$dupSlug = m_fetch_all($pdo, 'SELECT slug, COUNT(*) AS c FROM categories GROUP BY slug HAVING c > 1');
echo "  اسلاگ تکراری: " . count($dupSlug) . "\n";
foreach ($dupSlug as $r) {
    echo "    - «{$r['slug']}» × {$r['c']}\n";
}

// نام تکراری میان دسته‌های «فعال» باید صفر شود. نام‌های تکراری میان دسته‌های
// غیرفعال (باقی‌مانده‌های قدیمی که از درخت خارج شده‌اند) فقط اطلاعی‌اند و
// شمارش نمی‌شوند، چون دیگر بخشی از درخت زنده نیستند.
$dupNameActive = m_fetch_all(
    $pdo,
    'SELECT name, COUNT(*) AS c FROM categories WHERE is_active = 1 GROUP BY name HAVING c > 1 ORDER BY c DESC, name ASC'
);
echo "  نام تکراری (فعال): " . count($dupNameActive) . "\n";
foreach ($dupNameActive as $r) {
    $ids = m_fetch_all($pdo, 'SELECT id FROM categories WHERE name = ? AND is_active = 1', [$r['name']]);
    $idList = implode(',', array_map(fn($x) => (int)$x['id'], $ids));
    echo "    - «{$r['name']}» × {$r['c']}  (id: {$idList})\n";
}
$dupNameInactive = m_fetch_all(
    $pdo,
    'SELECT name, COUNT(*) AS c FROM categories WHERE is_active = 0 GROUP BY name HAVING c > 1 ORDER BY c DESC, name ASC'
);
if ($dupNameInactive !== []) {
    echo "  نام تکراری (غیرفعال، فقط اطلاعی): " . count($dupNameInactive) . "\n";
    foreach ($dupNameInactive as $r) {
        $ids = m_fetch_all($pdo, 'SELECT id FROM categories WHERE name = ? AND is_active = 0', [$r['name']]);
        $idList = implode(',', array_map(fn($x) => (int)$x['id'], $ids));
        echo "    - «{$r['name']}» × {$r['c']}  (id: {$idList})\n";
    }
}

$missing = [];
foreach ($desired as $slug => $d) {
    if (!isset($after['bySlug'][$slug])) {
        $missing[] = $slug;
    }
}
echo "  اسلاگ‌های مقصد ناموجود در دیتابیس: " . count($missing) . "\n";
if ($missing !== []) {
    echo "    " . implode(', ', $missing) . "\n";
}

if ($outsideCount === 0 && $missing === [] && $dupSlug === [] && $dupNameActive === []) {
    echo "\n✓ معیار پذیرش برقرار است: هیچ آگهی فعالی خارج از برگ‌های درخت نیست و نام دسته‌های فعال یکتاست.\n";
} else {
    echo "\n✗ هنوز موارد باقی‌مانده هست؛ بخش‌های بالا را بررسی کن.\n";
}

// ─── خروجی CSV اختیاری ──────────────────────────────────────────────────────
if ($csvDir !== null) {
    if ($csvDir === '' || !is_dir($csvDir)) {
        echo "\nهشدار: مسیر CSV «{$csvDir}» معتبر نیست؛ صرف‌نظر شد.\n";
    } else {
        echo "\n[۵] خروجی CSV در: {$csvDir}\n";
        $fh = fopen($csvDir . '/category-migration-moves.csv', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['old_category_id', 'old_slug', 'new_leaf_slug', 'public_listings', 'total_listings']);
        foreach ($moves as $cid => $m) {
            fputcsv($fh, [$cid, $m['slug'], $m['target'], $m['public'], $m['total']]);
        }
        fclose($fh);
        echo "    - category-migration-moves.csv (" . count($moves) . " ردیف)\n";

        $fh = fopen($csvDir . '/category-migration-unmapped.csv', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['old_category_id', 'old_slug', 'public_listings', 'total_listings', 'reason']);
        foreach ($unmapped as $cid => $u) {
            fputcsv($fh, [$cid, $u['slug'], $u['public'], $u['total'], $u['reason']]);
        }
        fclose($fh);
        echo "    - category-migration-unmapped.csv (" . count($unmapped) . " ردیف)\n";
        echo "    ستون‌های old_slug/new_leaf_slug را در صورت نیاز به‌عنوان --map بده.\n";
    }
}

echo "\n" . m_line('=') . "\nپایان.\n" . m_line('=') . "\n";
