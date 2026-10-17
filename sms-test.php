<?php
declare(strict_types=1);
/**
 * ابزار عیب‌یابی ارسال پیامک (ایران‌پیامک) — برای اجرا روی سرور.
 *
 * اجرای وب : https://<domain>/sms-test.php?key=swaapin-sms-test
 * اجرای CLI: php sms-test.php --to=0912xxxxxxx --test=balance|simple|pattern
 *
 * ⚠️ این فایل فقط برای عیب‌یابی است؛ بعد از پایان کار حذفش کنید.
 */

const SMS_TEST_ACCESS_KEY = 'swaapin-sms-test';
const SMS_TEST_API_BASE   = 'https://api.iranpayamak.com';

$isCli = (PHP_SAPI === 'cli');

$bootError = null;
try {
    require_once __DIR__ . '/includes/config.php';
} catch (Throwable $e) {
    $bootError = $e->getMessage();
}

/* ── پارامترها ─────────────────────────────────────────────────────────── */
$cliArgs = [];
if ($isCli && isset($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-zA-Z_]+)=(.*)$/s', $arg, $m)) {
            $cliArgs[$m[1]] = $m[2];
        } elseif (preg_match('/^--([a-zA-Z_]+)$/', $arg, $m)) {
            $cliArgs[$m[1]] = '1';
        }
    }
}
$in = static function (string $key, string $default = '') use ($isCli, $cliArgs): string {
    if ($isCli) {
        return (string) ($cliArgs[$key] ?? $default);
    }
    return (string) ($_GET[$key] ?? $default);
};

/* ── کنترل دسترسی (وب) ─────────────────────────────────────────────────── */
if (!$isCli) {
    $keyOk   = hash_equals(SMS_TEST_ACCESS_KEY, (string) ($_GET['key'] ?? ''));
    $isAdmin = false;
    try {
        $isAdmin = function_exists('auth_admin') && auth_admin() !== null;
    } catch (Throwable) {
    }
    if (!$keyOk && !$isAdmin) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        echo "با حساب مدیر وارد شوید یا ?key=" . SMS_TEST_ACCESS_KEY . " را به آدرس اضافه کنید.\n";
        exit;
    }
}

/* ── مقادیر تنظیمات ────────────────────────────────────────────────────── */
$apiKey  = defined('SMS_IRANPAYAMAK_API_KEY') ? (string) SMS_IRANPAYAMAK_API_KEY : '';
$line    = defined('SMS_IRANPAYAMAK_LINE_NUMBER') ? (string) SMS_IRANPAYAMAK_LINE_NUMBER : '';
$pattern = defined('SMS_IRANPAYAMAK_PATTERN_CODE') ? (string) SMS_IRANPAYAMAK_PATTERN_CODE : '';
$enabled = defined('SMS_ENABLED') ? (bool) SMS_ENABLED : false;

$to   = trim($in('to', ''));
$test = strtolower(trim($in('test', $to !== '' ? 'simple' : 'balance')));
if (!in_array($test, ['balance', 'simple', 'pattern', 'all'], true)) {
    $test = 'balance';
}
$text = trim($in('text', 'تست ارسال پیامک سواپین'));

function sms_test_mask(?string $v): string {
    $v = (string) $v;
    if ($v === '') {
        return '(خالی)';
    }
    if (strlen($v) <= 8) {
        return str_repeat('*', strlen($v));
    }
    return substr($v, 0, 4) . str_repeat('*', max(0, strlen($v) - 8)) . substr($v, -4);
}

/**
 * درخواست خام به API به همراه ثبت هدرها و خطای cURL.
 */
function sms_test_http(string $method, string $path, ?array $body, string $apiKey): array {
    $result = ['http' => 0, 'body' => '', 'curl' => '', 'json' => null, 'ms' => 0, 'headers' => []];

    if (!function_exists('curl_init')) {
        $result['curl'] = 'cURL در PHP فعال نیست';
        return $result;
    }

    $ch = curl_init(SMS_TEST_API_BASE . $path);
    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Api-Key: ' . $apiKey;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            $len = strlen($line);
            if (stripos($line, 'HTTP/') === 0) {
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
            return $len;
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $t0  = microtime(true);
    $raw = curl_exec($ch);
    $result['ms']    = (int) round((microtime(true) - $t0) * 1000);
    $result['curl']  = (string) curl_error($ch);
    $result['http']  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $result['body']  = $raw === false ? '' : (string) $raw;
    $result['json']  = is_string($raw) ? json_decode($raw, true) : null;
    $result['headers'] = $responseHeaders;
    curl_close($ch);

    return $result;
}

/* ── فرم وب (وقتی تستی اجرا نشده باشد) ─────────────────────────────────── */
if (!$isCli && $in('run', '') === '' && $to === '') {
    $self = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? 'sms-test.php'), ENT_QUOTES, 'UTF-8');
    $self = preg_replace('/[?&]run=1.*$/', '', $self);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>تست پیامک</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#eef2f7;color:#111;margin:0;padding:24px;line-height:1.8}
