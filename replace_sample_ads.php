<?php
define('SKIP_SESSION', true);
define('CLI_MODE', true);

require_once __DIR__ . '/includes/config.php';

$args = array_slice($argv, 1);
$dryRun      = in_array('--dry-run', $args, true);
$useManifest = in_array('--use-manifest', $args, true);
$yesFlag     = in_array('--yes', $args, true) || in_array('-y', $args, true);

$usersFile = __DIR__ . '/users-import.json';
$adsFile   = __DIR__ . '/ads-import.json';

$manifestFile     = __DIR__ . '/image_manifest.json';
$manifestCacheDir = __DIR__ . '/import_cache_images';
$manifestData     = null;

if ($useManifest) {
    if (!file_exists($manifestFile)) {
        die("❌ --use-manifest فعال ولی image_manifest.json پیدا نشد\n");
    }
    if (!is_dir($manifestCacheDir)) {
        die("❌ --use-manifest فعال ولی پوشه import_cache_images/ وجود ندارد\n");
    }
    $manifestData = json_decode(file_get_contents($manifestFile), true);
    if (!is_array($manifestData)) die("❌ ساختار manifest نامعتبر\n");
    echo "🗂️  حالت Manifest فعال شد. " . count($manifestData) . " تصویر در کش.\n";
}

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║     سواپین — جایگزینی فقط آگهی‌های نمونه (بدون لمس کاربران) ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
if ($dryRun) echo "\n⚠️  حالت --dry-run فعال است؛ هیچ تغییری در دیتابیس اعمال نمی‌شود.\n\n";

if (!file_exists($usersFile)) die("❌ users-import.json پیدا نشد\n");
if (!file_exists($adsFile))   die("❌ ads-import.json پیدا نشد\n");

$usersRaw = json_decode(file_get_contents($usersFile), true);
$adsRaw   = json_decode(file_get_contents($adsFile), true);
if (!is_array($usersRaw) || !is_array($adsRaw)) die("❌ ساختار JSON نامعتبر\n");

echo "📄 JSON خوانده شد: " . count($usersRaw) . " کاربر نمونه ، " . count($adsRaw) . " آگهی\n\n";

// ------------------------------------------------------------------
// ۱) شناسایی «کاربران نمونه» بر اساس ایمیل‌های موجود در users-import.json
//    + سه کاربر ثابت 2,3,4 (که در import_ads.php هم هاردکد هستند)
// ------------------------------------------------------------------
echo "━━━ مرحله ۱: شناسایی کاربران نمونه در دیتابیس ━━━\n";

$sampleEmails = [];
foreach ($usersRaw as $u) {
    $e = trim($u['email'] ?? '');
    if ($e) $sampleEmails[] = $e;
}
$sampleEmails = array_unique($sampleEmails);

$pdo = DB::pdo();

// سه کاربر ثابت 2,3,4 رو جداگانه هم در نظر می‌گیریم (اون‌هایی که در import_ads.php خطوط 64-67 هاردکد شده)
$hardcodedSampleIds = [2, 3, 4];

$placeholders = implode(',', array_fill(0, count($sampleEmails), '?'));
$emailRows = DB::fetchAll(
    "SELECT id, email, name, phone FROM users WHERE email IN ($placeholders)",
    $sampleEmails
);

$sampleUserIds = [];
foreach ($hardcodedSampleIds as $hid) {
    $u = DB::fetch("SELECT id, email, name, phone FROM users WHERE id = ?", [$hid]);
    if ($u) {
        $sampleUserIds[(int)$u['id']] = ['src'=>'hardcoded#'.$hid, 'name'=>$u['name'], 'email'=>$u['email'], 'phone'=>$u['phone']];
    }
}
foreach ($emailRows as $u) {
    $sampleUserIds[(int)$u['id']] = ['src'=>'users-import', 'name'=>$u['name'], 'email'=>$u['email'], 'phone'=>$u['phone']];
}
ksort($sampleUserIds);

echo "  کل کاربران نمونه شناسایی‌شده در DB: " . count($sampleUserIds) . "\n";
foreach ($sampleUserIds as $uid => $info) {
    echo "    DB#$uid  [{$info['src']}]  {$info['name']}  <{$info['email']}>  {$info['phone']}\n";
}
echo "\n";

if (count($sampleUserIds) === 0) {
    die("❌ هیچ کاربر نمونه‌ای در DB پیدا نشد — اجرا متوقف شد تا از حذف تصادفی جلوگیری شود.\n");
}

