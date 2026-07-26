<?php
require_once __DIR__ . '/../includes/config.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

csrf_verify_or_fail();

$id = (int)($_POST['id'] ?? 0);

if ($id) {
    DB::query('DELETE FROM content_pages WHERE id = ?', [$id]);
}

header('Location: ' . APP_URL . '/admin/pages.php');
exit;
