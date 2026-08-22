<?php
require_once __DIR__ . '/includes/config.php';

$changes = [];

if (!db_has_column('users', 'store_type')) {
    DB::query(
        "ALTER TABLE users ADD COLUMN store_type ENUM('online','physical','both') NOT NULL DEFAULT 'both' COMMENT 'حضوری یا آنلاین'"
    );
    $changes[] = "Added users.store_type";
}

if (db_has_table('store_requests') && !db_has_column('store_requests', 'store_type')) {
    DB::query(
        "ALTER TABLE store_requests ADD COLUMN store_type ENUM('online','physical','both') NOT NULL DEFAULT 'both' COMMENT 'حضوری یا آنلاین'"
    );
    $changes[] = "Added store_requests.store_type";
}

header('Content-Type: text/plain; charset=utf-8');
echo "store_type migration completed.\n";
echo "Applied changes: " . (empty($changes) ? "NONE (already applied)" : implode("\n - ", $changes)) . "\n";
echo "\nDone.";
