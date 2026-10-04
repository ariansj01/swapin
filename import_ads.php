<?php
define('SKIP_SESSION', true);
define('CLI_MODE', true);

require_once __DIR__ . '/includes/config.php';

$useManifest   = false;
$manifestData  = null;
$cacheDir      = __DIR__ . '/import_cache_images';
$manifestFile  = __DIR__ . '/image_manifest.json';

$usersFile = __DIR__ . '/users-import.json';
$adsFile   = __DIR__ . '/ads-import.json';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--use-manifest') {
        $useManifest = true;
    } elseif (file_exists($arg) && pathinfo($arg, PATHINFO_EXTENSION) === 'json') {
        if (str_contains(basename($arg), 'user'))  $usersFile = $arg;
        elseif (str_contains(basename($arg), 'ads'))  $adsFile = $arg;
    }
}

if ($useManifest) {
    if (!file_exists($manifestFile)) {
        die("❌ image_manifest.json پیدا نشد. ابتدا در لوکال download_images_local.php را اجرا کن.\n");
    }
    if (!is_dir($cacheDir)) {
        die("❌ پوشه import_cache پیدا نشد: $cacheDir\n");
    }
    $manifestData = json_decode(file_get_contents($manifestFile), true);
    if (!is_array($manifestData)) {
        die("❌ ساختار image_manifest.json معتبر نیست\n");
    }
    echo "ℹ️ حالت مانیفست فعال شد — استفاده از کش محلی عکس‌ها\n\n";
}

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║         سواپین — ابزار وارد کردن کاربران و آگهی‌ها        ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

if (!file_exists($usersFile)) {
    die("❌ فایل کاربران پیدا نشد: $usersFile\n");
}
if (!file_exists($adsFile)) {
    die("❌ فایل آگهی‌ها پیدا نشد: $adsFile\n");
}

$usersRaw = json_decode(file_get_contents($usersFile), true);
$adsRaw   = json_decode(file_get_contents($adsFile), true);

if (!is_array($usersRaw)) die("❌ ساختار users-import.json نامعتبر است\n");
if (!is_array($adsRaw))   die("❌ ساختار ads-import.json نامعتبر است\n");

echo "📄 فایل‌ها خوانده شدند. " . count($usersRaw) . " کاربر و " . count($adsRaw) . " آگهی\n\n";

// ------------------------------------------------------------------
// مرحله ۱: وارد کردن کاربران
// ------------------------------------------------------------------
echo "━━━ مرحله ۱: وارد کردن کاربران ━━━\n";
$userIdMap = [];

// کاربران موجود قبلی: 2,3,4 را همان ID نگه می‌داریم
$userIdMap[2] = 2;
$userIdMap[3] = 3;
$userIdMap[4] = 4;

// کاربران جدید: user_id در JSON از 5 تا 14 هستند
// بعد از درج، ID واقعی را نگاشت می‌کنیم
$jsonUserStartId = 5;

$pdo = DB::pdo();
$pdo->beginTransaction();

