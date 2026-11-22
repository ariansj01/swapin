<?php
/**
 * موتور توصیه آگهی (فاز ۳ سیستم هوشمند سواپین)
 *
 * بر پایه پروفایل علایق کاربر (user_interest_weights — فاز ۲) آگهی‌های
 * فعال و مرتبط را امتیازدهی می‌کند. نمایش‌ها در recommendation_log ثبت
 * می‌شوند تا در فاز ۶ (یادگیری مستمر) به بازخورد تبدیل شوند.
 *
 * مستقل و بدون وابستگی مخرب؛ در نبود داده رفتار، به «آگهی‌های تازه» عقب‌نشینی می‌کند.
 */

require_once __DIR__ . '/behavior.php';

if (!function_exists('recommend_ensure_tables')) {
    function recommend_ensure_tables(): bool {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            if (!db_has_table('recommendation_log')) {
                DB::query("
                    CREATE TABLE IF NOT EXISTS `recommendation_log` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id` INT UNSIGNED NOT NULL,
                        `listing_id` INT UNSIGNED NOT NULL,
                        `surface` VARCHAR(30) NOT NULL DEFAULT 'dashboard',
                        `score` DECIMAL(8,2) NOT NULL DEFAULT 0,
                        `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                        `clicked` TINYINT(1) NOT NULL DEFAULT 0,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_rl_user_time` (`user_id`,`created_at`),
                        KEY `idx_rl_listing` (`listing_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            $ok = true;
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('recommend-tables-failed', ['error' => $e->getMessage()]);
            }
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('recommend_interest_maps')) {
    /**
     * نقشه وزن علایق کاربر (خروجی فاز ۲) به تفکیک ابعاد.
     * @return array{category:array<int,float>,brand:array<int,float>,model:array<int,float>,keyword:array<string,float>}
     */
    function recommend_interest_maps(int $userId): array {
        $out = ['category' => [], 'brand' => [], 'model' => [], 'keyword' => []];
        if ($userId <= 0 || !db_has_table('user_interest_weights')) {
            return $out;
        }
        $rows = DB::fetchAll(
            'SELECT dimension, ref_id, ref_key, weight FROM user_interest_weights
             WHERE user_id = ? ORDER BY weight DESC LIMIT 200',
            [$userId]
        );
        foreach ($rows as $r) {
            $w = (float)$r['weight'];
            if ($w <= 0) continue;
            switch ($r['dimension']) {
                case 'category': $out['category'][(int)$r['ref_id']] = $w; break;
                case 'brand':    $out['brand'][(int)$r['ref_id']]    = $w; break;
                case 'model':    $out['model'][(int)$r['ref_id']]    = $w; break;
                case 'keyword':  $out['keyword'][(string)$r['ref_key']] = $w; break;
            }
        }
        return $out;
    }
}

if (!function_exists('recommend_top_interest_category')) {
    /** پرعلاقه‌ترین دسته کاربر (برای پیام‌های هوشمند/ترغیب). */
    function recommend_top_interest_category(int $userId): ?array {
        $maps = recommend_interest_maps($userId);
        if (!$maps['category']) {
            return null;
        }
        $catId = (int)array_key_first($maps['category']);
        if ($catId <= 0 || !db_has_table('categories')) {
            return null;
        }
        $row = DB::fetch('SELECT id, name, slug FROM categories WHERE id = ? LIMIT 1', [$catId]);
        return $row ?: null;
    }
}

if (!function_exists('recommend_seen_listing_ids')) {
    /** آگهی‌هایی که کاربر اخیراً دیده است (برای حذف از توصیه‌ها). */
    function recommend_seen_listing_ids(int $userId, int $days = 14): array {
        if ($userId <= 0 || !db_has_table('user_events')) {
            return [];
        }
        $days = max(1, min(90, $days));
        $rows = DB::fetchAll(
            'SELECT DISTINCT object_id FROM user_events
             WHERE user_id = ? AND object_type = "listing" AND object_id IS NOT NULL
               AND event_type IN ("view_listing","click_listing","save_listing")
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT 500',
            [$userId, $days]
        );
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int)$r['object_id']] = true;
        }
        return $ids;
    }
}

