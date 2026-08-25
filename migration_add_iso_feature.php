<?php
require_once __DIR__ . '/includes/config.php';

try {
    if (!db_has_table('iso_requests')) {
        DB::query("
            CREATE TABLE `iso_requests` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `listing_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL,
                `description` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `category_id` INT UNSIGNED NOT NULL,
                `city` VARCHAR(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `neighborhood` VARCHAR(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `latitude` DECIMAL(10,7) DEFAULT NULL,
                `longitude` DECIMAL(10,7) DEFAULT NULL,
                `status` ENUM('active','paused','completed','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_iso_requests_user` (`user_id`),
                KEY `idx_iso_requests_listing` (`listing_id`),
                KEY `idx_iso_requests_status` (`status`),
                KEY `idx_iso_requests_category` (`category_id`),
                CONSTRAINT `fk_iso_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_iso_requests_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_iso_requests_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (!db_has_table('iso_matches')) {
        DB::query("
            CREATE TABLE `iso_matches` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `iso_id` INT UNSIGNED NOT NULL,
                `listing_id` INT UNSIGNED NOT NULL,
                `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `distance_km` DECIMAL(8,2) DEFAULT NULL,
                `match_reason` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `status` ENUM('suggested','viewed','interested','rejected','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'suggested',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_iso_matches_pair` (`iso_id`, `listing_id`),
                KEY `idx_iso_matches_listing` (`listing_id`),
                KEY `idx_iso_matches_status` (`status`),
                KEY `idx_iso_matches_score` (`score`),
                CONSTRAINT `fk_iso_matches_iso` FOREIGN KEY (`iso_id`) REFERENCES `iso_requests` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_iso_matches_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    echo "ISO migration completed successfully!\n";
} catch (Throwable $e) {
    echo "ISO migration failed: " . $e->getMessage() . "\n";
}
