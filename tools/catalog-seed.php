<?php
/**
 * سواپین — داده اولیهٔ برند/مدل/تیپ (catalog_nodes)
 *
 * طبق سند معماری سئو (بخش ۲ و ۱۲) صفحات برند و مدل باید از یک فهرست ساختاریافته
 * ساخته شوند، نه متن آزاد. این ابزار فهرست پایهٔ برند/مدل/تیپ را برای دسته‌های
 * «خودرو» (car) و «گوشی موبایل» (mobile-phones) در جدول catalog_nodes می‌ریزد.
 *
 * پیش‌فرض «dry-run» است و چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 *
 * اجرا روی سرور (از ریشه پروژه):
 *   php tools/catalog-seed.php
 *   php tools/catalog-seed.php --apply
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

// ── داده اولیه ───────────────────────────────────────────────────────────────
// ساختار: [اسلاگ دسته => [ [name, slug, models => [ [name, slug, trims => [...]] ]] ]]
$seed = [
    'car' => [
        ['name' => 'ایران خودرو', 'slug' => 'ikco', 'models' => [
            ['name' => 'سمند', 'slug' => 'samand', 'trims' => [['سال', 'sal'], ['دوگانه‌سوز', 'dual-fuel']]],
            ['name' => 'دنا', 'slug' => 'dena', 'trims' => [['پلاس', 'plus'], ['توربو', 'turbo']]],
            ['name' => 'تارا', 'slug' => 'tara', 'trims' => []],
            ['name' => 'رانا', 'slug' => 'rana', 'trims' => []],
            ['name' => 'ارس', 'slug' => 'aras', 'trims' => []],
        ]],
        ['name' => 'سایپا', 'slug' => 'saipa', 'models' => [
            ['name' => 'پراید', 'slug' => 'pride', 'trims' => [['111', '111'], ['131', '131'], ['151', '151']]],
            ['name' => 'تیبا', 'slug' => 'tiba', 'trims' => []],
            ['name' => 'ساینا', 'slug' => 'saina', 'trims' => []],
            ['name' => 'کوییک', 'slug' => 'quick', 'trims' => []],
            ['name' => 'شاهین', 'slug' => 'shahin', 'trims' => []],
            ['name' => 'آریو', 'slug' => 'ario', 'trims' => []],
        ]],
        ['name' => 'پژو', 'slug' => 'peugeot', 'models' => [
            ['name' => '206', 'slug' => '206', 'trims' => [['تیپ ۲', 'tip-2'], ['تیپ ۵', 'tip-5']]],
            ['name' => '207', 'slug' => '207', 'trims' => []],
            ['name' => '405', 'slug' => '405', 'trims' => []],
            ['name' => 'پارس', 'slug' => 'pars', 'trims' => []],
            ['name' => '301', 'slug' => '301', 'trims' => []],
            ['name' => '2008', 'slug' => '2008', 'trims' => []],
        ]],
        ['name' => 'تویوتا', 'slug' => 'toyota', 'models' => [
            ['name' => 'کرولا', 'slug' => 'corolla', 'trims' => []],
            ['name' => 'کمری', 'slug' => 'camry', 'trims' => []],
            ['name' => 'یاریس', 'slug' => 'yaris', 'trims' => []],
            ['name' => 'راو۴', 'slug' => 'rav4', 'trims' => []],
            ['name' => 'لندکروزر', 'slug' => 'land-cruiser', 'trims' => []],
            ['name' => 'هایلوکس', 'slug' => 'hilux', 'trims' => []],
        ]],
        ['name' => 'هیوندای', 'slug' => 'hyundai', 'models' => [
            ['name' => 'توسان', 'slug' => 'tucson', 'trims' => []],
            ['name' => 'سانتافه', 'slug' => 'santa-fe', 'trims' => []],
            ['name' => 'النترا', 'slug' => 'elantra', 'trims' => []],
            ['name' => 'اکسنت', 'slug' => 'accent', 'trims' => []],
            ['name' => 'سوناتا', 'slug' => 'sonata', 'trims' => []],
            ['name' => 'i20', 'slug' => 'i20', 'trims' => []],
            ['name' => 'i30', 'slug' => 'i30', 'trims' => []],
        ]],
        ['name' => 'کیا', 'slug' => 'kia', 'models' => [
            ['name' => 'سراتو', 'slug' => 'cerato', 'trims' => []],
            ['name' => 'اسپورتیج', 'slug' => 'sportage', 'trims' => []],
            ['name' => 'سورنتو', 'slug' => 'sorento', 'trims' => []],
            ['name' => 'ریو', 'slug' => 'rio', 'trims' => []],
            ['name' => 'اپتیما', 'slug' => 'optima', 'trims' => []],
            ['name' => 'پیکانتو', 'slug' => 'picanto', 'trims' => []],
        ]],
        ['name' => 'رنو', 'slug' => 'renault', 'models' => [
            ['name' => 'تالیسمان', 'slug' => 'talisman', 'trims' => []],
            ['name' => 'کپچر', 'slug' => 'captur', 'trims' => []],
            ['name' => 'ال۹۰', 'slug' => 'l90', 'trims' => []],
            ['name' => 'ساندرو', 'slug' => 'sandero', 'trims' => []],
            ['name' => 'داستر', 'slug' => 'duster', 'trims' => []],
            ['name' => 'مگان', 'slug' => 'megane', 'trims' => []],
        ]],
        ['name' => 'ام‌وی‌ام', 'slug' => 'mvm', 'models' => [
            ['name' => '315', 'slug' => '315', 'trims' => []],
            ['name' => '530', 'slug' => '530', 'trims' => []],
            ['name' => '550', 'slug' => '550', 'trims' => []],
            ['name' => '110', 'slug' => '110', 'trims' => []],
            ['name' => '230', 'slug' => '230', 'trims' => []],
        ]],
        ['name' => 'چری', 'slug' => 'chery', 'models' => [
            ['name' => 'تیگو ۵', 'slug' => 'tiggo-5', 'trims' => []],
            ['name' => 'تیگو ۷', 'slug' => 'tiggo-7', 'trims' => []],
            ['name' => 'تیگو ۸', 'slug' => 'tiggo-8', 'trims' => []],
            ['name' => 'آریزو ۵', 'slug' => 'arrizo-5', 'trims' => []],
            ['name' => 'آریزو ۶', 'slug' => 'arrizo-6', 'trims' => []],
        ]],
        ['name' => 'جک', 'slug' => 'jac', 'models' => [
            ['name' => 'J4', 'slug' => 'j4', 'trims' => []],
            ['name' => 'J5', 'slug' => 'j5', 'trims' => []],
            ['name' => 'S3', 'slug' => 's3', 'trims' => []],
            ['name' => 'S5', 'slug' => 's5', 'trims' => []],
            ['name' => 'J7', 'slug' => 'j7', 'trims' => []],
        ]],
        ['name' => 'لیفان', 'slug' => 'lifan', 'models' => [
            ['name' => 'X60', 'slug' => 'x60', 'trims' => []],
            ['name' => '620', 'slug' => '620', 'trims' => []],
            ['name' => '820', 'slug' => '820', 'trims' => []],
            ['name' => 'X50', 'slug' => 'x50', 'trims' => []],
        ]],
        ['name' => 'برلیانس', 'slug' => 'brilliance', 'models' => [
            ['name' => 'H230', 'slug' => 'h230', 'trims' => []],
            ['name' => 'H320', 'slug' => 'h320', 'trims' => []],
            ['name' => 'H330', 'slug' => 'h330', 'trims' => []],
            ['name' => 'H530', 'slug' => 'h530', 'trims' => []],
        ]],
        ['name' => 'دانگ‌فنگ', 'slug' => 'dongfeng', 'models' => [
            ['name' => 'H30', 'slug' => 'h30', 'trims' => []],
            ['name' => 'S30', 'slug' => 's30', 'trims' => []],
            ['name' => 'A30', 'slug' => 'a30', 'trims' => []],
        ]],
        ['name' => 'کرمان موتور', 'slug' => 'kmc', 'models' => [
            ['name' => 'تیارا', 'slug' => 'tiara', 'trims' => []],
            ['name' => 'K7', 'slug' => 'k7', 'trims' => []],
            ['name' => 'J7', 'slug' => 'kmc-j7', 'trims' => []],
        ]],
        ['name' => 'هایما', 'slug' => 'haima', 'models' => [
            ['name' => 'S5', 'slug' => 'haima-s5', 'trims' => []],
            ['name' => 'S7', 'slug' => 'haima-s7', 'trims' => []],
            ['name' => '8S', 'slug' => 'haima-8s', 'trims' => []],
        ]],
        ['name' => 'نیسان', 'slug' => 'nissan', 'models' => [
            ['name' => 'قشقایی', 'slug' => 'qashqai', 'trims' => []],
            ['name' => 'جوک', 'slug' => 'juke', 'trims' => []],
            ['name' => 'ایکس‌تریل', 'slug' => 'xtrail', 'trims' => []],
            ['name' => 'ماکسیما', 'slug' => 'maxima', 'trims' => []],
            ['name' => 'تینا', 'slug' => 'teana', 'trims' => []],
        ]],
        ['name' => 'میتسوبیشی', 'slug' => 'mitsubishi', 'models' => [
            ['name' => 'لنسر', 'slug' => 'lancer', 'trims' => []],
            ['name' => 'ASX', 'slug' => 'asx', 'trims' => []],
            ['name' => 'اوتلندر', 'slug' => 'outlander', 'trims' => []],
        ]],
        ['name' => 'مزدا', 'slug' => 'mazda', 'models' => [
            ['name' => 'مزدا ۳', 'slug' => 'mazda-3', 'trims' => []],
            ['name' => 'مزدا ۶', 'slug' => 'mazda-6', 'trims' => []],
            ['name' => 'CX-5', 'slug' => 'cx-5', 'trims' => []],
        ]],
        ['name' => 'هوندا', 'slug' => 'honda', 'models' => [
            ['name' => 'سیویک', 'slug' => 'civic', 'trims' => []],
            ['name' => 'اکورد', 'slug' => 'accord', 'trims' => []],
            ['name' => 'CR-V', 'slug' => 'cr-v', 'trims' => []],
        ]],
        ['name' => 'بی‌ام‌و', 'slug' => 'bmw', 'models' => [
            ['name' => 'سری ۳', 'slug' => 'series-3', 'trims' => []],
            ['name' => 'سری ۵', 'slug' => 'series-5', 'trims' => []],
            ['name' => 'X3', 'slug' => 'x3', 'trims' => []],
            ['name' => 'X5', 'slug' => 'x5', 'trims' => []],
        ]],
        ['name' => 'مرسدس بنز', 'slug' => 'mercedes-benz', 'models' => [
            ['name' => 'C200', 'slug' => 'c200', 'trims' => []],
            ['name' => 'E250', 'slug' => 'e250', 'trims' => []],
            ['name' => 'S500', 'slug' => 's500', 'trims' => []],
            ['name' => 'GLA', 'slug' => 'gla', 'trims' => []],
            ['name' => 'GLC', 'slug' => 'glc', 'trims' => []],
        ]],
        ['name' => 'لکسوس', 'slug' => 'lexus', 'models' => [
            ['name' => 'NX', 'slug' => 'nx', 'trims' => []],
            ['name' => 'RX', 'slug' => 'rx', 'trims' => []],
            ['name' => 'ES', 'slug' => 'es', 'trims' => []],
            ['name' => 'LX', 'slug' => 'lx', 'trims' => []],
        ]],
        ['name' => 'آئودی', 'slug' => 'audi', 'models' => [
            ['name' => 'A4', 'slug' => 'a4', 'trims' => []],
            ['name' => 'A6', 'slug' => 'a6', 'trims' => []],
            ['name' => 'Q5', 'slug' => 'q5', 'trims' => []],
            ['name' => 'Q7', 'slug' => 'q7', 'trims' => []],
        ]],
        ['name' => 'ولوو', 'slug' => 'volvo', 'models' => [
            ['name' => 'XC60', 'slug' => 'xc60', 'trims' => []],
            ['name' => 'XC90', 'slug' => 'xc90', 'trims' => []],
            ['name' => 'S60', 'slug' => 's60', 'trims' => []],
        ]],
        ['name' => 'فولکس‌واگن', 'slug' => 'volkswagen', 'models' => [
            ['name' => 'گلف', 'slug' => 'golf', 'trims' => []],
            ['name' => 'پاسات', 'slug' => 'passat', 'trims' => []],
            ['name' => 'تیگوان', 'slug' => 'tiguan', 'trims' => []],
            ['name' => 'جتا', 'slug' => 'jetta', 'trims' => []],
        ]],
        ['name' => 'شورولت', 'slug' => 'chevrolet', 'models' => [
            ['name' => 'کروز', 'slug' => 'cruze', 'trims' => []],
            ['name' => 'کاپتیوا', 'slug' => 'captiva', 'trims' => []],
            ['name' => 'آویو', 'slug' => 'aveo', 'trims' => []],
        ]],
        ['name' => 'سوزوکی', 'slug' => 'suzuki', 'models' => [
            ['name' => 'ویتارا', 'slug' => 'vitara', 'trims' => []],
            ['name' => 'گراند ویتارا', 'slug' => 'grand-vitara', 'trims' => []],
            ['name' => 'سوئیفت', 'slug' => 'swift', 'trims' => []],
        ]],
        ['name' => 'اوپل', 'slug' => 'opel', 'models' => [
            ['name' => 'آسترا', 'slug' => 'astra', 'trims' => []],
            ['name' => 'اینسیگنیا', 'slug' => 'insignia', 'trims' => []],
        ]],
        ['name' => 'سیتروئن', 'slug' => 'citroen', 'models' => [
            ['name' => 'C3', 'slug' => 'c3', 'trims' => []],
            ['name' => 'C4', 'slug' => 'c4', 'trims' => []],
            ['name' => 'C5', 'slug' => 'c5', 'trims' => []],
        ]],
        ['name' => 'لندرور', 'slug' => 'land-rover', 'models' => [
            ['name' => 'رنجرور', 'slug' => 'range-rover', 'trims' => []],
            ['name' => 'دیسکاوری', 'slug' => 'discovery', 'trims' => []],
            ['name' => 'ایووک', 'slug' => 'evoque', 'trims' => []],
        ]],
        ['name' => 'پورشه', 'slug' => 'porsche', 'models' => [
            ['name' => 'پانامرا', 'slug' => 'panamera', 'trims' => []],
            ['name' => 'کاین', 'slug' => 'cayenne', 'trims' => []],
            ['name' => 'ماکان', 'slug' => 'macan', 'trims' => []],
        ]],
        ['name' => 'تسلا', 'slug' => 'tesla', 'models' => [
            ['name' => 'مدل ۳', 'slug' => 'model-3', 'trims' => []],
            ['name' => 'مدل S', 'slug' => 'model-s', 'trims' => []],
            ['name' => 'مدل Y', 'slug' => 'model-y', 'trims' => []],
        ]],
    ],
    'mobile-phones' => [
        ['name' => 'اپل', 'slug' => 'apple', 'models' => [
            ['name' => 'آیفون 11', 'slug' => 'iphone-11', 'trims' => []],
            ['name' => 'آیفون 12', 'slug' => 'iphone-12', 'trims' => []],
            ['name' => 'آیفون 13', 'slug' => 'iphone-13', 'trims' => []],
            ['name' => 'آیفون 14', 'slug' => 'iphone-14', 'trims' => []],
            ['name' => 'آیفون 15', 'slug' => 'iphone-15', 'trims' => []],
            ['name' => 'آیفون 16', 'slug' => 'iphone-16', 'trims' => []],
            ['name' => 'آیفون X', 'slug' => 'iphone-x', 'trims' => []],
            ['name' => 'آیفون XR', 'slug' => 'iphone-xr', 'trims' => []],
            ['name' => 'آیفون SE', 'slug' => 'iphone-se', 'trims' => []],
        ]],
        ['name' => 'سامسونگ', 'slug' => 'samsung', 'models' => [
            ['name' => 'گلکسی A05', 'slug' => 'galaxy-a05', 'trims' => []],
            ['name' => 'گلکسی A15', 'slug' => 'galaxy-a15', 'trims' => []],
            ['name' => 'گلکسی A25', 'slug' => 'galaxy-a25', 'trims' => []],
            ['name' => 'گلکسی A35', 'slug' => 'galaxy-a35', 'trims' => []],
            ['name' => 'گلکسی A55', 'slug' => 'galaxy-a55', 'trims' => []],
            ['name' => 'گلکسی S21', 'slug' => 'galaxy-s21', 'trims' => []],
            ['name' => 'گلکسی S22', 'slug' => 'galaxy-s22', 'trims' => []],
            ['name' => 'گلکسی S23', 'slug' => 'galaxy-s23', 'trims' => []],
            ['name' => 'گلکسی S24', 'slug' => 'galaxy-s24', 'trims' => []],
        ]],
        ['name' => 'شیائومی', 'slug' => 'xiaomi', 'models' => [
            ['name' => 'ردمی نوت ۱۲', 'slug' => 'redmi-note-12', 'trims' => []],
            ['name' => 'ردمی نوت ۱۳', 'slug' => 'redmi-note-13', 'trims' => []],
            ['name' => 'ردمی ۱۳', 'slug' => 'redmi-13', 'trims' => []],
            ['name' => 'پوکو F5', 'slug' => 'poco-f5', 'trims' => []],
            ['name' => 'می ۱۳', 'slug' => 'mi-13', 'trims' => []],
        ]],
        ['name' => 'هواوی', 'slug' => 'huawei', 'models' => [
            ['name' => 'P30', 'slug' => 'p30', 'trims' => []],
            ['name' => 'P40', 'slug' => 'p40', 'trims' => []],
            ['name' => 'نوا ۹', 'slug' => 'nova-9', 'trims' => []],
            ['name' => 'میت ۴۰', 'slug' => 'mate-40', 'trims' => []],
        ]],
        ['name' => 'آنر', 'slug' => 'honor', 'models' => [
            ['name' => 'X8', 'slug' => 'x8', 'trims' => []],
            ['name' => 'X9', 'slug' => 'x9', 'trims' => []],
            ['name' => '۹۰', 'slug' => '90', 'trims' => []],
            ['name' => 'Magic 5', 'slug' => 'magic-5', 'trims' => []],
        ]],
        ['name' => 'وان‌پلاس', 'slug' => 'oneplus', 'models' => [
            ['name' => '9', 'slug' => 'oneplus-9', 'trims' => []],
            ['name' => '10 Pro', 'slug' => '10-pro', 'trims' => []],
            ['name' => '11', 'slug' => 'oneplus-11', 'trims' => []],
            ['name' => 'Nord 3', 'slug' => 'nord-3', 'trims' => []],
        ]],
        ['name' => 'ریلمی', 'slug' => 'realme', 'models' => [
            ['name' => '11 Pro', 'slug' => 'realme-11-pro', 'trims' => []],
            ['name' => 'C55', 'slug' => 'c55', 'trims' => []],
            ['name' => 'GT Neo 3', 'slug' => 'gt-neo-3', 'trims' => []],
        ]],
        ['name' => 'موتورولا', 'slug' => 'motorola', 'models' => [
            ['name' => 'Edge 40', 'slug' => 'edge-40', 'trims' => []],
            ['name' => 'G84', 'slug' => 'g84', 'trims' => []],
            ['name' => 'G54', 'slug' => 'g54', 'trims' => []],
        ]],
        ['name' => 'نوکیا', 'slug' => 'nokia', 'models' => [
            ['name' => '5.4', 'slug' => 'nokia-5-4', 'trims' => []],
            ['name' => 'G21', 'slug' => 'g21', 'trims' => []],
            ['name' => 'C32', 'slug' => 'c32', 'trims' => []],
        ]],
        ['name' => 'گوگل', 'slug' => 'google', 'models' => [
            ['name' => 'پیکسل ۷', 'slug' => 'pixel-7', 'trims' => []],
            ['name' => 'پیکسل ۸', 'slug' => 'pixel-8', 'trims' => []],
        ]],
        ['name' => 'سونی', 'slug' => 'sony', 'models' => [
            ['name' => 'Xperia 1', 'slug' => 'xperia-1', 'trims' => []],
            ['name' => 'Xperia 5', 'slug' => 'xperia-5', 'trims' => []],
        ]],
        ['name' => 'تکنو', 'slug' => 'tecno', 'models' => [
            ['name' => 'Camon 20', 'slug' => 'camon-20', 'trims' => []],
            ['name' => 'Spark 10', 'slug' => 'spark-10', 'trims' => []],
        ]],
        ['name' => 'اینفینیکس', 'slug' => 'infinix', 'models' => [
            ['name' => 'نوت ۳۰', 'slug' => 'note-30', 'trims' => []],
            ['name' => 'هات ۳۰', 'slug' => 'hot-30', 'trims' => []],
        ]],
    ],
];

// ── ساخت جدول در صورت نیاز (هم‌راستا با includes/catalog.php) ────────────────
function seed_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `catalog_nodes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_id` INT UNSIGNED NOT NULL,
            `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `level` ENUM('brand','model','trim') NOT NULL,
            `name` VARCHAR(160) NOT NULL,
            `slug` VARCHAR(160) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_catalog` (`category_id`, `parent_id`, `slug`),
            KEY `idx_catalog_level` (`category_id`, `level`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** درج/به‌روزرسانی یک گرهٔ کاتالوگ با اسلاگ صریح. در حالت dry-run فقط وضعیت را برمی‌گرداند. */
