<?php
// includes/ai_category_keywords.php
// جستجوی هوشمند: تولید کلیدواژه‌های پیشنهادی برای هر دسته‌بندی با هوش مصنوعی + کش
// کش بر اساس «امضای» آگهی‌های همان دسته است؛ با ورود آگهی جدید، امضا تغییر می‌کند و
// در بازدید بعدی همان دسته، کلیدواژه‌ها دوباره از هوش مصنوعی گرفته می‌شوند.

require_once __DIR__ . '/ai.php';

const AICK_MIN_KEYWORDS    = 5;
const AICK_MAX_KEYWORDS    = 10;
const AICK_TITLES_LIMIT    = 60;
const AICK_FALLBACK_TTL    = 900; // ثانیه — تا این مدت کلیدواژه‌های پشتیبان دوباره درخواست نمی‌شوند

function aick_ensure_table(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!db_has_table('category_ai_keywords')) {
        try {
            DB::query("CREATE TABLE IF NOT EXISTS `category_ai_keywords` (
              `category_id` int UNSIGNED NOT NULL,
              `keywords` text COLLATE utf8mb4_unicode_ci NOT NULL,
              `signature` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
              `source` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ai',
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('aick_create_table_failed', ['msg' => $e->getMessage()]);
            }
        }
    }
}

/** شناسه دسته و همه زیردسته‌های آن */
function aick_scope_ids(int $categoryId): array {
    if ($categoryId <= 0) {
        return [];
    }
    $ids = swaapin_category_descendant_ids($categoryId);
    return array_values(array_unique(array_map('intval', $ids)));
}

/**
 * امضای آگهی‌های عمومی همان دسته (تعداد + بیشترین شناسه).
 * با افزودن یا حذف آگهی تغییر می‌کند و باعث تازه‌سازی کش می‌شود.
 */
function aick_signature(array $scopeIds): string {
    if (!$scopeIds) {
        return '';
    }
    $ph = implode(',', array_fill(0, count($scopeIds), '?'));
    try {
        $row = DB::fetch(
            "SELECT COUNT(*) AS c, COALESCE(MAX(l.id), 0) AS m
             FROM listings l
             WHERE l.category_id IN ($ph) AND " . listing_public_sql('l'),
            $scopeIds
        );
    } catch (Throwable $e) {
        return '';
    }
    return md5(((int)($row['c'] ?? 0)) . '|' . ((int)($row['m'] ?? 0)));
}

/** @return array{keywords:array<int,string>,signature:string,source:string,updated_at:string}|null */
function aick_cache_get(int $categoryId): ?array {
    aick_ensure_table();
    try {
        $row = DB::fetch(
            'SELECT category_id, keywords, signature, source, updated_at
             FROM category_ai_keywords WHERE category_id = ? LIMIT 1',
            [$categoryId]
        );
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $row['keywords'] = aick_sanitize_keywords($row['keywords'] ?? '');
    return $row;
}

function aick_cache_put(int $categoryId, array $keywords, string $signature, string $source = 'ai'): void {
    aick_ensure_table();
    $keywords = aick_sanitize_keywords($keywords);
    if (!$keywords) {
        return;
    }
    $data = [
        'keywords'  => json_encode($keywords, JSON_UNESCAPED_UNICODE),
        'signature' => $signature,
        'source'    => $source,
    ];
    try {
        $exists = DB::fetch('SELECT category_id FROM category_ai_keywords WHERE category_id = ? LIMIT 1', [$categoryId]);
        if ($exists) {
            DB::update('category_ai_keywords', $data, 'category_id = ?', [$categoryId]);
        } else {
            DB::insert('category_ai_keywords', array_merge(['category_id' => $categoryId], $data));
        }
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('aick_cache_put_failed', ['msg' => $e->getMessage(), 'category_id' => $categoryId]);
        }
    }
}

function aick_cache_delete(int $categoryId): void {
    aick_ensure_table();
    try {
        DB::query('DELETE FROM category_ai_keywords WHERE category_id = ?', [$categoryId]);
    } catch (Throwable $e) {
    }
}

