<?php
// Persian UI helpers — Swapin (سواَپین)

function persian_digits(string $str): string {
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        $str
    );
}

function fmt_num(int|float $number, int $decimals = 0): string {
    return persian_digits(number_format($number, $decimals));
}

function fmt_credit(float $amount, bool $withUnit = true): string {
    $n = fmt_num($amount, 0);
    return $withUnit ? $n . ' ' . CREDIT_UNIT : $n;
}

/** First visible letter of a name (UTF-8 safe). Empty if not a letter. */
function user_initial(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $ch = mb_substr($name, 0, 1, 'UTF-8');
    if (preg_match('/^[a-zA-Z]$/u', $ch)) {
        return mb_strtoupper($ch, 'UTF-8');
    }
    // Persian / Arabic letters
    if (preg_match('/^[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]$/u', $ch)) {
        return $ch;
    }
    return '';
}

/** Avatar markup: photo (local or external URL), letter initial, or person icon (never broken "?"). */
function avatar_html(?string $avatar, ?string $name = '', string $size = 'md'): string {
    $name = (string)($name ?? '');
    $sizeClass = 'avatar-' . preg_replace('/[^a-z]/', '', $size);

    if ($avatar !== null && trim($avatar) !== '') {
        $avatar = trim($avatar);
        $isExternal = preg_match('#^https?://#i', $avatar) === 1 || str_starts_with($avatar, '//');
        $src = $isExternal ? $avatar : UPLOAD_URL . $avatar;
        return '<img class="avatar ' . $sizeClass . ' avatar--img" src="' . h($src) . '" alt="' . h($name) . '" onerror="this.replaceWith(Object.assign(document.createElement(\'div\'),{className:\'' . 'avatar ' . $sizeClass . '\',innerHTML:this.alt.charAt(0)||\'\'}));">';
    }

    $initial = user_initial($name);
    if ($initial !== '') {
        return '<div class="avatar ' . $sizeClass . '" aria-hidden="true">' . h($initial) . '</div>';
    }
    return '<div class="avatar ' . $sizeClass . ' avatar--icon" aria-hidden="true"><i class="bi bi-person-fill"></i></div>';
}

function condition_label(string $cond): string {
    return match ($cond) {
        'new'      => 'نو',
        'like_new' => 'در حد نو',
        'good'     => 'خوب',
        'fair'     => 'متوسط',
        'poor'     => 'ضعیف',
        default    => $cond,
    };
}

function category_label(?string $slug = '', ?string $name = ''): string {
    $slug = (string)($slug ?? '');
    $name = (string)($name ?? '');
    $labels = [
        'electronics'     => 'دیجیتال',
        'clothing'        => 'پوشاک',
        'home-garden'     => 'خانه و ویلا',
        'home-appliances' => 'لوازم خانگی',
        'books-media'     => 'کتاب و رسانه',
        'sports'          => 'ورزش',
        'toys-games'      => 'اسباب‌بازی و بازی',
        'vehicles'        => 'خودرو',
        'services'        => 'خدمات',
        'food-drink'      => 'غذا و نوشیدنی',
        'other'           => 'سایر',
        'phones'          => 'موبایل و تلفن',
        'laptops'         => 'لپ‌تاپ',
        'cameras'         => 'دوربین',
        'audio'           => 'صوتی',
        'gaming'          => 'گیم و کنسول',
        'mens-clothing'   => 'لباس مردانه',
        'womens-clothing' => 'لباس زنانه',
        'shoes'           => 'کفش',
        'furniture'       => 'مبلمان',
        'kitchen'         => 'آشپزخانه',
        'garden'          => 'باغ و فضای سبز',
        'books'           => 'کتاب',
        'movies'          => 'فیلم',
        'music'           => 'موسیقی',
        'tutoring'        => 'آموزش و تدریس',
        'repair-fix'      => 'تعمیرات',
        'creative'        => 'خلاقیت و هنر',
        'transport'       => 'حمل و نقل',
    ];

    return $labels[$slug] ?? ($name ?: $slug);
}

function get_category_ancestors(string $slug): array {
    $chain = [];
    $current = DB::fetch(
        'SELECT id, slug, parent_id FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1',
        [$slug]
    );
    if (!$current) {
        return [$slug];
    }
    $chain[] = $current['slug'];
    $pid = $current['parent_id'];
    $safety = 10;
    while ($pid > 0 && $safety-- > 0) {
        $parent = DB::fetch(
            'SELECT id, slug, parent_id FROM categories WHERE id = ? AND is_active = 1 LIMIT 1',
            [(int)$pid]
        );
        if (!$parent) break;
        array_unshift($chain, $parent['slug']);
        $pid = (int)($parent['parent_id'] ?? 0);
        if ($pid === 0) break;
    }
    return $chain;
}

function get_category_by_slug_path(array $slugParts): ?array {
    if (empty($slugParts)) return null;
    $lastSlug = end($slugParts);
    $cat = DB::fetch(
        'SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1',
        [$lastSlug]
    );
    if (!$cat) return null;
    $chain = get_category_ancestors($cat['slug']);
    if (array_slice($chain, -count($slugParts)) === $slugParts) {
        return $cat;
    }
    if (count($chain) >= count($slugParts)) {
        $match = true;
        foreach ($slugParts as $i => $s) {
            if (($chain[$i] ?? '') !== $s) { $match = false; break; }
        }
        if ($match) return $cat;
    }
    $simple = DB::fetch(
        'SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1',
        [$slugParts[0]]
    );
    return $simple ?: null;
}

