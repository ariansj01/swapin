<?php
/**
 * نقطه پایانی دستیار کشف نیاز (فاز ۵)
 *
 * ورودی (JSON یا فرم): {"need":"...","city":"...","save":0|1,"_csrf":"..."}
 * خروجی: {"ok":true,"need_id":N,"filters":{...},"listings":[...],"total":N,"saved":bool}
 *
 * نیاز کاربر ساختاریافته و در user_needs ذخیره می‌شود؛ در صورت save=1
 * به جستجوی ذخیره‌شده با هشدار تبدیل می‌شود.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/need_discovery.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}
$uid = (int)$user['id'];

$raw = (string) file_get_contents('php://input');
$payload = null;
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === null) {
    $payload = $_POST;
}
if (!empty($payload['_csrf'])) {
    $_POST['_csrf'] = (string)$payload['_csrf'];
}
csrf_verify_or_fail(true);

rate_limit_ip_or_fail('need_discovery', 60, 3600, true);
rate_limit_user_or_fail('need_discovery', $uid, 30, 3600, true);

$need = trim(clean((string)($payload['need'] ?? '')));
$city = trim(clean((string)($payload['city'] ?? '')));
$save = !empty($payload['save']) && $payload['save'] !== '0';

if (mb_strlen($need) < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'need_too_short'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($need) > 300) {
    $need = mb_substr($need, 0, 300);
}

$data = need_discovery_discover($uid, $need, $city ?: null, $save);

if (empty($data['ok'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'discovery_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ثبت رفتار: جستجوی نیاز + نوبت دستیار (حلقه یادگیری فاز ۶)
behavior_track('search', ['keywords' => $need, 'category_id' => $data['filters']['category_id'] ?? null]);
behavior_track('ai_turn', ['object_type' => 'need_discovery', 'keywords' => $need]);

$listings = array_map(static function ($l) {
    return [
        'id'        => (int)$l['id'],
        'title'     => (string)$l['title'],
        'city'      => (string)($l['city'] ?? ''),
        'cat_name'  => (string)($l['cat_name'] ?? ''),
        'value_fmt' => !empty($l['estimated_value']) ? fmt_credit((float)$l['estimated_value']) : '',
        'thumb'     => !empty($l['thumb']) ? UPLOAD_URL . $l['thumb'] : null,
        'url'       => listing_url((int)$l['id'], (string)($l['title'] ?? '')),
    ];
}, $data['listings']);

echo json_encode([
    'ok'       => true,
    'need_id'  => (int)$data['need_id'],
    'filters'  => $data['filters'],
    'total'    => (int)$data['total'],
    'saved'    => (bool)$save,
    'source'   => $data['source'],
    'listings' => $listings,
], JSON_UNESCAPED_UNICODE);
