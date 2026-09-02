<?php
require_once __DIR__ . '/asset_valuation.php';

function negotiator_fetch_offer(int $offerId, ?int $userId = null): ?array {
    $sql = 'SELECT o.*,
                   l.id AS target_id, l.title AS target_title, l.category_id AS target_cat_id,
                   l.condition AS target_condition, l.estimated_value AS target_estval,
                   l.sell_price AS target_price, l.city AS target_city, l.user_id AS target_owner,
                   l.grade AS target_grade, l.year AS target_year,
                   ol.id AS source_id, ol.title AS source_title, ol.category_id AS source_cat_id,
                   ol.condition AS source_condition, ol.estimated_value AS source_estval,
                   ol.sell_price AS source_price, ol.city AS source_city, ol.user_id AS source_owner,
                   ol.grade AS source_grade, ol.year AS source_year
            FROM trade_offers o
            JOIN listings l ON l.id = o.listing_id
            LEFT JOIN listings ol ON ol.id = o.offer_listing_id
            WHERE o.id = ? LIMIT 1';
    $row = DB::fetch($sql, [$offerId]);
    if (!$row) return null;
    if ($userId !== null) {
        $isOwner = (int)($row['target_owner'] ?? 0) === (int)$userId;
        $isSender = (int)($row['from_user_id'] ?? 0) === (int)$userId;
        if (!$isOwner && !$isSender) return null;
    }
    return $row;
}

function negotiator_fetch_messages(int $offerId, int $limit = 20): array {
    if (!db_has_table('messages')) return [];
    $rows = DB::fetchAll(
        'SELECT from_user_id, body, created_at FROM messages WHERE offer_id = ? ORDER BY created_at DESC LIMIT ?',
        [$offerId, $limit]
    );
    return array_reverse($rows);
}

function negotiator_build_system_prompt(): string {
    return 'You are a swap negotiation assistant working for Swapin (Iranian C2C marketplace). '
        . 'Language: ALL output MUST be in Persian (Farsi) — فارسی. '
        . 'Analyze swap offers fairly. Never give exact market prices as facts; always qualify values as "تقریبی" or "تخمینی". '
        . 'Your task: compare the two sides, assess fairness, propose a cash difference if needed, and generate 3 reply variants (friendly / negotiation / firm). '
        . 'Respond STRICTLY as a valid JSON object with these keys (no markdown, no trailing text, no explanation):'
        . '{"assessment":"unfavorable|neutral|favorable","confidence":0.0..1.0,"your_value":integer,"their_value":integer,"difference":integer,"suggested_cash_difference":integer,"reason":"string (Persian, max 150 chars)","suggested_replies":["string (friendly)","string (negotiation)","string (firm)"]}';
}

