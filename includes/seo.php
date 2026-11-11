<?php
// includes/seo.php — SEO helpers (JSON-LD, canonical)

function seo_canonical(?string $override = null): string {
    if ($override !== null && $override !== '') {
        return seo_resolve_canonical($override);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return rtrim(APP_URL, '/') . $path;
}

function seo_is_valid_canonical_url(string $url): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    return str_starts_with($url, '/');
}

/**
 * Return a safe canonical URL — ignore non-URL values (e.g. page titles stored by mistake).
 */
function seo_resolve_canonical(?string $override, ?string $fallback = null): string {
    $fallback ??= seo_canonical();
    $override = trim((string) $override);

    if ($override === '' || !seo_is_valid_canonical_url($override)) {
        return $fallback;
    }

    if (str_starts_with($override, '/')) {
        return rtrim(APP_URL, '/') . $override;
    }

    return $override;
}

/** Normalize canonical before persisting in CMS/admin forms. */
function seo_sanitize_stored_canonical(string $url): string {
    $url = trim($url);

    return ($url !== '' && seo_is_valid_canonical_url($url)) ? $url : '';
}

function seo_json_ld_website(): array {
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'WebSite',
        'name'        => APP_NAME,
        'alternateName' => APP_NAME_EN,
        'url'         => APP_URL . '/',
        'description' => 'بازار هوشمند مبادله کالا با کالا در ایران',
        'inLanguage'  => 'fa-IR',
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => APP_URL . '/?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/**
 * SiteNavigationElement — اعلام صریح بخش‌های اصلی سایت به گوگل.
 * یکی از سیگنال‌های مؤثر در انتخاب لینک‌های Sitelinks برای کوئری برند.
 */
function seo_json_ld_site_navigation(): array {
    $items = [
        ['خانه', APP_URL . '/'],
        ['دسته‌بندی‌ها', APP_URL . '/iran'],
        ['فروشگاه‌ها', APP_URL . '/shops'],
        ['دستیار هوشمند', APP_URL . '/ai-assistant'],
        ['ثبت آگهی', APP_URL . '/listings/create'],
    ];

    $elements = [];
    foreach ($items as $i => [$name, $url]) {
        $elements[] = [
            '@type'    => 'SiteNavigationElement',
            'position' => $i + 1,
            'name'     => $name,
            'url'      => $url,
        ];
    }

    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'itemListElement' => $elements,
    ];
}

function seo_json_ld_organization(): array {
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => APP_NAME,
        'url'      => APP_URL . '/',
        'logo'     => LOGO_URL,
        'contactPoint' => [
            '@type'       => 'ContactPoint',
            'telephone'   => '+98-998-153-4269',
            'contactType' => 'customer service',
            'email'       => 'info@swaapin.ir',
            'areaServed'  => 'IR',
            'availableLanguage' => 'Persian',
        ],
        'address' => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'تهران',
            'streetAddress'   => 'مرکز نوآوری اکباتان',
            'addressCountry'  => 'IR',
        ],
    ];
}

function seo_json_ld_product(array $listing, ?string $imageUrl, string $pageUrl): array {
    $mode = (string) ($listing['listing_mode'] ?? 'swap');

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $listing['title'],
        'description' => mb_strimwidth(strip_tags($listing['description'] ?? ''), 0, 300),
        'url'         => $pageUrl,
        'category'    => $listing['cat_name'] ?? '',
        'itemCondition' => 'https://schema.org/UsedCondition',
    ];

    if ($imageUrl) {
        $schema['image'] = $imageUrl;
    }

    // آگهی معاوضه Offer نمی‌گیرد تا گوگل آن را Merchant listing در نظر نگیرد.
    // فقط آگهی فروش/هر دو با قیمت واقعی اعلام‌شده، Offer با مبلغ ریالی (IRR) می‌گیرد.
    $sellPriceToman = (float) ($listing['sell_price'] ?? 0);
    if ($mode !== 'swap' && $sellPriceToman > 0) {
        $schema['offers'] = [
            '@type'         => 'Offer',
            'priceCurrency' => 'IRR',
            'price'         => (int) round($sellPriceToman * 10), // تومان → ریال
            'availability'  => 'https://schema.org/InStock',
            'url'           => $pageUrl,
        ];
    }

    // برند فقط در صورت وجود برند واقعی کالا؛ جدول listings فیلد برند ندارد، پس حذف می‌شود.

    return $schema;
}

function seo_json_ld_breadcrumbs(array $items): array {
    $list = [];
    foreach ($items as $i => $item) {
        $entry = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $item['name'],
        ];
        if (!empty($item['url'])) {
            $entry['item'] = $item['url'];
        }
        $list[] = $entry;
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
}

