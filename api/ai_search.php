<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('ai_search', 40, 3600, true);
rate_limit_user_or_fail('ai_search', (int)$user['id'], ai_limit('SEARCH', 20), ai_window('SEARCH', 3600), true);

$need = trim(clean($_GET['need'] ?? $_POST['need'] ?? ''));
$city = clean($_GET['city'] ?? $_POST['city'] ?? '');
$limit = min(30, max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 24)));

if (mb_strlen($need) < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'need_too_short'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ai_search_listings_by_need($need, $city ?: null, $limit);

$listings = array_map(static function ($l) {
    return [
        'id'              => (int)$l['id'],
        'title'           => $l['title'],
        'city'            => $l['city'] ?? '',
        'cat_name'        => $l['cat_name'] ?? '',
        'estimated_value' => (float)($l['estimated_value'] ?? 0),
        'value_fmt'       => !empty($l['estimated_value']) ? fmt_credit((float)$l['estimated_value']) : '',
        'thumb'           => !empty($l['thumb']) ? UPLOAD_URL . $l['thumb'] : null,
        'url'             => APP_URL . '/listings/view?id=' . (int)$l['id'],
    ];
}, $result['listings']);

echo json_encode([
    'ok'       => true,
    'total'    => $result['total'],
    'filters'  => $result['filters'],
    'source'   => $result['source'],
    'listings' => $listings,
], JSON_UNESCAPED_UNICODE);
