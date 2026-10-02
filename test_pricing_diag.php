<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$isRun = ($_GET['run'] ?? '') === '1';
$error = null;
$result = null;
$rawBody = null;
$contentText = null;
$parsedFinal = null;
$wrapperResult = null;
$curlInfo = null;
$configInfo = null;

if ($isRun) {
    try {
        require_once __DIR__ . '/includes/config.php';

        $configInfo = [
            'groq' => groq_is_configured() ? substr(GROQ_API_KEY, 0, 12) . '...' : false,
            'GROQ_MODEL' => defined('GROQ_MODEL') ? GROQ_MODEL : null,
            'openrouter' => openrouter_is_configured(),
            'AI_PROXY_URL' => defined('AI_PROXY_URL') && AI_PROXY_URL ? AI_PROXY_URL : null,
            'AI_PROXY_AUTH' => defined('AI_PROXY_AUTH') && AI_PROXY_AUTH ? 'SET' : null,
            'order' => ai_provider_order(),
            'rl_groq' => ai_provider_is_rate_limited('groq'),
            'rl_openrouter' => ai_provider_is_rate_limited('openrouter'),
        ];

        $categories = DB::fetchAll('SELECT id, name, slug, parent_id FROM categories WHERE is_active=1 ORDER BY id LIMIT 3');
        $catId = (int)(($categories[0]['id'] ?? 0) ?: 1);
        $catName = $categories[0]['name'] ?? 'عمومی';
        $catSlug = $categories[0]['slug'] ?? '';

        $catStats = ai_category_stats($catId);
        $similar = ai_fetch_similar_listings($catId, 3);

        $listing = [
            'title'           => 'آیفون ۱۳ پرو ۲۵۶ گیگابایت تمیز با گارانتی',
            'description'     => 'سلامت ۱۰۰٪، باتری ۹۲٪، بدون ضربه، همراه با قاب اصلی و شارژر، گارانتی رسمی هنوز فعال است و کاغذها کامل می‌باشد.',
            'condition'       => 'good',
            'category_id'     => $catId,
            'category_label'  => category_label($catSlug, $catName),
            'demand_level'    => ai_demand_level($catId),
        ];

        $payloadForAi = [
            'listing' => [
                'title'       => $listing['title'],
                'description' => $listing['description'],
                'category'    => $listing['category_label'],
                'condition'   => $listing['condition'],
            ],
            'context' => [
                'similar_items'  => array_map(static function ($r) {
                    return [
                        'title'           => $r['title'] ?? '',
                        'condition'       => $r['condition'] ?? '',
                        'estimated_value' => (float)($r['estimated_value'] ?? 0),
                        'category'        => category_label($r['category_slug'] ?? '', $r['category_name'] ?? ''),
                    ];
                }, $similar),
                'demand_level'   => $listing['demand_level'],
                'credit_unit'    => CREDIT_UNIT,
                'category_stats' => $catStats,
            ],
        ];
        $userContent = json_encode(array_merge(['mode' => 'pricing'], $payloadForAi), JSON_UNESCAPED_UNICODE);
        $messages = [
            ['role' => 'system', 'content' => ai_system_prompt()],
            ['role' => 'user', 'content' => $userContent],
        ];

        $curlPayload = [
            'model'       => defined('GROQ_MODEL') ? GROQ_MODEL : 'qwen/qwen3.8-27b',
            'messages'    => $messages,
            'temperature' => 0.10,
            'max_tokens'  => 1200,
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($curlPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_VERBOSE        => true,
        ]);
        $verbose = fopen('php://temp', 'w+b');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        if (!empty($configInfo['AI_PROXY_URL'])) {
            curl_setopt($ch, CURLOPT_PROXY, $configInfo['AI_PROXY_URL']);
            if (!empty($configInfo['AI_PROXY_AUTH'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, AI_PROXY_AUTH);
            }
        }

        $t1 = microtime(true);
        $raw = curl_exec($ch);
        $t2 = microtime(true);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        fclose($verbose);
        curl_close($ch);

        $curlInfo = [
            'duration'  => round($t2 - $t1, 3),
            'http_code' => $code,
            'error'     => $err,
            'length'    => strlen((string)$raw),
            'catId'     => $catId,
            'catName'   => $catName,
            'stats'     => $catStats,
            'similar'   => count($similar),
            'verbose'   => $verboseLog,
        ];
        $rawBody = (string)$raw;

        $body = json_decode((string)$raw, true);
        if (is_array($body)) {
            $contentText = (string)($body['choices'][0]['message']['content'] ?? '');
            $parsed = json_decode(trim($contentText), true);
            if (!is_array($parsed) && preg_match('/\{[\s\S]*\}/', $contentText, $m)) {
                $parsed = json_decode($m[0], true);
            }
            $parsedFinal = $parsed;
        }

        $similar5 = ai_fetch_similar_listings($catId, 5);
        $wr = ai_price_listing($listing, $similar5);
        if ($wr === null) {
            $fb = ai_price_listing_fallback($listing);
            $wrapperResult = ['ok' => false, 'fallback' => $fb];
        } else {
            $wrapperResult = ['ok' => true, 'value' => $wr];
        }
    } catch (Throwable $e) {
        $error = ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()];
    }
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>AI Pricing Diag</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,Segoe UI,Tahoma,Arial;max-width:1200px;margin:20px auto;padding:0 14px;background:#0b1220;color:#e6edf7}
h1{font-size:18px;color:#fff;margin:0 0 12px}.card{background:#111a2e;border:1px solid #23335a;border-radius:12px;padding:16px;margin-bottom:14px}
.row{display:flex;gap:10px;flex-wrap:wrap}button{background:#0066ff;color:#fff;border:0;padding:10px 18px;border-radius:10px;cursor:pointer;font-weight:700}
button:hover{background:#0052cc}a.btn{display:inline-block;background:#ff6600;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:700}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;margin-right:6px}.ok{background:#0f5e39;color:#99e6bd}.bad{background:#7a2333;color:#ffbac4}.info{background:#223966;color:#aec6ef}
pre{background:#040915;color:#cfe2ff;border:1px solid #1f2d50;border-radius:10px;padding:14px;overflow:auto;max-height:420px;font-size:12px;line-height:1.55;direction:ltr;text-align:left}
table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:6px 10px;border-bottom:1px solid #1f2d50;text-align:right}th{color:#9cb3d9;font-weight:600}
.k{color:#9cb3d9;font-weight:600}
</style></head><body>
<h1>تست مستقیم تخمین قیمت با هوش مصنوعی</h1>

<div class="card">
  <div class="row">
    <a class="btn" href="?run=1">▶ اجرای تست و تماس مستقیم با مدل</a>
    <a class="btn" style="background:#2a3b66" href="?">↺ ریست</a>
  </div>
  <p style="color:#9cb3d9;font-size:13px;margin:10px 0 0">این تست با یک کالای نمونه (آیفون ۱۳ پرو) یک درخواست مستقیم به API مدل می‌فرستد و خروجی خام + parse شده را نمایش می‌دهد.</p>
</div>

<?php if ($error): ?>
<div class="card"><h3 style="color:#ff808e;margin:0 0 8px">خطا در هنگام اجرا</h3>
<pre><?= htmlspecialchars($error['msg']) . "\nدر: " . htmlspecialchars($error['file']) . ':' . (int)$error['line'] . "\n\n" . htmlspecialchars($error['trace']) ?></pre></div>
<?php endif ?>

<?php if ($configInfo): ?>
<div class="card">
  <h3 style="margin:0 0 10px;color:#fff">پیکربندی و محیط</h3>
  <table>
  <tr><th>آیتم</th><th>مقدار</th></tr>
  <tr><td class="k">GROQ API Key</td><td><?= $configInfo['groq'] ? "<span class='badge ok'>SET - {$configInfo['groq']}</span>" : "<span class='badge bad'>تنظیم نشده</span>" ?></td></tr>
  <tr><td class="k">مدل پیش‌فرض GROQ_MODEL</td><td><code><?= htmlspecialchars((string)$configInfo['GROQ_MODEL']) ?></code></td></tr>
  <tr><td class="k">OpenRouter</td><td><?= $configInfo['openrouter'] ? "<span class='badge ok'>SET</span>" : "<span class='badge info'>خالی</span>" ?></td></tr>
  <tr><td class="k">AI_PROXY_URL</td><td><?= $configInfo['AI_PROXY_URL'] ? "<code>" . htmlspecialchars($configInfo['AI_PROXY_URL']) . "</code>" : "<span class='badge bad'>خالی — اتصال مستقیم بدون پروکسی</span>" ?></td></tr>
  <tr><td class="k">AI_PROXY_AUTH</td><td><?= $configInfo['AI_PROXY_AUTH'] ? "<span class='badge ok'>SET</span>" : "<span class='badge info'>ندارد</span>" ?></td></tr>
  <tr><td class="k">ترتیب Provider</td><td><code><?= htmlspecialchars(implode(', ', $configInfo['order'])) ?></code></td></tr>
  <tr><td class="k">Rate limit</td><td>groq=<?= $configInfo['rl_groq'] ? '<span class="badge bad">YES</span>' : '<span class="badge ok">no</span>' ?> / openrouter=<?= $configInfo['rl_openrouter'] ? '<span class="badge bad">YES</span>' : '<span class="badge ok">no</span>' ?></td></tr>
  </table>
</div>
<?php endif ?>

<?php if ($curlInfo): ?>
<div class="card">
  <h3 style="margin:0 0 10px;color:#fff">نتیجه درخواست مستقیم cURL</h3>
  <table>
  <tr><th>آیتم</th><th>مقدار</th></tr>
  <tr><td class="k">زمان اجرا</td><td><?= $curlInfo['duration'] ?> ثانیه</td></tr>
  <tr><td class="k">HTTP Code</td><td><?= $curlInfo['http_code'] ?> <?= $curlInfo['http_code']===200?'<span class="badge ok">200 OK</span>':'<span class="badge bad">!!</span>' ?></td></tr>
  <tr><td class="k">cURL error</td><td><?= $curlInfo['error'] ? "<span class='badge bad'>" . htmlspecialchars($curlInfo['error']) . "</span>" : "<span class='badge ok'>ندارد</span>" ?></td></tr>
  <tr><td class="k">طول پاسخ خام</td><td><?= $curlInfo['length'] ?> بایت</td></tr>
  <tr><td class="k">دسته (برای تست)</td><td>id=<?= (int)$curlInfo['catId'] ?> / <?= htmlspecialchars($curlInfo['catName']) ?></td></tr>
  <tr><td class="k">آمار دسته</td><td>n=<?= (int)$curlInfo['stats']['total_listings'] ?> / avg=<?= (int)$curlInfo['stats']['avg_value'] ?> / median=<?= (int)$curlInfo['stats']['median_value'] ?></td></tr>
  <tr><td class="k">آیتم‌های مشابه پیدا شده</td><td><?= (int)$curlInfo['similar'] ?></td></tr>
  </table>

  <h4 style="margin:16px 0 8px;color:#cfe2ff">خروجی خام JSON API (بدون هیچ تغییری)</h4>
  <pre><?= htmlspecialchars((string)$rawBody) ?></pre>

  <?php if (strlen((string)$contentText)): ?>
  <h4 style="margin:14px 0 8px;color:#cfe2ff">محتوای message.content که مدل برمی‌گرداند</h4>
  <pre><?= htmlspecialchars((string)$contentText) ?></pre>
  <h4 style="margin:14px 0 8px;color:#cfe2ff">آیا این محتوا به صورت JSON parse می‌شود؟</h4>
  <?php if (is_array($parsedFinal)): ?>
    <div><span class="badge ok">بله — parse موفق</span></div>
    <pre><?= htmlspecialchars(json_encode($parsedFinal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
  <?php else: ?>
    <div><span class="badge bad">خیر — JSON نامعتبر است، همین الان fallback می‌شود</span></div>
  <?php endif ?>
  <?php endif ?>

  <?php if (strlen((string)($curlInfo['verbose'] ?? ''))): ?>
  <details style="margin-top:14px"><summary style="cursor:pointer;color:#9cb3d9">لاگ VERBOSE cURL (اتصال، سر握، پروکسی و …)</summary>
  <pre><?= htmlspecialchars((string)$curlInfo['verbose']) ?></pre></details>
  <?php endif ?>
</div>
<?php endif ?>

<?php if ($wrapperResult): ?>
<div class="card">
  <h3 style="margin:0 0 10px;color:#fff">نتیجه تابع نهایی <code>ai_price_listing()</code></h3>
  <?php if (!empty($wrapperResult['ok'])): $v = $wrapperResult['value']; ?>
    <span class="badge ok">موفق — خروجی از خود AI</span>
    <table>
    <tr><td class="k">مقدار تخمینی (میانگین)</td><td><?= htmlspecialchars($v['value_fmt']) ?></td></tr>
    <tr><td class="k">محدوده قیمت</td><td><?= htmlspecialchars($v['range_fmt']) ?></td></tr>
    <tr><td class="k">اطمینان</td><td><?= (int)$v['confidence'] ?>٪</td></tr>
    <tr><td class="k">دلیل (نکات اصلی)</td><td><?= htmlspecialchars($v['note'] ?? '') ?></td></tr>
    <tr><td class="k">منبع</td><td><?= htmlspecialchars($v['ai_source'] ?? '?') ?></td></tr>
    </table>
  <?php else: $f = $wrapperResult['fallback']; ?>
    <span class="badge bad">شکست — به fallback داخلی خورد</span>
    <table>
    <tr><td class="k">مقدار fallback</td><td><?= htmlspecialchars($f['value_fmt']) ?></td></tr>
    <tr><td class="k">محدوده fallback</td><td><?= htmlspecialchars($f['range_fmt']) ?></td></tr>
    <tr><td class="k">علت‌ها</td><td><?= htmlspecialchars(implode(' / ', $f['reasons'])) ?></td></tr>
    <tr><td class="k">توضیح نمایش داده شده به کاربر</td><td><?= htmlspecialchars($f['note']) ?></td></tr>
    <tr><td class="k">منبع ذکر شده</td><td><?= htmlspecialchars($f['ai_source']) ?></td></tr>
    </table>
    <p style="color:#ffb3b3;font-size:13px;margin:10px 0 0">⚠ در این حالت دقیقاً همان پیام «اتصال دستیار هوشمند برقرار نشد — از تخمین داخلی استفاده شد» به کاربر نمایش داده می‌شود. برای رفع مشکل حتماً بخش‌های HTTP Code، cURL error و خروجی JSON بالا را بررسی کنید.</p>
  <?php endif ?>
</div>

<div class="card">
  <h3 style="margin:0 0 8px;color:#fff">لاگ‌های اخیر ai_errors.log (اگر وجود دارند)</h3>
  <?php
  $logFile = __DIR__ . '/storage/logs/ai_errors.log';
  if (file_exists($logFile)) {
      $lines = array_reverse(array_filter(file($logFile)));
      $lines = array_slice($lines, 0, 25);
      echo "<pre>" . htmlspecialchars(implode('', $lines)) . "</pre>";
  } else {
      echo "<p style='color:#9cb3d9;font-size:13px'>هنوز log ساخته نشده (یا هیچ خطایی ثبت نشده).</p>";
  }
  ?>
</div>
<?php endif ?>

<div class="card" style="background:#0e1a31;border-color:#243966">
  <p style="margin:0;color:#9cb3d9;font-size:12px">
  نکته: اگر خروجی cURL خالی است یا HTTP code 0 یا خطای timeout می‌بینید → احتمالاً فیلترینگ باعث قطع ارتباط شده و باید پروکسی معتبر در <code>AI_PROXY_URL</code> در فایل <code>includes/ai_secrets.php</code> وارد کنید.
  </p>
</div>
</body></html>
