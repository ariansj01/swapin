<?php
/**
 * seo-tasks-test.php — تست رگرسیون تسک‌های جاری سواپین (قبل/بعد از دیپلوی)
 *
 * اجرا روی سرور (CLI):
 *   php seo-tasks-test.php --base=https://staging.swaapin.ir
 *
 * اجرا در مرورگر:
 *   https://staging.swaapin.ir/seo-tasks-test.php?key=SWAPIN-TASKS-2026&format=text
 *
 * پارامترها:
 *   key    : کلید دسترسی (فقط حالت وب؛ پیش‌فرض SWAPIN-TASKS-2026)
 *   base   : دامنهٔ تست (اختیاری؛ پیش‌فرض APP_URL)
 *   format : text برای خروجی متنی
 *
 * پوشش تست‌ها:
 *   PAG    — گارد صفحه‌بندی: صفحهٔ بعد از آخر و page نامعتبر → ۴۰۴؛ کانونیکال تمیز
 *   LINK   — حذف لینک‌های داخلی به فروشگاه تستی (myket-test-store / test)
 *   CHIP   — چیپ‌های جستجو: لینک مستقیم مکان‌محور (بدون ?city=) + rel="nofollow"
 *   SCHEMA — اسکیمای Product فقط برای آگهی فروش/هر دو و همراه offers معتبر
 *
 * نکته امنیتی: بعد از تست، این فایل را از سرور حذف کن.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(600);

$IS_CLI = (PHP_SAPI === 'cli');
$KEY    = getenv('SWAPIN_TASKS_KEY') ?: 'SWAPIN-TASKS-2026';
$opts   = [];

if ($IS_CLI) {
    foreach (array_slice($argv ?? [], 1) as $a) {
        if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) { $opts[$m[1]] = $m[2]; }
    }
    $AS_TEXT = !isset($opts['format']) || strtolower((string) $opts['format']) === 'text';
} else {
    if ((string) ($_GET['key'] ?? '') !== $KEY) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('403 Forbidden — کلید نامعتبر است.');
    }
    $AS_TEXT = strtolower((string) ($_GET['format'] ?? '')) === 'text';
}

if (!defined('SKIP_SESSION')) { define('SKIP_SESSION', true); }
require_once __DIR__ . '/includes/config.php';
foreach (['seo.php', 'locations.php', 'listing_validator.php', 'store.php'] as $__f) {
    $__p = __DIR__ . '/includes/' . $__f;
    if (is_file($__p)) { require_once $__p; }
}

// ── دامنهٔ تست ────────────────────────────────────────────────────────────────
if ($IS_CLI) {
    $BASE = rtrim((string) ($opts['base'] ?? APP_URL), '/');
} else {
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? (string) parse_url(APP_URL, PHP_URL_HOST);
    $basePath = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
    $BASE     = rtrim((string) ($_GET['base'] ?? ($scheme . '://' . $host . $basePath)), '/');
}
$BASE = rtrim($BASE, '/');

function url(string $path): string {
    global $BASE;
    return $BASE . '/' . ltrim($path, '/');
}

// ══════════════════════════════════════════════════════════════════════════════
// زیرساخت تست
// ══════════════════════════════════════════════════════════════════════════════
$RESULTS = [];

function check(string $task, string $name, bool $ok, string $detail = ''): void {
    global $RESULTS;
    $RESULTS[$task][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}
function dbf(string $sql, array $p = []): ?array {
    try { return DB::fetch($sql, $p); } catch (Throwable $e) { return null; }
}
function req_err(array $r): string {
    return ($r['code'] === 0 && ($r['err'] ?? '') !== '') ? ' [err ' . ($r['errno'] ?? 0) . ': ' . $r['err'] . ']' : '';
}
function req_curl(string $url, bool $wantBody = false, int $timeout = 15): array {
    if (!function_exists('curl_init')) { return ['code' => 0, 'location' => '', 'body' => '', 'headers' => '', 'err' => 'no-curl', 'errno' => 0]; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOBODY         => !$wantBody,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'SwaapinTasksTest/1.0',
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);
    $raw   = curl_exec($ch);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['code' => 0, 'location' => '', 'body' => '', 'headers' => '', 'err' => $err, 'errno' => $errno];
    }
    $headers = substr($raw, 0, $hsize);
    $body    = substr($raw, $hsize);
    $loc     = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) { $loc = trim($m[1]); }
    return ['code' => $code, 'location' => $loc, 'body' => $body, 'headers' => $headers, 'err' => '', 'errno' => 0];
}
function req_stream(string $url, int $timeout = 15): array {
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: SwaapinTasksTest/1.0\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body    = @file_get_contents($url, false, $ctx);
    $rawHead = $http_response_header ?? [];
    $code    = 0;
    $headers = '';
    $loc     = '';
    foreach ($rawHead as $hl) {
        $headers .= $hl . "\r\n";
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hl, $m)) { $code = (int) $m[1]; }
        if (preg_match('/^Location:\s*(.+)$/i', $hl, $m)) { $loc = trim($m[1]); }
    }
    return ['code' => $code, 'location' => $loc, 'body' => (string) $body, 'headers' => $headers, 'err' => 'stream', 'errno' => 0];
}
/** درخواست HTTP بدون دنبال‌کردن ریدایرکت (با fallback مقاوم به TLS). */
function req(string $url, bool $wantBody = true, int $timeout = 15): array {
    $r = req_curl($url, $wantBody, $timeout);
    if ($r['code'] !== 0) { return $r; }
    $rf = req_stream($url, $timeout);
    return $rf['code'] !== 0 ? $rf : $r;
}
function html_canonical(string $html): string {
    if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html, $m)
        && preg_match('/href=["\']([^"\']*)["\']/i', $m[0], $c)) {
        return trim($c[1]);
    }
    return '';
}
function html_title(string $html): string {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    return '';
}
function hdr_val(string $headers, string $name): string {
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m)) { return trim($m[1]); }
    return '';
}
/** استخراج همهٔ تگ‌های <a> با href. */
function find_anchors(string $html): array {
    $out = [];
    if (preg_match_all('/<a\b[^>]*>/i', $html, $m)) {
        foreach ($m[0] as $tag) {
            $href = '';
            if (preg_match('/\bhref\s*=\s*"([^"]*)"/i', $tag, $h)) { $href = $h[1]; }
            elseif (preg_match("/\bhref\s*=\s*'([^']*)'/i", $tag, $h)) { $href = $h[1]; }
            $out[] = ['tag' => $tag, 'href' => html_entity_decode($href, ENT_QUOTES, 'UTF-8')];
        }
    }
    return $out;
}
/** مقایسهٔ مسیر نسبی با احتساب پیشوند subdir نصب. */
function norm_path(string $path): string {
    $bp = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
    if ($bp !== '' && str_starts_with($path, $bp)) { $path = substr($path, strlen($bp)); }
    return '/' . trim($path, '/');
}

