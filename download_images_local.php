<?php
/**
 * download_images_local.php
 *
 * اجرا در لوکال (XAMPP ویندوز) — قبل از آپلود به سرور
 * خروجی: پوشه import_cache_images/ + فایل image_manifest.json
 *
 * کاربرد:
 *   چون در سرور ایران به Unsplash دسترسی نیست، این اسکریپت همه عکس‌ها را
 *   پیشاپیش در لوکال دانلود می‌کند و در کنار هم یک مانیفست می‌سازد.
 *   بعد کلاً پوشه cache + manifest را با فایل‌های JSON و import_ads.php
 *   به سرور آپلود می‌کنیم و اجرای نهایی بدون نیاز به اینترنت سرور انجام می‌شود.
 */

$adsFile   = __DIR__ . '/ads-import.json';
$usersFile = __DIR__ . '/users-import.json';

$cacheDir  = __DIR__ . '/import_cache_images';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$manifestFile = __DIR__ . '/image_manifest.json';

echo "┌─────────────────────────────────────────────────────┐\n";
echo "│ دانلود پیشنهادی عکس‌ها در لوکال (قبل از سرور)        │\n";
echo "└─────────────────────────────────────────────────────┘\n\n";

if (!file_exists($adsFile))   die("❌ ads-import.json پیدا نشد\n");
if (!file_exists($usersFile)) die("❌ users-import.json پیدا نشد\n");

$ads   = json_decode(file_get_contents($adsFile),   true);
$users = json_decode(file_get_contents($usersFile), true);
if (!is_array($ads) || !is_array($users)) die("❌ ساختار JSON معتبر نیست\n");

echo "📋 " . count($ads) . " آگهی | " . count($users) . " کاربر\n\n";

$manifest = [
    'ads'   => [],
    'users' => [],
];

$ok = 0;
$fail = 0;

echo "━━━ دانلود عکس‌های آگهی‌ها ━━━\n";
foreach ($ads as $idx => $ad) {
    $images = $ad['images'] ?? [];
    if (!is_array($images) || empty($images)) {
        $manifest['ads'][$idx] = [];
        echo "  [" . ($idx + 1) . "] عکسی ثبت نشده — رد\n";
        continue;
    }
    $saved = [];
    foreach ($images as $i => $img) {
        $url = trim($img, " \t\n\r\0\x0B`\"'");
        if (!$url) continue;

        $targetName = 'listing_' . $idx . '_' . $i . '.' . detectExt($url);
        $targetPath = $cacheDir . '/' . $targetName;

        if (file_exists($targetPath) && filesize($targetPath) > 1000) {
            $saved[] = $targetName;
            $ok++;
            echo "  [" . ($idx + 1) . "][$i] کَش موجود: $targetName\n";
            continue;
        }

        $data = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $data = fetchUrl($url);
            if ($data && strlen($data) > 1000) break;
            if ($attempt < 3) {
                echo "  [" . ($idx + 1) . "][$i] تلاش $attempt ناموفق — تلاش مجدد...\n";
                usleep(500000);
            }
        }

        if ($data && strlen($data) > 1000) {
            file_put_contents($targetPath, $data);
            $saved[] = $targetName;
            $ok++;
            echo "  [" . ($idx + 1) . "][$i] ✓ دانلود شد: $targetName (" . round(strlen($data) / 1024, 1) . "KB)\n";
        } else {
            $fail++;
            echo "  [" . ($idx + 1) . "][$i] ✗ دانلود ناموفق (آدرس ممکن است 404 باشد)\n";
        }
    }
    $manifest['ads'][$idx] = $saved;
}
echo "\n";

echo "━━━ آواتار کاربران ━━━\n";
echo "  ℹ️ طبق تنظیمات فعلی، آواتار دانلود نمی‌شود.\n\n";
foreach ($users as $idx => $u) {
    $manifest['users'][$idx] = '';
}

file_put_contents(
    $manifestFile,
    json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

echo "━━━ نتیجه ━━━\n";
echo "  موفق  : $ok\n";
echo "  ناموفق: $fail\n";
echo "  پوشه کَش: $cacheDir\n";
echo "  مانیفست: $manifestFile\n";
echo "\n✅ آماده آپلود به سرور:\n";
echo "   1. کل پوشه import_cache_images/\n";
echo "   2. فایل  image_manifest.json\n";
echo "   3. فایل  users-import.json\n";
echo "   4. فایل  ads-import.json\n";
echo "   5. فایل  import_ads.php\n";
echo "\n سپس در سرور اجرا کن:  php import_ads.php --use-manifest\n";


function detectExt(string $url): string {
    $path = parse_url($url, PHP_URL_PATH);
    $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
    if (in_array($ext, ['jpg','jpeg','png','webp','gif','svg'], true)) {
        return ($ext === 'jpeg') ? 'jpg' : $ext;
    }
    return 'jpg';
}

function fetchUrl(string $url): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,fa;q=0.8',
            'Referer: https://unsplash.com/',
            'Sec-Fetch-Dest: image',
            'Sec-Fetch-Mode: no-cors',
            'Sec-Fetch-Site: cross-site',
        ],
    ]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($out === false || $code < 200 || $code >= 400) return false;
    return $out;
}
