<?php
require_once __DIR__ . '/../includes/config.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

csrf_verify_or_fail();

$id = (int)($_POST['id'] ?? 0);
$field = $_POST['field'] ?? '';

if ($id > 0 && in_array($field, ['show_in_nav', 'show_in_footer'], true)) {
    DB::query(
        "UPDATE content_pages SET {$field} = CASE WHEN {$field} = 1 THEN 0 ELSE 1 END WHERE id = ?",
        [$id]
    );
}

header('Location: ' . APP_URL . '/admin/pages.php');
exit;