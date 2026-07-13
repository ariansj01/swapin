<?php
require_once __DIR__ . '/../includes/config.php';

$user = require_auth();
$uid  = $user['id'];

$offerId = (int)($_GET['offer'] ?? 0);

$trade = DB::fetch(
    'SELECT t.id FROM trades t
     JOIN trade_offers o ON o.id = t.offer_id
     JOIN listings l ON l.id = o.listing_id
     WHERE o.id = ? AND l.user_id = ?',
    [$offerId, $uid]
);

if ($trade) {
    header('Location: ' . APP_URL . '/trades/view.php?id=' . (int)$trade['id'] . '&tab=fee');
    exit;
}

header('Location: ' . APP_URL . '/trades?tab=received');
exit;