function category_url(string $slug): string {
    $chain = get_category_ancestors($slug);
    $path = implode('/', array_map('rawurlencode', $chain));
    return APP_URL . '/' . $path;
}

/**
 * Legacy top-level category slugs that were renamed/merged.
 * Maps the old slug to the current canonical slug so old URLs 301 instead of 404.
 */
function swaapin_legacy_category_aliases(): array {
    // اسلاگ‌های قدیمی دسته (درخت قبلی و دسته‌های فرم ثبت آگهی) → برگ جدید در «درخت دسته».
    return [
        // ── املاک
        'residential-apartment'   => 'apartment',
        'apartment-for-sale'      => 'apartment',
        'apartment-for-rent'      => 'apartment-rent',
        'residential-villa'       => 'villa',
        'villa-for-sale'          => 'villa',
        'villa-for-rent'          => 'villa-rent',
        'short-term-rentals'      => 'villa-rent',
        'commercial-office'       => 'commercial',
        'industrial-land'         => 'land',
        // ── وسایل نقلیه
        'cars'                    => 'car',
        'passenger-cars'          => 'car',
        'classic-cars'            => 'car',
        'rental-cars'             => 'services',
        'commercial-vehicles'     => 'heavy-vehicles',
        'heavy-equipment'         => 'heavy-vehicles',
        'car-rental-services'     => 'services',
        // ── کالای دیجیتال
        'mobile-tablet'           => 'mobile-phones',
        'tablets'                 => 'tablet',
        'computers'               => 'computer',
        'laptops'                 => 'laptop',
        'desktops'                => 'computer',
        'computer-parts'          => 'computer',
        'networking'              => 'computer',
        'printers-scanners'       => 'computer',
        'console-gaming'          => 'game-consoles',
        'audio-visual'            => 'audio-video',
        'music-movies'            => 'audio-video',
        'headphones-speakers'     => 'audio-video',
        'home-audio'              => 'audio-video',
        'dvd-blu-ray'             => 'audio-video',
        'tv-projector'            => 'audio-video',
        'landline-phones'         => 'audio-video',
        'cameras'                 => 'camera',
        'cctv'                    => 'camera',
        // ── خانه و آشپزخانه
        'appliances'              => 'home-appliances',
        'kitchen-appliances'      => 'home-appliances',
        'laundry-appliances'      => 'home-appliances',
        'cooling-heating'         => 'home-appliances',
        'small-appliances'        => 'home-appliances',
        'home-textile'            => 'carpet',
        'gardening-plants'        => 'plants',
        'garden'                  => 'plants',
        'home-garden'             => 'home-kitchen',
        // ── خدمات (درخت قدیمی) → خدمات
        'business-financial'      => 'services',
        'home-services'           => 'services',
        'cleaning-services'       => 'services',
        'moving-services'         => 'services',
        'renovation'              => 'services',
        'legal-educational'       => 'services',
        'wedding-events'          => 'services',
        'vehicles-services'       => 'services',
        'beauty-health-serv'      => 'services',
        'electronic-services'     => 'services',
        'tourism-travel'          => 'services',
        'travel-tourism-items'    => 'services',
        'transport'               => 'services',
        'repair-fix'              => 'services',
        'tutoring'                => 'services',
        // ── وسایل شخصی
        'clothing'                => 'personal-items',
        'womens-clothing'         => 'women-clothing',
        'mens-clothing'           => 'men-clothing',
        'kids-clothing'           => 'baby-kids',
        'watches-jewelry'         => 'jewellery',
        'bags-luggage'            => 'accessories',
        'eyeglasses'              => 'accessories',
        'beauty-cosmetics'        => 'beauty',
        'makeup'                  => 'beauty',
        'skincare'                => 'beauty',
        'haircare'                => 'beauty',
        'perfume'                 => 'beauty',
        'baby-products'           => 'baby-kids',
        // ── سرگرمی و فراغت
        'toys-games'              => 'toys',
        'books-media'             => 'books',
        'books-magazines'         => 'books',
        'fiction'                 => 'books',
        'academic'                => 'books',
        'comics-manga'            => 'books',
        'children-books'          => 'books',
        'movies'                  => 'audio-video',
        'sports-fitness'          => 'sports',
        'team-sports'             => 'sports',
        'fitness-gym'             => 'sports',
        'outdoor-sports'          => 'sports',
        'music'                   => 'musical-instruments',
        'collectibles'            => 'art-collectibles',
        'creative'                => 'art-collectibles',
        'pets-animals'            => 'pets',
        'dogs'                    => 'pets',
        'cats'                    => 'pets',
        'birds'                   => 'pets',
        'fish-aquarium'           => 'pets',
        'pet-accessories'         => 'pets',
        // ── مصالح، ابزار و تجهیزات
        'power-tools'             => 'tools',
        'hand-tools'              => 'tools',
        'industrial-machinery'    => 'industrial-equipment',
        'lab-scientific'          => 'industrial-equipment',
        'medical-equipment'       => 'industrial-equipment',
        'industrial-supplies'     => 'industrial-equipment',
        'construction'            => 'building-materials',
        // ── استخدام
        'fulltime-jobs'           => 'jobs',
        'parttime-jobs'           => 'jobs',
        'remote-jobs'             => 'jobs',
        'freelance-projects'      => 'jobs',
        'internships'             => 'jobs',
        'resume-cv'               => 'jobs',
        // ── دسته‌های قدیمی فرم ثبت آگهی
        'phones'                  => 'mobile-phones',
        'gaming'                  => 'game-consoles',
        'audio'                   => 'audio-video',
        'kitchen'                 => 'kitchenware',
    ];
}

