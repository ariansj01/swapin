<?php
require_once __DIR__ . '/includes/config.php';
try {
    $r = DB::fetch('SELECT 1 as one');
    echo "Connection OK: " . $r['one'];
} catch (Throwable $e) {
    echo "Connection Failed: " . $e->getMessage();
}
?>