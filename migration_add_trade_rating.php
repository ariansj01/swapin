<?php
require_once __DIR__ . '/includes/config.php';

try {
    // Check if trade_rating column already exists
    $columns = db_table_columns('reviews');
    if (!in_array('trade_rating', $columns)) {
        DB::query("ALTER TABLE `reviews` ADD COLUMN `trade_rating` TINYINT UNSIGNED NULL COMMENT '1-5' AFTER `rating`");
        echo "Successfully added trade_rating column to reviews table\n";
    } else {
        echo "trade_rating column already exists\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
