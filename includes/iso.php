<?php

function iso_get_user_requests(int $userId, ?string $status = null): array {
    $sql = 'SELECT ir.*, l.title AS listing_title, c.name AS category_name,
                   (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS listing_thumb
            FROM iso_requests ir
            JOIN listings l ON l.id = ir.listing_id
            JOIN categories c ON c.id = ir.category_id
            WHERE ir.user_id = ?';
    $params = [$userId];
    if ($status) {
        $sql .= ' AND ir.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY ir.created_at DESC';
    return DB::fetchAll($sql, $params);
}

function iso_get_request(int $isoId, ?int $userId = null): ?array {
    $sql = 'SELECT ir.*, l.title AS listing_title, l.estimated_value AS listing_value,
                   l.user_id AS listing_owner_id, c.name AS category_name,
                   (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS listing_thumb
            FROM iso_requests ir
            JOIN listings l ON l.id = ir.listing_id
            JOIN categories c ON c.id = ir.category_id
            WHERE ir.id = ?';
    $params = [$isoId];
    if ($userId !== null) {
        $sql .= ' AND ir.user_id = ?';
        $params[] = $userId;
    }
    $row = DB::fetch($sql, $params);
    return $row ?: null;
}

function iso_create_request(int $userId, array $data): int {
    $now = date('Y-m-d H:i:s');
    return DB::insert('iso_requests', [
        'user_id'       => $userId,
        'listing_id'    => (int)$data['listing_id'],
        'title'         => clean($data['title']),
        'description'   => !empty($data['description']) ? clean($data['description']) : null,
        'category_id'   => (int)$data['category_id'],
        'city'          => !empty($data['city']) ? clean($data['city']) : null,
        'neighborhood'  => !empty($data['neighborhood']) ? clean($data['neighborhood']) : null,
        'latitude'      => !empty($data['latitude']) ? $data['latitude'] : null,
        'longitude'     => !empty($data['longitude']) ? $data['longitude'] : null,
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);
}

function iso_update_request(int $isoId, int $userId, array $data): bool {
    $fields = [];
    $params = [];
    $allowed = ['title', 'description', 'category_id', 'city', 'neighborhood', 'latitude', 'longitude', 'status', 'listing_id'];
    foreach ($allowed as $f) {
        if (isset($data[$f])) {
            $val = $data[$f];
            if (in_array($f, ['category_id', 'listing_id'], true)) {
                $val = (int)$val;
            } elseif ($f !== 'status') {
                $val = $val === '' || $val === null ? null : clean($val);
            }
            $fields[] = "`{$f}` = ?";
            $params[] = $val;
        }
    }
    if (empty($fields)) return false;
    $fields[] = '`updated_at` = ?';
    $params[] = date('Y-m-d H:i:s');
    $params[] = $isoId;
    $params[] = $userId;
    $sql = 'UPDATE iso_requests SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';
    DB::query($sql, $params);
    return true;
}

function iso_delete_request(int $isoId, int $userId): bool {
    return iso_update_request($isoId, $userId, ['status' => 'deleted']);
}

function iso_listing_has_active_requests(int $listingId, ?int $userId = null): array {
    $sql = 'SELECT * FROM iso_requests WHERE listing_id = ? AND status = "active"';
    $params = [$listingId];
    if ($userId !== null) {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    return DB::fetchAll($sql, $params);
}

function iso_haversine_distance_km(float $lat1, float $lng1, ?float $lat2, ?float $lng2): ?float {
    if ($lat2 === null || $lng2 === null) return null;
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadius * $c, 2);
}

function iso_text_similarity_pct(string $a, string $b): int {
    $a = trim(mb_strtolower($a));
    $b = trim(mb_strtolower($b));
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 100;
    $wordsA = preg_split('/[\s,،.\/\\-_]+/u', $a, -1, PREG_SPLIT_NO_EMPTY);
    $wordsB = preg_split('/[\s,،.\/\\-_]+/u', $b, -1, PREG_SPLIT_NO_EMPTY);
    if (!$wordsA || !$wordsB) return 0;
    $hit = 0;
    $total = 0;
    foreach ($wordsA as $wa) {
        $waLen = mb_strlen($wa);
        if ($waLen < 2) continue;
        $total++;
        $bestSub = 0;
        foreach ($wordsB as $wb) {
            if ($wa === $wb) { $bestSub = 100; break; }
            if (mb_strpos($wb, $wa) !== false || mb_strpos($wa, $wb) !== false) {
                $bestSub = max($bestSub, 85);
                continue;
            }
            $maxLen = max(mb_strlen($wa), mb_strlen($wb));
            $sim = 0;
            similar_text($wa, $wb, $sim);
            $bestSub = max($bestSub, (int)$sim);
        }
        $hit += $bestSub;
    }
    return $total > 0 ? (int)min(100, round($hit / $total)) : 0;
}

function iso_build_match_reason(array $scores): string {
    $parts = [];
    if ($scores['title'] >= 70) $parts[] = 'عنوان مشابه';
    if ($scores['category'] >= 80) $parts[] = 'دسته‌بندی یکسان';
    elseif ($scores['category'] >= 50) $parts[] = 'دسته‌بندی نزدیک';
    if ($scores['value'] >= 70) $parts[] = 'ارزش نزدیک';
    if ($scores['location'] >= 80) $parts[] = 'در همان شهر';
    elseif ($scores['location'] >= 50) $parts[] = 'نزدیک از شما';
    if ($scores['description'] >= 70) $parts[] = 'توضیحات هم‌خوان';
    if (!$parts) $parts[] = 'پتانسیل معاوضه';
    return implode('، ', $parts);
}

/**
 * @return array{total:int,title:int,category:int,value:int,location:int,description:int,distance_km:?float,reason:string}
 */
function iso_calculate_match_score(array $iso, array $listing): array {
    $lTitle = (string)($listing['title'] ?? '');
    $iTitle = (string)($iso['title'] ?? '');
    $titleScore = iso_text_similarity_pct($iTitle, $lTitle);

    $iDesc = (string)($iso['description'] ?? '');
    $lDesc = (string)($listing['description'] ?? '');
    $descScore = 0;
    if ($iDesc !== '' && $lDesc !== '') {
        $descScore = iso_text_similarity_pct($iDesc, $lDesc);
    } else {
        $descScore = (int)round($titleScore * 0.5);
    }

    $catScore = 0;
    $isoCatId = (int)($iso['category_id'] ?? 0);
    $listingCatId = (int)($listing['category_id'] ?? 0);
    if ($isoCatId > 0 && $listingCatId > 0) {
        if ($isoCatId === $listingCatId) {
            $catScore = 100;
        } else {
            $isoCat = DB::fetch('SELECT parent_id FROM categories WHERE id = ?', [$isoCatId]);
            $listingCat = DB::fetch('SELECT parent_id FROM categories WHERE id = ?', [$listingCatId]);
            $isoParent = (int)($isoCat['parent_id'] ?? 0);
            $listingParent = (int)($listingCat['parent_id'] ?? 0);
            if ($isoParent > 0 && $isoParent === $listingParent) {
                $catScore = 70;
            } elseif (($isoParent > 0 && $isoParent === $listingCatId)
                || ($listingParent > 0 && $listingParent === $isoCatId)) {
                $catScore = 55;
            } elseif ($isoParent > 0 && $listingParent > 0) {
                $gpIso = DB::fetch('SELECT parent_id FROM categories WHERE id = ?', [$isoParent]);
                $gpListing = DB::fetch('SELECT parent_id FROM categories WHERE id = ?', [$listingParent]);
                if ((int)($gpIso['parent_id'] ?? 0) === (int)($gpListing['parent_id'] ?? 0)
                    && (int)($gpIso['parent_id'] ?? 0) > 0) {
                    $catScore = 35;
                }
            }
        }
    }

    $valueScore = 0;
    $isoListingValue = (float)($iso['listing_value'] ?? 0);
    $listingValue = (float)($listing['estimated_value'] ?? 0);
    if ($isoListingValue > 0 && $listingValue > 0) {
        $diff = abs($isoListingValue - $listingValue);
        $avg = ($isoListingValue + $listingValue) / 2;
        $ratio = $avg > 0 ? $diff / $avg : 1;
        if ($ratio <= 0.05) $valueScore = 100;
        elseif ($ratio <= 0.15) $valueScore = 85;
        elseif ($ratio <= 0.30) $valueScore = 65;
        elseif ($ratio <= 0.50) $valueScore = 45;
        else $valueScore = max(5, (int)round(100 - $ratio * 110));
    } else {
        $valueScore = 50;
    }

    $distanceKm = null;
    $locScore = 0;
    $iLat = $iso['latitude'] ?? null;
    $iLng = $iso['longitude'] ?? null;
    $lLat = $listing['latitude'] ?? null;
    $lLng = $listing['longitude'] ?? null;
    if ($iLat !== null && $iLng !== null) {
        $distanceKm = iso_haversine_distance_km((float)$iLat, (float)$iLng, $lLat !== null ? (float)$lLat : null, $lLng !== null ? (float)$lLng : null);
        if ($distanceKm !== null) {
            if ($distanceKm <= 5) $locScore = 100;
            elseif ($distanceKm <= 20) $locScore = 85;
            elseif ($distanceKm <= 50) $locScore = 65;
            elseif ($distanceKm <= 150) $locScore = 45;
            elseif ($distanceKm <= 400) $locScore = 25;
            else $locScore = 10;
        }
    }
    if ($locScore <= 0) {
        $iCity = trim((string)($iso['city'] ?? ''));
        $lCity = trim((string)($listing['city'] ?? ''));
        if ($iCity !== '' && $lCity !== '') {
            if (mb_strtolower($iCity) === mb_strtolower($lCity)) {
                $locScore = 85;
            } else {
                $locScore = 25;
            }
        } else {
            $locScore = 40;
        }
    }

    $weights = [
        'title'       => 0.30,
        'description' => 0.10,
        'category'    => 0.20,
        'value'       => 0.15,
        'location'    => 0.10,
    ];
    $bonusCat = $catScore >= 80 ? 5 : 0;
    $bonusTitle = $titleScore >= 90 ? 5 : 0;
    $bonusVal = $valueScore >= 80 ? 5 : 0;
    $rawTotal =
        $titleScore * $weights['title']
        + $descScore * $weights['description']
        + $catScore * $weights['category']
        + $valueScore * $weights['value']
        + $locScore * $weights['location'];
    $total = (int)min(100, round($rawTotal + $bonusCat + $bonusTitle + $bonusVal));

    $scores = [
        'title'       => $titleScore,
        'category'    => $catScore,
        'value'       => $valueScore,
        'location'    => $locScore,
        'description' => $descScore,
    ];

    return [
        'total'        => $total,
        'title'        => $titleScore,
        'category'     => $catScore,
        'value'        => $valueScore,
        'location'     => $locScore,
        'description'  => $descScore,
        'distance_km'  => $distanceKm,
        'reason'       => iso_build_match_reason($scores),
    ];
}

function iso_find_matches_for_iso(int $isoId, int $limit = 20): array {
    $iso = iso_get_request($isoId);
    if (!$iso || $iso['status'] !== 'active') return [];
    $isoUserId = (int)$iso['user_id'];
    $isoCatId = (int)$iso['category_id'];
    $isoParent = (int)(DB::fetch('SELECT parent_id FROM categories WHERE id = ?', [$isoCatId])['parent_id'] ?? 0);

    $catIn = [$isoCatId];
    if ($isoParent > 0) {
        $catIn[] = $isoParent;
        $siblings = DB::fetchAll('SELECT id FROM categories WHERE parent_id = ? AND id != ?', [$isoParent, $isoCatId]);
        foreach ($siblings as $s) $catIn[] = (int)$s['id'];
        $gp = (int)(DB::fetch('SELECT parent_id FROM categories WHERE id = ?', [$isoParent])['parent_id'] ?? 0);
        if ($gp > 0) {
            $uncles = DB::fetchAll('SELECT id FROM categories WHERE parent_id = ? AND id != ?', [$gp, $isoParent]);
            foreach ($uncles as $u) $catIn[] = (int)$u['id'];
        }
    }
    $catIn = array_unique($catIn);

    $placeholders = implode(',', array_fill(0, count($catIn), '?'));
    $params = array_values($catIn);
    $params[] = $isoUserId;
    $candidates = DB::fetchAll(
        "SELECT l.*, u.name AS seller_name,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
         FROM listings l
         JOIN users u ON u.id = l.user_id
         WHERE l.category_id IN ({$placeholders})
           AND l.status = 'active'
           AND l.review_status = 'approved'
           AND l.user_id != ?
         ORDER BY l.created_at DESC
         LIMIT 150",
        $params
    );

    $scored = [];
    foreach ($candidates as $c) {
        $score = iso_calculate_match_score($iso, $c);
        if ($score['total'] >= 25) {
            $scored[] = array_merge($c, [
                'match_score'   => $score['total'],
                'score_title'   => $score['title'],
                'score_cat'     => $score['category'],
                'score_value'   => $score['value'],
                'score_loc'     => $score['location'],
                'score_desc'    => $score['description'],
                'distance_km'   => $score['distance_km'],
                'match_reason'  => $score['reason'],
            ]);
        }
    }

    usort($scored, static function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });

    return array_slice($scored, 0, $limit);
}

function iso_save_match(int $isoId, int $listingId, array $scoreData): int {
    $existing = DB::fetch(
        'SELECT id FROM iso_matches WHERE iso_id = ? AND listing_id = ?',
        [$isoId, $listingId]
    );
    if ($existing) {
        DB::query(
            'UPDATE iso_matches SET score = ?, distance_km = ?, match_reason = ?, updated_at = NOW() WHERE id = ?',
            [
                (int)$scoreData['total'],
                $scoreData['distance_km'],
                $scoreData['reason'],
                (int)$existing['id'],
            ]
        );
        return (int)$existing['id'];
    }
    return DB::insert('iso_matches', [
        'iso_id'       => $isoId,
        'listing_id'   => $listingId,
        'score'        => (int)$scoreData['total'],
        'distance_km'  => $scoreData['distance_km'],
        'match_reason' => $scoreData['reason'],
        'status'       => 'suggested',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

function iso_generate_and_save_matches(int $isoId, int $limit = 30): int {
    $matches = iso_find_matches_for_iso($isoId, $limit);
    $saved = 0;
    foreach ($matches as $m) {
        $score = [
            'total'       => $m['match_score'],
            'distance_km' => $m['distance_km'],
            'reason'      => $m['match_reason'],
        ];
        iso_save_match($isoId, (int)$m['id'], $score);
        $saved++;
    }
    return $saved;
}

function iso_get_saved_matches(int $isoId, int $limit = 12, ?string $minStatus = null): array {
    $sql = 'SELECT im.*, l.*, u.name AS seller_name, c.name AS category_name,
                   (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
            FROM iso_matches im
            JOIN listings l ON l.id = im.listing_id
            JOIN users u ON u.id = l.user_id
            JOIN categories c ON c.id = l.category_id
            WHERE im.iso_id = ? AND l.status = "active" AND l.review_status = "approved"';
    $params = [$isoId];
    if ($minStatus) {
        $sql .= ' AND im.status != ?';
        $params[] = $minStatus;
    }
    $sql .= ' ORDER BY im.score DESC, im.created_at DESC LIMIT ?';
    $params[] = $limit;
    return DB::fetchAll($sql, $params);
}

function iso_find_reverse_matches_for_listing(int $listingId, int $limit = 12): array {
    $listing = DB::fetch(
        'SELECT l.*, c.name AS cat_name FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.id = ?',
        [$listingId]
    );
    if (!$listing) return [];

    $catId = (int)$listing['category_id'];
    $parentId = (int)(DB::fetch('SELECT parent_id FROM categories WHERE id = ?', [$catId])['parent_id'] ?? 0);
    $catIn = [$catId];
    if ($parentId > 0) {
        $catIn[] = $parentId;
        $siblings = DB::fetchAll('SELECT id FROM categories WHERE parent_id = ? AND id != ?', [$parentId, $catId]);
        foreach ($siblings as $s) $catIn[] = (int)$s['id'];
    }
    $catIn = array_unique($catIn);
    $listingUserId = (int)$listing['user_id'];

    $placeholders = implode(',', array_fill(0, count($catIn), '?'));
    $params = array_values($catIn);
    $params[] = $listingUserId;
    $isoRequests = DB::fetchAll(
        "SELECT ir.*, l.title AS source_listing_title, l.estimated_value AS source_listing_value,
                u.name AS iso_user_name, c.name AS cat_name
         FROM iso_requests ir
         JOIN listings l ON l.id = ir.listing_id
         JOIN users u ON u.id = ir.user_id
         JOIN categories c ON c.id = ir.category_id
         WHERE ir.category_id IN ({$placeholders})
           AND ir.status = 'active'
           AND ir.user_id != ?
         ORDER BY ir.created_at DESC
         LIMIT 150",
        $params
    );

    $scored = [];
    $isoForMatch = [
        'title'         => $listing['title'],
        'description'   => $listing['description'],
        'category_id'   => $listing['category_id'],
        'listing_value' => $listing['estimated_value'],
        'latitude'      => $listing['latitude'],
        'longitude'     => $listing['longitude'],
        'city'          => $listing['city'],
    ];
    foreach ($isoRequests as $iso) {
        $score = iso_calculate_match_score($iso, $isoForMatch);
        if ($score['total'] >= 30) {
            $scored[] = array_merge($iso, [
                'match_score'   => $score['total'],
                'score_title'   => $score['title'],
                'score_cat'     => $score['category'],
                'score_value'   => $score['value'],
                'score_loc'     => $score['location'],
                'score_desc'    => $score['description'],
                'distance_km'   => $score['distance_km'],
                'match_reason'  => $score['reason'],
            ]);
        }
    }
    usort($scored, static function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });
    return array_slice($scored, 0, $limit);
}

function iso_get_matches_for_user_listings(int $userId, int $limit = 12): array {
    $myListings = DB::fetchAll(
        'SELECT id, title FROM listings WHERE user_id = ? AND status = "active" AND review_status = "approved" ORDER BY created_at DESC LIMIT 20',
        [$userId]
    );
    if (!$myListings) return [];
    $all = [];
    foreach ($myListings as $ml) {
        $rev = iso_find_reverse_matches_for_listing((int)$ml['id'], 5);
        foreach ($rev as $r) {
            $r['matched_from_listing_id'] = (int)$ml['id'];
            $r['matched_from_listing_title'] = $ml['title'];
            $all[] = $r;
        }
    }
    usort($all, static function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });
    return array_slice($all, 0, $limit);
}

function iso_update_match_status(int $isoId, int $listingId, string $status): bool {
    $allowed = ['suggested', 'viewed', 'interested', 'rejected', 'expired'];
    if (!in_array($status, $allowed, true)) return false;
    DB::query(
        'UPDATE iso_matches SET status = ?, updated_at = NOW() WHERE iso_id = ? AND listing_id = ?',
        [$status, $isoId, $listingId]
    );
    return true;
}

function iso_validate_request(array $data, bool $isUpdate = false): array {
    $errors = [];
    $title = trim((string)($data['title'] ?? ''));
    if (mb_strlen($title) < 3) $errors['title'] = 'عنوان باید حداقل ۳ کاراکتر باشد.';
    if (mb_strlen($title) > 191) $errors['title'] = 'عنوان بیش از حد طولانی است.';
    if (empty($data['listing_id']) || (int)$data['listing_id'] <= 0)
        $errors['listing_id'] = 'لطفاً آگهی مبدا را انتخاب کنید.';
    if (empty($data['category_id']) || (int)$data['category_id'] <= 0)
        $errors['category_id'] = 'لطفاً دسته‌بندی را انتخاب کنید.';
    return $errors;
}

function iso_user_wants_sms(int $userId): bool {
    if (!db_has_table('users') || !db_has_column('users', 'sms_iso_alerts')) {
        return true;
    }
    $row = DB::fetch('SELECT sms_iso_alerts FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$row) return true;
    return (int)($row['sms_iso_alerts'] ?? 1) === 1;
}

function iso_sms_already_sent(int $userId, int $isoId, int $listingId): bool {
    if (!db_has_table('iso_sms_logs')) return false;
    $row = DB::fetch(
        'SELECT id FROM iso_sms_logs WHERE user_id = ? AND iso_id = ? AND listing_id = ? LIMIT 1',
        [$userId, $isoId, $listingId]
    );
    return (bool)$row;
}

function iso_rate_limit_sms_user(int $userId, int $maxPerHour = 10): bool {
    if (!db_has_table('iso_sms_logs')) return true;
    $row = DB::fetch(
        'SELECT COUNT(*) AS c FROM iso_sms_logs WHERE user_id = ? AND status = "sent" AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        [$userId]
    );
    return (int)($row['c'] ?? 0) < $maxPerHour;
}

function iso_sms_attributes(array $payload): array {
    $titleShort = mb_strimwidth((string)($payload['listing_title'] ?? ''), 0, 24, '…');
    $cityShort = trim((string)($payload['city'] ?? '')) ?: '—';
    $map = (defined('SMS_ISO_MATCH_ATTRIBUTE_MAP') && is_array(SMS_ISO_MATCH_ATTRIBUTE_MAP))
        ? SMS_ISO_MATCH_ATTRIBUTE_MAP
        : ['var1' => '{title}', 'var2' => '{city}'];
    $attributes = [];
    foreach ($map as $key => $template) {
        $attributes[(string)$key] = strtr((string)$template, [
            '{title}' => $titleShort,
            '{city}'  => $cityShort,
            '{app_name}' => defined('APP_NAME') ? APP_NAME : 'Swapin',
        ]);
    }
    return $attributes;
}

function iso_notification_for_match(int $userId, int $isoId, int $listingId, array $listing, int $score): void {
    if (!db_has_table('notifications')) {
        return;
    }
    $titleShort = mb_strimwidth((string)($listing['title'] ?? ''), 0, 36, '…');
    $body = sprintf(
        'یک آگهی جدید با امتیاز %d٪ برای درخواست معاوضه شما پیدا شد: «%s»',
        min(100, $score),
        $titleShort
    );
    $link = APP_URL . '/listings/view.php?id=' . (int)$listingId;
    $existing = DB::fetch(
        'SELECT id FROM notifications WHERE user_id = ? AND type = ? AND link = ? AND is_read = 0 LIMIT 1',
        [$userId, 'iso_match', $link]
    );
    if ($existing) return;
    try {
        DB::insert('notifications', [
            'user_id'  => $userId,
            'type'     => 'iso_match',
            'title'    => 'مطابقت جدید ISO',
            'body'     => $body,
            'link'     => $link,
            'meta_json' => json_encode([
                'iso_id'     => $isoId,
                'listing_id' => $listingId,
                'score'      => $score,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_read'  => 0,
        ]);
    } catch (Throwable) {
    }
}

function iso_process_new_listing_matches(int $listingId, int $minScore = 45): int {
    $listing = DB::fetch(
        'SELECT l.*, u.phone, u.id AS owner_id FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ? LIMIT 1',
        [$listingId]
    );
    if (!$listing) return 0;
    if (($listing['status'] ?? '') !== 'active') return 0;
    if (($listing['review_status'] ?? '') !== 'approved') return 0;

    $matches = iso_find_reverse_matches_for_listing($listingId, 30);
    if (empty($matches)) return 0;

    $processed = 0;
    $smsSentThisRun = 0;
    $smsMaxPerListing = 3;

    foreach ($matches as $m) {
        $score = (int)($m['match_score'] ?? 0);
        if ($score < $minScore) continue;

        $isoId = (int)($m['id'] ?? 0);
        $isoUserId = (int)($m['user_id'] ?? 0);
        if ($isoUserId <= 0 || $isoId <= 0) continue;
        if ((int)$listing['owner_id'] === $isoUserId) continue;

        $scoreData = [
            'total'       => $score,
            'distance_km' => $m['distance_km'] ?? null,
            'reason'      => (string)($m['match_reason'] ?? 'پتانسیل معاوضه'),
        ];
        try {
            iso_save_match($isoId, $listingId, $scoreData);
        } catch (Throwable) {
        }

        try {
            iso_notification_for_match($isoUserId, $isoId, $listingId, $listing, $score);
        } catch (Throwable) {
        }

        if ($smsSentThisRun < $smsMaxPerListing && db_has_table('iso_sms_logs')) {
            if (!iso_sms_already_sent($isoUserId, $isoId, $listingId)
                && iso_user_wants_sms($isoUserId)
                && iso_rate_limit_sms_user($isoUserId)) {
                $isoUser = DB::fetch(
                    'SELECT phone FROM users WHERE id = ? LIMIT 1',
                    [$isoUserId]
                );
                $phone = trim((string)($isoUser['phone'] ?? ''));
                if ($phone !== '') {
                    $inserted = false;
                    try {
                        DB::insert('iso_sms_logs', [
                            'user_id'    => $isoUserId,
                            'iso_id'     => $isoId,
                            'listing_id' => $listingId,
                            'phone'      => $phone,
                            'status'     => 'pending',
                            'provider'   => defined('SMS_IRANPAYAMAK_LINE_NUMBER') ? 'iranpayamak' : null,
                        ]);
                        $inserted = true;
                    } catch (Throwable) {
                        $inserted = false;
                    }
                    if ($inserted) {
                        $attributes = iso_sms_attributes([
                            'listing_title' => (string)($listing['title'] ?? ''),
                            'city'          => (string)($listing['city'] ?? ''),
                        ]);
                        $patternCode = defined('SMS_ISO_MATCH_PATTERN_CODE') ? SMS_ISO_MATCH_PATTERN_CODE : null;
                        $ok = false;
                        $err = '';
                        try {
                            $ok = send_pattern_sms($phone, $attributes, $patternCode);
                        } catch (Throwable $e) {
                            $ok = false;
                            $err = $e->getMessage();
                        }
                        $status = $ok ? 'sent' : 'failed';
                        $errMsg = $ok ? null : ($err !== '' ? $err : last_sms_error());
                        try {
                            DB::query(
                                'UPDATE iso_sms_logs SET status = ?, message = ?, error = ?, provider_message_id = NULL WHERE user_id = ? AND iso_id = ? AND listing_id = ?',
                                [
                                    $status,
                                    json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                    $errMsg,
                                    $isoUserId, $isoId, $listingId,
                                ]
                            );
                        } catch (Throwable) {
                        }
                        if ($ok) {
                            $smsSentThisRun++;
                        }
                    }
                }
            }
        }
        $processed++;
    }
    return $processed;
}