function swaapin_resolve_legacy_category_slug(string $slug): ?string {
    $aliases = swaapin_legacy_category_aliases();
    return $aliases[$slug] ?? null;
}

function wizard_allowed_category_slugs(): array {
    return [
        'real-estate',
        'vehicles',
        'electronics',
        'home-kitchen',
        'personal-items',
        'leisure-hobbies',
        'tools-equipment',
        'jobs-services',
    ];
}

function wizard_category_seed(): array {
    return [
        'real-estate'     => ['name' => 'املاک',                   'icon' => 'bi bi-buildings',   'sort_order' => 1],
        'vehicles'        => ['name' => 'وسایل نقلیه',             'icon' => 'bi bi-car-front',   'sort_order' => 2],
        'electronics'     => ['name' => 'کالای دیجیتال',           'icon' => 'bi bi-phone',       'sort_order' => 3],
        'home-kitchen'    => ['name' => 'لوازم خانه و آشپزخانه',   'icon' => 'bi bi-house-heart', 'sort_order' => 4],
        'personal-items'  => ['name' => 'وسایل شخصی',              'icon' => 'bi bi-person-badge','sort_order' => 5],
        'leisure-hobbies' => ['name' => 'سرگرمی و فراغت',          'icon' => 'bi bi-joystick',    'sort_order' => 6],
        'tools-equipment' => ['name' => 'مصالح، ابزار و تجهیزات',  'icon' => 'bi bi-hammer',      'sort_order' => 7],
        'jobs-services'   => ['name' => 'استخدام و خدمات',         'icon' => 'bi bi-briefcase',   'sort_order' => 8],
    ];
}