// ══════════════════════════════════════════════════════════════════════════════
// آماده‌سازی: انتخاب URLهای نمونه از دیتابیس
// ══════════════════════════════════════════════════════════════════════════════
$pub = function_exists('listing_public_sql') ? listing_public_sql('l') : "l.status = 'active' AND l.review_status = 'approved'";

// صفحهٔ لیست نمونه: دسته‌ای با بیشترین آگهی غیر-فروش (منطق all.php: listing_mode <> 'sell')
$best = dbf("SELECT c.slug AS slug FROM listings l JOIN categories c ON c.id = l.category_id
             WHERE {$pub} AND l.listing_mode <> 'sell' AND c.is_active = 1
             GROUP BY c.slug ORDER BY COUNT(*) DESC LIMIT 1");
$catSlug  = (string) ($best['slug'] ?? '');
if ($catSlug === '') { $catSlug = 'car'; }
$listPath = '/iran/' . rawurlencode($catSlug);

// صفحهٔ شهر نمونه برای تست چیپ‌های جستجو
$cityRow  = dbf("SELECT slug FROM locations WHERE level = 'city' AND slug <> '' ORDER BY id LIMIT 1");
$citySlug = (string) ($cityRow['slug'] ?? 'tehran');
if ($citySlug === '') { $citySlug = 'tehran'; }
$cityPath = '/' . rawurlencode($citySlug);

// ══════════════════════════════════════════════════════════════════════════════
// PAG — گارد صفحه‌بندی
// ══════════════════════════════════════════════════════════════════════════════
$p1 = req(url($listPath), true);
check('PAG', 'صفحهٔ لیست پایه ۲۰۰', $p1['code'] === 200, 'کد=' . $p1['code'] . req_err($p1) . ' url=' . $listPath);

$cleanCanon = html_canonical($p1['body']);
$hasNext    = (bool) preg_match('/[?&]page=2(?![0-9])/', $p1['body']);

// صفحهٔ ۲: فقط وقتی باید ۲۰۰ باشد که آگهی کافی برایش هست (لینک page=2 در صفحهٔ ۱)
$p2 = req(url($listPath . '?page=2'), true);
$expect2 = $hasNext ? 200 : 404;
check('PAG', 'صفحهٔ ۲ ' . $expect2 . ' (وجود صفحهٔ بعد=' . ($hasNext ? 'بله' : 'خیر') . ')',
    $p2['code'] === $expect2, 'کد=' . $p2['code'] . ' (انتظار ' . $expect2 . ')' . req_err($p2));

// صفحهٔ بعد از آخرین صفحه → ۴۰۴
$pAfter = req(url($listPath . '?page=999'), true);
check('PAG', 'صفحهٔ ۹۹۹ (بعد از آخر) → ۴۰۴', $pAfter['code'] === 404, 'کد=' . $pAfter['code'] . req_err($pAfter));

// page نامعتبر → ۴۰۴
foreach (['0' => 'صفر', '-1' => 'منفی', 'abc' => 'غیرعددی'] as $badVal => $badLbl) {
    $rb = req(url($listPath . '?page=' . rawurlencode((string) $badVal)), true);
    check('PAG', 'page=' . $badVal . ' (' . $badLbl . ') → ۴۰۴', $rb['code'] === 404, 'کد=' . $rb['code'] . req_err($rb));
}

// صفحهٔ ۴۰۴ نباید ایندکس شود
check('PAG', 'صفحهٔ ۴۰۴ دارای X-Robots-Tag noindex', stripos(hdr_val($pAfter['headers'], 'X-Robots-Tag'), 'noindex') !== false,
    'X-Robots-Tag=' . (hdr_val($pAfter['headers'], 'X-Robots-Tag') ?: '(خالی)'));

// کانونیکال صفحهٔ اول تمیز (بدون ?page)
check('PAG', 'کانونیکال صفحهٔ ۱ تمیز است', $cleanCanon !== '' && strpos($cleanCanon, 'page=') === false, 'canonical=' . ($cleanCanon ?: '(خالی)'));

// ?sort → کانونیکال به آدرس تمیز
$pSort = req(url($listPath . '?sort=old'), true);
check('PAG', '?sort=old کانونیکال تمیز', $pSort['code'] === 200 && html_canonical($pSort['body']) === $cleanCanon,
    'کد=' . $pSort['code'] . ' canonical=' . (html_canonical($pSort['body']) ?: '(خالی)'));

// ?page=1 → کانونیکال تمیز (بدون ?page=1)
$pOne = req(url($listPath . '?page=1'), true);
check('PAG', '?page=1 کانونیکال تمیز', $pOne['code'] === 200 && html_canonical($pOne['body']) === $cleanCanon,
    'کد=' . $pOne['code'] . ' canonical=' . (html_canonical($pOne['body']) ?: '(خالی)'));

// ?q → کانونیکال به آدرس تمیز (صفحهٔ جستجو noindex,follow)
$pQ = req(url($listPath . '?q=' . rawurlencode('مبل')), true);
check('PAG', '?q کانونیکال تمیز', $pQ['code'] === 200 && html_canonical($pQ['body']) === $cleanCanon,
    'کد=' . $pQ['code'] . ' canonical=' . (html_canonical($pQ['body']) ?: '(خالی)'));

// ?deal=swap → کانونیکال به آدرس تمیز
$pDeal = req(url($listPath . '?deal=swap'), true);
check('PAG', '?deal=swap کانونیکال تمیز', $pDeal['code'] === 200 && html_canonical($pDeal['body']) === $cleanCanon,
    'کد=' . $pDeal['code'] . ' canonical=' . (html_canonical($pDeal['body']) ?: '(خالی)'));

// صفحهٔ ۲ معتبر: کانونیکال سلف + تایتل «صفحه‌ی ۲»
if ($hasNext && $p2['code'] === 200) {
    $p2canon = html_canonical($p2['body']);
    check('PAG', 'کانونیکال صفحهٔ ۲ سلف (?page=2) است', str_contains($p2canon, 'page=2'), 'canonical=' . ($p2canon ?: '(خالی)'));
    $p2title = html_title($p2['body']);
    check('PAG', 'تایتل صفحهٔ ۲ شامل «صفحه» است', str_contains($p2title, 'صفحه'), 'title=' . $p2title);
    check('PAG', 'تایتل صفحهٔ ۲ با صفحهٔ ۱ متفاوت است', $p2title !== html_title($p1['body']), '');
}

// ══════════════════════════════════════════════════════════════════════════════
// LINK — فروشگاه تستی
// ══════════════════════════════════════════════════════════════════════════════
foreach (['myket-test-store' => '/shop/myket-test-store', 'test' => '/shop/test'] as $blockedSlug => $shopPath) {
    $rs = req(url($shopPath), true);
    check('LINK', $shopPath . ' → ۴۱۰', $rs['code'] === 410, 'کد=' . $rs['code'] . req_err($rs));
}

$scanPages = ['صفحهٔ اصلی' => '/', 'لیست فروشگاه‌ها' => '/shops', 'لیست دسته' => $listPath, 'صفحهٔ شهر' => $cityPath];
foreach ($scanPages as $lbl => $path) {
    $rr = req(url($path), true);
    $found = ($rr['code'] === 200) && (stripos($rr['body'], 'myket-test-store') !== false || stripos($rr['body'], '/shop/test') !== false);
    check('LINK', $lbl . ' لینکی به فروشگاه تستی ندارد', !$found,
        'کد=' . $rr['code'] . ($found ? ' → لینک فروشگاه تستی یافت شد!' : ''));
}

// ══════════════════════════════════════════════════════════════════════════════
// CHIP — چیپ‌های جستجو (صفحهٔ شهر، بدون دسته: چیپ‌های «جستجوهای مرتبط»)
// ══════════════════════════════════════════════════════════════════════════════
$pc = req(url($cityPath), true);
check('CHIP', 'صفحهٔ شهر ' . $cityPath . ' ۲۰۰', $pc['code'] === 200, 'کد=' . $pc['code'] . req_err($pc));

$qLinks = 0; $badCity = 0; $badRel = 0; $direct = 0; $sampleBad = ''; $sampleDirect = '';
foreach (find_anchors($pc['body']) as $a) {
    $href = $a['href'];
    if ($href === '' || preg_match('#^(mailto:|tel:|javascript:)#i', $href)) { continue; }
    $pq = parse_url($href);
    parse_str((string) ($pq['query'] ?? ''), $qv);
    if (!isset($qv['q']) || (string) $qv['q'] === '') { continue; }
    $qLinks++;
    if (isset($qv['city'])) { $badCity++; if ($sampleBad === '') { $sampleBad = $href; } }
    if (!preg_match('/\brel\s*=\s*["\']?[^"\'>]*nofollow/i', $a['tag'])) { $badRel++; }
    if (norm_path((string) ($pq['path'] ?? '')) === '/' . $citySlug) { $direct++; if ($sampleDirect === '') { $sampleDirect = $href; } }
}
check('CHIP', 'چیپ‌های جستجو دارای rel="nofollow"', $badRel === 0, 'بدون nofollow=' . $badRel . ' از ' . $qLinks);
check('CHIP', 'لینک چیپ‌ها بدون پارامتر ?city= (بدون ریدایرکت)', $badCity === 0,
    'دارای city=' . $badCity . ($sampleBad ? ' → ' . $sampleBad : ''));
check('CHIP', 'لینک چیپ‌ها مستقیم به مسیر مکان ' . $cityPath, $qLinks > 0 && $direct > 0,
    'مستقیم=' . $direct . ' از ' . $qLinks . ($sampleDirect ? ' → ' . $sampleDirect : ''));

// ══════════════════════════════════════════════════════════════════════════════
// SCHEMA — اسکیمای Product (تست درون‌فرآیندی روی تابع)
// ══════════════════════════════════════════════════════════════════════════════
if (function_exists('seo_json_ld_product')) {
    $sellRow = dbf("SELECT * FROM listings
                    WHERE status = 'active' AND review_status = 'approved'
                      AND listing_mode IN ('sell','both')
                      AND (sell_price > 0 OR estimated_value > 0)
                    ORDER BY id DESC LIMIT 1");
    if ($sellRow) {
        $sellRow['cat_name'] = $sellRow['cat_name'] ?? '';
        $s = seo_json_ld_product($sellRow, null, url('/x/' . (int) $sellRow['id']));
        $isProduct = is_array($s) && (($s['@type'] ?? '') === 'Product');
        check('SCHEMA', 'آگهی فروش/هر دو → اسکیمای Product', $isProduct, 'id=' . (int) $sellRow['id'] . ' mode=' . ($sellRow['listing_mode'] ?? ''));
        $off = is_array($s) ? ($s['offers'] ?? []) : [];
        check('SCHEMA', 'offers شامل priceCurrency/price/availability/itemCondition',
            isset($off['priceCurrency'], $off['price'], $off['availability'], $off['itemCondition'])
            && $off['priceCurrency'] === 'IRR' && (int) $off['price'] > 0,
            'offers=' . json_encode($off, JSON_UNESCAPED_UNICODE));
    } else {
        check('SCHEMA', 'آگهی فروش نمونه یافت شد', true, 'آگهی فروش/هر دو فعال یافت نشد؛ بررسی انجام نشد.');
    }

    $swapRow = dbf("SELECT * FROM listings
                    WHERE status = 'active' AND review_status = 'approved'
                      AND listing_mode NOT IN ('sell','both')
                    ORDER BY id DESC LIMIT 1");
    if ($swapRow) {
        $s2 = seo_json_ld_product($swapRow, null, url('/x/' . (int) $swapRow['id']));
        check('SCHEMA', 'آگهی فقط-معاوضه → بدون اسکیمای Product', $s2 === null,
            'id=' . (int) $swapRow['id'] . ' mode=' . ($swapRow['listing_mode'] ?? '') . ' → ' . (is_array($s2) ? 'Product صادر شد!' : 'null'));
    } else {
        check('SCHEMA', 'آگهی فقط-معاوضه نمونه یافت شد', true, 'آگهی فقط-معاوضه فعال یافت نشد؛ بررسی انجام نشد.');
    }
} else {
    check('SCHEMA', 'تابع seo_json_ld_product موجود است', false, 'includes/seo.php لود نشد.');
}

// ══════════════════════════════════════════════════════════════════════════════
// خروجی
// ══════════════════════════════════════════════════════════════════════════════
$ORDER = ['PAG', 'LINK', 'CHIP', 'SCHEMA'];
$LABELS = [
    'PAG'    => 'صفحه‌بندی (گارد ۴۰۴ + کانونیکال تمیز)',
    'LINK'   => 'فروشگاه تستی (۳۱۰/۴۱۰ + حذف لینک داخلی)',
    'CHIP'   => 'چیپ‌های جستجو (لینک مستقیم مکان + nofollow)',
    'SCHEMA' => 'اسکیمای Product (فقط فروش/هر دو + offers معتبر)',
];
$totalPass = 0; $totalFail = 0;
foreach ($RESULTS as $items) {
    foreach ($items as $it) { $it['ok'] ? $totalPass++ : $totalFail++; }
}

if ($AS_TEXT) {
    if (!$IS_CLI) { header('Content-Type: text/plain; charset=utf-8'); }
    echo "SWAAPIN TASKS TEST  base={$BASE}\n";
    echo "PASS={$totalPass}  FAIL={$totalFail}\n\n";
    foreach ($ORDER as $task) {
        if (empty($RESULTS[$task])) { continue; }
        echo "== {$task} — {$LABELS[$task]} ==\n";
        foreach ($RESULTS[$task] as $it) {
            echo ($it['ok'] ? '[PASS] ' : '[FAIL] ') . $it['name']
                . ($it['detail'] !== '' ? '  — ' . str_replace("\n", "\n        ", $it['detail']) : '') . "\n";
        }
        echo "\n";
    }
    echo $totalFail === 0 ? "RESULT: ALL PASS ✅\n" : "RESULT: {$totalFail} FAIL ❌\n";
    if ($IS_CLI) { exit($totalFail === 0 ? 0 : 1); }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Swaapin Tasks Test</title>
<style>
  body{font-family:Tahoma,Segoe UI,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px;line-height:1.7}
  h1{font-size:20px;margin:0 0 4px}.meta{color:#94a3b8;font-size:13px;margin-bottom:16px}
  .summary{display:flex;gap:12px;margin-bottom:20px}
  .pill{padding:8px 14px;border-radius:999px;font-weight:bold}
  .pill.pass{background:#064e3b;color:#6ee7b7}.pill.fail{background:#7f1d1d;color:#fca5a5}
  .task{background:#1e293b;border:1px solid #334155;border-radius:10px;margin-bottom:14px;overflow:hidden}
  .task>h2{font-size:15px;margin:0;padding:10px 14px;border-bottom:1px solid #334155;display:flex;justify-content:space-between}
  .row{padding:8px 14px;border-bottom:1px dashed #334155;font-size:14px;display:flex;gap:10px}
  .row:last-child{border-bottom:none}.row .st{flex:0 0 56px;font-weight:bold}
  .row.ok .st{color:#34d399}.row.no .st{color:#f87171}
  .detail{color:#94a3b8;font-size:12px;white-space:pre-wrap;display:block;margin-top:4px}
  code{background:#334155;padding:1px 5px;border-radius:4px}
</style>
</head>
<body>
<h1>تست تسک‌های سواپین</h1>
<div class="meta">دامنهٔ تست: <b><?= htmlspecialchars($BASE) ?></b> — زمان: <?= htmlspecialchars(date('Y-m-d H:i:s')) ?></div>
<div class="summary">
  <span class="pill pass">PASS: <?= (int) $totalPass ?></span>
  <span class="pill fail">FAIL: <?= (int) $totalFail ?></span>
</div>
<?php foreach ($ORDER as $task): ?>
  <?php if (empty($RESULTS[$task])) { continue; } ?>
  <?php $fails = 0; foreach ($RESULTS[$task] as $it) { if (!$it['ok']) { $fails++; } } ?>
  <div class="task">
    <h2><span><?= htmlspecialchars($task) ?> — <?= htmlspecialchars($LABELS[$task]) ?></span>
      <span><?= $fails === 0 ? '✅' : ('❌ ' . $fails) ?></span></h2>
    <?php foreach ($RESULTS[$task] as $it): ?>
      <div class="row <?= $it['ok'] ? 'ok' : 'no' ?>">
        <span class="st"><?= $it['ok'] ? 'PASS' : 'FAIL' ?></span>
        <span><?= htmlspecialchars($it['name']) ?>
          <?php if ($it['detail'] !== ''): ?><span class="detail"><?= htmlspecialchars($it['detail']) ?></span><?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<p class="meta">پس از پایان تست، فایل <code>seo-tasks-test.php</code> را از سرور حذف کن.</p>
</body>
</html>
