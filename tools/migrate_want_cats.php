<?php
/**
 * سواپین — مهاجرت «در ازای» متنی به دسته‌بندی ساختاریافته (تسک A05.3)
 *
 * آگهی‌های قدیمی فقط متن آزاد «در ازای / می‌خواهم» (ستون want_in_return) دارند.
 * این ابزار همان متن را به کلید ساختاریافته تبدیل می‌کند:
 *   - want_category_id  → دسته‌ی برگِ خواسته‌شده (سند معماری — بخش ۸-۴)
 *   - want_location_id  → فقط اگر این ستون در دیتابیس وجود داشته باشد
 *
 * روش تطبیق (اولویت از بالا به پایین):
 *   ۱) نگاشت دستی «--map=فایل.csv» (ردیف‌ها: listing_id,category) — همیشه برنده است
 *   ۲) تطابق نام کامل یک دسته‌ی برگ با متن (مثلاً «آپارتمان»)
 *   ۳) تطابق واژه‌نامه‌ی مترادف‌ها (مثلاً «ماشین» → خودرو)
 *   فقط دسته‌های «برگ» مقصد می‌شوند (قید پروژه: آگهی فقط به برگ وصل می‌شود).
 *
 * متن‌های نقد/پول دسته‌ی کالایی ندارند و جداگانه گزارش می‌شوند.
 *
 * پیش‌فرض «dry-run» است و هیچ چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 *
 * اجرا روی سرور (از ریشه‌ی پروژه):
 *   php tools/migrate_want_cats.php
 *   php tools/migrate_want_cats.php --map=/path/to/want-map.csv
 *   php tools/migrate_want_cats.php --apply
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

$apply      = false;
$mapPath    = null;
$limit      = 0;
$sampleSize = 20;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--dry-run') {
        $apply = false;
    } elseif (str_starts_with($arg, '--map=')) {
        $mapPath = trim(substr($arg, 6));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 8));
    } elseif (str_starts_with($arg, '--sample=')) {
        $sampleSize = max(0, (int)substr($arg, 9));
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

// ── کمکی‌ها ─────────────────────────────────────────────────────────────────
function w_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function w_has_column(PDO $pdo, string $table, string $column): bool
{
    $t = preg_replace('/[^a-z0-9_]/i', '', $table);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$t, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function w_line(string $ch = '─', int $n = 78): string
{
    return str_repeat($ch, $n);
}

function w_cut(string $s, int $n = 46): string
{
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
}

/** نرمال‌سازی متن فارسی: یکسان‌سازی ی/ک/ه، حذف اعراب و نیم‌فاصله، ارقام لاتین. */
function w_norm(string $s): string
{
    $s = str_replace(
        ["\xE2\x80\x8C", "\xE2\x80\x8B", 'ي', 'ك', 'ة', 'ۀ', 'ؤ', 'إ', 'أ', 'آ', 'ٱ', 'ﻻ'],
        [' ', '', 'ی', 'ک', 'ه', 'ه', 'و', 'ا', 'ا', 'ا', 'ا', 'لا'],
        $s
    );
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{0670}]/u', '', $s);
    $s = str_replace(
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        $s
    );
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$s);
    $s = trim((string)preg_replace('/\s+/u', ' ', (string)$s));
    return mb_strtolower($s);
}

/** حذف فاصله‌ها برای تطبیق «لپ‌تاپ» با «لپ تاپ». */
function w_squash(string $s): string
{
    return str_replace(' ', '', $s);
}

/** جست‌وجوی مرزدار واژه در متن نرمال‌شده. */
function w_find(string $hay, string $needle): bool
{
    if ($needle === '' || mb_strlen($needle) < 2) {
        return false;
    }
    $q = preg_quote($needle, '/');
    return (bool)preg_match('/(?<![\p{L}\p{N}])' . $q . '(?![\p{L}\p{N}])/u', $hay);
}

