<?php
/**
 * سازنده پروفایل هوشمند کاربر (فاز ۲)
 *
 * با تجمیع رخدادهای رفتاری (user_events) و منابع موجود
 * (آگهی‌های ذخیره‌شده، جستجوهای ذخیره‌شده، نیازهای ISO و آگهی‌های خود کاربر)
 * دو خروجی می‌سازد:
 *   - user_interest_weights : وزن علاقه به دسته/برند/مدل/کلیدواژه
 *   - user_needs            : نیازهای استخراج‌شده
 *
 * کاملاً قاعده‌محور و بدون فراخوانی LLM؛ مناسب اجرا در کرون.
 */

require_once __DIR__ . '/behavior.php';

if (!function_exists('profile_ensure_tables')) {
    function profile_ensure_tables(): bool {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            if (!db_has_table('user_interest_weights')) {
                DB::query("
                    CREATE TABLE IF NOT EXISTS `user_interest_weights` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id` INT UNSIGNED NOT NULL,
                        `dimension` ENUM('category','brand','model','keyword','location') NOT NULL,
                        `ref_id` INT UNSIGNED NOT NULL DEFAULT 0,
                        `ref_key` VARCHAR(160) NOT NULL DEFAULT '',
                        `weight` DECIMAL(10,4) NOT NULL DEFAULT 0,
                        `confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        `signals` INT UNSIGNED NOT NULL DEFAULT 0,
                        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uq_uiw` (`user_id`,`dimension`,`ref_id`,`ref_key`),
                        KEY `idx_uiw_user` (`user_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            if (!db_has_table('user_needs')) {
                DB::query("
                    CREATE TABLE IF NOT EXISTS `user_needs` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id` INT UNSIGNED NOT NULL,
                        `source` ENUM('iso','search','chat','listing','event') NOT NULL DEFAULT 'event',
                        `need_text` VARCHAR(500) NOT NULL,
                        `category_id` INT UNSIGNED DEFAULT NULL,
                        `brand_id` INT UNSIGNED DEFAULT NULL,
                        `model_id` INT UNSIGNED DEFAULT NULL,
                        `price_min` INT UNSIGNED DEFAULT NULL,
                        `price_max` INT UNSIGNED DEFAULT NULL,
                        `want_type` VARCHAR(20) DEFAULT NULL,
                        `city` VARCHAR(80) DEFAULT NULL,
                        `confidence` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        `status` ENUM('active','stale','fulfilled') NOT NULL DEFAULT 'active',
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_un_user` (`user_id`,`status`),
                        KEY `idx_un_cat` (`category_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            $ok = true;
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('profile-tables-failed', ['error' => $e->getMessage()]);
            }
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('profile_event_weight')) {
    /** وزن پایه هر نوع رخداد در محاسبه علاقه. */
    function profile_event_weight(string $type): float {
        static $map = [
            'view_page'         => 0.2,
            'view_listing'      => 1.0,
            'view_category'     => 0.5,
            'click_listing'     => 1.5,
            'search'            => 1.0,
            'filter_apply'      => 0.5,
            'save_listing'      => 3.0,
            'unsave_listing'    => -3.0,
            'start_chat'        => 3.0,
            'send_offer'        => 5.0,
            'valuation_request' => 2.0,
            'ai_turn'           => 2.0,
        ];
        return $map[$type] ?? 0.0;
    }
}

if (!function_exists('profile_decay')) {
    /** کاهش وزن بر اساس قدمت رخداد (نیمه‌عمر تقریبی ۳۰ روز). */
    function profile_decay(string $createdAt): float {
        $ts = strtotime($createdAt);
        if ($ts === false) return 1.0;
        $days = (time() - $ts) / 86400;
        if ($days <= 7)  return 1.0;
        if ($days <= 30) return 0.6;
        if ($days <= 90) return 0.3;
        return 0.1;
    }
}

if (!function_exists('profile_confidence')) {
    /** میزان اطمینان بر اساس تعداد سیگنال‌ها و تعداد منابع مستقل. */
    function profile_confidence(int $signals, int $sources): int {
        return (int) max(0, min(95, 10 + $signals * 5 + $sources * 8));
    }
}

if (!function_exists('profile_normalize')) {
    /**
     * نرمال‌سازی وزن‌ها به بازه ۰..۱۰۰ نسبت به بزرگ‌ترین مقدار.
     * خروجی: [key => ['weight'=>float,'signals'=>int]]
     */
    function profile_normalize(array $weights, array $signals): array {
        if (!$weights) return [];
        $max = max($weights);
        if ($max <= 0) return [];
        $out = [];
        foreach ($weights as $key => $w) {
            $norm = max(0.0, min(100.0, ($w / $max) * 100.0));
            if ($norm <= 0) continue;
            $out[$key] = ['weight' => round($norm, 4), 'signals' => (int)($signals[$key] ?? 1)];
        }
        arsort($out);
        return $out;
    }
}

if (!function_exists('profile_build_user')) {
    /**
     * ساخت/به‌روزرسانی پروفایل یک کاربر.
     * خروجی خلاصه: ['ok'=>bool,'user_id'=>int,'weights'=>int,'needs'=>int]
     */
    function profile_build_user(int $userId): array {
        $userId = (int)$userId;
        if ($userId <= 0 || !profile_ensure_tables() || !behavior_ensure_tables()) {
            return ['ok' => false, 'user_id' => $userId, 'weights' => 0, 'needs' => 0];
        }

        $catW = []; $catSig = [];
        $brandW = []; $brandSig = [];
        $modelW = []; $modelSig = [];
        $kwW = []; $kwSig = [];
        $sources = 0;

        // ۱) رخدادهای رفتاری (۹۰ روز اخیر)
        if (db_has_table('user_events')) {
            $events = DB::fetchAll(
                'SELECT event_type, category_id, brand_id, model_id, keywords, created_at
                 FROM user_events
                 WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                 ORDER BY id DESC LIMIT 5000',
                [$userId]
            );
            if ($events) $sources++;
            foreach ($events as $e) {
                $w = profile_event_weight((string)$e['event_type']) * profile_decay((string)$e['created_at']);
                if ($w === 0.0) continue;

                $cid = (int)($e['category_id'] ?? 0);
                if ($cid > 0) { $catW[$cid] = ($catW[$cid] ?? 0) + $w; $catSig[$cid] = ($catSig[$cid] ?? 0) + 1; }

                $bid = (int)($e['brand_id'] ?? 0);
                if ($bid > 0) { $brandW[$bid] = ($brandW[$bid] ?? 0) + $w; $brandSig[$bid] = ($brandSig[$bid] ?? 0) + 1; }

                $mid = (int)($e['model_id'] ?? 0);
                if ($mid > 0) { $modelW[$mid] = ($modelW[$mid] ?? 0) + $w; $modelSig[$mid] = ($modelSig[$mid] ?? 0) + 1; }

                $kws = trim((string)($e['keywords'] ?? ''));
                if ($kws !== '') {
                    foreach (preg_split('/\s+/u', $kws) ?: [] as $k) {
                        $k = trim($k);
                        if (mb_strlen($k) < 2) continue;
                        $kwW[$k] = ($kwW[$k] ?? 0) + $w * 0.5;
                        $kwSig[$k] = ($kwSig[$k] ?? 0) + 1;
                    }
                }
            }
        }

        // ۲) آگهی‌های ذخیره‌شده (علاقه صریح به کالا)
        if (db_has_table('saved_listings')) {
            $rows = DB::fetchAll(
                'SELECT l.category_id, l.brand_id, l.model_id
                 FROM saved_listings s JOIN listings l ON l.id = s.listing_id
                 WHERE s.user_id = ?',
                [$userId]
            );
            if ($rows) $sources++;
            foreach ($rows as $r) {
                $cid = (int)($r['category_id'] ?? 0);
                if ($cid > 0) { $catW[$cid] = ($catW[$cid] ?? 0) + 4; $catSig[$cid] = ($catSig[$cid] ?? 0) + 1; }
                $bid = (int)($r['brand_id'] ?? 0);
                if ($bid > 0) { $brandW[$bid] = ($brandW[$bid] ?? 0) + 4; $brandSig[$bid] = ($brandSig[$bid] ?? 0) + 1; }
                $mid = (int)($r['model_id'] ?? 0);
                if ($mid > 0) { $modelW[$mid] = ($modelW[$mid] ?? 0) + 4; $modelSig[$mid] = ($modelSig[$mid] ?? 0) + 1; }
            }
        }

        // ۳) جستجوهای ذخیره‌شده
        $savedSearchRows = [];
        if (db_has_table('saved_searches')) {
            $savedSearchRows = DB::fetchAll(
                'SELECT category_id, keywords, need_text, city, price_min, price_max, want_type
                 FROM saved_searches WHERE user_id = ?',
                [$userId]
            );
            if ($savedSearchRows) $sources++;
            foreach ($savedSearchRows as $r) {
                $cid = (int)($r['category_id'] ?? 0);
                if ($cid > 0) { $catW[$cid] = ($catW[$cid] ?? 0) + 5; $catSig[$cid] = ($catSig[$cid] ?? 0) + 1; }
                $kws = trim((string)($r['keywords'] ?? ''));
                if ($kws === '') $kws = trim((string)($r['need_text'] ?? ''));
                foreach (preg_split('/\s+/u', $kws) ?: [] as $k) {
                    $k = trim($k);
                    if (mb_strlen($k) < 2) continue;
                    $kwW[$k] = ($kwW[$k] ?? 0) + 2;
                    $kwSig[$k] = ($kwSig[$k] ?? 0) + 1;
                }
            }
        }

        // ۴) نیازهای ISO (بیان صریح نیاز)
        $isoRows = [];
        if (db_has_table('iso_requests')) {
            $isoRows = DB::fetchAll(
                'SELECT title, category_id, city, created_at
                 FROM iso_requests WHERE user_id = ? AND status = "active"',
                [$userId]
            );
            if ($isoRows) $sources++;
            foreach ($isoRows as $r) {
                $cid = (int)($r['category_id'] ?? 0);
                if ($cid > 0) { $catW[$cid] = ($catW[$cid] ?? 0) + 6; $catSig[$cid] = ($catSig[$cid] ?? 0) + 1; }
            }
        }

        // ۵) آگهی‌های خودِ کاربر (دارایی قابل مبادله)
        $myListings = DB::fetchAll(
            'SELECT category_id, brand_id, model_id FROM listings
             WHERE user_id = ? AND status <> "deleted" LIMIT 500',
            [$userId]
        );
        if ($myListings) $sources++;
        foreach ($myListings as $r) {
            $cid = (int)($r['category_id'] ?? 0);
            if ($cid > 0) { $catW[$cid] = ($catW[$cid] ?? 0) + 1; }
        }

        // محاسبه اطمینان هر ابعاد (کلید می‌تواند عددی (id) یا رشته‌ای (کلیدواژه) باشد)
        $confOf = static function (array $sig, $key, int $sources) {
            return profile_confidence((int)($sig[$key] ?? 1), $sources);
        };

        $catNorm   = profile_normalize($catW, $catSig);
        $brandNorm = profile_normalize($brandW, $brandSig);
        $modelNorm = profile_normalize($modelW, $modelSig);
        $kwNorm    = profile_normalize($kwW, $kwSig);

        // نوشتن وزن‌ها (بازنویسی کامل)
        $weightRows = [];
        $lim = 0;
        foreach ($catNorm as $id => $d) {
            if (++$lim > 20) break;
            $weightRows[] = ['category', (int)$id, '', $d['weight'], $confOf($catSig, (int)$id, $sources), $d['signals']];
        }
        $lim = 0;
        foreach ($brandNorm as $id => $d) {
            if (++$lim > 12) break;
            $weightRows[] = ['brand', (int)$id, '', $d['weight'], $confOf($brandSig, (int)$id, $sources), $d['signals']];
        }
        $lim = 0;
        foreach ($modelNorm as $id => $d) {
            if (++$lim > 12) break;
            $weightRows[] = ['model', (int)$id, '', $d['weight'], $confOf($modelSig, (int)$id, $sources), $d['signals']];
        }
        $lim = 0;
        foreach ($kwNorm as $kw => $d) {
            if (++$lim > 20) break;
            $weightRows[] = ['keyword', 0, mb_substr((string)$kw, 0, 160), $d['weight'], $confOf($kwSig, $kw, $sources), $d['signals']];
        }

        $userIdInt = $userId;
        try {
            DB::query('DELETE FROM user_interest_weights WHERE user_id = ?', [$userIdInt]);
            if ($weightRows) {
                $groups = [];
                $params = [];
                foreach ($weightRows as $r) {
                    $groups[] = '(?,?,?,?,?,?,?,NOW())';
                    $params[] = $userIdInt;
                    $params[] = $r[0];
                    $params[] = $r[1];
                    $params[] = $r[2];
                    $params[] = $r[3];
                    $params[] = $r[4];
                    $params[] = $r[5];
                }
                DB::query(
                    'INSERT INTO user_interest_weights
                     (user_id, dimension, ref_id, ref_key, weight, confidence, signals, updated_at)
                     VALUES ' . implode(', ', $groups),
                    $params
                );
            }
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('profile-weights-failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        // نوشتن نیازها (منابع iso و search بازنویسی می‌شوند)
        $needRows = [];
        foreach ($isoRows as $r) {
            $title = trim((string)($r['title'] ?? ''));
            if ($title === '') continue;
            $needRows[] = [
                'iso',
                mb_substr($title, 0, 500),
                (int)($r['category_id'] ?? 0) ?: null,
                trim((string)($r['city'] ?? '')) ?: null,
                70,
            ];
        }
        foreach ($savedSearchRows as $r) {
            $text = trim((string)($r['need_text'] ?? ''));
            if ($text === '') $text = trim((string)($r['keywords'] ?? ''));
            if ($text === '') continue;
            $needRows[] = [
                'search',
                mb_substr($text, 0, 500),
                (int)($r['category_id'] ?? 0) ?: null,
                trim((string)($r['city'] ?? '')) ?: null,
                50,
            ];
        }

        $needsWritten = 0;
        try {
            DB::query('DELETE FROM user_needs WHERE user_id = ? AND source IN ("iso","search")', [$userIdInt]);
            if ($needRows) {
                $groups = [];
                $params = [];
                foreach ($needRows as $r) {
                    $groups[] = '(?,?,?,?,?,?,NOW(),NOW(),"active")';
                    $params[] = $userIdInt;
                    $params[] = $r[0];
                    $params[] = $r[1];
                    $params[] = $r[2];
                    $params[] = $r[3];
                    $params[] = $r[4];
                }
                DB::query(
                    'INSERT INTO user_needs
                     (user_id, source, need_text, category_id, city, confidence, created_at, updated_at, status)
                     VALUES ' . implode(', ', $groups),
                    $params
                );
                $needsWritten = count($needRows);
            }
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('profile-needs-failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return [
            'ok'      => true,
            'user_id' => $userId,
            'weights' => count($weightRows),
            'needs'   => $needsWritten,
        ];
    }
}

if (!function_exists('profile_build_all_users')) {
    /**
     * ساخت پروفایل برای همه کاربران دارای فعالیت اخیر.
     * @param int $days  بازه فعالیت اخیر (روز)
     * @param int $limit حداکثر تعداد کاربر (۰ = بی‌نهایت)
     */
    function profile_build_all_users(int $days = 30, int $limit = 0): array {
        if (!profile_ensure_tables() || !behavior_ensure_tables()) {
            return ['users' => 0, 'built' => 0];
        }
        $days = max(1, $days);
        $sql = 'SELECT DISTINCT user_id FROM user_events
                WHERE user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$days];
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $ids = array_map(static function ($r) { return (int)$r['user_id']; }, DB::fetchAll($sql, $params));

        $built = 0;
        foreach ($ids as $id) {
            $res = profile_build_user($id);
            if (!empty($res['ok'])) $built++;
        }
        return ['users' => count($ids), 'built' => $built];
    }
}

if (!function_exists('profile_prune_events')) {
    /** پاکسازی رخدادهای قدیمی‌تر از N روز (برای کنترل حجم جدول). */
    function profile_prune_events(int $days = 90): int {
        $days = max(7, $days);
        if (!behavior_ensure_tables()) return 0;
        try {
            $stmt = DB::query(
                'DELETE FROM user_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$days]
            );
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}
