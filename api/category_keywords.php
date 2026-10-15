<?php
// api/category_keywords.php
// جستجوی هوشمند دسته‌بندی: بازگرداندن کلیدواژه‌های کش‌شده، تولید سروری،
// یا آماده‌سازی درخواست برای تکمیل از مرورگر (client_prepare) و سپس ذخیره در کش.

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ai_category_keywords.php';

header('Content-Type: application/json; charset=utf-8');

function aick_api_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aick_api_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

csrf_verify_or_fail(true);
rate_limit_ip_or_fail('ai_category_keywords', 40, 3600, true);

$catSlug = clean($_POST['cat'] ?? '');
$action  = clean($_POST['action'] ?? 'get');

$category = $catSlug !== ''
    ? DB::fetch('SELECT id, name, slug FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1', [$catSlug])
    : null;

if (!$category) {
    aick_api_json(['ok' => false, 'error' => 'category_not_found'], 404);
}

$categoryId   = (int)$category['id'];
$categoryName = category_label((string)$category['slug'], (string)$category['name']);

// ─── ذخیره نتیجه‌ای که مرورگر از هوش مصنوعی گرفته است ───────────────────────────
if ($action === 'save') {
    $keywords = aick_sanitize_keywords($_POST['keywords'] ?? '');
    if (count($keywords) < 3) {
        aick_api_json(['ok' => false, 'error' => 'invalid_keywords'], 400);
    }
    $signature = aick_signature(aick_scope_ids($categoryId));
    aick_cache_put($categoryId, $keywords, $signature, 'ai');
    aick_api_json(['ok' => true, 'type' => 'saved', 'keywords' => $keywords]);
}

// ─── دریافت کلیدواژه‌ها ────────────────────────────────────────────────────────
$state = aick_state($categoryId);
if ($state['fresh'] && $state['keywords']) {
    aick_api_json([
        'ok'       => true,
        'type'     => 'result',
        'source'   => $state['source'],
        'cached'   => true,
        'keywords' => $state['keywords'],
    ]);
}

$titles  = aick_listing_titles(aick_scope_ids($categoryId));
$children = aick_child_category_names($categoryId);
$fallback = aick_fallback_keywords($titles, $categoryName, $children);

// تلاش سروری (در صورت دسترسی سرور به سرویس هوش مصنوعی)
$serverKeywords = aick_generate_server($categoryName, $titles);
if ($serverKeywords) {
    aick_cache_put($categoryId, $serverKeywords, $state['signature'], 'ai');
    aick_api_json([
        'ok'       => true,
        'type'     => 'result',
        'source'   => 'ai',
        'cached'   => false,
        'keywords' => $serverKeywords,
    ]);
}

// تکمیل از مرورگر (عبور از محدودیت IP سرور)
$providers = ai_client_providers();
if ($providers && !empty($titles)) {
    aick_api_json([
        'ok'                => true,
        'type'              => 'client_prepare',
        'messages'          => aick_build_messages($categoryName, $titles),
        'temperature'       => 0.4,
        'max_tokens'        => 400,
        'providers'         => $providers,
        'fallback_keywords' => $fallback,
    ]);
}

// در نهایت: کلیدواژه‌های پشتیبان (بدون هوش مصنوعی)
if (!$fallback) {
    aick_api_json(['ok' => false, 'error' => 'no_keywords'], 200);
}

if ($state['signature'] !== '') {
    aick_cache_put($categoryId, $fallback, $state['signature'], 'fallback');
}

aick_api_json([
    'ok'       => true,
    'type'     => 'result',
    'source'   => 'fallback',
    'cached'   => false,
    'keywords' => $fallback,
]);