/**
 * نرمال‌سازی فهرست کلیدواژه‌ها: پشتیبانی از آرایه، رشته JSON و رشته جداشده با کاما،
 * حذف تکراری‌ها و محدودکردن تعداد.
 *
 * @param mixed $raw
 * @return array<int,string>
 */
function aick_sanitize_keywords($raw): array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/[،,\n]+/u', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }

    $flat = [];
    foreach ($raw as $item) {
        if (is_array($item)) {
            foreach ($item as $sub) {
                $flat[] = $sub;
            }
        } else {
            $flat[] = $item;
        }
    }

    $out  = [];
    $seen = [];
    foreach ($flat as $kw) {
        if (!is_scalar($kw)) {
            continue;
        }
        $kw = trim((string)$kw);
        $kw = trim($kw, " \t\n\r\0\x0B\"'[]{}،,.:;");
        $kw = (string)preg_replace('/\s+/u', ' ', $kw);
        if ($kw === '' || mb_strlen($kw) < 2 || mb_strlen($kw) > 40) {
            continue;
        }
        $key = mb_strtolower($kw);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[]      = $kw;
        if (count($out) >= AICK_MAX_KEYWORDS) {
            break;
        }
    }

    return $out;
}

/** عنوان آگهی‌های عمومی همان دسته برای تغذیه به هوش مصنوعی */
function aick_listing_titles(array $scopeIds, int $limit = AICK_TITLES_LIMIT): array {
    if (!$scopeIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($scopeIds), '?'));
    try {
        $rows = DB::fetchAll(
            "SELECT l.title FROM listings l
             WHERE l.category_id IN ($ph) AND " . listing_public_sql('l') . "
             ORDER BY l.created_at DESC LIMIT ?",
            array_merge($scopeIds, [max(1, $limit)])
        );
    } catch (Throwable $e) {
        return [];
    }
    $titles = [];
    foreach ($rows as $r) {
        $t = trim((string)($r['title'] ?? ''));
        if ($t !== '') {
            $titles[] = mb_substr($t, 0, 80);
        }
    }
    return $titles;
}

/** نام زیردسته‌ها — برای کلیدواژه‌های پشتیبان */
function aick_child_category_names(int $categoryId): array {
    if ($categoryId <= 0) {
        return [];
    }
    try {
        $rows = DB::fetchAll(
            'SELECT name FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, id LIMIT 10',
            [$categoryId]
        );
    } catch (Throwable $e) {
        return [];
    }
    $names = [];
    foreach ($rows as $r) {
        $n = trim((string)($r['name'] ?? ''));
        if ($n !== '') {
            $names[] = $n;
        }
    }
    return $names;
}

/**
 * کلیدواژه‌های پشتیبان بدون هوش مصنوعی: نام دسته + زیردسته‌ها + پرتکرارترین واژه‌های عنوان‌ها.
 *
 * @param array<int,string> $titles
 * @param array<int,string> $childNames
 * @return array<int,string>
 */
function aick_fallback_keywords(array $titles, string $categoryName = '', array $childNames = []): array {
    $stop = [
        'و', 'در', 'با', 'به', 'از', 'که', 'این', 'را', 'برای', 'های', 'ها', 'یک', 'است', 'شود', 'می',
        'بر', 'تا', 'هم', 'یا', 'کرد', 'شد', 'بود', 'نیاز', 'فروش', 'خرید', 'سلام', 'دارم', 'دارای',
        'قابل', 'مناسب', 'کاملا', 'کامل', 'خیلی', 'جدید', 'درصد', 'مدل', 'جهت', 'بدون', 'داره', 'هست',
    ];

    $freq = [];
    foreach ($titles as $t) {
        $words = preg_split('/[\s\/\-_,،.()+:]+/u', $t) ?: [];
        foreach ($words as $w) {
            $w = trim((string)$w);
            if ($w === '' || mb_strlen($w) < 3 || preg_match('/^\d+$/u', $w)) {
                continue;
            }
            if (in_array(mb_strtolower($w), $stop, true)) {
                continue;
            }
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }
    }
    arsort($freq);

    $out = [];
    if ($categoryName !== '') {
        $out[] = $categoryName;
    }
    foreach ($childNames as $child) {
        $out[] = $child;
    }
    foreach (array_keys($freq) as $w) {
        $out[] = $w;
        if (count($out) >= AICK_MAX_KEYWORDS * 2) {
            break;
        }
    }

    return aick_sanitize_keywords($out);
}

