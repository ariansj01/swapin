<?php
/**
 * diag_sw_pwa.php
 *
 * تشخیص سریع مشکل سرویس‌ورکر و PWA
 * در سرور اجرا کن:  php diag_sw_pwa.php > diag_sw_report.txt
 * یا از طریق مرورگر باز کن:  https://swaapin.ir/diag_sw_pwa.php
 */

declare(strict_types=1);

$root = __DIR__;
echo "═══════════════════════════════════════════════════════════\n";
echo "     سواپین — گزارش عیب‌یابی سرویس‌ورکر و PWA\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// --------------------------------------------------------------
// ۱. وجود و هش فایل‌های اصلی SW
// --------------------------------------------------------------
echo "━━━ ۱. بررسی وجود و نسخه فایل‌های سرویس‌ورکر ━━━\n";

$swFiles = [
    'sw-core.js',
    'sw.js',
    'sw-android.js',
    'sw-ios.js',
    'offline.html',
    'src/js/pwa/pwa-core.js',
    'src/js/pwa/platform-detector.js',
];

$expected = [
    'sw-core.js'     => ['v' => '1.0.2', 're' => '/SWAAPIN_SW_CORE_VERSION\s*=\s*[\'\"]([^\'\"]+)[\'\"]/'],
    'sw.js'          => ['v' => 'v3',    're' => "/CACHE_NAME\s*=\s*['\"]([^'\"]+)['\"]/"],
    'sw-android.js'  => ['v' => 'v3',    're' => "/CACHE_NAME\s*=\s*['\"]([^'\"]+)['\"]/"],
    'sw-ios.js'      => ['v' => 'v3',    're' => "/CACHE_NAME\s*=\s*['\"]([^'\"]+)['\"]/"],
    'pwa-core.js'    => ['v' => 'update', 're' => "/reg\.update\(\)/"],
];

$critical = false;

foreach ($swFiles as $f) {
    $full = $root . '/' . $f;
    $base = basename($f);
    if (!file_exists($full)) {
        echo "  ❌ $f — پیدا نشد\n";
        $critical = true;
        continue;
    }

    $size = filesize($full);
    $hash = substr(md5_file($full), 0, 10);
    $content = file_get_contents($full);
    $mod = date('Y-m-d H:i:s', filemtime($full));

    echo "  ✅ $f\n";
    echo "       اندازه  : " . number_format($size) . " بایت\n";
    echo "       آخرین ویرایش: $mod\n";
    echo "       MD5(10) : $hash\n";

    if (isset($expected[$base])) {
        $ex = $expected[$base];
        if (preg_match($ex['re'], $content, $m)) {
            $found = $m[1];
            $hit = false;
            if ($base === 'pwa-core.js') {
                $hit = (strpos($content, 'reg.update()') !== false);
                echo "       reg.update در کد  : " . ($hit ? '✅ دارد' : '⚠️ ندارد (PWA به‌روزرسانی خودکار ندارد)') . "\n";
                if (!$hit) $critical = true;
            } else {
                $hit = (strpos($found, $ex['v']) !== false);
                echo "       مقدار یافت‌شده: $found — انتظار می‌رفت شامل " . $ex['v'] . " باشد → " . ($hit ? '✅' : '⚠️ نسخه قدیمی است!') . "\n";
                if (!$hit) $critical = true;
            }
        } else {
            echo "       ⚠️ هیچ الگوی نسخه‌ای در فایل پیدا نشد\n";
            $critical = true;
        }
    }

    // چک‌های خاص برای هر فایل
    if ($base === 'sw-core.js') {
        $hasApiSkip = strpos($content, 'isApiRoute') !== false;
        $hasEmptyRes = strpos($content, 'emptyResponse') !== false;
        $hasSkipPhp  = preg_match('/endsWith\s*\(\s*[\'\"]\.php[\'\"]\s*\)/', $content) ? true : false;
        echo "       isApiRoute (رد API/.php از SW): " . ($hasApiSkip ? '✅' : '❌ نداره — دلیل اصلی خطای Response!') . "\n";
        echo "       emptyResponse (جایگزین undefined): " . ($hasEmptyRes ? '✅' : '❌ نداره') . "\n";
        if (!$hasApiSkip) $critical = true;
        if (!$hasEmptyRes) $critical = true;
    }

    if (in_array($base, ['sw.js', 'sw-android.js', 'sw-ios.js'], true)) {
        $v = preg_match('/CACHE_NAME\s*=\s*[\'\"][^\'\"]*?(v\d+)[\'\"]/', $content, $mv) ? ($mv[1] ?? '') : '';
        echo "       نسخه کش : $v — مورد انتظار: v3\n";
        if ($v !== 'v3') { echo "       ⚠️ نسخه قدیمی؛ مرورگرها SW جدید را فراخوانی نمی‌کنند\n"; $critical = true; }
    }
    echo "\n";
}

// --------------------------------------------------------------
// ۲. وجود دسترسی HTTP به فایل‌ها از بیرون
// --------------------------------------------------------------
echo "━━━ ۲. بررسی دسترسی HTTP مستقیم به SW (شبیه مرورگر) ━━━\n";

function testHttp(string $path): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url   = $proto . '://' . $host . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Swaapin-Diag)',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 400) {
        return "✅ HTTP $code | $ct";
    }
    if ($code === 0) {
        return "❌ curl_err: $err";
    }
    return "❌ HTTP $code | $ct";
}