function swaapin_category_tree(): array {
    return [
        // ─────────────────────────────────────────────── 1. املاک
        'real-estate' => [
            'name' => 'املاک', 'icon' => 'bi bi-buildings', 'sort_order' => 1,
            'children' => [
                ['slug' => 'apartment',      'name' => 'آپارتمان',            'sort_order' => 1],
                ['slug' => 'villa',          'name' => 'خانه و ویلا',          'sort_order' => 2],
                ['slug' => 'land',           'name' => 'زمین و کلنگی',         'sort_order' => 3],
                ['slug' => 'commercial',     'name' => 'ملک اداری و تجاری',    'sort_order' => 4],
                ['slug' => 'apartment-rent', 'name' => 'اجاره آپارتمان',       'sort_order' => 5],
                ['slug' => 'villa-rent',     'name' => 'اجاره خانه و ویلا',    'sort_order' => 6],
            ]
        ],
        // ─────────────────────────────────────────────── 2. وسایل نقلیه
        'vehicles' => [
            'name' => 'وسایل نقلیه', 'icon' => 'bi bi-car-front', 'sort_order' => 2,
            'children' => [
                ['slug' => 'car',            'name' => 'خودرو',                    'sort_order' => 1],
                ['slug' => 'motorcycles',    'name' => 'موتورسیکلت',                'sort_order' => 2],
                ['slug' => 'heavy-vehicles', 'name' => 'خودرو سنگین و ماشین‌آلات', 'sort_order' => 3],
                ['slug' => 'car-parts',      'name' => 'قطعات و لوازم جانبی خودرو', 'sort_order' => 4],
            ]
        ],
        // ─────────────────────────────────────────────── 3. کالای دیجیتال
        'electronics' => [
            'name' => 'کالای دیجیتال', 'icon' => 'bi bi-phone', 'sort_order' => 3,
            'children' => [
                ['slug' => 'mobile-phones',      'name' => 'گوشی موبایل',               'sort_order' => 1],
                ['slug' => 'tablet',             'name' => 'تبلت',                      'sort_order' => 2],
                ['slug' => 'laptop',             'name' => 'لپ‌تاپ',                    'sort_order' => 3],
                ['slug' => 'computer',           'name' => 'کامپیوتر و قطعات',          'sort_order' => 4],
                ['slug' => 'game-consoles',      'name' => 'کنسول بازی',                'sort_order' => 5],
                ['slug' => 'simcards',           'name' => 'سیم‌کارت',                  'sort_order' => 6],
                ['slug' => 'mobile-accessories', 'name' => 'لوازم جانبی موبایل',         'sort_order' => 7],
                ['slug' => 'audio-video',        'name' => 'لوازم صوتی و تصویری',       'sort_order' => 8],
                ['slug' => 'camera',             'name' => 'دوربین عکاسی و فیلم‌برداری', 'sort_order' => 9],
            ]
        ],
        // ─────────────────────────────────────────────── 4. خانه و آشپزخانه
        'home-kitchen' => [
            'name' => 'لوازم خانه و آشپزخانه', 'icon' => 'bi bi-house-heart', 'sort_order' => 4,
            'children' => [
                ['slug' => 'furniture',       'name' => 'مبلمان و دکور',          'sort_order' => 1],
                ['slug' => 'carpet',          'name' => 'فرش و منسوجات خانگی',    'sort_order' => 2],
                ['slug' => 'home-appliances', 'name' => 'لوازم خانگی',           'sort_order' => 3],
                ['slug' => 'kitchenware',     'name' => 'ظروف و لوازم آشپزخانه', 'sort_order' => 4],
            ]
        ],
        // ─────────────────────────────────────────────── 5. وسایل شخصی
        'personal-items' => [
            'name' => 'وسایل شخصی', 'icon' => 'bi bi-person-badge', 'sort_order' => 5,
            'children' => [
                ['slug' => 'women-clothing', 'name' => 'لباس زنانه',            'sort_order' => 1],
                ['slug' => 'men-clothing',   'name' => 'لباس مردانه',            'sort_order' => 2],
                ['slug' => 'watches',        'name' => 'ساعت',                   'sort_order' => 3],
                ['slug' => 'jewellery',      'name' => 'زیورآلات و جواهرات',     'sort_order' => 4],
                ['slug' => 'shoes',          'name' => 'کفش',                    'sort_order' => 5],
                ['slug' => 'baby-kids',      'name' => 'لوازم کودک و نوزاد',     'sort_order' => 6],
                ['slug' => 'beauty',         'name' => 'لوازم آرایشی و بهداشتی', 'sort_order' => 7],
                ['slug' => 'accessories',    'name' => 'کیف، عینک و اکسسوری',    'sort_order' => 8],
            ]
        ],
        // ─────────────────────────────────────────────── 6. سرگرمی و فراغت
        'leisure-hobbies' => [
            'name' => 'سرگرمی و فراغت', 'icon' => 'bi bi-joystick', 'sort_order' => 6,
            'children' => [
                ['slug' => 'toys',                'name' => 'اسباب‌بازی',            'sort_order' => 1],
                ['slug' => 'books',               'name' => 'کتاب و مجله',          'sort_order' => 2],
                ['slug' => 'sports',              'name' => 'لوازم ورزشی',          'sort_order' => 3],
                ['slug' => 'bicycles',            'name' => 'دوچرخه',               'sort_order' => 4],
                ['slug' => 'musical-instruments', 'name' => 'آلات موسیقی',          'sort_order' => 5],
                ['slug' => 'pets',                'name' => 'حیوانات خانگی',        'sort_order' => 6],
                ['slug' => 'plants',              'name' => 'گل و گیاه',            'sort_order' => 7],
                ['slug' => 'art-collectibles',    'name' => 'آثار هنری و کلکسیونی', 'sort_order' => 8],
            ]
        ],
        // ─────────────────────────────────────────────── 7. مصالح، ابزار و تجهیزات
        'tools-equipment' => [
            'name' => 'مصالح، ابزار و تجهیزات', 'icon' => 'bi bi-hammer', 'sort_order' => 7,
            'children' => [
                ['slug' => 'building-materials',   'name' => 'مصالح ساختمانی',                    'sort_order' => 1],
                ['slug' => 'tools',                'name' => 'ابزارآلات',                         'sort_order' => 2],
                ['slug' => 'industrial-equipment', 'name' => 'تجهیزات صنعتی، پزشکی و آزمایشگاهی', 'sort_order' => 3],
            ]
        ],
        // ─────────────────────────────────────────────── 8. استخدام و خدمات
        'jobs-services' => [
            'name' => 'استخدام و خدمات', 'icon' => 'bi bi-briefcase', 'sort_order' => 8,
            'children' => [
                ['slug' => 'jobs',     'name' => 'آگهی استخدام', 'sort_order' => 1],
                ['slug' => 'services', 'name' => 'خدمات',        'sort_order' => 2],
            ]
        ],
    ];
}

function swaapin_build_icon_map(): array {
    $icons = [];
    $seed = wizard_category_seed();
    foreach ($seed as $slug => $info) {
        $icons[$slug] = $info['icon'];
    }
    $icons += [
        // ── املاک
        'apartment' => 'bi bi-building',
        'villa' => 'bi bi-house-door',
        'land' => 'bi bi-geo-alt-fill',
        'commercial' => 'bi bi-building-check',
        'apartment-rent' => 'bi bi-key-fill',
        'villa-rent' => 'bi bi-key-fill',
        // ── وسایل نقلیه
        'car' => 'bi bi-car-front-fill',
        'motorcycles' => 'bi bi-bicycle',
        'heavy-vehicles' => 'bi bi-truck',
        'car-parts' => 'bi bi-gear-wide-connected',
        // ── کالای دیجیتال
        'mobile-phones' => 'bi bi-phone',
        'tablet' => 'bi bi-tablet',
        'laptop' => 'bi bi-laptop',
        'computer' => 'bi bi-pc-display',
        'game-consoles' => 'bi bi-controller',
        'simcards' => 'bi bi-sim',
        'mobile-accessories' => 'bi bi-earbuds',
        'audio-video' => 'bi bi-film',
        'camera' => 'bi bi-camera',
        // ── خانه و آشپزخانه
        'furniture' => 'bi bi-lamp',
        'carpet' => 'bi bi-layers',
        'home-appliances' => 'bi bi-tv',
        'kitchenware' => 'bi bi-cup-hot',
        // ── وسایل شخصی
        'women-clothing' => 'bi bi-person-dress',
        'men-clothing' => 'bi bi-person',
        'watches' => 'bi bi-watch',
        'jewellery' => 'bi bi-gem',
        'shoes' => 'bi bi-bag-check-fill',
        'baby-kids' => 'bi bi-person-heart',
        'beauty' => 'bi bi-magic',
        'accessories' => 'bi bi-bag-heart',
        // ── سرگرمی و فراغت
        'toys' => 'bi bi-puzzle',
        'books' => 'bi bi-book',
        'sports' => 'bi bi-trophy',
        'bicycles' => 'bi bi-bicycle',
        'musical-instruments' => 'bi bi-music-note-list',
        'pets' => 'bi bi-heart-pulse',
        'plants' => 'bi bi-flower1',
        'art-collectibles' => 'bi bi-palette',
        // ── مصالح، ابزار و تجهیزات
        'building-materials' => 'bi bi-brick',
        'tools' => 'bi bi-tools',
        'industrial-equipment' => 'bi bi-gear-wide-connected',
        // ── استخدام و خدمات
        'jobs' => 'bi bi-briefcase-fill',
        'services' => 'bi bi-tools',
    ];
    return $icons;
}

