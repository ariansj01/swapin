<?php
// includes/banners.php
// بنرهای اسلایدر صفحه اصلی (زیر استوری‌ها)

function banners_ensure_tables(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!db_has_table('banners')) {
        DB::query("CREATE TABLE IF NOT EXISTS `banners` (
          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
          `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `subtitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `badge` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `link_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `sort_order` int NOT NULL DEFAULT 0,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_banners_active` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/** بنرهای فعال برای نمایش در سایت */
function banners_active(): array {
    banners_ensure_tables();
    return DB::fetchAll('SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id DESC');
}

/** همه بنرها برای پنل مدیریت */
function banners_all(): array {
    banners_ensure_tables();
    return DB::fetchAll('SELECT * FROM banners ORDER BY sort_order ASC, id DESC');
}

function banners_get(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    banners_ensure_tables();
    return DB::fetch('SELECT * FROM banners WHERE id = ?', [$id]);
}

/** فقط لینک‌های http(s) یا مسیر داخلی مجاز هستند */
function banners_safe_link(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    return preg_match('#^(https?://|/)#i', $url) ? $url : '';
}
