<?php
/**
 * کاتالوگ برند/مدل/تیپ + لندینگ‌های معاوضه — طبق سند معماری سئو (بخش ۱، ۲، ۴ و ۶).
 *
 * مسیر کانونیکال برند/مدل/تیپ (بدون پیشوند /s/ طبق تصمیم پروژه):
 *   /{location}/{category}/{brand}[/{model}[/{trim}]]
 *
 * مسیر کانونیکال لندینگ معاوضه (بدون پیشوند /s/):
 *   /list/swap
 *   /list/swap-{category}
 *   /list/swap-{category}-{model}
 *   /list/swap-{X}-with-{Y}
 *   و نسخهٔ مکانی: /{location}/list/swap...
 */

function swaapin_catalog_levels(): array {
    return ['brand', 'model', 'trim'];
}

function swaapin_catalog_table_exists(): bool {
    return db_has_table('catalog_nodes');
}

function swaapin_ensure_catalog_table(): void {
    if (swaapin_catalog_table_exists()) {
        return;
    }
    try {
        DB::query(
            "CREATE TABLE IF NOT EXISTS `catalog_nodes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `category_id` INT UNSIGNED NOT NULL,
                `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `level` ENUM('brand','model','trim') NOT NULL,
                `name` VARCHAR(160) NOT NULL,
                `slug` VARCHAR(160) NOT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_catalog` (`category_id`, `parent_id`, `slug`),
                KEY `idx_catalog_level` (`category_id`, `level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        swapin_debug_log('catalog_create_fail', ['msg' => $e->getMessage()]);
    }
}

/** اسلاگ لاتین کوچک برند/مدل/تیپ (طبق بخش ۶ سند). */
function swaapin_catalog_slug(string $name): string {
    if (function_exists('swaapin_transliterate')) {
        return swaapin_transliterate($name);
    }
    return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? ''), '-');
}

/** درج یا بازگرداندن شناسهٔ یک گرهٔ کاتالوگ. */
function swaapin_catalog_upsert(int $categoryId, int $parentId, string $level, string $name): int {
    $name = trim($name);
    $slug = swaapin_catalog_slug($name);
    if ($slug === '' || !in_array($level, swaapin_catalog_levels(), true)) {
        return 0;
    }
    try {
        $row = DB::fetch(
            'SELECT id FROM catalog_nodes WHERE category_id = ? AND parent_id = ? AND slug = ? LIMIT 1',
            [$categoryId, $parentId, $slug]
        );
        if ($row) {
            return (int) $row['id'];
        }
        return (int) DB::insert('catalog_nodes', [
            'category_id' => $categoryId,
            'parent_id'   => $parentId,
            'level'       => $level,
            'name'        => $name,
            'slug'        => $slug,
        ]);
    } catch (Throwable $e) {
        swapin_debug_log('catalog_upsert_fail', ['msg' => $e->getMessage()]);
        return 0;
    }
}

/**
 * همگام‌سازی گره‌های کاتالوگ یک دسته از روی آگهی‌های موجود (listing_attrs).
 * فقط یک‌بار در هر درخواست و فقط اگر آن دسته هیچ گره‌ای ندارد اجرا می‌شود.
 * برند/مدل آگهی‌ها را در ستون‌های brand_id/model_id بازنویسی می‌کند تا فیلتر ساختاریافته کار کند.
 */
function swaapin_catalog_sync_category(int $categoryId): void {
    static $done = [];
    if ($categoryId <= 0 || isset($done[$categoryId])) {
        return;
    }
    $done[$categoryId] = true;
    swaapin_ensure_catalog_table();
    if (!swaapin_catalog_table_exists() || !db_has_column('listings', 'listing_attrs')) {
        return;
    }
    try {
        $has = DB::fetch('SELECT id FROM catalog_nodes WHERE category_id = ? LIMIT 1', [$categoryId]);
        if ($has) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    $pub = listing_public_sql('l');
    try {
        $rows = DB::fetchAll(
            "SELECT l.id, l.listing_attrs FROM listings l
             WHERE l.category_id = ? AND {$pub} AND l.listing_attrs IS NOT NULL
             LIMIT 500",
            [$categoryId]
        );
    } catch (Throwable $e) {
        return;
    }
    $hasBrandCol = db_has_column('listings', 'brand_id');
    $hasModelCol = db_has_column('listings', 'model_id');
    foreach ($rows as $r) {
        $decoded = json_decode((string) ($r['listing_attrs'] ?? ''), true);
        if (!is_array($decoded)) {
            continue;
        }
        $brandName = trim((string) ($decoded['brand'] ?? ''));
        $modelName = trim((string) ($decoded['model'] ?? ''));
        if ($brandName === '') {
            continue;
        }
        $brandId = swaapin_catalog_upsert($categoryId, 0, 'brand', $brandName);
        if ($brandId <= 0) {
            continue;
        }
        $modelId = $modelName !== '' ? swaapin_catalog_upsert($categoryId, $brandId, 'model', $modelName) : 0;
        if ($hasBrandCol || $hasModelCol) {
            try {
                DB::query(
                    'UPDATE listings SET brand_id = ?, model_id = ? WHERE id = ?',
                    [$brandId, $modelId > 0 ? $modelId : null, (int) $r['id']]
                );
            } catch (Throwable $e) {
            }
        }
    }
}

/** یک گرهٔ کاتالوگ بر اساس اسلاگ (با کلید والد). */
function swaapin_catalog_node(int $categoryId, int $parentId, string $slug): ?array {
    if ($categoryId <= 0 || $slug === '') {
        return null;
    }
    swaapin_ensure_catalog_table();
    if (!swaapin_catalog_table_exists()) {
        return null;
    }
    swaapin_catalog_sync_category($categoryId);
    try {
        $row = DB::fetch(
            'SELECT * FROM catalog_nodes WHERE category_id = ? AND parent_id = ? AND slug = ? AND is_active = 1 LIMIT 1',
            [$categoryId, $parentId, $slug]
        );
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** زنجیرهٔ گره‌ها از ریشه (برند) تا گرهٔ داده‌شده. */
function swaapin_catalog_ancestors(int $id): array {
    $chain = [];
    $cur   = $id;
    $safety = 10;
    while ($cur > 0 && $safety-- > 0) {
        $row = DB::fetch('SELECT * FROM catalog_nodes WHERE id = ? LIMIT 1', [$cur]);
        if (!$row) {
            break;
        }
        array_unshift($chain, $row);
        $cur = (int) $row['parent_id'];
    }
    return $chain;
}

/** آدرس کانونیکال یک صفحهٔ برند/مدل/تیپ. */
function swaapin_catalog_url(?array $locationRow, array $category, array $chain): string {
    $locSlug = $locationRow ? (string) $locationRow['slug'] : 'iran';
    $parts   = [$locSlug, (string) $category['slug']];
    foreach ($chain as $node) {
        $parts[] = (string) $node['slug'];
    }
    return APP_URL . '/' . implode('/', array_map('rawurlencode', $parts));
}

/** تعداد آگهی‌های فعال یک گرهٔ کاتالوگ (برای قاعدهٔ حداقل ۶ آگهی). */
function swaapin_catalog_active_listing_count(array $chain, int $categoryId): int {
    if (empty($chain) || $categoryId <= 0) {
        return 0;
    }
    $node  = end($chain);
    $level = (string) $node['level'];
    $col   = $level === 'brand' ? 'brand_id' : ($level === 'model' ? 'model_id' : 'trim_id');
    $parts = [];
    $params = [];
    if (db_has_column('listings', $col)) {
        $parts[]  = "l.`{$col}` = ?";
        $params[] = (int) $node['id'];
    }
    if (db_has_column('listings', 'listing_attrs')) {
        $name     = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $node['name']);
        $parts[]  = "l.listing_attrs LIKE ?";
        $params[] = '%"' . $level . '":"' . $name . '"%';
    }
    if (empty($parts)) {
        return 0;
    }
    $pub = listing_public_sql('l');
    try {
        $row = DB::fetch(
            "SELECT COUNT(*) AS c FROM listings l
             WHERE l.category_id = ? AND {$pub} AND (" . implode(' OR ', $parts) . ')',
            [$categoryId, ...$params]
        );
        return (int) ($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// لندینگ‌های معاوضه (بخش ۴ سند)
// ─────────────────────────────────────────────────────────────────────────────

/** یافتن بلندترین اسلاگ دستهٔ موجود که توکن با آن شروع می‌شود؛ باقی‌مانده = مدل. */
function swaapin_catalog_match_category_prefix(string $token): array {
    static $slugs = null;
    if ($slugs === null) {
        $slugs = [];
        try {
            foreach (DB::fetchAll('SELECT slug FROM categories WHERE is_active = 1') as $r) {
                $s = strtolower((string) $r['slug']);
                if ($s !== '') {
                    $slugs[] = $s;
                }
            }
        } catch (Throwable $e) {
        }
    }
    $best = '';
    foreach ($slugs as $s) {
        if ($s === $token || str_starts_with($token, $s . '-')) {
            if (strlen($s) > strlen($best)) {
                $best = $s;
            }
        }
    }
    if ($best === '') {
        return [null, $token];
    }
    $remainder = $best === $token ? '' : substr($token, strlen($best) + 1);
    $row = DB::fetch('SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1', [$best]);
    return [$row ?: null, $remainder];
}

/** نام فارسی یک مدل کاتالوگ بر اساس اسلاگ (برای فیلتر متنی). */
function swaapin_swap_model_name(string $slug, int $categoryId = 0): string {
    if ($slug === '') {
        return '';
    }
    swaapin_ensure_catalog_table();
    if (!swaapin_catalog_table_exists()) {
        return '';
    }
    swaapin_catalog_sync_category($categoryId);
    try {
        if ($categoryId > 0) {
            $row = DB::fetch(
                "SELECT name FROM catalog_nodes WHERE level = 'model' AND slug = ? AND category_id = ? LIMIT 1",
                [$slug, $categoryId]
            );
        } else {
            $row = DB::fetch(
                "SELECT name FROM catalog_nodes WHERE level = 'model' AND slug = ? LIMIT 1",
                [$slug]
            );
        }
        return $row ? (string) $row['name'] : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * تجزیهٔ توکن لندینگ معاوضه (بدون پیشوند «swap»).
 * @return array{token:string,category:?array,model:string,target_category:?string,target_location:?string}|null
 */
function swaapin_swap_landing_resolve(string $token): ?array {
    $token = strtolower(trim($token, "/ \t\n\r\0\x0B"));
    if ($token === '' || !str_starts_with($token, 'swap')) {
        return null;
    }
    $rest = ltrim(substr($token, 4), '-');

    $targetSlug = '';
    if (str_contains($rest, '-with-')) {
        [$rest, $targetSlug] = explode('-with-', $rest, 2);
    }

    $result = [
        'token'           => $token,
        'category'        => null,
        'model'           => '',
        'target_category' => null,
        'target_location' => null,
    ];

    if ($rest !== '') {
        [$catRow, $modelSlug] = swaapin_catalog_match_category_prefix($rest);
        if ($catRow) {
            $result['category'] = $catRow;
            $result['model']    = $modelSlug;
        }
    }

    if ($targetSlug !== '') {
        $loc = function_exists('swaapin_location_by_slug') ? swaapin_location_by_slug($targetSlug) : null;
        if ($loc) {
            $result['target_location'] = (string) $loc['slug'];
        } else {
            [$tcat, ] = swaapin_catalog_match_category_prefix($targetSlug);
            if ($tcat) {
                $result['target_category'] = (string) $tcat['slug'];
            }
        }
    }

    return $result;
}
