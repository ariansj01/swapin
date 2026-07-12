<?php
require_once __DIR__ . '/includes/config.php';

try {
    // 1. Add onboarding fields to users table
    $usersCols = db_table_columns('users');
    if (!in_array('onboarding_completed', $usersCols)) {
        DB::query("ALTER TABLE `users` ADD COLUMN `onboarding_completed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `updated_at`");
    }
    if (!in_array('primary_goal', $usersCols)) {
        DB::query("ALTER TABLE `users` ADD COLUMN `primary_goal` ENUM('swap','buy','sell','any') COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `onboarding_completed`");
    }
    if (!in_array('interested_categories', $usersCols)) {
        DB::query("ALTER TABLE `users` ADD COLUMN `interested_categories` JSON DEFAULT NULL COMMENT 'Array of category IDs' AFTER `primary_goal`");
    }
    if (!in_array('typical_value_range', $usersCols)) {
        DB::query("ALTER TABLE `users` ADD COLUMN `typical_value_range` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `interested_categories`");
    }
    if (!in_array('can_ship', $usersCols)) {
        DB::query("ALTER TABLE `users` ADD COLUMN `can_ship` TINYINT(1) DEFAULT NULL AFTER `typical_value_range`");
    }

    // 2. Modify trades table for new steps and Secure Trade Room
    $tradesCols = db_table_columns('trades');
    if (!in_array('step', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `step` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=offer_accepted,2=fee_payment,3=diff_payment,4=contract,5=shipping,6=tracking,7=delivered,8=rating,9=completed' AFTER `status`");
    }
    if (!in_array('fee_paid', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `fee_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `step`");
    }
    if (!in_array('diff_paid', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `diff_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `fee_paid`");
    }
    if (!in_array('shipping_method', $tradesCols)) {
        DB::query("ALTER TABLE `trades` MODIFY COLUMN `shipping_method` ENUM('in_person','post','tipax','courier','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL");
    }
    if (!in_array('user_a_shipping_date', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_a_shipping_date` DATE DEFAULT NULL AFTER `shipping_method`");
    }
    if (!in_array('user_a_shipping_time', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_a_shipping_time` TIME DEFAULT NULL AFTER `user_a_shipping_date`");
    }
    if (!in_array('user_b_shipping_date', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_b_shipping_date` DATE DEFAULT NULL AFTER `user_a_shipping_time`");
    }
    if (!in_array('user_b_shipping_time', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_b_shipping_time` TIME DEFAULT NULL AFTER `user_b_shipping_date`");
    }
    if (!in_array('proposed_shipping_date', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `proposed_shipping_date` DATE DEFAULT NULL AFTER `user_b_shipping_time`");
    }
    if (!in_array('proposed_shipping_time', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `proposed_shipping_time` TIME DEFAULT NULL AFTER `proposed_shipping_date`");
    }
    if (!in_array('user_a_delivered', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_a_delivered` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tracking_code_b`");
    }
    if (!in_array('user_b_delivered', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_b_delivered` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_a_delivered`");
    }
    if (!in_array('trade_rated', $tradesCols)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `trade_rated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_b_delivered`");
    }

    // 3. Create secure_room_messages table for Secure Trade Room
    if (!db_has_table('secure_room_messages')) {
        DB::query("
            CREATE TABLE `secure_room_messages` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `trade_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `type` ENUM('text','image','file','pdf','video') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
                `body` TEXT COLLATE utf8mb4_unicode_ci,
                `file_path` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_secure_trade` (`trade_id`),
                KEY `idx_secure_user` (`user_id`),
                CONSTRAINT `fk_secure_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_secure_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // 4. Create listing_promotions table for 6-tier promotion system
    if (!db_has_table('listing_promotions')) {
        DB::query("
            CREATE TABLE `listing_promotions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `plan` ENUM('boost','featured','vip','targeted','ai','gold') COLLATE utf8mb4_unicode_ci NOT NULL,
                `starts_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ends_at` TIMESTAMP NOT NULL,
                `amount_paid` DECIMAL(12,0) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_promo_listing` (`listing_id`),
                KEY `idx_promo_user` (`user_id`),
                CONSTRAINT `fk_promo_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_promo_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // 5. Add promotion fields to listings table
    $listingsCols = db_table_columns('listings');
    if (!in_array('vip_until', $listingsCols)) {
        DB::query("ALTER TABLE `listings` ADD COLUMN `vip_until` TIMESTAMP NULL DEFAULT NULL AFTER `featured_until`");
    }
    if (!in_array('targeted_until', $listingsCols)) {
        DB::query("ALTER TABLE `listings` ADD COLUMN `targeted_until` TIMESTAMP NULL DEFAULT NULL AFTER `vip_until`");
    }
    if (!in_array('ai_promo_until', $listingsCols)) {
        DB::query("ALTER TABLE `listings` ADD COLUMN `ai_promo_until` TIMESTAMP NULL DEFAULT NULL AFTER `targeted_until`");
    }

    echo "Migration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
