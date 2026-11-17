<?php
/**
 * سواپین — بازنویسی ساختاریافتهٔ برند/مدل آگهی‌ها روی کاتالوگ (catalog backfill)
 *
 * آگهی‌های قدیمی برند/مدل را به‌صورت «متن آزاد» در listing_attrs نگه داشته‌اند، پس
 * فیلتر ساختاریافتهٔ /{location}/{category}/{brand}/{model} آن‌ها را پیدا نمی‌کند.
 * این ابزار با تطبیق نرمال‌شده (ارقام فارسی/عربی، نیم‌فاصله، ی/ک عربی) برند و مدلِ
 * هر آگهی را به گرهٔ متناظر در catalog_nodes وصل می‌کند و ستون‌های
 * brand_id/model_id آگهی را پر می‌کند.
 *
 * پیش‌نیاز: اول «php tools/catalog-seed.php --apply» اجرا شود تا گره‌های پایه ساخته شوند.
 * این ابزار هیچ گرهٔ جدیدی نمی‌سازد (طبق تصمیم پروژه: فقط کیوردهای تأییدشده).
 *
 * پیش‌فرض «dry-run» است و چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 *
 * اجرا روی سرور (از ریشه پروژه):
 *   php tools/catalog-backfill.php
 *   php tools/catalog-backfill.php --apply
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

/** نرمال‌سازی نام برای تطبیق مقاوم: ارقام فارسی/عربی → ASCII، حذف نیم‌فاصله/فاصله، یکسان‌سازی ی/ک. */
function bf_norm(string $s): string {
    $s = str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
        $s
    );
    $s = str_replace(['ي', 'ك', 'ة', 'ۀ', 'أ', 'إ', 'آ', 'ٱ', 'ئ'], ['ی', 'ک', 'ه', 'ه', 'ا', 'ا', 'ا', 'ا', 'ی'], $s);
    $s = preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}\x{00A0}]+/u', '', $s);
    return trim(mb_strtolower((string) $s, 'UTF-8'));
}

// ── بررسی ستون‌های لازم ───────────────────────────────────────────────────────
$colRows = $pdo->query('SHOW COLUMNS FROM `listings`')->fetchAll();
$cols = array_map(static fn(array $r): string => (string) ($r['Field'] ?? ''), $colRows);
foreach (['listing_attrs', 'brand_id', 'model_id'] as $need) {
    if (!in_array($need, $cols, true)) {
        fwrite(STDERR, "ستون «{$need}» در جدول listings نیست. یک‌بار یک صفحهٔ سایت را باز کن تا مایگریشن‌ها اجرا شوند، بعد دوباره تلاش کن.\n");
        exit(1);
    }
}

$hasTrim = in_array('trim_id', $cols, true);

if (!$pdo->query('SHOW TABLES LIKE "catalog_nodes"')->fetchColumn()) {
    fwrite(STDERR, "جدول catalog_nodes وجود ندارد. اول «php tools/catalog-seed.php --apply» را اجرا کن.\n");
    exit(1);
}

$catIds = $pdo->query('SELECT DISTINCT category_id FROM catalog_nodes')->fetchAll(PDO::FETCH_COLUMN);
if (!$catIds) {
    fwrite(STDERR, "catalog_nodes خالی است. اول «php tools/catalog-seed.php --apply» را اجرا کن.\n");
    exit(1);
}

echo $apply ? "حالت اعمال (--apply)\n" : "حالت آزمایشی (dry-run) — برای اعمال --apply بزنید\n";

$grand = ['total' => 0, 'no_brand' => 0, 'brand_ok' => 0, 'model_ok' => 0];
$unmatchedBrand = [];
$unmatchedModel = [];
$upd = $pdo->prepare('UPDATE listings SET brand_id = ?, model_id = ? WHERE id = ?');