function seed_node(PDO $pdo, bool $apply, int $catId, int $parentId, string $level, string $name, string $slug, int $sort): string
{
    $q = $pdo->prepare('SELECT id FROM catalog_nodes WHERE category_id = ? AND parent_id = ? AND slug = ? LIMIT 1');
    $q->execute([$catId, $parentId, $slug]);
    $id = (int)($q->fetchColumn() ?: 0);
    if ($id > 0) {
        if ($apply) {
            $u = $pdo->prepare('UPDATE catalog_nodes SET name = ?, level = ?, sort_order = ?, is_active = 1 WHERE id = ?');
            $u->execute([$name, $level, $sort, $id]);
        }
        return 'update';
    }
    if ($apply) {
        $i = $pdo->prepare('INSERT INTO catalog_nodes (category_id, parent_id, level, name, slug, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $i->execute([$catId, $parentId, $level, $name, $slug, $sort]);
        return 'insert';
    }
    return 'insert';
}

echo $apply ? "حالت اعمال (--apply)\n" : "حالت آزمایشی (dry-run) — برای اعمال --apply بزنید\n";

$totals = ['insert' => 0, 'update' => 0, 'skip' => 0];

foreach ($seed as $catSlug => $brands) {
    $cq = $pdo->prepare('SELECT id FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1');
    $cq->execute([$catSlug]);
    $catId = (int)($cq->fetchColumn() ?: 0);
    if ($catId <= 0) {
        echo "دسته «{$catSlug}» پیدا نشد — رد شد.\n";
        $totals['skip']++;
        continue;
    }

    echo "دسته {$catSlug} (id={$catId}):\n";
    $brandSort = 0;
    foreach ($brands as $brand) {
        $brandSort++;
        $brandId = 0;
        if ($apply) {
            $brandId = (int)seed_node_id($pdo, $catId, 0, 'brand', (string)$brand['name'], (string)$brand['slug'], $brandSort);
        } else {
            $bq = $pdo->prepare('SELECT id FROM catalog_nodes WHERE category_id = ? AND parent_id = 0 AND slug = ? LIMIT 1');
            $bq->execute([$catId, $brand['slug']]);
            $brandId = (int)($bq->fetchColumn() ?: 0);
        }
        $action = seed_node($pdo, $apply, $catId, 0, 'brand', (string)$brand['name'], (string)$brand['slug'], $brandSort);
        $totals[$action]++;
        echo "  - برند {$brand['name']} ({$brand['slug']}) → {$action}\n";

        $modelSort = 0;
        foreach (($brand['models'] ?? []) as $model) {
            $modelSort++;
            if ($apply) {
                $modelId = (int)seed_node_id($pdo, $catId, $brandId, 'model', (string)$model['name'], (string)$model['slug'], $modelSort);
            } else {
                $mq = $pdo->prepare('SELECT id FROM catalog_nodes WHERE category_id = ? AND parent_id = ? AND slug = ? LIMIT 1');
                $mq->execute([$catId, $brandId, $model['slug']]);
                $modelId = (int)($mq->fetchColumn() ?: 0);
            }
            $mAction = seed_node($pdo, $apply, $catId, $brandId, 'model', (string)$model['name'], (string)$model['slug'], $modelSort);
            $totals[$mAction]++;
            echo "      • مدل {$model['name']} ({$model['slug']}) → {$mAction}\n";

            $trimSort = 0;
            foreach (($model['trims'] ?? []) as $trim) {
                $trimSort++;
                $tAction = seed_node($pdo, $apply, $catId, $modelId, 'trim', (string)$trim[0], (string)$trim[1], $trimSort);
                $totals[$tAction]++;
                echo "          - تیپ {$trim[0]} ({$trim[1]}) → {$tAction}\n";
            }
        }
    }
}

echo "\nنتیجه: insert={$totals['insert']}  update={$totals['update']}  دستهٔ رد‌شده={$totals['skip']}\n";

/** درج/به‌روزرسانی و بازگرداندن شناسه (فقط در حالت اعمال استفاده می‌شود). */
function seed_node_id(PDO $pdo, int $catId, int $parentId, string $level, string $name, string $slug, int $sort): int
{
    $q = $pdo->prepare('SELECT id FROM catalog_nodes WHERE category_id = ? AND parent_id = ? AND slug = ? LIMIT 1');
    $q->execute([$catId, $parentId, $slug]);
    $id = (int)($q->fetchColumn() ?: 0);
    if ($id > 0) {
        $u = $pdo->prepare('UPDATE catalog_nodes SET name = ?, level = ?, sort_order = ?, is_active = 1 WHERE id = ?');
        $u->execute([$name, $level, $sort, $id]);
        return $id;
    }
    $i = $pdo->prepare('INSERT INTO catalog_nodes (category_id, parent_id, level, name, slug, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    $i->execute([$catId, $parentId, $level, $name, $slug, $sort]);
    return (int)$pdo->lastInsertId();
}
