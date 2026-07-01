<?php
/**
 * Serve private uploads (KYC) — owner or admin only.
 */
require_once __DIR__ . '/../includes/config.php';

$userId = (int)($_GET['u'] ?? 0);
if ($userId <= 0) {
    http_response_code(404);
    exit;
}

$viewer = auth_user();
if (!$viewer) {
    http_response_code(401);
    exit('Unauthorized.');
}

$isOwner = (int)$viewer['id'] === $userId;
$isAdmin = is_admin_user($viewer);
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    exit('Forbidden.');
}

$row = DB::fetch('SELECT id_card_image FROM users WHERE id = ?', [$userId]);
if (!$row || empty($row['id_card_image'])) {
    http_response_code(404);
    exit;
}

$path = resolve_private_upload_path($row['id_card_image']);
if (!$path) {
    http_response_code(404);
    exit;
}

serve_private_file($path, $row['id_card_image']);
