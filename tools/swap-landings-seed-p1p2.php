<?php
/**
 * سواپین — دادهٔ اولیهٔ ۴۴ لندینگ معاوضهٔ تأییدشده (اولویت P1 و P2)
 *
 * منبع: شیت «سواپین — صفحات هدف کیوردها» — فقط ردیف‌های تأییدشدهٔ P1/P2.
 * طبق سند معماری سئو (بخش ۴ و ۵-۲) لندینگ معاوضه فقط از «لیست کیورد تأییدشده»
 * ساخته می‌شود، نه از ترکیب خودکار فیلترها؛ پس این ابزار *فقط* همین فهرست ثابت را
 * می‌سازد (نه همهٔ ترکیب‌های مکان×دسته):
 *
 *   مکان    →  /{location}/list/swap
 *   دسته    →  /{location}/list/swap-{category}
 *   مدل     →  /{location}/list/swap-car-pride
 *   X با Y  →  /{location}/list/swap-{x}-with-{y}
 *
 * تفکیک نوع: ۲۴ لندینگ دسته + ۱۷ لندینگ X با Y + ۲ لندینگ مکان + ۱ لندینگ مدل = ۴۴
 *
 * دو اسلاگ غیرمعتبر طبق تصمیم:
 *   - «تهاتر ملک با طلا» → gold اسلاگ معتبر ندارد؛ طبق سند «طلا دستهٔ جدا نیست»،
 *     به اسلاگ معتبر `jewellery` نگاشت شد → swap-real-estate-with-jewellery
 *   - «تهاتر ملک با کالا» → goods اسلاگ معتبر ندارد و نگاشت معنایی درستی هم ندارد؛
 *     ساخته نمی‌شود (۲۷۶ لندینگ کاندید فقط با شرط ایندکس ساخته می‌شوند).
 *   - سه لندینگ «مصالح ساختمانی» حذف شد: دستهٔ building-materials آگهی ندارد و
 *     صفحهٔ خالی/Soft 404 می‌ساخت؛ پس از پرشدن دسته دوباره به فهرست برگردد.
 *
 * ترتیب ساخت طبق کارت: اول لندینگ‌های مکان و دسته (به «می‌خواهم» نیاز ندارند)،
 * بعد لندینگ‌های X با Y.
 *
 * تایتل/H1/مقدمه به‌صورت خودکار از قالب پنل (شیت «قالب تایتل و H1») ساخته می‌شوند؛
 * مقادیر دستیِ پنل در اجرای مجدد دست‌نخورده می‌مانند.
 *
 * پیش‌فرض «dry-run» است و چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 * با «--prune» ردیف‌های خارج از این فهرست تأییدشده (مثل لندینگ‌های ساخته‌شده با
 * ترکیب خودکار مکان×دسته) از جدول حذف می‌شوند تا از سایت‌مپ بیرون بروند؛
 * بدون «--apply» فقط فهرست موارد قابل‌حذف نمایش داده می‌شود.
 *
 * اجرا روی سرور (از ریشهٔ پروژه):
 *   php tools/swap-landings-seed-p1p2.php
 *   php tools/swap-landings-seed-p1p2.php --apply
 *   php tools/swap-landings-seed-p1p2.php --apply --prune
 *
 * عمداً «includes/config.php» لود نمی‌شود تا مایگریشن‌های خودکار CLI اجرا نشوند؛
 * فقط «.env» خوانده می‌شود و اتصال با PDO مستقیم انجام می‌شود.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان (CLI) قابل اجراست.');
}

require_once __DIR__ . '/../includes/load_env.php';
load_env(__DIR__ . '/../.env');

$args  = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$prune = in_array('--prune', $args, true);

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

/**
 * فهرست ثابت ۴۴ لندینگ تأییدشده (P1/P2).
 * هر ردیف: [location_slug, token, type, category_slug, model_slug, target_kind, target_slug]
 * ترتیب: ابتدا مکان‌ها، سپس دسته‌ها، سپس مدل، و در پایان X با Y.
 */
