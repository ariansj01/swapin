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
// وقتی nginx با rewrite داخلی به این فایل می‌زند، REQUEST_URI ممکن است
// /category/index.php باشد؛ مسیر واقعی در slug_path است (مثل iran).
$_reqPathNorm = strtolower($_reqPath);
if ($_reqPathNorm === 'category/index.php' || $_reqPathNorm === 'category/index' || $_reqPathNorm === 'category') {
    $_fromQuery = trim((string)($_GET['slug_path'] ?? ''), '/');
    if ($_fromQuery === '') {
        $_fromQuery = trim((string)($_GET['redirect_slugs'] ?? ''), '/');
    }
    $_reqPath = $_fromQuery;
}
if ($_reqPath !== '') {
    $_segments = explode('/', $_reqPath);
    $_last     = end($_segments);

    // ── پارامتر ?city= → آدرس تمیز /{location}[/{category}] (سند — الگوی URL) ──
    // صفحههای شهری قبلاً فقط با پارامتر ساخته میشدند و canonical آنها را حذف میکرد،
    // بنابراین هرگز ایندکس نمیشدند. اینجا نام شهر/استان به اسلاگ مکان تبدیل و ۳۰۱ میشود.
    if (!headers_sent() && !empty($_GET['city'])) {
        $cityName  = trim((string) $_GET['city']);
        $locByName = swaapin_location_by_name($cityName, 'city')
            ?? swaapin_location_by_name($cityName, 'province');
        if ($locByName) {
            $locTarget = '';
            if (swaapin_location_by_slug((string) ($_segments[0] ?? ''))) {
                // مسیر از قبل پیشوند مکان دارد → فقط مکان را با مکان درست عوض کن
                $_segments[0] = (string) $locByName['slug'];
                $locTarget = rtrim(APP_URL, '/') . '/' . implode('/', array_map('rawurlencode', $_segments));
            } else {
                // مسیر دسته بدون پیشوند مکان → برگ دسته (یا معادل قدیمی) را به مکان بچسبان
                $leafRow  = DB::fetch('SELECT slug FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1', [strtolower(rawurldecode((string) end($_segments)))]);
                if (!$leafRow) {
                    $leafAlias = swaapin_resolve_legacy_category_slug(strtolower(rawurldecode((string) end($_segments))));
                    if ($leafAlias !== null) {
                        $leafRow = DB::fetch('SELECT slug FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1', [$leafAlias]);
                    }
                }
                if ($leafRow) {
                    $locTarget = rtrim(swaapin_location_url($locByName), '/') . '/' . rawurlencode((string) $leafRow['slug']);
                }
            }
            if ($locTarget !== '') {
                $cityKeep = [];
                // طبق نقشهٔ ۳۰۱، پارامترهای بی‌ارزش (sort، time_ago) به مقصد منتقل نمی‌شوند.
                foreach (['q', 'want', 'want_category_id', 'condition', 'price_min', 'price_max', 'page'] as $ckpk) {
                    $ckpv = $_GET[$ckpk] ?? '';
                    if ($ckpv !== '' && $ckpv !== null && $ckpv !== '0' && $ckpv !== 0) {
                        $cityKeep[$ckpk] = $ckpv;
                    }
                }
                header('Location: ' . $locTarget . ($cityKeep ? '?' . http_build_query($cityKeep) : ''), true, 301);
                exit;
            }
        }
    }

    $_systemFirst = [
        'api', 'admin', 'includes', 'seed', 'database', 'storage', 'tools', 'vendor', 'logs', 'backups', 'sql',
        'store', 'orders', 'chat', 'store-offers', 'shops', 'search', 'shop', 'support', 'pwa', 'ai-lab', 'sep',
        'iso', 'listing', 'listings', 'auth', 'page', 'src', 'css', 'js', 'assets', 'scripts', 'ops', 'blog',
        'clubs', 'club', 'category', 'community',
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
                        require __DIR__ . '/../404.php';
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
                // اسلاگ قدیمی دسته (مثل car → cars) → ریدایرکت 301 به مسیر کانونیکال
                $alias = swaapin_resolve_legacy_category_slug($catSeg);
                if ($alias !== null) {
                    header('Location: ' . rtrim(APP_URL, '/') . '/' . $_firstSeg . '/' . $alias, true, 301);
                    exit;
                }
                require __DIR__ . '/../404.php';
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
        $currentPath = strtolower(implode('/', $parts));

        // الگوی کانونیکال دسته: /iran/{leaf} — بدون تودرتویی والدها و با پیشوند مکان.
        // برگ دسته از «آخرین سگمنت» مسیر حل می‌شود (اسلاگ جاری، سپس alias قدیمی)؛
        // بنابراین هر مسیر قدیمی/تودرتو (مثل /electronics/mobile-tablet/mobile-phones)
        // در یک مرحله به کانونیکال ۳۰۱ می‌شود، نه به والدِ اشتباه.
        $lastSeg      = strtolower((string) end($parts));

        // اسلاگ سطح۱ قدیمی که همنام یک برگ جدید شده است (مثل home-appliances):
        // مسیر تکبخشی قدیمی باید به دستهٔ سطح۱ جدید ۳۰۱ شود، نه به برگ همنام.
        // بدون این، DB-lookup برگ همنام روی alias سایه می‌انداخت.
        if (count($parts) === 1) {
            $topAlias = swaapin_resolve_legacy_category_slug($lastSeg);
            if ($topAlias !== null && $topAlias !== $lastSeg) {
                $topAliasRow = DB::fetch(
                    'SELECT slug FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1',
                    [$topAlias]
                );
                if ($topAliasRow) {
                    header('Location: ' . category_url((string) $topAliasRow['slug']), true, 301);
                    exit;
                }
            }
        }

        $resolvedSlug = null;

        $leafRow = DB::fetch(
            'SELECT slug FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1',
            [$lastSeg]
        );
        if ($leafRow) {
            $resolvedSlug = (string) $leafRow['slug'];
        } else {
            $alias = swaapin_resolve_legacy_category_slug($lastSeg);
            if ($alias !== null) {
                $aliasRow = DB::fetch(
                    'SELECT slug FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1',
                    [$alias]
                );
                if ($aliasRow) {
                    $resolvedSlug = (string) $aliasRow['slug'];
                }
            }
        }

        if ($resolvedSlug !== null) {
            if ($currentPath !== 'iran/' . $resolvedSlug) {
                header('Location: ' . category_url($resolvedSlug), true, 301);
                exit;
            }
            $category = DB::fetch(
                'SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1',
                [$resolvedSlug]
            );
        }
    }
}

if (!$category && isset($_GET['slug'])) {
    $slug = trim((string)$_GET['slug']);
    $category = DB::fetch(
        'SELECT * FROM categories WHERE slug = ? AND is_active = 1',
        [$slug]
    );
    if ($category) {
        // دسترسی پارامتری (?slug=) → ۳۰۱ به آدرس کانونیکال /iran/{leaf}
        header('Location: ' . category_url((string) $category['slug']), true, 301);
        exit;
    }
}

if (!$category) {
    require __DIR__ . '/../404.php';
    exit;
}

$_GET['cat'] = $category['slug'];

require __DIR__ . '/../listings/all.php';
