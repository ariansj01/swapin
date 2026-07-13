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

$unreadMessages = DB::fetchAll(
    'SELECT m.id, m.body, m.created_at, m.from_user_id, m.thread_id,
            u.name AS from_name
     FROM messages m
     JOIN users u ON u.id = m.from_user_id
     WHERE m.to_user_id = ? AND m.is_read = 0
     ORDER BY m.created_at DESC
     LIMIT 8',
    [$uid]
);

$pendingOffers = DB::fetchAll(
    'SELECT o.id, o.created_at, o.listing_id, o.from_user_id,
            l.title AS listing_title,
            u.name AS from_name
     FROM trade_offers o
     JOIN listings l ON l.id = o.listing_id
     JOIN users u ON u.id = o.from_user_id
     WHERE l.user_id = ? AND o.status = "pending"
     ORDER BY o.created_at DESC
     LIMIT 8',
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

foreach ($unreadMessages as $m) {
    $items[] = [
        'type'    => 'message',
        'id'      => (int)$m['id'],
        'title'   => 'پیام از ' . $m['from_name'],
        'body'    => mb_strimwidth($m['body'], 0, 80, '…'),
        'time'    => $m['created_at'],
        'time_ago'=> timeago($m['created_at']),
        'url'     => APP_URL . '/messages.php?to=' . (int)$m['from_user_id'],
        'icon'    => 'bi-chat-dots',
    ];
}

usort($items, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
$items = array_slice($items, 0, 12);

$total = count($unreadMessages) + count($pendingOffers);

echo json_encode([
    'ok'    => true,
    'total' => $total,
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