$landings = [
    // ── لندینگ مکان (۲) ───────────────────────────────────────────────────
    ['karaj',  'swap', 'location', '', '', '', ''],
    ['tehran', 'swap', 'location', '', '', '', ''],

    // ── لندینگ دسته (۲۴) ─────────────────────────────────────────────────
    ['iran',    'swap-car',              'category', 'car',              '', '', ''],
    ['iran',    'swap-real-estate',      'category', 'real-estate',      '', '', ''],
    ['tehran',  'swap-apartment',        'category', 'apartment',        '', '', ''],
    ['iran',    'swap-mobile-phones',    'category', 'mobile-phones',    '', '', ''],
    ['iran',    'swap-apartment',        'category', 'apartment',        '', '', ''],
    ['iran',    'swap-game-consoles',    'category', 'game-consoles',    '', '', ''],
    ['isfahan', 'swap-real-estate',      'category', 'real-estate',      '', '', ''],
    ['tehran',  'swap-real-estate',      'category', 'real-estate',      '', '', ''],
    ['isfahan', 'swap-apartment',        'category', 'apartment',        '', '', ''],
    ['karaj',   'swap-apartment',        'category', 'apartment',        '', '', ''],
    ['shomal',  'swap-villa',            'category', 'villa',            '', '', ''],
    ['iran',    'swap-books',            'category', 'books',            '', '', ''],
    ['iran',    'swap-laptop',           'category', 'laptop',           '', '', ''],
    ['iran',    'swap-villa',            'category', 'villa',            '', '', ''],
    ['karaj',   'swap-real-estate',      'category', 'real-estate',      '', '', ''],
    ['iran',    'swap-simcards',         'category', 'simcards',         '', '', ''],
    ['iran',    'swap-commercial',       'category', 'commercial',       '', '', ''],
    ['iran',    'swap-land',             'category', 'land',             '', '', ''],
    ['karaj',   'swap-car',              'category', 'car',              '', '', ''],
    ['rasht',   'swap-real-estate',      'category', 'real-estate',      '', '', ''],
    ['iran',    'swap-motorcycles',      'category', 'motorcycles',      '', '', ''],
    ['iran',    'swap-home-appliances',  'category', 'home-appliances',  '', '', ''],
    ['iran',    'swap-plants',           'category', 'plants',           '', '', ''],
    ['iran',    'swap-pets',             'category', 'pets',             '', '', ''],

    // ── لندینگ مدل (۱): پراید — برند سایپا، دستهٔ خودرو ──────────────────────
    ['iran', 'swap-car-pride', 'model', 'car', 'pride', '', ''],

    // ── لندینگ X با Y (۱۷) ───────────────────────────────────────────────
    ['iran',   'swap-car-with-real-estate',                  'xy', 'car',              '', 'category', 'real-estate'],
    ['iran',   'swap-real-estate-with-real-estate',          'xy', 'real-estate',      '', 'category', 'real-estate'],
    ['iran',   'swap-real-estate-with-car',                  'xy', 'real-estate',      '', 'category', 'car'],
    ['iran',   'swap-car-with-car',                          'xy', 'car',              '', 'category', 'car'],
    ['iran',   'swap-land-with-apartment',                   'xy', 'land',             '', 'category', 'apartment'],
    ['iran',   'swap-villa-with-apartment',                 'xy', 'villa',            '', 'category', 'apartment'],
    ['iran',   'swap-car-with-land',                         'xy', 'car',              '', 'category', 'land'],
    ['iran',   'swap-game-consoles-with-game-consoles',      'xy', 'game-consoles',    '', 'category', 'game-consoles'],
    ['iran',   'swap-apartment-with-villa',                  'xy', 'apartment',        '', 'category', 'villa'],
    ['karaj',  'swap-apartment-with-villa',                  'xy', 'apartment',        '', 'category', 'villa'],
    ['shomal', 'swap-land-with-apartment',                   'xy', 'land',             '', 'category', 'apartment'],
    ['shomal', 'swap-apartment-with-villa',                  'xy', 'apartment',        '', 'category', 'villa'],
    ['iran',   'swap-real-estate-with-jewellery',            'xy', 'real-estate',      '', 'category', 'jewellery'],
    ['iran',   'swap-land-with-car',                         'xy', 'land',             '', 'category', 'car'],
    ['iran',   'swap-apartment-with-apartment',              'xy', 'apartment',        '', 'category', 'apartment'],
    ['iran',   'swap-apartment-with-land',                   'xy', 'apartment',        '', 'category', 'land'],
    ['iran',   'swap-land-with-land',                        'xy', 'land',             '', 'category', 'land'],
];

echo $apply
    ? "حالت اعمال (--apply)\n"
    : "حالت آزمایشی (dry-run) — برای اعمال --apply بزنید\n";
