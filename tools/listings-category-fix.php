<?php
/**
 * سواپین — اصلاح دسته‌بندی آگهی‌های جابه‌جا (نگاشت «id آگهی → اسلاگ برگ درست»)
 *
 * منبع تصمیم: شیت «سواپین — نگاشت دسته‌های فعلی» (دسته‌ی فعلی فرم → دسته‌ی برگ جدید).
 * این ابزار آگهی‌های مشخصی را که در برگ اشتباه نشسته‌اند به برگ درست منتقل می‌کند
 * (برخلاف category-migrate.php که دسته‌ها را جابه‌جا می‌کند، نه آگهی‌ها را).
 *
 * پیش‌فرض «dry-run» است؛ برای اعمال واقعی «--apply».
 *
 * اجرا روی سرور (از ریشه‌ی پروژه):
 *   php tools/listings-category-fix.php
 *   php tools/listings-category-fix.php --apply
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

/**
 * نگاشت: id آگهی => [اسلاگ برگ درست، دلیل].
 * برگ‌های مجاز = برگ‌های فعال درخت دسته (parent_id != 0).
 */
$fixes = [
    // ── دسته‌ی «خدمات» (/iran/services) ──────────────────────────────────────
    284  => ['watches',          'ساعت مچی برند → برگ ساعت (نه خدمات)'],
    291  => ['watches',          'ساعت مچی هوشمند → برگ ساعت (نه خدمات)'],
    241  => ['accessories',      'ورساچه (کیف/اکسسوری) → لوازم جانبی (نه خدمات)'],

    // ── آگهی چسبیده به برگ سطح ۱ «وسایل نقلیه» ───────────────────────────────
    1328 => ['motorcycles',      'باجاج اونجر ۲۲۰ موتورسیکلت → برگ موتورسیکلت'],

    // ── جابه‌جایی‌های تک‌موردی ─────────────────────────────────────────────────
    263  => ['mobile-phones',    'گوشی تاشو → برگ گوشی موبایل (نه خودرو)'],
    247  => ['car',              'شاهین سفید سواری → برگ خودرو (نه قطعات خودرو)'],
    1164 => ['furniture',        'صندلی گیمینگ → مبلمان (نه رایانه)'],
    1162 => ['laptop',           'لپ‌تاپ گیمینگ → لپ‌تاپ (نه مبلمان)'],
    1212 => ['furniture',        'آلاچیق/میز باغچه راتانی → مبلمان'],
    1266 => ['sports',           'یونیفورم تکواندو → لوازم ورزشی (نه آلات موسیقی)'],
    1267 => ['sports',           'کمان تفریحی → لوازم ورزشی (نه آشپزخانه)'],
    1270 => ['bicycles',         'دوچرخه برقی → برگ دوچرخه'],
    1326 => ['furniture',        'میز ناهارخوری → مبلمان (نه گوشی موبایل)'],
    1229 => ['audio-video',      'سی‌دی آلبوم موسیقی → صوتی و تصویری'],
    1186 => ['shoes',            'بوت زنانه بارانی → برگ کفش'],
    1208 => ['furniture',        'میز و صندلی باغچه → مبلمان'],
    1318 => ['kitchenware',      'ست بارمن/ککتل → ظروف و لوازم آشپزخانه'],

    // ── خدمات تبلیغ‌شده در «آثار هنری و کلکسیونی» ─────────────────────────────
    272  => ['services',         'انجام خدمات خوراکی → خدمات'],
    274  => ['services',         'طراحی سایت و اپلیکیشن → خدمات'],
    280  => ['services',         'طراحی وب‌سایت → خدمات'],
    1309 => ['services',         'طراحی گرافیک و هویت بصری → خدمات'],

    // ── لوازم برقی آشپزخانه که باید در «لوازم خانگی» باشند ───────────────────
    228  => ['home-appliances',  'آبگرمکن برقی → لوازم خانگی'],
    1198 => ['home-appliances',  'یخچال فریزر → لوازم خانگی'],
    1199 => ['home-appliances',  'ماشین لباسشویی → لوازم خانگی'],
    1200 => ['home-appliances',  'اجاق گاز → لوازم خانگی'],
    1203 => ['home-appliances',  'قهوه‌ساز اسپرسو → لوازم خانگی'],
    1204 => ['home-appliances',  'چای‌ساز برقی → لوازم خانگی'],
    1311 => ['home-appliances',  'قهوه‌ساز صنعتی → لوازم خانگی'],
    1315 => ['home-appliances',  'آبمیوه‌گیری → لوازم خانگی'],
    1317 => ['home-appliances',  'همزن صنعتی → لوازم خانگی'],

    // ── طبق یادداشت شیت «رایانه»: آگهی‌های لپ‌تاپ تکی به برگ laptop ────────────
    2    => ['laptop',           'لپ‌تاپ'],
    238  => ['laptop',           'لپ‌تاپ'],
    261  => ['laptop',           'لپ‌تاپ'],
    289  => ['laptop',           'لپ‌تاپ'],
    1116 => ['laptop',           'لپ‌تاپ'],
    1117 => ['laptop',           'لپ‌تاپ'],
    1134 => ['laptop',           'لپ‌تاپ'],
    1135 => ['laptop',           'لپ‌تاپ'],
    1136 => ['laptop',           'لپ‌تاپ'],
    1137 => ['laptop',           'لپ‌تاپ'],
    1138 => ['laptop',           'لپ‌تاپ'],
    1139 => ['laptop',           'لپ‌تاپ'],
    1140 => ['laptop',           'لپ‌تاپ'],
    1141 => ['laptop',           'لپ‌تاپ'],
];