/**
 * واژه‌نامه‌ی مترادف: عبارت رایج در متن آزاد → اسلاگ دسته‌ی برگ.
 * فقط اسلاگ‌هایی که واقعاً در دیتابیس برگ هستند اعمال می‌شوند.
 */
function w_synonyms(): array
{
    return [
        // ── املاک
        'اپارتمان' => 'apartment', 'واحد' => 'apartment', 'رهن' => 'apartment-rent',
        'اجاره' => 'apartment-rent', 'ویلا' => 'villa', 'زمین' => 'land', 'کلنگی' => 'land',
        'مغازه' => 'commercial', 'مغازه و ملک تجاری' => 'commercial', 'دفتر کار' => 'commercial',
        // ── وسایل نقلیه
        'ماشین' => 'car', 'پژو' => 'car', 'پراید' => 'car', 'سمند' => 'car', 'تیبا' => 'car',
        'ساینا' => 'car', 'دنا' => 'car', 'شاهین' => 'car', 'رانا' => 'car', 'کوییک' => 'car',
        'تارا' => 'car', 'پیکان' => 'car', 'بنز' => 'car', 'بی ام و' => 'car', 'bmw' => 'car',
        'تویوتا' => 'car', 'هیوندای' => 'car', 'کیا' => 'car', 'ام وی ام' => 'car', 'لیفان' => 'car',
        'جک' => 'car', 'چری' => 'car', 'هایما' => 'car', 'سانتافه' => 'car', 'اسپورتیج' => 'car',
        'موتور' => 'motorcycles', 'موتورسیکلت' => 'motorcycles', 'اسکوتر' => 'motorcycles',
        'لوازم یدکی' => 'car-parts', 'قطعات خودرو' => 'car-parts', 'لاستیک خودرو' => 'car-parts',
        'کامیون' => 'heavy-vehicles', 'بیل مکانیکی' => 'heavy-vehicles', 'لودر' => 'heavy-vehicles',
        'ماشین الات' => 'heavy-vehicles', 'جرثقیل' => 'heavy-vehicles',
        // ── کالای دیجیتال
        'گوشی' => 'mobile-phones', 'موبایل' => 'mobile-phones', 'آیفون' => 'mobile-phones',
        'ایفون' => 'mobile-phones', 'سامسونگ' => 'mobile-phones', 'شیائومی' => 'mobile-phones',
        'هواوی' => 'mobile-phones', 'نوکیا' => 'mobile-phones',
        'تبلت' => 'tablet', 'آیپد' => 'tablet',
        'لپ تاپ' => 'laptop', 'نوت بوک' => 'laptop', 'مک بوک' => 'laptop',
        'کامپیوتر' => 'computer', 'کیس' => 'computer', 'رم' => 'computer', 'هارد' => 'computer',
        'مانیتور' => 'computer', 'سی پی یو' => 'computer',
        'کنسول' => 'game-consoles', 'پلی استیشن' => 'game-consoles', 'پلی‌استیشن' => 'game-consoles',
        'ایکس باکس' => 'game-consoles', 'پلی استيشن' => 'game-consoles',
        'سیم کارت' => 'simcards', 'خط ایرانسل' => 'simcards', 'خط همراه اول' => 'simcards',
        'شارژر' => 'mobile-accessories', 'پاور بانک' => 'mobile-accessories', 'گلس' => 'mobile-accessories',
        'تلویزیون' => 'audio-video', 'اسپیکر' => 'audio-video', 'هدفون' => 'audio-video',
        'هندزفری' => 'audio-video', 'پروژکتور' => 'audio-video', 'ضبط' => 'audio-video',
        'دوربین' => 'camera', 'گوپرو' => 'camera', 'وب کم' => 'camera',
        // ── خانه و آشپزخانه
        'مبل' => 'furniture', 'مبلمان' => 'furniture', 'کاناپه' => 'furniture', 'دکور' => 'furniture',
        'سرویس خواب' => 'furniture',
        'فرش' => 'carpet', 'قالی' => 'carpet', 'موکت' => 'carpet',
        'یخچال' => 'home-appliances', 'لباسشویی' => 'home-appliances', 'ظرفشویی' => 'home-appliances',
        'کولر' => 'home-appliances', 'اسپلیت' => 'home-appliances', 'بخاری' => 'home-appliances',
        'پکیج' => 'home-appliances', 'آبگرمکن' => 'home-appliances', 'جاروبرقی' => 'home-appliances',
        'مایکروویو' => 'home-appliances', 'اجاق گاز' => 'home-appliances',
        'ظروف' => 'kitchenware', 'قابلمه' => 'kitchenware', 'سرویس ظروف' => 'kitchenware',
        // ── وسایل شخصی
        'لباس زنانه' => 'women-clothing', 'مانتو' => 'women-clothing',
        'لباس مردانه' => 'men-clothing',
        'ساعت' => 'watches', 'ساعت مچی' => 'watches', 'ساعت هوشمند' => 'watches',
        'طلا' => 'jewellery', 'جواهر' => 'jewellery', 'زیورالات' => 'jewellery', 'گردنبند' => 'jewellery',
        'انگشتر' => 'jewellery', 'دستبند' => 'jewellery',
        'کفش' => 'shoes', 'کفش ورزشی' => 'shoes', 'کتانی' => 'shoes', 'بوت' => 'shoes',
        'کالسکه' => 'baby-kids', 'سیسمونی' => 'baby-kids', 'نوزاد' => 'baby-kids', 'کریر' => 'baby-kids',
        'لوازم ارایشی' => 'beauty', 'ارایشی' => 'beauty', 'بهداشتی' => 'beauty', 'عطر' => 'beauty',
        'ادکلن' => 'beauty', 'کرم' => 'beauty', 'میکاپ' => 'beauty',
        'کیف' => 'accessories', 'عینک' => 'accessories', 'چمدان' => 'accessories', 'اکسسوری' => 'accessories',
        // ── سرگرمی و فراغت
        'اسباب بازی' => 'toys', 'لگو' => 'toys', 'عروسک' => 'toys', 'پازل' => 'toys',
        'کتاب' => 'books', 'مجله' => 'books', 'جزوه' => 'books',
        'لوازم ورزشی' => 'sports', 'دمبل' => 'sports', 'تردمیل' => 'sports', 'توپ' => 'sports',
        'راکت' => 'sports', 'وزنه' => 'sports',
        'دوچرخه' => 'bicycles',
        'گیتار' => 'musical-instruments', 'پیانو' => 'musical-instruments', 'سنتور' => 'musical-instruments',
        'ویولن' => 'musical-instruments', 'تنبک' => 'musical-instruments', 'ساز' => 'musical-instruments',
        'سگ' => 'pets', 'گربه' => 'pets', 'حیوانات خانگی' => 'pets', 'اقواریوم' => 'pets',
        'آکواریوم' => 'pets', 'ماهی' => 'pets', 'پرنده' => 'pets', 'قفس' => 'pets',
        'گل' => 'plants', 'گیاه' => 'plants', 'کاکتوس' => 'plants',
        'تابلو' => 'art-collectibles', 'عتیقه' => 'art-collectibles', 'کلکسیون' => 'art-collectibles',
        'سکه' => 'art-collectibles', 'تمبر' => 'art-collectibles',
        // ── مصالح، ابزار و تجهیزات
        'مصالح ساختمانی' => 'building-materials', 'سیمان' => 'building-materials', 'گچ' => 'building-materials',
        'کاشی' => 'building-materials', 'اهک' => 'building-materials', 'بلوک' => 'building-materials',
        'ابزار' => 'tools', 'دریل' => 'tools', 'اره' => 'tools', 'کمپرسور' => 'tools',
        'تجهیزات پزشکی' => 'industrial-equipment', 'تجهیزات صنعتی' => 'industrial-equipment',
        'موتور برق' => 'industrial-equipment',
        // ── استخدام و خدمات
        'استخدام' => 'jobs', 'شغل' => 'jobs',
        'خدمات' => 'services', 'تعمیر' => 'services', 'نصب' => 'services', 'حمل' => 'services',
        'باربری' => 'services', 'اسباب کشی' => 'services', 'کارگر' => 'services', 'سرویس' => 'services',
    ];
}