// ------------------------------------------------------------------
// ۲) پیدا کردن آگهی‌های متعلق به همین کاربران نمونه (و فقط همین‌ها)
// ------------------------------------------------------------------
echo "━━━ مرحله ۲: پیدا کردن آگهی‌های متعلق به کاربران نمونه ━━━\n";

$ids = array_keys($sampleUserIds);
$ph  = implode(',', array_fill(0, count($ids), '?'));

$statsQ = DB::fetch(
    "SELECT COUNT(*) AS c FROM listings WHERE user_id IN ($ph)",
    $ids
);
$sampleListingCount = (int)($statsQ['c'] ?? 0);

$allListings = DB::fetch("SELECT COUNT(*) AS c FROM listings");
$allCount = (int)($allListings['c'] ?? 0);
$safeCount = $allCount - $sampleListingCount;

echo "  کل آگهی‌ها در دیتابیس      : $allCount\n";
echo "  آگهی‌های کاربران نمونه      : $sampleListingCount  (این‌ها حذف خواهند شد)\n";
echo "  آگهی‌های سایر کاربران (مصون): $safeCount  (این‌ها اصلاً لمس نمی‌شوند)\n\n";

if ($sampleListingCount === 0) {
    echo "  ℹ️  هیچ آگهی نمونه‌ای برای حذف وجود ندارد — مستقیم وارد کردن جدید شروع می‌شود.\n\n";
    $toDelete = [];
} else {
    $previews = DB::fetchAll(
        "SELECT id, user_id, category_id, LEFT(title,40) AS title, sell_price, created_at
         FROM listings WHERE user_id IN ($ph) ORDER BY id DESC LIMIT 15",
        $ids
    );
    echo "  نمونه آخرین ۱۵ آگهی‌ای که قرار است حذف شود (فقط کاربران نمونه):\n";
    foreach ($previews as $p) {
        echo "    listing#{$p['id']}  user#{$p['user_id']}  cat#{$p['category_id']}  " .
             "{$p['title']}  (" . number_format((int)$p['sell_price']) . " تومان)\n";
    }
    echo "    ...\n\n";

    $toDelete = DB::fetchAll("SELECT id FROM listings WHERE user_id IN ($ph)", $ids);
    $toDelete = array_map(function($r){ return (int)$r['id']; }, $toDelete);
}

// ------------------------------------------------------------------
// ۳) تأیید نهایی کاربر (مگر --yes/--dry-run)
// ------------------------------------------------------------------
if (!$dryRun && !$yesFlag) {
    echo "⚠️  در صورت تایید، $sampleListingCount آگهی کاربر نمونه (همراه با تصاویر و پلن‌های تبلیغاتی‌شان) حذف شده\n";
    echo "    و " . count($adsRaw) . " آگهی جدید وارد می‌شود. آگهی‌های کاربران واقعی (تعداد $safeCount) کاملاً دست‌نخورده باقی می‌مانند.\n";
    echo "آیا مطمئن هستید؟ (yes / no): ";
    $handle = fopen('php://stdin', 'r');
    $line = trim(fgets($handle));
    fclose($handle);
    if (strtolower($line) !== 'yes' && strtolower($line) !== 'y') {
        die("❌ کاربر لغو کرد — هیچ تغییری اعمال نشد.\n");
    }
    echo "\n";
}

if ($dryRun) {
    echo "✅ dry-run پایان یافت. هیچ تغییری در دیتابیس و فایل‌ها اعمال نشد.\n";
    echo "   برای اجرای واقعی:  php replace_sample_ads.php [--use-manifest] [--yes]\n";
    exit(0);
}

// ------------------------------------------------------------------
// ۴) تراکنش: حذف + وارد کردن جدید
// ------------------------------------------------------------------
echo "━━━ مرحله ۳: حذف آگهی‌های قدیمی نمونه و وارده کردن جدید (تراکنش) ━━━\n";

$pdo->beginTransaction();

