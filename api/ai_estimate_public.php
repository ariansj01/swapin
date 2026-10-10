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

rate_limit_ip_or_fail('ai_estimate_public', 30, 3600, true);

$title       = clean($_POST['title'] ?? '');
$description = clean($_POST['description'] ?? '');
$condition   = clean($_POST['condition'] ?? 'good');
$categoryId  = (int)($_POST['category_id'] ?? 0);

if (mb_strlen($title) < 3) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'title_too_short', 'msg' => 'نام کالا باید حداقل ۳ کاراکتر باشد.']);
    exit;
}

if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
    $condition = 'good';
}

$cat = $categoryId
    ? DB::fetch('SELECT name, slug FROM categories WHERE id = ? AND is_active = 1', [$categoryId])
    : null;

$categoryLabel = $cat ? category_label($cat['slug'], $cat['name']) : 'عمومی';
$demandLevel   = ai_demand_level($categoryId);
$similar       = $categoryId > 0 ? ai_fetch_similar_listings($categoryId, 5) : [];

$listing = [
    'title'           => $title,
    'description'     => $description,
    'condition'       => $condition,
    'category_id'     => $categoryId,
    'category_label'  => $categoryLabel,
    'demand_level'    => $demandLevel,
];

if (function_exists('ai_price_listing')) {
    $pricing = ai_price_listing($listing, $similar);
    if ($pricing && is_array($pricing)) {
        $estMin  = (int)($pricing['min_value']       ?? $pricing['p25'] ?? 0);
        $estMax  = (int)($pricing['max_value']       ?? $pricing['p75'] ?? 0);
        $estMid  = (int)($pricing['estimated_value'] ?? $pricing['median_value'] ?? $pricing['avg_value'] ?? 0);
        if ($estMid > 0) {
            if ($estMin <= 0) $estMin = (int)round($estMid * 0.75);
            if ($estMax <= 0) $estMax = (int)round($estMid * 1.20);
        }
        $note = trim((string)($pricing['note'] ?? $pricing['reason'] ?? ''));

        echo json_encode([
            'ok'   => true,
            'type' => 'server_result',
            'data' => [
                'min'   => $estMin,
                'max'   => $estMax,
                'mid'   => $estMid,
                'note'  => $note,
                'unit'  => (string)CREDIT_UNIT,
            ],
            'meta' => [
                'provider'     => $pricing['provider'] ?? 'fallback',
                'category'     => $categoryLabel,
                'demand_level' => $demandLevel,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$fallback = ai_sanitize_pricing_for_client(ai_price_listing_fallback($listing));
$estMin = (int)($fallback['min_value'] ?? 0);
$estMax = (int)($fallback['max_value'] ?? 0);
$estMid = (int)($fallback['estimated_value'] ?? 0);
$note   = trim((string)($fallback['note'] ?? ''));

$geminiMessages = ai_pricing_build_gemini_messages($listing, $similar);
$providers      = ai_pricing_client_providers($geminiMessages);

echo json_encode([
    'ok'          => true,
    'type'        => 'client_prepare',
    'messages'    => ai_pricing_build_messages($listing, $similar),
    'temperature' => 0.10,
    'max_tokens'  => 2048,
    'providers'   => $providers,
    'fallback'    => [
        'min'  => $estMin,
        'max'  => $estMax,
        'mid'  => $estMid,
        'note' => $note,
        'unit' => (string)CREDIT_UNIT,
    ],
    'meta'        => [
        'similar_count' => count($similar),
        'category_id'   => $categoryId,
        'demand_level'  => $demandLevel,
    ],
], JSON_UNESCAPED_UNICODE);