/** واژه‌های «نقد» که دسته‌ی کالایی ندارند و باید جدا گزارش شوند. */
function w_cash_words(): array
{
    return ['نقد', 'پول', 'وجه نقد', 'پول نقد', 'کش', 'پول نقدی'];
}

/** خواندن نگاشت دستی CSV: ردیف‌ها به شکل `listing_id,category` (اسلاگ یا شناسه). */
function w_read_map(?string $path): array
{
    if ($path === null || $path === '' || !is_file($path)) {
        return [];
    }
    $out = [];
    $fh  = fopen($path, 'r');
    if ($fh === false) {
        return [];
    }
    while (($row = fgetcsv($fh)) !== false) {
        $id  = trim((string)($row[0] ?? ''));
        $val = trim((string)($row[1] ?? ''));
        if ($id === '' || $val === '' || !ctype_digit($id)) {
            continue;
        }
        $out[(int)$id] = $val;
    }
    fclose($fh);
    return $out;
}

// ── سرصفحه ──────────────────────────────────────────────────────────────────
echo w_line('=') . "\n";
echo "سواپین — مهاجرت «در ازای» متنی به دسته‌ی ساختاریافته (" . ($apply ? 'APPLY — تغییر می‌دهد' : 'DRY-RUN — فقط گزارش') . ")\n";
echo "زمان اجرا: " . date('Y-m-d H:i') . "\n";
echo "محیط: {$appEnv} | DB: {$dbName} | PHP: " . PHP_VERSION . "\n";
echo w_line('=') . "\n\n";

