<?php
/**
 * سواپین — بازنویسی ساختاریافتهٔ برند/مدل آگهی‌ها روی کاتالوگ (catalog backfill)
 *
 * آگهی‌های قدیمی برند/مدل را به‌صورت «متن آزاد» نگه داشته‌اند، پس فیلتر
 * ساختاریافتهٔ /{location}/{category}/{brand}/{model} آن‌ها را پیدا نمی‌کند.
 * این ابزار برند و مدلِ هر آگهی را به گرهٔ متناظر در catalog_nodes وصل می‌کند و
 * ستون‌های brand_id/model_id آگهی را پر می‌کند. دو منبع به‌ترتیب اولویت:
 *   ۱) فیلدهای برند/مدل در listing_attrs
 *   ۲) استخراج از عنوان آگهی (برای داده‌های قدیمی که listing_attrs ندارند)
 *
 * نکتهٔ مهم: گره‌های کاتالوگ روی دستهٔ والد ثبت می‌شوند، اما آگهی‌ها معمولاً روی
 * برگ‌های زیرمجموعه می‌نشینند؛ بنابراین دامنهٔ آگهی‌ها کل زیردرخت هر دستهٔ کاتالوگ است.
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

/** همهٔ شناسه‌های زیردرخت یک دسته (شامل خودش). */
function bf_subtree(int $root, array $childrenMap): array {
    $ids   = [$root];
    $queue = [$root];
    $safety = 2000;
    while ($queue && $safety-- > 0) {
        $pid = (int) array_shift($queue);
        foreach ($childrenMap[$pid] ?? [] as $cid) {
            if (!in_array($cid, $ids, true)) {
                $ids[] = $cid;
                $queue[] = $cid;
            }
        }
    }
    return $ids;
}

/**
 * یافتن یک نام در متن نرمال‌شده: زودترین جایگاه اولویت دارد، در تساوی طولانی‌ترین نام.
 *
 * @param array<int, array{0:string,1:mixed}> $candidates فهرست [نام نرمال‌شده, مقدار]
 * @return array{0:mixed,1:?int} [مقدار برنده یا null, جایگاه]
 */
