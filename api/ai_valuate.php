<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

csrf_verify_or_fail(true);
rate_limit_ip_or_fail('ai_valuate', 40, 3600, true);

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

rate_limit_user_or_fail(
    'ai_valuate',
    (int) $user['id'],
    ai_limit('VALUATE_USER', 3),
    ai_window('VALUATE_USER', 900),
    true
);

$title       = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$condition   = clean($_POST['condition'] ?? 'good');
$categoryId  = (int)($_POST['category_id'] ?? 0);

if (mb_strlen($title) < 5) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'title_too_short']);
    exit;
}

$conditionLabels = ['new' => 'نو', 'like_new' => 'مثل نو', 'good' => 'خوب', 'fair' => 'متوسط', 'poor' => 'خورده'];
$conditionLabel = $conditionLabels[$condition] ?? ($condition ?: 'خوب');

$cat = $categoryId
    ? DB::fetch('SELECT name, slug FROM categories WHERE id = ?', [$categoryId])
    : null;

$categoryLabel = $cat ? category_label($cat['slug'], $cat['name']) : 'عمومی';
$demandLevel   = ai_demand_level($categoryId);

$titleShort       = mb_substr(trim($title), 0, 120);
$descriptionShort = trim(preg_replace('/\s+/', ' ', $description));
if (mb_strlen($descriptionShort) > 220) {
    $descriptionShort = mb_substr($descriptionShort, 0, 220) . '...';
}

$pricingPayload = [
    'mode' => 'pricing',
    'listing' => [
        'title'          => $titleShort,
        'description'  => $descriptionShort,
        'category'     => mb_substr($categoryLabel, 0, 80),
        'condition'       => $condition,
        'condition_label' => $conditionLabel,
    ],
    'context' => [
        'unit'             => (string)CREDIT_UNIT,
        'demand'           => $demandLevel,
    ],
    'instruction' =>
        'فقط خروجی JSON تولید کن و هیچ توضیح متنی قبل یا بعد آن ننویس. ساختار JSON: ' .
        '{"type":"pricing","value_range":{"min":عدد,"max":عدد},"confidence":0.xx,"reason":"متن کوتاه دلیل","reasons":["دلیل ۱"]}',
];

$chatMessage =
    "دستور ارزش‌گذاری کالا — فقط و فقط JSON خروجی بده و هیچ حرف متنی ننویس:\n" .
    json_encode($pricingPayload, JSON_UNESCAPED_UNICODE);

$chatResult = ai_chat_respond($chatMessage, [], $user);

$parsed = null;
if (!empty($chatResult['message'])) {
    $parsed = ai_parse_completion_text(
        ['choices' => [['message' => ['content' => $chatResult['message']]]]],
        $chatResult['provider'] ?? null
    );
    if (is_array($parsed)) {
        $afterJson = ai_parse_json_response($parsed);
        if (is_array($afterJson)) {
            $parsed = $afterJson;
        }
    }
}

if ((!$parsed || !is_array($parsed)) && !empty($chatResult['message'])) {
    $text = $chatResult['message'];
    $cleaned = null;
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $cleaned = json_decode($m[0], true);
    }
    if (is_array($cleaned)) {
        $parsed = $cleaned;
    }
}

function ai_valuate_extract_range($parsed, $provider = null) {
    if (!$parsed || !is_array($parsed)) return null;

    $min = 0;
    $max = 0;
    if (isset($parsed['value_range']) && is_array($parsed['value_range'])) {
        $min = (int)($parsed['value_range']['min'] ?? 0);
        $max = (int)($parsed['value_range']['max'] ?? 0);
    }
    if ($min <= 0 && $max <= 0) {
        $min = (int)($parsed['min'] ?? 0);
        $max = (int)($parsed['max'] ?? 0);
    }
    if ($min <= 0 && $max <= 0) {
        $val = (int)($parsed['estimated_value'] ?? $parsed['valuation'] ?? $parsed['value'] ?? $parsed['price'] ?? 0);
        if ($val > 0) {
            $min = (int)round($val * 0.88);
            $max = (int)round($val * 1.12);
        }
    }
    if ($min <= 0 && $max <= 0) return null;
    if ($min > $max) [$min, $max] = [$max, $min];
    if ($min <= 0) $min = (int)max(500000, round($max * 0.85));
    if ($max <= 0) $max = (int)min(500000000000, round($min * 1.15));

    $min = (int)round($min / 100000) * 100000;
    $max = (int)round($max / 100000) * 100000;
    $min = max(500000, $min);
    $max = min(500000000000, $max);
    if ($min > $max) $min = (int)round($max * 0.88 / 100000) * 100000;

    $value = (int)round(($min + $max) / 2 / 100000) * 100000;

    $conf = $parsed['confidence'] ?? $parsed['certainty'] ?? 0.55;
    if (is_string($conf)) {
        $cn = (float)preg_replace('/[^\d.]/', '', $conf);
        $conf = $cn > 1 ? $cn / 100 : (float)$conf;
    }
    $conf = (float)$conf;
    if ($conf > 1) $conf = $conf / 100;
    $confPct = (int)round(max(0, min(1, $conf)) * 100);
    $uncertain = $confPct < 60;

    $reasons = [];
    if (!empty($parsed['reasons']) && is_array($parsed['reasons'])) {
        foreach ($parsed['reasons'] as $r) {
            $s = trim((string)$r);
            if ($s !== '') $reasons[] = $s;
        }
    }
    $singleReason = trim((string)($parsed['reason'] ?? ''));
    if ($singleReason !== '' && !in_array($singleReason, $reasons, true)) {
        array_unshift($reasons, $singleReason);
    }
    if ($uncertain) $reasons[] = 'اطمینان پایین — محدوده تقریبی است؛ در صورت نیاز مقدار را دستی تنظیم کنید.';
    if (!$reasons) $reasons[] = 'ارزش‌گذاری هوشمند بر اساس مشخصات کالای شما.';

    return [
        'value'      => $value,
        'value_fmt'  => fmt_credit((float)$value),
        'range_low'  => $min,
        'range_high' => $max,
        'range_fmt'  => fmt_credit((float)$min) . ' — ' . fmt_credit((float)$max),
        'confidence' => $confPct,
        'uncertain'  => $uncertain,
        'reasons'    => array_values($reasons),
        'note'       => $uncertain
            ? 'ارزش‌گذاری با اطمینان پایین — پیشنهاد را راهنما در نظر بگیرید.'
            : 'ارزش‌گذاری هوشمند سواَپین بر اساس مشخصات و آگهی‌های مشابه.',
        'ai_source'  => $provider ?? 'ai',
    ];
}

$result = ai_valuate_extract_range($parsed, $chatResult['provider'] ?? null);

if (!$result) {
    $fallbackListing = [
        'title'           => $title,
        'description'     => $description,
        'condition'       => $condition,
        'category_id'     => $categoryId,
        'category_label'  => $categoryLabel,
        'demand_level'    => $demandLevel,
    ];
    $result = ai_price_listing_fallback($fallbackListing);
}

echo json_encode(array_merge(['ok' => true], ai_sanitize_pricing_for_client($result)), JSON_UNESCAPED_UNICODE);
