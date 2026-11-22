<?php
/**
 * دستیار کشف نیاز (فاز ۵ سیستم هوشمند سواپین)
 *
 * نیاز کاربر را از زبان طبیعی به فیلترهای ساختاریافته تبدیل و در
 * جدول user_needs ذخیره می‌کند (حلقه یادگیری). در صورت درخواست،
 * آن را به «جستجوی ذخیره‌شده» با هشدار تبدیل می‌کند.
 *
 * وابسته به ماژول‌های موجود (ai_parse_need_search / create_saved_search).
 */

require_once __DIR__ . '/behavior.php';
require_once __DIR__ . '/profile_builder.php';

if (!function_exists('need_discovery_normalize_want_type')) {
    function need_discovery_normalize_want_type(?string $wantType): ?string {
        $wantType = trim((string)$wantType);
        return in_array($wantType, ['item', 'service', 'credit'], true) ? $wantType : null;
    }
}

if (!function_exists('need_discovery_record')) {
    /**
     * ثبت نیاز کشف‌شده در user_needs.
     * @param array $filters خروجی ai_parse_need_search
     * @return int شناسه نیاز ثبت‌شده (۰ در صورت خطا/تکراری)
     */
    function need_discovery_record(int $userId, array $filters, string $needText, string $source = 'chat'): int {
        $userId   = (int)$userId;
        $needText = mb_substr(trim($needText), 0, 500);
        $source   = in_array($source, ['iso', 'search', 'chat', 'listing', 'event'], true) ? $source : 'chat';
        if ($userId <= 0 || $needText === '' || !profile_ensure_tables()) {
            return 0;
        }

        try {
            $existing = DB::fetch(
                'SELECT id FROM user_needs
                 WHERE user_id = ? AND need_text = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 LIMIT 1',
                [$userId, $needText]
            );
            if ($existing) {
                return (int)$existing['id'];
            }

            return (int)DB::insert('user_needs', [
                'user_id'     => $userId,
                'source'      => $source,
                'need_text'   => $needText,
                'category_id' => !empty($filters['category_id']) ? (int)$filters['category_id'] : null,
                'brand_id'    => !empty($filters['brand_id']) ? (int)$filters['brand_id'] : null,
                'model_id'    => !empty($filters['model_id']) ? (int)$filters['model_id'] : null,
                'price_min'   => !empty($filters['price_min']) ? (int)$filters['price_min'] : null,
                'price_max'   => !empty($filters['price_max']) ? (int)$filters['price_max'] : null,
                'want_type'   => need_discovery_normalize_want_type($filters['want_type'] ?? null),
                'city'        => !empty($filters['city']) ? mb_substr((string)$filters['city'], 0, 80) : null,
                'confidence'  => 60,
                'status'      => 'active',
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('need-discovery-record-failed', ['error' => $e->getMessage()]);
            }
            return 0;
        }
    }
}

if (!function_exists('need_discovery_recent')) {
    /** نیازهای فعال اخیر کاربر. */
    function need_discovery_recent(int $userId, int $limit = 5): array {
        if ($userId <= 0 || !db_has_table('user_needs')) {
            return [];
        }
        return DB::fetchAll(
            'SELECT n.*, c.name AS cat_name
             FROM user_needs n
             LEFT JOIN categories c ON c.id = n.category_id
             WHERE n.user_id = ? AND n.status = "active"
             ORDER BY n.updated_at DESC LIMIT ?',
            [$userId, max(1, min(20, $limit))]
        );
    }
}

if (!function_exists('need_discovery_discover')) {
    /**
     * کشف نیاز از متن آزاد: ساختاری‌سازی + ثبت + جستجوی اولیه.
     * @return array{ok:bool,need_id:int,filters:array,listings:list<array>,total:int,source:string}
     */
    function need_discovery_discover(int $userId, string $needText, ?string $city = null, bool $save = false): array {
        $needText = trim($needText);
        if ($userId <= 0 || mb_strlen($needText) < 3) {
            return ['ok' => false, 'need_id' => 0, 'filters' => [], 'listings' => [], 'total' => 0, 'source' => 'rules'];
        }

        $result  = ai_search_listings_by_need($needText, $city, 6);
        $filters = $result['filters'] ?? [];

        $needId = need_discovery_record($userId, $filters, $needText, 'chat');

        if ($save && function_exists('create_saved_search') && saved_searches_enabled()) {
            $keywords = is_array($filters['keywords'] ?? null) ? implode(' ', $filters['keywords']) : (string)($filters['keywords'] ?? '');
            create_saved_search($userId, [
                'need_text'   => $needText,
                'keywords'    => $keywords,
                'city'        => $filters['city'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'price_min'   => $filters['price_min'] ?? null,
                'price_max'   => $filters['price_max'] ?? null,
                'want_type'   => need_discovery_normalize_want_type($filters['want_type'] ?? null),
            ]);
        }

        return [
            'ok'       => true,
            'need_id'  => $needId,
            'filters'  => $filters,
            'listings' => $result['listings'] ?? [],
            'total'    => (int)($result['total'] ?? 0),
            'source'   => (string)($result['source'] ?? 'rules'),
        ];
    }
}
