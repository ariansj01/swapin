<?php
/**
 * سواپین — داده اولیهٔ لندینگ‌های معاوضه (swap_landings) — کارت A16.2
 *
 * طبق سند معماری سئو (بخش ۴ و ۵-۲) لندینگ معاوضه فقط از «لیست کیورد تأییدشده»
 * ساخته می‌شود، نه از ترکیب خودکار فیلترها. این ابزار حداقل لندینگ‌های تأییدشدهٔ
 * «مکان» و «دسته» را می‌سازد:
 *
 *   مکان  →  /{location}/list/swap                (همهٔ معاوضه‌های یک مکان)
 *   دسته  →  /{location}/list/swap-{category}     (معاوضه‌های یک دسته در مکان)
 *
 * اسلاگ‌ها از خودِ دیتابیس خوانده می‌شوند (اسلاگ‌های تأییدشدهٔ درخت مکان و درخت
 * دسته)؛ سطح کشور اسلاگش `iran` است. بدون این ردیف‌ها مسیریابی «strict» صفحه‌های
 * /{loc}/list/swap* را با ۳۰۱ به صفحهٔ دسته/مکان می‌فرستد و سایت‌مپ swap خالی می‌ماند.
 *
 * قواعد پیش‌فرض:
 *   - لندینگ مکان برای همهٔ مکان‌ها (کشور، استان، منطقه، شهر).
 *   - لندینگ دسته برای دسته‌های سطح ۱ و ۲ (والد ریشه یا فرزند سطح ۱) در سطح
 *     کشور + استان + منطقه.
 *
 * پیش‌فرض «dry-run» است و چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 * گزینه‌ها:
 *   --apply         درج/به‌روزرسانی واقعی
 *   --with-cities   افزودن لندینگ دسته برای شهرها هم (مثل /tehran/list/swap-real-estate)
 *
 * اجرا روی سرور (از ریشهٔ پروژه):
 *   php tools/swap-landings-seed.php
 *   php tools/swap-landings-seed.php --apply
 *   php tools/swap-landings-seed.php --apply --with-cities
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

$args       = array_slice($argv, 1);
$apply      = in_array('--apply', $args, true);
$withCities = in_array('--with-cities', $args, true);

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

/** ایجاد جدول لندینگ‌های معاوضه (هم‌راستا با includes/swap_landings.php). */
function seed_swap_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `swap_landings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(16) NOT NULL DEFAULT 'category',
            `location_slug` VARCHAR(120) NOT NULL DEFAULT '',
            `token` VARCHAR(191) NOT NULL,
            `category_slug` VARCHAR(120) NOT NULL DEFAULT '',
            `model_slug` VARCHAR(120) NOT NULL DEFAULT '',
            `target_kind` VARCHAR(16) NOT NULL DEFAULT '',
            `target_slug` VARCHAR(120) NOT NULL DEFAULT '',
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `h1` VARCHAR(255) NOT NULL DEFAULT '',
            `intro` TEXT NULL,
            `index_status` VARCHAR(16) NOT NULL DEFAULT 'auto',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `first_indexed_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_swap_landing` (`location_slug`, `token`),
            KEY `idx_swap_landing_cat` (`location_slug`, `category_slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

seed_swap_ensure_table($pdo);

// ── مکان‌های تأییدشده (همهٔ سطوح) ────────────────────────────────────────────
$locStmt = $pdo->query(
    "SELECT slug, level, name FROM locations
     WHERE is_active = 1 AND level IN ('country','province','region','city')
     ORDER BY FIELD(level, 'country', 'province', 'region', 'city'), sort_order, id"
);
$locations = $locStmt->fetchAll();

// ── دسته‌های تأییدشده: سطح ۱ و ۲ (والد ریشه یا فرزند سطح ۱) ───────────────────
$catRows = $pdo->query(
    "SELECT c.slug AS slug, c.parent_id AS parent_id, p.parent_id AS gp_parent_id
     FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1
     ORDER BY c.sort_order, c.id"
)->fetchAll();

$categories = [];
foreach ($catRows as $c) {
    $slug = strtolower((string) $c['slug']);
    if ($slug === '') {
        continue;
    }
    $pid  = (int) ($c['parent_id'] ?? 0);
    $gpid = (int) ($c['gp_parent_id'] ?? 0);
    if ($pid === 0) {                 // سطح ۱
        $categories[$slug] = true;
    } elseif ($gpid === 0) {          // سطح ۲ (فرزند یک دستهٔ سطح ۱)
        $categories[$slug] = true;
    }
}
$categories = array_keys($categories);

