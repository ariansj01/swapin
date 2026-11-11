<?php
/**
 * سیستم مکان‌های ساختاریافته سواپین — طبق سند معماری سئو (بخش ۳ و ۶).
 *
 * پنج سطح: country > province > region > city > neighborhood
 * قواعد: اسلاگ انگلیسی کوچک، یکتا در کل سایت، جداکننده «-»، هر مکان دارای Parent.
 * آدرس کانونیکال: /{location}[/{category}]  (بدون پیشوند /s/ طبق تصمیم پروژه)
 */

function swaapin_location_levels(): array {
    return ['country', 'province', 'region', 'city', 'neighborhood'];
}

function swaapin_locations_table_exists(): bool {
    return db_has_table('locations');
}

function swaapin_ensure_locations_table(): void {
    if (swaapin_locations_table_exists()) {
        return;
    }
    try {
        DB::query(
            "CREATE TABLE IF NOT EXISTS `locations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_id` INT UNSIGNED NULL,
                `level` ENUM('country','province','region','city','neighborhood') NOT NULL,
                `name` VARCHAR(120) NOT NULL,
                `slug` VARCHAR(120) NOT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_locations_slug` (`slug`),
                KEY `idx_locations_parent` (`parent_id`),
                KEY `idx_locations_level` (`level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        swapin_debug_log('locations_create_fail', ['msg' => $e->getMessage()]);
    }
}

/** تبدیل نام فارسی مکان به اسلاگ لاتین (پایه، بدون واکه‌گذاری دقیق). */
function swaapin_transliterate(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $map = [
        'آ' => 'a', 'أ' => 'a', 'إ' => 'a', 'ا' => 'a', 'ء' => '',
        'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'z',
        'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'gh', 'ک' => 'k', 'ك' => 'k', 'گ' => 'g',
        'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'و' => 'v', 'ه' => 'h',
        'ی' => 'y', 'ي' => 'y', 'ى' => 'a', 'ئ' => 'y', 'ة' => 'h', 'ۀ' => 'h', 'ؤ' => 'o',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        "\xE2\x80\x8C" => '-',
    ];
    $text = str_replace(array_keys($map), array_values($map), $text);
    $text = preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '';
    return trim(strtolower($text), '-');
}

/**
 * اسلاگ لاتین یک مکان. برای شهرها/استان‌ها/محله‌های مهم از نقشه دستی استفاده می‌شود
 * و سایر موارد با تبدیل نویسه‌ای ساخته می‌شوند.
 */
function swaapin_location_slug(string $name, string $level): string {
    static $maps = null;
    if ($maps === null) {
        $maps = [
            'country' => [
                'ایران' => 'iran',
            ],
            'province' => [
                'آذربایجان شرقی' => 'east-azerbaijan-province',
                'آذربایجان غربی' => 'west-azerbaijan-province',
                'اردبیل' => 'ardabil-province',
                'اصفهان' => 'isfahan-province',
                'البرز' => 'alborz-province',
                'ایلام' => 'ilam-province',
                'بوشهر' => 'bushehr-province',
                'تهران' => 'tehran-province',
                'چهارمحال و بختیاری' => 'chaharmahal-and-bakhtiari-province',
                'خراسان جنوبی' => 'south-khorasan-province',
                'خراسان رضوی' => 'razavi-khorasan-province',
                'خراسان شمالی' => 'north-khorasan-province',
                'خوزستان' => 'khuzestan-province',
                'زنجان' => 'zanjan-province',
                'سمنان' => 'semnan-province',
                'سیستان و بلوچستان' => 'sistan-and-baluchestan-province',
                'فارس' => 'fars-province',
                'قزوین' => 'qazvin-province',
                'قم' => 'qom-province',
                'کردستان' => 'kurdistan-province',
                'کرمان' => 'kerman-province',
                'کرمانشاه' => 'kermanshah-province',
                'کهگیلویه و بویراحمد' => 'kohgiluyeh-and-boyer-ahmad-province',
                'گلستان' => 'golestan-province',
                'گیلان' => 'gilan-province',
                'لرستان' => 'lorestan-province',
                'مازندران' => 'mazandaran-province',
                'مرکزی' => 'markazi-province',
                'هرمزگان' => 'hormozgan-province',
                'همدان' => 'hamadan-province',
                'یزد' => 'yazd-province',
            ],
            'region' => [
                'شمال' => 'shomal',
            ],
            'city' => [
                'تهران' => 'tehran', 'کرج' => 'karaj', 'رشت' => 'rasht', 'مشهد' => 'mashhad',
                'اصفهان' => 'isfahan', 'شیراز' => 'shiraz', 'تبریز' => 'tabriz', 'اهواز' => 'ahvaz',
                'قم' => 'qom', 'کرمانشاه' => 'kermanshah', 'ارومیه' => 'urmia', 'زاهدان' => 'zahedan',
                'کرمان' => 'kerman', 'یزد' => 'yazd', 'اردبیل' => 'ardabil', 'اراک' => 'arak',
                'همدان' => 'hamadan', 'زنجان' => 'zanjan', 'ساری' => 'sari', 'بابل' => 'babol',
                'آمل' => 'amol', 'گرگان' => 'gorgan', 'قزوین' => 'qazvin', 'سنندج' => 'sanandaj',
                'خرم‌آباد' => 'khorramabad', 'بوشهر' => 'bushehr', 'بیرجند' => 'birjand', 'بجنورد' => 'bojnord',
                'شهرکرد' => 'shahrekord', 'یاسوج' => 'yasuj', 'ایلام' => 'ilam', 'سمنان' => 'semnan',
                'بندرعباس' => 'bandar-abbas', 'بندر انزلی' => 'bandar-anzali', 'لاهیجان' => 'lahijan',
                'چالوس' => 'chalus', 'نوشهر' => 'nowshahr', 'تنکابن' => 'tonekabon', 'رامسر' => 'ramsar',
                'نیشابور' => 'neyshabur', 'سبزوار' => 'sabzevar', 'کاشان' => 'kashan', 'ورامین' => 'varamin',
                'شهریار' => 'shahriar', 'پاکدشت' => 'pakdasht', 'قرچک' => 'qarchak', 'ملارد' => 'malard',
                'بروجرد' => 'borujerd', 'دزفول' => 'dezful', 'آبادان' => 'abadan', 'خرمشهر' => 'khorramshahr',
                'اندیمشک' => 'andimeshk', 'مراغه' => 'maragheh', 'مرند' => 'marand', 'خوی' => 'khoy',
                'میاندوآب' => 'miandoab', 'مهاباد' => 'mahabad', 'بوکان' => 'bukan', 'ابهر' => 'abhar',
                'ملایر' => 'malayer', 'ساوه' => 'saveh', 'شاهرود' => 'shahroud', 'دامغان' => 'damghan',
                'گرمسار' => 'garmsar', 'جهرم' => 'jahrom', 'فسا' => 'fasa', 'کازرون' => 'kazeroon',
                'مرودشت' => 'marvdasht', 'لار' => 'lar', 'بافت' => 'baft', 'رفسنجان' => 'rafsanjan',
                'سیرجان' => 'sirjan', 'زرند' => 'zarand', 'بم' => 'bam', 'جیرفت' => 'jiroft',
                'قشم' => 'qeshm', 'کیش' => 'kish', 'میناب' => 'minab', 'برازجان' => 'borazjan',
                'گنبدکاووس' => 'gonbad-e-kavous', 'بندرترکمن' => 'bandar-torkaman',
                'علی‌آباد کتول' => 'aliabad-e-katoul', 'اسلام‌شهر' => 'eslamshahr',
                'نسیم‌شهر' => 'nasimshahr', 'قائم‌شهر' => 'qaem-shahr', 'نجف‌آباد' => 'najafabad',
                'خمینی‌شهر' => 'khomeyni-shahr', 'شهرضا' => 'shahreza', 'رباط‌کریم' => 'robat-karim',
                'فردیس' => 'fardis', 'بهارستان' => 'baharestan', 'نظرآباد' => 'nazarabad',
                'پردیس' => 'pardis', 'شمشک' => 'shemeshak', 'بومهن' => 'boumehen', 'ری' => 'rey',
            ],
            'neighborhood' => [
                'ونک' => 'vanak', 'میدان ونک' => 'vanak-square', 'جردن' => 'jordan',
                'سعادت‌آباد' => 'saadatabad', 'پونک' => 'punak', 'نیاوران' => 'niavaran',
                'تجریش' => 'tajrish', 'شریعتی' => 'shariati', 'میرداماد' => 'mirdamad',
                'یوسف‌آباد' => 'yousefabad', 'امیرآباد' => 'amirabad', 'انقلاب' => 'enghelab',
                'ولیعصر' => 'valiasr', 'میدان آزادی' => 'azadi-square', 'آزادی' => 'azadi',
                'شهرک غرب' => 'shahrak-gharb', 'اکباتان' => 'ekbatan', 'تهرانپارس' => 'tehranpars',
                'نارمک' => 'narmak', 'پیروزی' => 'piroozi', 'رسالت' => 'resalat', 'افسریه' => 'afsarieh',
                'شهرری' => 'shahr-e-rey', 'چیتگر' => 'chitgar', 'صادقیه' => 'sadeghieh',
                'ستارخان' => 'sattarkhan', 'دولت' => 'dolat', 'فرمانیه' => 'farmanieh',
                'زعفرانیه' => 'zaferanieh', 'گوهردشت' => 'gohardasht', 'عظیمیه' => 'azimiyeh',
                'مهرویلا' => 'mehrvila', 'جهانشهر' => 'jahan-shahr', 'مهرشهر' => 'mehrshahr',
            ],
        ];
    }

    $name = trim($name);
    $levelMap = $maps[$level] ?? [];
    if (isset($levelMap[$name])) {
        return (string) $levelMap[$name];
    }

    $slug = swaapin_transliterate($name);
    if ($slug === '') {
        return '';
    }
    if ($level === 'province' && !str_ends_with($slug, '-province')) {
        $slug .= '-province';
    }
    return $slug;
}

/** مناطق تجمیعی چند استان (سطح region): slug => نام استان‌های زیرمجموعه. */
function swaapin_location_regions(): array {
    return [
        ['name' => 'شمال', 'slug' => 'shomal', 'provinces' => ['گیلان', 'مازندران', 'گلستان']],
    ];
}

function swaapin_location_region_provinces(string $slug): array {
    foreach (swaapin_location_regions() as $region) {
        if ($region['slug'] === $slug) {
            return $region['provinces'];
        }
    }
    return [];
}

/**
 * ساخت جدول locations و پرکردن اولیه (فقط بار اولی که جدول خالی است).
 * بازگشت: تعداد ردیف‌های جدول.
 */
function swaapin_ensure_location_tree(): int {
    static $cachedCount = null;
    if ($cachedCount !== null) {
        return $cachedCount;
    }

    swaapin_ensure_locations_table();
    if (!swaapin_locations_table_exists()) {
        $cachedCount = 0;
        return 0;
    }

    try {
        $cachedCount = (int) (DB::fetch('SELECT COUNT(*) AS c FROM locations')['c'] ?? 0);
    } catch (Throwable $e) {
        $cachedCount = 0;
        return 0;
    }
    if ($cachedCount > 0) {
        return $cachedCount;
    }

    // اسلاگ‌های دسته‌بندی و کلمات رزروشده هم باید یکتا بمانند تا مسیر مکان با آن‌ها تداخل نکند.
    // نکته: «iran» کلمه رزروشده است اما اسلاگ رسمی سطح کشور است و مستثنی می‌شود.
    $used = [];
    foreach (['list', 'v', 's', 'shop', 'blog', 'app', 'page', 'new', 'search'] as $reserved) {
        $used[$reserved] = true;
    }
    try {
        foreach (DB::fetchAll('SELECT slug FROM categories') as $row) {
            $used[strtolower((string) $row['slug'])] = true;
        }
    } catch (Throwable $e) {
    }

    $insert = function (string $level, string $name, ?int $parentId, int $sort, ?string $slugOverride = null) use (&$used): int {
        $base = $slugOverride !== null ? $slugOverride : swaapin_location_slug($name, $level);
        $base = trim(strtolower($base), '-');
        if ($base === '') {
            $base = 'loc';
        }
        $slug = $base;
        $i = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $i;
            $i++;
        }
        $used[$slug] = true;
        try {
            DB::query(
                'INSERT INTO locations (parent_id, level, name, slug, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)',
                [$parentId, $level, $name, $slug, $sort]
            );
            return (int) DB::lastId();
        } catch (Throwable $e) {
            swapin_debug_log('location_insert_fail', ['level' => $level, 'name' => $name, 'msg' => $e->getMessage()]);
            return 0;
        }
    };

    try {
        $countryId = $insert('country', 'ایران', null, 0, 'iran');

        $sort = 0;
        foreach (iran_provinces() as $province) {
            $pid = $insert('province', (string) $province, $countryId, ++$sort);
            if ($pid <= 0) {
                continue;
            }
            $csort = 0;
            foreach (array_values(array_unique(iran_cities_by_province((string) $province))) as $city) {
                $city = trim((string) $city);
                if ($city === '') {
                    continue;
                }
                $cid = $insert('city', $city, $pid, ++$csort);
                if ($cid <= 0) {
                    continue;
                }
                $citySlug = (string) (DB::fetch('SELECT slug FROM locations WHERE id = ?', [$cid])['slug'] ?? '');
                if ($citySlug === '') {
                    continue;
                }
                $nsort = 0;
                foreach (iran_city_neighborhood_names($city) as $neighborhood) {
                    $nsort++;
                    $insert(
                        'neighborhood',
                        (string) $neighborhood,
                        $cid,
                        $nsort,
                        $citySlug . '-' . swaapin_location_slug((string) $neighborhood, 'neighborhood')
                    );
                }
            }
        }

        // شهرهای فهرست عمومی که زیر هیچ استانی ثبت نشده‌اند → زیر کشور
        foreach (iran_cities() as $city) {
            $city = (string) $city;
            if ($city === '') {
                continue;
            }
            $exists = null;
            try {
                $exists = DB::fetch('SELECT id FROM locations WHERE level = ? AND name = ? LIMIT 1', ['city', $city]);
            } catch (Throwable $e) {
            }
            if (!$exists) {
                $insert('city', $city, $countryId, ++$sort);
            }
        }

        foreach (swaapin_location_regions() as $region) {
            $insert('region', (string) $region['name'], $countryId, ++$sort, (string) $region['slug']);
        }

        $cachedCount = (int) (DB::fetch('SELECT COUNT(*) AS c FROM locations')['c'] ?? 0);
        swapin_debug_log('locations_seeded', ['count' => $cachedCount]);
    } catch (Throwable $e) {
        swapin_debug_log('locations_seed_fail', ['msg' => $e->getMessage()]);
    }

    return $cachedCount ?? 0;
}

