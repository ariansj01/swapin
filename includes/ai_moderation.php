<?php
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/listing_validator.php';
require_once __DIR__ . '/admin.php';

function ai_mod_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $row = DB::fetch('SELECT * FROM `ai_moderation_settings` WHERE `id` = 1 LIMIT 1');
    if (!$row) {
        $cache = [
            'enabled'              => true,
            'shadow_mode'          => true,
            'auto_approve_threshold' => 93,
            'auto_reject_threshold'  => 96,
            'safe_category_slugs'  => ['book','game-console','toy','household-small','kitchenware','mobile-accessory','laptop-accessory','audio','clothing','watch'],
            'never_approve_slugs'  => ['real-estate','car','motorcycle','service','job','heavy-equipment'],
        ];
        return $cache;
    }

    $safe  = json_decode($row['safe_category_slugs']  ?? '[]', true);
    $never = json_decode($row['never_approve_slugs'] ?? '[]', true);

    $cache = [
        'enabled'              => (bool)($row['enabled'] ?? 1),
        'shadow_mode'          => (bool)($row['shadow_mode'] ?? 1),
        'auto_approve_threshold' => (int)($row['auto_approve_threshold'] ?? 93),
        'auto_reject_threshold'  => (int)($row['auto_reject_threshold'] ?? 96),
        'safe_category_slugs'  => is_array($safe)  ? $safe  : [],
        'never_approve_slugs'  => is_array($never) ? $never : [],
    ];
    return $cache;
}

function ai_mod_system_prompt(): string {
    static $prompt = null;
    if ($prompt === null) {
        $path = __DIR__ . '/ai_moderation_prompt.txt';
        $prompt = is_readable($path) ? (string) file_get_contents($path) : '';
    }
    return $prompt;
}

function ai_mod_category_safe(?string $slug, array $settings): bool {
    if (!$slug) return false;
    return in_array($slug, $settings['safe_category_slugs'], true);
}

function ai_mod_category_never_approve(?string $slug, array $settings): bool {
    if (!$slug) return false;
    return in_array($slug, $settings['never_approve_slugs'], true);
}

function ai_mod_count_user_approved(int $userId): int {
    return (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = "active" AND review_status = "approved"',
        [$userId]
    )['c'] ?? 0);
}

