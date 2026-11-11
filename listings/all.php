<?php
// SWAPIN_DIRECT_ALL_PHP_301
if (!defined('SKIP_301') && isset($_SERVER["REQUEST_URI"]) && preg_match("#^/listings/(?:all|all\.php)(?:/|\?|$)#", $_SERVER["REQUEST_URI"])) {
    $query = $_SERVER["QUERY_STRING"] ?? "";
    header("Location: /iran" . ($query ? "?" . $query : ""), true, 301);
    exit;
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/ai_category_keywords.php';

$user = auth_user();

try {
    swaapin_ensure_category_tree();
} catch (Throwable $e) {
    swapin_debug_log('listings_all_cat_tree_skip', ['msg' => $e->getMessage()]);
}

// فیلترها
$search    = clean($_GET['q']          ?? '');
$catSlug   = clean($_GET['cat']        ?? '');
$cityFilter = clean($_GET['city']       ?? '');
$city      = $cityFilter ?: 'تهران';
$locMode   = clean($_GET['loc']        ?? '');
$nearbyCitiesRaw = clean($_GET['nearby_cities'] ?? '');
$nearbyCitiesList = ($locMode === 'nearby') ? parse_nearby_cities_param($nearbyCitiesRaw) : [];
$wantType  = clean($_GET['want']       ?? '');
$condition = clean($_GET['condition']  ?? '');
$pmin      = (int)($_GET['price_min']  ?? 0);
$pmax      = (int)($_GET['price_max']  ?? 0);
$sort      = in_array($_GET['sort'] ?? '', ['new','old','value']) ? $_GET['sort'] : 'new';
$page      = max(1, (int)($_GET['page'] ?? 1));
$timeAgo   = in_array($_GET['time_ago'] ?? '', ['3h','12h','1d','3d','1w']) ? $_GET['time_ago'] : '';

if ($locMode === 'nearby' && $nearbyCitiesList === []) {
    $locMode = '';
    $nearbyCitiesRaw = '';
}

// فاز ۲: مکان ساختاریافته — /{location} و /{location}/{category} (بدون پیشوند /s/)
$locSlug    = clean($_GET['loc_slug'] ?? '');
$locRow     = $locSlug !== '' ? swaapin_location_by_slug($locSlug) : null;
$locName    = $locRow ? (string) $locRow['name'] : '';
$locCityNames    = [];
$locNeighborhood = '';
if ($locRow) {
    $locFilter       = swaapin_location_filter($locRow);
    $locCityNames    = $locFilter['cities'];
    $locNeighborhood = $locFilter['neighborhood'];
    if ($cityFilter === '') {
        $city = $locName;
    }
}

// لندینگ معاوضه (سند — بخش ۴): /list/swap، /list/swap-{cat}، /list/swap-{cat}-{model}، /list/swap-{X}-with-{Y}
$swapToken = clean($_GET['swap'] ?? '');
$swapInfo  = null;
if ($swapToken !== '' && function_exists('swaapin_swap_landing_resolve')) {
    $swapInfo = swaapin_swap_landing_resolve($swapToken);
    if ($swapInfo && !empty($swapInfo['category']) && $catSlug === '') {
        $catSlug = (string) $swapInfo['category']['slug'];
    }
}

// دسته‌بندی
$category = $catSlug ? DB::fetch('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [$catSlug]) : null;
$catId    = $category ? (int)$category['id'] : null;
$catScopeIds = [];
if ($catId > 0) {
    $catScopeIds = swaapin_category_descendant_ids($catId);
}

// برند/مدل/تیپ (سند — بخش ۱ و ۲): /{location}/{category}/{brand}[/{model}[/{trim}]]
$brandSlug    = clean($_GET['brand'] ?? '');
$modelSlug    = clean($_GET['model'] ?? '');
$trimSlug     = clean($_GET['trim']  ?? '');
$catalogChain = [];
if ($catId > 0 && $brandSlug !== '' && function_exists('swaapin_catalog_node')) {
    $bNode = swaapin_catalog_node($catId, 0, $brandSlug);
    if ($bNode) {
        $catalogChain[] = $bNode;
        if ($modelSlug !== '') {
            $mNode = swaapin_catalog_node($catId, (int) $bNode['id'], $modelSlug);
            if ($mNode) {
                $catalogChain[] = $mNode;
                if ($trimSlug !== '') {
                    $tNode = swaapin_catalog_node($catId, (int) $mNode['id'], $trimSlug);
                    if ($tNode) {
                        $catalogChain[] = $tNode;
                    }
                }
            }
        }
    }
}

// فیلتر متنی مدل برای لندینگ «swap-{category}-{model}» و مقصد «with-{Y}»
$swapModelName  = '';
$swapTargetName = '';
if ($swapInfo) {
    if (!empty($swapInfo['model'])) {
        $swapModelName = function_exists('swaapin_swap_model_name')
            ? (swaapin_swap_model_name((string) $swapInfo['model'], (int) $catId) ?: (string) $swapInfo['model'])
            : (string) $swapInfo['model'];
    }
    if (!empty($swapInfo['target_category'])) {
        $tcat = DB::fetch('SELECT name FROM categories WHERE slug = ? LIMIT 1', [(string) $swapInfo['target_category']]);
        $swapTargetName = $tcat ? (string) $tcat['name'] : '';
    } elseif (!empty($swapInfo['target_location']) && function_exists('swaapin_location_by_slug')) {
        $tloc = swaapin_location_by_slug((string) $swapInfo['target_location']);
        $swapTargetName = $tloc ? (string) $tloc['name'] : '';
    }
}

// ساخت شرط‌ها
$whereClauses = [listing_public_sql('l'), 'l.listing_mode != "sell"'];
$params       = [];

if ($search) {
    $whereClauses[] = '(l.title LIKE ? OR l.description LIKE ? OR l.want_in_return LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
// فیلتر برند/مدل/تیپ (سند — بخش ۱ و ۲)
foreach ($catalogChain as $node) {
    $level = (string) ($node['level'] ?? '');
    $col   = $level === 'brand' ? 'brand_id' : ($level === 'model' ? 'model_id' : 'trim_id');
    $parts = [];
    if (db_has_column('listings', $col)) {
        $parts[] = "l.`{$col}` = ?";
        $params[] = (int) $node['id'];
    }
    if (db_has_column('listings', 'listing_attrs')) {
        $parts[] = 'l.listing_attrs LIKE ?';
        $params[] = '%"' . $level . '":"' . (string) $node['name'] . '"%';
    }
    if ($parts) {
        $whereClauses[] = '(' . implode(' OR ', $parts) . ')';
    }
}
// لندینگ معاوضه — مدل خاص (سند — بخش ۴)
if ($swapModelName !== '') {
    $whereClauses[] = '(l.title LIKE ? OR l.listing_attrs LIKE ?)';
    $params[] = "%{$swapModelName}%";
    $params[] = "%{$swapModelName}%";
}
// لندینگ معاوضه — مقصد «with-{Y}» (سند — بخش ۴)
if ($swapTargetName !== '') {
    $whereClauses[] = 'l.want_in_return LIKE ?';
    $params[] = "%{$swapTargetName}%";
}
if (!empty($catScopeIds)) {
    $placeholders = implode(',', array_fill(0, count($catScopeIds), '?'));
    $whereClauses[] = "(l.category_id IN ({$placeholders}))";
    foreach ($catScopeIds as $cid) { $params[] = (int)$cid; }
}
if ($locRow) {
    if (!empty($locCityNames)) {
        $locPlaceholders = implode(',', array_fill(0, count($locCityNames), '?'));
        $whereClauses[] = "l.city IN ({$locPlaceholders})";
        foreach ($locCityNames as $locCity) {
            $params[] = $locCity;
        }
    }
    if ($locNeighborhood !== '') {
        $whereClauses[] = 'l.neighborhood = ?';
        $params[] = $locNeighborhood;
    }
} elseif ($locMode === 'nearby' && $nearbyCitiesList !== []) {
    $cityPlaceholders = implode(',', array_fill(0, count($nearbyCitiesList), '?'));
    $whereClauses[] = "l.city IN ({$cityPlaceholders})";
    foreach ($nearbyCitiesList as $nearbyCity) {
        $params[] = $nearbyCity;
    }
} elseif ($city) {
    $provinces = iran_provinces();
    if (in_array($city, $provinces, true)) {
        $provinceCities = iran_cities_by_province($city);
        if (!empty($provinceCities)) {
            $placeholders = implode(',', array_fill(0, count($provinceCities), '?'));
            $whereClauses[] = "l.city IN ($placeholders)";
            foreach ($provinceCities as $pc) {
                $params[] = $pc;
            }
        } else {
            $whereClauses[] = 'l.city LIKE ?';
            $params[] = "%{$city}%";
        }
    } else {
        $whereClauses[] = 'l.city LIKE ?';
        $params[] = "%{$city}%";
    }
}
if ($wantType) {
    $whereClauses[] = 'l.want_type = ?';
    $params[] = $wantType;
}
if ($condition) {
    $whereClauses[] = 'l.condition = ?';
    $params[] = $condition;
}
if ($pmin > 0) {
    $whereClauses[] = 'l.estimated_value >= ?';
    $params[] = $pmin;
}
if ($pmax > 0 && $pmax >= $pmin) {
    $whereClauses[] = 'l.estimated_value <= ?';
    $params[] = $pmax;
}
if ($timeAgo) {
    $intervalMap = [
        '3h'  => '3 HOUR',
        '12h' => '12 HOUR',
        '1d'  => '1 DAY',
        '3d'  => '3 DAY',
        '1w'  => '7 DAY',
    ];
    $interval = $intervalMap[$timeAgo] ?? '3 HOUR';
    $whereClauses[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL {$interval})";
}

$where   = 'WHERE ' . implode(' AND ', $whereClauses);
$orderBy = match($sort) {
    'old'   => 'l.created_at ASC, l.id ASC',
    'value' => 'l.estimated_value DESC, l.id DESC',
    default => 'l.created_at DESC, l.id DESC',
};

// شمارش و دریافت داده
$totalRow = DB::fetch(
    "SELECT COUNT(*) AS c FROM listings l JOIN categories c ON c.id = l.category_id {$where}",
    $params
);
$total = (int)($totalRow['c'] ?? 0);
$perPage = 25;
$pag = paginate($total, $perPage, $page);

$listings = DB::fetchAll(
    "SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
            c.name AS cat_name, c.slug AS cat_slug,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     {$where}
     ORDER BY {$orderBy}
     LIMIT ? OFFSET ?",
    [...$params, $perPage, $pag['offset']]
);
listing_card_prefetch($listings);

// متادیتا — طبق قوانین سئو سواَپین 
// الگو عنوان: معاوضه [عنوان] | در [شهر] | سواَپین  (حداکثر ۶۵ کاراکتر)
// الگو توضیحات: شامل دسته، شهر، عنوان و ارزش تخمینی (حداکثر ۱۶۰ کاراکتر)
$catChainNames = [];
$catChainCrumbs = [];
if ($category) {
    $chainSlugs = get_category_ancestors($category['slug']);
    foreach ($chainSlugs as $cs) {
        $crow = DB::fetch('SELECT name FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1', [$cs]);
        if ($crow) {
            $catChainNames[]  = $crow['name'];
            $catChainCrumbs[] = ['name' => $crow['name'], 'url' => category_url($cs)];
        }
    }
}
$catDisplayName = !empty($catChainNames) ? implode(' › ', $catChainNames) : '';
// افزودن برند/مدل/تیپ به نام نمایشی و بردکرامب (سند — بخش ۱ و ۲)
$catalogBaseUrl = $locRow ? rtrim(swaapin_location_url($locRow), '/') : APP_URL;
foreach ($catalogChain as $node) {
    $catDisplayName   = ($catDisplayName !== '' ? $catDisplayName . ' › ' : '') . (string) $node['name'];
    $catalogBaseUrl  .= '/' . $node['slug'];
    $catChainCrumbs[] = ['name' => (string) $node['name'], 'url' => $catalogBaseUrl];
}

$titleParts = [];
$titleMode = 'معاوضه';
if ($swapInfo) {
    // لندینگ معاوضه — عنوان اختصاصی (سند — بخش ۴)
    $titleParts[] = $catDisplayName !== '' ? ('معاوضه ' . $catDisplayName) : 'معاوضه';
} elseif ($category) {
    $titleParts[] = $titleMode . ' ' . ($catDisplayName ?: category_label($category['slug'], $category['name']));
} else {
    $titleParts[] = $titleMode . ' کالا';
}
if ($locName !== '') {
    $titleParts[] = 'در ' . $locName;
} elseif ($city) {
    $titleParts[] = 'در ' . $city;
} elseif ($locMode === 'nearby' && !empty($nearbyCitiesList)) {
    $titleParts[] = 'در شهرهای نزدیک';
}
$title = implode(' | ', $titleParts) . ' | ' . APP_NAME;
if (mb_strlen($title) > 65) {
    $first = array_shift($titleParts);
    $title = $first . ' | ' . APP_NAME;
    if (mb_strlen($title) > 65) {
        $title = mb_strimwidth($title, 0, 63, '…') . ' | ' . APP_NAME;
    }
}
if ($search) {
    $searchTitle = 'نتایج برای «' . $search . '» | ' . APP_NAME;
    $title = mb_strlen($searchTitle) > 65 ? mb_strimwidth($searchTitle, 0, 63, '…') : $searchTitle;
}

$descParts = [];
if (!empty($catDisplayName)) {
    $descParts[] = 'دسته‌بندی: ' . $catDisplayName;
} else {
    $descParts[] = 'بازار معاوضه کالا با کالا';
}
if ($locName !== '') {
    $descParts[] = 'مکان: ' . $locName;
} elseif ($city) {
    $descParts[] = 'شهر: ' . $city;
} elseif ($locMode === 'nearby' && !empty($nearbyCitiesList)) {
    $descParts[] = 'شهرهای نزدیک: ' . implode('، ', array_slice($nearbyCitiesList, 0, 3));
}
if ($search) {
    $descParts[] = 'جستجو: ' . $search;
}
if ($swapTargetName !== '') {
    $descParts[] = 'در ازای ' . $swapTargetName;
}
$descParts[] = 'در پلتفرم سواَپین ';
$desc = implode(' - ', $descParts);
if (mb_strlen($desc) > 160) {
    $desc = mb_strimwidth($desc, 0, 158, '…');
}

// Canonical: بدون پارامترها؛ صفحه‌بندی Self-canonical (طبق سند پارامترها و صفحه‌بندی)
$categorySlugPath = trim((string)($_GET['slug_path'] ?? ''), '/');
$requestCanonicalPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if ($locRow) {
    // صفحه مکان: کانونیکال به آدرس تمیز /{location}[/{category}[/{brand}[/{model}[/{trim}]]]]
    $baseCanonical = swaapin_location_url($locRow);
    if ($category) {
        $baseCanonical .= '/' . $category['slug'];
    }
    foreach ($catalogChain as $node) {
        $baseCanonical .= '/' . $node['slug'];
    }
} elseif ($categorySlugPath !== '' && $requestCanonicalPath !== '') {
    $baseCanonical = APP_URL . rtrim($requestCanonicalPath, '/');
} else {
    $baseCanonical = APP_URL . '/iran';
}
// فیلترهای پارامتری (sort/q/min_price/city/...) → noindex,follow و کانونیکال به صفحه بدون پارامتر
$hasOtherFilters = $search || $cityFilter !== '' || $locMode === 'nearby' || $wantType || $condition
    || $pmin > 0 || $pmax > 0 || $sort !== 'new' || $timeAgo !== '';
if (!$locRow && $category && !$hasOtherFilters && empty($catalogChain)) {
    $baseCanonical = category_url($category['slug']);
}
// لندینگ معاوضه — کانونیکال اختصاصی (سند — بخش ۴)
if ($swapInfo) {
    $swapBase = $locRow ? rtrim(swaapin_location_url($locRow), '/') : APP_URL;
    $baseCanonical = $swapBase . '/list/' . $swapInfo['token'];
}
$canonical = $baseCanonical;
if (!$hasOtherFilters && $page > 1) {
    // صفحه‌بندی Self-canonical: هر صفحه به خودش اشاره می‌کند، نه به صفحه اول
    $canonical = rtrim($baseCanonical, '/') . '?page=' . $page;
}
// قاعده حداقل ۶ آگهی فعال برای ایندکس صفحات مکان/دسته/برند/مدل/لندینگ معاوضه (سند — بخش ۷)
$pageIndexable = null;
if (!$hasOtherFilters && !$swapModelName && ($locRow || $category || $swapInfo) && function_exists('seo_list_page_indexable')) {
    $pageIndexable = seo_list_page_indexable($baseCanonical, $total);
}
$robotsMeta = ($hasOtherFilters || $pageIndexable === false) ? 'noindex, follow' : null;

// بخش ۱۰: آگهی‌های جایگزین در صفحات کم‌آگهی (رندر سمت سرور؛ بدون تغییر URL/Title/H1)
$fallbackTitle    = '';
$fallbackListings = [];
if ($page === 1 && !$hasOtherFilters && $total < SWAAPIN_MIN_LISTINGS_FOR_INDEX
    && function_exists('swaapin_fallback_listings')) {
    $shownIds = array_map(static fn($l) => (int) $l['id'], $listings);
    $fb = swaapin_fallback_listings($locRow, $category, $shownIds, 8);
    $fallbackListings = $fb['rows'];
    $fallbackTitle    = $fb['title'];
}

// BreadcrumbList — تقویت درک گوگل از ساختار دسته‌بندی (کمک به Sitelinks)
$jsonLd = [];
if ($category && !$hasOtherFilters && !empty($catChainCrumbs)) {
    $jsonLd[] = seo_json_ld_breadcrumbs(array_merge(
        [['name' => 'خانه', 'url' => APP_URL . '/']],
        $catChainCrumbs
    ));
}

render_head($title, $desc, [
  'canonical' => $canonical,
  'og_type'   => 'website',
  'og_image'  => APP_URL . '/src/img/heropng.png',
  'json_ld'   => $jsonLd,
  'robots'    => $robotsMeta,
  'body_class' => 'page-listings',
]);
render_navbar($user);
?>

<!-- Filter Button (Mobile & Tablet Only) -->
<div class="container mb-4 d-block d-lg-none">
  <button type="button" id="open-filter-modal" style="margin-top: 25px;" class="btn btn-primary w-100">
    <i class="bi bi-funnel"></i> فیلترها
  </button>
</div>

<main class="section-sm">
  <div class="container all-listings-layout">
    <!-- Filter Modal Overlay (Mobile Only) -->
    <div id="filter-modal-overlay" class="filter-modal-overlay"></div>

    <!-- Sidebar / Filter Modal -->
    <aside aria-label="فیلترها" class="all-listings-sidebar" id="filter-modal" style="width: 85%;">
      <!-- Close Button (Mobile Only) -->
      <div class="filter-modal-header d-flex justify-between align-center d-lg-none mb-4">
        <h2>فیلترها</h2>
        <button type="button" id="close-filter-modal" class="btn btn-ghost">   
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <div class="card all-listings-filter-card">
        <!-- جستجو -->
        <div class="mb-5">
          <label class="fs-xs" for="q">جستجو در آگهی‌ها</label>
          <form method="GET" action="<?= APP_URL ?>/iran" class="d-flex gap-2 align-center">
            <input type="hidden" name="cat" value="<?= h($catSlug) ?>">
            <input type="hidden" name="city" value="<?= h($city) ?>">
            <input type="hidden" name="loc" value="<?= h($locMode) ?>">
            <input type="hidden" name="nearby_cities" value="<?= h($nearbyCitiesRaw) ?>">
            <input type="hidden" name="want" value="<?= h($wantType) ?>">
            <input type="hidden" name="condition" value="<?= h($condition) ?>">
            <input type="hidden" name="price_min" value="<?= $pmin > 0 ? (int)$pmin : '' ?>">
            <input type="hidden" name="price_max" value="<?= $pmax > 0 ? (int)$pmax : '' ?>">
            <input type="hidden" name="sort" value="<?= h($sort) ?>">
            <input type="hidden" name="time_ago" value="<?= h($timeAgo) ?>">
            <div class="flex-1 position-relative">
              <input type="search" id="q" name="q" class="form-control" value="<?= h($search) ?>" placeholder="جستجوی کالا..." dir="rtl">
            </div>
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-search"></i> جستجو
            </button>
          </form>
        </div>

        <?php
        // لینک‌ساز جستجو با حفظ فیلترهای فعلی
        $buildSearchLink = function (string $kw) use ($catSlug, $city, $locMode, $nearbyCitiesRaw, $wantType, $condition, $pmin, $pmax, $sort, $timeAgo): string {
            $qs = http_build_query(array_filter([
                'q'              => $kw,
                'cat'            => $catSlug !== '' ? $catSlug : null,
                'city'           => $city !== '' ? $city : null,
                'loc'            => $locMode === 'nearby' ? 'nearby' : null,
                'nearby_cities'  => $locMode === 'nearby' && $nearbyCitiesRaw !== '' ? $nearbyCitiesRaw : null,
                'want'           => $wantType !== '' ? $wantType : null,
                'condition'      => $condition !== '' ? $condition : null,
                'price_min'      => $pmin > 0 ? $pmin : null,
                'price_max'      => $pmax > 0 ? $pmax : null,
                'sort'           => $sort !== 'new' ? $sort : null,
                'time_ago'       => $timeAgo !== '' ? $timeAgo : null,
            ]));
            return APP_URL . '/iran?' . $qs;
        };

        if ($category):
            // جستجوی هوشمند: کلیدواژه‌های پیشنهادی هوش مصنوعی بر اساس آگهی‌های همین دسته
            aick_render_sidebar($category, APP_URL . '/iran', [
                'city'          => $city,
                'loc'           => $locMode === 'nearby' ? 'nearby' : null,
                'nearby_cities' => $locMode === 'nearby' ? $nearbyCitiesRaw : null,
                'want'          => $wantType,
                'condition'     => $condition,
                'price_min'     => $pmin > 0 ? $pmin : null,
                'price_max'     => $pmax > 0 ? $pmax : null,
                'sort'          => $sort !== 'new' ? $sort : null,
                'time_ago'      => $timeAgo,
            ]);
        else:
            // حالت بدون دسته: جستجوهای مرتبط آماده
            $relatedKeywords = [
                'موبایل', 'سامسونگ', 'آیفون', 'لپ تاپ', 'پلی استیشن',
                'تبلت', 'ساعت هوشمند', 'هدفون', 'دوربین', 'دوچرخه',
                'کنسول بازی', 'تلویزیون', 'یخچال', 'مبل', 'فرش',
            ];
            $relatedKeywords = array_values(array_unique($relatedKeywords));
        ?>
        <div class="related-searches">
          <h3 class="all-listings-sidebar__subtitle" style="margin-top:0"><i class="bi bi-search-heart"></i> جستجوهای مرتبط</h3>
          <div class="related-searches__chips">
            <?php foreach ($relatedKeywords as $kw): ?>
            <a href="<?= h($buildSearchLink($kw)) ?>" class="filter-chip"><?= h($kw) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <h2 class="all-listings-sidebar__title">دسته‌بندی‌ها</h2>
        <?php
        $allTopCats = DB::fetchAll('SELECT * FROM categories WHERE (parent_id IS NULL OR parent_id = 0) AND is_active = 1 ORDER BY sort_order');
        $activeChainIds = [];
        if ($catId > 0) {
            $activeChainIds = swaapin_category_descendant_ids($catId);
            $activeChainIds[] = $catId;
            $up = $catId;
            $s = 15;
            while ($up > 0 && $s-- > 0) {
                $parent = DB::fetch('SELECT id, parent_id FROM categories WHERE id = ? LIMIT 1', [$up]);
                if (!$parent) break;
                $activeChainIds[] = (int)$parent['id'];
                $pid = (int)($parent['parent_id'] ?? 0);
                if ($pid <= 0) break;
                $up = $pid;
            }
        }
        $buildFilterLink = function (string $slug) use ($catSlug, $search, $city, $cityFilter, $locMode, $nearbyCitiesRaw, $wantType, $condition, $pmin, $pmax, $sort, $timeAgo): string {
            $baseCatUrl = category_url($slug);
            $hasOtherFilters = $search || $cityFilter !== '' || $locMode === 'nearby' || $wantType || $condition || $pmin > 0 || $pmax > 0 || $sort !== 'new' || $timeAgo !== '';
            if ($hasOtherFilters) {
                $qs = http_build_query(array_filter([
                    'cat' => $slug,
                    'q' => $search !== '' ? $search : null,
                    'city' => $city !== '' ? $city : null,
                    'loc' => $locMode === 'nearby' ? 'nearby' : null,
                    'nearby_cities' => $locMode === 'nearby' && $nearbyCitiesRaw !== '' ? $nearbyCitiesRaw : null,
                    'want' => $wantType !== '' ? $wantType : null,
                    'condition' => $condition !== '' ? $condition : null,
                    'price_min' => $pmin > 0 ? $pmin : null,
                    'price_max' => $pmax > 0 ? $pmax : null,
                    'sort' => $sort !== 'new' ? $sort : null,
                    'time_ago' => $timeAgo !== '' ? $timeAgo : null,
                ]));
                return APP_URL . '/iran?' . $qs;
            }
            return $baseCatUrl;
        };
        ?>
        <div class="all-listings-categories cat-tree">
          <div class="cat-tree__item cat-tree__item--root">
            <a href="<?= APP_URL ?>/iran" class="cat-tree__link <?= $catSlug === '' ? 'is-active' : '' ?>"><i class="bi bi-grid"></i> همه دسته‌بندی‌ها</a>
          </div>
          <?php foreach ($allTopCats as $tc):
              $tcId = (int)$tc['id'];
              $tcSlug = (string)$tc['slug'];
              $topActive = $catSlug === $tcSlug;
              $hasActiveInside = in_array($tcId, $activeChainIds, true) || $topActive;
              $l2 = DB::fetchAll('SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, id', [$tcId]);
              $class = 'cat-tree__item' . ($hasActiveInside ? ' is-open' : '');
          ?>
          <div class="<?= $class ?>">
            <a href="<?= $buildFilterLink($tcSlug) ?>" class="cat-tree__link cat-tree__link--parent <?= $topActive ? 'is-active' : '' ?>">
              <?php if (!empty($l2)): ?><span class="cat-tree__toggle"><i class="bi bi-chevron-left"></i></span><?php endif; ?>
              <i class="<?= h($tc['icon']) ?>"></i> <?= h(category_label($tcSlug, $tc['name'])) ?>
            </a>
            <?php if (!empty($l2)): ?>
            <div class="cat-tree__sub">
              <?php foreach ($l2 as $lc):
                  $lcId = (int)$lc['id'];
                  $lcActive = ($catSlug === (string)$lc['slug']) || in_array($lcId, $activeChainIds, true);
                  $l3 = DB::fetchAll('SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, id', [$lcId]);
                  $class2 = 'cat-tree__item' . ($lcActive ? ' is-open' : '');
              ?>
              <div class="<?= $class2 ?>">
                <a href="<?= $buildFilterLink((string)$lc['slug']) ?>" class="cat-tree__link cat-tree__link--l2 <?= ($catSlug === (string)$lc['slug']) ? 'is-active' : '' ?>">
                  <?php if (!empty($l3)): ?><span class="cat-tree__toggle"><i class="bi bi-chevron-left"></i></span><?php endif; ?>
                  <?= h(category_label((string)$lc['slug'], $lc['name'])) ?>
                </a>
                <?php if (!empty($l3)): ?>
                <div class="cat-tree__sub">
                  <?php foreach ($l3 as $gc): ?>
                  <div class="cat-tree__item">
                    <a href="<?= $buildFilterLink((string)$gc['slug']) ?>" class="cat-tree__link cat-tree__link--l3 <?= ($catSlug === (string)$gc['slug']) ? 'is-active' : '' ?>">
                      <?= h(category_label((string)$gc['slug'], $gc['name'])) ?>
                    </a>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <h3 class="all-listings-sidebar__subtitle">فیلترهای دیگر</h3>
        <form method="GET" action="<?= APP_URL ?>/iran" class="all-listings-filters" id="all-listings-filters">
          <input type="hidden" name="cat" value="<?= h($catSlug) ?>">
          <?php if ($search): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?>
          <input type="hidden" name="nearby_cities" id="nearby-cities" value="<?= h($nearbyCitiesRaw) ?>">
          <input type="hidden" name="time_ago" value="<?= h($timeAgo) ?>">

          <label class="fs-xs" for="loc-mode">مکان</label>
          <select id="loc-mode" name="loc" class="form-control">
            <option value="" <?= $locMode !== 'nearby' ? 'selected' : '' ?>>همه شهرها</option>
            <option value="nearby" <?= $locMode === 'nearby' ? 'selected' : '' ?>>شهرهای اطراف من 📍</option>
          </select>
          <div id="loc-filter-alert" class="alert alert-warning mt-2" role="alert" hidden></div>
          <?php if ($locMode === 'nearby' && $nearbyCitiesList !== []): ?>
          <p class="fs-xs text-muted mt-2 mb-0">
            <i class="bi bi-geo-alt"></i>
            نمایش آگهی‌های: <?= h(implode('، ', array_slice($nearbyCitiesList, 0, 5))) ?><?= count($nearbyCitiesList) > 5 ? ' و ...' : '' ?>
          </p>
          <?php endif; ?>

          <label class="fs-xs" for="city">شهر</label>
          <select id="city" name="city" class="form-control" <?= $locMode === 'nearby' ? 'disabled' : '' ?>>
            <option value="">همه شهرها</option>
            <?= render_city_options($city) ?>
          </select>

          <label class="fs-xs" for="want">نوع معامله</label>
          <select id="want" name="want" class="form-control">
            <option value="">همه</option>
            <option value="item"    <?= $wantType === 'item' ? 'selected' : '' ?>>کالا با کالا</option>
            <option value="service" <?= $wantType === 'service' ? 'selected' : '' ?>>خدمات</option>
            <option value="credit"  <?= $wantType === 'credit' ? 'selected' : '' ?>>اعتبار</option>
          </select>

          <div class="mb-4" style="margin-top: 10px;">
            <div class="filter-chip-group">
              <span class="filter-chip-group__label"><i class="bi bi-clock"></i> زمان انتشار:</span>
              <button type="button" class="filter-chip <?= $timeAgo === '' ? 'is-active' : '' ?>" data-filter="time_ago" data-value="">همه</button>
              <button type="button" class="filter-chip <?= $timeAgo === '3h' ? 'is-active' : '' ?>" data-filter="time_ago" data-value="3h">۳ ساعت</button>
              <button type="button" class="filter-chip <?= $timeAgo === '12h' ? 'is-active' : '' ?>" data-filter="time_ago" data-value="12h">۱۲ ساعت</button>
              <button type="button" class="filter-chip <?= $timeAgo === '1d' ? 'is-active' : '' ?>" data-filter="time_ago" data-value="1d">۱ روز</button>
              <button type="button" class="filter-chip <?= $timeAgo === '3d' ? 'is-active' : '' ?>" data-filter="time_ago" data-value="3d">۳ روز</button>
              <button type="button" class="filter-chip <?= $timeAgo === '1w' ? 'is-active' : '' ?>" data-filter="time_ago" data-value="1w">یک هفته</button>
            </div>
          </div>

          <label class="fs-xs" for="condition">وضعیت کالا</label>
          <select id="condition" name="condition" class="form-control">
            <option value="">همه</option>
            <?php foreach (['new','like_new','good','fair','poor'] as $c): ?>
            <option value="<?= h($c) ?>" <?= $condition === $c ? 'selected' : '' ?>><?= h(condition_label($c)) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="all-listings-price-grid">
            <div>
              <label class="fs-xs" for="price_min">حداقل قیمت</label>
              <input type="number" id="price_min" name="price_min" class="form-control" value="<?= $pmin > 0 ? (int)$pmin : '' ?>" min="0" step="1000" inputmode="numeric">
            </div>
            <div>
              <label class="fs-xs" for="price_max">حداکثر قیمت</label>
              <input type="number" id="price_max" name="price_max" class="form-control" value="<?= $pmax > 0 ? (int)$pmax : '' ?>" min="0" step="1000" inputmode="numeric">
            </div>
          </div>

          <div>
            <label class="fs-xs" for="sort">مرتب‌سازی</label>
            <select id="sort" name="sort" class="form-control">
              <option value="new"   <?= $sort === 'new'   ? 'selected' : '' ?>>جدیدترین</option>
              <option value="old"   <?= $sort === 'old'   ? 'selected' : '' ?>>قدیمی‌ترین</option>
              <option value="value" <?= $sort === 'value' ? 'selected' : '' ?>>بالاترین ارزش</option>
            </select>
          </div>

          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-funnel"></i> اعمال فیلتر
          </button>
        </form>
      </div>
    </aside>

    <!-- Results -->
    <section aria-label="همه آگهی‌ها" class="all-listings-results">
      <header class="all-listings-results__header d-flex align-center mb-5">
        <h1 class="all-listings-results__title">
          <?= h($title) ?>
        </h1>
        <span class="badge badge-primary"><?= fmt_num($total) ?> آگهی</span>
      </header>

      <?php if ($swapInfo): ?>
      <p class="all-listings-results__intro text-muted mb-5">
        آگهی‌های معاوضه<?= $catDisplayName !== '' ? ' ' . h($catDisplayName) : '' ?><?= $locName !== '' ? ' در ' . h($locName) : '' ?><?= $swapTargetName !== '' ? ' در ازای ' . h($swapTargetName) : '' ?>.
      </p>
      <?php endif; ?>

      <?php
      // لینک متقابل صفحه دسته‌بندی و لندینگ معاوضه (سند — بخش ۴)
      $crossLink = null;
      $crossBase = $locRow ? rtrim(swaapin_location_url($locRow), '/') : APP_URL;
      if ($swapInfo && $category && empty($catalogChain)) {
          $crossLink = ['url' => $crossBase . '/' . $category['slug'], 'label' => 'مشاهده همه آگهی‌های ' . ($catDisplayName ?: $category['name'])];
      } elseif (!$swapInfo && $category && !$hasOtherFilters) {
          $crossLink = ['url' => $crossBase . '/list/swap-' . $category['slug'], 'label' => 'آگهی‌های معاوضه ' . ($catDisplayName ?: $category['name'])];
      }
      ?>
      <?php if ($crossLink): ?>
      <p class="mb-5"><a href="<?= h($crossLink['url']) ?>" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left-right"></i> <?= h($crossLink['label']) ?></a></p>
      <?php endif; ?>

      <?php if (empty($listings)): ?>
      <div class="empty-state">
        <i class="bi bi-search"></i>
        <h3>آگهی‌ای یافت نشد</h3>
        <p>فیلترها را تغییر دهید یا اولین نفری باشید که آگهی ثبت می‌کند!</p>
        <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary">ثبت آگهی</a>
      </div>
      <?php else: ?>
      <div class="all-listings-grid">
        <?php foreach ($listings as $l): ?>
        <div class="all-page-listing-card">
          <?php include __DIR__ . '/../includes/listing_card.php'; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($fallbackListings)): ?>
      <!-- بخش ۱۰: آگهی‌های جایگزین (سایر مناطق/دسته‌های نزدیک) -->
      <section class="fallback-listings mt-5" aria-label="آگهی‌های جایگزین">
        <h2 class="all-listings-sidebar__title"><?= h($fallbackTitle !== '' ? $fallbackTitle : 'آگهی‌های مشابه') ?></h2>
        <p class="fs-xs text-muted mb-4">این آگهی‌ها در منطقه یا دسته‌بندی نزدیک شما ثبت شده‌اند.</p>
        <div class="all-listings-grid">
          <?php foreach ($fallbackListings as $l): ?>
          <div class="all-page-listing-card">
            <?php include __DIR__ . '/../includes/listing_card.php'; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($pag['pages'] > 1): ?>
      <nav class="pagination" aria-label="صفحه‌بندی">
        <?php
        $currentPage = $pag['page'];
        $totalPages  = $pag['pages'];

        $qs = $_GET;

        $buildUrl = function($p) use ($qs, $categorySlugPath, $requestCanonicalPath, $hasOtherFilters, $locRow, $category, $catalogChain, $swapInfo) {
            // لندینگ معاوضه: صفحه‌بندی روی /[location/]list/swap...
            if ($swapInfo) {
                $swapBase = $locRow ? rtrim(swaapin_location_url($locRow), '/') : APP_URL;
                return $swapBase . '/list/' . $swapInfo['token'] . '?page=' . $p;
            }
            // صفحه مکان/دسته/برند/مدل: صفحه‌بندی روی آدرس تمیز
            if ($locRow) {
                $locBase = swaapin_location_url($locRow) . ($category ? '/' . $category['slug'] : '');
                foreach ($catalogChain as $node) {
                    $locBase .= '/' . $node['slug'];
                }
                return $locBase . '?page=' . $p;
            }
            // صفحه‌بندی روی مسیر تمیز دسته (Self-canonical) → بدون پارامترهای اضافی
            if ($categorySlugPath !== '' && $requestCanonicalPath !== '' && !$hasOtherFilters) {
                return APP_URL . rtrim($requestCanonicalPath, '/') . '?page=' . $p;
            }
            $qs['page'] = $p;
            unset($qs['slug_path']);
            return APP_URL . '/iran?' . http_build_query($qs);
        };

        $isFirst = $currentPage === 1;
        $isLast  = $currentPage === $totalPages;
        ?>

        <a href="<?= $isFirst ? '#' : h($buildUrl($currentPage - 1)) ?>"
           class="page-link page-link__nav <?= $isFirst ? 'is-disabled' : '' ?>"
           aria-label="صفحه قبلی"
           <?= $isFirst ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
          <i class="bi bi-chevron-right"></i>
        </a>

        <?php
        $range = 2;
        $start = max(1, $currentPage - $range);
        $end   = min($totalPages, $currentPage + $range);

        if ($start > 1) {
            $cls1 = 1 === $currentPage ? 'active' : '';
            echo '<a href="' . h($buildUrl(1)) . '" class="page-link ' . $cls1 . '">' . fmt_num(1) . '</a>';
            if ($start > 2) {
                echo '<span class="pagination__ellipsis">…</span>';
            }
        }

        for ($p = $start; $p <= $end; $p++):
            $href = h($buildUrl($p));
            $cls  = $p === $currentPage ? 'active' : '';
        ?>
          <a href="<?= $href ?>" class="page-link <?= $cls ?>" <?= $p === $currentPage ? 'aria-current="page"' : '' ?>><?= fmt_num($p) ?></a>
        <?php endfor;

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                echo '<span class="pagination__ellipsis">…</span>';
            }
            $clsN = $totalPages === $currentPage ? 'active' : '';
            echo '<a href="' . h($buildUrl($totalPages)) . '" class="page-link ' . $clsN . '">' . fmt_num($totalPages) . '</a>';
        }
        ?>

        <a href="<?= $isLast ? '#' : h($buildUrl($currentPage + 1)) ?>"
           class="page-link page-link__nav <?= $isLast ? 'is-disabled' : '' ?>"
           aria-label="صفحه بعدی"
           <?= $isLast ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
          <i class="bi bi-chevron-left"></i>
        </a>
      </nav>
      <?php endif; ?>
    </section>
  </div>
</main>

<?php render_footer(); ?>