function swaapin_location_by_slug(string $slug): ?array {
    $slug = strtolower(trim($slug));
    if ($slug === '' || !swaapin_locations_table_exists()) {
        return null;
    }
    try {
        $row = DB::fetch('SELECT * FROM locations WHERE slug = ? AND is_active = 1 LIMIT 1', [$slug]);
    } catch (Throwable $e) {
        return null;
    }
    return $row ?: null;
}

function swaapin_location_child_city_names(int $locationId): array {
    if ($locationId <= 0) {
        return [];
    }
    try {
        $rows = DB::fetchAll(
            'SELECT name FROM locations WHERE parent_id = ? AND level = ? AND is_active = 1',
            [$locationId, 'city']
        );
    } catch (Throwable $e) {
        return [];
    }
    return array_column($rows, 'name');
}

/**
 * فیلتر آگهی برای یک مکان: نام شهرهای تحت پوشش + محله (در صورت وجود).
 * خروجی: ['cities' => string[], 'neighborhood' => string]
 */
function swaapin_location_filter(array $location): array {
    $level = (string) ($location['level'] ?? '');
    $name  = (string) ($location['name'] ?? '');
    $out   = ['cities' => [], 'neighborhood' => ''];

    if ($level === 'city') {
        $out['cities'] = $name !== '' ? [$name] : [];
    } elseif ($level === 'neighborhood') {
        try {
            $parent = DB::fetch('SELECT name FROM locations WHERE id = ? LIMIT 1', [(int) ($location['parent_id'] ?? 0)]);
        } catch (Throwable $e) {
            $parent = null;
        }
        if ($parent) {
            $out['cities'] = [(string) $parent['name']];
        }
        $out['neighborhood'] = $name;
    } elseif ($level === 'province') {
        $out['cities'] = swaapin_location_child_city_names((int) ($location['id'] ?? 0));
    } elseif ($level === 'region') {
        $cities = [];
        foreach (swaapin_location_region_provinces((string) ($location['slug'] ?? '')) as $provinceName) {
            try {
                $pid = DB::fetch('SELECT id FROM locations WHERE level = ? AND name = ? LIMIT 1', ['province', $provinceName]);
            } catch (Throwable $e) {
                $pid = null;
            }
            if ($pid) {
                $cities = array_merge($cities, swaapin_location_child_city_names((int) $pid['id']));
            }
        }
        $out['cities'] = array_values(array_unique($cities));
    }

    return $out;
}

