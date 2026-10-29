<?php
require_once __DIR__ . '/../includes/config.php';

// List all admin users
$admins = DB::fetchAll("SELECT id, email, role, is_active FROM users WHERE role = 'admin' OR id = 1");

header('Content-Type: application/json');
echo json_encode([
    'admins_in_db' => $admins,
    'target_email' => 'Yekta.akramsani@gmail.com',
    'admin_email_constant' => ADMIN_EMAIL
], JSON_PRETTY_PRINT);
