<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/i18n.php';

try {
    swaapin_ensure_category_tree();
} catch (Throwable $e) {
    swapin_debug_log('category_idx_cat_tree_skip', ['msg' => $e->getMessage()]);
}

// فاز ۱: مسیر آگهی بدون پیشوند (/{fa-slug}/{id} یا /{id}) → listings/view.php و سپس 301 کانونیکال
$_reqPath = trim(rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)), '/');
$_appBase = trim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
if ($_appBase !== '' && str_starts_with($_reqPath, $_appBase)) {
    $_reqPath = trim(substr($_reqPath, strlen($_appBase)), '/');
}
if ($_reqPath !== '') {
    $_segments = explode('/', $_reqPath);
    $_last     = end($_segments);
    $_systemFirst = [
        'api', 'admin', 'includes', 'seed', 'database', 'storage', 'tools', 'vendor', 'logs', 'backups', 'sql',
        'store', 'orders', 'chat', 'store-offers', 'shops', 'search', 'shop', 'support', 'pwa', 'ai-lab', 'sep',
        'iso', 'listing', 'listings', 'auth', 'page', 'src', 'css', 'js', 'assets', 'scripts', 'ops', 'blog',
    ];
    if (ctype_digit((string) $_last)
        && (count($_segments) === 1 || (count($_segments) === 2 && !in_array(strtolower($_segments[0]), $_systemFirst, true)))) {
        $_GET['id'] = (int) $_last;
        require __DIR__ . '/../listings/view.php';
        exit;
    }

    // فاز ۲: مسیرهای لاتین بدون پیشوند
    //  (الف) لندینگ معاوضه کشوری: /list/swap[...
    //  (ب) مسیر مکان: /{location}[/{category}[/{brand}[/{model}[/{trim}]]]]
    //  (ج) لندینگ معاوضه مکانی: /{location}/list/swap[...
    $_firstSeg = strtolower((string) $_segments[0]);
    $_segCount = count($_segments);

    // (الف) /list/swap و /list/swap-... (سند — بخش ۴)
    if ($_firstSeg === 'list' && $_segCount === 2
        && str_starts_with(strtolower((string) $_segments[1]), 'swap')) {
        $_GET['swap'] = strtolower(rawurldecode((string) $_segments[1]));
        require __DIR__ . '/../listings/all.php';
        exit;
    }

    // (ب) و (ج) مسیرهای با سگمنت اول مکان
    if (!in_array($_firstSeg, $_systemFirst, true)) {
        // درخواست‌های پرتکرار (دسته‌ها) نباید هر بار جدول مکان را seed کنند؛
        // فقط وقتی جدول وجود ندارد یک‌بار درخت مکان ساخته می‌شود.
        if (!swaapin_locations_table_exists()) {
            try {
                swaapin_ensure_location_tree();
            } catch (Throwable $e) {
                swapin_debug_log('category_idx_loc_tree_skip', ['msg' => $e->getMessage()]);
            }
        }
        $locRow = swaapin_location_by_slug($_firstSeg);
        if ($locRow) {
            // (ج) /{location}/list/swap و /{location}/list/swap-...
            if ($_segCount === 3 && strtolower((string) $_segments[1]) === 'list'
                && str_starts_with(strtolower((string) $_segments[2]), 'swap')) {
                $_GET['loc_slug'] = $_firstSeg;
                $_GET['swap']     = strtolower(rawurldecode((string) $_segments[2]));
                require __DIR__ . '/../listings/all.php';
                exit;
            }

            // /{location}
            if ($_segCount === 1) {
                $_GET['loc_slug'] = $_firstSeg;
                require __DIR__ . '/../listings/all.php';
                exit;
            }

            // /{location}/{category} و عمیق‌تر (برند/مدل/تیپ)
            $catSeg = strtolower(rawurldecode((string) $_segments[1]));
            $catRow = DB::fetch('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [$catSeg]);
            if ($catRow) {
                $_GET['loc_slug'] = $_firstSeg;
                $_GET['cat']      = $catRow['slug'];

                if ($_segCount > 2) {
                    // برند/مدل/تیپ (سند — بخش ۱ و ۲): /{location}/{category}/{brand}[/{model}[/{trim}]]
                    $nodeKeys = ['brand', 'model', 'trim'];
                    $parentId = 0;
                    $resolved = true;
                    $found    = [];
                    for ($i = 2; $i < $_segCount && $i < 5; $i++) {
                        $nodeSlug = strtolower(rawurldecode((string) $_segments[$i]));
                        $node = swaapin_catalog_node((int) $catRow['id'], $parentId, $nodeSlug);
                        if (!$node) {
                            $resolved = false;
                            break;
                        }
                        $found[$nodeKeys[$i - 2]] = $nodeSlug;
                        $parentId = (int) $node['id'];
                    }
                    if ($_segCount > 5 || !$resolved) {
                        http_response_code(404);
                        exit;
                    }
                    foreach ($found as $k => $v) {
                        $_GET[$k] = $v;
                    }
                }

                require __DIR__ . '/../listings/all.php';
                exit;
            }

            // مکان معتبر ولی دسته نامعتبر
            if ($_segCount === 2) {
                http_response_code(404);
                exit;
            }
        }
    }
}

$redirectSlugs = trim((string)($_GET['redirect_slugs'] ?? ''));
$slugPath      = trim((string)($_GET['slug_path'] ?? ''));

if ($redirectSlugs !== '') {
    $parts = array_values(array_filter(explode('/', $redirectSlugs), 'strlen'));
    if (!empty($parts)) {
        $last = end($parts);
        $alias = swaapin_resolve_legacy_category_slug($last);
        $newUrl = category_url($alias ?? $last);
        if ($newUrl && strpos($newUrl, '/category/') === false) {
            header('Location: ' . $newUrl, true, 301);
            exit;
        }
        $slugPath = $redirectSlugs;
    }
}

$category = null;
$slug     = '';

if ($slugPath !== '') {
    $parts = array_values(array_filter(explode('/', $slugPath), 'strlen'));
    if (!empty($parts)) {
        $parts = array_map('rawurldecode', $parts);
        $category = get_category_by_slug_path($parts);
        if (!$category && count($parts) === 1) {
            $alias = swaapin_resolve_legacy_category_slug($parts[0]);
            if ($alias !== null) {
                header('Location: ' . category_url($alias), true, 301);
                exit;
            }
        }
    }
}

if (!$category && isset($_GET['slug'])) {
    $slug = trim((string)$_GET['slug']);
    $category = DB::fetch(
        'SELECT * FROM categories WHERE slug = ? AND is_active = 1',
        [$slug]
    );
}

if (!$category) {
    http_response_code(404);
    exit;
}

$_GET['cat'] = $category['slug'];

require __DIR__ . '/../listings/all.php';
