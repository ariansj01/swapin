<?php
require __DIR__ . '/includes/config.php';

require_cli();
$sql = file_get_contents(__DIR__ . '/categories_fa.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt && str_starts_with($stmt, 'UPDATE')) {
        DB::query($stmt);
    }
}
echo "Categories updated.\n";