/**
 * ── ایندکس‌پذیری صفحات لیستی (سند معماری سئو — بخش ۷) ──────────────────────
 * قاعده: صفحات لیستی تنها با حداقل ۶ آگهی فعال ایندکس‌پذیر می‌شوند؛
 * پس از اولین ایندکس، حتی با کاهش موجودی، ایندکس باقی می‌ماند.
 */
if (!defined('SWAAPIN_MIN_LISTINGS_FOR_INDEX')) {
    define('SWAAPIN_MIN_LISTINGS_FOR_INDEX', 6);
}

function seo_index_status_table_exists(): bool {
    return function_exists('db_has_table') && db_has_table('listing_index_status');
}

function seo_ensure_index_status_table(): void {
    if (seo_index_status_table_exists()) {
        return;
    }
    try {
        DB::query(
            "CREATE TABLE IF NOT EXISTS `listing_index_status` (
                `path` VARCHAR(191) NOT NULL,
                `is_indexed` TINYINT(1) NOT NULL DEFAULT 0,
                `indexed_at` DATETIME NULL,
                `last_count` INT NOT NULL DEFAULT 0,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`path`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('index_status_create_fail', ['msg' => $e->getMessage()]);
        }
    }
}

/** نرمال‌سازی مسیر صفحات لیستی (حذف دامنه و پارامتر). */
function seo_normalize_index_path(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $appPrefix = rtrim(APP_URL, '/');
    if ($appPrefix !== '' && str_starts_with($path, $appPrefix)) {
        $path = substr($path, strlen($appPrefix));
    }
    $parsed = parse_url($path, PHP_URL_PATH);
    if (is_string($parsed) && $parsed !== '') {
        $path = $parsed;
    }
    return '/' . trim($path, '/');
}

/** تاریخ اولین ایندکس یک مسیر؛ null اگر هنوز ایندکس نشده باشد. */
function seo_page_indexed_at(string $path): ?string {
    $path = seo_normalize_index_path($path);
    if ($path === '' || !seo_index_status_table_exists()) {
        return null;
    }
    try {
        $row = DB::fetch('SELECT is_indexed, indexed_at FROM listing_index_status WHERE path = ? LIMIT 1', [$path]);
    } catch (Throwable $e) {
        return null;
    }
    if ($row && (int) $row['is_indexed'] === 1) {
        return (string) ($row['indexed_at'] ?? '');
    }
    return null;
}

/** ثبت اولین ایندکس صفحه و تاریخ آن. */
function seo_mark_page_indexed(string $path, int $count): void {
    $path = seo_normalize_index_path($path);
    if ($path === '') {
        return;
    }
    seo_ensure_index_status_table();
    if (!seo_index_status_table_exists()) {
        return;
    }
    try {
        DB::query(
            'INSERT INTO listing_index_status (path, is_indexed, indexed_at, last_count) VALUES (?, 1, NOW(), ?)
             ON DUPLICATE KEY UPDATE is_indexed = 1, indexed_at = COALESCE(indexed_at, NOW()), last_count = VALUES(last_count)',
            [$path, $count]
        );
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('index_status_mark_fail', ['path' => $path, 'msg' => $e->getMessage()]);
        }
    }
}

/**
 * آیا صفحه لیستی با «count» آگهی مجاز به ایندکس است؟
 * اگر قبلاً ایندکس شده باشد همیشه true؛ در غیر این صورت با رسیدن به حداقل تعداد، ایندکس ثبت می‌شود.
 */
function seo_list_page_indexable(string $path, int $count, int $min = SWAAPIN_MIN_LISTINGS_FOR_INDEX): bool {
    $path = seo_normalize_index_path($path);
    if ($path === '') {
        return false;
    }
    if (seo_page_indexed_at($path) !== null) {
        return true;
    }
    if ($count >= $min) {
        seo_mark_page_indexed($path, $count);
        return true;
    }
    return false;
}

/** مسیرهای ثبت‌شده به‌عنوان ایندکس‌شده: path => indexed_at */
function seo_indexed_paths(): array {
    if (!seo_index_status_table_exists()) {
        return [];
    }
    try {
        $rows = DB::fetchAll('SELECT path, indexed_at FROM listing_index_status WHERE is_indexed = 1');
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['path']] = (string) ($r['indexed_at'] ?? '');
    }
    return $out;
}