function swaapin_upsert_category(array $row, ?int $parentId): int {
    $slug       = (string)($row['slug'] ?? '');
    $name       = (string)($row['name'] ?? '');
    $sortOrder  = (int)($row['sort_order'] ?? 0);
    $iconMap    = swaapin_build_icon_map();
    $icon       = $row['icon'] ?? ($iconMap[$slug] ?? 'bi bi-tag');
    if ($slug === '' || $name === '') return 0;

    $existing = DB::fetch("SELECT id FROM categories WHERE slug = ? LIMIT 1", [$slug]);
    if ($existing) {
        DB::query(
            "UPDATE categories SET name = ?, icon = ?, sort_order = ?, is_active = 1, parent_id = ? WHERE id = ?",
            [$name, $icon, $sortOrder, $parentId, (int)$existing['id']]
        );
        return (int)$existing['id'];
    }
    DB::query(
        "INSERT INTO categories (parent_id, name, slug, icon, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)",
        [$parentId, $name, $slug, $icon, $sortOrder]
    );
    return (int)DB::lastId();
}

function swaapin_ensure_category_tree(): array {
    $tree = swaapin_category_tree();
    $idsBySlug = [];
    foreach ($tree as $topSlug => $topNode) {
        $topId = swaapin_upsert_category([
            'slug' => $topSlug,
            'name' => $topNode['name'],
            'sort_order' => (int)($topNode['sort_order'] ?? 0),
            'icon' => $topNode['icon'] ?? null,
        ], null);
        if ($topId <= 0) continue;
        $idsBySlug[$topSlug] = $topId;
        $level2 = $topNode['children'] ?? [];
        foreach ($level2 as $l2Idx => $l2Node) {
            $l2Slug = (string)($l2Node['slug'] ?? '');
            if ($l2Slug === '') continue;
            $l2Id = swaapin_upsert_category([
                'slug' => $l2Slug,
                'name' => (string)($l2Node['name'] ?? ''),
                'sort_order' => (int)($l2Node['sort_order'] ?? ($l2Idx + 1)),
                'icon' => $l2Node['icon'] ?? null,
            ], $topId);
            if ($l2Id <= 0) continue;
            $idsBySlug[$l2Slug] = $l2Id;
            $level3 = $l2Node['children'] ?? [];
            foreach ($level3 as $l3Idx => $l3Node) {
                $l3Slug = (string)($l3Node['slug'] ?? '');
                if ($l3Slug === '') continue;
                $l3Id = swaapin_upsert_category([
                    'slug' => $l3Slug,
                    'name' => (string)($l3Node['name'] ?? ''),
                    'sort_order' => (int)($l3Node['sort_order'] ?? ($l3Idx + 1)),
                    'icon' => $l3Node['icon'] ?? null,
                ], $l2Id);
                if ($l3Id > 0) {
                    $idsBySlug[$l3Slug] = $l3Id;
                }
            }
        }
    }

    $allowedSlugs = array_keys($idsBySlug);
    if (!empty($allowedSlugs)) {
        $placeholders = implode(',', array_fill(0, count($allowedSlugs), '?'));
        try {
            DB::query(
                "UPDATE categories SET is_active = 0 WHERE slug NOT IN ({$placeholders}) AND is_active = 1",
                $allowedSlugs
            );
        } catch (Throwable $e) {
            swapin_debug_log('cat_tree_deactivate_skip', ['msg' => $e->getMessage()]);
        }
    }
    try {
        DB::query("UPDATE categories SET is_active = 0 WHERE slug = 'other' AND is_active = 1");
    } catch (Throwable $e) {}

    return $idsBySlug;
}

function swaapin_category_descendant_ids(int $categoryId): array {
    // نقشه فرزندان فقط یک‌بار در هر درخواست ساخته می‌شود (به‌جای یک کوئری به‌ازای هر گره)
    static $childrenMap = null;
    if ($childrenMap === null) {
        $childrenMap = [];
        try {
            $rows = DB::fetchAll('SELECT id, parent_id FROM categories WHERE is_active = 1');
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $pid = (int)($r['parent_id'] ?? 0);
            $childrenMap[$pid][] = (int)$r['id'];
        }
    }

    $ids = [$categoryId];
    $queue = [$categoryId];
    $safety = 2000;
    while (!empty($queue) && $safety-- > 0) {
        $pid = (int)array_shift($queue);
        foreach ($childrenMap[$pid] ?? [] as $cid) {
            if (!in_array($cid, $ids, true)) {
                $ids[] = $cid;
                $queue[] = $cid;
            }
        }
    }
    return $ids;
}