function negotiator_build_user_prompt(array $offer, array $messages, bool $isRecipient, int $recipientUserId): string {
    $targetValue = av_calculate_listing_value([
        'sell_price'      => (float)($offer['target_price'] ?? 0),
        'estimated_value' => (float)($offer['target_estval'] ?? 0),
        'condition'       => (string)($offer['target_condition'] ?? 'good'),
        'category_id'     => (int)($offer['target_cat_id'] ?? 0),
        'year'            => $offer['target_year'] ?? null,
    ]);
    $sourceValue = av_calculate_listing_value([
        'sell_price'      => (float)($offer['source_price'] ?? 0),
        'estimated_value' => (float)($offer['source_estval'] ?? 0),
        'condition'       => (string)($offer['source_condition'] ?? 'good'),
        'category_id'     => (int)($offer['source_cat_id'] ?? 0),
        'year'            => $offer['source_year'] ?? null,
    ]);

    if ($isRecipient) {
        $yourListing = [
            'title' => (string)($offer['target_title'] ?? ''),
            'value' => $targetValue['value'],
            'condition' => (string)($offer['target_condition'] ?? ''),
            'city'  => (string)($offer['target_city'] ?? ''),
        ];
        $theirListing = [
            'title' => (string)($offer['source_title'] ?? ''),
            'value' => $sourceValue['value'],
            'condition' => (string)($offer['source_condition'] ?? ''),
            'city'  => (string)($offer['source_city'] ?? ''),
        ];
    } else {
        $yourListing = [
            'title' => (string)($offer['source_title'] ?? ''),
            'value' => $sourceValue['value'],
            'condition' => (string)($offer['source_condition'] ?? ''),
            'city'  => (string)($offer['source_city'] ?? ''),
        ];
        $theirListing = [
            'title' => (string)($offer['target_title'] ?? ''),
            'value' => $targetValue['value'],
            'condition' => (string)($offer['target_condition'] ?? ''),
            'city'  => (string)($offer['target_city'] ?? ''),
        ];
    }

    $offerCredit = (float)($offer['offer_credit'] ?? 0);
    $counterCredit = $offer['counter_offer_credit'] ?? null;
    $prevMsgs = array_slice($messages, -6);

    $lines = [];
    $lines[] = 'Swap Offer Analysis:';
    $lines[] = 'You (recipient userId=' . $recipientUserId . ') receive this offer.';
    $lines[] = 'Role: ' . ($isRecipient ? 'you are RECEIVER (owner of target listing)' : 'you are SENDER (offer creator)');
    $lines[] = 'Your Listing: ' . json_encode($yourListing, JSON_UNESCAPED_UNICODE);
    $lines[] = 'Their Listing: ' . json_encode($theirListing, JSON_UNESCAPED_UNICODE);
    $lines[] = 'Offer cash difference (they offer to pay your way): ' . $offerCredit . ' تومان';
    if ($counterCredit !== null) {
        $lines[] = 'Counter offer cash difference previously requested: ' . ((float)$counterCredit) . ' تومان';
    }
    $lines[] = 'Offer status: ' . ($offer['status'] ?? 'pending');
    if (!empty($prevMsgs)) {
        $lines[] = 'Recent negotiation messages (oldest first):';
        foreach ($prevMsgs as $m) {
            $lines[] = '  [' . ($m['from_user_id'] == $recipientUserId ? 'me' : 'them') . '] ' . mb_strimwidth((string)($m['body'] ?? ''), 0, 120, '…');
        }
    }

    $lines[] = '';
    $lines[] = 'Compute:';
    $lines[] = '- assessment = favorable|neutral|unfavorable from YOUR (recipient) perspective only.';
    $lines[] = '- your_value = integer تومان (تقریبی).';
    $lines[] = '- their_value = integer تومان (تقریبی).';
    $lines[] = '- difference = (your_value - their_value). positive means their side is lower.';
    $lines[] = '- suggested_cash_difference = fair cash compensation (from them to you) or 0 if balanced. only non-negative (they always pay your way if imbalance).';
    $lines[] = '- reason = 1-2 sentences in Persian, short, mention values as تخمینی, NEVER absolute.';
    $lines[] = '- suggested_replies = EXACTLY 3 Persian reply templates for the user to click:';
    $lines[] = '  [0] = friendly / soft';
    $lines[] = '  [1] = negotiation-focused / question';
    $lines[] = '  [2] = firm but polite (clear condition)';
    $lines[] = '';
    $lines[] = 'Keep reply texts short (<=90 chars each). Use warm, local Persian tone appropriate for Iranian C2C marketplace.';
    $lines[] = 'CRITICAL: Output ONLY valid JSON. No text outside JSON braces.';

    return implode("\n", $lines);
}

