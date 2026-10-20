<?php
// includes/banners.php
// بنرهای اسلایدر صفحه اصلی (زیر استوری‌ها)

function banners_ensure_tables(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!db_has_table('banners')) {
        DB::query("CREATE TABLE IF NOT EXISTS `banners` (
          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
          `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `subtitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `badge` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `link_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `sort_order` int NOT NULL DEFAULT 0,
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_banners_active` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/** بنرهای فعال برای نمایش در سایت */
function banners_active(): array {
    banners_ensure_tables();
    return DB::fetchAll('SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id DESC');
}

/** همه بنرها برای پنل مدیریت */
function banners_all(): array {
    banners_ensure_tables();
    return DB::fetchAll('SELECT * FROM banners ORDER BY sort_order ASC, id DESC');
}

function banners_get(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    banners_ensure_tables();
    return DB::fetch('SELECT * FROM banners WHERE id = ?', [$id]);
}

/** فقط لینک‌های http(s) یا مسیر داخلی مجاز هستند */
function banners_safe_link(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    return preg_match('#^(https?://|/)#i', $url) ? $url : '';
}

function banners_delete(int $id): bool {
    banners_ensure_tables();
    $row = banners_get($id);
    if (!$row) {
        return false;
    }
    DB::query('DELETE FROM banners WHERE id = ?', [$id]);
    if (!empty($row['image'])) {
        $file = UPLOAD_DIR . '/' . $row['image'];
        if (is_file($file)) {
            @unlink($file);
        }
    }
    return true;
}

function banners_save(int $id, array $data): int {
    banners_ensure_tables();
    if ($id > 0) {
        DB::update('banners', $data, 'id = ?', [$id]);
        return $id;
    }
    return DB::insert('banners', $data);
}

function banners_render_slider(): void {
    $banners = banners_active();
    if (empty($banners)) {
        return;
    }
    ?>
    <section class="home-slider-section container mb-6">
        <div class="banner-slider" id="bannerSlider">
            <div class="banner-slider__wrapper">
                <?php foreach ($banners as $index => $b): ?>
                <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
                    <a href="<?= h($b['link_url'] ?: 'javascript:void(0)') ?>" class="banner-card">
                        <div class="banner-card__content">
                            <?php if ($b['badge']): ?>
                            <div class="banner-card__badge">
                                <i class="bi bi-stars"></i>
                                <span><?= h($b['badge']) ?></span>
                            </div>
                            <?php endif; ?>
                            <h2 class="banner-card__title"><?= h($b['title']) ?></h2>
                            <p class="banner-card__subtitle"><?= h($b['subtitle']) ?></p>
                        </div>
                        <div class="banner-card__image">
                            <img src="<?= UPLOAD_URL . h($b['image']) ?>" alt="<?= h($b['title']) ?>" loading="lazy">
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($banners) > 1): ?>
            <div class="banner-slider__dots">
                <?php foreach ($banners as $index => $b): ?>
                <button class="banner-dot <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>" aria-label="Go to slide <?= $index + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const slider = document.getElementById('bannerSlider');
        if (!slider) return;
        
        const slides = slider.querySelectorAll('.banner-slide');
        const dots = slider.querySelectorAll('.banner-dot');
        let current = 0;
        let interval;

        function showSlide(index) {
            slides.forEach(s => s.classList.remove('active'));
            dots.forEach(d => d.classList.remove('active'));
            
            slides[index].classList.add('active');
            if (dots[index]) dots[index].classList.add('active');
            current = index;
        }

        function nextSlide() {
            showSlide((current + 1) % slides.length);
        }

        function startAutoPlay() {
            stopAutoPlay();
            interval = setInterval(nextSlide, 5000);
        }

        function stopAutoPlay() {
            if (interval) clearInterval(interval);
        }

        dots.forEach(dot => {
            dot.addEventListener('click', () => {
                showSlide(parseInt(dot.dataset.index));
                startAutoPlay();
            });
        });

        slider.addEventListener('mouseenter', stopAutoPlay);
        slider.addEventListener('mouseleave', startAutoPlay);

        if (slides.length > 1) {
            startAutoPlay();
        }
    });
    </script>
    <?php
}
