<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();

$slug = normalize_shop_slug((string)($_GET['slug'] ?? ''));

if ($slug === '') {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$storeUser = DB::fetch(
    'SELECT id, store_name, store_description, store_banner, avatar, store_address, store_phone,
            store_website, store_instagram, store_telegram, store_opening_hours, store_lat, store_lng,
            name, rating AS seller_rating, city AS seller_city, created_at, seller_type
     FROM users
     WHERE store_slug = ? AND (seller_type = "store" OR (store_name IS NOT NULL AND store_name != ""))
     LIMIT 1',
    [$slug]
);

if (!$storeUser) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$listings = DB::fetchAll(
    'SELECT l.*, u.name AS seller_name, u.rating AS seller_rating, u.city AS seller_city,
            c.name AS cat_name, c.slug AS cat_slug,
            (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
     FROM listings l
     JOIN users u ON u.id = l.user_id
     JOIN categories c ON c.id = l.category_id
     WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved"
     ORDER BY (l.vip_until > NOW()) DESC, (l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC, l.id DESC',
    [(int)$storeUser['id']]
);

$title = h($storeUser['store_name'] ?? $storeUser['name']);
$desc = trim($storeUser['store_description'] ?? '') ?: 'فروشگاه ' . $title . ' در سواَپین';
$canonical = APP_URL . '/shop/' . h($slug);

$ogImage = LOGO_URL;
if (!empty($storeUser['store_banner'])) {
    $ogImage = UPLOAD_URL . $storeUser['store_banner'];
} elseif (!empty($storeUser['avatar'])) {
    $isExternal = preg_match('#^https?://#i', $storeUser['avatar']) === 1 || str_starts_with($storeUser['avatar'], '//');
    $ogImage = $isExternal ? $storeUser['avatar'] : UPLOAD_URL . $storeUser['avatar'];
}

render_head($title, $desc, [
    'canonical' => $canonical,
    'og_type'   => 'profile',
    'og_image'  => $ogImage,
    'json_ld'   => [
        '@context' => 'https://schema.org',
        '@type'    => 'Store',
        'name'     => $storeUser['store_name'] ?? $storeUser['name'],
        'description' => $storeUser['store_description'] ?? '',
        'url'      => $canonical,
        'telephone' => $storeUser['store_phone'] ?? '',
        'address'   => $storeUser['store_address'] ?? '',
        'image'     => $ogImage,
        'geo'       => (!empty($storeUser['store_lat']) && !empty($storeUser['store_lng'])) ? [
            '@type'     => 'GeoCoordinates',
            'latitude'  => (float)$storeUser['store_lat'],
            'longitude' => (float)$storeUser['store_lng'],
        ] : null,
        'openingHours' => $storeUser['store_opening_hours'] ?? '',
        'aggregateRating' => !empty($storeUser['seller_rating']) ? [
            '@type' => 'AggregateRating',
            'ratingValue' => (float)$storeUser['seller_rating'],
            'bestRating'  => 5,
        ] : null,
    ],
]);

render_navbar($user);

$bannerUrl = '';
if (!empty($storeUser['store_banner'])) {
    $bannerUrl = UPLOAD_URL . $storeUser['store_banner'];
}
?>

<main id="main-content">
    <?php if ($bannerUrl): ?>
    <div class="store-banner-wrapper">
        <img src="<?= h($bannerUrl) ?>" alt="<?= h($storeUser['store_name'] ?? $storeUser['name']) ?>" class="store-banner-img">
    </div>
    <?php else: ?>
    <div class="store-banner-wrapper store-banner--placeholder">
        <div class="store-banner-placeholder-inner">
            <i class="bi bi-shop-window"></i>
        </div>
    </div>
    <?php endif; ?>

    <section class="section-sm store-profile-section">
        <div class="container">
            <div class="store-profile-header">
                <div class="store-profile-header__avatar">
                    <?= avatar_html($storeUser['avatar'] ?? null, $storeUser['store_name'] ?? $storeUser['name'], 'lg') ?>
                </div>
                <div class="store-profile-header__info">
                    <div class="d-flex align-center gap-2 flex-wrap mb-2">
                        <h1 class="store-profile__name mb-0"><?= h($storeUser['store_name'] ?? $storeUser['name']) ?></h1>
                        <?php if (is_store_seller($storeUser)): ?>
                        <span class="badge badge-success" title="فروشنده تاییدشده">
                            <i class="bi bi-patch-check-fill"></i> تاییدشده
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="store-profile-header__meta d-flex align-center gap-3 flex-wrap">
                        <?php if (!empty($storeUser['seller_city'])): ?>
                        <span><i class="bi bi-geo-alt"></i> <?= h($storeUser['seller_city']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($storeUser['seller_rating'])): ?>
                        <span class="store-rating">
                            <i class="bi bi-star-fill"></i>
                            <span><?= number_format((float)$storeUser['seller_rating'], 1) ?></span>
                            <span class="text-muted fs-xs">از ۵</span>
                        </span>
                        <?php endif; ?>
                        <span class="text-muted fs-xs">
                            <i class="bi bi-calendar3"></i>
                            عضو از <?= h(timeago($storeUser['created_at'])) ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="store-grid">
                <aside class="store-sidebar">
                    <div class="card store-contact-card">
                        <h3 class="store-sidebar__title mb-4"><i class="bi bi-info-circle"></i> اطلاعات فروشگاه</h3>

                        <ul class="store-contact-list">
                            <?php if (!empty($storeUser['store_phone'])): ?>
                            <li>
                                <i class="bi bi-telephone"></i>
                                <span class="store-contact__label">تلفن:</span>
                                <a href="tel:<?= h($storeUser['store_phone']) ?>" dir="ltr"><?= h($storeUser['store_phone']) ?></a>
                            </li>
                            <?php endif; ?>

                            <?php if (!empty($storeUser['store_address'])): ?>
                            <li>
                                <i class="bi bi-geo-alt-fill"></i>
                                <span class="store-contact__label">آدرس:</span>
                                <span><?= h($storeUser['store_address']) ?></span>
                            </li>
                            <?php endif; ?>

                            <?php if (!empty($storeUser['store_website'])): ?>
                            <li>
                                <i class="bi bi-globe"></i>
                                <span class="store-contact__label">وب‌سایت:</span>
                                <?php
                                $ws = $storeUser['store_website'];
                                if (!preg_match('#^https?://#i', $ws)) $ws = 'https://' . $ws;
                                ?>
                                <a href="<?= h($ws) ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?= h($storeUser['store_website']) ?></a>
                            </li>
                            <?php endif; ?>

                            <?php if (!empty($storeUser['store_instagram'])): ?>
                            <li>
                                <i class="bi bi-instagram"></i>
                                <span class="store-contact__label">اینستاگرام:</span>
                                <?php
                                $ig = $storeUser['store_instagram'];
                                if (!preg_match('#^https?://#i', $ig)) {
                                    $ig = preg_replace('#^@#', '', $ig);
                                    $ig = 'https://instagram.com/' . $ig;
                                }
                                ?>
                                <a href="<?= h($ig) ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?= h($storeUser['store_instagram']) ?></a>
                            </li>
                            <?php endif; ?>

                            <?php if (!empty($storeUser['store_telegram'])): ?>
                            <li>
                                <i class="bi bi-telegram"></i>
                                <span class="store-contact__label">تلگرام:</span>
                                <?php
                                $tg = $storeUser['store_telegram'];
                                if (!preg_match('#^https?://#i', $tg)) {
                                    $tg = preg_replace('#^@#', '', $tg);
                                    $tg = 'https://t.me/' . $tg;
                                }
                                ?>
                                <a href="<?= h($tg) ?>" target="_blank" rel="noopener noreferrer" dir="ltr"><?= h($storeUser['store_telegram']) ?></a>
                            </li>
                            <?php endif; ?>

                            <?php if (!empty($storeUser['store_opening_hours'])): ?>
                            <li>
                                <i class="bi bi-clock"></i>
                                <span class="store-contact__label">ساعات کاری:</span>
                                <span><?= h($storeUser['store_opening_hours']) ?></span>
                            </li>
                            <?php endif; ?>
                        </ul>

                        <?php if (!empty($storeUser['store_lat']) && !empty($storeUser['store_lng'])): ?>
                        <div class="store-map-placeholder mt-4">
                            <i class="bi bi-geo-alt"></i>
                            <div>
                                <strong>موقعیت روی نقشه</strong>
                                <div class="fs-xs text-muted mt-1">
                                    lat: <?= h($storeUser['store_lat']) ?> , lng: <?= h($storeUser['store_lng']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </aside>

                <section class="store-main">
                    <?php if (!empty($storeUser['store_description'])): ?>
                    <div class="card mb-5 p-4" style="padding: 15px;">
                        <h3 class="store-sidebar__title mb-3"><i class="bi bi-file-text"></i> درباره فروشگاه</h3>
                        <div class="store-description">
                            <?= nl2br(h($storeUser['store_description'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex align-center justify-between mb-4">
                        <h2 class="mb-0">
                            <i class="bi bi-box-seam"></i>
                            محصولات فروشگاه
                            <span class="badge badge-primary ml-2"><?= fmt_num(count($listings)) ?></span>
                        </h2>
                    </div>

                    <?php if (empty($listings)): ?>
                    <div class="empty-state">
                        <i class="bi bi-box-seam"></i>
                        <h3>هنوز محصولی ثبت نشده</h3>
                        <p>این فروشگاه هنوز محصولی در سواَپین ثبت نکرده است.</p>
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
                </section>
            </div>
        </div>
    </section>
</main>

<style>
.store-banner-wrapper {
    width: 100%;
    max-height: 320px;
    overflow: hidden;
    background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
    position: relative;
}
.store-banner-img {
    width: 100%;
    height: 320px;
    object-fit: cover;
    display: block;
}
.store-banner--placeholder .store-banner-placeholder-inner {
    height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,0.3);
}
.store-banner--placeholder .store-banner-placeholder-inner i {
    font-size: 6rem;
}
.store-profile-section {
    margin-top: -80px;
    position: relative;
    z-index: 2;
}
.store-profile-header {
    background: var(--bg-card, #fff);
    border-radius: 16px;
    padding: 1.5rem 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    display: flex;
    align-items: flex-end;
    gap: 1.5rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}
.store-profile-header__avatar .avatar {
    width: 120px;
    height: 120px;
    border: 4px solid #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    background: var(--bg-card, #fff);
}
.store-profile__name {
    font-size: 1.75rem;
    font-weight: 800;
}
.store-profile-header__meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.store-rating i {
    color: #f59e0b;
}
.store-grid {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 1.5rem;
}
@media (max-width: 991px) {
    .store-grid {
        grid-template-columns: 1fr;
    }
    .store-banner-img {
        height: 180px;
    }
    .store-profile-header__avatar .avatar {
        width: 90px;
        height: 90px;
    }
    .store-profile-section {
        margin-top: -50px;
    }
}
.store-sidebar__title {
    font-size: 1.1rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.store-contact-card {
    padding: 1.5rem;
    position: sticky;
    top: 1rem;
}
.store-contact-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.store-contact-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    font-size: 0.925rem;
}
.store-contact-list li i {
    color: var(--primary);
    margin-top: 2px;
    font-size: 1rem;
    width: 20px;
    flex-shrink: 0;
}
.store-contact__label {
    color: var(--text-muted);
    font-size: 0.85rem;
    min-width: 70px;
    display: inline-block;
}
.store-contact-list a {
    color: var(--text);
    text-decoration: none;
}
.store-contact-list a:hover {
    color: var(--primary);
}
.store-map-placeholder {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: var(--bg-muted, #f6f8fb);
    border-radius: 10px;
    border: 1px dashed var(--border-color, #e1e1e1);
}
.store-map-placeholder > i {
    font-size: 1.5rem;
    color: var(--primary);
}
.store-description {
    line-height: 1.9;
    color: var(--text-secondary);
}
</style>

<?php render_footer(); ?>
