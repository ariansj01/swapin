<?php
require_once __DIR__ . '/includes/config.php';
$count = DB::fetch('SELECT COUNT(*) as c FROM catalog_nodes');
echo "Count: " . ($count['c'] ?? 'table not found or empty');
?>