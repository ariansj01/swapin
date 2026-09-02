<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/asset_valuation.php';
require_once __DIR__ . '/../includes/negotiator_service.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('negotiator_analyze', 30, 3600, true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$offerId = (int)($_GET['offer_id'] ?? 0);
if ($offerId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'offer_id_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = negotiator_analyze_offer($offerId, (int)$user['id']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (function_exists('swapin_debug_log')) {
        swapin_debug_log('api-negotiator-analyze-failed', ['msg' => $e->getMessage(), 'line' => $e->getLine(), 'offer_id' => $offerId]);
    }
    http_response_code(500);
    echo json_encode([
        'ok'     => false,
        'error'  => 'server_error',
    ], JSON_UNESCAPED_UNICODE);
}
