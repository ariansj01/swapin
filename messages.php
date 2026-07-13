<?php
/**
 * Regular chat has been removed — messaging only happens inside the secure trade room.
 */
require_once __DIR__ . '/includes/config.php';

header('Location: ' . APP_URL . '/trades', true, 301);
exit;