echo ($apply ? "حالت اعمال (--apply)\n" : "حالت آزمایشی (dry-run) — برای اعمال --apply بزنید\n");
echo 'نگاشت پیشنهادی: ' . count($fixes) . " آگهی\n\n";

// اسلاگ برگ → id (فقط برگ‌های فعال: parent_id != 0)
$leafId = [];
foreach ($pdo->query('SELECT id, slug, parent_id, is_active FROM categories') as $r) {
    if ((int) $r['parent_id'] === 0 || (int) $r['is_active'] !== 1) {
        continue;
    }
    $leafId[(string) $r['slug']] = (int) $r['id'];
}

$sel  = $pdo->prepare(
    'SELECT l.id, l.title, l.category_id, c.slug AS cat_slug
       FROM listings l LEFT JOIN categories c ON c.id = l.category_id
      WHERE l.id = ?'
);
$upd  = $pdo->prepare('UPDATE listings SET category_id = ? WHERE id = ?');

$moved = 0;
$skip  = 0;
$bad   = [];

foreach ($fixes as $id => [$targetSlug, $reason]) {
    if (!isset($leafId[$targetSlug])) {
        $bad[] = "برگ «{$targetSlug}» در دیتابیس فعال نیست؛ آگهی {$id} رد شد.";
        continue;
    }
    $sel->execute([$id]);
    $row = $sel->fetch();
    if (!$row) {
        $bad[] = "آگهی {$id} پیدا نشد ({$reason}).";
        continue;
    }
    $fromSlug = (string) ($row['cat_slug'] ?? '');
    if ($fromSlug === $targetSlug) {
        $skip++;
        continue;
    }
    $title = mb_substr((string) $row['title'], 0, 48);
    echo sprintf("[%s] %s → %s | #%d %s\n", $apply ? 'MOVE' : 'PLAN', $fromSlug, $targetSlug, $id, $title);
    if ($apply) {
        $upd->execute([$leafId[$targetSlug], $id]);
    }
    $moved++;
}

echo "\n";
echo $apply
    ? "منتقل‌شده: {$moved} | بدون‌تغییر: {$skip}\n"
    : "برنامه‌ی انتقال: {$moved} | بدون‌تغییر: {$skip}\n";

if ($bad !== []) {
    echo "\nموارد ردشده (" . count($bad) . "):\n";
    foreach ($bad as $b) {
        echo "  - {$b}\n";
    }
}

if (!$apply) {
    echo "\nاین فقط پیش‌نمایش است؛ برای اعمال واقعی «--apply» را اضافه کنید.\n";
}