foreach ($catIds as $catId) {
    $catId = (int) $catId;
    $catName = (string) ($pdo->query('SELECT name FROM categories WHERE id = ' . $catId)->fetchColumn() ?: $catId);

    // گره‌های برند/مدل این دسته
    $brandByKey  = [];   // norm(name) => id
    $brandBySlug = [];   // slug(lower) => id
    $brandName   = [];   // id => name
    $bq = $pdo->prepare("SELECT id, name, slug FROM catalog_nodes WHERE category_id = ? AND level = 'brand' AND parent_id = 0");
    $bq->execute([$catId]);
    foreach ($bq->fetchAll() as $r) {
        $brandByKey[bf_norm((string) $r['name'])] = (int) $r['id'];
        $brandBySlug[strtolower((string) $r['slug'])] = (int) $r['id'];
        $brandName[(int) $r['id']] = (string) $r['name'];
    }

    $modelByKey  = [];   // brandId => [norm(name) => id]
    $modelBySlug = [];   // brandId => [slug(lower) => id]
    $modelsOf    = [];   // brandId => [ [key, id], ... ] مرتب‌شده بر اساس بلندی کلید (برای تطبیق پیشوندی)
    $mq = $pdo->prepare("SELECT id, parent_id, name, slug FROM catalog_nodes WHERE category_id = ? AND level = 'model'");
    $mq->execute([$catId]);
    foreach ($mq->fetchAll() as $r) {
        $bid = (int) $r['parent_id'];
        $key = bf_norm((string) $r['name']);
        $modelByKey[$bid][$key] = (int) $r['id'];
        $modelBySlug[$bid][strtolower((string) $r['slug'])] = (int) $r['id'];
        $modelsOf[$bid][] = [$key, (int) $r['id']];
    }
    foreach ($modelsOf as $bid => $list) {
        usort($list, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
        $modelsOf[$bid] = $list;
    }

    if (!$brandByKey && !$brandBySlug) {
        continue;
    }

    $lq = $pdo->prepare('SELECT id, listing_attrs FROM listings WHERE category_id = ? AND listing_attrs IS NOT NULL');
    $lq->execute([$catId]);
    $rows = $lq->fetchAll();
    if (!$rows) {
        continue;
    }

    $stat = ['total' => 0, 'no_brand' => 0, 'brand_ok' => 0, 'model_ok' => 0];

    foreach ($rows as $r) {
        $stat['total']++;
        $attrs = json_decode((string) $r['listing_attrs'], true);
        if (!is_array($attrs)) {
            $stat['no_brand']++;
            continue;
        }
        $brandText = trim((string) ($attrs['brand'] ?? ''));
        $modelText = trim((string) ($attrs['model'] ?? ''));
        if ($brandText === '') {
            $stat['no_brand']++;
            continue;
        }

        $brandId = $brandByKey[bf_norm($brandText)] ?? $brandBySlug[strtolower($brandText)] ?? 0;
        if ($brandId <= 0) {
            $unmatchedBrand[$brandText] = ($unmatchedBrand[$brandText] ?? 0) + 1;
            continue;
        }
        $stat['brand_ok']++;

        $modelId = 0;
        if ($modelText !== '') {
            $mk = bf_norm($modelText);
            $modelId = $modelByKey[$brandId][$mk] ?? $modelBySlug[$brandId][strtolower($modelText)] ?? 0;
            if ($modelId <= 0) {
                // تطبیق پیشوندی: «206 تیپ 2» → مدل «206»
                foreach (($modelsOf[$brandId] ?? []) as [$key, $id]) {
                    if ($key !== '' && strlen($key) >= 2 && str_starts_with($mk, $key)) {
                        $modelId = $id;
                        break;
                    }
                }
            }
            if ($modelId > 0) {
                $stat['model_ok']++;
            } else {
                $label = $brandName[$brandId] . ' ' . $modelText;
                $unmatchedModel[$label] = ($unmatchedModel[$label] ?? 0) + 1;
            }
        }

        if ($apply) {
            $upd->execute([$brandId, $modelId > 0 ? $modelId : null, (int) $r['id']]);
        }
    }

    printf("دسته %-16s (id=%d): کل=%d  بدون‌برند=%d  برند‌متصل=%d  مدل‌متصل=%d\n",
        $catName, $catId, $stat['total'], $stat['no_brand'], $stat['brand_ok'], $stat['model_ok']);

    foreach ($stat as $k => $v) {
        $grand[$k] += $v;
    }
}

echo "\n=== جمع کل ===\n";
printf("  کل آگهی‌های بررسی‌شده : %d\n", $grand['total']);
printf("  بدون برند            : %d\n", $grand['no_brand']);
printf("  برند متصل شد        : %d\n", $grand['brand_ok']);
printf("  مدل متصل شد         : %d\n", $grand['model_ok']);

if ($unmatchedBrand) {
    arsort($unmatchedBrand);
    echo "\n=== برندهای بدون گرهٔ کاتالوگ (نیازمند افزودن دستی) : " . count($unmatchedBrand) . " ===\n";
    foreach (array_slice($unmatchedBrand, 0, 30, true) as $name => $c) {
        printf("  %-20s %d\n", $name, $c);
    }
}
if ($unmatchedModel) {
    arsort($unmatchedModel);
    echo "\n=== مدل‌های بدون گرهٔ کاتالوگ (نیازمند افزودن دستی) : " . count($unmatchedModel) . " ===\n";
    foreach (array_slice($unmatchedModel, 0, 30, true) as $name => $c) {
        printf("  %-28s %d\n", $name, $c);
    }
}

// ── گزارش تأیید (توزیع آگهی به تفکیک برند/مدل) ────────────────────────────────
echo "\n=== تأیید: توزیع آگهی‌های متصل‌شده به تفکیک برند/مدل ===\n";
$verify = $pdo->query(
    "SELECT b.name AS brand, COALESCE(m.name, '-') AS model, COUNT(*) AS c
     FROM listings l
     JOIN catalog_nodes b ON b.id = l.brand_id
     LEFT JOIN catalog_nodes m ON m.id = l.model_id
     GROUP BY b.name, m.name
     ORDER BY c DESC, b.name
     LIMIT 20"
)->fetchAll();
if (!$verify) {
    echo "  (هیچ آگهی متصلی وجود ندارد)\n";
}
foreach ($verify as $row) {
    printf("  %-18s %-18s %d\n", (string) $row['brand'], (string) $row['model'], (int) $row['c']);
}

if (!$apply) {
    echo "\n[dry-run] هیچ تغییری اعمال نشد. برای اعمال: php tools/catalog-backfill.php --apply\n";
}
