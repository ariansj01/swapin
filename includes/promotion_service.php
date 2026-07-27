<?php

function promotion_plans(): array {
    return [
        'boost' => [
            'name' => 'بازدید بیشتر',
            'base_price' => 50000,
            'durations' => ['24 ساعت' => 24, '7 روز' => 7 * 24, '14 روز' => 14 * 24, '30 روز' => 30 * 24],
        ],
        'featured' => [
            'name' => 'داغ',
            'base_price' => 100000,
            'durations' => ['24 ساعت' => 24, '7 روز' => 7 * 24, '14 روز' => 14 * 24, '30 روز' => 30 * 24],
        ],
        'vip' => [
            'name' => 'ویژه',
            'base_price' => 200000,
            'durations' => ['24 ساعت' => 24, '7 روز' => 7 * 24, '14 روز' => 14 * 24, '30 روز' => 30 * 24],
        ],
        'targeted' => [
            'name' => 'هدفمند',
            'base_price' => 150000,
            'durations' => ['24 ساعت' => 24, '7 روز' => 7 * 24, '14 روز' => 14 * 24, '30 روز' => 30 * 24],
        ],
        'ai' => [
            'name' => 'هوشمند',
            'base_price' => 250000,
            'durations' => ['24 ساعت' => 24, '7 روز' => 7 * 24, '14 روز' => 14 * 24, '30 روز' => 30 * 24],
        ],
        'gold' => [
            'name' => 'طلایی',
            'base_price' => 500000,
            'durations' => ['24 ساعت' => 24, '7 روز' => 7 * 24, '14 روز' => 14 * 24, '30 روز' => 30 * 24],
        ],
    ];
}

function promotion_plan_details(): array {
    return [
        'boost' => [
            'icon' => 'bi-send',
            'color' => 'purple',
            'header' => 'purple',
            'badge' => null,
            'badge_class' => '',
            'description' => 'آگهی در بالاترین جایگاه لیست قرار می‌گیرد',
        ],
        'featured' => [
            'icon' => 'bi-fire',
            'color' => 'orange',
            'header' => 'orange',
            'badge' => null,
            'badge_class' => '',
            'description' => 'نمایش در بخش آگهی‌های ویژه با برچسب داغ',
        ],
        'vip' => [
            'icon' => 'bi-award',
            'color' => 'violet',
            'header' => 'violet',
            'badge' => 'محبوب',
            'badge_class' => 'popular',
            'description' => 'نمایش در صفحه اول، اولویت در نتایج جستجو',
        ],
        'targeted' => [
            'icon' => 'bi-bullseye',
            'color' => 'green',
            'header' => 'green',
            'badge' => 'جدید',
            'badge_class' => 'new',
            'description' => 'نمایش به مخاطبان مرتبط بر اساس شهر و دسته‌بندی',
        ],
        'ai' => [
            'icon' => 'bi-telegram',
            'color' => 'blue',
            'header' => 'blue',
            'badge' => 'حرفه‌ای',
            'badge_class' => 'pro',
            'description' => 'ارسال هوشمند آگهی به مخاطبان دقیقاً مرتبط',
        ],
        'gold' => [
            'icon' => 'bi-star-fill',
            'color' => 'gold',
            'header' => 'gold',
            'badge' => 'پیشنهاد ویژه',
            'badge_class' => 'special',
            'description' => 'شامل تمام امکانات: بازدید بیشتر, داغ, ویژه, هدفمند و هوشمند',
            'featured' => true,
        ],
    ];
}

function promotion_ui_plans(): array {
    $plans = promotion_plans();
    $details = promotion_plan_details();
    foreach ($plans as $slug => &$plan) {
        $plan = array_merge($details[$slug] ?? [], $plan);
    }
    unset($plan);
    return $plans;
}

function normalize_promotion_duration(string $plan, int $selectedDuration): array {
    $plans = promotion_plans();
    if (!isset($plans[$plan])) {
        return ['valid' => false];
    }
    $planData = $plans[$plan];
    $validDurations = array_values($planData['durations']);
    $baseDuration = (int)($validDurations[0] ?? 24);
    if (!in_array($selectedDuration, $validDurations, true)) {
        $selectedDuration = $baseDuration;
    }
    $price = (int)round($planData['base_price'] * ($selectedDuration / max($baseDuration, 1)));
    return [
        'valid' => true,
        'plan' => $planData,
        'duration_hours' => $selectedDuration,
        'base_duration' => $baseDuration,
        'price' => $price,
    ];
}

function apply_listing_promotion(int $listingId, int $userId, string $plan, int $durationHours, float $amountPaid): array {
    $normalized = normalize_promotion_duration($plan, $durationHours);
    if (empty($normalized['valid'])) {
        throw new RuntimeException('پلن ارتقا نامعتبر است');
    }

    $listing = DB::fetch(
        'SELECT id, user_id, status FROM listings WHERE id = ? AND user_id = ? LIMIT 1',
        [$listingId, $userId]
    );
    if (!$listing || $listing['status'] !== 'active') {
        throw new RuntimeException('آگهی برای ارتقا یافت نشد یا فعال نیست');
    }

    $now = time();
    $startsAt = date('Y-m-d H:i:s', $now);
    $endsAt = date('Y-m-d H:i:s', $now + ($normalized['duration_hours'] * 3600));

    $promotionId = DB::insert('listing_promotions', [
        'listing_id' => $listingId,
        'user_id' => $userId,
        'plan' => $plan,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'amount_paid' => $amountPaid,
    ]);

    $updateData = [];
    if ($plan === 'boost') {
        $updateData['bump_until'] = $endsAt;
    } elseif ($plan === 'featured') {
        $updateData['featured_until'] = $endsAt;
        $updateData['is_featured'] = 1;
    } elseif ($plan === 'vip') {
        $updateData['featured_until'] = $endsAt;
        $updateData['vip_until'] = $endsAt;
        $updateData['is_featured'] = 1;
    } elseif ($plan === 'targeted') {
        $updateData['targeted_until'] = $endsAt;
    } elseif ($plan === 'ai') {
        $updateData['ai_promo_until'] = $endsAt;
    } elseif ($plan === 'gold') {
        $updateData['bump_until'] = $endsAt;
        $updateData['featured_until'] = $endsAt;
        $updateData['vip_until'] = $endsAt;
        $updateData['is_featured'] = 1;
        $updateData['targeted_until'] = $endsAt;
        $updateData['ai_promo_until'] = $endsAt;
    }

    if ($updateData !== []) {
        DB::update('listings', $updateData, 'id = ?', [$listingId]);
    }

    return [
        'success' => true,
        'promotion_id' => $promotionId,
        'ends_at' => $endsAt,
        'plan_name' => $normalized['plan']['name'] ?? $plan,
    ];
}