echo 'لندینگ پیشنهادی: ' . count($landings) . "\n\n";

// ── درج/به‌روزرسانی (title/h1/intro/index_status دستی حفظ می‌شود) ─────────────
$find = $pdo->prepare('SELECT id FROM swap_landings WHERE location_slug = ? AND token = ? LIMIT 1');
$ins  = $pdo->prepare(
    'INSERT INTO swap_landings
        (type, location_slug, token, category_slug, model_slug, target_kind, target_slug, index_status, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, \'auto\', 1)'
);
$upd  = $pdo->prepare(
    'UPDATE swap_landings
        SET type = ?, category_slug = ?, model_slug = ?, target_kind = ?, target_slug = ?, is_active = 1
     WHERE id = ?'
);

$totals = ['insert' => 0, 'update' => 0, 'unchanged' => 0];

foreach ($landings as $row) {
    [$loc, $token, $type, $catSlug, $modelSlug, $targetKind, $targetSlug] = $row;

    $find->execute([$loc, $token]);
    $id = (int) ($find->fetchColumn() ?: 0);

    if ($id > 0) {
        // ردیف موجود: فقط شکل فیلتر و فعال‌بودن هم‌راستا می‌شود؛
        // تایتل/H1/مقدمه و وضعیت ایندکسِ دستی دست‌نخورده می‌ماند.
        if ($apply) {
            $upd->execute([$type, $catSlug, $modelSlug, $targetKind, $targetSlug, $id]);
            $totals[$upd->rowCount() > 0 ? 'update' : 'unchanged']++;
        } else {
            $totals['unchanged']++;
        }
        continue;
    }

    if ($apply) {
        $ins->execute([$type, $loc, $token, $catSlug, $modelSlug, $targetKind, $targetSlug]);
    }
    $totals['insert']++;
}

echo 'نتیجه: insert=' . $totals['insert']
    . '  update=' . $totals['update']
    . '  بدون‌تغییر=' . $totals['unchanged'] . "\n";

// ── حذف لندینگ‌های خارج از فهرست تأییدشده (--prune) ──────────────────────────
// جلوگیری از باقی‌ماندن لندینگ‌های ساخته‌شده با ترکیب خودکار مکان×دسته و خروج آن‌ها
// از سایت‌مپ؛ فقط ردیف‌هایی که در فهرست ثابت بالا نیستند حذف می‌شوند.
if ($prune) {
    $approved = [];
    foreach ($landings as $row) {
        $approved[$row[0] . '|' . $row[1]] = true;
    }
    $extra = [];
    foreach ($pdo->query('SELECT id, location_slug, token FROM swap_landings')->fetchAll() as $r) {
        if (!isset($approved[(string) $r['location_slug'] . '|' . (string) $r['token']])) {
            $extra[] = $r;
        }
    }
    echo "\nلندینگ خارج از فهرست تأییدشده: " . count($extra) . "\n";
    if ($extra && !$apply) {
        echo "(توجه: هر لندینگی که در فهرست ثابت بالا نباشد — از جمله موارد افزوده‌شده از پنل — حذف می‌شود.)\n";
    }
    if ($apply && $extra) {
        $del = $pdo->prepare('DELETE FROM swap_landings WHERE id = ?');
        foreach ($extra as $r) {
            $del->execute([(int) $r['id']]);
        }
        echo 'حذف‌شده: ' . count($extra) . "\n";
    } elseif ($extra) {
        foreach (array_slice($extra, 0, 60) as $r) {
            echo '  - /' . $r['location_slug'] . '/list/' . $r['token'] . "\n";
        }
        if (count($extra) > 60) {
            echo '  ... و ' . (count($extra) - 60) . " مورد دیگر\n";
        }
    }
}

if (!$apply) {
    echo "\nاین فقط پیش‌نمایش است؛ برای درج واقعی «--apply» را اضافه کنید.\n";
    exit(0);
}

echo "\nپس از اعمال، برای راستی‌آزمایی:\n";
echo "  # لندینگ‌های تأییدشده باید ۲۰۰ بدهند:\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-car\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-car-pride\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-car-with-real-estate\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/karaj/list/swap\n";
echo "  # لندینگ‌های خارج از فهرست باید ۴۰۴ بدهند و در سایت‌مپ نباشند:\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-jobs-services\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-building-materials\n";
echo "  curl -s https://<host>/sitemap-swap.xml | grep -c '<loc>'\n";
