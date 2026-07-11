<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

csrf_verify_or_fail(true);
rate_limit_ip_or_fail('save_listing', 60, 3600, true);

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

$listingId = (int)($_POST['listing_id'] ?? 0);
if ($listingId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_listing']);
    exit;
}

$reviewClause = db_has_column('listings', 'review_status')
    ? ' AND review_status = "approved"'
    : '';
$listing = DB::fetch(
    'SELECT id FROM listings WHERE id = ? AND status = "active"' . $reviewClause,
    [$listingId]
);
if (!$listing) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$uid      = (int)$user['id'];
$existing = DB::fetch(
    'SELECT 1 FROM saved_listings WHERE user_id = ? AND listing_id = ?',
    [$uid, $listingId]
);

if ($existing) {
    DB::query('DELETE FROM saved_listings WHERE user_id = ? AND listing_id = ?', [$uid, $listingId]);
    echo json_encode(['ok' => true, 'saved' => false]);
    exit;
}

DB::query('INSERT IGNORE INTO saved_listings (user_id, listing_id) VALUES (?, ?)', [$uid, $listingId]);
echo json_encode(['ok' => true, 'saved' => true]);
