<?php
/**
 * Public AI capability demos — production engines, no listing/review writes.
 */
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/ai_moderation.php';
require_once __DIR__ . '/listing_swap_suggestions.php';

function ai_demo_categories(): array {
    return [
        ['slug' => 'book', 'name' => 'کتاب'],
        ['slug' => 'clothing', 'name' => 'پوشاک'],
        ['slug' => 'watch', 'name' => 'ساعت'],
        ['slug' => 'audio', 'name' => 'صوتی'],
        ['slug' => 'mobile-accessory', 'name' => 'لوازم جانبی موبایل'],
        ['slug' => 'toy', 'name' => 'اسباب‌بازی'],
        ['slug' => 'car', 'name' => 'خودرو'],
        ['slug' => 'real-estate', 'name' => 'املاک'],
        ['slug' => 'motorcycle', 'name' => 'موتورسیکلت'],
        ['slug' => 'electronics', 'name' => 'دیجیتال'],
    ];
}

function ai_demo_category_by_slug(string $slug): ?array {
    foreach (ai_demo_categories() as $c) {
        if ($c['slug'] === $slug) {
            return $c;
        }
    }
    return null;
}

function ai_demo_mock_listings(): array {
    return [
        [
            'id' => 9101, 'title' => 'لپ‌تاپ گیمینگ ASUS TUF F15',
            'description' => 'لپ‌تاپ گیمینگ سالم با کارت RTX، مناسب کار و بازی.',
            'want_in_return' => 'آیفون یا پلی‌استیشن', 'condition' => 'good',
            'estimated_value' => 38000000, 'cat_slug' => 'electronics', 'cat_name' => 'دیجیتال',
            'city' => 'تهران', 'neighborhood' => 'ونک', 'distance_km' => 4.2, 'seller_name' => 'نمونه دمو',
        ],
        [
            'id' => 9102, 'title' => 'PlayStation 5 استاندارد',
            'description' => 'کنسول PS5 با دو دسته، کار کرده در حد نو.',
            'want_in_return' => 'لپ‌تاپ یا تبلت', 'condition' => 'like_new',
            'estimated_value' => 32000000, 'cat_slug' => 'electronics', 'cat_name' => 'دیجیتال',
            'city' => 'تهران', 'neighborhood' => 'سعادت‌آباد', 'distance_km' => 7.8, 'seller_name' => 'نمونه دمو',
        ],
        [
            'id' => 9103, 'title' => 'آیفون ۱۳ پرو ۲۵۶ گیگ',
            'description' => 'گوشی بدون خط و خش، باتری سالم، جعبه و شارژر.',
            'want_in_return' => 'لپ‌تاپ سبک یا اعتبار', 'condition' => 'like_new',
            'estimated_value' => 35000000, 'cat_slug' => 'electronics', 'cat_name' => 'دیجیتال',
            'city' => 'اصفهان', 'neighborhood' => '', 'distance_km' => 18.0, 'seller_name' => 'نمونه دمو',
        ],
        [
            'id' => 9104, 'title' => 'دوچرخه کوهستان سایز ۲۶',
            'description' => 'دوچرخه سالم، ترمز دیسکی، مناسب مسیرهای شهری.',
            'want_in_return' => 'ساعت یا هدفون', 'condition' => 'good',
            'estimated_value' => 8500000, 'cat_slug' => 'sports', 'cat_name' => 'ورزش',
            'city' => 'شیراز', 'neighborhood' => '', 'distance_km' => 42.0, 'seller_name' => 'نمونه دمو',
        ],
        [
            'id' => 9105, 'title' => 'هدفون Sony WH-1000XM4',
            'description' => 'هدفون نویزکنسلینگ اصلی، سالم.',
            'want_in_return' => 'ساعت هوشمند', 'condition' => 'good',
            'estimated_value' => 12000000, 'cat_slug' => 'audio', 'cat_name' => 'صوتی',
            'city' => 'تهران', 'neighborhood' => 'جردن', 'distance_km' => 3.1, 'seller_name' => 'نمونه دمو',
        ],
        [
            'id' => 9106, 'title' => 'کتاب مجموعه هری پاتر فارسی',
            'description' => 'مجموعه کامل جلد سخت، کم‌استفاده.',
            'want_in_return' => 'کتاب یا اعتبار', 'condition' => 'good',
            'estimated_value' => 2800000, 'cat_slug' => 'book', 'cat_name' => 'کتاب',
            'city' => 'مشهد', 'neighborhood' => '', 'distance_km' => 22.0, 'seller_name' => 'نمونه دمو',
        ],
    ];
}

