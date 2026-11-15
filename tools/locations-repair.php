<?php
/**
 * سواپین — ترمیم جدول مکان‌ها (locations)
 *
 * این ابزار ردیف‌های جدول «locations» را با منبع صحیح داده هم‌راستا می‌کند:
 *   ۱) شهرهایی که زیر استان اشتباه یا زیر «ایران» افتاده‌اند به استان درست منتقل می‌شوند
 *      (منبع درستی: iran_cities_by_province() در includes/store_orders.php).
 *   ۲) شهرهای تکراری (هم‌نام و در یک استان) در یک ردیف ادغام و محله‌هایشان منتقل می‌شوند.
 *   ۳) استان‌های عضو منطقه «شمال» (گیلان/مازندران/گلستان) زیر سرور «شمال» قرار می‌گیرند.
 *
 * پیش‌فرض «dry-run» است و هیچ چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 *
 * اجرا روی سرور (از ریشه پروژه):
 *   php tools/locations-repair.php
 *   php tools/locations-repair.php --apply
 *
 * عمداً «includes/config.php» لود نمی‌شود تا مایگریشن‌های خودکار CLI اجرا نشوند؛
 * فقط «.env» خوانده می‌شود و کلاس DB با PDO مستقیم شبیه‌سازی می‌شود.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان (CLI) قابل اجراست.');
}

require_once __DIR__ . '/../includes/load_env.php';
load_env(__DIR__ . '/../.env');

$apply = in_array('--apply', array_slice($argv, 1), true);

$dbHost = getenv('SWAPIN_DB_HOST');
$dbName = getenv('SWAPIN_DB_NAME');
$dbUser = getenv('SWAPIN_DB_USER');
$dbPass = getenv('SWAPIN_DB_PASS');
$dbHost = ($dbHost !== false && $dbHost !== '') ? $dbHost : 'localhost';
$dbName = ($dbName !== false && $dbName !== '') ? $dbName : 'kala_b_kala';
$dbUser = ($dbUser !== false && $dbUser !== '') ? $dbUser : 'root';
$dbPass = ($dbPass === false) ? '' : $dbPass;

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

// ── شبیه‌سازی کلاس DB (نام محلی CliDb تا تحلیل‌گر کد با DB اصلی قاطی نشود) ──────
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

if (!function_exists('db_has_table')) {
    function db_has_table(string $table): bool
    {
        $t = preg_replace('/[^a-z0-9_]/i', '', $table);
        try {
            $stmt = DB::query(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$t]
            );
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('listing_public_sql')) {
    function listing_public_sql(string $alias = 'l'): string
    {
        return "{$alias}.status = 'active' AND {$alias}.review_status = 'approved'";
    }
}

require_once __DIR__ . '/../includes/store_orders.php';
require_once __DIR__ . '/../includes/locations.php';

// ── کمکی‌ها ─────────────────────────────────────────────────────────────────
function r_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** نام محله‌های یک شهر از فهرست مرجع (data/iran_neighborhoods.json). */
function r_neighborhood_names(string $city): array
{
    static $cache = null;
    if ($cache === null) {
        $path = __DIR__ . '/../data/iran_neighborhoods.json';
        $decoded = is_readable($path) ? json_decode((string)file_get_contents($path), true) : [];
        $cache = is_array($decoded) ? $decoded : [];
    }
    $rows = $cache[$city] ?? [];
    $out = [];
    foreach ((array)$rows as $row) {
        if (is_array($row) && !empty($row['name'])) {
            $out[] = (string)$row['name'];
        }
    }
    return $out;
}