/** پیام‌های اختصاصی استخراج کلیدواژه (مستقل از پرامپت اصلی سیستم) */
function aick_build_messages(string $categoryName, array $titles): array {
    $system = "You are a search-keyword assistant for an Iranian barter marketplace (سواپین).\n"
        . "Given a product category and real listing titles, produce between 5 and 10 short Persian search keywords "
        . "that best represent what users trade in this category.\n"
        . "Rules: Persian words only, 1 to 3 words per keyword, no duplicates, no prices, no punctuation, no numbering.\n"
        . 'Respond with JSON only: {"type":"category_keywords","keywords":["...","..."]}';

    $user = json_encode([
        'type'           => 'category_keywords',
        'category'       => $categoryName,
        'listing_titles' => array_slice($titles, 0, AICK_TITLES_LIMIT),
    ], JSON_UNESCAPED_UNICODE);

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ];
}

/** تلاش سروری برای تولید کلیدواژه (در صورت مسدود بودن IP، null برمی‌گرداند) */
function aick_generate_server(string $categoryName, array $titles): ?array {
    if (!ai_is_configured() || !$titles) {
        return null;
    }
    $result = ai_chat_completion(aick_build_messages($categoryName, $titles), 0.4);
    if (empty($result['parsed']) || !is_array($result['parsed'])) {
        return null;
    }
    $parsed = $result['parsed'];
    $kws = aick_sanitize_keywords($parsed['keywords'] ?? $parsed);
    if (count($kws) < 3) {
        return null;
    }
    return $kws;
}

/**
 * وضعیت کش کلیدواژه‌های یک دسته.
 *
 * @return array{category_id:int,keywords:array<int,string>,source:string,signature:string,fresh:bool,has_cache:bool}
 */
function aick_state(int $categoryId): array {
    aick_ensure_table();
    $signature = aick_signature(aick_scope_ids($categoryId));
    $cache     = aick_cache_get($categoryId);
    $fresh     = false;

    if ($cache) {
        $sameSignature = (string)($cache['signature'] ?? '') === $signature;
        if ($sameSignature && (string)($cache['source'] ?? '') === 'ai') {
            $fresh = true;
        } elseif ($sameSignature && (string)($cache['source'] ?? '') !== 'ai') {
            // کلیدواژه پشتیبان است؛ تا انقضای TTL معتبر است
            $age   = time() - strtotime((string)($cache['updated_at'] ?? ''));
            $fresh = $age < AICK_FALLBACK_TTL;
        }
    }

    return [
        'category_id' => $categoryId,
        'keywords'    => $cache['keywords'] ?? [],
        'source'      => (string)($cache['source'] ?? ''),
        'signature'   => $signature,
        'fresh'       => $fresh,
        'has_cache'   => (bool)$cache,
    ];
}

/**
 * رندر بخش «جستجوی هوشمند» در سایدبار صفحه دسته‌بندی.
 * در هر صفحه‌ای که سایدبار دسته‌بندی دارد (همه‌ی آگهی‌ها، نقشه و …) قابل استفاده است.
 *
 * @param array<string,mixed> $category   ردیف دسته (حداقل id و slug)
 * @param string              $baseUrl    آدرس صفحه فعلی برای ساخت لینک جستجو
 * @param array<string,mixed> $baseParams پارامترهای فیلتر فعلی که باید در لینک حفظ شوند
 */
