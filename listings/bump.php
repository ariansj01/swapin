<?php
require_once __DIR__ . '/../includes/config.php';

$id = (int)($_GET['id'] ?? 0);
header('Location: ' . APP_URL . '/listings/promote' . ($id ? '?id=' . $id : ''));
exit;