if (!$locations) {
    fwrite(STDERR, "هیچ مکانی در جدول locations پیدا نشد — ابتدا درخت مکان ساخته شود.\n");
    exit(1);
}
if (!$categories) {
    fwrite(STDERR, "هیچ دستهٔ سطح ۱/۲ در جدول categories پیدا نشد — ابتدا درخت دسته ساخته شود.\n");
    exit(1);
}

// مکان‌های مجاز برای لندینگ دسته: کشور + استان + منطقه (+ شهرها با --with-cities)
$catLocations = [];
foreach ($locations as $loc) {
    if ($withCities || in_array((string) $loc['level'], ['country', 'province', 'region'], true)) {
        $catLocations[] = (string) $loc['slug'];
    }
}

// ── ساخت فهرست لندینگ‌های تأییدشده: [location_slug, token, type, category_slug] ─
$landings = [];

// لندینگ مکان: token = swap
foreach ($locations as $loc) {
    $landings[] = [
        'location_slug' => (string) $loc['slug'],
        'token'         => 'swap',
        'type'          => 'location',
        'category_slug' => '',
    ];
}

// لندینگ دسته: token = swap-{category}
foreach ($catLocations as $locSlug) {
    foreach ($categories as $catSlug) {
        $landings[] = [
            'location_slug' => $locSlug,
            'token'         => 'swap-' . $catSlug,
            'type'          => 'category',
            'category_slug' => $catSlug,
        ];
    }
}

echo $apply
    ? "حالت اعمال (--apply)\n"
    : "حالت آزمایشی (dry-run) — برای اعمال --apply بزنید\n";
echo 'مکان‌ها: ' . count($locations)
    . '  |  مکان‌های لندینگ دسته: ' . count($catLocations)
    . '  |  دسته‌های سطح ۱ و ۲: ' . count($categories)
    . '  |  لندینگ پیشنهادی: ' . count($landings) . "\n\n";

// ── درج/به‌روزرسانی (title/h1/intro/index_status دستی حفظ می‌شود) ─────────────
$find = $pdo->prepare('SELECT id FROM swap_landings WHERE location_slug = ? AND token = ? LIMIT 1');
$ins  = $pdo->prepare(
    'INSERT INTO swap_landings
        (type, location_slug, token, category_slug, model_slug, target_kind, target_slug, index_status, is_active)
     VALUES (?, ?, ?, ?, \'\', \'\', \'\', \'auto\', 1)'
);
$upd  = $pdo->prepare(
    'UPDATE swap_landings
        SET type = ?, category_slug = ?, model_slug = \'\', target_kind = \'\', target_slug = \'\', is_active = 1
     WHERE id = ?'
);

$totals = ['insert' => 0, 'update' => 0, 'unchanged' => 0];

foreach ($landings as $row) {
    $find->execute([$row['location_slug'], $row['token']]);
    $id = (int) ($find->fetchColumn() ?: 0);

    if ($id > 0) {
        // ردیف موجود: فقط شکل لندینگ و فعال‌بودن هم‌راستا می‌شود؛
        // تایتل/H1/مقدمه و وضعیت ایندکسِ دستی دست‌نخورده می‌ماند.
        if ($apply) {
            $upd->execute([$row['type'], $row['category_slug'], $id]);
            $totals[$upd->rowCount() > 0 ? 'update' : 'unchanged']++;
        } else {
            $totals['unchanged']++;
        }
        continue;
    }

    if ($apply) {
        $ins->execute([$row['type'], $row['location_slug'], $row['token'], $row['category_slug']]);
    }
    $totals['insert']++;
}

echo 'نتیجه: insert=' . $totals['insert']
    . '  update=' . $totals['update']
    . '  بدون‌تغییر=' . $totals['unchanged'] . "\n";

if (!$apply) {
    echo "\nاین فقط پیش‌نمایش است؛ برای درج واقعی «--apply» را اضافه کنید.\n";
    exit(0);
}

echo "\nپس از اعمال، برای راستی‌آزمایی:\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-car\n";
echo "  (هر دو باید ۲۰۰ بدهند و از ۳۰۱ به /iran و /iran/car خبری نباشد)\n";
