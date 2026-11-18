<?php
/**
 * لندینگ‌های معاوضه (سند معماری سئو — بخش ۵-۲ و ۴).
 *
 * منبع حقیقت: جدول `swap_landings`. لندینگ فقط از ردیف‌های تأییدشده‌ی پنل ساخته
 * می‌شود (نه از ترکیب خودکار فیلترها). اسلاگ هر لندینگ فقط از اسلاگ دسته‌ها و
 * مکان‌ها ساخته می‌شود.
 *
 * چهار نوع لندینگ:
 *   location  →  /{loc}/list/swap                 (همه‌ی معاوضه‌های یک مکان)
 *   category  →  /{loc}/list/swap-{cat}           (معاوضه‌های یک دسته در مکان)
 *   model     →  /{loc}/list/swap-{cat}-{model}   (معاوضه‌های یک مدل در مکان)
 *   xy        →  /{loc}/list/swap-{X}-with-{Y}    (معاوضه‌ی X با Y در مکان)
 * مکان سطح کشور اسلاگ `iran` است.
 */

require_once __DIR__ . '/seo.php';

function swaapin_swap_landings_table_exists(): bool {
    return function_exists('db_has_table') && db_has_table('swap_landings');
}

/** ایجاد جدول لندینگ‌های معاوضه در صورت نبود (الگوی lazy مثل listing_index_status). */
function swaapin_ensure_swap_landings_table(): void {
    if (!swaapin_swap_landings_table_exists()) {
        try {
            DB::query(
                "CREATE TABLE IF NOT EXISTS `swap_landings` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `type` VARCHAR(16) NOT NULL DEFAULT 'category',
                    `location_slug` VARCHAR(120) NOT NULL DEFAULT '',
                    `token` VARCHAR(191) NOT NULL,
                    `category_slug` VARCHAR(120) NOT NULL DEFAULT '',
                    `model_slug` VARCHAR(120) NOT NULL DEFAULT '',
                    `target_kind` VARCHAR(16) NOT NULL DEFAULT '',
                    `target_slug` VARCHAR(120) NOT NULL DEFAULT '',
                    `title` VARCHAR(255) NOT NULL DEFAULT '',
                    `h1` VARCHAR(255) NOT NULL DEFAULT '',
                    `intro` TEXT NULL,
                    `index_status` VARCHAR(16) NOT NULL DEFAULT 'auto',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `first_indexed_at` DATETIME NULL DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_swap_landing` (`location_slug`, `token`),
                    KEY `idx_swap_landing_cat` (`location_slug`, `category_slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('swap_landings_create_fail', ['msg' => $e->getMessage()]);
            }
        }
    }
    // ستون «تاریخ اولین ایندکس» برای جدول‌های ساخته‌شده‌ی نسخه‌های قبلی
    if (swaapin_swap_landings_table_exists() && !db_has_column('swap_landings', 'first_indexed_at')) {
        try {
            DB::query('ALTER TABLE swap_landings ADD COLUMN `first_indexed_at` DATETIME NULL DEFAULT NULL');
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('swap_landings_alter_fail', ['msg' => $e->getMessage()]);
            }
        }
    }
}

// ── قالب تایتل و H1 (شیت «قالب تایتل و H1») ────────────────────────────────

function swaapin_ensure_swap_settings_table(): void {
    if (function_exists('db_has_table') && db_has_table('swap_landing_settings')) {
        return;
    }
    try {
        DB::query(
            "CREATE TABLE IF NOT EXISTS `swap_landing_settings` (
                `k` VARCHAR(64) NOT NULL,
                `v` TEXT NULL,
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('swap_settings_create_fail', ['msg' => $e->getMessage()]);
        }
    }
}