function wizard_ensure_parents_exist(): array {
    try {
        $allBySlug = swaapin_ensure_category_tree();
    } catch (Throwable $e) {
        swapin_debug_log('cat_tree_ensure_fail', ['msg' => $e->getMessage()]);
        $allBySlug = [];
    }
    $parentIds = [];
    $parents = DB::fetchAll('SELECT id, slug FROM categories WHERE (parent_id IS NULL OR parent_id = 0) AND is_active = 1');
    foreach ($parents as $p) {
        $parentIds[(string)$p['slug']] = (int)$p['id'];
    }
    foreach ($allBySlug as $s => $id) {
        if (!isset($parentIds[$s])) {
            $row = DB::fetch('SELECT id, parent_id FROM categories WHERE id = ?', [(int)$id]);
            if ($row && (($row['parent_id'] ?? 0) == 0)) {
                $parentIds[(string)$s] = (int)$id;
            }
        }
    }
    return $parentIds;
}

function render_wizard_category_options(array $categoriesIgnored = [], int $selectedId = 0, bool $rootOnly = false): string {
    try {
        $idsBySlug = swaapin_ensure_category_tree();
    } catch (Throwable $e) {
        swapin_debug_log('wizard_cat_tree_fail', ['msg' => $e->getMessage()]);
        $idsBySlug = [];
    }
    $rows = DB::fetchAll('SELECT id, name, slug, parent_id FROM categories WHERE is_active = 1 ORDER BY sort_order, id');
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;

    $pathOf = function (int $id) use ($byId): string {
        $parts = [];
        $cur = $id;
        $s = 30;
        while ($cur > 0 && $s-- > 0 && isset($byId[$cur])) {
            $parts[] = (string)$byId[$cur]['name'];
            $pid = (int)($byId[$cur]['parent_id'] ?? 0);
            if ($pid <= 0) break;
            $cur = $pid;
        }
        $parts = array_reverse($parts);
        return implode(' › ', $parts);
    };

    $leaves = [];
    $parentsOf = [];
    foreach ($rows as $r) {
        $pid = (int)($r['parent_id'] ?? 0);
        if ($pid > 0) {
            $parentsOf[$pid][] = (int)$r['id'];
        }
    }
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if (empty($parentsOf[$id] ?? [])) {
            $leaves[$id] = $r;
        }
    }

    if ($rootOnly) {
        $leaves = [];
        foreach ($rows as $r) {
            if ((int)($r['parent_id'] ?? 0) === 0) {
                $leaves[(int)$r['id']] = $r;
            }
        }
    }

    uasort($leaves, function ($a, $b) use ($byId, $idsBySlug): int {
        $chainA = $chainB = [];
        $c = (int)$a['id'];
        $s = 20;
        while ($c > 0 && $s-- > 0 && isset($byId[$c])) {
            $chainA[] = (int)($byId[$c]['sort_order'] ?? 0);
            $c = (int)($byId[$c]['parent_id'] ?? 0);
        }
        $chainA = array_reverse($chainA);
        $c = (int)$b['id'];
        $s = 20;
        while ($c > 0 && $s-- > 0 && isset($byId[$c])) {
            $chainB[] = (int)($byId[$c]['sort_order'] ?? 0);
            $c = (int)($byId[$c]['parent_id'] ?? 0);
        }
        $chainB = array_reverse($chainB);
        $n = min(count($chainA), count($chainB));
        for ($i = 0; $i < $n; $i++) {
            if ($chainA[$i] !== $chainB[$i]) return $chainA[$i] <=> $chainB[$i];
        }
        return count($chainA) <=> count($chainB);
    });

    $html = '<option value="">— انتخاب دسته‌بندی —</option>';
    foreach ($leaves as $id => $r) {
        $label = $pathOf((int)$id);
        $sel = ((int)$r['id'] === $selectedId) ? ' selected' : '';
        $html .= '<option value="' . (int)$id . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

function wizard_validate_category_id(int $categoryId): bool {
    if ($categoryId <= 0) return false;
    try {
        swaapin_ensure_category_tree();
    } catch (Throwable) {}
    $row = DB::fetch('SELECT id, is_active FROM categories WHERE id = ? LIMIT 1', [$categoryId]);
    return ($row && (int)($row['is_active'] ?? 0) === 1);
}

function wizard_validate_root_category_id(int $categoryId): bool {
    if ($categoryId <= 0) {
        return false;
    }
    try {
        swaapin_ensure_category_tree();
    } catch (Throwable) {}
    $row = DB::fetch('SELECT id, parent_id, is_active FROM categories WHERE id = ? LIMIT 1', [$categoryId]);
    return $row
        && (int)($row['is_active'] ?? 0) === 1
        && (int)($row['parent_id'] ?? 0) <= 0;
}

function want_type_label(string $type): string {
    return match ($type) {
        'item'    => 'کالا',
        'service' => 'خدمات',
        'credit'  => 'اعتبار',
        'any'     => 'هر نوع',
        default   => $type,
    };
}

function offer_status_label(string $status): string {
    return match ($status) {
        'pending'         => 'در انتظار',
        'negotiating'     => 'در حال مذاکره',
        'counter_offered' => 'پیشنهاد جدید',
        'accepted'        => 'پذیرفته‌شده',
        'rejected'        => 'رد شده',
        'cancelled'       => 'لغو شده',
        'completed'       => 'تکمیل‌شده',
        default           => $status,
    };
}

function trade_status_label(string $status): string {
    return match ($status) {
        'in_progress'     => 'در حال انجام',
        'user_a_confirmed' => 'تأیید طرف اول',
        'user_b_confirmed' => 'تأیید طرف دوم',
        'disputed'        => 'اختلاف',
        'completed'       => 'تکمیل‌شده',
        default           => $status,
    };
}

function tx_type_label(string $type): array {
    return match ($type) {
        'deposit'      => ['arrow-down-circle', 'واریز', 'success'],
        'withdraw'     => ['arrow-up-circle', 'برداشت', 'danger'],
        'trade_credit' => ['arrow-down-circle', 'دریافت معامله', 'success'],
        'trade_debit'  => ['arrow-up-circle', 'پرداخت معامله', 'danger'],
        'fee'          => ['dash-circle', 'کارمزد', 'warning'],
        'refund'       => ['arrow-counterclockwise', 'بازگشت وجه', 'info'],
        'subscription' => ['gem', 'خرید اشتراک', 'warning'],
        'promotion'    => ['rocket-takeoff', 'ارتقای آگهی', 'info'],
        'store_order'  => ['bag-check', 'خرید از فروشگاه', 'success'],
        default        => ['circle', 'تراکنش', 'info'],
    };
}



/**
 * Convert Gregorian date to Jalali (Persian) date
 * Source: https://github.com/sallar/jDateTime/blob/master/src/jDateTime.php
 * @param int $gy Gregorian year
 * @param int $gm Gregorian month
 * @param int $gd Gregorian day
 * @return array [year, month, day] in Jalali
 */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $jm = 1;
    $j_d_m = [0, 31, 62, 93, 124, 155, 186, 216, 246, 276, 306, 336];
    while ($jm < 12 && $days >= $j_d_m[$jm]) $jm++;
    $jd = 1 + $days - $j_d_m[$jm - 1];
    return [$jy, $jm, $jd];
}