try {
    // ۴-الف) حذف وابسته‌ها و خود آگهی‌ها (چون ممکنه FK ها RESTRICT باشه)
    if (count($toDelete) > 0) {
        $phDel = implode(',', array_fill(0, count($toDelete), '?'));
        $deletedImages = DB::query(
            "DELETE FROM listing_images WHERE listing_id IN ($phDel)",
            $toDelete
        )->rowCount();
        $deletedPromos = DB::query(
            "DELETE FROM listing_promotions WHERE listing_id IN ($phDel)",
            $toDelete
        )->rowCount();
        $deletedListings = DB::query(
            "DELETE FROM listings WHERE id IN ($phDel)",
            $toDelete
        )->rowCount();
        echo "  🗑️  حذف شد:  $deletedListings listing ، $deletedImages تصویر ، $deletedPromos پلن تبلیغاتی\n";
    } else {
        echo "  ℹ️  آگهی قدیمی برای حذف نبود.\n";
    }

    // ۴-ب) وارد کردن کاربران (مطابق منطق import_ads.php — فقط کاربر جدید درج می‌شود؛ موجود هم نگاشت می‌شود)
    echo "\n━━━ مرحله ۴: نگاشت کاربران نمونه (وارد کردن اگر موجود نباشند) ━━━\n";
    $userIdMap = [];
    $userIdMap[2] = 2;
    $userIdMap[3] = 3;
    $userIdMap[4] = 4;
    $jsonUserStartId = 5;

    foreach ($usersRaw as $idx => $u) {
        $jsonUserId = $jsonUserStartId + $idx;
        $email = trim($u['email'] ?? '');
        $phone = trim($u['phone'] ?? '');
        if (!$email || !$phone) {
            echo "  ⚠️  کاربر ردیف " . ($idx+1) . ": ایمیل/موبایل خالی — رد شد\n";
            continue;
        }
        $existing = DB::fetch(
            "SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1",
            [$email, $phone]
        );
        if ($existing) {
            $userIdMap[$jsonUserId] = (int)$existing['id'];
            echo "  ✅ کاربر موجود: {$u['name']}  json#$jsonUserId → DB#{$existing['id']}\n";
            continue;
        }

        $avatarLocal = '';
        if (!empty($u['avatar'])) {
            global $useManifest, $manifestData;
            if ($useManifest && isset($manifestData['users'][$idx])) {
                $avatarLocal = copyFromManifest($manifestData['users'][$idx], 'avatars');
            }
            if (!$avatarLocal) {
                $avatarLocal = downloadExternalImage($u['avatar'], 'avatars');
            }
        }
        $sellerType = $u['seller_type'] ?? 'personal';
        $insertData = [
            'name'               => $u['name'] ?? 'کاربر',
            'email'              => $email,
            'phone'              => $phone,
            'city'               => $u['city'] ?? null,
            'avatar'             => $avatarLocal ?: null,
            'bio'                => $u['bio'] ?? null,
            'seller_type'        => $sellerType,
            'store_name'         => $sellerType === 'store' ? ($u['store_name'] ?? null) : null,
            'password_hash'      => password_hash($u['password'] ?? 'Pass@1234', PASSWORD_BCRYPT),
            'credit_balance'     => rand(50000, 500000) / 100,
            'rating'             => 0.00,
            'rating_count'       => 0,
            'verification_level' => 2,
            'is_active'          => 1,
            'role'               => $u['role'] ?? 'user',
            'kyc_status'         => 'none',
            'subscription_plan'  => 'none',
        ];
        $newId = DB::insert('users', $insertData);
        $userIdMap[$jsonUserId] = $newId;
        echo "  ➕ کاربر جدید: {$u['name']}  json#$jsonUserId → DB#$newId\n";
    }

    // ۴-ج) وارد کردن 205 آگهی جدید
    echo "\n━━━ مرحله ۵: وارد کردن " . count($adsRaw) . " آگهی جدید ━━━\n";

    $listingsDir = UPLOAD_DIR . '/listings';
    if (!is_dir($listingsDir)) @mkdir($listingsDir, 0755, true);

    $importedCount = 0;
    $imageCount    = 0;
    $skipCount     = 0;
    $promoCount    = 0;

    $validConditions = ['new','like_new','good','fair','poor'];
    $validModes      = ['swap','sell','both'];
    $validWantTypes  = ['item','service','credit','any'];

    foreach ($adsRaw as $idx => $ad) {
        $line = $idx + 1;
        $errors = [];
        if (empty($ad['title']))       $errors[] = 'title خالی';
        if (empty($ad['description'])) $errors[] = 'description خالی';
        if (empty($ad['category_id'])) $errors[] = 'category_id خالی';
        $jsonUserId = (int)($ad['user_id'] ?? 2);
        if (!isset($userIdMap[$jsonUserId])) $errors[] = "user_id=$jsonUserId نامعتبر";
        if ($errors) {
            echo "  ❌ آگهی #$line: " . implode(' | ', $errors) . " — رد شد\n";
            $skipCount++;
            continue;
        }
        $categoryId = (int)$ad['category_id'];
        $catExists = DB::fetch("SELECT id FROM categories WHERE id = ?", [$categoryId]);
        if (!$catExists) {
            echo "  ❌ آگهی #$line: دسته $categoryId وجود ندارد — رد شد\n";
            $skipCount++;
            continue;
        }

        $condition = in_array($ad['condition'] ?? 'good', $validConditions, true) ? $ad['condition'] : 'good';
        $mode      = in_array($ad['listing_mode'] ?? 'swap', $validModes, true)      ? $ad['listing_mode'] : 'swap';
        $wantType  = in_array($ad['want_type'] ?? 'any', $validWantTypes, true)      ? $ad['want_type'] : 'any';
        $sellPrice     = (int)($ad['sell_price'] ?? 0);
        $estimatedVal  = (float)($ad['estimated_value'] ?? $sellPrice);
        $wantInReturn  = $ad['want_in_return'] ?? '';
        if ($mode === 'sell') { $wantType = 'credit'; $wantInReturn = ''; }

        $listingData = [
            'user_id'           => $userIdMap[$jsonUserId],
            'category_id'       => $categoryId,
            'title'             => mb_substr(trim($ad['title']), 0, 200),
            'description'       => trim($ad['description']),
            'condition'         => $condition,
            'estimated_value'   => $estimatedVal,
            'want_in_return'    => $wantInReturn,
            'want_type'         => $wantType,
            'listing_mode'      => $mode,
            'sell_price'        => $sellPrice,
            'needs_inspection'  => 0,
            'inspection_status' => 'none',
            'city'              => $ad['city'] ?? null,
            'status'            => 'active',
            'review_status'     => 'approved',
            'is_featured'       => $sellPrice >= 100000000 ? 1 : 0,
            'views'             => rand(10, 5000),
        ];
        $listingId = DB::insert('listings', $listingData);

        $listingImgDir = $listingsDir . '/' . $listingId;
        if (!is_dir($listingImgDir)) @mkdir($listingImgDir, 0755, true);

        $images = $ad['images'] ?? [];
        if (is_array($images)) {
            $sort = 0;
            foreach ($images as $imgIdx => $imgUrl) {
                $url = trim($imgUrl, " \t\n\r\0\x0B`\"'");
                if (!$url) continue;
                global $useManifest, $manifestData;
                $savedAs = '';
                if ($useManifest && isset($manifestData['ads'][$line][$imgIdx])) {
                    $savedAs = copyFromManifest($manifestData['ads'][$line][$imgIdx], "listings/$listingId");
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
        echo "  ✅ #$line: {$listingData['title']}  (ID:$listingId | " . (is_countable($images)?count($images):0) . " عکس)\n";

        $promoPlan = $ad['promotion_plan'] ?? $ad['subscription_plan'] ?? null;
        if ($promoPlan) {
            $validPromos = ['boost','featured','vip','targeted','ai','gold'];
            if (in_array($promoPlan, $validPromos, true)) {
                $hours = (int)($ad['promotion_hours'] ?? (30 * 24));
                if ($hours < 1) $hours = 24;
                if ($hours > 365 * 24) $hours = 365 * 24;
                $startsAt = date('Y-m-d H:i:s');
                $endsAt   = date('Y-m-d H:i:s', time() + ($hours * 3600));
                try {
                    DB::insert('listing_promotions', [
                        'listing_id'  => $listingId,
                        'user_id'     => $userIdMap[$jsonUserId],
                        'plan'        => $promoPlan,
                        'starts_at'   => $startsAt,
                        'ends_at'     => $endsAt,
                        'amount_paid' => 0,
                    ]);
                    $promoUpdate = [];
                    switch ($promoPlan) {
                        case 'boost':    $promoUpdate['bump_until'] = $endsAt; break;
                        case 'featured': $promoUpdate['featured_until'] = $endsAt; $promoUpdate['is_featured'] = 1; break;
                        case 'vip':      $promoUpdate['featured_until'] = $endsAt; $promoUpdate['vip_until'] = $endsAt; $promoUpdate['is_featured'] = 1; break;
                        case 'targeted': $promoUpdate['targeted_until'] = $endsAt; break;
                        case 'ai':       $promoUpdate['ai_promo_until'] = $endsAt; break;
                        case 'gold':
                            $promoUpdate['bump_until']=$endsAt; $promoUpdate['featured_until']=$endsAt;
                            $promoUpdate['vip_until']=$endsAt;  $promoUpdate['targeted_until']=$endsAt;
                            $promoUpdate['ai_promo_until']=$endsAt; $promoUpdate['is_featured']=1; break;
                    }
                    if ($promoUpdate) {
                        $pu = db_filter_row('listings', $promoUpdate);
                        if ($pu) DB::update('listings', $pu, 'id = ?', [$listingId]);
                    }
                    $promoCount++;
                    echo "      🏷️  پلن $promoPlan تا $endsAt\n";
                } catch (Throwable $e) {
                    echo "      ⚠️  خطا در پلن: ".$e->getMessage()."\n";
                }
            }
        }
    }

    $pdo->commit();

    echo "\n━━━ نتیجه نهایی ━━━\n";
    echo "  🗑️  حذف قدیمی‌ها    : " . count($toDelete) . " آگهی نمونه\n";
    echo "  ➕ آگهی‌های جدید    : $importedCount\n";
    echo "  🖼️  تصاویر ذخیره   : $imageCount\n";
    echo "  🏷️  پلن‌های فعال    : $promoCount\n";
    echo "  ⛔ موارد رد شده    : $skipCount\n";

    // جمع‌بندی نهایی و تأیید مجدد تمیز نبودن بقیه کاربران
    $totalAfter = (int)(DB::fetch("SELECT COUNT(*) AS c FROM listings")['c'] ?? 0);
    $belongsSampleAfter = (int)(DB::fetch(
        "SELECT COUNT(*) AS c FROM listings WHERE user_id IN ($ph)",
        $ids
    )['c'] ?? 0);
    echo "\n📊 جمع‌بندی نهایی دیتابیس:\n";
    echo "  کل listings            : $totalAfter\n";
    echo "  متعلق به کاربران نمونه : $belongsSampleAfter\n";
    echo "  متعلق به سایر کاربران  : " . ($totalAfter - $belongsSampleAfter) . " (دست‌نخورده)\n";
    echo "\n🎉 تمام مراحل با موفقیت انجام شد.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌ تراکنش به‌صورت کامل رول‌بک شد — هیچ تغییری اعمال نشد.\n";
    echo "   علت: " . $e->getMessage() . "\n";
    echo "   در " . $e->getFile() . " خط " . $e->getLine() . "\n";
    exit(1);
}

// ---------------- توابع کمکی ----------------
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
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $imgData = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($imgData === false || $code < 200 || $code >= 400) return createPlaceholderImage($targetDir, $fname);
    $size = @file_put_contents($fullPath, $imgData);
    if (!$size || $size < 500) return createPlaceholderImage($targetDir, $fname);
    return rtrim($subDir, '/') . '/' . $fname;
}
function createPlaceholderImage(string $dir, string $fname): string {
    $fullPath = $dir . '/' . $fname;
    $w=800; $h=600;
    $img = @imagecreatetruecolor($w,$h); if (!$img) return '';
    $bg = imagecolorallocate($img,0,102,255); $fg=imagecolorallocate($img,255,255,255);
    imagefill($img,0,0,$bg);
    $text='Swaapin'; $font=5;
    imagestring($img,$font,(int)(($w-imagefontwidth($font)*strlen($text))/2),(int)($h/2)-10,$text,$fg);
    imagestring($img,$font,(int)(($w-imagefontwidth($font)*11)/2),(int)($h/2)+10,'Placeholder',imagecolorallocate($img,255,200,200));
    imagejpeg($img,$fullPath,85); imagedestroy($img);
    $sub = str_replace(UPLOAD_DIR . '/','',$dir);
    return $sub.'/'.$fname;
}
function copyFromManifest(?string $cacheFileName, string $subDir): string {
    global $manifestCacheDir;
    if (!$cacheFileName) return '';
    $srcPath = rtrim($manifestCacheDir,'/').'/'.$cacheFileName;
    if (!file_exists($srcPath) || filesize($srcPath) < 500) return '';
    $baseDir = UPLOAD_DIR;
    $targetDir = $baseDir.'/'.rtrim($subDir,'/');
    if (!is_dir($targetDir)) @mkdir($targetDir,0755,true);
    if (!is_dir($targetDir) || !is_writable($targetDir)) return '';
    $ext = strtolower(pathinfo($cacheFileName,PATHINFO_EXTENSION));
    if (!$ext) $ext='jpg';
    $fname = 'imp_'.substr(md5($cacheFileName.microtime(true)),0,12).'.'.$ext;
    $fullPath = $targetDir.'/'.$fname;
    if (!@copy($srcPath,$fullPath)) return '';
    if (!file_exists($fullPath) || filesize($fullPath) < 500) return '';
    return rtrim($subDir,'/').'/'.$fname;
}