$paths = [
    '/sw-core.js',
    '/sw.js',
    '/sw-android.js',
    '/sw-ios.js',
    '/offline.html',
    '/src/js/pwa/pwa-core.js',
    '/src/js/pwa/platform-detector.js',
    '/src/js/pwa/pwa-android.js',
    '/src/js/pwa/pwa-ios.js',
    '/src/img/fav_icon/site.webmanifest',
    '/src/img/fav_icon/site-ios.webmanifest',
    '/src/img/fav_icon/site-android.webmanifest',
];
foreach ($paths as $p) {
    $st = testHttp($p);
    echo "  $p\n    $st\n";
}
echo "\n";

// --------------------------------------------------------------
// ۳. بررسی include/require در layout (آیا PWA Core اجرا می‌شود؟)
// --------------------------------------------------------------
echo "━━━ ۳. بررسی درج فایل‌های PWA در layout ━━━\n";

$layoutFile = $root . '/includes/layout.php';
if (file_exists($layoutFile)) {
    $l = file_get_contents($layoutFile);
    $checks = [
        'pwa-core.js'          => ['pttrn' => '/pwa-core\.js/',                'lbl' => 'لینک pwa-core.js'],
        'platform-detector.js' => ['pttrn' => '/platform-detector\.js/',       'lbl' => 'لینک platform-detector.js'],
        'SwaapinPWA.init()'    => ['pttrn' => '/SwaapinPWA\s*\.\s*init\s*\(/', 'lbl' => 'فراخوانی SwaapinPWA.init'],
        'serviceWorker'        => ['pttrn' => '/serviceWorker\.register/',     'lbl' => 'ثبت SW'],
    ];
    foreach ($checks as $k => $c) {
        $ok = preg_match($c['pttrn'], $l);
        echo "  " . ($ok ? '✅' : '❌') . " {$c['lbl']}: " . ($ok ? 'دارد' : 'ندارد') . "\n";
        if (!$ok) $critical = true;
    }
} else {
    echo "  ❌ includes/layout.php پیدا نشد\n";
    $critical = true;
}
echo "\n";

// --------------------------------------------------------------
// ۴. بررسی هدرهای SW (Service-Worker-Allowed و غیره)
// --------------------------------------------------------------
echo "━━━ ۴. بررسی فایل‌های پیکربندی وب‌سرور ━━━\n";

$ht = $root . '/.htaccess';
if (file_exists($ht)) {
    echo "  .htaccess موجود است.\n";
    $h = file_get_contents($ht);
    $tests = [
        'Service-Worker-Allowed' => ['pttrn' => '/Service-Worker-Allowed/i', 'lbl' => 'هدر Service-Worker-Allowed'],
        'Headers SW'             => ['pttrn' => '/sw-(core|android|ios|)\.js/i',   'lbl' => 'قانون خاص برای فایل‌های SW'],
        'no-cache SW'            => ['pttrn' => '/Cache-Control.*no-cache/i',  'lbl' => 'عدم کش فایل‌های SW'],
    ];
    foreach ($tests as $t) {
        $ok = preg_match($t['pttrn'], $h);
        echo "    " . ($ok ? '✅' : 'ℹ️ ') . " {$t['lbl']}: " . ($ok ? 'دارد' : 'ندارد — اختیاری ولی بهتر است باشد') . "\n";
    }
} else {
    echo "  ℹ️ .htaccess پیدا نشد\n";
}
echo "\n";

// --------------------------------------------------------------
// ۵. نمونه‌ی کد SW آماده‌ی کپی برای تست در مرورگر (Console)
// --------------------------------------------------------------
echo "━━━ ۵. دستورات آماده برای اجرا در کنسول مرورگر ━━━\n";
echo <<<JS
// ۱) دریافت نسخه‌ی SW فعال و انتظار
(async () => {
  if (!('serviceWorker' in navigator)) { console.log('⚠️ SW پشتیبانی نمی‌شود'); return; }
  const regs = await navigator.serviceWorker.getRegistrations();
  console.log('📋 تعداد SW ثبت‌شده:', regs.length);
  regs.forEach((r, i) => {
    console.log('─── Registration['+i+'] ───');
    console.log('  scope   :', r.scope);
    console.log('  active  :', r.active?.scriptURL || 'null');
    console.log('  waiting :', r.waiting?.scriptURL || 'null');
    console.log('  install :', r.installing?.scriptURL || 'null');
    if (r.active) {
      const ch = new MessageChannel();
      ch.port1.onmessage = (ev) => console.log('  SW VERSION:', ev.data);
      r.active.postMessage({type:'GET_VERSION'}, [ch.port2]);
    }
  });
})();

// ۲) پاک‌سازی کامل و رجیستر مجدد (سنگین)
// await navigator.serviceWorker.getRegistrations().then(rs => Promise.all(rs.map(r => r.unregister())));
// console.log('✅ همه SWها حذف شدند'); location.reload();

// ۳) بررسی اینکه کدام درخواست باعث خطای Response می‌شود (فعال کن)
/*
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', e => console.log('SW MSG:', e.data));
  const of = fetch; window.fetch = function(...a){ return of.apply(this,a).catch(err => { console.error('FETCH ERR:', a[0], err); throw err; }); };
  window.addEventListener('unhandledrejection', e => console.error('UNHANDLED:', e.reason, e));
}
*/
JS;
echo "\n\n";

echo "━━━ جمع‌بندی وضعیت بحرانی ━━━\n";
echo $critical
    ? "❌ حداقل یک مشکل بحرانی پیدا شد — موارد بالای با ❌ را در سرور اصلاح کن.\n"
    : "✅ فایل‌های سروری درست هستند. احتمالاً مشکل از کش قدیمی مرورگر است.\n";
