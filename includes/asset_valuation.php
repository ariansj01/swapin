<?php

function av_condition_factor(string $condition): float {
    return match (strtolower(trim($condition))) {
        'new', 'like_new', 'perfect' => 1.0,
        'excellent', 'good'       => 0.92,
        'fair', 'used', 'acceptable' => 0.8,
        'poor', 'broken', 'for_parts' => 0.6,
        default => 0.9,
    };
}

function av_age_factor(int $ageYears): float {
    if ($ageYears <= 0) return 1.0;
    if ($ageYears <= 1) return 0.92;
    if ($ageYears <= 2) return 0.82;
    if ($ageYears <= 3) return 0.72;
    if ($ageYears <= 5) return 0.6;
    return max(0.3, 0.6 - ($ageYears - 5) * 0.05);
}

function av_category_multiplier(int $categoryId, ?string $slugOrName = null): float {
    if ($categoryId <= 0) return 1.0;
    if ($slugOrName !== null) {
        $s = mb_strtolower(trim((string)$slugOrName));
        if ($s !== '') {
            $map = [
                'موبایل' => 1.05, 'گوشی' => 1.05, 'phone' => 1.05, 'mobile' => 1.05,
                'لپ\u200cتاپ' => 1.02, 'لپتاپ' => 1.02, 'laptop' => 1.02, 'notebook' => 1.02,
                'خودرو' => 1.0, 'car' => 1.0, 'موتور' => 1.0, 'motorcycle' => 1.0,
                'کنسول بازی' => 0.98, 'کنسول' => 0.98, 'console' => 0.98,
                'دوربین' => 0.97, 'camera' => 0.97,
                'طلا و جواهر' => 1.1, 'طلا' => 1.1, 'gold' => 1.1, 'jewelry' => 1.1,
                'ساعت' => 1.05, 'watch' => 1.05,
                'لوازم خانگی' => 0.9, 'appliance' => 0.9,
                'پوشاک' => 0.7, 'clothes' => 0.7, 'cloth' => 0.7,
                'کتاب' => 0.5, 'book' => 0.5,
                'لوازم ورزشی' => 0.85, 'sport' => 0.85,
            ];
            foreach ($map as $k => $v) {
                if (mb_strpos($s, $k) !== false) return (float)$v;
            }
        }
    }
    return 1.0;
}

function av_listing_age_years(array $listing): int {
    $year = 0;
    if (!empty($listing['year'])) {
        $year = (int)$listing['year'];
    } elseif (isset($listing['attributes'])) {
        if (is_string($listing['attributes'])) {
            $dec = json_decode($listing['attributes'], true);
            if (is_array($dec) && !empty($dec['year'])) $year = (int)$dec['year'];
        } elseif (is_array($listing['attributes']) && !empty($listing['attributes']['year'])) {
            $year = (int)$listing['attributes']['year'];
        }
    }
    if ($year > 1900 && $year <= (int)date('Y')) {
        return max(0, (int)date('Y') - $year);
    }
    if (!empty($listing['created_at'])) {
        $ts = strtotime((string)$listing['created_at']);
        if ($ts > 0) return max(0, (int)floor((time() - $ts) / 31536000));
    }
    return 0;
}

/**
 * @return array{value:int,confidence:string,confidence_pct:int,breakdown:array{base:int,condition:int,age:int,category:int},source:string}
 */