try {
    foreach ($usersRaw as $idx => $u) {
        $jsonUserId = $jsonUserStartId + $idx;

        $email = trim($u['email'] ?? '');
        $phone = trim($u['phone'] ?? '');

        if (!$email || !$phone) {
            echo "  ⚠️ ردیف " . ($idx + 1) . ": ایمیل یا موبایل خالی است — رد شد\n";
            continue;
        }

        // وجود کاربر با همین ایمیل یا موبایل
        $existing = DB::fetch(
            "SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1",
            [$email, $phone]
        );

        if ($existing) {
            $userIdMap[$jsonUserId] = (int)$existing['id'];
            echo "  ✅ کاربر موجود: {$u['name']} (ID: {$existing['id']})\n";
            continue;
        }

        $avatarLocal = '';
        if (!empty($u['avatar'])) {
            if ($useManifest && isset($manifestData['users'][$idx]) && $manifestData['users'][$idx]) {
                $cachedFile = $cacheDir . '/' . $manifestData['users'][$idx];
                if (file_exists($cachedFile)) {
                    $avatarLocal = importCachedFile($cachedFile, 'avatars');
                }
            }
            if (!$avatarLocal) {
                $avatarLocal = downloadExternalImage($u['avatar'], 'avatars');
            }
        }

        $sellerType = $u['seller_type'] ?? 'personal';
        $role       = $u['role'] ?? 'user';

        $insertData = [
            'name'              => $u['name'] ?? 'کاربر',
            'email'             => $email,
            'phone'             => $phone,
            'city'              => $u['city'] ?? null,
            'avatar'            => $avatarLocal ?: null,
            'bio'               => $u['bio'] ?? null,
            'seller_type'       => $sellerType,
            'store_name'        => $sellerType === 'store' ? ($u['store_name'] ?? null) : null,
            'password_hash'     => password_hash($u['password'] ?? 'Pass@1234', PASSWORD_BCRYPT),
            'credit_balance'    => rand(50000, 500000) / 100,
            'rating'            => 0.00,
            'rating_count'      => 0,
            'verification_level'=> 2,
            'is_active'         => 1,
            'role'              => $role,
            'kyc_status'        => 'none',
            'subscription_plan' => 'none',
        ];

        $newId = DB::insert('users', $insertData);
        $userIdMap[$jsonUserId] = $newId;
        echo "  ➕ کاربر جدید: {$u['name']} (ID جدید: $newId)\n";
    }

    echo "\n✅ کاربران با موفقیت وارد شدند. نگاشت IDها:\n";
    foreach ($userIdMap as $json => $real) {
        if ($json !== $real) echo "   json#$json → DB#$real\n";
    }
    echo "\n";

    // ------------------------------------------------------------------
    // مرحله ۲: وارد کردن آگهی‌ها (listings)
    // ------------------------------------------------------------------
    echo "━━━ مرحله ۲: وارد کردن آگهی‌ها ━━━\n";

    $listingsDir = UPLOAD_DIR . '/listings';
    if (!is_dir($listingsDir)) @mkdir($listingsDir, 0755, true);

    $importedCount = 0;
    $imageCount    = 0;
    $skipCount     = 0;

    $validConditions = ['new','like_new','good','fair','poor'];
    $validModes      = ['swap','sell','both'];
    $validWantTypes  = ['item','service','credit','any'];

    foreach ($adsRaw as $idx => $ad) {
        $line = $idx + 1;

        // اعتبارسنجی اصلی
        $errors = [];
        if (empty($ad['title']))         $errors[] = 'title خالی';
        if (empty($ad['description']))   $errors[] = 'description خالی';
        if (empty($ad['category_id']))   $errors[] = 'category_id خالی';

        $jsonUserId = (int)($ad['user_id'] ?? 2);
        if (!isset($userIdMap[$jsonUserId])) {
            $errors[] = "user_id=$jsonUserId در نگاشت پیدا نشد";
        }

        if ($errors) {
            echo "  ❌ آگهی #$line: " . implode(' | ', $errors) . " — رد شد\n";
            $skipCount++;
            continue;
        }

        $categoryId = (int)$ad['category_id'];
        $catExists = DB::fetch("SELECT id FROM categories WHERE id = ?", [$categoryId]);
        if (!$catExists) {
            echo "  ❌ آگهی #$line: دسته category_id=$categoryId وجود ندارد — رد شد\n";
            $skipCount++;
            continue;
        }

        $condition = $ad['condition'] ?? 'good';
        if (!in_array($condition, $validConditions, true)) $condition = 'good';

        $mode = $ad['listing_mode'] ?? 'swap';
        if (!in_array($mode, $validModes, true)) $mode = 'swap';

        $wantType = $ad['want_type'] ?? 'any';
        if (!in_array($wantType, $validWantTypes, true)) $wantType = 'any';

        $sellPrice     = (int)($ad['sell_price'] ?? 0);
        $estimatedVal  = (float)($ad['estimated_value'] ?? $sellPrice);
        $wantInReturn  = $ad['want_in_return'] ?? '';
        if ($mode === 'sell') { $wantType = 'credit'; $wantInReturn = ''; }

        $listingData = [
            'user_id'         => $userIdMap[$jsonUserId],
            'category_id'     => $categoryId,
            'title'           => mb_substr(trim($ad['title']), 0, 200),
            'description'     => trim($ad['description']),
            'condition'       => $condition,
            'estimated_value' => $estimatedVal,
            'want_in_return'  => $wantInReturn,
            'want_type'       => $wantType,
            'listing_mode'    => $mode,
            'sell_price'      => $sellPrice,
            'needs_inspection'=> 0,
            'inspection_status' => 'none',
            'city'            => $ad['city'] ?? null,
            'status'          => 'active',
            'review_status'   => 'approved',
            'is_featured'     => $sellPrice >= 100000000 ? 1 : 0,
            'views'           => rand(10, 5000),
        ];

        $listingId = DB::insert('listings', $listingData);

        // دانلود و ذخیره تصاویر
        $listingImgDir = $listingsDir . '/' . $listingId;
        if (!is_dir($listingImgDir)) @mkdir($listingImgDir, 0755, true);

        $images = $ad['images'] ?? [];
        if (is_array($images)) {
            $sort = 0;
            foreach ($images as $imgIdx => $imgUrl) {
                $url = trim($imgUrl, " \t\n\r\0\x0B`\"'");
                if (!$url) continue;

                $savedAs = '';
                if ($useManifest && isset($manifestData['ads'][$idx][$imgIdx]) && $manifestData['ads'][$idx][$imgIdx]) {
                    $cachedFile = $cacheDir . '/' . $manifestData['ads'][$idx][$imgIdx];
                    if (file_exists($cachedFile)) {
                        $savedAs = importCachedFile($cachedFile, "listings/$listingId");
                    }
                }
                if (!$savedAs) {
                    $savedAs = downloadExternalImage($url, "listings/$listingId");
                }

                if ($savedAs) {
                    DB::insert('listing_images', [
                        'listing_id' => $listingId,
                        'filename'   => $savedAs,
                        'is_primary' => $sort === 0 ? 1 : 0,
                        'sort_order' => $sort,
                    ]);
                    $sort++;
                    $imageCount++;
                }
            }
        }

        $importedCount++;
        echo "  ✅ آگهی #$line: {$listingData['title']} (ID: $listingId | " . (is_countable($images) ? count($images) : 0) . " عکس)\n";
    }

    $pdo->commit();

    echo "\n━━━ نتیجه نهایی ━━━\n";
    echo "  کاربران وارد شده  : " . (count($userIdMap) - 3) . " نفر جدید\n";
    echo "  آگهی‌های موفق    : $importedCount\n";
    echo "  تصاویر ذخیره شده: $imageCount\n";
    echo "  موارد رد شده     : $skipCount\n";
    echo "\n🎉 تمام مراحل با موفقیت انجام شد.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌ خطا هنگام وارد کردن: " . $e->getMessage() . "\n";
    echo "   در فایل: " . $e->getFile() . " خط " . $e->getLine() . "\n";
    exit(1);
}