function negotiator_sanitize_response(array $raw): array {
    $assessment = (string)($raw['assessment'] ?? 'neutral');
    if (!in_array($assessment, ['unfavorable', 'neutral', 'favorable'], true)) {
        $assessment = 'neutral';
    }
    $conf = (float)($raw['confidence'] ?? 0.5);
    if ($conf < 0) $conf = 0;
    if ($conf > 1) $conf = 1;

    $yourVal = (int)($raw['your_value'] ?? 0);
    $theirVal = (int)($raw['their_value'] ?? 0);
    $diff = (int)($raw['difference'] ?? ($yourVal - $theirVal));
    $sugg = max(0, (int)($raw['suggested_cash_difference'] ?? 0));
    $reason = trim((string)($raw['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'تحلیل در دسترس نیست.';
    }
    $reason = mb_strimwidth($reason, 0, 200, '…');

    $replies = [];
    $rawReplies = $raw['suggested_replies'] ?? [];
    if (is_array($rawReplies)) {
        foreach ($rawReplies as $r) {
            $s = trim((string)$r);
            if ($s === '') continue;
            $replies[] = mb_strimwidth($s, 0, 160, '…');
            if (count($replies) >= 3) break;
        }
    }
    while (count($replies) < 3) {
        $replies[] = match (count($replies)) {
            0 => 'متوجه شدم، کمی بررسی می‌کنم و به‌زودی پاسخ می‌دهم.',
            1 => 'در مورد مابه‌التفاوت نقدی امکان توافق بیشتری وجود دارد؟',
            2 => 'با این مبلغ مابه‌التفاوت برایم به‌صرفه نیست؛ لطفاً مقدار را افزایش دهید.',
        };
    }

    return [
        'assessment'              => $assessment,
        'confidence'              => round($conf, 2),
        'your_value'              => max(0, $yourVal),
        'their_value'             => max(0, $theirVal),
        'difference'              => $diff,
        'suggested_cash_difference'=> $sugg,
        'reason'                  => $reason,
        'suggested_replies'       => array_slice($replies, 0, 3),
    ];
}

function negotiator_ai_rate_limit_hit(int $userId): bool {
    if (!db_has_table('notifications')) return false;
    $key = 'negotiator_rate:' . $userId . ':' . (int)floor(time() / 300);
    try {
        $existing = DB::fetch(
            "SELECT id FROM notifications WHERE user_id = ? AND type = ? AND `link` = ? LIMIT 1",
            [$userId, 'system', $key]
        );
        if ($existing) {
            $cnt = 0;
            $row = DB::fetch(
                'SELECT CAST(body AS UNSIGNED) AS c FROM notifications WHERE id = ?',
                [(int)$existing['id']]
            );
            $cnt = (int)($row['c'] ?? 0);
            if ($cnt >= 5) return true;
            DB::query('UPDATE notifications SET body = ? WHERE id = ?', [(string)($cnt + 1), (int)$existing['id']]);
        } else {
            DB::insert('notifications', [
                'user_id' => $userId,
                'type'    => 'system',
                'title'   => 'negotiator_counter',
                'body'    => '1',
                'link'    => $key,
                'is_read' => 1,
            ]);
        }
    } catch (Throwable) {
    }
    return false;
}

/**
 * @return array{ok:bool,error?:string,result?:array,from_cache?:bool,provider?:?string}
 */
function negotiator_analyze_offer(int $offerId, int $userId): array {
    $offer = negotiator_fetch_offer($offerId, $userId);
    if (!$offer) {
        return ['ok' => false, 'error' => 'offer_not_found'];
    }
    $isRecipient = (int)($offer['target_owner'] ?? 0) === $userId;
    $messages = negotiator_fetch_messages($offerId);

    $targetValue = av_calculate_listing_value([
        'sell_price'      => (float)($offer['target_price'] ?? 0),
        'estimated_value' => (float)($offer['target_estval'] ?? 0),
        'condition'       => (string)($offer['target_condition'] ?? 'good'),
        'category_id'     => (int)($offer['target_cat_id'] ?? 0),
        'year'            => $offer['target_year'] ?? null,
    ]);
    $sourceValue = av_calculate_listing_value([
        'sell_price'      => (float)($offer['source_price'] ?? 0),
        'estimated_value' => (float)($offer['source_estval'] ?? 0),
        'condition'       => (string)($offer['source_condition'] ?? 'good'),
        'category_id'     => (int)($offer['source_cat_id'] ?? 0),
        'year'            => $offer['source_year'] ?? null,
    ]);
    $yourValue = $isRecipient ? $targetValue['value'] : $sourceValue['value'];
    $theirValue = $isRecipient ? $sourceValue['value'] : $targetValue['value'];
    $fallback = negotiator_build_rule_based($yourValue, $theirValue, (float)($offer['offer_credit'] ?? 0));

    if (!ai_is_configured()) {
        return [
            'ok'       => true,
            'provider' => null,
            'from_rule_based' => true,
            'result'   => $fallback,
        ];
    }
    if (negotiator_ai_rate_limit_hit($userId)) {
        return [
            'ok'       => true,
            'provider' => null,
            'from_rule_based' => true,
            'rate_limited' => true,
            'result'   => $fallback,
        ];
    }

    $messagesPayload = [
        ['role' => 'system', 'content' => negotiator_build_system_prompt()],
        ['role' => 'user',   'content' => negotiator_build_user_prompt($offer, $messages, $isRecipient, $userId)],
    ];

    try {
        $resp = ai_chat_completion($messagesPayload, 0.2);
    } catch (Throwable $e) {
        return [
            'ok'       => true,
            'provider' => null,
            'from_rule_based' => true,
            'ai_error' => $e->getMessage(),
            'result'   => $fallback,
        ];
    }

    $parsed = $resp['parsed'] ?? null;
    if (is_array($parsed) && !empty($parsed)) {
        $sanitized = negotiator_sanitize_response($parsed);
        if ($sanitized['your_value'] <= 0) $sanitized['your_value'] = $yourValue;
        if ($sanitized['their_value'] <= 0) $sanitized['their_value'] = $theirValue;
        if ($sanitized['difference'] === 0 && $yourValue !== $theirValue) {
            $sanitized['difference'] = $sanitized['your_value'] - $sanitized['their_value'];
        }
        if ($sanitized['suggested_cash_difference'] === 0) {
            $sanitized['suggested_cash_difference'] = $fallback['suggested_cash_difference'];
        }
        return [
            'ok'       => true,
            'provider' => $resp['provider'] ?? null,
            'from_rule_based' => false,
            'result'   => $sanitized,
        ];
    }

    return [
        'ok'       => true,
        'provider' => $resp['provider'] ?? null,
        'from_rule_based' => true,
        'ai_error' => 'empty_or_invalid_ai_response',
        'result'   => $fallback,
    ];
}

function negotiator_build_rule_based(int $yourValue, int $theirValue, float $offerCash): array {
    $diff = $yourValue - $theirValue;
    $absDiff = abs($diff);

    if ($yourValue <= 0 || $theirValue <= 0) {
        return [
            'assessment'               => 'neutral',
            'confidence'               => 0.3,
            'your_value'               => max(0, $yourValue),
            'their_value'              => max(0, $theirValue),
            'difference'               => $diff,
            'suggested_cash_difference'=> 0,
            'reason'                   => 'اطلاعات کافی برای تخمین دقیق ارزش دو طرف وجود ندارد؛ لطفاً جزئیات بیشتر را بررسی کنید.',
            'suggested_replies'        => [
                'متوجه شدم، کمی بررسی می‌کنم و به‌زودی پاسخ می‌دهم.',
                'در مورد جزئیات کالاها و مابه‌التفاوت امکان صحبت بیشتری هست؟',
                'قبل از تصمیم، نیاز به گرفتن اطلاعات بیشتری از وضعیت کالا دارم.',
            ],
        ];
    }

    $avg = ($yourValue + $theirValue) / 2;
    $ratio = $avg > 0 ? $absDiff / $avg : 0;

    $suggDiff = 0;
    if ($diff > 0) {
        $suggDiff = (int)round($diff * 0.9);
        if ($offerCash > 0) {
            $remaining = $suggDiff - (int)$offerCash;
            $suggDiff = max(0, $remaining > 0 ? (int)$offerCash + (int)round($remaining * 0.8) : $suggDiff);
        }
    }

    if ($ratio <= 0.05) {
        $assessment = 'favorable';
        $reason = 'ارزش تقریبی دو کالا نسبتاً نزدیک هم هستند و پیشنهاد از نظر مالی متعادل به نظر می‌رسد.';
    } elseif ($ratio <= 0.15) {
        $assessment = 'neutral';
        if ($diff > 0) {
            $reason = 'ارزش تقریبی کالای پیشنهادی کمی کمتر از کالای شما است؛ مابه‌التفاوت اندکی می‌تواند معامله را متعادل کند.';
        } else {
            $reason = 'ارزش تقریبی کالای پیشنهادی کمی بیشتر از کالای شما است؛ پیشنهاد جذابی است.';
        }
    } else {
        $assessment = $diff > 0 ? 'unfavorable' : 'favorable';
        if ($diff > 0) {
            $reason = 'فاصله قابل‌توجهی بین ارزش تخمینی کالای شما و کالای پیشنهادی وجود دارد؛ دریافت مابه‌التفاوت نقدی توصیه می‌شود.';
        } else {
            $reason = 'ارزش تقریبی کالای پیشنهادی به‌طور معناداری بالاتر از کالای شما است؛ این پیشنهاد از نظر مالی به نفع شماست.';
        }
    }

    $fmtYour = fmt_num($yourValue);
    $fmtTheir = fmt_num($theirValue);
    $fmtSugg = fmt_num($suggDiff);
    if ($suggDiff > 0) {
        $replyFriendly = "اگر حدود {$fmtSugg} تومان مابه‌التفاوت هم اضافه کنید، برایم کاملاً مناسبه.";
        $replyNegotiation = 'به نظرم با توجه به ارزش تقریبی دو کالا، کمی افزایش مابه‌التفاوت امکان‌پذیر هست؟';
        $replyFirm = "با این مبلغ مابه‌التفاوت برام به‌صرفه نیست؛ اگر حدود {$fmtSugg} تومان اضافه کنید می‌تونیم ادامه بدیم.";
    } else {
        $replyFriendly = 'پیشنهاد برایم مناسب است؛ می‌توانیم مراحل بعدی معامله را شروع کنیم.';
        $replyNegotiation = 'آیا در مورد زمان و محل تحویل و وضعیت کالا جزئیات بیشتری دارید؟';
        $replyFirm = 'از نظر مالی پیشنهاد متعادلی است؛ در صورت تأیید شما، معامله آماده ادامه است.';
    }

    return [
        'assessment'               => $assessment,
        'confidence'               => 0.65,
        'your_value'               => $yourValue,
        'their_value'              => $theirValue,
        'difference'               => $diff,
        'suggested_cash_difference'=> $suggDiff,
        'reason'                   => $reason,
        'suggested_replies'        => [
            $replyFriendly,
            $replyNegotiation,
            $replyFirm,
        ],
    ];
}

function negotiator_assessment_label(string $assessment): string {
    return match ($assessment) {
        'favorable'   => 'به نفع شما',
        'unfavorable' => 'نامناسب',
        default       => 'متعادل',
    };
}

function negotiator_assessment_class(string $assessment): string {
    return match ($assessment) {
        'favorable'   => 'success',
        'unfavorable' => 'danger',
        default       => 'warning',
    };
}