.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #d7dee8;border-radius:10px;padding:20px}
h1{font-size:1.25rem;margin:0 0 4px}
p.hint{font-size:.85rem;color:#5b6b80;margin:0 0 16px}
label{display:block;font-size:.85rem;margin:12px 0 4px;font-weight:700}
input,select{width:100%;padding:9px 11px;border:1px solid #cfd8e3;border-radius:8px;font-family:inherit;font-size:.9rem;box-sizing:border-box}
button{margin-top:16px;background:#0b63c5;color:#fff;border:0;border-radius:8px;padding:11px 20px;font-family:inherit;font-size:.95rem;cursor:pointer}
code{background:#f2f5f9;padding:2px 6px;border-radius:5px;direction:ltr;display:inline-block}
</style>
</head>
<body>
<div class="card">
  <h1>ابزار تست ارسال پیامک</h1>
  <p class="hint">این ابزار نتیجه خام وب‌سرویس ایران‌پیامک را نشان می‌دهد. بعد از عیب‌یابی فایل را حذف کنید.</p>
  <form method="GET" action="<?= $self ?>">
    <input type="hidden" name="key" value="<?= htmlspecialchars(SMS_TEST_ACCESS_KEY, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="run" value="1">
    <label>نوع تست</label>
    <select name="test">
      <option value="balance">فقط بررسی کلید و اعتبار (بدون ارسال)</option>
      <option value="simple">ارسال پیامک متنی ساده</option>
      <option value="pattern">ارسال پیامک پترن (OTP)</option>
      <option value="all">هر سه تست</option>
    </select>
    <label>شماره تست (برای تست ارسال لازم است)</label>
    <input type="text" name="to" value="" placeholder="09120000000" dir="ltr">
    <label>متن پیامک</label>
    <input type="text" name="text" value="تست ارسال پیامک سواپین">
    <button type="submit">اجرای تست</button>
  </form>
</div>
</body>
</html><?php
    exit;
}

/* ── اجرای تست‌ها ──────────────────────────────────────────────────────── */
header('Content-Type: text/plain; charset=utf-8');

$out = [];
$add = static function (string $line = '') use (&$out): void {
    $out[] = $line;
};
$addJson = static function ($data, int $pad = 4) use (&$out): void {
    $out[] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
};

$add('================= اطلاعات محیط =================');
$add('زمان سرور        : ' . date('Y-m-d H:i:s'));
$add('نسخه PHP         : ' . PHP_VERSION);
$add('SAPI             : ' . PHP_SAPI);
$add('افزونه cURL      : ' . (function_exists('curl_init') ? 'فعال' : 'غیرفعال'));
$add('allow_url_fopen  : ' . (ini_get('allow_url_fopen') ? 'on' : 'off'));
$add('open_basedir     : ' . (ini_get('open_basedir') ?: '(تنظیم نشده)'));
$add('APP_ENV          : ' . (defined('APP_ENV') ? (string) APP_ENV : '(نامشخص)'));
$add('خطای بارگذاری config: ' . ($bootError !== null ? $bootError : 'ندارد'));

$add('');
$add('================= تنظیمات پیامک =================');
$add('SMS_ENABLED      : ' . ($enabled ? 'true' : 'false'));
$add('API KEY          : ' . sms_test_mask($apiKey));
$add('LINE NUMBER      : ' . ($line !== '' ? $line : '(خالی)'));
$add('PATTERN CODE     : ' . ($pattern !== '' ? $pattern : '(خالی)'));
$add('sms_is_enabled() : ' . (function_exists('sms_is_enabled') ? (sms_is_enabled() ? 'true' : 'false') : '(تابع موجود نیست)'));

$toDigits = preg_replace('/\D+/', '', $to);
$toNormalized = function_exists('sms_normalize_recipient') ? sms_normalize_recipient($to) : $toDigits;
if ($to !== '') {
    $add('');
    $add('شماره ورودی     : ' . $to);
    $add('شماره نرمال‌شده  : ' . $toNormalized);
    $add('معتبر طبق API    : ' . (preg_match('/^09\d{9}$/', $toNormalized) ? 'بله' : 'خیر — باید 11 رقم و با 09 شروع شود'));
}

/* ── تست ۱: اعتبار و کلید ──────────────────────────────────────────────── */
if ($test === 'balance' || $test === 'all') {
    $add('');
    $add('================= تست ۱: بررسی کلید و اعتبار =================');
    $add('GET ' . SMS_TEST_API_BASE . '/ws/v1/account/balance');
    $r = sms_test_http('GET', '/ws/v1/account/balance', null, $apiKey);
    $add('کد HTTP  : ' . $r['http']);
    $add('زمان     : ' . $r['ms'] . ' ms');
    $add('خطای cURL: ' . ($r['curl'] !== '' ? $r['curl'] : 'ندارد'));
    if (!empty($r['headers']['server'])) {
        $add('Server   : ' . $r['headers']['server']);
    }
    if (!empty($r['headers']['wcdn-country'])) {
        $add('CDN country: ' . $r['headers']['wcdn-country']);
    }
    $add('پاسخ خام :');
    $add($r['body'] !== '' ? mb_strimwidth($r['body'], 0, 1200, '…') : '(خالی)');
    if ($r['http'] === 401) {
        $add('>>> کلید API نامعتبر است یا مسدود شده. کلید را از پنل ایران‌پیامک دوباره بگیرید.');
    } elseif ($r['http'] === 200 && !empty($r['json']['status']) && $r['json']['status'] === 'success') {
        $add('>>> کلید سالم است.');
    }
}

/* ── تست ۲: ارسال پیامک متنی ساده ─────────────────────────────────────── */
if (($test === 'simple' || $test === 'all')) {
    $add('');
    $add('================= تست ۲: ارسال پیامک متنی (simple) =================');
    if ($toNormalized === '' || !preg_match('/^09\d{9}$/', $toNormalized)) {
        $add('برای این تست یک شماره معتبر با پارامتر to بدهید (مثال: --to=09120000000).');
    } else {
        $payload = [
            'text'          => $text,
            'line_number'   => $line,
            'recipients'    => [$toNormalized],
            'number_format' => 'english',
            'schedule'      => null,
        ];
        $add('POST /ws/v1/sms/simple');
        $add('Payload  :');
        $addJson($payload);
        $r = sms_test_http('POST', '/ws/v1/sms/simple', $payload, $apiKey);
        $add('کد HTTP  : ' . $r['http']);
        $add('زمان     : ' . $r['ms'] . ' ms');
        $add('خطای cURL: ' . ($r['curl'] !== '' ? $r['curl'] : 'ندارد'));
        $add('پاسخ خام :');
        $add($r['body'] !== '' ? mb_strimwidth($r['body'], 0, 1200, '…') : '(خالی)');

        if (function_exists('send_simple_sms')) {
            $appOk = send_simple_sms([$toNormalized], $text);
            $add('');
            $add('نتیجه تابع send_simple_sms() : ' . ($appOk ? 'true' : 'false'));
            $add('آخرین خطای برنامه           : ' . (function_exists('last_sms_error') && last_sms_error() !== '' ? last_sms_error() : '(ندارد)'));
        }

        $add('');
        $add('نکته مهم: طبق مستندات ایران‌پیامک، ارسال‌های غیرپترن (simple) در صف «تأیید انسانی» قرار می‌گیرند و');
        $add('تا تأیید اپراتور ارسال نمی‌شوند. برای ارسال فوری، از پیامک پترن استفاده کنید.');
    }
}

/* ── تست ۳: ارسال پیامک پترن ──────────────────────────────────────────── */
if ($test === 'pattern' || $test === 'all') {
    $add('');
    $add('================= تست ۳: ارسال پیامک پترن =================');
    if ($toNormalized === '' || !preg_match('/^09\d{9}$/', $toNormalized)) {
        $add('برای این تست یک شماره معتبر با پارامتر to بدهید (مثال: --to=09120000000).');
    } elseif ($pattern === '') {
        $add('کد پترن (SMS_IRANPAYAMAK_PATTERN_CODE) تنظیم نشده است.');
    } else {
        $payload = [
            'code'          => $pattern,
            'attributes'    => ['code' => '123456', 'minutes' => '5'],
            'recipient'     => $toNormalized,
            'line_number'   => $line,
            'number_format' => 'english',
        ];
        $add('POST /ws/v1/sms/pattern');
        $add('Payload  :');
        $addJson($payload);
        $r = sms_test_http('POST', '/ws/v1/sms/pattern', $payload, $apiKey);
        $add('کد HTTP  : ' . $r['http']);
        $add('زمان     : ' . $r['ms'] . ' ms');
        $add('خطای cURL: ' . ($r['curl'] !== '' ? $r['curl'] : 'ندارد'));
        $add('پاسخ خام :');
        $add($r['body'] !== '' ? mb_strimwidth($r['body'], 0, 1200, '…') : '(خالی)');
    }
}

$add('');
$add('================= راهنمای تفسیر =================');
$add('- 401 : کلید API اشتباه یا مسدود است.');
$add('- 400 : خطای منطق کاری است؛ متن message پاسخ را بخوانید.');
$add('- curl error : مشکل شبکه/SSL سرور در دسترسی به api.iranpayamak.com.');
$add('- status=pending-approval : پیام در انتظار تأیید انسانی است و ارسال نشده.');
$add('- برای مشاهده وضعیت واقعی تحویل: GET /ws/v1/send_request/{id}/items');

echo implode("\n", $out) . "\n";