/** قالب‌های پیش‌فرض تایتل/H1 طبق سند (جایگزین‌شوها: {location} {category} {brand} {model} {x} {y}). */
function swaapin_swap_template_defaults(): array {
    return [
        'title_location' => 'معاوضه و تهاتر کالا در {location}',
        'title_category' => 'معاوضه و تهاتر {category} در {location}',
        'title_model'    => 'معاوضه {brand} {model} در {location}',
        'title_xy'       => 'معاوضه {x} با {y} در {location}',
        'h1_location'    => 'معاوضه در {location}',
        'h1_category'    => 'معاوضه {category} در {location}',
        'h1_model'       => 'معاوضه {brand} {model} در {location}',
        'h1_xy'          => 'معاوضه {x} با {y} در {location}',
    ];
}

/** قالب‌های ذخیره‌شده در پنل (با پیش‌فرض سند برای کلیدهای خالی). */
function swaapin_swap_settings(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = swaapin_swap_template_defaults();
    if (function_exists('db_has_table') && db_has_table('swap_landing_settings')) {
        try {
            foreach (DB::fetchAll('SELECT k, v FROM swap_landing_settings') as $r) {
                $out[(string) $r['k']] = (string) $r['v'];
            }
        } catch (Throwable $e) {
            // از پیش‌فرض‌ها استفاده می‌شود
        }
    }
    return $cache = $out;
}

function swaapin_swap_setting(string $key, string $default = ''): string {
    $all = swaapin_swap_settings();
    if (isset($all[$key]) && trim((string) $all[$key]) !== '') {
        return (string) $all[$key];
    }
    return $default;
}

/** ذخیره‌ی گروهی قالب‌های تایتل/H1. */
function swaapin_swap_settings_save(array $map): void {
    swaapin_ensure_swap_settings_table();
    if (!function_exists('db_has_table') || !db_has_table('swap_landing_settings')) {
        return;
    }
    foreach ($map as $k => $v) {
        try {
            DB::query(
                'INSERT INTO swap_landing_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
                [(string) $k, (string) $v]
            );
        } catch (Throwable $e) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('swap_settings_save_fail', ['k' => (string) $k, 'msg' => $e->getMessage()]);
            }
        }
    }
}

// ── کمکی‌های پایه ────────────────────────────────────────────────────────

function swaapin_swap_landing_types(): array {
    return ['location', 'category', 'model', 'xy'];
}

/** ساخت توکن لندینگ فقط از اسلاگ دسته/مدل/مقصد (بدون ترکیب پارامترهای فیلتر). */
function swaapin_swap_landing_build_token(string $type, string $categorySlug, string $modelSlug, string $targetSlug): string {
    $token        = 'swap';
    $categorySlug = strtolower(trim($categorySlug));
    $modelSlug    = strtolower(trim($modelSlug));
    $targetSlug   = strtolower(trim($targetSlug));

    if ($categorySlug !== '') {
        $token .= '-' . $categorySlug;
    }
    if ($modelSlug !== '') {
        $token .= '-' . $modelSlug;
    }
    if ($type === 'xy' && $targetSlug !== '') {
        $token .= '-with-' . $targetSlug;
    }

    return $token;
}

/** یافتن لندینگ فعال با (اسلاگ مکان، توکن). مکان سطح کشور = `iran`. */
function swaapin_swap_landing_find(string $locationSlug, string $token): ?array {
    $locationSlug = strtolower(trim($locationSlug));
    $token        = strtolower(trim($token));
    if ($token === '' || !swaapin_swap_landings_table_exists()) {
        return null;
    }
    try {
        $row = DB::fetch(
            'SELECT * FROM swap_landings WHERE location_slug = ? AND token = ? AND is_active = 1 LIMIT 1',
            [$locationSlug, $token]
        );
    } catch (Throwable $e) {
        return null;
    }
    return $row ?: null;
}

/** یافتن لندینگ معاوضه‌ی یک دسته در یک مکان (برای دکمه‌ی «فقط معاوضه»). */
function swaapin_swap_landing_for_category(string $locationSlug, string $categorySlug): ?array {
    $locationSlug = strtolower(trim($locationSlug));
    $categorySlug = strtolower(trim($categorySlug));
    if ($categorySlug === '' || !swaapin_swap_landings_table_exists()) {
        return null;
    }
    try {
        $row = DB::fetch(
            "SELECT * FROM swap_landings
             WHERE location_slug = ? AND category_slug = ? AND type = 'category' AND is_active = 1
             ORDER BY id ASC LIMIT 1",
            [$locationSlug, $categorySlug]
        );
    } catch (Throwable $e) {
        return null;
    }
    return $row ?: null;
}

