<?php
require_once __DIR__ . '/../includes/config.php';

$user = require_auth();
$uid  = $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/trades.php');
    exit;
}

$tradeId   = (int)($_POST['trade_id'] ?? 0);
$toUserId  = (int)($_POST['to_user_id'] ?? 0);
$rating    = max(1, min(5, (int)($_POST['rating'] ?? 0)));
$comment   = clean($_POST['comment'] ?? '');

$trade = DB::fetch(
    'SELECT * FROM trades WHERE id = ? AND status = "completed" AND (user_a_id = ? OR user_b_id = ?)',
    [$tradeId, $uid, $uid]
);

if (!$trade || !in_array($toUserId, [(int)$trade['user_a_id'], (int)$trade['user_b_id']], true) || $toUserId === $uid) {
    header('Location: ' . APP_URL . '/trades.php?tab=completed&error=invalid');
    exit;
}

$existing = DB::fetch(
    'SELECT id FROM reviews WHERE trade_id = ? AND from_user_id = ?',
    [$tradeId, $uid]
);
if ($existing) {
    header('Location: ' . APP_URL . '/trades.php?tab=completed');
    exit;
}

DB::insert('reviews', [
    'trade_id'     => $tradeId,
    'from_user_id' => $uid,
    'to_user_id'   => $toUserId,
    'rating'       => $rating,
    'comment'      => $comment ?: null,
]);

$stats = DB::fetch(
    'SELECT AVG(rating) AS avg_r, COUNT(*) AS cnt FROM reviews WHERE to_user_id = ?',
    [$toUserId]
);
DB::update('users', [
    'rating'       => round((float)($stats['avg_r'] ?? 0), 2),
    'rating_count' => (int)($stats['cnt'] ?? 0),
], 'id = ?', [$toUserId]);

header('Location: ' . APP_URL . '/trades.php?tab=completed&reviewed=1');
exit;