/**
 * Get Persian (Jalali) month name
 * @param int $month Month number (1-12)
 * @return string Month name in Persian
 */
function persian_jalali_month(int $month): string {
  return [
    '', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد',
    'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
  ][$month] ?? '';
}

/**
 * Format date as Persian (Jalali) date
 * @param string|int $datetime DateTime string or timestamp
 * @return string Formatted Persian date
 */
function persian_date(string|int $datetime): string {
  $timestamp = is_int($datetime) ? $datetime : strtotime($datetime);
  [$jy, $jm, $jd] = gregorian_to_jalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
  return persian_digits(persian_jalali_month($jm) . ' ' . $jd . '، ' . $jy);
}

/**
 * Format datetime as Persian (Jalali) date and time
 * @param string|int $datetime DateTime string or timestamp
 * @return string Formatted Persian datetime
 */
function persian_datetime(string|int|null $datetime): string {
  if ($datetime === null || $datetime === '') {
    return '—';
  }
  $timestamp = is_int($datetime) ? $datetime : strtotime((string)$datetime);
  if ($timestamp === false) {
    return '—';
  }
  [$jy, $jm, $jd] = gregorian_to_jalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
  return persian_digits(persian_jalali_month($jm) . ' ' . $jd . '، ' . $jy . ' — ' . date('G:i', $timestamp));
}

/**
 * Convert Jalali (Persian) date to Gregorian
 * @param int $jy Jalali year
 * @param int $jm Jalali month
 * @param int $jd Jalali day
 * @return array [year, month, day] in Gregorian
 */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array {
  $jy += 1595;
  $days = -355668 + (365 * $jy) + ((int)($jy / 33)) * 8 + ((int)((($jy % 33) + 3) / 4)) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
  $gy = 400 * ((int)($days / 146097));
  $days %= 146097;
  if ($days > 36524) {
    $gy += 100 * ((int)(--$days / 36524));
    $days %= 36524;
    if ($days >= 365) $days++;
  }
  $gy += 4 * ((int)($days / 1461));
  $days %= 1461;
  if ($days > 365) {
    $gy += (int)(($days - 1) / 365);
    $days = ($days - 1) % 365;
  }
  $gd = $days + 1;
  $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  $gm = 0;
  while ($gm < 13 && $gd > $sal_a[$gm]) {
    $gd -= $sal_a[$gm++];
  }
  return [$gy, $gm, $gd];
}

/**
 * Parse Jalali date input (e.g. 1404/04/23) to Gregorian Y-m-d for DB storage.
 */
function parse_jalali_date_input(string $input): ?string {
    $input = trim(str_replace(['-', '.'], '/', $input));
    $input = strtr($input, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $input = strtr($input, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $input, $m)) {
        return null;
    }
    [$gy, $gm, $gd] = jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
    if ($gm < 1 || $gm > 12 || $gd < 1 || $gd > 31) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * Parse shipping date from Jalali (1404/04/23) or Gregorian (2025-07-13) input.
 */
function parse_shipping_date_input(string $input): ?string {
    $jalali = parse_jalali_date_input($input);
    if ($jalali) {
        return $jalali;
    }
    $input = trim($input);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        return $input;
    }
    return null;
}

