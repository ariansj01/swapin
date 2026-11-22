<?php
/**
 * یادگیری مستمر (فاز ۶ سیستم هوشمند سواپین)
 *
 * بازخورد حلقه توصیه را به علایق کاربر برمی‌گرداند:
 *  - کلیک/تعامل روی توصیه‌ها → تقویت علاقه همان ابعاد
 *  - نیازهای قدیمیِ رفع‌نشده → «کهنه» علامت می‌خورند
 *  - لاگ توصیه‌های قدیمی پاکسازی می‌شود
 *
 * توابع این ماژول در بازسازی پروفایل (فاز ۲) و کرون استفاده می‌شوند.
 */

require_once __DIR__ . '/behavior.php';

if (!function_exists('learning_mark_recommendation_clicks')) {
    /**
     * علامت‌گذاری توصیه‌هایی که کاربر روی آن‌ها تعامل کرده است.
     * @return int تعداد ردیف‌های به‌روزشده
     */
    function learning_mark_recommendation_clicks(int $days = 14): int {
        $days = max(1, min(120, $days));
        if (!db_has_table('recommendation_log') || !db_has_table('user_events')) {
            return 0;
        }
        try {
            $stmt = DB::query(
                'UPDATE recommendation_log rl
                 JOIN user_events e
                   ON e.user_id = rl.user_id
                  AND e.object_type = "listing"
                  AND e.object_id = rl.listing_id
                  AND e.event_type IN ("click_listing","save_listing","start_chat","send_offer")
                  AND e.created_at >= rl.created_at
                 SET rl.clicked = 1
                 WHERE rl.clicked = 0 AND rl.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$days]
            );
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}

if (!function_exists('learning_recommendation_signals')) {
    /**
     * سیگنال علاقه برگرفته از توصیه‌های موفق (کلیک‌شده).
     * @return array{category:array<int,float>,brand:array<int,float>,model:array<int,float>}
     */
    function learning_recommendation_signals(int $userId, int $days = 60): array {
        $out = ['category' => [], 'brand' => [], 'model' => []];
        $userId = (int)$userId;
        if ($userId <= 0 || !db_has_table('recommendation_log') || !db_has_table('listings')) {
            return $out;
        }
        $days = max(7, min(180, $days));
        try {
            $rows = DB::fetchAll(
                'SELECT l.category_id, l.brand_id, l.model_id, COUNT(*) AS c
                 FROM recommendation_log rl
                 JOIN listings l ON l.id = rl.listing_id
                 WHERE rl.user_id = ? AND rl.clicked = 1
                   AND rl.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY l.category_id, l.brand_id, l.model_id
                 LIMIT 100',
                [$userId, $days]
            );
        } catch (Throwable) {
            return $out;
        }
        foreach ($rows as $r) {
            $w = min(30.0, (float)$r['c'] * 2.0);
            $cid = (int)($r['category_id'] ?? 0);
            $bid = (int)($r['brand_id'] ?? 0);
            $mid = (int)($r['model_id'] ?? 0);
            if ($cid > 0) $out['category'][$cid] = ($out['category'][$cid] ?? 0) + $w * 0.6;
            if ($bid > 0) $out['brand'][$bid]    = ($out['brand'][$bid] ?? 0) + $w;
            if ($mid > 0) $out['model'][$mid]    = ($out['model'][$mid] ?? 0) + $w;
        }
        return $out;
    }
}

if (!function_exists('learning_mark_stale_needs')) {
    /** علامت‌گذاری نیازهای فعالِ قدیمی به‌عنوان کهنه. */
    function learning_mark_stale_needs(int $days = 90): int {
        $days = max(7, min(365, $days));
        if (!db_has_table('user_needs')) {
            return 0;
        }
        try {
            $stmt = DB::query(
                'UPDATE user_needs SET status = "stale", updated_at = NOW()
                 WHERE status = "active" AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$days]
            );
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}

if (!function_exists('learning_prune_recommendations')) {
    /** حذف لاگ توصیه‌های قدیمی برای کنترل حجم جدول. */
    function learning_prune_recommendations(int $days = 90): int {
        $days = max(30, $days);
        if (!db_has_table('recommendation_log')) {
            return 0;
        }
        try {
            $stmt = DB::query(
                'DELETE FROM recommendation_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$days]
            );
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}