/** آدرس کانونیکال لندینگ — همیشه /{location}/list/{token}. */
function swaapin_swap_landing_url(array $row): string {
    $loc = strtolower(trim((string) ($row['location_slug'] ?? '')));
    if ($loc === '') {
        $loc = 'iran';
    }
    return rtrim(APP_URL, '/') . '/' . $loc . '/list/' . rawurlencode((string) ($row['token'] ?? 'swap'));
}

/** نام فارسی مقصد «with-{Y}» (دسته یا مکان). */
function swaapin_swap_landing_target_name(array $row): string {
    $slug = trim((string) ($row['target_slug'] ?? ''));
    if ($slug === '') {
        return '';
    }
    try {
        if ((string) ($row['target_kind'] ?? '') === 'location' && function_exists('swaapin_location_by_slug')) {
            $loc = swaapin_location_by_slug($slug);
            return $loc ? (string) $loc['name'] : '';
        }
        $cat = DB::fetch('SELECT name FROM categories WHERE slug = ? LIMIT 1', [$slug]);
        return $cat ? (string) $cat['name'] : '';
    } catch (Throwable $e) {
        return '';
    }
}

/** اجزای «برند» و «مدل» از کاتالوگ (برای قالب تایتل مدل). */
function swaapin_swap_catalog_parts(string $modelSlug, int $categoryId = 0): array {
    $out = ['brand' => '', 'model' => ''];
    if ($modelSlug === '' || !function_exists('swaapin_catalog_table_exists') || !swaapin_catalog_table_exists()) {
        return $out;
    }
    try {
        if ($categoryId > 0) {
            $row = DB::fetch(
                "SELECT id, name, parent_id FROM catalog_nodes WHERE level = 'model' AND slug = ? AND category_id = ? LIMIT 1",
                [$modelSlug, $categoryId]
            );
        } else {
            $row = DB::fetch(
                "SELECT id, name, parent_id FROM catalog_nodes WHERE level = 'model' AND slug = ? LIMIT 1",
                [$modelSlug]
            );
        }
        if (!$row) {
            return $out;
        }
        $out['model'] = trim((string) $row['name']);
        $pid = (int) ($row['parent_id'] ?? 0);
        if ($pid > 0) {
            $brand = DB::fetch('SELECT name FROM catalog_nodes WHERE id = ? LIMIT 1', [$pid]);
            $out['brand'] = $brand ? trim((string) $brand['name']) : '';
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

/** برچسب «برند مدل» از کاتالوگ (سازگاری با فراخوانی‌های قبلی). */
function swaapin_swap_model_label(string $modelSlug, int $categoryId = 0): string {
    $parts = swaapin_swap_catalog_parts($modelSlug, $categoryId);
    return trim($parts['brand'] . ' ' . $parts['model']);
}

/** زمینه‌ی متنی لندینگ برای پر کردن قالب‌ها. */
function swaapin_swap_landing_context(array $row): array {
    $locSlug = strtolower(trim((string) ($row['location_slug'] ?? '')));
    $locName = '';
    if ($locSlug !== '' && $locSlug !== 'iran' && function_exists('swaapin_location_by_slug')) {
        $loc     = swaapin_location_by_slug($locSlug);
        $locName = $loc ? (string) $loc['name'] : '';
    }

    $catSlug = strtolower(trim((string) ($row['category_slug'] ?? '')));
    $catName = '';
    $catId   = 0;
    if ($catSlug !== '') {
        try {
            $cat = DB::fetch('SELECT id, name FROM categories WHERE slug = ? LIMIT 1', [$catSlug]);
            if ($cat) {
                $catId   = (int) $cat['id'];
                $catName = (string) $cat['name'];
            }
        } catch (Throwable $e) {
            $catName = '';
        }
    }

    $parts = swaapin_swap_catalog_parts(trim((string) ($row['model_slug'] ?? '')), $catId);
    $targetName = swaapin_swap_landing_target_name($row);

    $x = $parts['model'] !== '' ? trim($parts['brand'] . ' ' . $parts['model']) : $catName;
    if ($x === '') {
        $x = 'کالا';
    }

    return [
        'location'      => $locName,
        'category'      => $catName,
        'brand'         => $parts['brand'],
        'model'         => $parts['model'],
        'x'             => $x,
        'y'             => $targetName,
        'location_slug' => $locSlug,
    ];
}

/** پر کردن قالب با مقادیر؛ حذف بخش «در {location}» در سطح کشور و «با {y}» بی‌مقصد. */
function swaapin_swap_landing_render_template(string $tpl, array $vals): string {
    $tpl = trim($tpl);
    if ($tpl === '') {
        return '';
    }
    if (trim((string) ($vals['location'] ?? '')) === '') {
        $tpl = (string) preg_replace('/\s*در\s*\{location\}/u', '', $tpl);
    }
    if (trim((string) ($vals['y'] ?? '')) === '') {
        $tpl = (string) preg_replace('/\s*با\s*\{y\}/u', '', $tpl);
    }
    $out = strtr($tpl, [
        '{location}' => trim((string) ($vals['location'] ?? '')),
        '{category}' => trim((string) ($vals['category'] ?? '')),
        '{brand}'    => trim((string) ($vals['brand'] ?? '')),
        '{model}'    => trim((string) ($vals['model'] ?? '')),
        '{x}'        => trim((string) ($vals['x'] ?? '')),
        '{y}'        => trim((string) ($vals['y'] ?? '')),
    ]);
    $out = (string) preg_replace('/\s{2,}/u', ' ', $out);
    return trim($out);
}

/**
 * عبارت مشترک تایتل/H1 لندینگ طبق قالب پنل (شیت «قالب تایتل و H1»).
 * kind: 'title' یا 'h1'.
 */
function swaapin_swap_landing_phrase(array $row, array $ctx = [], string $kind = 'title'): string {
    $ctx = $ctx ?: swaapin_swap_landing_context($row);
    $type = (string) ($row['type'] ?? 'category');
    if (!in_array($type, swaapin_swap_landing_types(), true)) {
        $type = 'category';
    }
    $key = ($kind === 'h1' ? 'h1_' : 'title_') . $type;
    $tpl = swaapin_swap_setting($key, (string) (swaapin_swap_template_defaults()[$key] ?? ''));
    $phrase = swaapin_swap_landing_render_template($tpl, $ctx);
    if ($phrase === '') {
        $phrase = 'معاوضه و تهاتر کالا';
    }
    return $phrase;
}

/** تایتل نهایی صفحه (`<title>`) با اولویت بازنویسی پنل (بدون بریدن و بدون پسوند برند). */
function swaapin_swap_landing_title(array $row, array $ctx = []): string {
    $override = trim((string) ($row['title'] ?? ''));
    if ($override !== '') {
        return $override;
    }
    return swaapin_swap_landing_phrase($row, $ctx, 'title');
}

/** H1 نهایی لندینگ با اولویت بازنویسی پنل. */
function swaapin_swap_landing_h1(array $row, array $ctx = []): string {
    $override = trim((string) ($row['h1'] ?? ''));
    return $override !== '' ? $override : swaapin_swap_landing_phrase($row, $ctx, 'h1');
}

/** مقدمه‌ی لندینگ (متن پنل یا پیش‌فرض). */
function swaapin_swap_landing_intro(array $row, array $ctx = []): string {
    $override = trim((string) ($row['intro'] ?? ''));
    if ($override !== '') {
        return $override;
    }
    $ctx = $ctx ?: swaapin_swap_landing_context($row);
    return swaapin_swap_landing_phrase($row, $ctx, 'title')
        . '؛ در سواَپین کالای خود را با کالای موردنظرتان تهاتر کنید.';
}

/** شمارش آگهی‌های معاوضه‌ای متناظر با فیلتر لندینگ (برای آستانه‌ی ایندکس و سایتمپ). */
function swaapin_swap_landing_count(array $row): int {
    $where  = [listing_public_sql('l'), 'l.listing_mode != "sell"'];
    $params = [];

    $locSlug = strtolower(trim((string) ($row['location_slug'] ?? '')));
    if ($locSlug !== '' && $locSlug !== 'iran' && function_exists('swaapin_location_by_slug')) {
        $loc = swaapin_location_by_slug($locSlug);
        if ($loc && function_exists('swaapin_location_filter')) {
            $filter = swaapin_location_filter($loc);
            $cities = (array) ($filter['cities'] ?? []);
            if ($cities !== []) {
                $where[] = 'l.city IN (' . implode(',', array_fill(0, count($cities), '?')) . ')';
                foreach ($cities as $c) {
                    $params[] = $c;
                }
            }
            if (!empty($filter['neighborhood'])) {
                $where[]  = 'l.neighborhood = ?';
                $params[] = $filter['neighborhood'];
            }
        }
    }

    $catId   = 0;
    $catSlug = strtolower(trim((string) ($row['category_slug'] ?? '')));
    if ($catSlug !== '') {
        $cat = DB::fetch('SELECT id FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1', [$catSlug]);
        if ($cat) {
            $catId = (int) $cat['id'];
            $ids   = function_exists('swaapin_category_descendant_ids')
                ? swaapin_category_descendant_ids($catId)
                : [$catId];
            if ($ids !== []) {
                $where[] = 'l.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                foreach ($ids as $i) {
                    $params[] = (int) $i;
                }
            }
        }
    }

    $modelSlug = trim((string) ($row['model_slug'] ?? ''));
    if ($modelSlug !== '') {
        $modelName = function_exists('swaapin_swap_model_name')
            ? swaapin_swap_model_name($modelSlug, $catId)
            : '';
        if ($modelName === '') {
            $modelName = swaapin_swap_model_label($modelSlug, $catId);
        }
        if ($modelName === '') {
            $modelName = $modelSlug;
        }
        $where[]  = '(l.title LIKE ? OR l.listing_attrs LIKE ?)';
        $params[] = "%{$modelName}%";
        $params[] = "%{$modelName}%";
    }

    $targetName = swaapin_swap_landing_target_name($row);
    if ($targetName !== '') {
        $where[]  = 'l.want_in_return LIKE ?';
        $params[] = "%{$targetName}%";
    }

    try {
        $r = DB::fetch('SELECT COUNT(*) AS c FROM listings l WHERE ' . implode(' AND ', $where), $params);
        return (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** تاریخ اولین ایندکس لندینگ (از ستون جدول یا سابقه‌ی listing_index_status). */
function swaapin_swap_landing_first_indexed_at(array $row): ?string {
    $v = trim((string) ($row['first_indexed_at'] ?? ''));
    if ($v !== '' && !str_starts_with($v, '0000')) {
        return $v;
    }
    if (function_exists('seo_page_indexed_at')) {
        return seo_page_indexed_at(swaapin_swap_landing_url($row));
    }
    return null;
}

/** آیا لندینگ اجازه‌ی ایندکس دارد؟ (index اجباری، noindex اجباری، یا auto بر اساس آستانه/سابقه). */
function swaapin_swap_landing_indexable(array $row): bool {
    $status = (string) ($row['index_status'] ?? 'auto');
    if ($status === 'noindex') {
        return false;
    }
    if ($status === 'index') {
        return true;
    }
    if (swaapin_swap_landing_first_indexed_at($row) !== null) {
        return true;
    }
    return swaapin_swap_landing_count($row) >= SWAAPIN_MIN_LISTINGS_FOR_INDEX;
}

/** ثبت اولین ایندکس لندینگ و پایداری وضعیت آن. */
function swaapin_swap_landing_mark_indexed(array $row, int $count): void {
    $id = (int) ($row['id'] ?? 0);
    if ($id > 0 && swaapin_swap_landings_table_exists()) {
        try {
            DB::query(
                'UPDATE swap_landings
                 SET first_indexed_at = COALESCE(first_indexed_at, NOW()),
                     index_status = IF(index_status = "noindex", "noindex", "index")
                 WHERE id = ?',
                [$id]
            );
        } catch (Throwable $e) {
            // ثبت تاریخ ایندکس در listing_index_status کافی است
        }
    }
    if (function_exists('seo_mark_page_indexed')) {
        seo_mark_page_indexed(swaapin_swap_landing_url($row), $count);
    }
}

/** همه‌ی لندینگ‌های فعال (برای سایتمپ). */
function swaapin_swap_landings_active(): array {
    if (!swaapin_swap_landings_table_exists()) {
        return [];
    }
    try {
        return DB::fetchAll('SELECT * FROM swap_landings WHERE is_active = 1 ORDER BY location_slug, id');
    } catch (Throwable $e) {
        return [];
    }
}

/** همه‌ی لندینگ‌ها (فعال و غیرفعال) برای پنل مدیریت. */
function swaapin_swap_landings_all(): array {
    if (!swaapin_swap_landings_table_exists()) {
        return [];
    }
    try {
        return DB::fetchAll('SELECT * FROM swap_landings ORDER BY is_active DESC, type, location_slug, token');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * استخراج شکل لندینگ (نوع/دسته/مدل/مقصد) از اسلاگ مکان و توکن.
 * اسلاگ فقط از اسلاگ‌های موجود ساخته می‌شود؛ ترکیب نامعتبر → null.
 */
function swaapin_swap_landing_shape(string $locationSlug, string $token): ?array {
    $locationSlug = strtolower(trim($locationSlug));
    $locationSlug = $locationSlug === '' ? 'iran' : $locationSlug;
    $token        = strtolower(trim($token));

    if (!str_starts_with($token, 'swap')) {
        return null;
    }
    if ($locationSlug !== 'iran'
        && (!function_exists('swaapin_location_by_slug') || !swaapin_location_by_slug($locationSlug))) {
        return null;
    }
    $info = function_exists('swaapin_swap_landing_resolve') ? swaapin_swap_landing_resolve($token) : null;
    if ($info === null) {
        return null;
    }

    $shape = [
        'type'          => 'category',
        'location_slug' => $locationSlug,
        'token'         => $token,
        'category_slug' => '',
        'model_slug'    => '',
        'target_kind'   => '',
        'target_slug'   => '',
    ];

    if ($token === 'swap') {
        $shape['type'] = 'location';
        return $shape;
    }

    $catSlug   = (string) ($info['category']['slug'] ?? '');
    $modelSlug = (string) ($info['model'] ?? '');

    if (!empty($info['target_category']) || !empty($info['target_location'])) {
        $shape['type']        = 'xy';
        $shape['category_slug'] = $catSlug;
        $shape['model_slug']  = $modelSlug;
        if (!empty($info['target_location'])) {
            $shape['target_kind'] = 'location';
            $shape['target_slug'] = (string) $info['target_location'];
        } else {
            $shape['target_kind'] = 'category';
            $shape['target_slug'] = (string) $info['target_category'];
        }
        return $shape;
    }

    if ($catSlug === '') {
        // نه «swap» خالی، نه دسته‌ی معتبر → نامعتبر
        return null;
    }
    $shape['category_slug'] = $catSlug;
    if ($modelSlug !== '') {
        $shape['type']       = 'model';
        $shape['model_slug'] = $modelSlug;
    }
    return $shape;
}

/** ذخیره (درج/به‌روزرسانی) لندینگ از ورودی پنل. */
function swaapin_swap_landing_save(array $input, int $id = 0): array {
    swaapin_ensure_swap_landings_table();
    $errors = [];

    $locSlug = strtolower(trim((string) ($input['location_slug'] ?? 'iran')));
    $locSlug = $locSlug === '' ? 'iran' : $locSlug;
    $token   = strtolower(trim((string) ($input['token'] ?? '')));

    if ($token === '') {
        $errors[] = 'اسلاگ (توکن) لندینگ الزامی است.';
    } elseif (!str_starts_with($token, 'swap')) {
        $errors[] = 'اسلاگ لندینگ باید با «swap» شروع شود.';
    }

    $shape = $errors ? null : swaapin_swap_landing_shape($locSlug, $token);
    if (!$errors && $shape === null) {
        $errors[] = 'ترکیب مکان/دسته/مدل نامعتبر است؛ اسلاگ فقط از اسلاگ‌های موجود ساخته می‌شود.';
    }

    $indexStatus = (string) ($input['index_status'] ?? 'auto');
    if (!in_array($indexStatus, ['auto', 'index', 'noindex'], true)) {
        $indexStatus = 'auto';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'id' => $id];
    }

    $data = array_merge($shape, [
        'title'        => mb_substr(trim((string) ($input['title'] ?? '')), 0, 255),
        'h1'           => mb_substr(trim((string) ($input['h1'] ?? '')), 0, 255),
        'intro'        => trim((string) ($input['intro'] ?? '')),
        'index_status' => $indexStatus,
        'is_active'    => !empty($input['is_active']) ? 1 : 0,
    ]);

    if ($id <= 0) {
        try {
            $existing = DB::fetch(
                'SELECT id FROM swap_landings WHERE location_slug = ? AND token = ? LIMIT 1',
                [$locSlug, $token]
            );
            if ($existing) {
                $id = (int) $existing['id'];
            }
        } catch (Throwable $e) {
            // ادامه با درج جدید
        }
    }

    try {
        if ($id > 0) {
            DB::update('swap_landings', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('swap_landings', $data);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['ذخیره‌سازی ناموفق بود: ' . $e->getMessage()], 'id' => $id];
    }
    return ['ok' => true, 'errors' => [], 'id' => $id];
}

function swaapin_swap_landing_delete(int $id): bool {
    if ($id <= 0 || !swaapin_swap_landings_table_exists()) {
        return false;
    }
    try {
        DB::query('DELETE FROM swap_landings WHERE id = ?', [$id]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ── ورود گروهی از CSV/شیت (لیست کیورد تأییدشده) ──────────────────────────

/** تجزیه‌ی یک سلول به (مکان، توکن)؛ پشتیبانی از URL کامل، مسیر و توکن تنها. */
function swaapin_swap_parse_target(string $cell): ?array {
    $cell = trim($cell);
    if ($cell === '') {
        return null;
    }
    if (str_contains($cell, '://')) {
        $path = parse_url($cell, PHP_URL_PATH);
        $cell = is_string($path) ? $path : '';
    }
    $cell = strtolower(trim(rawurldecode($cell)));
    $pos  = strpos($cell, 'swaapin.ir/');
    if ($pos !== false) {
        $cell = substr($cell, $pos + strlen('swaapin.ir/'));
    }
    $seg = array_values(array_filter(explode('/', trim($cell, "/ \t\r\n")), 'strlen'));
    if ($seg === []) {
        return null;
    }
    if ($seg[0] === 'list' && isset($seg[1]) && str_starts_with($seg[1], 'swap')) {
        return ['location_slug' => 'iran', 'token' => $seg[1]];
    }
    if (count($seg) === 1 && str_starts_with($seg[0], 'swap')) {
        return ['location_slug' => 'iran', 'token' => $seg[0]];
    }
    if (count($seg) === 3 && $seg[1] === 'list' && str_starts_with($seg[2], 'swap')) {
        return ['location_slug' => $seg[0], 'token' => $seg[2]];
    }
    return null;
}

/** نگاشت ستون‌های CSV بر اساس سطر سرصفحه. */
function swaapin_swap_import_map(array $cells): ?array {
    $idx = [];
    foreach ($cells as $i => $c) {
        $k = strtolower(trim((string) $c));
        $k = trim((string) preg_replace('/\s+/u', ' ', $k));
        $k = str_replace("\xEF\xBB\xBF", '', $k);
        if ($k === '') {
            continue;
        }
        if (in_array($k, ['آدرس', 'آدرس جدید', 'url', 'address', 'link', 'لینک', 'نشانی'], true)) {
            $idx['url'] = $i;
        } elseif (in_array($k, ['مکان', 'استان', 'شهر', 'location', 'loc'], true)) {
            $idx['location'] = $i;
        } elseif (in_array($k, ['اسلاگ', 'slug', 'token', 'لندینگ'], true)) {
            $idx['token'] = $i;
        } elseif (in_array($k, ['تایتل', 'title', 'عنوان'], true)) {
            $idx['title'] = $i;
        } elseif ($k === 'h1') {
            $idx['h1'] = $i;
        } elseif (in_array($k, ['مقدمه', 'intro', 'intro_text'], true)) {
            $idx['intro'] = $i;
        }
    }
    return $idx ?: null;
}

/**
 * ورود گروهی لیست کیورد تأییدشده.
 * ورودی: متن CSV یا فهرست ساده‌ی مسیرها/توکن‌ها (هر خط یکی).
 * @return array{added:int,updated:int,skipped:string[]}
 */
function swaapin_swap_landing_import(string $text): array {
    swaapin_ensure_swap_landings_table();
    $added = 0;
    $updated = 0;
    $skipped = [];
    $map = null;
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

    foreach ($lines as $i => $raw) {
        $line = trim($raw);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $cells = str_getcsv($line);
        if (count($cells) === 1) {
            $cells = [trim((string) $cells[0])];
        }

        if ($map === null && count($cells) > 1) {
            $candidate = swaapin_swap_import_map($cells);
            if ($candidate !== null) {
                $map = $candidate;
                continue;
            }
        }

        $locSlug = 'iran';
        $token   = '';
        $extra   = [];

        if ($map !== null) {
            if (isset($map['url'])) {
                $p = swaapin_swap_parse_target((string) ($cells[$map['url']] ?? ''));
                if ($p) {
                    $locSlug = $p['location_slug'];
                    $token   = $p['token'];
                }
            }
            if ($token === '' && isset($map['token'])) {
                $t = strtolower(trim((string) ($cells[$map['token']] ?? '')));
                if ($t !== '' && str_starts_with($t, 'swap')) {
                    $token = $t;
                } elseif ($t !== '') {
                    $p = swaapin_swap_parse_target($t);
                    if ($p) {
                        $token   = $p['token'];
                        $locSlug = $p['location_slug'];
                    }
                }
            }
            if (isset($map['location'])) {
                $locSlug = strtolower(trim((string) ($cells[$map['location']] ?? ''))) ?: $locSlug;
                $p = swaapin_swap_parse_target($locSlug);
                if ($p) {
                    $locSlug = $p['location_slug'];
                }
            }
            foreach (['title', 'h1', 'intro'] as $k) {
                if (isset($map[$k]) && trim((string) ($cells[$map[$k]] ?? '')) !== '') {
                    $extra[$k] = trim((string) $cells[$map[$k]]);
                }
            }
        }

        if ($token === '') {
            foreach ($cells as $cell) {
                $p = swaapin_swap_parse_target((string) $cell);
                if ($p !== null) {
                    $locSlug = $p['location_slug'];
                    $token   = $p['token'];
                    break;
                }
            }
        }

        if ($token === '') {
            $skipped[] = 'خط ' . ($i + 1) . ': توکن معاوضه یافت نشد';
            continue;
        }

        $shape = swaapin_swap_landing_shape($locSlug, $token);
        if ($shape === null) {
            $skipped[] = 'خط ' . ($i + 1) . ': ترکیب «' . $locSlug . '/' . $token . '» نامعتبر است';
            continue;
        }

        $existing = swaapin_swap_landing_find($locSlug, $token);
        $res = swaapin_swap_landing_save(array_merge($extra, [
            'location_slug' => $locSlug,
            'token'         => $token,
            'index_status'  => $existing['index_status'] ?? 'auto',
            'is_active'     => 1,
        ]), $existing ? (int) $existing['id'] : 0);

        if ($res['ok']) {
            if ($existing) {
                $updated++;
            } else {
                $added++;
            }
        } else {
            $skipped[] = 'خط ' . ($i + 1) . ': ' . implode(' ', $res['errors']);
        }
    }

    return ['added' => $added, 'updated' => $updated, 'skipped' => array_slice($skipped, 0, 50)];
}
