<?php
/**
 * ترغیب هوشمند به ثبت آگهی (فاز ۴ سیستم هوشمند سواپین)
 *
 * بر پایه رفتار کاربر (فاز ۱) و پروفایل علایق (فاز ۲) تشخیص می‌دهد
 * کاربر «کاوشگر منفعل» است (به‌دنبال کالا می‌گردد اما آگهی فعال ندارد)
 * و پیام/فراخوان مناسب نمایش می‌دهد تا آگهی ثبت کند.
 *
 * بدون جدول جدید؛ رد/بستن پیام با کوکی نگه داشته می‌شود.
 */

require_once __DIR__ . '/behavior.php';

if (!function_exists('nudge_dismissed_kinds')) {
    /** انواع پیام‌هایی که کاربر بسته است (کوکی swapin_nudge). */
    function nudge_dismissed_kinds(): array {
        $raw = (string)($_COOKIE['swapin_nudge'] ?? '');
        if ($raw === '') return [];
        $out = [];
        foreach (explode(',', $raw) as $k) {
            $k = preg_replace('/[^a-z_]/', '', strtolower(trim($k)));
            if ($k !== '') $out[$k] = true;
        }
        return $out;
    }
}

if (!function_exists('nudge_user_active_in_category')) {
    /** آیا کاربر آگهی فعال در این دسته (یا فرزندان آن) دارد؟ */
    function nudge_user_active_in_category(int $userId, int $catId): bool {
        if ($userId <= 0 || $catId <= 0) {
            return false;
        }
        try {
            $row = DB::fetch(
                'SELECT COUNT(*) AS c
                 FROM listings l JOIN categories c ON c.id = l.category_id
                 WHERE l.user_id = ? AND ' . listing_public_sql('l') . '
                   AND (l.category_id = ? OR c.parent_id = ?)',
                [$userId, $catId, $catId]
            );
            return (int)($row['c'] ?? 0) > 0;
        } catch (Throwable) {
            return true; // در صورت خطا پیام نشان نده
        }
    }
}

if (!function_exists('nudge_analyze_user')) {
    /**
     * تحلیل اینکه کاربر نیاز به ترغیب دارد یا نه.
     * @return array{show:bool,kind:string,title:string,message:string,cta_url:string,cta_label:string,icon:string}
     */
    function nudge_analyze_user(int $userId): array {
        $none = [
            'show' => false, 'kind' => '', 'title' => '', 'message' => '',
            'cta_url' => '', 'cta_label' => '', 'icon' => '',
        ];
        $userId = (int)$userId;
        if ($userId <= 0 || !db_has_table('listings')) {
            return $none;
        }
        $dismissed = nudge_dismissed_kinds();

        try {
            $activeListings = (int)(DB::fetch(
                'SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND ' . listing_public_sql('listings'),
                [$userId]
            )['c'] ?? 0);

            $recentEvents = 0;
            if (db_has_table('user_events')) {
                $recentEvents = (int)(DB::fetch(
                    'SELECT COUNT(*) AS c FROM user_events
                     WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)',
                    [$userId]
                )['c'] ?? 0);
            }
            $saved = 0;
            if (db_has_table('saved_listings')) {
                $saved = (int)(DB::fetch('SELECT COUNT(*) AS c FROM saved_listings WHERE user_id = ?', [$userId])['c'] ?? 0);
            }
            $searches = 0;
            if (db_has_table('saved_searches')) {
                $searches = (int)(DB::fetch('SELECT COUNT(*) AS c FROM saved_searches WHERE user_id = ?', [$userId])['c'] ?? 0);
            }
        } catch (Throwable) {
            return $none;
        }

        $topCat = function_exists('recommend_top_interest_category')
            ? recommend_top_interest_category($userId)
            : null;
        $catName = $topCat ? (string)$topCat['name'] : '';

        // حالت ۱: کاوشگر منفعل — علاقه/فعالیت دارد اما هیچ آگهی فعالی ندارد
        if ($activeListings === 0 && ($recentEvents >= 4 || $saved >= 1 || $searches >= 1)) {
            if (isset($dismissed['start_listing'])) {
                return $none;
            }
            $msg = $catName !== ''
                ? 'به «' . $catName . '» علاقه دارید. برای دریافت پیشنهاد معاوضه، کالای خود را ثبت کنید.'
                : 'در حال کاوش آگهی‌ها هستید. برای دریافت پیشنهاد معاوضه، کالای خود را ثبت کنید.';
            return [
                'show'      => true,
                'kind'      => 'start_listing',
                'title'     => 'اولین آگهی خود را ثبت کنید',
                'message'   => $msg,
                'cta_url'   => APP_URL . '/listings/create',
                'cta_label' => 'ثبت آگهی',
                'icon'      => 'bi-plus-circle',
            ];
        }

        // حالت ۲: گسترش به دسته پرعلاقه — آگهی دارید اما در دسته مورد علاقه آگهی ندارید
        if ($activeListings > 0 && $topCat) {
            $catId = (int)$topCat['id'];
            if (!isset($dismissed['expand_category']) && !nudge_user_active_in_category($userId, $catId)) {
                return [
                    'show'      => true,
                    'kind'      => 'expand_category',
                    'title'     => 'در دسته «' . $catName . '» آگهی ندارید',
                    'message'   => 'شما به «' . $catName . '» علاقه دارید اما آگهی فعالی در این دسته ندارید. با ثبت آگهی، شانس معاوضه بیشتر می‌شود.',
                    'cta_url'   => APP_URL . '/listings/create',
                    'cta_label' => 'ثبت آگهی در این دسته',
                    'icon'      => 'bi-lightbulb',
                ];
            }
        }

        return $none;
    }
}

if (!function_exists('nudge_render')) {
    /** نمایش بنر ترغیب (در صورت نیاز) همراه دکمه بستن. */
    function nudge_render(int $userId): bool {
        $n = nudge_analyze_user($userId);
        if (empty($n['show'])) {
            return false;
        }
        $kind    = h($n['kind']);
        $title   = h($n['title']);
        $message = h($n['message']);
        $url     = h($n['cta_url']);
        $label   = h($n['cta_label']);
        $icon    = h($n['icon']);
        $csrf    = h(csrf_token());
        ?>
        <div class="alert alert-info nudge-banner mb-6" data-nudge-kind="<?= $kind ?>"
             style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap">
          <i class="bi <?= $icon ?>" style="font-size:1.5rem;color:var(--primary)"></i>
          <div style="flex:1;min-width:200px">
            <strong><?= $title ?></strong>
            <div class="fs-sm" style="color:var(--text-secondary)"><?= $message ?></div>
          </div>
          <a href="<?= $url ?>" class="btn btn-accent btn-sm"><?= $label ?></a>
          <button type="button" class="btn btn-ghost btn-sm nudge-dismiss" aria-label="بستن" data-kind="<?= $kind ?>">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
        <script>
        (function () {
          document.querySelectorAll('.nudge-dismiss').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var kind = btn.getAttribute('data-kind') || '';
              var banner = btn.closest('.nudge-banner');
              if (banner) banner.remove();
              if (!kind) return;
              try {
                var parts = (document.cookie.match(/(?:^|;\s*)swapin_nudge=([^;]*)/) || [])[1];
                var list = parts ? decodeURIComponent(parts).split(',') : [];
                if (list.indexOf(kind) === -1) list.push(kind);
                document.cookie = 'swapin_nudge=' + encodeURIComponent(list.join(',').slice(0, 120)) +
                  ';path=/;max-age=2592000;SameSite=Lax';
              } catch (e) {}
            });
          });
        })();
        </script>
        <?php
        return true;
    }
}