// ── ۱) بررسی ستون‌ها ─────────────────────────────────────────────────────────
$hasWantCat = w_has_column($pdo, 'listings', 'want_category_id');
$hasWantLoc = w_has_column($pdo, 'listings', 'want_location_id');
echo "[۱] ستون‌های مقصد\n";
echo "  listings.want_category_id : " . ($hasWantCat ? 'موجود' : 'موجود نیست') . "\n";
echo "  listings.want_location_id : " . ($hasWantLoc ? 'موجود' : 'موجود نیست (نادیده گرفته می‌شود)') . "\n\n";
if (!$hasWantCat) {
    fwrite(STDERR,
        "ستون «want_category_id» در جدول listings وجود ندارد.\n"
        . "مایگریشن‌ها فقط در CLI یا با «?migrate=1» اجرا می‌شوند؛ یکی از این دو را بزنید:\n"
        . "  php -r \"require 'includes/config.php';\"\n"
        . "  یا در مرورگر: https://<دامنه>/?migrate=1\n"
        . "سپس همین ابزار را دوباره اجرا کنید.\n");
    exit(1);
}

// ── ۲) دسته‌های برگ ──────────────────────────────────────────────────────────
$catRows = w_all($pdo, 'SELECT id, name, slug, parent_id FROM categories WHERE is_active = 1');
$catIdBySlug = [];
$childrenOf  = [];
foreach ($catRows as $r) {
    $id  = (int)$r['id'];
    $pid = (int)($r['parent_id'] ?? 0);
    $childrenOf[$pid][] = $id;
    $slug = (string)($r['slug'] ?? '');
    if ($slug !== '') {
        $catIdBySlug[$slug] = $id;
    }
}
$leafIds = [];
foreach ($catRows as $r) {
    $id = (int)$r['id'];
    if (empty($childrenOf[$id])) {
        $leafIds[$id] = true;
    }
}
if ($leafIds === []) {
    fwrite(STDERR, "هیچ دسته‌ی برگ فعالی پیدا نشد؛ ابتدا درخت دسته را بسازید.\n");
    exit(1);
}

