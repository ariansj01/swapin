<?php
require_once __DIR__ . '/includes/config.php';

try {
    $tradesCols = db_table_columns('trades');
    if (!in_array('user_a_shipping_status', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_a_shipping_status` ENUM('preparing','shipped','dispute') COLLATE utf8mb4_unicode_ci DEFAULT 'preparing' AFTER `user_a_shipping_method`");
    }
    if (!in_array('user_b_shipping_status', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_b_shipping_status` ENUM('preparing','shipped','dispute') COLLATE utf8mb4_unicode_ci DEFAULT 'preparing' AFTER `user_b_shipping_method`");
    }

    echo "Migration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
