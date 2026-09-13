<?php
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

$steps = [];

if (!db_has_table('listing_ai_reviews')) {
    try {
        DB::query("
            CREATE TABLE `listing_ai_reviews` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `review_mode` ENUM('shadow','auto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shadow',
                `ai_decision` ENUM('approve','reject','escalate') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'escalate',
                `ai_confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `ai_provider` VARCHAR(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `rule_signals` JSON DEFAULT NULL,
                `unsafe_flags` JSON DEFAULT NULL,
                `reason_codes` JSON DEFAULT NULL,
                `human_readable_note` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `admin_decision` ENUM('approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `admin_id` INT UNSIGNED DEFAULT NULL,
                `admin_note` VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `matched_threshold` VARCHAR(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `latency_ms` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_ai_listing` (`listing_id`),
                KEY `idx_ai_decision` (`ai_decision`,`created_at`),
                KEY `idx_ai_user` (`user_id`),
                KEY `idx_admin_vs_ai` (`ai_decision`,`admin_decision`),
                CONSTRAINT `fk_air_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_air_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $steps[] = '✓ جدول listing_ai_reviews ساخته شد.';
    } catch (Throwable $e) {
        $steps[] = '✗ خطا در ساخت listing_ai_reviews: ' . $e->getMessage();
    }
} else {
    $steps[] = '— جدول listing_ai_reviews از قبل وجود دارد.';
}

$cols = [
    'ai_reviewed'   => "ALTER TABLE `listings` ADD COLUMN `ai_reviewed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `review_note`",
    'ai_suggestion' => "ALTER TABLE `listings` ADD COLUMN `ai_suggestion` ENUM('approve','reject','escalate') COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ai_reviewed`",
    'ai_confidence' => "ALTER TABLE `listings` ADD COLUMN `ai_confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ai_suggestion`",
    'ai_note'       => "ALTER TABLE `listings` ADD COLUMN `ai_note` VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ai_confidence`",
];
foreach ($cols as $name => $sql) {
    if (!db_has_column('listings', $name)) {
        try {
            DB::query($sql);
            $steps[] = "✓ ستون listings.{$name} اضافه شد.";
        } catch (Throwable $e) {
            $steps[] = "✗ خطا در ستون {$name}: " . $e->getMessage();
        }
    } else {
        $steps[] = "— ستون listings.{$name} از قبل وجود دارد.";
    }
}

if (!db_has_table('ai_moderation_settings')) {
    try {
        DB::query("
            CREATE TABLE `ai_moderation_settings` (
                `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `shadow_mode` TINYINT(1) NOT NULL DEFAULT 1,
                `auto_approve_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 93,
                `auto_reject_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 96,
                `safe_category_slugs` JSON DEFAULT NULL,
                `never_approve_slugs` JSON DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $safeJson = json_encode(['book','game-console','toy','household-small','kitchenware','mobile-accessory','laptop-accessory','audio','clothing','watch'], JSON_UNESCAPED_UNICODE);
        $neverJson = json_encode(['real-estate','car','motorcycle','service','job','heavy-equipment'], JSON_UNESCAPED_UNICODE);
        DB::query(
            "INSERT INTO `ai_moderation_settings` (`id`,`safe_category_slugs`,`never_approve_slugs`) VALUES (1,?,?)",
            [$safeJson, $neverJson]
        );
        $steps[] = '✓ جدول ai_moderation_settings ساخته شد.';
    } catch (Throwable $e) {
        $steps[] = '✗ خطا در ساخت ai_moderation_settings: ' . $e->getMessage();
    }
} else {
    $steps[] = '— جدول ai_moderation_settings از قبل وجود دارد.';
}

echo implode("\n", $steps) . "\n\n✅ پایان فرآیند Migration ربات نظارت AI.\n";