// ── ۳) کاندیدهای تطبیق (نام برگ + مترادف) ────────────────────────────────────
$candidates = [];
foreach ($catRows as $r) {
    $id = (int)$r['id'];
    if (!isset($leafIds[$id])) {
        continue;
    }
    $n = w_norm((string)$r['name']);
    if (mb_strlen($n) >= 2) {
        $candidates[] = ['n' => $n, 'id' => $id, 'w' => 5, 'len' => mb_strlen($n), 'src' => 'name'];
    }
}
$synUsed = 0;
$synSkipped = 0;
foreach (w_synonyms() as $phrase => $slug) {
    $id = $catIdBySlug[$slug] ?? 0;
    if ($id <= 0 || !isset($leafIds[$id])) {
        $synSkipped++;
        continue;
    }
    $n = w_norm($phrase);
    if (mb_strlen($n) >= 2) {
        $candidates[] = ['n' => $n, 'id' => $id, 'w' => 0, 'len' => mb_strlen($n), 'src' => 'synonym'];
        $synUsed++;
    }
}
echo "[۲] فهرست تطبیق\n";
echo "  دسته‌ی برگ فعال: " . count($leafIds) . " | کاندید نام: " . (count($candidates) - $synUsed) . "\n";
echo "  مترادف‌های فعال: {$synUsed}" . ($synSkipped > 0 ? " (نامعتبر/غیربرگ: {$synSkipped})" : "") . "\n\n";

/** بهترین دسته برای یک متن نرمال‌شده. */
function w_best(array $candidates, string $textNorm): ?array
{
    $hay  = $textNorm;
    $haySq = w_squash($textNorm);
    $best = null;
    $bestScore = -1;
    foreach ($candidates as $c) {
        $ok = w_find($hay, (string)$c['n']);
        if (!$ok && strpos((string)$c['n'], ' ') !== false) {
            $ok = strpos($haySq, w_squash((string)$c['n'])) !== false;
        }
        if (!$ok) {
            continue;
        }
        $score = ((int)$c['len'] * 10) + (int)$c['w'];
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $c;
        }
    }
    return $best;
}

// ── ۴) فهرست مکان (برای want_location_id، در صورت وجود ستون) ────────────────
$locCandidates = [];
$locNameById   = [];
if ($hasWantLoc) {
    $locRows = w_all($pdo, "SELECT id, name, level FROM locations WHERE is_active = 1 AND level IN ('city','province')");
    foreach ($locRows as $r) {
        $id = (int)$r['id'];
        $locNameById[$id] = (string)$r['name'];
        $n = w_norm((string)$r['name']);
        if (mb_strlen($n) >= 3) {
            $locCandidates[] = [
                'n' => $n, 'id' => $id, 'len' => mb_strlen($n),
                'w' => ((string)$r['level'] === 'city') ? 5 : 0,
            ];
        }
    }
    echo "[۳] فهرست مکان: " . count($locCandidates) . " کاندید (شهر/استان)\n\n";
}

// ── ۵) نگاشت دستی ───────────────────────────────────────────────────────────
$manualRaw = w_read_map($mapPath);
$manual    = [];
$manualBad = [];
foreach ($manualRaw as $listingId => $val) {
    if (ctype_digit($val) && isset($leafIds[(int)$val])) {
        $manual[$listingId] = (int)$val;
        continue;
    }
    $cid = $catIdBySlug[$val] ?? 0;
    if ($cid > 0 && isset($leafIds[$cid])) {
        $manual[$listingId] = $cid;
    } else {
        $manualBad[$listingId] = $val;
    }
}
if ($mapPath !== null) {
    echo "[۴] نگاشت دستی «{$mapPath}»: " . count($manual) . " ردیف معتبر"
        . ($manualBad !== [] ? " | نامعتبر: " . count($manualBad) : "") . "\n\n";
}

