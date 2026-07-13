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

rate_limit_ip_or_fail('notifications', 120, 3600, true);

$uid = (int)$user['id'];

$pendingOffers = DB::fetchAll(
    'SELECT o.id, o.created_at, o.listing_id, o.from_user_id,
            l.title AS listing_title,
            u.name AS from_name
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = o.from_user_id
     WHERE l.user_id = ? AND o.status = "pending"
     ORDER BY o.created_at DESC
     LIMIT 12',
    [$uid]
);

$items = [];

foreach ($pendingOffers as $o) {
    $items[] = [
        'type'    => 'offer',
        'id'      => (int)$o['id'],
        'title'   => 'پیشنهاد معاوضه جدید',
        'body'    => $o['from_name'] . ' برای «' . mb_strimwidth($o['listing_title'], 0, 40, '…') . '» پیشنهاد داد',
        'time'    => $o['created_at'],
        'time_ago'=> timeago($o['created_at']),
        'url'     => APP_URL . '/trades?tab=received',
        'icon'    => 'bi-arrow-left-right',
    ];
}

echo json_encode([
    'ok'    => true,
    'total' => count($items),
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