// ------------------------------------------------------------------
// تابع کمکی: دانلود تصویر خارجی و ذخیره در پوشه uploads
// ------------------------------------------------------------------
function downloadExternalImage(string $url, string $subDir): string {
    $baseDir = UPLOAD_DIR;
    $targetDir = $baseDir . '/' . rtrim($subDir, '/');
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    if (!is_dir($targetDir) || !is_writable($targetDir)) return '';

    $ext = 'jpg';
    $path = parse_url($url, PHP_URL_PATH);
    if ($path) {
        $e = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($e, ['jpg','jpeg','png','webp','gif'], true)) $ext = ($e === 'jpeg') ? 'jpg' : $e;
    }

    $fname = 'imp_' . substr(md5($url . microtime(true)), 0, 12) . '.' . $ext;
    $fullPath = $targetDir . '/' . $fname;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $imgData = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    if ($imgData === false || $code < 200 || $code >= 400) {
        // دانلود نشد، به‌عنوان پشتیبان URL را کپی می‌کنیم ولی رندر برای آپلود صحیح نیست
        // در عوض، یک فایل پلیس‌هلدر مربعی آبی بساز
        return createPlaceholderImage($targetDir, $fname);
    }

    $size = @file_put_contents($fullPath, $imgData);
    if (!$size || $size < 500) {
        return createPlaceholderImage($targetDir, $fname);
    }

    $relPath = rtrim($subDir, '/') . '/' . $fname;
    return $relPath;
}

function createPlaceholderImage(string $dir, string $fname): string {
    $fullPath = $dir . '/' . $fname;
    $w = 800; $h = 600;
    $img = @imagecreatetruecolor($w, $h);
    if (!$img) return '';
    $bg = imagecolorallocate($img, 0, 102, 255);
    $fg = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $bg);
    $text = 'Swaapin';
    $font = 5;
    $tw = imagefontwidth($font) * strlen($text);
    imagestring($img, $font, (int)(($w - $tw) / 2), (int)($h / 2) - 10, $text, $fg);
    $brand = 'Placeholder';
    $tw2 = imagefontwidth($font) * strlen($brand);
    imagestring($img, $font, (int)(($w - $tw2) / 2), (int)($h / 2) + 10, $brand, imagecolorallocate($img, 255, 200, 200));
    imagejpeg($img, $fullPath, 85);
    imagedestroy($img);
    $sub = str_replace(UPLOAD_DIR . '/', '', $dir);
    return $sub . '/' . $fname;
}

function importCachedFile(string $sourcePath, string $subDir): string {
    $baseDir = UPLOAD_DIR;
    $targetDir = $baseDir . '/' . rtrim($subDir, '/');
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    if (!is_dir($targetDir) || !is_writable($targetDir)) return '';

    $sourceSize = @filesize($sourcePath);
    if (!$sourceSize || $sourceSize < 300) return '';

    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) $ext = 'jpg';

    $fname = 'imp_' . substr(md5($sourcePath . microtime(true) . rand()), 0, 12) . '.' . $ext;
    $fullPath = $targetDir . '/' . $fname;

    if (!@copy($sourcePath, $fullPath)) {
        return '';
    }

    $finalSize = @filesize($fullPath);
    if (!$finalSize || $finalSize < 300) {
        @unlink($fullPath);
        return '';
    }

    $relPath = rtrim($subDir, '/') . '/' . $fname;
    return $relPath;
}