/** ثبت گروهی اولین ایندکس صفحات. */
function seo_mark_pages_indexed(array $paths): void {
    $clean = [];
    foreach ($paths as $p) {
        $p = seo_normalize_index_path((string) $p);
        if ($p !== '') {
            $clean[$p] = true;
        }
    }
    $paths = array_keys($clean);
    if ($paths === []) {
        return;
    }
    seo_ensure_index_status_table();
    if (!seo_index_status_table_exists()) {
        return;
    }
    try {
        $values = implode(',', array_fill(0, count($paths), '(?, 1, NOW(), 0)'));
        DB::query(
            "INSERT INTO listing_index_status (path, is_indexed, indexed_at, last_count) VALUES {$values}
             ON DUPLICATE KEY UPDATE is_indexed = 1, indexed_at = COALESCE(indexed_at, NOW())",
            $paths
        );
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('index_status_bulk_fail', ['msg' => $e->getMessage()]);
        }
    }
}

function seo_clean_text(string $s, int $maxLen = 0): string {
    $s = preg_replace('/\s+/u', ' ', trim(strip_tags($s)));
    $s = preg_replace("/[«»\"'`]+/u", '', $s);
    if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
        $s = mb_strimwidth($s, 0, $maxLen, '…');
    }
    return $s;
}

function seo_listing_title(array $listing): string {
    $title       = seo_clean_text((string)($listing['title'] ?? ''), 50);
    $catName     = seo_clean_text((string)($listing['cat_name']  ?? ''));
    $city        = seo_clean_text((string)($listing['city']      ?? ''));
    $want        = seo_clean_text((string)($listing['want_in_return'] ?? ''), 35);
    $mode        = (string)($listing['listing_mode'] ?? 'swap');

    $suffix = ' | ' . APP_NAME;
    $prefix = ($mode === 'sell') ? 'فروش ' : 'معاوضه ';

    $parts = [];

    if ($title !== '' && $title !== '0') {
        $parts[] = $prefix . $title;
    } else {
        $parts[] = $prefix . trim($catName . ' مناسب');
    }

    if ($want !== '') {
        $parts[] = 'با ' . $want;
    }

    if ($city !== '') {
        $parts[] = 'در ' . $city;
    }

    $combined = implode(' | ', $parts);

    if (mb_strlen($combined . $suffix) > 65) {
        $combined = mb_strimwidth($combined, 0, 63 - mb_strlen($suffix), '…');
    }

    return $combined . $suffix;
}

function seo_listing_description(array $listing): string {
    $title       = seo_clean_text((string)($listing['title'] ?? ''));
    $catName     = seo_clean_text((string)($listing['cat_name']  ?? ''));
    $city        = seo_clean_text((string)($listing['city']      ?? ''));
    $want        = seo_clean_text((string)($listing['want_in_return'] ?? ''), 60);
    $description = seo_clean_text((string)($listing['description'] ?? ''));
    $condition   = seo_clean_text((string)($listing['condition'] ?? ''));
    $mode        = (string)($listing['listing_mode'] ?? 'swap');
    $value       = (float)($listing['estimated_value'] ?? 0);

    $sentences = [];

    if ($mode === 'sell') {
        $head = 'خرید و فروش ';
    } else {
        $head = 'معاوضه ';
    }
    $head .= ($catName ?: 'کالا');
    if ($city)  $head .= ' در ' . $city;
    $sentences[] = $head . ' در پلتفرم سواَپین .';

    if ($title && $title !== $catName) {
        $sentences[] = 'عنوان آگهی: ' . $title . '.';
    }

    if ($want && $mode !== 'sell') {
        $sentences[] = 'درخواست تعویض با: ' . $want . '.';
    }

    if ($condition && $condition !== 'good') {
        $condMap = [
            'new'         => 'نو',
            'like_new'    => 'در حد نو',
            'good'        => 'خوب',
            'fair'        => 'قابل قبول',
            'for_parts'   => 'قطعی',
        ];
        $c = $condMap[$condition] ?? $condition;
        $sentences[] = 'وضعیت کالا: ' . $c . '.';
    }

    if ($value > 0) {
        $toman = (int)round($value);
        if ($toman >= 1_000_000) {
            $sentences[] = 'ارزش تخمینی حدود ' . number_format($toman / 1_000_000, 1, '.', '') . ' میلیون تومان.';
        } else {
            $sentences[] = 'ارزش تخمینی حدود ' . number_format($toman) . ' تومان.';
        }
    }

    if ($description !== '' && count($sentences) < 4) {
        $extra = mb_strimwidth($description, 0, 80, '…');
        if (mb_strlen($extra) > 20) {
            $sentences[] = $extra;
        }
    }

    $out = implode(' ', $sentences);
    if (mb_strlen($out) > 160) {
        $out = mb_strimwidth($out, 0, 158, '…');
    }
    return $out;
}

