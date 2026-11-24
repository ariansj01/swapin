<?php
/**
 * سواپین — ابزار اصلاح لندینگ‌های «مصالح ساختمانی» (P1)
 *
 * دو لندینگ تأییدشدهٔ مصالح ساختمانی را به جدول `swap_landings` بازمی‌گرداند
 * (ردیف ناموجود → درج، ردیف غیرفعال → فعال‌سازی، ردیف موجود → به‌روزرسانی شکل فیلتر).
 * طبق کارت A16.2 اسلاگ لندینگ فقط از اسلاگ دسته/مکان ساخته می‌شود و لندینگ فقط از
 * فهرست کیورد تأییدشده؛ این ابزار همان دو ردیف فهرست `swap-landings-seed-p1p2.php`
 * را هدف می‌گیرد.
 *
 * تا وقتی آگهی کافی نباشد صفحه noindex و بیرون از سایت‌مپ می‌ماند و با بخش
 * «سایر …» (A12) پر می‌شود؛ به همین دلیل وضعیت ایندکس «auto» درج می‌شود.
 *
 * پیش‌فرض «dry-run» است و هیچ چیزی تغییر نمی‌کند. برای اعمال واقعی «--apply».
 *
 * اجرا روی سرور (از ریشهٔ پروژه):
 *   php tools/fix-building-materials.php
 *   php tools/fix-building-materials.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان (CLI) قابل اجراست.');
}

require_once __DIR__ . '/../includes/config.php';

$apply = in_array('--apply', array_slice($argv, 1), true);

$landings = [
    ['iran', 'swap-building-materials', 'category', 'building-materials', '', '', ''],
    ['iran', 'swap-building-materials-with-real-estate', 'xy', 'building-materials', '', 'category', 'real-estate'],
];

echo $apply
    ? "حالت اعمال (--apply)\n"
    : "حالت آزمایشی (dry-run) — برای اعمال «--apply» بزنید\n";
echo 'لندینگ مصالح ساختمانی: ' . count($landings) . "\n\n";

$changed = 0;
foreach ($landings as $row) {
    [$loc, $token, $type, $catSlug, $modelSlug, $targetKind, $targetSlug] = $row;

    $existing = swaapin_swap_landing_find($loc, $token);
    $action   = $existing ? 'به‌روزرسانی' : 'درج';
    echo '• /' . $loc . '/list/' . $token . ' → ' . $action . ' ... ';

    if (!$apply) {
        echo "فقط پیش‌نمایش\n";
        continue;
    }

    $res = swaapin_swap_landing_save([
        'location_slug' => $loc,
        'token'         => $token,
        'type'          => $type,
        'category_slug' => $catSlug,
        'model_slug'    => $modelSlug,
        'target_kind'   => $targetKind,
        'target_slug'   => $targetSlug,
        'index_status'  => 'auto',
        'is_active'     => 1,
    ], $existing ? (int) $existing['id'] : 0);

    if (!$res['ok']) {
        echo 'ناموفق: ' . implode('، ', $res['errors']) . "\n";
        continue;
    }

    $changed++;
    $savedRow  = swaapin_swap_landing_find($loc, $token) ?: [];
    $count     = $savedRow ? swaapin_swap_landing_count($savedRow) : 0;
    $indexable = $savedRow && function_exists('swaapin_swap_landing_indexable')
        ? swaapin_swap_landing_indexable($savedRow)
        : false;
    echo 'انجام شد — آگهی=' . $count . ' | ایندکس=' . ($indexable ? 'index' : 'noindex (auto)') . "\n";
}

if (!$apply) {
    echo "\nاین فقط پیش‌نمایش است؛ برای درج واقعی «--apply» را اضافه کنید.\n";
    exit(0);
}

echo "\nثبت‌شده: {$changed} لندینگ.\n";
echo "راستی‌آزمایی (باید ۲۰۰ بدهند و تا پرشدن دسته noindex و بیرون از سایت‌مپ بمانند):\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-building-materials\n";
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://<host>/iran/list/swap-building-materials-with-real-estate\n";
echo "  curl -s https://<host>/sitemap-swap.xml | grep -c 'building-materials'\n";
