<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();

$metaTitle = 'خدمات معاوضه‌ای و اشتراکی | سواَپین';
$metaDesc  = 'ارائه و دریافت خدمات در قبال معاوضه کالا یا خدمات دیگر. بهترین پلتفرم برای تبادل مهارت و سرویس‌های تخصصی.';
$canonical = APP_URL . '/services';

render_head($metaTitle, $metaDesc, [
    'canonical' => $canonical,
    'og_type'   => 'website',
]);
render_navbar($user);

// دسته‌بندی‌های خدمات
$serviceCats = [
    ['name' => 'آموزش', 'icon' => 'bi-book', 'q' => 'آموزش'],
    ['name' => 'تعمیرات', 'icon' => 'bi-tools', 'q' => 'تعمیرات'],
    ['name' => 'حمل و نقل', 'icon' => 'bi-truck', 'q' => 'حمل'],
    ['name' => 'نظافت', 'icon' => 'bi-stars', 'q' => 'نظافت'],
    ['name' => 'آرایشگری', 'icon' => 'bi-scissors', 'q' => 'آرایش'],
    ['name' => 'طراحی و گرافیک', 'icon' => 'bi-palette', 'q' => 'طراحی'],
    ['name' => 'برنامه‌نویسی', 'icon' => 'bi-code-slash', 'q' => 'برنامه نویسی'],
    ['name' => 'مشاوره', 'icon' => 'bi-chat-dots', 'q' => 'مشاوره'],
    ['name' => 'باغبانی', 'icon' => 'bi-tree', 'q' => 'باغبانی'],
    ['name' => 'پذیرایی', 'icon' => 'bi-cup-hot', 'q' => 'پذیرایی'],
];

$servicePairs = [
    'آموزش' => ['گوشی', 'لپ تاپ', 'تعمیرات', 'مشاوره'],
    'تعمیرات' => ['کالا', 'خدمات نظافت', 'آموزش'],
];
?>

<style>
    .sv-page {
        padding: 40px 0;
        background: #fff;
        direction: rtl;
    }
    .sv-section {
        margin-bottom: 50px;
    }
    .sv-title {
        font-size: 1.4rem;
        font-weight: 800;
        margin-bottom: 25px;
        color: #071A33;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .sv-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }
    .sv-chip {
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
    .sv-chip:hover {
        border-color: var(--accent);
        color: var(--accent);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    
    .sv-combined-row {
        margin-bottom: 30px;
    }
    .sv-combined-label {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 15px;
        color: #555;
    }
    .sv-scroll {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding: 5px 2px 15px 2px;
        scrollbar-width: none;
    }
    .sv-scroll::-webkit-scrollbar {
        display: none;
    }
    .sv-sub-chip {
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
    .sv-sub-chip:hover {
        background: #fff;
        border-color: var(--accent);
        color: var(--accent);
    }

    @media (max-width: 768px) {
        .sv-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<main class="sv-page">
    <div class="container">
        
        <!-- دسته‌های خدمات -->
        <section class="sv-section">
            <h2 class="sv-title">خدمات سواَپین</h2>
            <div class="sv-grid">
                <?php foreach ($serviceCats as $cat): ?>
                    <a href="<?= APP_URL ?>/listings/?q=<?= urlencode('خدمات ' . $cat['q']) ?>" class="sv-chip">
                        <?= h($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- معاوضه خدمات -->
        <section class="sv-section">
            <h2 class="sv-title">تبادل مهارت و خدمت</h2>
            
            <?php foreach ($servicePairs as $main => $subs): ?>
                <div class="sv-combined-row">
                    <div class="sv-combined-label">ارائه <?= h($main) ?> در قبال...</div>
                    <div class="sv-scroll">
                        <?php foreach ($subs as $sub): ?>
                            <a href="<?= APP_URL ?>/listings/?q=<?= urlencode($main) ?>&want=<?= urlencode($sub) ?>" class="sv-sub-chip">
                                <?= h($main) ?> با <?= h($sub) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

    </div>
</main>

<?php render_footer(); ?>
