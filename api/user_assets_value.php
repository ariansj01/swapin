<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/asset_valuation.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('user_assets_value', 60, 3600, true);

try {
    $data = av_get_user_assets((int)$user['id']);
    echo json_encode([
        'ok'                 => true,
        'total_value'        => $data['total_value'],
        'currency'           => $data['currency'],
        'assets'             => $data['assets'],
        'swap_opportunities' => $data['swap_opportunities'],
        'confidence'         => $data['confidence'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (function_exists('swapin_debug_log')) {
        swapin_debug_log('api-user-assets-value-failed', ['msg' => $e->getMessage(), 'line' => $e->getLine()]);
    }
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'سرور در حال حاضر قادر به محاسبه ارزش دارایی‌ها نیست.',
    ], JSON_UNESCAPED_UNICODE);
}