function ai_demo_format_listing_card(array $row): array {
    $value = (float) ($row['estimated_value'] ?? 0);
    return [
        'id'             => (int) ($row['id'] ?? 0),
        'title'          => (string) ($row['title'] ?? ''),
        'city'           => (string) ($row['city'] ?? ''),
        'cat_name'       => category_label($row['cat_slug'] ?? '', $row['cat_name'] ?? ''),
        'want_in_return' => (string) ($row['want_in_return'] ?? ''),
        'estimated_value'=> $value,
        'value_fmt'      => $value > 0 ? fmt_credit($value) : '',
        'condition'      => condition_label((string) ($row['condition'] ?? 'good')),
        'demo'           => true,
    ];
}

function ai_demo_run_moderate(array $input): array {
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $want = trim((string) ($input['want_in_return'] ?? ''));
    $condition = (string) ($input['condition'] ?? 'good');
    if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
        $condition = 'good';
    }
    $slug = trim((string) ($input['category_slug'] ?? 'book'));
    $cat = ai_demo_category_by_slug($slug) ?? ['slug' => 'book', 'name' => 'کتاب'];
    $value = (float) ($input['estimated_value'] ?? 0);
    $city = trim((string) ($input['city'] ?? 'تهران'));

    if (mb_strlen($title) < 5) {
        return ['ok' => false, 'error' => 'title_too_short'];
    }

    $listing = [
        'title'           => mb_substr($title, 0, 160),
        'description'     => mb_substr($description, 0, 4000),
        'want_in_return'  => mb_substr($want, 0, 400),
        'condition'       => $condition,
        'estimated_value' => $value,
        'sell_price'      => null,
        'listing_mode'    => 'swap',
        'city'            => $city,
    ];

    // Demo simulates a trusted seller so the live approve path can fire
    // (new-user + high-value otherwise always escalates in production).
    $result = ai_mod_review_sandbox($listing, $cat, 8);
    $result['public_provider'] = ai_public_mode($result['provider'] ?? 'rules');
    unset($result['provider']);
    $result['category'] = $cat;
    $result['listing_preview'] = $listing;
    return $result;
}

function ai_demo_run_pricing(array $input): array {
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $condition = (string) ($input['condition'] ?? 'good');
    if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
        $condition = 'good';
    }
    $slug = trim((string) ($input['category_slug'] ?? ''));
    $cat = ai_demo_category_by_slug($slug);

    if (mb_strlen($title) < 5) {
        return ['ok' => false, 'error' => 'title_too_short'];
    }

    $listing = [
        'title'          => mb_substr($title, 0, 160),
        'description'    => mb_substr($description, 0, 4000),
        'condition'      => $condition,
        'category_label' => $cat ? category_label($cat['slug'], $cat['name']) : 'عمومی',
        'demand_level'   => 'medium',
    ];

    $result = ai_price_listing($listing, []);
    if (!$result) {
        $result = ai_price_listing_fallback($listing);
    }

    return array_merge(['ok' => true, 'sandbox' => true], ai_sanitize_pricing_for_client($result));
}

function ai_demo_run_chat(string $message, array $history = []): array {
    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'empty_message'];
    }
    if (mb_strlen($message) > 800) {
        return ['ok' => false, 'error' => 'message_too_long'];
    }

    $cleanHistory = [];
    foreach (array_slice($history, -6) as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $role = ($turn['role'] ?? '') === 'user' ? 'user' : 'assistant';
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        $cleanHistory[] = ['role' => $role, 'content' => mb_substr($content, 0, 800)];
    }

    $result = ai_chat_respond($message, $cleanHistory, ['name' => 'بازدیدکننده دمو']);
    return [
        'ok'      => true,
        'sandbox' => true,
        'type'    => 'chat',
        'message' => $result['message'],
    ];
}