if (!function_exists('recommend_candidates')) {
    /**
     * واکشی آگهی‌های نامزد برای امتیازدهی.
     * @param array<int,int> $categoryIds
     * @return list<array>
     */
    function recommend_candidates(int $userId, array $categoryIds, array $excludeIds, int $pool): array {
        $params = [$userId];
        $where  = [listing_public_sql('l'), 'l.user_id <> ?'];

        if ($categoryIds) {
            $ph = implode(',', array_fill(0, count($categoryIds), '?'));
            $where[] = "(c.id IN ($ph) OR c.parent_id IN ($ph))";
            foreach ($categoryIds as $cid) $params[] = $cid;
            foreach ($categoryIds as $cid) $params[] = $cid;
        }
        if ($excludeIds) {
            $ids = array_map('intval', array_slice(array_keys($excludeIds), 0, 400));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "l.id NOT IN ($ph)";
            foreach ($ids as $eid) $params[] = $eid;
        }

        $params[] = $pool;
        return DB::fetchAll(
            'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
                    c.name AS cat_name, c.slug AS cat_slug, c.parent_id AS cat_parent_id,
                    (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
             FROM listings l
             JOIN users u ON u.id = l.user_id
             JOIN categories c ON c.id = l.category_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC
             LIMIT ?',
            $params
        );
    }
}

if (!function_exists('recommend_score_row')) {
    /**
     * امتیازدهی یک آگهی نسبت به علایق کاربر.
     * @return array{score:float,reason:string}
     */
    function recommend_score_row(array $row, array $maps, array $catNames): array {
        $cid = (int)($row['category_id'] ?? 0);
        $pid = (int)($row['cat_parent_id'] ?? 0);
        $bid = (int)($row['brand_id'] ?? 0);
        $mid = (int)($row['model_id'] ?? 0);
        $title = (string)($row['title'] ?? '');

        $catW   = (float)($maps['category'][$cid] ?? 0);
        if ($catW <= 0 && $pid > 0) {
            $catW = (float)($maps['category'][$pid] ?? 0) * 0.6;
        }
        $brandW = (float)($maps['brand'][$bid] ?? 0);
        $modelW = (float)($maps['model'][$mid] ?? 0);

        $kwScore = 0.0;
        $bestKw  = '';
        if (!empty($maps['keyword']) && $title !== '') {
            foreach ($maps['keyword'] as $kw => $w) {
                if ($kw === '' || mb_strlen($kw) < 2) continue;
                if (mb_stripos($title, $kw) !== false) {
                    $kwScore += $w * 0.25;
                    if ($w > 0 && $bestKw === '') $bestKw = $kw;
                }
            }
            $kwScore = min(35.0, $kwScore);
        }

        $recency = 0.0;
        $ts = strtotime((string)($row['created_at'] ?? ''));
        if ($ts !== false) {
            $days = (time() - $ts) / 86400;
            if ($days <= 3)       $recency = 8.0;
            elseif ($days <= 7)   $recency = 5.0;
            elseif ($days <= 30)  $recency = 2.0;
        }

        $score = $catW * 0.5 + $brandW * 0.3 + $modelW * 0.35 + $kwScore + $recency;
        $score = round(min(100.0, $score), 2);

        $reason = '';
        if ($catW > 0 && $cid > 0 && isset($catNames[$cid])) {
            $reason = 'مطابق علاقه شما به «' . $catNames[$cid] . '»';
        } elseif ($catW > 0 && $pid > 0 && isset($catNames[$pid])) {
            $reason = 'مطابق علاقه شما به «' . $catNames[$pid] . '»';
        } elseif ($modelW > 0) {
            $reason = 'مدل مورد توجه شما';
        } elseif ($brandW > 0) {
            $reason = 'برند مورد توجه شما';
        } elseif ($kwScore > 0 && $bestKw !== '') {
            $reason = 'مطابق جستجوی «' . $bestKw . '»';
        } else {
            $reason = 'آگهی تازه و فعال در سواَپین';
        }

        return ['score' => $score, 'reason' => $reason];
    }
}

if (!function_exists('recommend_for_user')) {
    /**
     * توصیه آگهی برای کاربر.
     * @return list<array> هر ردیف شامل فیلدهای آگهی + recommend_score/recommend_reason
     */
    function recommend_for_user(int $userId, int $limit = 12, array $opts = []): array {
        $userId = (int)$userId;
        $limit  = max(1, min(48, $limit));
        if ($userId <= 0 || !db_has_table('listings')) {
            return [];
        }
        recommend_ensure_tables();

        $maps     = recommend_interest_maps($userId);
        $topCats  = array_slice(array_map('intval', array_keys($maps['category'])), 0, 10);
        $exclude  = recommend_seen_listing_ids($userId, (int)($opts['seen_days'] ?? 14));
        if (!empty($opts['exclude']) && is_array($opts['exclude'])) {
            foreach ($opts['exclude'] as $eid) {
                $exclude[(int)$eid] = true;
            }
        }

        $pool = max($limit * 5, 60);
        $rows = [];
        if ($topCats) {
            $rows = recommend_candidates($userId, $topCats, $exclude, $pool);
        }
        if (count($rows) < $limit) {
            $broad = recommend_candidates($userId, [], $exclude, $pool);
            $seen  = [];
            foreach ($rows as $r) { $seen[(int)$r['id']] = true; }
            foreach ($broad as $r) {
                if (!isset($seen[(int)$r['id']])) {
                    $rows[] = $r;
                }
                if (count($rows) >= $pool) break;
            }
        }
        if (!$rows) {
            return [];
        }

        // نام دسته‌ها برای متن دلیل
        $catIds = [];
        foreach ($rows as $r) {
            $catIds[(int)$r['category_id']] = true;
            if (!empty($r['cat_parent_id'])) $catIds[(int)$r['cat_parent_id']] = true;
        }
        $catNames = [];
        if ($catIds && db_has_table('categories')) {
            $ids = array_map('intval', array_keys($catIds));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::fetchAll("SELECT id, name FROM categories WHERE id IN ($ph)", $ids) as $c) {
                $catNames[(int)$c['id']] = (string)$c['name'];
            }
        }

        $scored = [];
        foreach ($rows as $r) {
            $s = recommend_score_row($r, $maps, $catNames);
            $r['recommend_score']  = $s['score'];
            $r['recommend_reason'] = $s['reason'];
            $scored[] = $r;
        }
        usort($scored, static function ($a, $b) {
            if ($a['recommend_score'] === $b['recommend_score']) {
                return strcmp((string)$b['created_at'], (string)$a['created_at']);
            }
            return $b['recommend_score'] <=> $a['recommend_score'];
        });

        return array_slice($scored, 0, $limit);
    }
}