/**
 * آگهی‌های جایگزین برای صفحات کم‌آگهی (سند — بخش ۱۰).
 *
 * ترتیب گسترش مکان: همان محله → سایر محله‌های شهر → استان → کشور
 * و در صورت نیاز یک سطح بالاتر در دسته‌بندی.
 * آگهی‌های جایگزین در محاسبه حداقل ۶ آگهی برای ایندکس شدن حساب نمی‌شوند.
 *
 * @param array|null $locationRow مکان فعلی صفحه
 * @param array|null $categoryRow دسته فعلی صفحه
 * @param int[]      $excludeIds  شناسه آگهی‌های اصلی (برای جلوگیری از تکرار)
 * @param int        $need        حداکثر تعداد آگهی جایگزین
 * @return array{rows: array, title: string}
 */
function swaapin_fallback_listings(?array $locationRow, ?array $categoryRow, array $excludeIds = [], int $need = 8): array {
    $need    = max(1, min(24, $need));
    $exclude = array_values(array_unique(array_map('intval', $excludeIds)));

    // نردبان مکان از نزدیک به دور
    $ladder = [];
    $cur    = $locationRow;
    $safety = 8;
    while ($cur && $safety-- > 0) {
        $ladder[] = [
            'filter' => swaapin_location_filter($cur),
            'name'   => (string) ($cur['name'] ?? ''),
        ];
        $pid = (int) ($cur['parent_id'] ?? 0);
        $cur = $pid > 0 ? (DB::fetch('SELECT * FROM locations WHERE id = ? LIMIT 1', [$pid]) ?: null) : null;
    }
    if (empty($ladder)) {
        $ladder[] = ['filter' => ['cities' => [], 'neighborhood' => ''], 'name' => ''];
    }

    // نردبان دسته: دسته فعلی، سپس یک سطح بالاتر
    $catRounds = [];
    if ($categoryRow) {
        $catRounds[] = ['id' => (int) $categoryRow['id'], 'name' => (string) ($categoryRow['name'] ?? '')];
        $parentId = (int) ($categoryRow['parent_id'] ?? 0);
        if ($parentId > 0) {
            $prow = DB::fetch('SELECT id, name FROM categories WHERE id = ? LIMIT 1', [$parentId]);
            if ($prow) {
                $catRounds[] = ['id' => (int) $prow['id'], 'name' => (string) $prow['name']];
            }
        }
    } else {
        $catRounds[] = ['id' => 0, 'name' => ''];
    }

    $rows  = [];
    $seen  = $exclude ? array_fill_keys($exclude, true) : [];
    $title = '';

    foreach ($catRounds as $round) {
        $scopeIds = $round['id'] > 0 ? swaapin_category_descendant_ids((int) $round['id']) : [];
        foreach ($ladder as $step) {
            if (count($rows) >= $need) {
                break 2;
            }
            $where  = [listing_public_sql('l'), 'l.listing_mode != "sell"'];
            $params = [];
            if (!empty($scopeIds)) {
                $where[] = 'l.category_id IN (' . implode(',', array_fill(0, count($scopeIds), '?')) . ')';
                foreach ($scopeIds as $cid) {
                    $params[] = (int) $cid;
                }
            }
            if (!empty($step['filter']['cities'])) {
                $where[] = 'l.city IN (' . implode(',', array_fill(0, count($step['filter']['cities']), '?')) . ')';
                foreach ($step['filter']['cities'] as $cn) {
                    $params[] = $cn;
                }
            }
            if (($step['filter']['neighborhood'] ?? '') !== '') {
                $where[]  = 'l.neighborhood = ?';
                $params[] = $step['filter']['neighborhood'];
            }
            if ($seen) {
                $where[] = 'l.id NOT IN (' . implode(',', array_fill(0, count($seen), '?')) . ')';
                foreach (array_keys($seen) as $eid) {
                    $params[] = (int) $eid;
                }
            }
            $params[] = $need - count($rows);
            try {
                $got = DB::fetchAll(
                    "SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
                            c.name AS cat_name, c.slug AS cat_slug,
                            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
                     FROM listings l
                     JOIN users u ON u.id = l.user_id
                     JOIN categories c ON c.id = l.category_id
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY l.created_at DESC, l.id DESC
                     LIMIT ?",
                    $params
                );
            } catch (Throwable $e) {
                $got = [];
            }
            foreach ($got as $g) {
                $gid = (int) $g['id'];
                if (isset($seen[$gid])) {
                    continue;
                }
                $seen[$gid] = true;
                $rows[]     = $g;
            }
            if (!empty($got) && $title === '') {
                $catPart = $round['name'] !== '' ? $round['name'] : 'آگهی‌ها';
                $locPart = $step['name'] !== '' ? (' در ' . $step['name']) : '';
                $title   = 'سایر ' . $catPart . $locPart;
            }
        }
    }

    if (!empty($rows)) {
        listing_card_prefetch($rows);
    }
    return ['rows' => $rows, 'title' => $title];
}