/** آدرس کانونیکال یک مکان: /{slug} (بدون پیشوند /s/). */
function swaapin_location_url(array $location): string {
    $slug = trim((string) ($location['slug'] ?? ''));
    return $slug === '' ? APP_URL . '/' : APP_URL . '/' . $slug;
}

/** تعداد آگهی‌های فعال به تفکیک شهر و محله (کش‌شده در طول درخواست). */
function swaapin_location_listing_counts(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $byCity = [];
    $byNb   = [];
    $pub    = listing_public_sql('l');
    try {
        $rows = DB::fetchAll(
            "SELECT l.city AS c, COUNT(*) AS n FROM listings l
             WHERE {$pub} AND l.listing_mode <> 'sell' AND l.city <> '' GROUP BY l.city"
        );
        foreach ($rows as $r) {
            $byCity[(string) $r['c']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
    }
    try {
        $rows = DB::fetchAll(
            "SELECT l.city AS c, l.neighborhood AS nb, COUNT(*) AS n FROM listings l
             WHERE {$pub} AND l.listing_mode <> 'sell' AND l.neighborhood <> '' GROUP BY l.city, l.neighborhood"
        );
        foreach ($rows as $r) {
            $byNb[(string) $r['c'] . "\x1f" . (string) $r['nb']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
    }
    return $cache = ['city' => $byCity, 'neighborhood' => $byNb];
}

/**
 * تعداد آگهی فعال پوشش‌داده‌شده توسط یک مکان (همان منطق فیلتر صفحه مکان).
 * آگهی‌های جایگزین سطح بالاتر در این عدد حساب نمی‌شوند (سند — بخش ۱۰).
 */
function swaapin_location_active_listing_count(array $location): int {
    $counts = swaapin_location_listing_counts();
    $level  = (string) ($location['level'] ?? '');

    if ($level === 'country') {
        return array_sum($counts['city']);
    }

    $filter = swaapin_location_filter($location);

    if ($level === 'neighborhood') {
        $city = (string) ($filter['cities'][0] ?? '');
        $nb   = (string) $filter['neighborhood'];
        if ($city === '' || $nb === '') {
            return 0;
        }
        return (int) ($counts['neighborhood'][$city . "\x1f" . $nb] ?? 0);
    }

    $total = 0;
    foreach ($filter['cities'] as $cityName) {
        $total += (int) ($counts['city'][$cityName] ?? 0);
    }
    return $total;
}
