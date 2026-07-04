<?php
/**
 * Serve dispute evidence — trade parties or admin only.
 */
require_once __DIR__ . '/../includes/config.php';

$disputeId = (int)($_GET['id'] ?? 0);
if ($disputeId <= 0) {
    http_response_code(404);
    exit;
}

$viewer = auth_user();
if (!$viewer) {
    http_response_code(401);
    exit('Unauthorized.');
}

$dispute = DB::fetch(
    'SELECT d.*, t.user_a_id, t.user_b_id FROM disputes d JOIN trades t ON t.id = d.trade_id WHERE d.id = ?',
    [$disputeId]
);
if (!$dispute || empty($dispute['evidence'])) {
    http_response_code(404);
    exit;
}

$viewerId = (int) $viewer['id'];
$allowed  = is_admin_user($viewer)
    || $viewerId === (int) $dispute['filed_by']
    || $viewerId === (int) $dispute['against']
    || $viewerId === (int) $dispute['user_a_id']
    || $viewerId === (int) $dispute['user_b_id'];

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden.');
}

$path = resolve_private_upload_path($dispute['evidence']);
if (!$path && defined('UPLOAD_DIR')) {
    $candidate = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename($dispute['evidence']);
    if (is_readable($candidate)) {
        $path = $candidate;
    }
}
if (!$path) {
    http_response_code(404);
    exit;
}

serve_private_file($path, basename($dispute['evidence']));