function ai_demo_run_need_search(string $need, string $city = ''): array {
    $need = trim($need);
    $city = trim($city);
    if (mb_strlen($need) < 3) {
        return ['ok' => false, 'error' => 'need_too_short'];
    }

    $filters = [
        'keywords'      => array_values(array_filter(preg_split('/\s+/u', $need) ?: [])),
        'category_slug' => null,
        'city'          => $city !== '' ? $city : null,
        'price_min'     => null,
        'price_max'     => null,
        'want_type'     => null,
        'summary'       => mb_strimwidth($need, 0, 120, '…'),
        'source'        => 'rules',
    ];

    if (ai_is_configured()) {
        $catPayload = array_map(static fn($c) => [
            'slug' => $c['slug'],
            'name' => $c['name'],
        ], ai_demo_categories());

        $result = ai_call('need_search', [
            'need_text'   => mb_substr($need, 0, 400),
            'city_hint'   => $city !== '' ? $city : null,
            'categories'  => $catPayload,
            'instruction' => 'Extract search filters from the user need. Respond with need_search JSON only.',
        ]);
        $parsed = ai_parse_json_response($result['parsed'] ?? null);
        if ($parsed && ($parsed['type'] ?? '') === 'need_search') {
            $keywords = [];
            foreach (($parsed['keywords'] ?? []) as $kw) {
                $kw = trim((string) $kw);
                if (mb_strlen($kw) >= 2) {
                    $keywords[] = $kw;
                }
            }
            $slug = trim((string) ($parsed['category_slug'] ?? ''));
            if ($slug === 'null') {
                $slug = '';
            }
            $cityHint = trim((string) ($parsed['city_hint'] ?? ''));
            if ($cityHint === 'null') {
                $cityHint = '';
            }
            $filters = [
                'keywords'      => $keywords !== [] ? $keywords : $filters['keywords'],
                'category_slug' => $slug !== '' ? $slug : null,
                'city'          => $city !== '' ? $city : ($cityHint !== '' ? $cityHint : null),
                'price_min'     => max(0, (int) ($parsed['price_min'] ?? 0)) ?: null,
                'price_max'     => max(0, (int) ($parsed['price_max'] ?? 0)) ?: null,
                'want_type'     => ($parsed['want_type'] ?? '') && ($parsed['want_type'] !== 'null') ? $parsed['want_type'] : null,
                'summary'       => trim((string) ($parsed['summary'] ?? '')) ?: $filters['summary'],
                'source'        => ai_public_mode($result['provider'] ?? 'assistant'),
            ];
        }
    }

    $pool = ai_demo_mock_listings();
    $matched = [];
    foreach ($pool as $row) {
        $hay = mb_strtolower($row['title'] . ' ' . $row['description'] . ' ' . $row['city'] . ' ' . ($row['cat_name'] ?? ''));
        $hit = false;
        foreach ($filters['keywords'] as $kw) {
            if (mb_strlen($kw) >= 2 && mb_stripos($hay, mb_strtolower($kw)) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit && $filters['category_slug'] && ($row['cat_slug'] ?? '') === $filters['category_slug']) {
            $hit = true;
        }
        if ($hit) {
            $matched[] = ai_demo_format_listing_card($row);
        }
    }

    if ($matched === []) {
        $matched = array_map('ai_demo_format_listing_card', array_slice($pool, 0, 3));
    }

    return [
        'ok'       => true,
        'sandbox'  => true,
        'total'    => count($matched),
        'filters'  => $filters,
        'source'   => $filters['source'],
        'listings' => $matched,
        'note'     => 'نتایج از مجموعهٔ نمونهٔ دموست؛ آگهی واقعی جستجو یا ذخیره نمی‌شود.',
    ];
}

function ai_demo_build_source_listing(array $input): array {
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $want = trim((string) ($input['want_in_return'] ?? ''));
    $condition = (string) ($input['condition'] ?? 'good');
    if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'poor'], true)) {
        $condition = 'good';
    }
    $slug = trim((string) ($input['category_slug'] ?? 'electronics'));
    $cat = ai_demo_category_by_slug($slug) ?? ['slug' => 'electronics', 'name' => 'دیجیتال'];
    $value = (float) ($input['estimated_value'] ?? 0);
    $city = trim((string) ($input['city'] ?? 'تهران'));

    return [
        'id'              => 9000,
        'title'           => mb_substr($title !== '' ? $title : 'آگهی نمونه من', 0, 160),
        'description'     => mb_substr($description !== '' ? $description : 'توضیحات نمونه برای دموی تطبیق.', 0, 4000),
        'want_in_return'  => mb_substr($want !== '' ? $want : 'لپ‌تاپ یا کنسول', 0, 400),
        'condition'       => $condition,
        'estimated_value' => $value > 0 ? $value : 30000000,
        'cat_slug'        => $cat['slug'],
        'cat_name'        => $cat['name'],
        'city'            => $city !== '' ? $city : 'تهران',
        'neighborhood'    => '',
    ];
}

