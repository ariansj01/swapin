<?php
/**
 * رفتار کاربران (Behavior Tracking) — فاز ۰/۱ سیستم هوشمند سواپین
 *
 * زیرساخت ثبت رخدادهای رفتاری کاربران برای ساخت پروفایل هوشمند.
 *  - جدول append-only: user_events
 *  - ثبت سمت سرور: behavior_track()
 *  - ثبت دسته‌ای از beacon مرورگر: behavior_log_events()
 *  - زمینه صفحه برای کلاینت: behavior_render_client()
 *
 * این ماژول مستقل است و به هیچ بخش موجود وابستگی مخرب ندارد.
 */

if (!function_exists('behavior_event_types')) {
    /** رخدادهای مجاز — فقط این نوع‌ها ثبت می‌شوند. */
    function behavior_event_types(): array {
        return [
            'view_page', 'view_listing', 'view_category',
            'click_listing', 'search', 'filter_apply',
            'save_listing', 'unsave_listing',
            'start_chat', 'send_offer', 'valuation_request', 'ai_turn',
        ];
    }
}

if (!function_exists('behavior_ensure_tables')) {
    /** ساخت جدول رخدادها (با الگوی db_has_table مشابه سایر ماژول‌ها). */
    function behavior_ensure_tables(): bool {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            if (!db_has_table('user_events')) {
                DB::query("
                    CREATE TABLE IF NOT EXISTS `user_events` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id` INT UNSIGNED DEFAULT NULL,
                        `anon_id` CHAR(32) DEFAULT NULL,
                        `event_type` VARCHAR(40) NOT NULL,
                        `object_type` VARCHAR(20) DEFAULT NULL,
                        `object_id` INT UNSIGNED DEFAULT NULL,
                        `category_id` INT UNSIGNED DEFAULT NULL,
                        `brand_id` INT UNSIGNED DEFAULT NULL,
                        `model_id` INT UNSIGNED DEFAULT NULL,
                        `keywords` VARCHAR(255) DEFAULT NULL,
                        `event_value` DECIMAL(15,2) DEFAULT NULL,
                        `meta_json` JSON DEFAULT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_ue_user_time` (`user_id`,`created_at`),
                        KEY `idx_ue_anon_time` (`anon_id`,`created_at`),
                        KEY `idx_ue_type_time` (`event_type`,`created_at`),
                        KEY `idx_ue_object` (`object_type`,`object_id`),
                        KEY `idx_ue_category` (`category_id`,`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            $ok = true;
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('behavior-tables-failed', ['error' => $e->getMessage()]);
            }
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('behavior_anon_id')) {
    /** شناسه ناشناس بازدیدکننده (کوکی) — برای اتصال رفتار مهمان پیش از ورود. */
    function behavior_anon_id(): string {
        $cookie = (string)($_COOKIE['swapin_anon'] ?? '');
        if ($cookie !== '' && preg_match('/^[a-f0-9]{32}$/', $cookie)) {
            return $cookie;
        }
        $id = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie('swapin_anon', $id, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['swapin_anon'] = $id;
        return $id;
    }
}

if (!function_exists('behavior_link_anon')) {
    /** اتصال رخدادهای ناشناس به کاربر پس از ورود. */
    function behavior_link_anon(string $anonId, int $userId): int {
        if ($anonId === '' || $userId <= 0 || !behavior_ensure_tables()) {
            return 0;
        }
        try {
            $stmt = DB::query(
                'UPDATE user_events SET user_id = ? WHERE anon_id = ? AND user_id IS NULL',
                [$userId, $anonId]
            );
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}

if (!function_exists('behavior_is_bot')) {
    /** تشخیص خزنده‌ها — رفتارشان ثبت نمی‌شود. */
    function behavior_is_bot(): bool {
        if (defined('SWAPIN_SKIP_TRACK') && SWAPIN_SKIP_TRACK) {
            return true;
        }
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') {
            return true;
        }
        $needles = [
            'bot', 'crawler', 'spider', 'slurp', 'bingpreview', 'googlebot', 'yandex',
            'duckduckbot', 'baiduspider', 'facebookexternalhit', 'semrush', 'ahrefs',
            'headlesschrome', 'lighthouse', 'pingdom', 'uptimerobot',
        ];
        foreach ($needles as $needle) {
            if (strpos($ua, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('behavior_optout')) {
    /** کاربر با کوکی «swapin_optout=1» ردیابی را غیرفعال کرده است. */
    function behavior_optout(): bool {
        return (string)($_COOKIE['swapin_optout'] ?? '') === '1';
    }
}

if (!function_exists('behavior_normalize_event')) {
    /** اعتبارسنجی و نرمال‌سازی یک رخداد؛ در صورت نامعتبر بودن null برمی‌گرداند. */
    function behavior_normalize_event(array $ev, ?int $userId, string $anonId): ?array {
        $type = (string)($ev['event_type'] ?? '');
        if (!in_array($type, behavior_event_types(), true)) {
            return null;
        }

        $metaJson = null;
        $meta = $ev['meta'] ?? null;
        if (is_array($meta) && $meta) {
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false && mb_strlen($encoded) <= 2000) {
                $metaJson = $encoded;
            }
        }

        $keywords = isset($ev['keywords']) ? trim((string)$ev['keywords']) : '';
        if ($keywords !== '' && function_exists('clean')) {
            $keywords = trim((string)clean($keywords));
        }

        $value = $ev['value'] ?? null;
        $objectType = !empty($ev['object_type']) ? mb_substr((string)$ev['object_type'], 0, 20) : null;

        return [
            'user_id'     => $userId,
            'anon_id'     => $anonId !== '' ? $anonId : null,
            'event_type'  => $type,
            'object_type' => $objectType !== null && function_exists('clean') ? mb_substr((string)clean($objectType), 0, 20) : $objectType,
            'object_id'   => !empty($ev['object_id']) ? (int)$ev['object_id'] : null,
            'category_id' => !empty($ev['category_id']) ? (int)$ev['category_id'] : null,
            'brand_id'    => !empty($ev['brand_id']) ? (int)$ev['brand_id'] : null,
            'model_id'    => !empty($ev['model_id']) ? (int)$ev['model_id'] : null,
            'keywords'    => $keywords !== '' ? mb_substr($keywords, 0, 255) : null,
            'event_value' => ($value !== null && $value !== '' && is_numeric($value)) ? (float)$value : null,
            'meta_json'   => $metaJson,
            'created_at'  => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('behavior_log_events')) {
    /** ثبت دسته‌ای رخدادها. خروجی: تعداد رخداد ثبت‌شده. */
    function behavior_log_events(array $events, ?int $userId, string $anonId): int {
        if (!$events || !behavior_ensure_tables()) {
            return 0;
        }

        $rows = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $row = behavior_normalize_event($ev, $userId, $anonId);
            if ($row !== null) {
                $rows[] = $row;
            }
            if (count($rows) >= 25) {
                break;
            }
        }
        if (!$rows) {
            return 0;
        }

        $cols = array_keys($rows[0]);
        $colSql = implode(', ', array_map(static function ($c) { return "`$c`"; }, $cols));
        $groups = [];
        $params = [];
        foreach ($rows as $row) {
            $groups[] = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            foreach ($cols as $c) {
                $params[] = $row[$c];
            }
        }

        try {
            DB::query('INSERT INTO `user_events` (' . $colSql . ') VALUES ' . implode(', ', $groups), $params);
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('behavior-log-failed', ['error' => $e->getMessage()]);
            }
            return 0;
        }
        return count($rows);
    }
}

if (!function_exists('behavior_track')) {
    /** ثبت یک رخداد از سمت سرور (برای نقاطی که JS در دسترس نیست). */
    function behavior_track(string $type, array $data = []): void {
        if (behavior_is_bot() || behavior_optout()) {
            return;
        }
        $user = function_exists('auth_user') ? auth_user() : null;
        $uid  = $user ? (int)$user['id'] : null;
        $anon = (string)($_COOKIE['swapin_anon'] ?? '');
        if ($uid === null && $anon === '') {
            return; // رخداد بدون هیچ هویتی ثبت نمی‌شود
        }
        $data['event_type'] = $type;
        behavior_log_events([$data], $uid, $anon);
    }
}

if (!function_exists('behavior_context')) {
    /** زمینه فعلی صفحه؛ صفحات می‌توانند $GLOBALS['swaapin_behavior_context'] را پر کنند. */
    function behavior_context(): array {
        $ctx = $GLOBALS['swaapin_behavior_context'] ?? null;
        if (!is_array($ctx)) {
            $ctx = ['page_type' => 'page'];
        }
        $ctx['app_url'] = defined('APP_URL') ? APP_URL : '';
        return $ctx;
    }
}

if (!function_exists('behavior_render_client')) {
    /** درج context (JSON) + اسکریپت کلاینت در انتهای صفحات عمومی. */
    function behavior_render_client(): void {
        if (behavior_is_bot() || behavior_optout()) {
            return;
        }
        behavior_ensure_tables();
        behavior_anon_id(); // اطمینان از وجود شناسه ناشناس

        $ctx = behavior_context();
        $json = json_encode(
            $ctx,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            return;
        }
        $url = defined('APP_URL') ? APP_URL : '';
        $ver = @filemtime(__DIR__ . '/../src/js/behavior.js') ?: time();

        echo '<script type="application/json" id="swaapin-behavior-context">' . $json . '</script>' . "\n";
        echo '<script defer src="' . $url . '/src/js/behavior.js?v=' . $ver . '"></script>' . "\n";
    }
}