$catNameById = [];
foreach ($catRows as $r) {
    $catNameById[(int)$r['id']] = (string)$r['name'];
}

// ── ۶) آگهی‌های هدف ─────────────────────────────────────────────────────────
$sql = "SELECT id, want_in_return
        FROM listings
        WHERE status = 'active' AND review_status = 'approved'
          AND (want_category_id IS NULL OR want_category_id = 0)
          AND want_in_return IS NOT NULL AND TRIM(want_in_return) <> ''
        ORDER BY id";
if ($limit > 0) {
    $sql .= " LIMIT {$limit}";
}
$rows = w_all($pdo, $sql);

$plan       = []; // id => ['cat'=>, 'loc'=>, 'src'=>, 'text'=>]
$unmatched  = []; // id => text
$cashOnly   = []; // id => text
$locDetected = 0;
$cashWords  = w_cash_words();

foreach ($rows as $r) {
    $id   = (int)$r['id'];
    $text = trim((string)$r['want_in_return']);
    $norm = w_norm($text);

    if (isset($manual[$id])) {
        $plan[$id] = ['cat' => $manual[$id], 'loc' => 0, 'src' => 'manual', 'text' => $text];
        continue;
    }

    $hit = w_best($candidates, $norm);
    if ($hit !== null) {
        $locId = 0;
        if ($hasWantLoc && $locCandidates !== []) {
            $lhit  = w_best($locCandidates, $norm);
            $locId = $lhit !== null ? (int)$lhit['id'] : 0;
            if ($locId > 0) {
                $locDetected++;
            }
        }
        $plan[$id] = ['cat' => (int)$hit['id'], 'loc' => $locId, 'src' => (string)$hit['src'], 'text' => $text];
        continue;
    }

    // بدون دسته — اگر فقط «نقد/پول» است جداگانه گزارش می‌شود
    $hasCash = false;
    foreach ($cashWords as $cw) {
        if (w_find($norm, w_norm($cw))) {
            $hasCash = true;
            break;
        }
    }
    if ($hasCash) {
        $cashOnly[$id] = $text;
    } else {
        $unmatched[$id] = $text;
    }
}

// ── ۷) گزارش ────────────────────────────────────────────────────────────────
echo "[۵] نتیجه‌ی تحلیل\n";
echo "  آگهی فعال با متن «در ازای» و بدون دسته : " . count($rows) . "\n";
echo "  قابل تبدیل (خودکار + دستی)             : " . count($plan) . "\n";
echo "  فقط نقد/پول (دسته‌ی کالایی ندارد)       : " . count($cashOnly) . "\n";
echo "  نیازمند بررسی دستی                     : " . count($unmatched) . "\n";
if ($hasWantLoc) {
    echo "  با مکان شناسایی‌شده                     : {$locDetected}\n";
}
echo "\n";

if ($plan !== []) {
    echo "[۶] نمونه‌ی برنامه‌ی تبدیل (حداکثر ۴۰ ردیف)\n";
    echo "  " . str_pad('ID', 7) . str_pad('منبع', 9) . str_pad('دسته', 24) . "متن «در ازای»\n";
    $i = 0;
    foreach ($plan as $id => $p) {
        if ($i++ >= 40) {
            echo "  ... (" . (count($plan) - 40) . " ردیف دیگر)\n";
            break;
        }
        printf("  %-7d%-9s%-24s%s\n", $id, $p['src'], w_cut((string)($catNameById[$p['cat']] ?? '?'), 22), w_cut((string)$p['text'], 46));
    }
    echo "\n";
}