function ai_demo_run_matching(array $input): array {
    $source = ai_demo_build_source_listing($input);
    $candidates = array_slice(ai_demo_mock_listings(), 0, 5);

    $candidatePayload = [];
    foreach ($candidates as $c) {
        $candidatePayload[] = array_merge(ai_match_listing_payload($c), [
            'listing_id'   => (int) $c['id'],
            'rule_score'   => 62,
            'mutual'       => mb_stripos($source['want_in_return'], mb_substr($c['title'], 0, 6)) !== false,
            'four_pillars' => [
                'needs_match_score'     => 70,
                'category_match_score'  => ($c['cat_slug'] ?? '') === ($source['cat_slug'] ?? '') ? 88 : 40,
                'value_proximity_score' => 75,
                'success_probability'   => 60,
            ],
            'seller_stats' => [
                'verified' => 'none', 'rating' => 4.6, 'completed_trades' => 3,
            ],
            'weights_hint' => [
                'needs_match' => 0.34, 'value' => 0.24, 'category' => 0.22, 'success' => 0.20,
            ],
        ]);
    }

    $matches = [];
    $sourceTag = 'rules';

    if (ai_is_configured()) {
        $payload = [
            'user_listing'       => ai_match_listing_payload($source),
            'candidate_listings' => $candidatePayload,
            'instruction'        => 'Rank candidates by weighted FOUR PILLAR score: needs_match*0.34 + value*0.24 + category*0.22 + success*0.20. For each match, return listing_id, score (0-100), trade_type (direct|credit), and a 1-line Persian reason mentioning the strongest pillars.',
        ];
        $result = ai_call('matching', $payload);
        $parsed = ai_parse_json_response($result['parsed'] ?? null);
        if ($parsed && ($parsed['type'] ?? '') === 'matching' && !empty($parsed['matches'])) {
            $sourceTag = $result['provider'] ?? 'assistant';
            $byId = [];
            foreach ($candidates as $c) {
                $byId[(int) $c['id']] = $c;
            }
            foreach ($parsed['matches'] as $item) {
                $lid = (int) ($item['listing_id'] ?? 0);
                if (!$lid || !isset($byId[$lid])) {
                    continue;
                }
                $c = $byId[$lid];
                $score = max(0, min(100, (int) ($item['score'] ?? 50)));
                $matches[] = [
                    'listing_id'     => $lid,
                    'title'          => $c['title'],
                    'match_score'    => $score,
                    'trade_type'     => in_array($item['trade_type'] ?? '', ['direct', 'credit'], true) ? $item['trade_type'] : 'direct',
                    'reason'         => trim((string) ($item['reason'] ?? '')) ?: 'همخوانی بر اساس چهار ستون تطبیق.',
                    'city'           => $c['city'],
                    'value_fmt'      => fmt_credit((float) $c['estimated_value']),
                    'cat_name'       => category_label($c['cat_slug'], $c['cat_name']),
                    'want_in_return' => $c['want_in_return'],
                ];
            }
        }
    }

    if ($matches === []) {
        foreach (array_slice($candidates, 0, 3) as $i => $c) {
            $score = 78 - ($i * 9);
            $matches[] = [
                'listing_id'     => (int) $c['id'],
                'title'          => $c['title'],
                'match_score'    => $score,
                'trade_type'     => 'direct',
                'reason'         => ai_match_rule_reason($c + ['match_score' => $score, 'mutual' => $i === 0]),
                'city'           => $c['city'],
                'value_fmt'      => fmt_credit((float) $c['estimated_value']),
                'cat_name'       => category_label($c['cat_slug'], $c['cat_name']),
                'want_in_return' => $c['want_in_return'],
            ];
        }
        $sourceTag = 'rules';
    }

    return [
        'ok'           => true,
        'sandbox'      => true,
        'source'       => ai_public_mode($sourceTag),
        'user_listing' => [
            'title'          => $source['title'],
            'want_in_return' => $source['want_in_return'],
            'value_fmt'      => fmt_credit((float) $source['estimated_value']),
            'category'       => category_label($source['cat_slug'], $source['cat_name']),
        ],
        'matches'      => $matches,
    ];
}

