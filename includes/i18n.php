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
function avatar_html(?string $avatar, string $name, string $size = 'md'): string {
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

function category_label(string $slug, string $name = ''): string {
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

function wizard_allowed_category_slugs(): array {
    return [
        'real-estate',
        'vehicles',
        'electronics',
        'home-kitchen',
        'services',
        'personal-items',
        'leisure-hobbies',
        'community',
        'tools-equipment',
        'jobs',
    ];
}

function wizard_category_seed(): array {
    return [
        'real-estate'       => ['name' => 'املاک',                'icon' => 'bi bi-buildings',   'sort_order' => 1],
        'vehicles'          => ['name' => 'وسایل نقلیه',          'icon' => 'bi bi-car-front',   'sort_order' => 2],
        'electronics'       => ['name' => 'کالای دیجیتال',        'icon' => 'bi bi-phone',       'sort_order' => 3],
        'home-kitchen'      => ['name' => 'خانه و آشپزخانه',      'icon' => 'bi bi-house-heart', 'sort_order' => 4],
        'services'          => ['name' => 'خدمات',                'icon' => 'bi bi-tools',       'sort_order' => 5],
        'personal-items'    => ['name' => 'وسایل شخصی',           'icon' => 'bi bi-person-badge','sort_order' => 6],
        'leisure-hobbies'   => ['name' => 'سرگرمی و فراغت',       'icon' => 'bi bi-joystick',    'sort_order' => 7],
        'community'         => ['name' => 'اجتماعی',              'icon' => 'bi bi-people',      'sort_order' => 8],
        'tools-equipment'   => ['name' => 'تجهیزات و صنعتی',      'icon' => 'bi bi-hammer',      'sort_order' => 9],
        'jobs'              => ['name' => 'استخدام و کاریابی',    'icon' => 'bi bi-briefcase',   'sort_order' => 10],
    ];
}

function swaapin_category_tree(): array {
    return [
        // ─────────────────────────────────────────────── 1. املاک
        'real-estate' => [
            'name' => 'املاک', 'icon' => 'bi bi-buildings', 'sort_order' => 1,
            'children' => [
                ['slug' => 'residential-apartment',  'name' => 'آپارتمان',           'sort_order' => 1,
                    'children' => [
                        ['slug' => 'apartment-for-sale',   'name' => 'آپارتمان برای فروش',   'sort_order' => 1],
                        ['slug' => 'apartment-for-rent',   'name' => 'آپارتمان برای اجاره',  'sort_order' => 2],
                    ]
                ],
                ['slug' => 'residential-villa',      'name' => 'خانه و ویلا',        'sort_order' => 2,
                    'children' => [
                        ['slug' => 'villa-for-sale',       'name' => 'خانه و ویلای فروش',   'sort_order' => 1],
                        ['slug' => 'villa-for-rent',       'name' => 'خانه و ویلای اجاره',  'sort_order' => 2],
                    ]
                ],
                ['slug' => 'commercial-office',     'name' => 'اداری و تجاری',      'sort_order' => 3],
                ['slug' => 'industrial-land',       'name' => 'زمین و کلنگی',       'sort_order' => 4],
                ['slug' => 'short-term-rentals',    'name' => 'اجاره موقت',         'sort_order' => 5],
            ]
        ],
        // ─────────────────────────────────────────────── 2. وسایل نقلیه
        'vehicles' => [
            'name' => 'وسایل نقلیه', 'icon' => 'bi bi-car-front', 'sort_order' => 2,
            'children' => [
                ['slug' => 'cars',                 'name' => 'خودرو',              'sort_order' => 1,
                    'children' => [
                        ['slug' => 'passenger-cars',     'name' => 'سواری',                'sort_order' => 1],
                        ['slug' => 'classic-cars',       'name' => 'کلاسیک',                'sort_order' => 2],
                        ['slug' => 'rental-cars',        'name' => 'خودرو اجاره‌ای',        'sort_order' => 3],
                    ]
                ],
                ['slug' => 'motorcycles',          'name' => 'موتورسیکلت',         'sort_order' => 2],
                ['slug' => 'commercial-vehicles',  'name' => 'خودروهای باربری و کاری', 'sort_order' => 3],
                ['slug' => 'heavy-equipment',      'name' => 'سنگین و ساختمانی',   'sort_order' => 4],
                ['slug' => 'car-parts',            'name' => 'قطعات و لوازم جانبی خودرو', 'sort_order' => 5],
                ['slug' => 'bicycles',             'name' => 'دوچرخه',             'sort_order' => 6],
                ['slug' => 'car-rental-services',  'name' => 'اجاره خودرو',        'sort_order' => 7],
            ]
        ],
        // ─────────────────────────────────────────────── 3. کالای دیجیتال
        'electronics' => [
            'name' => 'کالای دیجیتال', 'icon' => 'bi bi-phone', 'sort_order' => 3,
            'children' => [
                ['slug' => 'mobile-tablet',        'name' => 'موبایل و تبلت',      'sort_order' => 1,
                    'children' => [
                        ['slug' => 'mobile-phones',      'name' => 'موبایل',                 'sort_order' => 1],
                        ['slug' => 'tablets',            'name' => 'تبلت',                   'sort_order' => 2],
                        ['slug' => 'mobile-accessories', 'name' => 'لوازم جانبی موبایل و تبلت', 'sort_order' => 3],
                        ['slug' => 'simcards',           'name' => 'سیم‌کارت',              'sort_order' => 4],
                    ]
                ],
                ['slug' => 'computers',            'name' => 'رایانه',              'sort_order' => 2,
                    'children' => [
                        ['slug' => 'laptops',            'name' => 'لپ‌تاپ',                 'sort_order' => 1],
                        ['slug' => 'desktops',           'name' => 'رایانه رومیزی',          'sort_order' => 2],
                        ['slug' => 'computer-parts',     'name' => 'قطعات و لوازم جانبی',    'sort_order' => 3],
                        ['slug' => 'networking',         'name' => 'مودم و تجهیزات شبکه',    'sort_order' => 4],
                        ['slug' => 'printers-scanners',  'name' => 'پرینتر، اسکنر و کپی',    'sort_order' => 5],
                    ]
                ],
                ['slug' => 'console-gaming',       'name' => 'کنسول و بازی',       'sort_order' => 3],
                ['slug' => 'audio-visual',         'name' => 'صوتی و تصویری',      'sort_order' => 4,
                    'children' => [
                        ['slug' => 'music-movies',       'name' => 'فیلم و موسیقی',         'sort_order' => 1],
                        ['slug' => 'cameras',            'name' => 'دوربین عکاسی و فیلم‌برداری', 'sort_order' => 2],
                        ['slug' => 'headphones-speakers','name' => 'هدفون، اسپیکر و میکروفون', 'sort_order' => 3],
                        ['slug' => 'home-audio',         'name' => 'سیستم صوتی خانگی',       'sort_order' => 4],
                        ['slug' => 'dvd-blu-ray',        'name' => 'DVD و Blu-ray',         'sort_order' => 5],
                        ['slug' => 'tv-projector',       'name' => 'تلویزیون و پروژکتور',    'sort_order' => 6],
                        ['slug' => 'cctv',               'name' => 'دوربین مداربسته',        'sort_order' => 7],
                    ]
                ],
                ['slug' => 'landline-phones',      'name' => 'تلفن رومیزی',        'sort_order' => 5],
            ]
        ],
        // ─────────────────────────────────────────────── 4. خانه و آشپزخانه
        'home-kitchen' => [
            'name' => 'خانه و آشپزخانه', 'icon' => 'bi bi-house-heart', 'sort_order' => 4,
            'children' => [
                ['slug' => 'furniture',            'name' => 'مبلمان و دکور',     'sort_order' => 1],
                ['slug' => 'appliances',           'name' => 'لوازم خانگی',       'sort_order' => 2,
                    'children' => [
                        ['slug' => 'kitchen-appliances', 'name' => 'لوازم آشپزخانه',        'sort_order' => 1],
                        ['slug' => 'laundry-appliances', 'name' => 'لباسشویی و خشک‌کن',     'sort_order' => 2],
                        ['slug' => 'cooling-heating',    'name' => 'یخچال و فریزر، گرمایش', 'sort_order' => 3],
                        ['slug' => 'small-appliances',   'name' => 'لوازم جانبی کوچک',       'sort_order' => 4],
                    ]
                ],
                ['slug' => 'kitchenware',          'name' => 'ظروف و تجهیزات آشپزخانه', 'sort_order' => 3],
                ['slug' => 'home-textile',         'name' => 'پارچه و منسوجات خانگی', 'sort_order' => 4],
                ['slug' => 'gardening-plants',     'name' => 'باغچه و گیاهان',    'sort_order' => 5],
                ['slug' => 'building-materials',   'name' => 'تجهیزات ساختمانی و بهسازی', 'sort_order' => 6],
            ]
        ],
        // ─────────────────────────────────────────────── 5. خدمات
        'services' => [
            'name' => 'خدمات', 'icon' => 'bi bi-tools', 'sort_order' => 5,
            'children' => [
                ['slug' => 'business-financial',   'name' => 'تجاری و مالی',      'sort_order' => 1],
                ['slug' => 'home-services',        'name' => 'منزل و ساختمان',    'sort_order' => 2,
                    'children' => [
                        ['slug' => 'cleaning-services',  'name' => 'نظافت و نظافت‌چی',       'sort_order' => 1],
                        ['slug' => 'moving-services',    'name' => 'انبارداری و باربری',      'sort_order' => 2],
                        ['slug' => 'renovation',         'name' => 'تعمیرات و بازسازی',       'sort_order' => 3],
                    ]
                ],
                ['slug' => 'legal-educational',    'name' => 'آموزشی و حقوقی',    'sort_order' => 3],
                ['slug' => 'wedding-events',       'name' => 'مراسم و رویداد',    'sort_order' => 4],
                ['slug' => 'vehicles-services',    'name' => 'خودرو و موتور',     'sort_order' => 5],
                ['slug' => 'beauty-health-serv',   'name' => 'آرایشی و بهداشتی',  'sort_order' => 6],
                ['slug' => 'electronic-services',  'name' => 'تعمیرات دیجیتال',   'sort_order' => 7],
                ['slug' => 'tourism-travel',       'name' => 'گردشگری و سفر',     'sort_order' => 8],
            ]
        ],
        // ─────────────────────────────────────────────── 6. وسایل شخصی
        'personal-items' => [
            'name' => 'وسایل شخصی', 'icon' => 'bi bi-person-badge', 'sort_order' => 6,
            'children' => [
                ['slug' => 'clothing',             'name' => 'پوشاک و کفش',       'sort_order' => 1,
                    'children' => [
                        ['slug' => 'men-clothing',     'name' => 'مردانه',                 'sort_order' => 1],
                        ['slug' => 'women-clothing',   'name' => 'زنانه',                  'sort_order' => 2],
                        ['slug' => 'kids-clothing',    'name' => 'بچگانه',                 'sort_order' => 3],
                        ['slug' => 'shoes',            'name' => 'کفش و بوت',              'sort_order' => 4],
                    ]
                ],
                ['slug' => 'watches-jewelry',      'name' => 'ساعت و زیورآلات',   'sort_order' => 2],
                ['slug' => 'bags-luggage',         'name' => 'کیف و بار travel',  'sort_order' => 3],
                ['slug' => 'beauty-cosmetics',     'name' => 'آرایشی و بهداشتی',  'sort_order' => 4,
                    'children' => [
                        ['slug' => 'makeup',           'name' => 'آرایشی',                'sort_order' => 1],
                        ['slug' => 'skincare',         'name' => 'مراقبت پوست',           'sort_order' => 2],
                        ['slug' => 'haircare',         'name' => 'موی و مو',               'sort_order' => 3],
                        ['slug' => 'perfume',          'name' => 'عطر و ادکلن',            'sort_order' => 4],
                    ]
                ],
                ['slug' => 'eyeglasses',           'name' => 'عینک طبی و آفتابی', 'sort_order' => 5],
                ['slug' => 'baby-products',        'name' => 'لوازم کودک و نوزاد', 'sort_order' => 6],
            ]
        ],
        // ─────────────────────────────────────────────── 7. سرگرمی و فراغت
        'leisure-hobbies' => [
            'name' => 'سرگرمی و فراغت', 'icon' => 'bi bi-joystick', 'sort_order' => 7,
            'children' => [
                ['slug' => 'collectibles',         'name' => 'کلکسیونی و زنجیره', 'sort_order' => 1],
                ['slug' => 'books-magazines',      'name' => 'کتاب و مجلات',      'sort_order' => 2,
                    'children' => [
                        ['slug' => 'fiction',         'name' => 'داستانی و ادبیات',        'sort_order' => 1],
                        ['slug' => 'academic',        'name' => 'آموزشی و دانشگاهی',       'sort_order' => 2],
                        ['slug' => 'comics-manga',    'name' => 'کمیک و مانگا',            'sort_order' => 3],
                        ['slug' => 'children-books',  'name' => 'کودک و نوجوان',           'sort_order' => 4],
                    ]
                ],
                ['slug' => 'sports-fitness',       'name' => 'ورزش و تناسب اندام','sort_order' => 3,
                    'children' => [
                        ['slug' => 'team-sports',     'name' => 'ورزش‌های تیمی',          'sort_order' => 1],
                        ['slug' => 'fitness-gym',     'name' => 'تناسب اندام و بدنسازی',   'sort_order' => 2],
                        ['slug' => 'outdoor-sports',  'name' => 'کوهنوردی و کمپینگ',       'sort_order' => 3],
                    ]
                ],
                ['slug' => 'musical-instruments',  'name' => 'آلات موسیقی',       'sort_order' => 4],
                ['slug' => 'travel-tourism-items', 'name' => 'تور و گردشگری',     'sort_order' => 5],
                ['slug' => 'pets-animals',         'name' => 'حیوانات خانگی',     'sort_order' => 6,
                    'children' => [
                        ['slug' => 'dogs',             'name' => 'سگ',                     'sort_order' => 1],
                        ['slug' => 'cats',             'name' => 'گربه',                   'sort_order' => 2],
                        ['slug' => 'birds',            'name' => 'پرنده',                  'sort_order' => 3],
                        ['slug' => 'fish-aquarium',    'name' => 'ماهی و آکواریوم',        'sort_order' => 4],
                        ['slug' => 'pet-accessories',  'name' => 'لوازم جانبی حیوانات',    'sort_order' => 5],
                    ]
                ],
            ]
        ],
        // ─────────────────────────────────────────────── 8. اجتماعی
        'community' => [
            'name' => 'اجتماعی', 'icon' => 'bi bi-people', 'sort_order' => 8,
            'children' => [
                ['slug' => 'announcements',        'name' => 'اعلامیه‌ها و اطلاعیه‌ها', 'sort_order' => 1],
                ['slug' => 'lost-found',           'name' => 'گم‌شده و پیدا‌شده',    'sort_order' => 2],
                ['slug' => 'volunteering',         'name' => 'خیریه و داوطلبانه',    'sort_order' => 3],
                ['slug' => 'local-events',         'name' => 'رویدادهای محلی',       'sort_order' => 4],
                ['slug' => 'exchange-barter',      'name' => 'معاوضه و هدیه',        'sort_order' => 5],
            ]
        ],
        // ─────────────────────────────────────────────── 9. تجهیزات و صنعتی
        'tools-equipment' => [
            'name' => 'تجهیزات و صنعتی', 'icon' => 'bi bi-hammer', 'sort_order' => 9,
            'children' => [
                ['slug' => 'power-tools',          'name' => 'ابزار برقی',          'sort_order' => 1],
                ['slug' => 'hand-tools',           'name' => 'ابزار دستی',          'sort_order' => 2],
                ['slug' => 'industrial-machinery', 'name' => 'ماشین‌آلات صنعتی',    'sort_order' => 3],
                ['slug' => 'construction',         'name' => 'ساختمانی و مهندسی',   'sort_order' => 4],
                ['slug' => 'lab-scientific',       'name' => 'آزمایشگاهی و علمی',   'sort_order' => 5],
                ['slug' => 'medical-equipment',    'name' => 'پزشکی و درمانی',      'sort_order' => 6],
                ['slug' => 'industrial-supplies',  'name' => 'مواد اولیه صنعتی',    'sort_order' => 7],
            ]
        ],
        // ─────────────────────────────────────────────── 10. استخدام و کاریابی
        'jobs' => [
            'name' => 'استخدام و کاریابی', 'icon' => 'bi bi-briefcase', 'sort_order' => 10,
            'children' => [
                ['slug' => 'fulltime-jobs',        'name' => 'تمام‌وقت',           'sort_order' => 1],
                ['slug' => 'parttime-jobs',        'name' => 'پاره‌وقت',           'sort_order' => 2],
                ['slug' => 'remote-jobs',          'name' => 'دورکار / از راه دور', 'sort_order' => 3],
                ['slug' => 'freelance-projects',   'name' => 'فریلنس و پروژه‌ای',  'sort_order' => 4],
                ['slug' => 'internships',          'name' => 'کارآموزی',           'sort_order' => 5],
                ['slug' => 'resume-cv',            'name' => 'رزومه و درخواست کار', 'sort_order' => 6],
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
        'residential-apartment' => 'bi bi-building',
        'apartment-for-sale' => 'bi bi-tag-fill',
        'apartment-for-rent' => 'bi bi-key-fill',
        'residential-villa' => 'bi bi-house-door',
        'villa-for-sale' => 'bi bi-tag-fill',
        'villa-for-rent' => 'bi bi-key-fill',
        'commercial-office' => 'bi bi-building-check',
        'industrial-land' => 'bi bi-geo-alt-fill',
        'short-term-rentals' => 'bi bi-calendar-range',
        'cars' => 'bi bi-car-front-fill',
        'passenger-cars' => 'bi bi-car-front',
        'classic-cars' => 'bi bi-car-front',
        'rental-cars' => 'bi bi-calendar-check',
        'motorcycles' => 'bi bi-bicycle',
        'commercial-vehicles' => 'bi bi-truck',
        'heavy-equipment' => 'bi bi-truck-front',
        'car-parts' => 'bi bi-gear-wide-connected',
        'bicycles' => 'bi bi-bicycle',
        'car-rental-services' => 'bi bi-bag-check',
        'mobile-tablet' => 'bi bi-tablet-landscape',
        'mobile-phones' => 'bi bi-phone',
        'tablets' => 'bi bi-tablet',
        'mobile-accessories' => 'bi bi-earbuds',
        'simcards' => 'bi bi-sim',
        'computers' => 'bi bi-laptop',
        'laptops' => 'bi bi-laptop',
        'desktops' => 'bi bi-pc-display',
        'computer-parts' => 'bi bi-motherboard',
        'networking' => 'bi bi-wifi-2',
        'printers-scanners' => 'bi bi-printer',
        'console-gaming' => 'bi bi-controller',
        'audio-visual' => 'bi bi-film',
        'music-movies' => 'bi bi-music-note-beamed',
        'cameras' => 'bi bi-camera',
        'headphones-speakers' => 'bi bi-headphones',
        'home-audio' => 'bi bi-speaker',
        'dvd-blu-ray' => 'bi bi-disc',
        'tv-projector' => 'bi bi-tv',
        'cctv' => 'bi bi-camera-video',
        'landline-phones' => 'bi bi-telephone',
        'furniture' => 'bi bi-lamp',
        'appliances' => 'bi bi-tv',
        'kitchen-appliances' => 'bi bi-egg-fried',
        'laundry-appliances' => 'bi bi-droplet-half',
        'cooling-heating' => 'bi bi-snow',
        'small-appliances' => 'bi bi-mic',
        'kitchenware' => 'bi bi-mortarboard-pestle',
        'home-textile' => 'bi bi-backpack',
        'gardening-plants' => 'bi bi-flower1',
        'building-materials' => 'bi bi-brick',
        'business-financial' => 'bi bi-cash-stack',
        'home-services' => 'bi bi-house-gear',
        'cleaning-services' => 'bi bi-droplet',
        'moving-services' => 'bi bi-box-seam',
        'renovation' => 'bi bi-brush',
        'legal-educational' => 'bi bi-mortarboard',
        'wedding-events' => 'bi bi-balloon-heart',
        'vehicles-services' => 'bi bi-wrench-adjustable-circle',
        'beauty-health-serv' => 'bi bi-bandaid',
        'electronic-services' => 'bi bi-motherboard',
        'tourism-travel' => 'bi bi-airplane',
        'clothing' => 'bi bi-bag',
        'men-clothing' => 'bi bi-person',
        'women-clothing' => 'bi bi-person-dress',
        'kids-clothing' => 'bi bi-person-heart',
        'shoes' => 'bi bi-bag-check-fill',
        'watches-jewelry' => 'bi bi-watch',
        'bags-luggage' => 'bi bi-bag-heart',
        'beauty-cosmetics' => 'bi bi-magic',
        'makeup' => 'bi bi-eyedropper',
        'skincare' => 'bi bi-stars',
        'haircare' => 'bi bi-person-check',
        'perfume' => 'bi bi-droplet',
        'eyeglasses' => 'bi bi-eyeglasses',
        'baby-products' => 'bi bi-person-wheelchair',
        'collectibles' => 'bi bi-trophy',
        'books-magazines' => 'bi bi-book',
        'fiction' => 'bi bi-book-half',
        'academic' => 'bi bi-journal-text',
        'comics-manga' => 'bi bi-mask',
        'children-books' => 'bi bi-bookmark-star',
        'sports-fitness' => 'bi bi-trophy',
        'team-sports' => 'bi bi-bicycle',
        'fitness-gym' => 'bi bi-dumbbell',
        'outdoor-sports' => 'bi bi-mountain',
        'musical-instruments' => 'bi bi-music-note-list',
        'travel-tourism-items' => 'bi bi-suitcase',
        'pets-animals' => 'bi bi-heart-pulse',
        'dogs' => 'bi bi-bug',
        'cats' => 'bi bi-stars',
        'birds' => 'bi bi-bird',
        'fish-aquarium' => 'bi bi-water',
        'pet-accessories' => 'bi bi-bag',
        'announcements' => 'bi bi-megaphone',
        'lost-found' => 'bi bi-question-octagon',
        'volunteering' => 'bi bi-heart',
        'local-events' => 'bi bi-calendar-event',
        'exchange-barter' => 'bi bi-arrow-left-right',
        'power-tools' => 'bi bi-screwdriver',
        'hand-tools' => 'bi bi-tools',
        'industrial-machinery' => 'bi bi-gear-wide-connected',
        'construction' => 'bi bi-brick',
        'lab-scientific' => 'bi bi-boxes',
        'medical-equipment' => 'bi bi-heart-pulse',
        'industrial-supplies' => 'bi bi-box-seam',
        'fulltime-jobs' => 'bi bi-briefcase-fill',
        'parttime-jobs' => 'bi bi-clock',
        'remote-jobs' => 'bi bi-pc-display',
        'freelance-projects' => 'bi bi-diagram-3',
        'internships' => 'bi bi-mortarboard-fill',
        'resume-cv' => 'bi bi-file-earmark-person',
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

    $pidSql = $parentId === null ? ' (parent_id IS NULL OR parent_id = 0)' : ' parent_id = ? ';
    if ($parentId === null) {
        $params = [$slug];
    } else {
        $params = [$parentId, $slug];
    }
    $existing = DB::fetch("SELECT id FROM categories WHERE {$pidSql} AND slug = ? LIMIT 1", $params);
    if ($existing) {
        DB::query(
            "UPDATE categories SET name = ?, icon = ?, sort_order = ?, is_active = 1 WHERE id = ?",
            [$name, $icon, $sortOrder, (int)$existing['id']]
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
    $ids = [$categoryId];
    $queue = [$categoryId];
    $safety = 2000;
    while (!empty($queue) && $safety-- > 0) {
        $pid = (int)array_shift($queue);
        $children = DB::fetchAll('SELECT id FROM categories WHERE parent_id = ? AND is_active = 1', [$pid]);
        foreach ($children as $c) {
            $cid = (int)$c['id'];
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

function render_wizard_category_options(array $categoriesIgnored = [], int $selectedId = 0): string {
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
function persian_datetime(string|int $datetime): string {
  $timestamp = is_int($datetime) ? $datetime : strtotime($datetime);
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
