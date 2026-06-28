<?php
require_once __DIR__ . '/../includes/config.php';
logout_user();
header('Location: ' . APP_URL . '/');
exit;