function ai_demo_run_swap(array $input): array {
    $source = ai_demo_build_source_listing($input);
    $candidates = array_slice(ai_demo_mock_listings(), 0, 5);
    $limit = 4;
    $suggestions = [];
    $tag = 'rules';

    $ai = listing_swap_suggestions_from_ai($source, $candidates, $limit);
    if ($ai && !empty($ai['suggestions'])) {
        $tag = $ai['source'] ?? 'assistant';
        $byId = [];
        foreach ($candidates as $c) {
            $byId[(int) $c['id']] = $c;
        }
        foreach ($ai['suggestions'] as $item) {
            $lid = (int) ($item['listing_id'] ?? 0);
            if (!isset($byId[$lid])) {
                continue;
            }
            $c = $byId[$lid];
            $swap = (int) ($item['swap_compatibility'] ?? 0);
            $loc = (int) ($item['location_score'] ?? 0);
            $suggestions[] = [
                'listing_id'             => $lid,
                'title'                  => $c['title'],
                'city'                   => $c['city'],
                'distance_km'            => $c['distance_km'],
                'value_fmt'              => fmt_credit((float) $c['estimated_value']),
                'swap_compatibility'     => $swap,
                'value_compatibility'    => (int) ($item['value_compatibility'] ?? 0),
                'category_compatibility' => (int) ($item['category_compatibility'] ?? 0),
                'location_score'         => $loc,
                'confidence'             => (int) ($item['confidence'] ?? 0),
                'final_score'            => listing_swap_ai_compute_final_score($swap, $loc),
                'reasons'                => $item['reasons'] ?? [],
            ];
        }
    }

    if ($suggestions === []) {
        foreach (array_slice($candidates, 0, 3) as $i => $c) {
            $swap = 80 - ($i * 8);
            $loc = max(20, 90 - (int) ($c['distance_km'] * 2));
            $suggestions[] = [
                'listing_id'             => (int) $c['id'],
                'title'                  => $c['title'],
                'city'                   => $c['city'],
                'distance_km'            => $c['distance_km'],
                'value_fmt'              => fmt_credit((float) $c['estimated_value']),
                'swap_compatibility'     => $swap,
                'value_compatibility'    => 72,
                'category_compatibility' => ($c['cat_slug'] === $source['cat_slug']) ? 90 : 45,
                'location_score'         => $loc,
                'confidence'             => 70,
                'final_score'            => listing_swap_ai_compute_final_score($swap, $loc),
                'reasons'                => ['نزدیکی ارزش کالا', 'هم‌خوانی نسبی نیاز'],
            ];
        }
        $tag = 'rules';
    }

    usort($suggestions, static fn($a, $b) => $b['final_score'] <=> $a['final_score']);

    return [
        'ok'           => true,
        'sandbox'      => true,
        'source'       => ai_public_mode($tag),
        'user_listing' => [
            'title'          => $source['title'],
            'city'           => $source['city'],
            'want_in_return' => $source['want_in_return'],
        ],
        'weights'      => listing_swap_final_score_weights(),
        'suggestions'  => array_slice($suggestions, 0, $limit),
    ];
}