function aick_render_sidebar(array $category, string $baseUrl, array $baseParams = []): void {
    $categoryId = (int)($category['id'] ?? 0);
    $catSlug    = trim((string)($category['slug'] ?? ''));
    if ($categoryId <= 0 || $catSlug === '') {
        return;
    }

    $state    = aick_state($categoryId);
    $keywords = $state['keywords'];
    $stale    = $state['fresh'] ? '0' : '1';
    $endpoint = APP_URL . '/api/category_keywords.php';

    $keep = static function ($v): bool {
        return $v !== null && $v !== '' && $v !== '0';
    };
    $baseQuery = http_build_query(array_filter(array_merge($baseParams, ['cat' => $catSlug]), $keep));

    $buildSearchLink = static function (string $kw) use ($baseUrl, $baseQuery): string {
        $qs = $baseQuery !== '' ? $baseQuery . '&' : '';
        return $baseUrl . '?' . $qs . 'q=' . urlencode($kw);
    };
    ?>
    <div class="related-searches ai-cat-search" id="ai-cat-search"
         data-cat-slug="<?= h($catSlug) ?>"
         data-stale="<?= $stale ?>"
         data-endpoint="<?= h($endpoint) ?>"
         data-base-url="<?= h($baseUrl) ?>"
         data-base-query="<?= h($baseQuery) ?>">
      <h3 style="font-size:.9rem;font-weight:bold;color:#555;margin:0 0 6px;display:flex;align-items:center;gap:8px">
        <i class="bi bi-stars"></i> جستجوی هوشمند
      </h3>
      <p class="fs-xs text-muted" style="margin:0 0 8px">پیشنهاد هوش مصنوعی بر اساس آگهی‌های همین دسته</p>
      <div class="related-searches__chips" id="ai-cat-search-chips">
        <?php if ($keywords): ?>
          <?php foreach ($keywords as $kw): ?>
          <a href="<?= h($buildSearchLink($kw)) ?>" class="filter-chip"><?= h($kw) ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="fs-xs text-muted"><i class="bi bi-arrow-repeat"></i> در حال آماده‌سازی کلیدواژه‌ها…</span>
        <?php endif; ?>
      </div>
    </div>
    <script>
    (function () {
      function ready(fn) {
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', fn);
        } else {
          fn();
        }
      }

      ready(function () {
        var box = document.getElementById('ai-cat-search');
        if (!box || box.getAttribute('data-stale') !== '1') { return; }

        var slug      = box.getAttribute('data-cat-slug') || '';
        var endpoint  = box.getAttribute('data-endpoint') || '';
        var baseUrl   = box.getAttribute('data-base-url') || '';
        var baseQuery = box.getAttribute('data-base-query') || '';
        var chips     = document.getElementById('ai-cat-search-chips');
        if (!slug || !endpoint || !chips) { return; }

        function keywordLink(kw) {
          var qs = baseQuery ? (baseQuery + '&q=' + encodeURIComponent(kw)) : ('q=' + encodeURIComponent(kw));
          return baseUrl + '?' + qs;
        }

        function render(list) {
          if (!Array.isArray(list) || !list.length) { return; }
          chips.innerHTML = '';
          list.forEach(function (kw) {
            kw = String(kw || '').trim();
            if (!kw) { return; }
            var a = document.createElement('a');
            a.className = 'filter-chip';
            a.textContent = kw;
            a.setAttribute('href', keywordLink(kw));
            chips.appendChild(a);
          });
        }

        function post(data) {
          var fd = new FormData();
          Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
          if (typeof appendCsrf === 'function') { appendCsrf(fd); }
          var headers = (typeof withCsrfHeaders === 'function') ? withCsrfHeaders() : {};
          return fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: headers })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; });
        }

        function extractKeywords(text) {
          if (typeof swaapinExtractJsonFromText !== 'function') { return null; }
          var parsed = swaapinExtractJsonFromText(text);
          return (parsed && Array.isArray(parsed.keywords)) ? parsed.keywords : null;
        }

        post({ action: 'get', cat: slug }).then(function (res) {
          if (!res || res.ok !== true) { return; }
          if (res.type === 'result') { render(res.keywords); return; }

          if (res.type === 'client_prepare') {
            var fallback = res.fallback_keywords;
            if (typeof swaapinBrowserCompleteChat !== 'function') { render(fallback); return; }
            swaapinBrowserCompleteChat(res).then(function (done) {
              var kws = (done && done.content) ? extractKeywords(done.content) : null;
              if (kws && kws.length) {
                render(kws);
                post({ action: 'save', cat: slug, keywords: JSON.stringify(kws) });
              } else {
                render(fallback);
              }
            }).catch(function () { render(fallback); });
          }
        });
      });
    })();
    </script>
    <?php
}
