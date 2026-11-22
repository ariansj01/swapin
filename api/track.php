<?php
/**
 * نقطه پایانی ثبت رفتار کاربران (فاز ۱)
 *
 * ورودی: JSON بدنه  {"events":[{event_type,...}], "_csrf":"..."}
 * یا فرم معمولی با کلیدهای event_type/...
 * خروجی: {"ok":true,"tracked":N}
 *
 * نکات امنیتی: CSRF، محدودیت نرخ بر اساس IP، رد خودکار خزنده‌ها و احترام به opt-out.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/behavior.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

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

// توکن CSRF می‌تواند در بدنه JSON باشد (سازگاری با sendBeacon).
if (!empty($payload['_csrf'])) {
    $_POST['_csrf'] = (string)$payload['_csrf'];
}
csrf_verify_or_fail(true);

rate_limit_ip_or_fail('behavior_track', 600, 60, true);

if (behavior_is_bot() || behavior_optout()) {
    echo json_encode(['ok' => true, 'tracked' => 0]);
    exit;
}

$events = $payload['events'] ?? null;
if ($events === null && !empty($payload['event_type'])) {
    $events = [$payload];
}
if (!is_array($events)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$user = auth_user();
$uid  = $user ? (int)$user['id'] : null;
$anon = behavior_anon_id();

if ($uid && $anon !== '') {
    behavior_link_anon($anon, $uid);
}

$tracked = behavior_log_events($events, $uid, $anon);
echo json_encode(['ok' => true, 'tracked' => $tracked]);
