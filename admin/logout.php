<?php
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

csrf_verify_or_fail();
logout_user();
header('Location: ' . APP_URL . '/admin/login.php');
exit;