if ($unmatched !== []) {
    echo "[۷] بدون تطبیق — برای این‌ها از «--map=» استفاده کنید\n";
    $i = 0;
    foreach ($unmatched as $id => $text) {
        if ($i++ >= 40) {
            echo "  ... (" . (count($unmatched) - 40) . " ردیف دیگر)\n";
            break;
        }
        printf("  %-7d%s\n", $id, w_cut($text, 62));
    }
    echo "\n";
}

if ($cashOnly !== []) {
    echo "[۸] فقط نقد/پول (نرمال است که دسته نداشته باشند): " . count($cashOnly) . " آگهی\n\n";
}

// ── ۸) اعمال ────────────────────────────────────────────────────────────────
if (!$apply) {
    echo w_line() . "\n";
    echo "DRY-RUN — هیچ تغییری اعمال نشد. برای اعمال واقعی: php tools/migrate_want_cats.php --apply\n";
    echo w_line() . "\n";
} else {
    $updated = 0;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            $hasWantLoc
                ? "UPDATE listings SET want_category_id = ?, want_location_id = ? WHERE id = ?"
                : "UPDATE listings SET want_category_id = ? WHERE id = ?"
        );
        foreach ($plan as $id => $p) {
            if ($hasWantLoc) {
                $stmt->execute([(int)$p['cat'], ((int)$p['loc']) ?: null, $id]);
            } else {
                $stmt->execute([(int)$p['cat'], $id]);
            }
            $updated += $stmt->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "اعمال تغییرات ناموفق بود: " . $e->getMessage() . "\n");
        exit(1);
    }
    echo w_line() . "\n";
    echo "APPLY — {$updated} آگهی به‌روزرسانی شد.\n";
    echo w_line() . "\n";
}

// ── ۹) معیار پذیرش (A05.3) ──────────────────────────────────────────────────
$base = "SELECT COUNT(*) FROM listings
         WHERE status = 'active' AND review_status = 'approved'
           AND want_in_return IS NOT NULL AND TRIM(want_in_return) <> ''";
$total = (int)$pdo->query($base)->fetchColumn();
$done  = (int)$pdo->query($base . ' AND want_category_id IS NOT NULL AND want_category_id <> 0')->fetchColumn();
$left  = $total - $done;
$pct   = $total > 0 ? round(($done / $total) * 100, 1) : 100.0;
echo "\n[۹] معیار پذیرش — آگهی‌های فعال با متن «در ازای» و دسته‌ی ساختاریافته\n";
echo "  دارای متن «در ازای»      : {$total}\n";
echo "  دارای دسته‌ی ساختاریافته  : {$done}\n";
echo "  باقی‌مانده (نیازمند دستی) : {$left}\n";
echo "  پوشش                      : {$pct}%\n";

// ── ۱۰) نمونه‌گیری برای تأیید ───────────────────────────────────────────────
if ($sampleSize > 0) {
    $sampleRows = w_all($pdo,
        "SELECT l.id, l.want_in_return, l.want_category_id, c.name AS cat_name
         FROM listings l
         LEFT JOIN categories c ON c.id = l.want_category_id
         WHERE l.status = 'active' AND l.review_status = 'approved'
           AND l.want_category_id IS NOT NULL AND l.want_category_id <> 0
           AND l.want_in_return IS NOT NULL AND TRIM(l.want_in_return) <> ''
         ORDER BY RAND()
         LIMIT " . (int)$sampleSize);
    echo "\n[۱۰] نمونه‌گیری {$sampleSize} آگهی برای تأیید چشمى\n";
    echo "  " . str_pad('ID', 7) . str_pad('دسته', 24) . "متن «در ازای»\n";
    foreach ($sampleRows as $s) {
        printf("  %-7d%-24s%s\n", (int)$s['id'], w_cut((string)($s['cat_name'] ?? '-'), 22), w_cut((string)$s['want_in_return'], 46));
    }
}