if (!function_exists('recommend_log_impressions')) {
    /** ثبت نمایش توصیه‌ها (مبنای یادگیری فاز ۶). */
    function recommend_log_impressions(int $userId, array $items, string $surface = 'dashboard'): int {
        $userId = (int)$userId;
        if ($userId <= 0 || !$items || !recommend_ensure_tables()) {
            return 0;
        }
        $surface = mb_substr(trim($surface) !== '' ? trim($surface) : 'dashboard', 0, 30);
        $rows = [];
        $pos = 0;
        foreach (array_slice($items, 0, 24) as $it) {
            $lid = (int)($it['id'] ?? 0);
            if ($lid <= 0) continue;
            $rows[] = [$userId, $lid, $surface, (float)($it['recommend_score'] ?? 0), $pos++];
        }
        if (!$rows) {
            return 0;
        }
        $groups = [];
        $params = [];
        foreach ($rows as $r) {
            $groups[] = '(?,?,?,?,?,NOW())';
            foreach ($r as $v) $params[] = $v;
        }
        try {
            DB::query(
                'INSERT INTO recommendation_log (user_id, listing_id, surface, score, position, created_at)
                 VALUES ' . implode(', ', $groups),
                $params
            );
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('recommend-log-failed', ['error' => $e->getMessage()]);
            }
            return 0;
        }
        return count($rows);
    }
}

if (!function_exists('recommend_render_section')) {
    /**
     * رندر بخش «پیشنهاد برای شما» با کارت‌های آگهی.
     * @return int تعداد آیتم‌های رندر‌شده
     */
    function recommend_render_section(array $user, string $surface = 'dashboard', int $limit = 6): int {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return 0;
        }
        $items = recommend_for_user($userId, $limit);
        if (!$items) {
            return 0;
        }
        recommend_log_impressions($userId, $items, $surface);
        listing_card_prefetch($items);
        ?>
        <div class="card mb-8">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3);flex-wrap:wrap">
            <h3 style="margin:0;font-size:1rem">
              <i class="bi bi-magic" style="color:var(--accent-dark)"></i>
              پیشنهاد برای شما
            </h3>
            <a href="<?= APP_URL ?>/search/ai" class="btn btn-ghost btn-sm">
              <i class="bi bi-search"></i> جستجوی دقیق‌تر
            </a>
          </div>
          <div class="card-body">
            <div class="listings-grid">
              <?php $cardLayout = 'vertical'; foreach ($items as $l): ?>
                <?php include __DIR__ . '/listing_card.php'; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php
        return count($items);
    }
}