/** شمارش آگهی فعال به تفکیک شهر/محله (همان منطق swaapin_location_listing_counts). */
function r_listing_counts(PDO $pdo): array
{
    $byCity = [];
    $byNb   = [];
    foreach (r_all($pdo,
        "SELECT city AS c, COUNT(*) AS n FROM listings
         WHERE status='active' AND review_status='approved' AND listing_mode <> 'sell' AND city <> ''
         GROUP BY city") as $r) {
        $byCity[(string)$r['c']] = (int)$r['n'];
    }
    foreach (r_all($pdo,
        "SELECT city AS c, neighborhood AS nb, COUNT(*) AS n FROM listings
         WHERE status='active' AND review_status='approved' AND listing_mode <> 'sell' AND neighborhood <> ''
         GROUP BY city, neighborhood") as $r) {
        $byNb[(string)$r['c'] . "\x1f" . (string)$r['nb']] = (int)$r['n'];
    }
    return ['city' => $byCity, 'neighborhood' => $byNb];
}

/** تعداد آگهی فعال پوشش‌داده‌شده توسط یک مکان (همان منطق صفحه مکان). */
function r_loc_count(array $loc, array $counts): int
{
    $level = (string)($loc['level'] ?? '');
    if ($level === 'country') {
        return array_sum($counts['city']);
    }
    $filter = swaapin_location_filter($loc);
    if ($level === 'neighborhood') {
        $city = (string)($filter['cities'][0] ?? '');
        $nb   = (string)$filter['neighborhood'];
        return (int)($counts['neighborhood'][$city . "\x1f" . $nb] ?? 0);
    }
    $total = 0;
    foreach ($filter['cities'] as $cityName) {
        $total += (int)($counts['city'][$cityName] ?? 0);
    }
    return $total;
}

function r_stats(PDO $pdo, string $label): void
{
    echo "\n=== {$label} ===\n";
    foreach (r_all($pdo,
        "SELECT level, COUNT(*) AS c FROM locations
         GROUP BY level
         ORDER BY FIELD(level,'country','province','region','city','neighborhood')") as $r) {
        printf("  %-13s %d\n", $r['level'], (int)$r['c']);
    }
    $orphan = (int)$pdo->query(
        "SELECT COUNT(*) FROM locations c
         WHERE c.level='city' AND c.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM locations p WHERE p.id = c.parent_id AND p.level='province')"
    )->fetchColumn();
    $dups = r_all($pdo, "SELECT name, COUNT(*) AS c FROM locations WHERE level='city' AND is_active = 1 GROUP BY name HAVING c > 1");
    echo "  active cities without province parent : {$orphan}\n";
    echo "  duplicate active city names           : " . count($dups) . "\n";
}

function r_acceptance(PDO $pdo): void
{
    echo "\n=== acceptance / listing counts ===\n";
    $counts = r_listing_counts($pdo);
    $slugs  = ['iran', 'shomal', 'gilan-province', 'mazandaran-province', 'tehran-province', 'alborz-province', 'rasht', 'karaj', 'tehran'];
    foreach ($slugs as $slug) {
        $loc = DB::fetch('SELECT * FROM locations WHERE slug = ? LIMIT 1', [$slug]);
        if (!$loc) {
            printf("  %-22s NOT FOUND\n", $slug);
            continue;
        }
        printf("  %-22s level=%-11s listings=%d\n", $slug, (string)$loc['level'], r_loc_count($loc, $counts));
    }

    // تفکیک شهرهای گیلان و تعداد آگهی هرکدام
    $gil = DB::fetch("SELECT id FROM locations WHERE slug='gilan-province' LIMIT 1");
    if ($gil) {
        $cities = r_all($pdo, "SELECT name FROM locations WHERE parent_id = ? AND level='city' AND is_active = 1 ORDER BY name", [(int)$gil['id']]);
        echo "  -- gilan cities --\n";
        foreach ($cities as $c) {
            $n = (int)($counts['city'][(string)$c['name']] ?? 0);
            printf("     %-16s %d\n", (string)$c['name'], $n);
        }
    }
}

/** تشخیص وضعیت سطح محله: وجود ردیف‌ها، تطابق با آگهی‌ها، شهرهای هم‌نام محله. */
function r_neighborhood_diag(PDO $pdo): void
{
    echo "\n=== neighborhood diagnostics ===\n";

    $nbRows   = (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE level='neighborhood'")->fetchColumn();
    $nbActive = (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE level='neighborhood' AND is_active=1")->fetchColumn();
    $nbOrphan = (int)$pdo->query(
        "SELECT COUNT(*) FROM locations n
         WHERE n.level='neighborhood'
           AND NOT EXISTS (SELECT 1 FROM locations c WHERE c.id = n.parent_id AND c.level='city')"
    )->fetchColumn();
    printf("  neighborhood rows: %d (active %d) | without city parent: %d\n", $nbRows, $nbActive, $nbOrphan);

    // آگهی‌هایی که (شهر, محله)شان در locations ردیف محله ندارد
    $rows = r_all($pdo,
        "SELECT l.city, l.neighborhood, COUNT(*) AS c
         FROM listings l
         WHERE l.status='active' AND l.review_status='approved' AND l.neighborhood <> ''
           AND NOT EXISTS (
               SELECT 1 FROM locations n
               JOIN locations p ON p.id = n.parent_id AND p.level='city'
               WHERE n.level='neighborhood' AND n.name = l.neighborhood AND p.name = l.city
           )
         GROUP BY l.city, l.neighborhood
         ORDER BY c DESC LIMIT 25");
    echo "  listings with (city,neighborhood) absent from locations: " . count($rows) . " pairs\n";
    foreach ($rows as $r) {
        printf("     %-12s / %-18s %d\n", (string)$r['city'], (string)$r['neighborhood'], (int)$r['c']);
    }

    // ردیف‌های سطح شهر که نامشان با یک محله در شهر دیگر یکسان است (احتمال رکورد اشتباه)
    $nbByName = [];
    foreach (r_all($pdo,
        "SELECT n.name AS nb, p.name AS city FROM locations n
         LEFT JOIN locations p ON p.id = n.parent_id
         WHERE n.level='neighborhood'") as $r) {
        $nbByName[(string)$r['nb']][] = (string)$r['city'];
    }
    $flags = [];
    foreach (r_all($pdo, "SELECT id, name, parent_id, is_active FROM locations WHERE level='city'") as $c) {
        $nm = (string)$c['name'];
        if (!isset($nbByName[$nm])) {
            continue;
        }
        $pn = (string)(DB::fetch('SELECT name FROM locations WHERE id = ?', [(int)$c['parent_id']])['name'] ?? '');
        if ($pn === '' || !in_array($pn, $nbByName[$nm], true)) {
            $cnt = (int)$pdo->query(
                "SELECT COUNT(*) FROM listings WHERE status='active' AND review_status='approved'
                   AND city = " . $pdo->quote($nm)
            )->fetchColumn();
            $flags[] = ['n' => $nm, 'i' => (int)$c['id'], 'p' => $pn, 'a' => (int)$c['is_active'], 'l' => $cnt];
        }
    }
    echo "  city rows whose name is a neighborhood of another city: " . count($flags) . "\n";
    foreach ($flags as $f) {
        printf("     #%d %-14s parent=%-14s active=%d listings=%d\n", $f['i'], $f['n'], $f['p'] !== '' ? $f['p'] : '-', $f['a'], $f['l']);
    }

    // آگهی‌هایی که مقدار city آن‌ها در واقع نام یک محله است (نه شهر) — دلیل اصلی نبودن در /{city}/{cat}
    $badCity = r_all($pdo,
        "SELECT l.city, COUNT(*) AS c
         FROM listings l
         WHERE l.status='active' AND l.review_status='approved'
           AND EXISTS (SELECT 1 FROM locations n JOIN locations p ON p.id = n.parent_id AND p.level='city'
                       WHERE n.level='neighborhood' AND n.name = l.city)
           AND NOT EXISTS (SELECT 1 FROM locations c WHERE c.level='city' AND c.name = l.city)
         GROUP BY l.city
         ORDER BY c DESC LIMIT 25");
    $badCityTotal = 0;
    foreach ($badCity as $r) {
        $badCityTotal += (int)$r['c'];
    }
    echo "  listings whose city value is actually a neighborhood: {$badCityTotal}\n";
    foreach ($badCity as $r) {
        printf("     city=%-18s %d\n", (string)$r['city'], (int)$r['c']);
    }
}

// ── ساخت نقشه مطلوب (نام شهر → نام استان) ─────────────────────────────────────
$desired = [];
$ambiguous = [];
foreach (iran_provinces() as $prov) {
    $prov = (string)$prov;
    foreach (array_unique(iran_cities_by_province($prov)) as $city) {
        $city = trim((string)$city);
        if ($city === '') {
            continue;
        }
        if (isset($desired[$city]) && $desired[$city] !== $prov) {
            $ambiguous[$city] = true;
        } else {
            $desired[$city] = $prov;
        }
    }
}
foreach (array_keys($ambiguous) as $c) {
    unset($desired[$c]);
}

// ── خواندن وضعیت فعلی ────────────────────────────────────────────────────────
$locById   = [];
$provinceId = [];
foreach (r_all($pdo, "SELECT id, parent_id, level, name FROM locations") as $r) {
    $locById[(int)$r['id']] = $r;
    if ($r['level'] === 'province') {
        $provinceId[(string)$r['name']] = (int)$r['id'];
    }
}

$cities = r_all($pdo, "SELECT id, parent_id, name FROM locations WHERE level='city'");

$moves = [];
$byName = [];
foreach ($cities as $c) {
    $name = (string)$c['name'];
    $byName[$name][] = $c;

    $exp = $desired[$name] ?? null;
    if ($exp === null) {
        continue;
    }
    $expId = $provinceId[$exp] ?? null;
    if ($expId === null) {
        continue;
    }
    if ((int)$c['parent_id'] === $expId) {
        continue;
    }
    $parent = $locById[(int)$c['parent_id']] ?? null;
    $fromLabel = $parent ? ((string)$parent['level'] . ':' . (string)$parent['name']) : '-';
    $moves[] = ['id' => (int)$c['id'], 'name' => $name, 'from' => $fromLabel, 'to' => $exp, 'to_id' => $expId];
}

$dupes = [];
foreach ($byName as $name => $rows) {
    if (!isset($desired[$name]) || count($rows) < 2) {
        continue;
    }
    $ids = array_map(static fn(array $x): int => (int)$x['id'], $rows);
    sort($ids);
    $dupes[$name] = $ids;
}

// سطح region: استان‌های عضو «شمال» زیر سرور شمال
$regionIdBySlug = [];
foreach (r_all($pdo, "SELECT id, slug FROM locations WHERE level='region'") as $r) {
    $regionIdBySlug[(string)$r['slug']] = (int)$r['id'];
}
$regionMoves = [];
foreach (swaapin_location_regions() as $region) {
    $rid = $regionIdBySlug[(string)$region['slug']] ?? 0;
    if ($rid <= 0) {
        continue;
    }
    foreach ($region['provinces'] as $pName) {
        $pid = $provinceId[(string)$pName] ?? 0;
        if ($pid <= 0 || $pid === $rid) {
            continue;
        }
        $curParent = (int)($locById[$pid]['parent_id'] ?? 0);
        if ($curParent === $rid) {
            continue;
        }
        $regionMoves[] = ['id' => $pid, 'name' => (string)$pName, 'to_id' => $rid];
    }
}

// ── نام‌های غلط قدیمی (کپی اشتباه seed قدیمی) ────────────────────────────────
// اگر ردیفِ نام‌درست موجود باشد ادغام می‌شود، وگرنه ردیف غلط تغییر نام می‌دهد.
$legacyRenames = [
    'چابکسار'   => 'چابکسر',
    'مساله'     => 'ماسال',
    'بندرانزلی' => 'بندر انزلی',
];

$mergeInto  = [];
$renameRows = [];
foreach ($legacyRenames as $badName => $goodName) {
    if (!isset($byName[$badName])) {
        continue;
    }
    $goodRow = $byName[$goodName][0] ?? null;
    foreach ($byName[$badName] as $badRow) {
        if ($goodRow !== null && (int)$goodRow['id'] !== (int)$badRow['id']) {
            $mergeInto[] = [
                'from'     => (int)$badRow['id'],
                'fromName' => (string)$badName,
                'to'       => (int)$goodRow['id'],
                'toName'   => (string)$goodName,
            ];
            continue;
        }
        $renameRows[] = [
            'id'       => (int)$badRow['id'],
            'fromName' => (string)$badName,
            'toName'   => (string)$goodName,
            'slug'     => swaapin_location_slug((string)$goodName, 'city'),
        ];
    }
}

// ── شهرهای بی‌والد (والدشان استان نیست) ───────────────────────────────────────
$moveIds = [];
foreach ($moves as $m) {
    $moveIds[$m['id']] = true;
}
$counts = r_listing_counts($pdo);

$orphanDeact = [];
$orphanKeep  = [];
foreach ($cities as $c) {
    $cid = (int)$c['id'];
    if (isset($moveIds[$cid])) {
        continue; // در برنامه انتقال به استان درست است
    }
    $parentLevel = (string)($locById[(int)$c['parent_id']]['level'] ?? '');
    if ($parentLevel === 'province') {
        continue;
    }
    $n = (int)($counts['city'][(string)$c['name']] ?? 0);
    if ($n > 0) {
        $orphanKeep[] = ['name' => (string)$c['name'], 'n' => $n];
    } else {
        $orphanDeact[] = ['id' => $cid, 'name' => (string)$c['name']];
    }
}

// ── گزارش برنامه تغییرات ─────────────────────────────────────────────────────
r_stats($pdo, 'BEFORE');

echo "\n=== planned re-parents (city -> province) : " . count($moves) . " ===\n";
foreach ($moves as $m) {
    printf("  #%d %-16s %s  ->  %s\n", $m['id'], $m['name'], $m['from'], $m['to']);
}

echo "\n=== planned duplicate merges : " . count($dupes) . " ===\n";
foreach ($dupes as $name => $ids) {
    printf("  %-16s keep #%d  remove %s\n", $name, $ids[0], implode(', ', array_map(static fn($i) => '#' . $i, array_slice($ids, 1))));
}

echo "\n=== planned region re-parents (province -> shomal) : " . count($regionMoves) . " ===\n";
foreach ($regionMoves as $m) {
    printf("  #%d %s -> region#%d\n", $m['id'], $m['name'], $m['to_id']);
}

echo "\n=== planned legacy renames / merges : " . (count($mergeInto) + count($renameRows)) . " ===\n";
foreach ($mergeInto as $m) {
    printf("  merge  #%d %-12s -> #%d %s\n", $m['from'], $m['fromName'], $m['to'], $m['toName']);
}
foreach ($renameRows as $m) {
    printf("  rename #%d %-12s -> %s (slug: %s)\n", $m['id'], $m['fromName'], $m['toName'], $m['slug']);
}

echo "\n=== planned orphan city deactivations : " . count($orphanDeact) . " ===\n";
foreach (array_slice($orphanDeact, 0, 25) as $o) {
    printf("  #%d %s\n", $o['id'], $o['name']);
}
if (count($orphanDeact) > 25) {
    printf("  ... و %d مورد دیگر\n", count($orphanDeact) - 25);
}

if ($orphanKeep) {
    echo "\n=== orphans WITH listings (left active — need manual mapping) : " . count($orphanKeep) . " ===\n";
    foreach ($orphanKeep as $o) {
        printf("  %-16s %d\n", $o['name'], $o['n']);
    }
}

// ── تشخیص سطح محله (تسک P2: چرا آگهی‌های محله در /{city}/{category} دیده نمی‌شوند) ──
r_neighborhood_diag($pdo);

if (!$apply) {
    echo "\n[dry-run] هیچ تغییری اعمال نشد. برای اعمال: php tools/locations-repair.php --apply\n";
    exit(0);
}

// ── اعمال ────────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    $upParent = $pdo->prepare('UPDATE locations SET parent_id = ? WHERE id = ?');
    $upChild  = $pdo->prepare("UPDATE locations SET parent_id = ? WHERE parent_id = ? AND level = 'neighborhood'");
    $delRow   = $pdo->prepare('DELETE FROM locations WHERE id = ?');

    foreach ($moves as $m) {
        $upParent->execute([$m['to_id'], $m['id']]);
    }
    foreach ($dupes as $ids) {
        $keep = array_shift($ids);
        foreach ($ids as $dup) {
            $upChild->execute([$keep, $dup]);
            $delRow->execute([$dup]);
        }
    }
    foreach ($regionMoves as $m) {
        $upParent->execute([$m['to_id'], $m['id']]);
    }

    // نام‌های غلط: تغییر نام (با حفظ اسلاگ در صورت اشغال بودن مقصد)
    $upName   = $pdo->prepare('UPDATE locations SET name = ?, slug = ? WHERE id = ?');
    $upNameOnly = $pdo->prepare('UPDATE locations SET name = ? WHERE id = ?');
    foreach ($renameRows as $m) {
        $slug  = (string)$m['slug'];
        $taken = $slug !== '' ? DB::fetch('SELECT id FROM locations WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $m['id']]) : null;
        if ($slug === '' || $taken) {
            $upNameOnly->execute([$m['toName'], $m['id']]);
        } else {
            $upName->execute([$m['toName'], $slug, $m['id']]);
        }
    }

    // ادغام کپی‌های اشتباه در ردیف نام‌درست
    foreach ($mergeInto as $m) {
        $upChild->execute([$m['to'], $m['from']]);
        $delRow->execute([$m['from']]);
    }

    // غیرفعال‌سازی شهرهای بی‌والد (بدون آگهی) و محله‌هایشان
    $upActive      = $pdo->prepare('UPDATE locations SET is_active = 0 WHERE id = ?');
    $upChildActive = $pdo->prepare("UPDATE locations SET is_active = 0 WHERE parent_id = ? AND level = 'neighborhood'");
    foreach ($orphanDeact as $o) {
        $upChildActive->execute([$o['id']]);
        $upActive->execute([$o['id']]);
    }

    $pdo->commit();
    echo "\n[applied] تغییرات اعمال شد.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\n[error] اعمال ناموفق بود: " . $e->getMessage() . "\n");
    exit(1);
}

r_stats($pdo, 'AFTER');
r_acceptance($pdo);
