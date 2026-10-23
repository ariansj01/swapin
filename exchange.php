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
    ['name' => 'گوشی', 'icon' => 'bi-phone', 'q' => 'گوشی'],
    ['name' => 'لپ‌تاپ', 'icon' => 'bi-laptop', 'q' => 'لپ تاپ'],
    ['name' => 'تبلت', 'icon' => 'bi-tablet', 'q' => 'تبلت'],
    ['name' => 'خانه', 'icon' => 'bi-house', 'q' => 'خانه'],
    ['name' => 'آپارتمان', 'icon' => 'bi-building', 'q' => 'آپارتمان'],
    ['name' => 'زمین', 'icon' => 'bi-map', 'q' => 'زمین'],
    ['name' => 'ماشین', 'icon' => 'bi-car-front', 'q' => 'ماشین'],
    ['name' => 'موتور', 'icon' => 'bi-bicycle', 'q' => 'موتور'],
    ['name' => 'یخچال', 'icon' => 'bi-snow', 'q' => 'یخچال'],
    ['name' => 'تلویزیون', 'icon' => 'bi-tv', 'q' => 'تلویزیون'],
    ['name' => 'مبل', 'icon' => 'bi-chair', 'q' => 'مبل'],
    ['name' => 'طلا', 'icon' => 'bi-gem', 'q' => 'طلا'],
    ['name' => 'دوچرخه', 'icon' => 'bi-bicycle', 'q' => 'دوچرخه'],
];

$combinedPairs = [
    'گوشی' => ['لپ تاپ', 'تبلت', 'خانه', 'آپارتمان', 'زمین', 'ماشین', 'موتور', 'یخچال', 'تلویزیون', 'مبل', 'طلا', 'دوچرخه'],
    'لپ تاپ' => ['گوشی', 'تبلت', 'ماشین', 'موتور', 'طلا'],
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
            <h2 class="ex-title">دسته‌های اصلی</h2>
            <div class="ex-grid">
                <?php foreach ($mainCats as $cat): ?>
                    <a href="<?= APP_URL ?>/listings/?q=<?= urlencode('معاوضه ' . $cat['q']) ?>" class="ex-chip">
                        معاوضه <?= h($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- معاوضه ترکیبی -->
        <section class="ex-section">
            <h2 class="ex-title">معاوضه ترکیبی</h2>
            
            <?php foreach ($combinedPairs as $main => $subs): ?>
                <div class="ex-combined-row">
                    <div class="ex-combined-label">معاوضه <?= h($main) ?> با...</div>
                    <div class="ex-scroll">
                        <?php foreach ($subs as $sub): ?>
                            <a href="<?= APP_URL ?>/listings/?q=<?= urlencode('معاوضه ' . $main) ?>&want=<?= urlencode($sub) ?>" class="ex-sub-chip">
                                معاوضه <?= h($main) ?> با <?= h($sub) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Fallback for other combinations -->
            <div class="ex-combined-row">
                <div class="ex-combined-label">معاوضه تبلت با...</div>
                <div class="ex-scroll">
                    <a href="<?= APP_URL ?>/listings/?q=معاوضه تبلت&want=گوشی" class="ex-sub-chip">معاوضه تبلت با گوشی</a>
                    <a href="<?= APP_URL ?>/listings/?q=معاوضه تبلت&want=لپ تاپ" class="ex-sub-chip">معاوضه تبلت با لپ تاپ</a>
                </div>
            </div>
        </section>

    </div>
</main>

<?php render_footer(); ?>