function shipping_label(string $method): string {
  return match ($method) {
    'in_person' => 'تحویل حضوری',
    'post'      => 'پست',
    'tipax'     => 'تیپاکس',
    'courier'   => 'پیک',
    'swapin_secure' => 'تحویل حضوری (ارسال در محل)',
    default     => $method,
  };
}

function render_wizard_category_picker(int $selectedId = 0, bool $rootsOnly = false): string {
    try {
        swaapin_ensure_category_tree();
    } catch (Throwable $e) {
        swapin_debug_log('wizard_cat_picker_tree_fail', ['msg' => $e->getMessage()]);
    }
    $all = DB::fetchAll('SELECT id, name, slug, parent_id, icon FROM categories WHERE is_active = 1 ORDER BY sort_order, id');
    $byId = [];
    $byParent = [];
    foreach ($all as $r) {
        $id = (int)$r['id'];
        $byId[$id] = $r;
        $pid = (int)($r['parent_id'] ?? 0);
        $byParent[$pid][] = $r;
    }
    $topLevel = $byParent[0] ?? [];

    $selLabel = 'انتخاب دسته‌بندی…';
    if ($selectedId > 0 && isset($byId[$selectedId])) {
        $parts = [];
        $cur = $byId[$selectedId];
        $safety = 20;
        while ($cur && $safety-- > 0) {
            $parts[] = (string)$cur['name'];
            $pid = (int)($cur['parent_id'] ?? 0);
            if ($pid <= 0) break;
            $cur = $byId[$pid] ?? null;
        }
        $parts = array_reverse($parts);
        $selLabel = implode(' › ', $parts);
    }

    $renderer = null;
    $renderer = function (array $nodes, int $depth) use (&$renderer, &$byParent, $selectedId, $byId): string {
        $html = '';
        foreach ($nodes as $cat) {
            $cid = (int)$cat['id'];
            $label = category_label($cat['slug'], $cat['name']);
            $iconHtml = $depth === 0 ? '<i class="cat-pick-icon ' . h($cat['icon'] ?? 'bi bi-collection') . '"></i> ' : '';
            $kids = $byParent[$cid] ?? [];
            $isSelected = $selectedId === $cid ? ' cat-pick-selected' : '';

            if (empty($kids)) {
                $html .= '<a href="javascript:void(0)" class="dropdown-item cat-pick-leaf' . $isSelected . '" data-cat-id="' . $cid . '" data-cat-label="' . h($label) . '">'
                    . $iconHtml . h($label) . '</a>';
            } else {
                $chevron = '<i class="bi bi-chevron-left cat-pick-chevron"></i>';
                $kidsHtml = $renderer($kids, $depth + 1);
                $html .= '<div class="dropdown-submenu">'
                    . '<a href="javascript:void(0)" class="dropdown-item dropdown-item--parent cat-pick-parent">'
                    . $iconHtml . h($label) . ' ' . $chevron . '</a>'
                    . '<div class="dropdown-menu dropdown-menu--sub">' . $kidsHtml . '</div>'
                    . '</div>';
            }
        }
        return $html;
    };

    $topHtml = '';
    foreach ($topLevel as $cat) {
        $cid = (int)$cat['id'];
        $label = category_label($cat['slug'], $cat['name']);
        $iconHtml = '<i class="cat-pick-icon ' . h($cat['icon'] ?? 'bi bi-collection') . '"></i> ';
        $kids = $byParent[$cid] ?? [];
        $isSelected = $selectedId === $cid ? ' cat-pick-selected' : '';

        if ($rootsOnly || empty($kids)) {
            $topHtml .= '<a href="javascript:void(0)" class="dropdown-item cat-pick-leaf' . $isSelected . '" data-cat-id="' . $cid . '" data-cat-label="' . h($label) . '">'
                . $iconHtml . h($label) . '</a>';
        } else {
            $chevron = '<i class="bi bi-chevron-left cat-pick-chevron"></i>';
            $kidsHtml = $renderer($kids, 1);
            $topHtml .= '<div class="dropdown-submenu">'
                . '<a href="javascript:void(0)" class="dropdown-item dropdown-item--parent cat-pick-parent">'
                . $iconHtml . h($label) . ' ' . $chevron . '</a>'
                . '<div class="dropdown-menu dropdown-menu--sub">' . $kidsHtml . '</div>'
                . '</div>';
        }
    }

    $html = '<div class="wizard-category-picker" id="wizard-category-picker">';
    $html .= '<div class="cat-pick-trigger" id="cat-pick-trigger" data-empty="' . ($selectedId === 0 ? '1' : '0') . '">';
    $html .= '<i class="bi bi-grid-3x3-gap-fill cat-pick-trigger-icon"></i>';
    $html .= '<span class="cat-pick-trigger-label" id="cat-pick-label">' . h($selLabel) . '</span>';
    $html .= '<i class="bi bi-chevron-down cat-pick-trigger-caret"></i>';
    $html .= '</div>';
    $html .= '<div class="dropdown-menu cat-pick-menu categories-dropdown" id="cat-pick-menu">' . $topHtml . '</div>';
    $html .= '<input type="hidden" name="category_id" id="step3-category-hidden" value="' . $selectedId . '">';
    $html .= '</div>';
    return $html;
}
