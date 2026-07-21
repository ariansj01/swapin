<?php

function swapin_content_defaults(): array {
    return [
        'home_meta_title'         => 'سواَپین | بازار هوشمند مبادله کالا',
        'home_meta_desc'          => 'سواَپین - بزرگترین پلتفرم هوشمند مبادله کالا با کالا در ایران. کالاهای خود را ثبت کنید و با چیزهایی که نیاز دارید معاوضه کنید.',
        'hero_title_line_1'       => 'سواَپین؛ پلتفرم هوشمند',
        'hero_title_line_2'       => 'مبادله کالا با کالا',
        'hero_subtitle_before'    => 'کمتر بخر، بیشتر',
        'hero_subtitle_highlight' => 'مبادله',
        'hero_primary_cta'        => 'ثبت معامله',
        'hero_secondary_cta'      => 'مرور آگهی‌ها',
        'home_ai_badge'           => 'هوش مصنوعی',
        'home_ai_title'           => 'ارزش‌گذاری و مشاوره معاوضه با AI',
        'home_ai_desc'            => 'بعد از ثبت کالا، هوش مصنوعی سواپین ارزش تقریبی را محاسبه می‌کند. با دستیار AI هم درباره بهترین گزینه‌های معاوضه مشورت کنید.',
        'home_ai_primary_cta'     => 'ثبت کالا + قیمت AI',
        'home_ai_secondary_cta'   => 'دستیار AI',
        'footer_brand_tagline'    => 'سواَپین بستری امن و آسان برای معاوضه تهاتر کالا های نو و دست دوم بین کاربران',
        'footer_copy'             => 'تمامی حقوق این وبسایت متعلق به سواپین می‌باشد.',
    ];
}

function swapin_content_path(): string {
    return STORAGE_DIR . '/content_settings.php';
}

function swapin_content_all(): array {
    static $content = null;
    if ($content !== null) {
        return $content;
    }

    $content = swapin_content_defaults();
    $path = swapin_content_path();
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            foreach ($loaded as $key => $value) {
                if (array_key_exists($key, $content) && is_string($value)) {
                    $content[$key] = $value;
                }
            }
        }
    }

    return $content;
}

function swapin_content_get(string $key): string {
    $content = swapin_content_all();
    return $content[$key] ?? '';
}

function swapin_content_save(array $values): bool {
    $defaults = swapin_content_defaults();
    $payload = [];

    foreach ($defaults as $key => $default) {
        $value = isset($values[$key]) ? clean((string) $values[$key]) : $default;
        $payload[$key] = trim($value) !== '' ? trim($value) : $default;
    }

    $path = swapin_content_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $php = "<?php\nreturn " . var_export($payload, true) . ";\n";
    $written = @file_put_contents($path, $php, LOCK_EX);
    if ($written === false) {
        return false;
    }

    clearstatcache(true, $path);
    return true;
}
