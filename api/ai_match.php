<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

rate_limit_ip_or_fail('ai_match', 80, 3600, true);

$refresh = !empty($_GET['refresh']) || !empty($_POST['refresh']);
if ($refresh) {
    csrf_verify_or_fail(true);
    rate_limit_user_or_fail(
        'ai_match_refresh',
        (int) $user['id'],
        ai_limit('MATCH_REFRESH', 6),
        ai_window('MATCH_REFRESH', 3600),
        true
    );
}

$uid       = (int) $user['id'];
$listingId = (int) ($_GET['listing_id'] ?? $_POST['listing_id'] ?? 0) ?: null;
$limit     = min(12, max(1, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 8)));

$result = ai_match_listings_cached($uid, $listingId, $refresh, $limit);

$matches = array_map(static function ($m) {
    $m = ai_sanitize_match_row_for_client($m);
    $m['value_fmt'] = !empty($m['estimated_value'])
        ? fmt_credit((float) $m['estimated_value'])
        : '';
    $m['url'] = APP_URL . '/listings/view.php?id=' . (int) $m['listing_id'];
    return $m;
}, $result['matches']);

echo json_encode([
    'ok'           => true,
    'matches'      => $matches,
    'user_listing' => $result['user_listing'],
    'source'       => ai_public_mode($result['source']),
    'cached'       => !$refresh,
], JSON_UNESCAPED_UNICODE);
