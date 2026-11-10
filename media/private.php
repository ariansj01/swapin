<?php
/**
 * Serve private uploads (KYC + store seller documents) — owner or admin only.
 */
require_once __DIR__ . '/../includes/config.php';

$viewer = auth_user();
if (!$viewer) {
    http_response_code(401);
    exit('Unauthorized.');
}

$isAdmin = is_admin_user($viewer);
$reqId   = (int)($_GET['req'] ?? 0);

// مرحله دوم درخواست فروشگاه — مدارک فروشنده
if ($reqId > 0) {
    $field     = (string)($_GET['f'] ?? '');
    $columnMap = [
        'national_card' => 'seller_national_card',
        'birth_cert'    => 'seller_birth_cert',
    ];
    if (!isset($columnMap[$field]) || !db_has_table('store_requests')) {
        http_response_code(404);
        exit;
    }
    $column = $columnMap[$field];
    $row    = DB::fetch(
        "SELECT user_id, `{$column}` AS filename FROM store_requests WHERE id = ?",
        [$reqId]
    );
    if (!$row || empty($row['filename'])) {
        http_response_code(404);
        exit;
    }
    if (!$isAdmin && (int)$viewer['id'] !== (int)$row['user_id']) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $path = resolve_private_upload_path($row['filename']);
    if (!$path) {
        http_response_code(404);
        exit;
    }
    serve_private_file($path, $row['filename']);
    exit;
}

$userId = (int)($_GET['u'] ?? 0);
if ($userId <= 0) {
    http_response_code(404);
    exit;
}

if (!$isAdmin && (int)$viewer['id'] !== $userId) {
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