function bf_find_in_text(string $haystack, array $candidates): array {
    $bestPos = null;
    $bestVal = null;
    $bestLen = 0;
    foreach ($candidates as [$needle, $val]) {
        $needle = (string) $needle;
        if ($needle === '' || mb_strlen($needle) < 2) {
            continue;
        }
        $pos = mb_strpos($haystack, $needle);
        if ($pos === false) {
            continue;
        }
        $len = mb_strlen($needle);
        if ($bestPos === null || $pos < $bestPos || ($pos === $bestPos && $len > $bestLen)) {
            $bestPos = $pos;
            $bestVal = $val;
            $bestLen = $len;
        }
    }
    return [$bestVal, $bestPos];
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

echo "\n=== ستون‌های جدول listings ===\n  " . implode(', ', $cols) . "\n\n";

if (!$pdo->query('SHOW TABLES LIKE "catalog_nodes"')->fetchColumn()) {
    fwrite(STDERR, "جدول catalog_nodes وجود ندارد. اول «php tools/catalog-seed.php --apply» را اجرا کن.\n");
    exit(1);
}

$catIds = $pdo->query('SELECT DISTINCT category_id FROM catalog_nodes')->fetchAll(PDO::FETCH_COLUMN);
if (!$catIds) {
    fwrite(STDERR, "catalog_nodes خالی است. اول «php tools/catalog-seed.php --apply» را اجرا کن.\n");
    exit(1);
}

// نقشهٔ درخت دسته‌ها برای محاسبهٔ زیردرخت
$catInfo     = [];
$childrenMap = [];
foreach ($pdo->query('SELECT id, slug, name, parent_id FROM categories')->fetchAll() as $r) {
    $id = (int) $r['id'];
    $catInfo[$id] = [
        'slug'      => (string) $r['slug'],
        'name'      => (string) $r['name'],
        'parent_id' => (int) $r['parent_id'],
    ];
    $childrenMap[(int) $r['parent_id']][] = $id;
}

echo $apply ? "حالت اعمال (--apply)\n" : "حالت آزمایشی (dry-run) — برای اعمال --apply بزنید\n";

$grand = ['total' => 0, 'no_brand' => 0, 'brand_ok' => 0, 'model_ok' => 0];
$unmatchedBrand = [];
$unmatchedModel = [];
$upd = $pdo->prepare('UPDATE listings SET brand_id = ?, model_id = ? WHERE id = ?');
$noDataCats = [];

foreach ($catIds as $catId) {
    $catId   = (int) $catId;
    $catName = $catInfo[$catId]['name'] ?? (string) $catId;
    $scope   = bf_subtree($catId, $childrenMap);
    $ph      = implode(',', array_fill(0, count($scope), '?'));

    // گره‌های برند/مدل این دسته
    $brandByKey  = [];   // norm(name) => id
    $brandBySlug = [];   // slug(lower) => id
    $brandName   = [];   // id => name
    $brandCand   = [];   // [ [norm(name), id] ] برای تطبیق عنوان
    $bq = $pdo->prepare("SELECT id, name, slug FROM catalog_nodes WHERE category_id = ? AND level = 'brand' AND parent_id = 0");
    $bq->execute([$catId]);
    foreach ($bq->fetchAll() as $r) {
        $brandByKey[bf_norm((string) $r['name'])] = (int) $r['id'];
        $brandBySlug[strtolower((string) $r['slug'])] = (int) $r['id'];
        $brandName[(int) $r['id']] = (string) $r['name'];
        $brandCand[] = [bf_norm((string) $r['name']), (int) $r['id']];
    }

    $modelByKey    = [];   // brandId => [norm(name) => id]
    $modelBySlug   = [];   // brandId => [slug(lower) => id]
    $modelsOf      = [];   // brandId => [ [key, id], ... ] مرتب‌شده بر اساس بلندی کلید (برای تطبیق پیشوندی)
    $modelCand     = [];   // brandId => [ [norm(name), id] ] برای تطبیق عنوان
    $allModelCand  = [];   // [ [norm(name), [modelId, brandId]] ] برای وقتی نام برند در عنوان نیست
    $mq = $pdo->prepare("SELECT id, parent_id, name, slug FROM catalog_nodes WHERE category_id = ? AND level = 'model'");
    $mq->execute([$catId]);
    foreach ($mq->fetchAll() as $r) {
        $bid = (int) $r['parent_id'];
        $key = bf_norm((string) $r['name']);
        $modelByKey[$bid][$key] = (int) $r['id'];
        $modelBySlug[$bid][strtolower((string) $r['slug'])] = (int) $r['id'];
        $modelsOf[$bid][]  = [$key, (int) $r['id']];
        $modelCand[$bid][] = [$key, (int) $r['id']];
        $allModelCand[]    = [$key, [(int) $r['id'], $bid]];
    }
    foreach ($modelsOf as $bid => $list) {
        usort($list, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
        $modelsOf[$bid] = $list;
    }

    if (!$brandByKey && !$brandBySlug) {
        continue;
    }

    $lq = $pdo->prepare(
        "SELECT id, title, listing_attrs FROM listings WHERE category_id IN ({$ph})"
    );
    $lq->execute($scope);
    $rows = $lq->fetchAll();
    if (!$rows) {
        // چرا صفر؟ کل آگهی‌های زیردرخت و تعداد آگهی‌های دارای attrs را گزارش کن.
        $cq = $pdo->prepare("SELECT COUNT(*) AS t, SUM(listing_attrs IS NOT NULL) AS a FROM listings WHERE category_id IN ({$ph})");
        $cq->execute($scope);
        $c = $cq->fetch() ?: ['t' => 0, 'a' => 0];
        $noDataCats[] = sprintf('%s (id=%d) زیردرخت=%d برگ درخت، کل آگهی=%d، دارای attrs=%d',
            $catName, $catId, count($scope), (int) $c['t'], (int) $c['a']);

        // نمونهٔ آگهی‌ها برای تشخیص این‌که متن برند/مدل کجا ذخیره شده است.
        $sq = $pdo->prepare(
            "SELECT id, title, listing_attrs FROM listings WHERE category_id IN ({$ph}) ORDER BY id DESC LIMIT 5"
        );
        $sq->execute($scope);
        foreach ($sq->fetchAll() as $s) {
            printf("      نمونه id=%-7d title=%-50s attrs=%s\n",
                (int) $s['id'],
                mb_substr(trim((string) $s['title']), 0, 50),
                $s['listing_attrs'] === null ? 'NULL' : mb_substr((string) $s['listing_attrs'], 0, 60));
        }
        continue;
    }

    $stat = ['total' => 0, 'no_brand' => 0, 'brand_ok' => 0, 'model_ok' => 0, 'via_title' => 0];

    foreach ($rows as $r) {
        $stat['total']++;

        $attrs     = json_decode((string) ($r['listing_attrs'] ?? ''), true);
        $brandText = is_array($attrs) ? trim((string) ($attrs['brand'] ?? '')) : '';
        $modelText = is_array($attrs) ? trim((string) ($attrs['model'] ?? '')) : '';

        $brandId = 0;
        $modelId = 0;

        // ۱) منبع ساختاریافته: فیلدهای برند/مدل متن‌آزاد در listing_attrs
        if ($brandText !== '') {
            $brandId = $brandByKey[bf_norm($brandText)] ?? $brandBySlug[strtolower($brandText)] ?? 0;
            if ($brandId > 0 && $modelText !== '') {
                $mk = bf_norm($modelText);
                $modelId = $modelByKey[$brandId][$mk] ?? $modelBySlug[$brandId][strtolower($modelText)] ?? 0;
                if ($modelId <= 0) {
                    // تطبیق پیشوندی: «206 تیپ 2» → مدل «206»
                    foreach (($modelsOf[$brandId] ?? []) as [$key, $id]) {
                        if ($key !== '' && mb_strlen($key) >= 2 && str_starts_with($mk, $key)) {
                            $modelId = $id;
                            break;
                        }
                    }
                }
            }
        }

        // ۲) fallback: استخراج برند/مدل از عنوان آگهی (داده‌های قدیمی که attrs ندارند)
        if ($brandId <= 0) {
            $nt = bf_norm((string) ($r['title'] ?? ''));
            if ($nt !== '') {
                [$tBrand, ] = bf_find_in_text($nt, $brandCand);
                $tModel = 0;
                $tBrand = (int) ($tBrand ?? 0);
                if ($tBrand > 0) {
                    [$tModel, ] = bf_find_in_text($nt, $modelCand[$tBrand] ?? []);
                    $tModel = (int) ($tModel ?? 0);
                } else {
                    // نام برند در عنوان نیست (مثل «پراید 131») → از خود مدل، برند را استخراج کن
                    [$hit, ] = bf_find_in_text($nt, $allModelCand);
                    if (is_array($hit)) {
                        $tModel = (int) $hit[0];
                        $tBrand = (int) $hit[1];
                    }
                }
                if ($tBrand > 0) {
                    $brandId = $tBrand;
                    $modelId = $tModel;
                    $stat['via_title']++;
                }
            }
        }

        if ($brandId <= 0) {
            $stat['no_brand']++;
            $label = $brandText !== '' ? $brandText : mb_substr(trim((string) ($r['title'] ?? '')), 0, 40);
            if ($label !== '') {
                $unmatchedBrand[$label] = ($unmatchedBrand[$label] ?? 0) + 1;
            }
            continue;
        }
        $stat['brand_ok']++;

        if ($modelId > 0) {
            $stat['model_ok']++;
        } elseif ($modelText !== '') {
            $label = $brandName[$brandId] . ' ' . $modelText;
            $unmatchedModel[$label] = ($unmatchedModel[$label] ?? 0) + 1;
        }

        if ($apply) {
            $upd->execute([$brandId, $modelId > 0 ? $modelId : null, (int) $r['id']]);
        }
    }

    printf("دسته %-16s (id=%d) دامنه=%d: کل=%d  ناموفق=%d  برندمتصل=%d (ازعنوان=%d)  مدلمتصل=%d\n",
        $catName, $catId, count($scope), $stat['total'], $stat['no_brand'],
        $stat['brand_ok'], $stat['via_title'], $stat['model_ok']);

    foreach ($stat as $k => $v) {
        $grand[$k] = ($grand[$k] ?? 0) + $v;
    }
}

echo "\n=== جمع کل ===\n";
printf("  کل آگهی‌های بررسی‌شده : %d\n", $grand['total']);
printf("  ناموفق (بدون برند)   : %d\n", $grand['no_brand'] ?? 0);
printf("  برند متصل شد        : %d (از این تعداد، %d از عنوان استخراج شد)\n", $grand['brand_ok'] ?? 0, $grand['via_title'] ?? 0);
printf("  مدل متصل شد         : %d\n", $grand['model_ok'] ?? 0);

if ($noDataCats) {
    echo "\n=== دسته‌های کاتالوگ بدون آگهی : " . count($noDataCats) . " ===\n";
    foreach ($noDataCats as $line) {
        echo '  - ' . $line . "\n";
    }
}

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

// ── گزارش تشخیصی: آگهی‌ها در کدام دسته‌ها هستند؟ ───────────────────────────────
echo "\n=== تشخیص: توزیع آگهی در دسته‌ها (۱۵ مورد اول) ===\n";
try {
    $dist = $pdo->query(
        "SELECT l.category_id, COUNT(*) AS c, SUM(l.listing_attrs IS NOT NULL) AS a
         FROM listings l GROUP BY l.category_id ORDER BY c DESC LIMIT 15"
    )->fetchAll();
    foreach ($dist as $row) {
        $cid = (int) $row['category_id'];
        $slug = $catInfo[$cid]['slug'] ?? '?';
        $name = $catInfo[$cid]['name'] ?? '?';
        $pid  = $catInfo[$cid]['parent_id'] ?? -1;
        printf("  id=%-8d parent=%-8d %-14s %-22s کل=%-5d attrs=%d\n",
            $cid, $pid, $slug, $name, (int) $row['c'], (int) $row['a']);
    }
} catch (Throwable $e) {
    echo '  (گزارش تشخیصی ناموفق: ' . $e->getMessage() . ")\n";
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