function ai_mod_rule_layer(array $listing, ?array $category, int $userId, ?int $approvedCountOverride = null): array {
    $signals = [
        'bad_words_detected'   => false,
        'links_count'          => 0,
        'has_contact_info'     => false,
        'title_desc_match_pct' => 0,
        'user_approved_count'  => 0,
        'duplicate_similarity_pct' => 0,
        'is_new_user'          => false,
        'high_value_new_user'  => false,
        'price_anomaly'        => false,
        'short_description'    => false,
        'nonsense_pattern'     => false,
    ];
    $flags = [];
    $reasons = [];
    $decision = 'escalate';
    $confidence = 0.60;
    $persianNote = 'نیاز به بررسی دستی ادمین.';

    $title       = trim((string)($listing['title'] ?? ''));
    $description = trim((string)($listing['description'] ?? ''));
    $wantText    = trim((string)($listing['want_in_return'] ?? ''));
    $value       = (float)($listing['estimated_value'] ?? 0);
    $catSlug     = $category['slug'] ?? null;

    $badWords = listing_bad_words();
    $patterns = listing_nonsense_patterns();
    $allText  = $title . ' ' . $description . ' ' . $wantText;

    foreach ($badWords as $bw) {
        if (mb_stripos($allText, $bw) !== false) {
            $signals['bad_words_detected'] = true;
            $flags[] = 'spam';
            $reasons[] = 'R001';
            break;
        }
    }

    foreach ($patterns as $pattern => $msg) {
        if (!is_string($pattern) || $pattern === '' || @preg_match($pattern, '') === false) {
            continue;
        }
        if (preg_match($pattern, $allText) === 1) {
            $signals['nonsense_pattern'] = true;
            $flags[] = 'spam';
            $reasons[] = 'R014';
            break;
        }
    }

    $linkPattern = '/(https?:\/\/|t\.me\/|telegram|@[\w_]{3,}|whatsapp|09\d{9}|\+989\d{9})/ui';
    $linkMatches = [];
    preg_match_all($linkPattern, $description . ' ' . $title, $linkMatches);
    $signals['links_count'] = count($linkMatches[0] ?? []);
    if ($signals['links_count'] >= 3) {
        $flags[] = 'spam';
        $reasons[] = 'R005';
    }

    $contactPattern = '/(تـلگرام|تلگرام|واتساپ|واتس‌آپ|شماره|شماره تماس|تماس بگیرید|تماس بگیر|watsapp|whats app)/ui';
    if (preg_match($contactPattern, $allText) || preg_match('/09\d{9}/', $allText) || preg_match('/@[\w_]{3,}/', $allText)) {
        $signals['has_contact_info'] = true;
        $flags[] = 'contact_info';
        $reasons[] = 'R004';
    }

    $tlen = mb_strlen($title);
    $dlen = mb_strlen($description);
    if ($tlen > 0 && $dlen > 0) {
        $signals['title_desc_match_pct'] = $dlen > $tlen ? (int)min(100, round(($tlen / max(3, $dlen)) * 200)) : 70;
        $shared = array_intersect(
            preg_split('/\s+/u', $title) ?: [],
            preg_split('/\s+/u', $description) ?: []
        );
        $titleWords = count(preg_split('/\s+/u', $title) ?: []);
        if ($titleWords > 0) {
            $signals['title_desc_match_pct'] = max($signals['title_desc_match_pct'], (int)round(count($shared) / $titleWords * 100));
        }
    }
    if ($dlen < 30) {
        $signals['short_description'] = true;
        $flags[] = 'low_effort';
        $reasons[] = 'R010';
    }

    $approvedCount = $approvedCountOverride !== null ? max(0, $approvedCountOverride) : ai_mod_count_user_approved($userId);
    $signals['user_approved_count'] = $approvedCount;
    $signals['is_new_user'] = $approvedCount < 2;
    if ($signals['is_new_user'] && $value >= 50_000_000) {
        $signals['high_value_new_user'] = true;
        $flags[] = 'new_user_high_value';
        $reasons[] = 'R009';
    }

    if ($signals['bad_words_detected'] || $signals['nonsense_pattern']) {
        $decision   = 'reject';
        $confidence = 0.98;
        $persianNote = 'تشخیص کلمه نامناسب یا متن تستی/اسپم توسط سیستم قاعده‌محور.';
    } elseif ($signals['has_contact_info'] || $signals['links_count'] >= 3) {
        $decision   = 'reject';
        $confidence = 0.90;
        $flags[]    = 'spam';
        $persianNote = 'وجود شماره تماس، تلگرام یا لینک‌های زیاد در متن آگهی.';
    } else {
        $decision   = 'escalate';
        $confidence = 0.65;
        $persianNote = 'پاس قاعده‌محور عبور کرد — نیاز به تحلیل عمیق‌تر با هوش مصنوعی یا بررسی دستی.';
    }

    return [
        'decision'     => $decision,
        'confidence'   => $confidence,
        'flags'        => $flags,
        'reasons'      => $reasons,
        'signals'      => $signals,
        'persian_note' => $persianNote,
        'needs_llm'    => !($decision === 'reject' && $confidence >= 0.95),
    ];
}

