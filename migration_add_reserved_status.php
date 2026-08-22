<?php
require_once __DIR__ . '/includes/config.php';

try {
    $cols = DB::fetchAll("SHOW COLUMNS FROM listings LIKE 'status'");
    if (!empty($cols)) {
        $type = $cols[0]['Type'] ?? '';
        if (strpos($type, 'reserved') === false) {
            DB::query("ALTER TABLE `listings` MODIFY COLUMN `status` ENUM('active','traded','expired','deleted','reserved') COLLATE utf8mb4_unicode_ci DEFAULT 'active'");
            echo "Added 'reserved' to listings.status ENUM successfully!\n";
        } else {
            echo "'reserved' already exists in listings.status ENUM.\n";
        }
    }

    $myCols = DB::fetchAll("SHOW COLUMNS FROM listings LIKE 'status'");
    echo "\nCurrent listings.status column: " . ($myCols[0]['Type'] ?? 'unknown') . "\n";

    echo "\nMigration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
