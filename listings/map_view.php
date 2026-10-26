<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/ai_category_keywords.php';

$user = auth_user();

// فیلترها
$search    = clean($_GET['q']          ?? '');
$catSlug   = clean($_GET['cat']        ?? '');
$city      = clean($_GET['city']       ?? '') ?: 'تهران';
$wantType  = clean($_GET['want']       ?? '');
$sort      = in_array($_GET['sort'] ?? '', ['new','old','value']) ? $_GET['sort'] : 'new';
$page      = max(1, (int)($_GET['page'] ?? 1));
$timeAgo   = in_array($_GET['time_ago'] ?? '', ['3h','12h','1d','3d','1w']) ? $_GET['time_ago'] : '';
$pmin      = isset($_GET['price_min']) ? (float)$_GET['price_min'] : 0;
$pmax      = isset($_GET['price_max']) ? (float)$_GET['price_max'] : 0;
$neighborhood = clean($_GET['neighborhood'] ?? '');

// دسته‌بندی
$category = $catSlug ? DB::fetch('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [$catSlug]) : null;
$catId    = $category ? (int)$category['id'] : null;
$catScopeIds = [];
if ($catId > 0) {
    $catScopeIds = swaapin_category_descendant_ids($catId);
    $catScopeIds[] = $catId;
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
if (!empty($catScopeIds)) {
    $placeholders = implode(',', array_fill(0, count($catScopeIds), '?'));
    $whereClauses[] = "(l.category_id IN ({$placeholders}))";
    foreach ($catScopeIds as $cid) { $params[] = (int)$cid; }
}
if ($city) {
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
if ($pmin > 0) {
    $whereClauses[] = 'l.estimated_value >= ?';
    $params[] = $pmin;
}
if ($pmax > 0) {
    $whereClauses[] = 'l.estimated_value <= ?';
    $params[] = $pmax;
}
if ($neighborhood) {
    $whereClauses[] = 'l.neighborhood = ?';
    $params[] = $neighborhood;
}

$where   = 'WHERE ' . implode(' AND ', $whereClauses);

// PHP 7.4 compatible orderBy
$orderBy = '(l.vip_until > NOW()) DESC, (l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC, l.id DESC';
if ($sort === 'old') {
    $orderBy = 'l.created_at ASC, l.id ASC';
} elseif ($sort === 'value') {
    $orderBy = 'l.estimated_value DESC, l.id DESC';
}

// شمارش و دریافت داده
$totalRow = DB::fetch(
    "SELECT COUNT(*) AS c FROM listings l JOIN categories c ON c.id = l.category_id {$where}",
    $params
);
$total = (int)($totalRow['c'] ?? 0);
$perPage = 6;
$pag = paginate($total, $perPage, $page);

// PHP 7.4 compatible array merging
$queryListingsParams = array_merge($params, [$perPage, $pag['offset']]);

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
    $queryListingsParams
);

// دریافت تمامی آگهی‌ها برای نقشه (فقط مختصات)
$mapListings = DB::fetchAll(
    "SELECT l.id, l.title, l.latitude as lat, l.longitude as lng, l.estimated_value,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN categories c ON c.id = l.category_id
     {$where}
     AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL",
    $params
);
foreach ($mapListings as &$ml) {
    $ml['url'] = listing_url($ml);
}
unset($ml);

$title = ($category ? category_label($category['slug'], $category['name']) : 'آگهی‌ها') . ' | ' . APP_NAME;
$desc = "مشاهده آگهی‌های معاوضه در دسته " . ($category ? $category['name'] : 'همه') . " در شهر " . $city;

render_head($title, $desc, [
    'og_type' => 'website',
    'body_class' => 'page-listings',
]);
render_navbar($user);

// دریافت زیر‌دسته‌ها برای نمایش در سایدبار
$subCategories = [];
if ($category) {
    $subCategories = DB::fetchAll('SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order', [$catId]);
}
?>

<style>
    body {
        background: #f8f9fa;
        color: #333;
    }
    .map-view-layout {
        display: flex;
        height: calc(100vh - 70px);
        background: #f8f9fa;
        overflow: hidden;
    }

    .map-view-map-container {
        flex: 1;
        position: relative;
        height: 100%;
    }

    #map {
        height: 100%;
        width: 100%;
        z-index: 1;
    }

    .map-view-middle {
        width: 450px;
        height: 100%;
        overflow-y: auto;
        border-right: 1px solid #dee2e6;
        border-left: 1px solid #dee2e6;
        background: #fff;
        display: flex;
        flex-direction: column;
    }

    .map-view-sidebar {
        width: 300px;
        height: 100%;
        overflow-y: auto;
        padding: 20px;
        background: #fff;
    }

    .map-view-header {
        padding: 20px;
        border-bottom: 1px solid #dee2e6;
    }

    .map-view-listings-content {
        flex: 1;
        padding: 20px;
    }

    .listing-card-single {
        background: #fff;
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 15px;
        display: flex;
        gap: 15px;
        border: 1px solid #dee2e6;
        text-decoration: none;
        color: inherit;
        transition: all 0.2s;
    }

    .listing-card-single:hover {
        border-color: var(--accent);
        background: #f8f9fa;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    .listing-card-single__thumb {
        width: 120px;
        height: 120px;
        border-radius: 8px;
        object-fit: cover;
    }

    .listing-card-single__info {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .listing-card-single__title {
        font-size: 0.95rem;
        line-height: 1.4;
        font-weight: 500;
        margin-bottom: 8px;
        color: #333;
    }

    .listing-card-single__price {
        color: var(--accent);
        font-weight: bold;
        font-size: 1.1rem;
    }

    .listing-card-single__location {
        font-size: 0.8rem;
        color: #777;
        margin-top: 5px;
    }

    .map-view-sidebar-section {
        margin-bottom: 25px;
    }

    .map-view-sidebar-title {
        font-size: 0.9rem;
        font-weight: bold;
        margin-bottom: 15px;
        color: #555;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .map-view-subcats {
        list-style: none;
        padding: 0;
        margin: 10px 0 20px 0;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .map-view-subcats li a {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        color: #444;
        text-decoration: none;
        font-size: 0.88rem;
        transition: all 0.2s ease;
        border-radius: 10px;
        background: #f8f9fa;
        border: 1px solid transparent;
    }

    .map-view-subcats li a:hover {
        color: var(--accent);
        background: #fff;
        border-color: #eee;
        transform: translateX(-5px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .map-view-subcats li a::before {
        content: "\F285"; /* bi-chevron-left */
        font-family: "bootstrap-icons";
        margin-left: 10px;
        font-size: 0.75rem;
        opacity: 0.5;
    }

    /* Mobile Toggle Styling */
    .home-sidebar__mobile-toggle {
        display: none; /* Hidden by default on desktop */
        width: 100%;
        padding: 12px 20px;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 12px;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .home-sidebar__mobile-toggle:hover {
        border-color: var(--accent);
        background: #fcfcfc;
    }

    .home-sidebar__mobile-toggle-icon {
        background: rgba(var(--accent-rgb), 0.1);
        color: var(--accent);
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-size: 1.2rem;
    }

    .home-sidebar__mobile-toggle-label {
        flex: 1;
        margin-right: 12px;
        font-weight: 700;
        font-size: 0.95rem;
        color: #333;
        text-align: right;
    }

    .home-sidebar__mobile-toggle-caret {
        transition: transform 0.3s ease;
        color: #999;
    }

    @media (max-width: 991px) {
        .home-sidebar__mobile-toggle {
            display: flex;
        }
        
        .home-sidebar--mobile-collapsible .home-sidebar__inner--collapsible {
            display: none;
            padding-top: 10px;
        }

        .home-sidebar--mobile-collapsible.is-open .home-sidebar__inner--collapsible {
            display: block;
        }

        .home-sidebar--mobile-collapsible.is-open .home-sidebar__mobile-toggle-caret {
            transform: rotate(180deg);
        }
    }

    .map-view-close-btn {
        position: absolute;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 1000;
        background: #e91e63;
        color: #fff;
        border: none;
        padding: 12px 25px;
        border-radius: 50px;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 15px rgba(233, 30, 99, 0.4);
        cursor: pointer;
    }

    .map-view-pagination {
        margin-top: 20px;
        display: flex;
        justify-content: center;
        gap: 10px;
        padding-bottom: 20px;
    }

    .map-view-pagination a {
        padding: 8px 12px;
        background: #eee;
        border-radius: 6px;
        color: #333;
        text-decoration: none;
        font-size: 0.9rem;
    }

    .map-view-pagination a.active {
        background: var(--accent);
        color: #fff;
    }

    /* Map Styles */
    .leaflet-container { background: #f0f0f0 !important; }
    
    .map-marker-popup {
        color: #333;
        min-width: 150px;
    }
    .map-marker-popup img {
        width: 100%;
        border-radius: 4px;
        margin-bottom: 5px;
    }
    
    /* Override sidebar_home styles for light theme */
    .home-sidebar-cats__label, .home-sidebar-field__label, .home-sidebar-time h4 {
        color: #333 !important;
    }
    .home-sidebar-cats__item {
        color: #555 !important;
    }
    .home-sidebar-cats__item:hover, .home-sidebar-cats__item.is-active {
        background: #f0f0f0 !important;
        color: var(--accent) !important;
    }
    .form-control {
        background: #fff !important;
        color: #333 !important;
        border: 1px solid #dee2e6 !important;
    }

    /* ══════════════════════════════════════════════════════════════
       موبایل: نقشه تمام‌صفحه + شیت پایینی آگهی‌ها + کشوی فیلترها
       ══════════════════════════════════════════════════════════════ */
    .map-view-filters-btn,
    .map-view-overlay,
    .map-view-drawer-head,
    .map-view-header__hint {
        display: none;
    }

    @media (max-width: 991px) {
        body.page-listings { overflow: hidden; }

        .map-view-layout {
            display: block;
            position: relative;
            height: calc(100vh - 140px); /* با JS دقیق‌تر تنظیم می‌شود */
            overflow: hidden;
        }

        /* نقشه تمام‌صفحه؛ اولین چیزی که کاربر می‌بیند */
        .map-view-map-container {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            flex: none;
        }

        .map-view-close-btn {
            top: 14px;
            left: 14px;
            bottom: auto;
            transform: none;
            padding: 9px 15px;
            font-size: 0.8rem;
            background: #fff;
            color: #071A33;
            box-shadow: 0 4px 14px rgba(7, 26, 51, 0.18);
        }

        .map-view-filters-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 500;
            padding: 9px 15px;
            border: none;
            border-radius: 50px;
            background: #fff;
            color: #071A33;
            font-size: 0.8rem;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(7, 26, 51, 0.18);
            cursor: pointer;
        }

        /* سایدبار → کشوی راست (مخفی تا زمان کلیک) */
        .map-view-sidebar {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 87%;
            max-width: 360px;
            height: 100%;
            z-index: 2100;
            padding: 0 18px calc(26px + env(safe-area-inset-bottom, 0px));
            background: #fff;
            box-shadow: -10px 0 34px rgba(7, 26, 51, 0.2);
            transform: translateX(101%);
            transition: transform 0.3s ease;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .map-view-sidebar.is-open { transform: translateX(0); }

        .map-view-drawer-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 2;
            margin: 0 -18px 16px;
            padding: 14px 18px;
            background: #fff;
            border-bottom: 1px solid #eef1f4;
        }

        .map-view-drawer-head__title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #071A33;
        }

        .map-view-drawer-close {
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 10px;
            background: #f4f6f8;
            color: #071A33;
            font-size: 1rem;
            cursor: pointer;
        }

        .map-view-overlay {
            display: block;
            position: fixed;
            inset: 0;
            z-index: 2050;
            background: rgba(7, 26, 51, 0.45);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .map-view-overlay.is-open { opacity: 1; visibility: visible; }

        /* سایدبار هوم داخل کشو: تمام‌عرض و همیشه باز */
        .map-view-sidebar .home-sidebar { width: 100% !important; }
        .map-view-sidebar .home-sidebar__inner { width: 100%; }
        .map-view-sidebar .home-sidebar__mobile-toggle { display: none !important; }
        .map-view-sidebar .home-sidebar__inner--collapsible {
            display: block !important;
            padding-top: 0;
        }

        /* لیست آگهی‌ها → شیت پایینی */
        .map-view-middle {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 70vh;
            overflow: hidden;
            border: 1px solid #e9ecef;
            border-bottom: none;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 -10px 34px rgba(7, 26, 51, 0.16);
            z-index: 1200;
            transform: translateY(calc(100% - 100px));
            transition: transform 0.34s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform;
        }

        .map-view-middle.is-expanded { transform: translateY(0); }
        .map-view-middle.is-dragging { transition: none; }

        .map-view-header {
            position: relative;
            flex-shrink: 0;
            padding: 18px 20px 12px;
            cursor: grab;
            user-select: none;
            touch-action: none;
        }

        .map-view-header::before {
            content: "";
            position: absolute;
            top: 7px;
            left: 50%;
            transform: translateX(-50%);
            width: 46px;
            height: 4px;
            border-radius: 999px;
            background: #d5dae0;
        }

        .map-view-header h1 { font-size: 1rem; }

        .map-view-header__hint {
            display: block;
            margin-top: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--accent);
        }

        .map-view-listings-content {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 14px 16px 26px;
        }

        .listing-card-single { padding: 10px; gap: 12px; }
        .listing-card-single__thumb { width: 88px; height: 88px; }
        .listing-card-single__title { font-size: 0.88rem; margin-bottom: 5px; }
        .listing-card-single__price { font-size: 0.98rem; }
    }

    @media (max-width: 991px) and (max-height: 640px) {
        .map-view-middle { height: 86vh; }
    }
</style>

<div class="map-view-layout">
    <!-- Right: Sidebar (First in HTML for RTL Right position) -->
    <div class="map-view-sidebar" id="map-view-sidebar">
        <div class="map-view-drawer-head">
            <span class="map-view-drawer-head__title">فیلترها و دسته‌بندی‌ها</span>
            <button type="button" class="map-view-drawer-close" id="map-view-drawer-close" aria-label="بستن">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="map-view-sidebar-section">
            <div class="map-view-sidebar-title">دسته‌ها</div>
            <div class="mb-3">
                <a href="<?= APP_URL ?>/listings/map" class="text-dark text-decoration-none fs-sm">
                    <i class="bi bi-arrow-right"></i> همه‌ی آگهی‌ها
                </a>
            </div>
            
            <?php if ($category): ?>
                <!-- <div class="mb-2 d-flex align-center gap-2">
                    <i class="bi bi-house text-accent"></i>
                    <span class="fw-bold text-dark"><?= h($category['name']) ?></span>
                </div>
                <ul class="map-view-subcats ms-3" >
                    <?php foreach ($subCategories as $sub): ?>
                        <li><a href="?<?= http_build_query(array_merge($_GET, ['cat' => $sub['slug'], 'page' => 1])) ?>"><?= h($sub['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul> -->
            <?php else: ?>
                <!-- نمایش دسته‌های اصلی اگر دسته‌ای انتخاب نشده -->
                <?php 
                $topCats = DB::fetchAll('SELECT * FROM categories WHERE (parent_id IS NULL OR parent_id = 0) AND is_active = 1 ORDER BY sort_order LIMIT 10');
                foreach ($topCats as $tc): ?>
                    <div class="mb-2">
                        <a href="?<?= http_build_query(['cat' => $tc['slug']]) ?>" class="text-dark text-decoration-none fs-sm">
                            <i class="bi <?= h($tc['icon'] ?: 'bi-grid') ?> me-2"></i> <?= h($tc['name']) ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($category): ?>
            <?php
            aick_render_sidebar($category, APP_URL . '/listings/map', [
                'city'         => $city,
                'want'         => $wantType,
                'sort'         => $sort !== 'new' ? $sort : null,
                'time_ago'     => $timeAgo,
                'price_min'    => $pmin > 0 ? $pmin : null,
                'price_max'    => $pmax > 0 ? $pmax : null,
                'neighborhood' => $neighborhood,
            ]);
            ?>
        <?php endif; ?>

        <div class="map-view-sidebar-section">
            <div class="map-view-sidebar-title">محله</div>
            <select class="form-control bg-white text-dark border-secondary fs-sm" 
                    onchange="location.href='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)), neighborhood: this.value, page: 1}).toString()">
                <option value="">انتخاب محله</option>
                <?php foreach (iran_city_neighborhood_names($city) as $n): ?>
                    <option value="<?= h($n) ?>" <?= $neighborhood === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <hr class="border-light my-4">

        <?php include __DIR__ . '/../includes/sidebar_home.php'; ?>
    </div>

    <!-- Middle: Listings -->
    <div class="map-view-middle">
        <div class="map-view-header">
            <div class="text-muted fs-xs mb-1">خرید، فروش و اجاره انواع <?= $category ? h($category['name']) : 'کالا' ?> در <?= h($city) ?></div>
            <h1 class="fs-lg text-dark"><?= fmt_num($total) ?> آگهی در این محدوده</h1>
            <span class="map-view-header__hint">برای دیدن لیست آگهی‌ها بالا بکشید</span>
        </div>

        <div class="map-view-listings-content">
            <?php if (empty($listings)): ?>
                <div class="text-center py-8">
                    <i class="bi bi-search fs-xxl mb-3 text-muted"></i>
                    <p class="text-dark">آگهی یافت نشد.</p>
                </div>
            <?php else: ?>
                <?php foreach ($listings as $l): ?>
                    <a href="<?= h(listing_url($l)) ?>" class="listing-card-single">
                        <div class="position-relative">
                            <?php if ($l['thumb']): ?>
                                <img src="<?= UPLOAD_URL . h($l['thumb']) ?>" class="listing-card-single__thumb" alt="<?= h($l['title']) ?>">
                            <?php else: ?>
                                <div class="listing-card-single__thumb d-flex align-center justify-center bg-light text-muted">
                                    <i class="bi bi-image"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="listing-card-single__info">
                            <h3 class="listing-card-single__title"><?= h($l['title']) ?></h3>
                            <div>
                                <div class="listing-card-single__price"><?= fmt_credit((float)$l['estimated_value']) ?></div>
                                <div class="listing-card-single__location">
                                    <?= h($l['neighborhood'] ?: $l['city']) ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>

                <?php if ($pag['pages'] > 1): ?>
                    <div class="map-view-pagination">
                        <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $page === $i ? 'active' : '' ?>">
                                <?= fmt_num($i) ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Left: Map (Last in HTML for RTL Left position) -->
    <div class="map-view-map-container">
        <div id="map"></div>
        <button type="button" class="map-view-filters-btn" id="map-view-filters-btn">
            <i class="bi bi-sliders2"></i> فیلترها
        </button>
        <button class="map-view-close-btn" onclick="window.location.href='<?= APP_URL ?>/'">
            <i class="bi bi-x-lg"></i> بستن نقشه
        </button>
    </div>

    <div class="map-view-overlay" id="map-view-overlay"></div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const mapListings = <?= json_encode($mapListings) ?>;
        const city = "<?= $city ?>";
        
        // Default center (Tehran)
        let center = [35.6892, 51.3890];
        
        const map = L.map('map', {
            zoomControl: true,
            scrollWheelZoom: true
        }).setView(center, 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const markers = [];

        mapListings.forEach(l => {
            if (l.lat && l.lng) {
                const marker = L.marker([parseFloat(l.lat), parseFloat(l.lng)]).addTo(map);
                const thumb = l.thumb ? `<?= UPLOAD_URL ?>${l.thumb}` : '';
                const popupContent = `
                    <div class="map-marker-popup">
                        ${thumb ? `<img src="${thumb}" alt="">` : ''}
                        <strong>${l.title}</strong><br>
                        ${l.estimated_value > 0 ? `<span style="color:var(--accent)">${new Intl.NumberFormat('fa-IR').format(l.estimated_value)} تومان</span>` : ''}
                        <br><a href="${l.url}" style="color:blue">مشاهده آگهی</a>
                    </div>
                `;
                marker.bindPopup(popupContent);
                markers.push(marker);
            }
        });

        if (markers.length > 0) {
            const group = new L.featureGroup(markers);
            map.fitBounds(group.getBounds().pad(0.1));
        }

        /* ── رفتار موبایل: ارتفاع دقیق، شیت آگهی‌ها و کشوی فیلترها ── */
        const layout     = document.querySelector('.map-view-layout');
        const sheet      = document.querySelector('.map-view-middle');
        const sheetHead  = document.querySelector('.map-view-header');
        const sheetHint  = document.querySelector('.map-view-header__hint');
        const sidebar    = document.getElementById('map-view-sidebar');
        const overlay    = document.getElementById('map-view-overlay');
        const filtersBtn = document.getElementById('map-view-filters-btn');
        const drawerClose = document.getElementById('map-view-drawer-close');
        const isMobile   = () => window.innerWidth <= 991;

        function sizeMapLayout() {
            if (!layout) return;
            if (!isMobile()) {
                layout.style.height = '';
                map.invalidateSize();
                return;
            }
            const bottomNav = document.querySelector('.mobile-bottom-nav');
            const bottomNavVisible = bottomNav && getComputedStyle(bottomNav).display !== 'none';
            const bottomNavHeight = bottomNavVisible ? bottomNav.getBoundingClientRect().height : 0;
            const top = layout.getBoundingClientRect().top + window.scrollY;
            layout.style.height = Math.max(320, window.innerHeight - top - bottomNavHeight) + 'px';
            map.invalidateSize();
        }

        function setDrawer(open) {
            sidebar.classList.toggle('is-open', open);
            overlay.classList.toggle('is-open', open);
        }

        function setSheet(expanded) {
            sheet.classList.toggle('is-expanded', expanded);
            if (sheetHint) {
                sheetHint.textContent = expanded
                    ? 'برای بستن لیست، پایین بکشید'
                    : 'برای دیدن لیست آگهی‌ها بالا بکشید';
            }
        }

        filtersBtn?.addEventListener('click', () => setDrawer(true));
        drawerClose?.addEventListener('click', () => setDrawer(false));
        overlay?.addEventListener('click', () => setDrawer(false));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') setDrawer(false);
        });

        // کشیدن (Drag) شیت با لمس هدر
        let sheetStartY = 0, sheetDragY = 0, sheetDragging = false, suppressClick = false;

        sheetHead?.addEventListener('touchstart', (e) => {
            sheetStartY = e.touches[0].clientY;
            sheetDragY = 0;
            sheetDragging = true;
            sheet.classList.add('is-dragging');
        }, { passive: true });

        sheetHead?.addEventListener('touchmove', (e) => {
            if (!sheetDragging) return;
            sheetDragY = e.touches[0].clientY - sheetStartY;
        }, { passive: true });

        sheetHead?.addEventListener('touchend', () => {
            if (!sheetDragging) return;
            sheetDragging = false;
            sheet.classList.remove('is-dragging');
            if (Math.abs(sheetDragY) > 40) {
                setSheet(sheetDragY < 0);
                suppressClick = true;
                setTimeout(() => { suppressClick = false; }, 350);
            }
        });

        sheetHead?.addEventListener('click', () => {
            if (suppressClick || !isMobile()) return;
            setSheet(!sheet.classList.contains('is-expanded'));
        });

        sizeMapLayout();
        window.addEventListener('resize', sizeMapLayout);
        window.addEventListener('orientationchange', () => setTimeout(sizeMapLayout, 150));
    });
</script>

<?php render_footer(); ?>