function ai_mod_llm_layer(array $listing, ?array $category, int $userId, ?int $approvedCountOverride = null): ?array {
    if (!ai_is_configured()) {
        return null;
    }

    $approvedCount = $approvedCountOverride !== null ? max(0, $approvedCountOverride) : ai_mod_count_user_approved($userId);
    $payload = [
        'listing' => [
            'title'           => $listing['title'] ?? '',
            'description'     => $listing['description'] ?? '',
            'want_in_return'  => $listing['want_in_return'] ?? '',
            'condition'       => $listing['condition'] ?? 'good',
            'estimated_value' => (float)($listing['estimated_value'] ?? 0),
            'sell_price'      => $listing['sell_price'] ?? null,
            'listing_mode'    => $listing['listing_mode'] ?? 'swap',
            'city'            => $listing['city'] ?? '',
        ],
        'category' => $category ? [
            'slug' => $category['slug'] ?? '',
            'name' => $category['name'] ?? '',
        ] : null,
        'user_context' => [
            'approved_listings_count' => $approvedCount,
            'is_trusted_user'         => $approvedCount >= 3,
        ],
        'instruction' => 'تحلیل آگهی پلتفرم معاوضه سواَپین را انجام بده. فقط JSON طبق ساختار moderation برگردان. زبان پاسخ: فارسی.',
    ];

    $messages = [
        ['role' => 'system', 'content' => ai_mod_system_prompt()],
        ['role' => 'user',   'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
    ];

    $result = ai_chat_completion($messages, 0.15);
    $parsed = $result['parsed'] ?? null;

    if (!$parsed || ($parsed['type'] ?? '') !== 'moderation') {
        return null;
    }

    $decision   = in_array($parsed['decision'] ?? 'escalate', ['approve','reject','escalate'], true)
        ? $parsed['decision'] : 'escalate';
    $confidence = (float)max(0, min(1, (float)($parsed['confidence'] ?? 0.50)));

    return [
        'decision'     => $decision,
        'confidence'   => $confidence,
        'flags'        => $parsed['unsafe_flags'] ?? [],
        'reasons'      => $parsed['reason_codes'] ?? [],
        'signals'      => $parsed['rule_signals'] ?? null,
        'persian_note' => trim((string)($parsed['persian_note'] ?? '')),
        'provider'     => $result['provider'] ?? null,
    ];
}

function ai_mod_merge_results(array $ruleResult, ?array $llmResult, ?array $category, array $settings): array {
    $confApproval = $settings['auto_approve_threshold'] / 100;
    $confReject   = $settings['auto_reject_threshold']   / 100;
    $catSlug      = $category['slug'] ?? null;
    $neverApprove = ai_mod_category_never_approve($catSlug, $settings);
    $isSafe       = ai_mod_category_safe($catSlug, $settings);

    $finalDecision = 'escalate';
    $finalConf     = 0.50;
    $finalFlags    = $ruleResult['flags'];
    $finalReasons  = $ruleResult['reasons'];
    $finalNote     = $ruleResult['persian_note'];
    $matched       = null;
    $provider      = 'rules';

    if ($ruleResult['decision'] === 'reject' && $ruleResult['confidence'] >= $confReject) {
        $finalDecision = 'reject';
        $finalConf     = $ruleResult['confidence'];
        $matched       = 'rule_reject_hard';
    } elseif ($llmResult) {
        $provider    = $llmResult['provider'] ?? 'rules';
        $finalFlags  = array_values(array_unique(array_merge($finalFlags,  $llmResult['flags']   ?? [])));
        $finalReasons= array_values(array_unique(array_merge($finalReasons,$llmResult['reasons'] ?? [])));
        if (!empty($llmResult['persian_note'])) {
            $finalNote = $llmResult['persian_note'];
        }

        if ($llmResult['decision'] === 'reject' && $llmResult['confidence'] >= $confReject && empty(array_diff($llmResult['flags'] ?? [], ['wrong_category','low_effort']))) {
            $finalDecision = 'reject';
            $finalConf     = $llmResult['confidence'];
            $matched       = 'llm_reject_high';
        } elseif ($neverApprove) {
            $finalDecision = 'escalate';
            $finalConf     = max($ruleResult['confidence'], $llmResult['confidence']);
            if (empty($finalFlags)) $finalFlags[] = 'high_risk_category';
            if (empty($finalReasons)) $finalReasons[] = 'R008';
            $matched   = 'category_never_approve';
            $finalNote = 'دسته‌بندی پرریسک — نیاز به بررسی دستی ادمین.';
        } elseif ($llmResult['decision'] === 'approve' && $llmResult['confidence'] >= $confApproval && $isSafe) {
            $finalDecision = 'approve';
            $finalConf     = $llmResult['confidence'];
            $matched       = 'llm_approve_safe_category';
        } elseif ($llmResult['decision'] === 'approve' && $llmResult['confidence'] >= $confApproval && !$isSafe) {
            $finalDecision = 'escalate';
            $finalConf     = $llmResult['confidence'];
            $matched       = 'llm_approve_but_unsafe_category';
        } else {
            $finalDecision = 'escalate';
            $finalConf     = max($ruleResult['confidence'], $llmResult['confidence']);
            $matched       = 'llm_escalate_mixed';
        }
    } else {
        $provider = 'rules_only';
        if ($ruleResult['decision'] === 'approve' && $isSafe && $ruleResult['confidence'] >= $confApproval) {
            $finalDecision = 'approve';
            $finalConf     = $ruleResult['confidence'];
            $matched       = 'rule_approve_safe';
        } else {
            $finalDecision = 'escalate';
            $finalConf     = $ruleResult['confidence'];
            $matched       = 'rule_escalate_no_llm';
        }
    }

    $confPct = (int)round($finalConf * 100);
    return [
        'decision'     => $finalDecision,
        'confidence'   => $confPct,
        'flags'        => $finalFlags,
        'reasons'      => $finalReasons,
        'note'         => $finalNote,
        'matched'      => $matched,
        'provider'     => $provider,
        'rule_signals' => $ruleResult['signals'] ?? null,
    ];
}

function ai_mod_apply_or_shadow(int $listingId, int $userId, array $merged): array {
    $settings = ai_mod_settings();
    $note     = $merged['note'] ?? '';

    DB::query(
        'INSERT INTO `listing_ai_reviews`
         (`listing_id`,`user_id`,`review_mode`,`ai_decision`,`ai_confidence`,`ai_provider`,
          `rule_signals`,`unsafe_flags`,`reason_codes`,`human_readable_note`,`matched_threshold`,`latency_ms`)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            `ai_decision`        = VALUES(`ai_decision`),
            `ai_confidence`      = VALUES(`ai_confidence`),
            `ai_provider`        = VALUES(`ai_provider`),
            `rule_signals`       = VALUES(`rule_signals`),
            `unsafe_flags`       = VALUES(`unsafe_flags`),
            `reason_codes`       = VALUES(`reason_codes`),
            `human_readable_note`= VALUES(`human_readable_note`),
            `matched_threshold`  = VALUES(`matched_threshold`),
            `updated_at`         = NOW()',
        [
            $listingId,
            $userId,
            $settings['shadow_mode'] ? 'shadow' : 'auto',
            $merged['decision'],
            $merged['confidence'],
            $merged['provider'] ?? null,
            json_encode($merged['rule_signals'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($merged['flags']        ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($merged['reasons']      ?? [], JSON_UNESCAPED_UNICODE),
            $note,
            $merged['matched'] ?? null,
            null,
        ]
    );

    DB::update('listings', [
        'ai_reviewed'   => 1,
        'ai_suggestion' => $merged['decision'],
        'ai_confidence' => $merged['confidence'],
        'ai_note'       => $note ? mb_substr($note, 0, 500) : null,
    ], 'id = ?', [$listingId]);

    $actuallyApplied = false;
    $appliedAction   = 'shadow_logged';

    if ($settings['enabled'] && !$settings['shadow_mode']) {
        if ($merged['decision'] === 'approve') {
            admin_approve_listing($listingId, '[AI Auto] ' . $note);
            $actuallyApplied = true;
            $appliedAction   = 'auto_approved';
        } elseif ($merged['decision'] === 'reject' && mb_strlen($note) >= 5) {
            admin_reject_listing($listingId, '[AI Auto] ' . $note);
            $actuallyApplied = true;
            $appliedAction   = 'auto_rejected';
        }
    }

    return [
        'suggestion'      => $merged['decision'],
        'confidence'      => $merged['confidence'],
        'note'            => $note,
        'mode'            => $settings['shadow_mode'] ? 'shadow' : 'auto',
        'actually_applied'=> $actuallyApplied,
        'applied_action'  => $appliedAction,
        'matched'         => $merged['matched'] ?? null,
    ];
}

function ai_mod_review_listing(int $listingId): array {
    $settings = ai_mod_settings();
    $listing  = DB::fetch(
        'SELECT l.*, c.slug AS cat_slug, c.name AS cat_name
         FROM listings l
         LEFT JOIN categories c ON c.id = l.category_id
         WHERE l.id = ? LIMIT 1',
        [$listingId]
    );
    if (!$listing) {
        return ['error' => 'listing_not_found'];
    }

    $userId   = (int)$listing['user_id'];
    $category = $listing['cat_slug'] ? ['slug' => $listing['cat_slug'], 'name' => $listing['cat_name']] : null;

    $startMs   = microtime(true);
    $ruleRes   = ai_mod_rule_layer($listing, $category, $userId);

    $llmRes    = null;
    if ($settings['enabled'] && !empty($ruleRes['needs_llm']) && ai_is_configured()) {
        try {
            $llmRes = ai_mod_llm_layer($listing, $category, $userId);
        } catch (Throwable) {
            $llmRes = null;
        }
    }

    $merged    = ai_mod_merge_results($ruleRes, $llmRes, $category, $settings);
    $latencyMs = (int)round((microtime(true) - $startMs) * 1000);

    $reviewRow = DB::fetch('SELECT id FROM listing_ai_reviews WHERE listing_id = ? LIMIT 1', [$listingId]);
    if ($reviewRow) {
        DB::query('UPDATE listing_ai_reviews SET latency_ms = ? WHERE id = ?', [$latencyMs, (int)$reviewRow['id']]);
    }

    return ai_mod_apply_or_shadow($listingId, $userId, $merged);
}

function ai_mod_mark_admin_action(int $listingId, string $adminDecision, int $adminId, string $adminNote = ''): void {
    DB::query(
        'UPDATE `listing_ai_reviews`
         SET `admin_decision` = ?, `admin_id` = ?, `admin_note` = ?, `updated_at` = NOW()
         WHERE `listing_id` = ? LIMIT 1',
        [
            in_array($adminDecision, ['approved','rejected'], true) ? $adminDecision : null,
            $adminId,
            $adminNote ? mb_substr($adminNote, 0, 500) : null,
            $listingId,
        ]
    );
}

function ai_mod_get_stats(): array {
    if (!db_has_table('listing_ai_reviews')) {
        return [
            'total_reviewed' => 0,
            'decisions'      => ['approve' => 0, 'reject' => 0, 'escalate' => 0],
            'admin_compared' => 0,
            'accuracy_pct'   => null,
            'table_missing' => true,
        ];
    }
    try {
        $totalReviewed = (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM listing_ai_reviews'
        )['c'] ?? 0);

        $byDecision = DB::fetchAll(
            'SELECT ai_decision, COUNT(*) AS c FROM listing_ai_reviews GROUP BY ai_decision'
        );
        $decisions = [];
        foreach ($byDecision as $row) {
            $decisions[$row['ai_decision']] = (int)$row['c'];
        }

        $agreeRow = DB::fetch(
            "SELECT COUNT(*) AS c FROM listing_ai_reviews
             WHERE (ai_decision = 'approve' AND admin_decision = 'approved')
                OR (ai_decision = 'reject'  AND admin_decision = 'rejected')"
        );
        $agreements = (int)($agreeRow['c'] ?? 0);

        $comparedTotal = (int)(DB::fetch(
            "SELECT COUNT(*) AS c FROM listing_ai_reviews WHERE admin_decision IS NOT NULL"
        )['c'] ?? 0);

        $accuracy = $comparedTotal > 0 ? (int)round($agreements / max(1, $comparedTotal) * 100) : null;
    } catch (Throwable) {
        return [
            'total_reviewed' => 0,
            'decisions'      => ['approve' => 0, 'reject' => 0, 'escalate' => 0],
            'admin_compared' => 0,
            'accuracy_pct'   => null,
        ];
    }

    return [
        'total_reviewed'  => $totalReviewed,
        'decisions'       => $decisions + ['approve' => 0, 'reject' => 0, 'escalate' => 0],
        'admin_compared'  => $comparedTotal,
        'accuracy_pct'    => $accuracy,
    ];
}

/**
 * Production moderation pipeline without any INSERT/UPDATE.
 * Does not read listing rows or user history from the database.
 *
 * @return array{ok:bool,shadow:true,suggestion:string,confidence:int,note:string,matched:?string,provider:string,flags:array,reasons:array,rule_signals:?array,llm:?array}
 */
function ai_mod_review_sandbox(array $listing, ?array $category, int $simulatedApprovedCount = 0): array {
    $settings = [
        'enabled'                => true,
        'shadow_mode'            => true,
        'auto_approve_threshold' => 93,
        'auto_reject_threshold'  => 96,
        'safe_category_slugs'    => ['book','game-console','toy','household-small','kitchenware','mobile-accessory','laptop-accessory','audio','clothing','watch'],
        'never_approve_slugs'    => ['real-estate','car','motorcycle','service','job','heavy-equipment'],
    ];

    $ruleRes = ai_mod_rule_layer($listing, $category, 0, $simulatedApprovedCount);

    $llmRes = null;
    if (!empty($ruleRes['needs_llm']) && ai_is_configured()) {
        try {
            $llmRes = ai_mod_llm_layer($listing, $category, 0, $simulatedApprovedCount);
        } catch (Throwable) {
            $llmRes = null;
        }
    }

    $merged = ai_mod_merge_results($ruleRes, $llmRes, $category, $settings);

    return [
        'ok'           => true,
        'shadow'       => true,
        'suggestion'   => $merged['decision'],
        'confidence'   => $merged['confidence'],
        'note'         => $merged['note'],
        'matched'      => $merged['matched'] ?? null,
        'provider'     => $merged['provider'] ?? 'rules',
        'flags'        => $merged['flags'] ?? [],
        'reasons'      => $merged['reasons'] ?? [],
        'rule_signals' => $merged['rule_signals'] ?? $ruleRes['signals'] ?? null,
        'llm'          => $llmRes ? [
            'decision'     => $llmRes['decision'],
            'confidence'   => (int) round(($llmRes['confidence'] ?? 0) * 100),
            'persian_note' => $llmRes['persian_note'] ?? '',
            'flags'        => $llmRes['flags'] ?? [],
        ] : null,
        'applied_action' => 'sandbox_not_applied',
        'mode'           => 'sandbox',
    ];
}