function av_calculate_listing_value(array $listing): array {
    $base = (float)($listing['sell_price'] ?? 0);
    $sourceBase = 'sell_price';
    if ($base <= 0) {
        $base = (float)($listing['estimated_value'] ?? 0);
        $sourceBase = 'estimated_value';
    }

    $cond = (string)($listing['condition'] ?? 'good');
    $condFactor = av_condition_factor($cond);
    $catId = (int)($listing['category_id'] ?? 0);
    $catName = (string)($listing['category_name'] ?? $listing['cat_name'] ?? '');
    $catFactor = av_category_multiplier($catId, $catName);
    $ageYears = av_listing_age_years($listing);
    $ageFactor = av_age_factor($ageYears);

    if ($base <= 0) {
        $base = (float)($listing['custom_value'] ?? 0);
        $sourceBase = 'custom_value';
    }
    if ($base <= 0) {
        $base = 0;
        $sourceBase = 'unavailable';
    }

    $valCond = (int)round($base * $condFactor);
    $valAge  = (int)round($valCond * $ageFactor);
    $valCat  = (int)round($valAge * $catFactor);
    $final   = max(0, $valCat);

    $confidencePct = 40;
    if ($base > 0) {
        if ($sourceBase === 'sell_price') $confidencePct = 90;
        elseif ($sourceBase === 'estimated_value') $confidencePct = 70;
        else $confidencePct = 50;

        if ($cond !== '' && $cond !== 'good') $confidencePct += 2;
        if ($catId > 0) $confidencePct += 3;
        if ($ageYears > 0 || !empty($listing['year'])) $confidencePct += 2;
    }
    $confidencePct = min(99, $confidencePct);

    if ($confidencePct >= 80) $confidence = 'high';
    elseif ($confidencePct >= 55) $confidence = 'medium';
    else $confidence = 'low';

    return [
        'value'          => $final,
        'confidence'     => $confidence,
        'confidence_pct' => $confidencePct,
        'breakdown'      => [
            'base'     => (int)round($base),
            'condition'=> $valCond,
            'age'      => $valAge,
            'category' => $valCat,
        ],
        'source'         => $sourceBase,
    ];
}

/**
 * @return array{total_value:int,currency:string,assets:array,swap_opportunities:int,confidence:string}
 */
function av_get_user_assets(int $userId): array {
    $listings = DB::fetchAll(
        'SELECT l.*, c.name AS category_name, c.slug AS category_slug,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         LEFT JOIN categories c ON c.id = l.category_id
         WHERE l.user_id = ? AND l.status = "active" AND l.review_status = "approved"
         ORDER BY l.created_at DESC',
        [$userId]
    );

    $totalValue = 0;
    $assets = [];
    $confMin = 100;
    $hasAny = false;

    foreach ($listings as $l) {
        $hasAny = true;
        $calc = av_calculate_listing_value(array_merge($l, [
            'category_name' => (string)($l['category_name'] ?? ''),
        ]));
        $val = $calc['value'];
        $totalValue += $val;
        if ($calc['confidence_pct'] < $confMin) $confMin = $calc['confidence_pct'];
        $assets[] = [
            'listing_id'     => (int)$l['id'],
            'title'          => (string)$l['title'],
            'thumb'          => !empty($l['thumb']) ? UPLOAD_URL . $l['thumb'] : null,
            'category_id'    => (int)$l['category_id'],
            'category_name'  => (string)($l['category_name'] ?? ''),
            'condition'      => (string)($l['condition'] ?? ''),
            'estimated_value'=> $val,
            'confidence'     => $calc['confidence'],
            'confidence_pct' => $calc['confidence_pct'],
            'source'         => $calc['source'],
            'value_breakdown'=> $calc['breakdown'],
            'view_url'       => APP_URL . '/listings/view.php?id=' . (int)$l['id'],
        ];
    }

    $swapOpps = 0;
    try {
        if (function_exists('iso_get_matches_for_user_listings')) {
            $matches = iso_get_matches_for_user_listings($userId, 200);
            $seen = [];
            foreach ($matches as $m) {
                $k = (int)($m['id'] ?? 0) . '-' . (int)($m['matched_from_listing_id'] ?? 0);
                if (!isset($seen[$k])) {
                    $swapOpps++;
                    $seen[$k] = true;
                }
            }
        }
    } catch (Throwable) {
        $swapOpps = 0;
    }

    $overallConf = 'low';
    if ($hasAny) {
        if ($confMin >= 80) $overallConf = 'high';
        elseif ($confMin >= 55) $overallConf = 'medium';
    }

    return [
        'total_value'       => $totalValue,
        'currency'          => DEFAULT_CURRENCY_CODE,
        'assets'            => $assets,
        'swap_opportunities'=> $swapOpps,
        'confidence'        => $overallConf,
    ];
}

function av_confidence_label(string $conf): string {
    return match ($conf) {
        'high'   => 'بالا',
        'medium' => 'متوسط',
        default  => 'پایین',
    };
}
