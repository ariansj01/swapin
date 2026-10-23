<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();

$metaTitle = 'معاوضه هوشمند کالا و خدمات | سواَپین';
$metaDesc  = 'در سواَپین به راحتی کالای خود را با هر چیزی که نیاز دارید معاوضه کنید. دسته‌بندی‌های مختلف برای تبادل مستقیم و هوشمند.';
$canonical = APP_URL . '/exchange';

render_head($metaTitle, $metaDesc, [
    'canonical' => $canonical,
    'og_type'   => 'website',
]);
render_navbar($user);

// دسته‌بندی‌های اصلی برای نمایش در چیپ‌ها
$mainCats = [
    ['name' => 'گوشی', 'icon' => 'bi-phone', 'slug' => 'electronics/phones'],
    ['name' => 'لپ‌تاپ', 'icon' => 'bi-laptop', 'slug' => 'electronics/laptops'],
    ['name' => 'تبلت', 'icon' => 'bi-tablet', 'slug' => 'electronics/tablets'],
    ['name' => 'خانه و آپارتمان', 'icon' => 'bi-house', 'slug' => 'real-estate/residential'],
    ['name' => 'ویلا و باغ', 'icon' => 'bi-tree', 'slug' => 'real-estate/villa'],
    ['name' => 'زمین و کلنگی', 'icon' => 'bi-map', 'slug' => 'real-estate/land'],
    ['name' => 'ماشین', 'icon' => 'bi-car-front', 'slug' => 'vehicles/cars'],
    ['name' => 'موتور', 'icon' => 'bi-bicycle', 'slug' => 'vehicles/motorcycles'],
    ['name' => 'یخچال', 'icon' => 'bi-snow', 'slug' => 'home-appliances/fridge'],
    ['name' => 'تلویزیون', 'icon' => 'bi-tv', 'slug' => 'home-appliances/tv'],
    ['name' => 'مبل', 'icon' => 'bi-chair', 'slug' => 'home-appliances/furniture'],
    ['name' => 'طلا', 'icon' => 'bi-gem', 'slug' => 'personal-items/jewelry'],
    ['name' => 'دوچرخه', 'icon' => 'bi-bicycle', 'slug' => 'leisure/bicycle'],
];

$combinedPairs = [
    'گوشی' => [
        ['label' => 'لپ‌تاپ', 'q' => 'گوشی', 'w' => 'لپ تاپ'],
        ['label' => 'تبلت', 'q' => 'گوشی', 'w' => 'تبلت'],
        ['label' => 'ماشین', 'q' => 'گوشی', 'w' => 'ماشین'],
        ['label' => 'ساعت هوشمند', 'q' => 'گوشی', 'w' => 'ساعت هوشمند'],
        ['label' => 'کنسول بازی', 'q' => 'گوشی', 'w' => 'کنسول بازی'],
    ],
    'املاک' => [
        ['label' => 'آپارتمان با ویلا', 'q' => 'آپارتمان', 'w' => 'ویلا'],
        ['label' => 'زمین با ماشین', 'q' => 'زمین', 'w' => 'ماشین'],
        ['label' => 'خانه با مغازه', 'q' => 'خانه', 'w' => 'مغازه'],
        ['label' => 'باغ با آپارتمان', 'q' => 'باغ', 'w' => 'آپارتمان'],
    ],
    'خودرو' => [
        ['label' => 'ماشین با ماشین', 'q' => 'ماشین', 'w' => 'ماشین'],
        ['label' => 'ماشین با زمین', 'q' => 'ماشین', 'w' => 'زمین'],
        ['label' => 'موتور با گوشی', 'q' => 'موتور', 'w' => 'گوشی'],
    ],
    'لوازم خانگی' => [
        ['label' => 'یخچال با تلویزیون', 'q' => 'یخچال', 'w' => 'تلویزیون'],
        ['label' => 'مبل با فرش', 'q' => 'مبل', 'w' => 'فرش'],
        ['label' => 'کولر با پکیج', 'q' => 'کولر', 'w' => 'پکیج'],
    ]
];
?>

<style>
    .ex-page {
        padding: 40px 0;
        background: #fff;
        direction: rtl;
    }
    .ex-section {
        margin-bottom: 50px;
    }
    .ex-title {
        font-size: 1.4rem;
        font-weight: 800;
        margin-bottom: 25px;
        color: #071A33;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .ex-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }
    .ex-chip {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 15px 20px;
        background: #fff;
        border: 1px solid #eee;
        border-radius: 12px;
        text-decoration: none;
        color: #333;
        font-weight: 600;
        transition: all 0.2s;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .ex-chip:hover {
        border-color: var(--accent);
        color: var(--accent);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    
    .ex-combined-row {
        margin-bottom: 30px;
    }
    .ex-combined-label {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 15px;
        color: #555;
    }
    .ex-scroll {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding: 5px 2px 15px 2px;
        scrollbar-width: none; /* Firefox */
    }
    .ex-scroll::-webkit-scrollbar {
        display: none; /* Chrome/Safari */
    }
    .ex-sub-chip {
        flex: 0 0 auto;
        padding: 10px 18px;
        background: #f8f9fa;
        border: 1px solid #eee;
        border-radius: 50px;
        text-decoration: none;
        color: #666;
        font-size: 0.9rem;
        transition: all 0.2s;
    }
    .ex-sub-chip:hover {
        background: #fff;
        border-color: var(--accent);
        color: var(--accent);
    }

    @media (max-width: 768px) {
        .ex-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<main class="ex-page">
    <div class="container">
        
        <!-- دسته‌های اصلی -->
        <section class="ex-section">
            <h2 class="ex-title">دسته‌های اصلی معاوضه</h2>
            <div class="ex-grid">
                <?php foreach ($mainCats as $cat): ?>
                    <a href="<?= APP_URL ?>/listings/?cat=<?= urlencode($cat['slug']) ?>" class="ex-chip">
                        معاوضه <?= h($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- معاوضه ترکیبی -->
        <section class="ex-section">
            <h2 class="ex-title">پیشنهادات معاوضه ترکیبی</h2>
            
            <?php foreach ($combinedPairs as $group => $subs): ?>
                <div class="ex-combined-row">
                    <div class="ex-combined-label"><?= h($group) ?> با...</div>
                    <div class="ex-scroll">
                        <?php foreach ($subs as $sub): ?>
                            <a href="<?= APP_URL ?>/listings/?q=<?= urlencode($sub['q']) ?>&want=<?= urlencode($sub['w']) ?>" class="ex-sub-chip">
                                <?= h($sub['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

    </div>
</main>

<?php render_footer(); ?>
