<?php
// includes/ai.php — AI router (Groq + OpenRouter with failover)

function ai_limit(string $name, int $default): int {
    $const = 'AI_' . strtoupper($name) . '_LIMIT';
    return defined($const) ? (int) constant($const) : $default;
}

function ai_window(string $name, int $default): int {
    $const = 'AI_' . strtoupper($name) . '_WINDOW';
    return defined($const) ? (int) constant($const) : $default;
}

function ai_system_prompt(): string {
    static $prompt = null;
    if ($prompt === null) {
        $path = __DIR__ . '/ai_system_prompt.txt';
        $prompt = is_readable($path) ? (string) file_get_contents($path) : '';
    }
    return $prompt;
}

function groq_is_configured(): bool {
    return defined('GROQ_API_KEY') && GROQ_API_KEY !== '';
}

function openrouter_is_configured(): bool {
    return defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '';
}

function ai_is_configured(): bool {
    return groq_is_configured() || openrouter_is_configured();
}

function ai_provider_status_file(): string {
    $dir = defined('STORAGE_DIR') ? STORAGE_DIR : __DIR__ . '/../storage/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'ai_provider_status.json';
}

/** @return array<string,int> provider => limited_until unix timestamp */
function ai_provider_status_load(): array {
    $file = ai_provider_status_file();
    if (!is_readable($file)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function ai_provider_is_rate_limited(string $provider): bool {
    $status = ai_provider_status_load();
    return (int) ($status[$provider] ?? 0) > time();
}

function ai_mark_provider_rate_limited(string $provider, int $seconds = 600): void {
    $status = ai_provider_status_load();
    $status[$provider] = time() + max(60, $seconds);
    file_put_contents(ai_provider_status_file(), json_encode($status), LOCK_EX);
}

/** @return list<string> */
function ai_provider_order(): array {
    $providers = [];
    if (groq_is_configured()) {
        $providers[] = 'groq';
    }
    if (openrouter_is_configured()) {
        $providers[] = 'openrouter';
    }

    usort($providers, static function (string $a, string $b): int {
        $aLimited = ai_provider_is_rate_limited($a);
        $bLimited = ai_provider_is_rate_limited($b);
        if ($aLimited === $bLimited) {
            return 0;
        }
        return $aLimited ? 1 : -1;
    });

    return $providers;
}

/**
 * @return array{parsed:?array,provider:?string,rate_limited:bool}
 */
function ai_provider_chat_once(string $provider, array $messages, float $temperature): array {
    if ($provider === 'groq') {
        return groq_chat_completion_once($messages, $temperature);
    }
    if ($provider === 'openrouter') {
        return openrouter_chat_completion_once($messages, $temperature);
    }
    return ['parsed' => null, 'provider' => null, 'rate_limited' => false];
}

/**
 * @return array{parsed:?array,provider:?string}
 */
function ai_chat_completion(array $messages, float $temperature = 0.25): array {
    $providers = ai_provider_order();
    if (empty($providers)) {
        return ['parsed' => null, 'provider' => null];
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
        foreach ($providers as $provider) {
            if (ai_provider_is_rate_limited($provider)) {
                continue;
            }

            $result = ai_provider_chat_once($provider, $messages, $temperature);
            if (!empty($result['parsed'])) {
                return ['parsed' => $result['parsed'], 'provider' => $provider];
            }
            if (!empty($result['rate_limited'])) {
                ai_mark_provider_rate_limited($provider);
                continue;
            }
        }

        if ($attempt === 0) {
            usleep(400000);
        }
    }

    return ['parsed' => null, 'provider' => null];
}

/**
 * @return array{parsed:?array,provider:?string,rate_limited:bool}
 */
function groq_chat_completion_once(array $messages, float $temperature): array {
    if (!groq_is_configured()) {
        return ['parsed' => null, 'provider' => null, 'rate_limited' => false];
    }

    $payload = [
        'model'           => defined('GROQ_MODEL') ? GROQ_MODEL : 'llama-3.3-70b-versatile',
        'messages'        => $messages,
        'temperature'     => $temperature,
        'max_tokens'      => 1200,
        'response_format' => ['type' => 'json_object'],
    ];

    $response = ai_http_chat_request(
        'https://api.groq.com/openai/v1/chat/completions',
        ['Authorization: Bearer ' . GROQ_API_KEY],
        $payload
    );

    if ($response['rate_limited']) {
        return ['parsed' => null, 'provider' => 'groq', 'rate_limited' => true];
    }

    return [
        'parsed'       => ai_parse_completion_text($response['body']),
        'provider'     => 'groq',
        'rate_limited' => false,
    ];
}

/**
 * @return array{parsed:?array,provider:?string,rate_limited:bool}
 */
function openrouter_chat_completion_once(array $messages, float $temperature): array {
    if (!openrouter_is_configured()) {
        return ['parsed' => null, 'provider' => null, 'rate_limited' => false];
    }

    $payload = [
        'model'           => defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : 'meta-llama/llama-3.3-70b-instruct',
        'messages'        => $messages,
        'temperature'     => $temperature,
        'max_tokens'      => 1200,
        'response_format' => ['type' => 'json_object'],
    ];

    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : 'https://swaapin.ir'),
        'X-Title: ' . (defined('APP_NAME') ? APP_NAME : 'Swapin'),
    ];

    $response = ai_http_chat_request(
        'https://openrouter.ai/api/v1/chat/completions',
        $headers,
        $payload
    );

    if ($response['rate_limited']) {
        return ['parsed' => null, 'provider' => 'openrouter', 'rate_limited' => true];
    }

    return [
        'parsed'       => ai_parse_completion_text($response['body']),
        'provider'     => 'openrouter',
        'rate_limited' => false,
    ];
}

/** @return array{body:?array,code:int,rate_limited:bool} */
function ai_http_chat_request(string $url, array $headers, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['body' => null, 'code' => 0, 'rate_limited' => false];
    }

    $body = json_decode($raw, true);
    $rateLimited = $code === 429
        || ($code === 403 && is_array($body) && str_contains(strtolower(json_encode($body)), 'rate'));

    return [
        'body'         => is_array($body) ? $body : null,
        'code'         => $code,
        'rate_limited' => $rateLimited,
    ];
}

function ai_parse_completion_text(?array $body): ?array {
    if (!$body) {
        return null;
    }

    $text = trim($body['choices'][0]['message']['content'] ?? '');
    if ($text === '') {
        return null;
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed) && preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }

    return is_array($parsed) ? $parsed : null;
}

function ai_call(string $mode, array $payload): array {
    $userContent = json_encode(array_merge(['mode' => $mode], $payload), JSON_UNESCAPED_UNICODE);

    $messages = [
        ['role' => 'system', 'content' => ai_system_prompt()],
        ['role' => 'user', 'content' => $userContent],
    ];

    return ai_chat_completion($messages);
}

function ai_parse_json_response(?array $parsed): ?array {
    if (!$parsed) {
        return null;
    }
    if (($parsed['type'] ?? '') === 'error') {
        return null;
    }
    if (!empty($parsed['message']) && empty($parsed['type'])) {
        $parsed['type'] = 'chat';
    }
    if (in_array($parsed['type'] ?? '', ['response', 'assistant'], true) && !empty($parsed['message'])) {
        $parsed['type'] = 'chat';
    }
    return $parsed;
}

/** @return array{message:string,provider:?string} */
function ai_chat_respond(string $userMessage, array $history = [], ?array $user = null): array {
    $history = array_slice($history, -10);
    $messages = [
        ['role' => 'system', 'content' => ai_system_prompt()],
    ];

    foreach ($history as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'user' ? 'user' : 'assistant';
        $content = trim((string)($turn['content'] ?? ''));
        if ($content === '') continue;
        if ($role === 'assistant') {
            $messages[] = ['role' => 'assistant', 'content' => json_encode(['type' => 'chat', 'message' => $content], JSON_UNESCAPED_UNICODE)];
        } else {
            $messages[] = ['role' => 'user', 'content' => json_encode(['mode' => 'chat', 'message' => $content], JSON_UNESCAPED_UNICODE)];
        }
    }

    $messages[] = ['role' => 'user', 'content' => json_encode([
        'mode'    => 'chat',
        'message' => $userMessage,
        'user'    => $user ? ['name' => $user['name']] : null,
    ], JSON_UNESCAPED_UNICODE)];

    $result = ai_chat_completion($messages, 0.35);
    $parsed = ai_parse_json_response($result['parsed']);

    if ($parsed && !empty($parsed['message'])) {
        $type = $parsed['type'] ?? 'chat';
        if ($type === 'chat' || $type === 'response') {
            return [
                'message'  => trim((string) $parsed['message']),
                'provider' => $result['provider'],
            ];
        }
    }

    return ['message' => ai_chat_fallback($userMessage), 'provider' => null];
}

function ai_chat_fallback(string $userMessage): string {
    $t = mb_strtolower($userMessage);
    if (str_contains($t, 'قیمت') || str_contains($t, 'ارزش')) {
        return 'برای ارزش‌گذاری، کالا را در «ثبت کالا» ثبت کنید — دستیار هوشمند ارزش ' . CREDIT_UNIT . ' را به‌صورت محدوده پیشنهاد می‌دهد.';
    }
    if (str_contains($t, 'معاوضه') || str_contains($t, 'تعویض')) {
        return 'برای یافتن معاوضه مناسب، از فیلترهای صفحه اصلی و بخش پیشنهادهای داشبورد استفاده کنید.';
    }
    return 'سؤال شما دریافت شد. لطفاً کمی بعد دوباره تلاش کنید یا از بخش ثبت کالا و راهنما کمک بگیرید.';
}

function ai_fetch_similar_listings(int $categoryId, int $limit = 6): array {
    if ($categoryId <= 0) {
        return [];
    }
    return DB::fetchAll(
        'SELECT l.id, l.title, l.condition, l.estimated_value, l.want_in_return,
                c.name AS category_name, c.slug AS category_slug
         FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.category_id = ? AND l.status = "active" AND l.review_status = "approved" AND l.estimated_value > 0
         ORDER BY l.created_at DESC
         LIMIT ?',
        [$categoryId, $limit]
    );
}

function ai_demand_level(int $categoryId): string {
    if ($categoryId <= 0) return 'medium';
    $count = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM listings WHERE category_id = ? AND status = "active" AND review_status = "approved" AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)',
        [$categoryId]
    )['c'] ?? 0);
    if ($count >= 20) return 'high';
    if ($count >= 5) return 'medium';
    return 'low';
}

function ai_provider_label(?string $provider): string {
    return match ($provider) {
        'groq'       => 'Groq',
        'openrouter' => 'OpenRouter',
        default      => 'AI',
    };
}

/** @return array|null Normalized pricing result for API */
function ai_price_listing(array $listing, array $similarItems = []): ?array {
    $similar = array_map(static function ($row) {
        return [
            'title'           => $row['title'],
            'condition'       => $row['condition'],
            'estimated_value' => (float) $row['estimated_value'],
            'category'        => category_label($row['category_slug'] ?? '', $row['category_name'] ?? ''),
        ];
    }, $similarItems);

    $payload = [
        'listing' => [
            'title'       => $listing['title'],
            'description' => $listing['description'],
            'category'    => $listing['category_label'] ?? $listing['category'] ?? '',
            'condition'   => $listing['condition'],
        ],
        'context' => [
            'similar_items' => $similar,
            'demand_level'  => $listing['demand_level'] ?? 'medium',
            'credit_unit'   => CREDIT_UNIT,
        ],
    ];

    $result = ai_call('pricing', $payload);
    $parsed = ai_parse_json_response($result['parsed']);
    $provider = $result['provider'] ?? null;

    if (!$parsed || ($parsed['type'] ?? '') !== 'pricing') {
        return null;
    }

    $min        = (int) ($parsed['value_range']['min'] ?? 0);
    $max        = (int) ($parsed['value_range']['max'] ?? 0);
    $confidence = (float) ($parsed['confidence'] ?? 0);
    $reason     = trim((string) ($parsed['reason'] ?? ''));

    if ($min <= 0 && $max <= 0) {
        return null;
    }
    if ($min > $max) {
        [$min, $max] = [$max, $min];
    }
    if ($min <= 0) {
        $min = (int) max(500_000, round($max * 0.85));
    }
    if ($max <= 0) {
        $max = (int) min(120_000_000, round($min * 1.15));
    }

    $min = (int) max(500_000, round($min / 100_000) * 100_000);
    $max = (int) min(120_000_000, round($max / 100_000) * 100_000);
    if ($min > $max) {
        $min = (int) round($max * 0.88 / 100_000) * 100_000;
    }

    $value      = (int) round(($min + $max) / 2 / 100_000) * 100_000;
    $uncertain  = $confidence < 0.6;
    $confidencePct = (int) round(max(0, min(1, $confidence)) * 100);

    $reasons = array_filter([$reason]);
    if ($uncertain) {
        $reasons[] = 'اطمینان پایین — محدوده تقریبی است؛ در صورت نیاز مقدار را دستی تنظیم کنید.';
    }
    if (!empty($similar)) {
        $reasons[] = 'مقایسه با ' . count($similar) . ' آگهی مشابه در همین دسته';
    }

    return [
        'value'       => $value,
        'value_fmt'   => fmt_credit((float) $value),
        'range_low'   => $min,
        'range_high'  => $max,
        'range_fmt'   => fmt_credit((float) $min) . ' — ' . fmt_credit((float) $max),
        'confidence'  => $confidencePct,
        'uncertain'   => $uncertain,
        'reasons'     => array_values($reasons),
        'note'        => $uncertain
            ? 'ارزش‌گذاری با اطمینان پایین — پیشنهاد را راهنما در نظر بگیرید.'
            : 'ارزش‌گذاری هوشمند سواپین بر اساس مشخصات و آگهی‌های مشابه.',
        'ai_source'   => $provider ?? 'ai',
    ];
}

function ai_price_listing_fallback(array $listing): array {
    $title       = $listing['title'] ?? '';
    $description = $listing['description'] ?? '';
    $condition   = $listing['condition'] ?? 'good';

    $condMul = ['new' => 1.0, 'like_new' => 0.88, 'good' => 0.72, 'fair' => 0.58, 'poor' => 0.42];
    $mul       = $condMul[$condition] ?? 0.72;
    $seed      = abs(crc32($title . $description . $condition));
    $base      = 3_500_000 + ($seed % 42_000_000);
    $value     = (int) round($base * $mul / 100_000) * 100_000;
    $value     = max(500_000, min($value, 120_000_000));
    $rangeLow  = (int) round($value * 0.88 / 100_000) * 100_000;
    $rangeHigh = (int) round($value * 1.12 / 100_000) * 100_000;

    return [
        'value'       => $value,
        'value_fmt'   => fmt_credit((float) $value),
        'range_low'   => $rangeLow,
        'range_high'  => $rangeHigh,
        'range_fmt'   => fmt_credit((float) $rangeLow) . ' — ' . fmt_credit((float) $rangeHigh),
        'confidence'  => 55,
        'uncertain'   => true,
        'reasons'     => ['تخمین پشتیبان سیستم (دستیار در دسترس نبود)'],
        'note'        => 'اتصال دستیار هوشمند برقرار نشد — از تخمین داخلی استفاده شد.',
        'ai_source'   => 'fallback',
    ];
}

function ai_match_rule_reason(array $match): string {
    if (!empty($match['mutual'])) {
        return 'هر دو طرف دقیقاً آنچه طرف دیگر می‌خواهد را در «نیازمند» ذکر کرده‌اند.';
    }
    $score = (int) ($match['match_score'] ?? 0);
    if ($score >= 65) {
        return 'یک طرف نیاز طرف مقابل را پوشش می‌دهد — گزینه مناسب برای پیشنهاد معاوضه.';
    }
    return 'هم‌خوانی دسته یا کلیدواژه با «نیازمند» — پیشنهاد اولیه سیستم.';
}

function ai_match_listing_payload(array $row): array {
    return [
        'listing_id'      => (int) $row['id'],
        'title'           => $row['title'] ?? '',
        'description'     => mb_strimwidth($row['description'] ?? '', 0, 280, '…'),
        'category'        => category_label($row['cat_slug'] ?? '', $row['cat_name'] ?? ($row['category'] ?? '')),
        'condition'       => $row['condition'] ?? '',
        'wanted_item'     => $row['want_in_return'] ?? '',
        'estimated_value' => (float) ($row['estimated_value'] ?? 0),
    ];
}

/** @return array{user_listing:array,candidates:array}|null */
function ai_match_context(int $userId, ?int $listingId = null): ?array {
    $myListings = DB::fetchAll(
        'SELECT l.*, c.name AS cat_name, c.slug AS cat_slug FROM listings l
         JOIN categories c ON c.id = l.category_id
         WHERE l.user_id = ? AND l.status = "active" AND l.listing_mode IN ("swap","both")
         ORDER BY l.created_at DESC',
        [$userId]
    );
    if (empty($myListings)) {
        return null;
    }

    $userListing = null;
    if ($listingId) {
        foreach ($myListings as $l) {
            if ((int) $l['id'] === $listingId) {
                $userListing = $l;
                break;
            }
        }
    }
    $userListing ??= $myListings[0];

    $ruleMatches = find_swap_matches($userId, 25);
    $candidates  = [];
    foreach ($ruleMatches as $m) {
        if ((int) ($m['match_listing_id'] ?? 0) !== (int) $userListing['id']) {
            continue;
        }
        $candidates[] = $m;
    }
    if (empty($candidates)) {
        $candidates = array_slice($ruleMatches, 0, 15);
    }

    return [
        'user_listing' => $userListing,
        'candidates'   => array_slice($candidates, 0, 18),
    ];
}

function ai_match_format_row(array $candidate, int $score, string $tradeType, string $reason, string $source): array {
    return [
        'listing_id'       => (int) $candidate['id'],
        'title'            => $candidate['title'] ?? '',
        'seller_name'      => $candidate['seller_name'] ?? '',
        'thumb'            => $candidate['thumb'] ?? null,
        'cat_name'         => $candidate['cat_name'] ?? '',
        'match_score'      => max(0, min(100, $score)),
        'trade_type'       => in_array($tradeType, ['direct', 'credit'], true) ? $tradeType : 'direct',
        'reason'           => $reason,
        'mutual'           => !empty($candidate['mutual']),
        'match_listing_id' => (int) ($candidate['match_listing_id'] ?? 0),
        'match_title'      => $candidate['match_title'] ?? '',
        'want_in_return'   => $candidate['want_in_return'] ?? '',
        'estimated_value'  => (float) ($candidate['estimated_value'] ?? 0),
        'ai_source'        => $source,
    ];
}

function ai_match_from_rules(array $candidates, int $limit = 8): array {
    $out = [];
    foreach (array_slice($candidates, 0, $limit) as $c) {
        $tradeType = ($c['want_type'] ?? '') === 'credit' ? 'credit' : 'direct';
        $out[] = ai_match_format_row(
            $c,
            (int) ($c['match_score'] ?? 45),
            $tradeType,
            ai_match_rule_reason($c),
            'rules'
        );
    }
    return $out;
}

function ai_match_rules_only(int $userId, ?int $listingId = null, int $limit = 8): array {
    $ctx = ai_match_context($userId, $listingId);
    if (!$ctx) {
        return ['matches' => [], 'user_listing' => null, 'source' => 'empty'];
    }

    return [
        'matches'      => ai_match_from_rules($ctx['candidates'], $limit),
        'user_listing' => ai_match_listing_payload($ctx['user_listing']),
        'source'       => 'rules',
    ];
}

/**
 * AI Matching Engine — ranks rule-filtered candidates via Groq/OpenRouter.
 *
 * @return array{matches:array,user_listing:array|null,source:string}
 */
function ai_match_listings(int $userId, ?int $listingId = null, int $limit = 8): array {
    $ctx = ai_match_context($userId, $listingId);
    if (!$ctx) {
        return ['matches' => [], 'user_listing' => null, 'source' => 'empty'];
    }

    $userListing = $ctx['user_listing'];
    $candidates  = $ctx['candidates'];

    if (empty($candidates)) {
        return [
            'matches'      => [],
            'user_listing' => ai_match_listing_payload($userListing),
            'source'       => 'empty',
        ];
    }

    if (!ai_is_configured()) {
        return [
            'matches'      => ai_match_from_rules($candidates, $limit),
            'user_listing' => ai_match_listing_payload($userListing),
            'source'       => 'rules',
        ];
    }

    $candidatePayload = array_map(static function ($c) {
        return array_merge(ai_match_listing_payload($c), [
            'rule_score' => (int) ($c['match_score'] ?? 0),
            'mutual'     => !empty($c['mutual']),
        ]);
    }, $candidates);

    $payload = [
        'user_listing'       => ai_match_listing_payload($userListing),
        'candidate_listings' => $candidatePayload,
    ];

    $result = ai_call('matching', $payload);
    $parsed = ai_parse_json_response($result['parsed']);
    $provider = $result['provider'] ?? 'ai';

    if (!$parsed || ($parsed['type'] ?? '') !== 'matching' || empty($parsed['matches'])) {
        return [
            'matches'      => ai_match_from_rules($candidates, $limit),
            'user_listing' => ai_match_listing_payload($userListing),
            'source'       => 'rules',
        ];
    }

    $byId = [];
    foreach ($candidates as $c) {
        $byId[(int) $c['id']] = $c;
    }

    $out    = [];
    $seen   = [];
    foreach ($parsed['matches'] as $item) {
        $lid = (int) ($item['listing_id'] ?? 0);
        if (!$lid || !isset($byId[$lid]) || isset($seen[$lid])) {
            continue;
        }
        $seen[$lid] = true;
        $c = $byId[$lid];
        $out[] = ai_match_format_row(
            $c,
            (int) ($item['score'] ?? $c['match_score'] ?? 50),
            (string) ($item['trade_type'] ?? 'direct'),
            trim((string) ($item['reason'] ?? '')) ?: ai_match_rule_reason($c),
            $provider
        );
        if (count($out) >= $limit) {
            break;
        }
    }

    if (count($out) < min(3, $limit)) {
        foreach ($candidates as $c) {
            $lid = (int) $c['id'];
            if (isset($seen[$lid])) {
                continue;
            }
            $out[] = ai_match_format_row(
                $c,
                (int) ($c['match_score'] ?? 45),
                'direct',
                ai_match_rule_reason($c),
                'rules'
            );
            if (count($out) >= $limit) {
                break;
            }
        }
    }

    usort($out, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

    return [
        'matches'      => $out,
        'user_listing' => ai_match_listing_payload($userListing),
        'source'       => $provider,
    ];
}

function ai_match_listings_cached(int $userId, ?int $listingId = null, bool $refresh = false, int $limit = 8): array {
    $cacheKey = $userId . ':' . ($listingId ?? 0);
    if (!isset($_SESSION['_ai_match_cache'])) {
        $_SESSION['_ai_match_cache'] = [];
    }

    $cached = $_SESSION['_ai_match_cache'][$cacheKey] ?? null;
    if (!$refresh && $cached && (time() - ($cached['at'] ?? 0)) < 900) {
        return $cached['data'];
    }

    if (!$refresh) {
        return ai_match_rules_only($userId, $listingId, $limit);
    }

    $data = ai_match_listings($userId, $listingId, $limit);
    $_SESSION['_ai_match_cache'][$cacheKey] = ['at' => time(), 'data' => $data];
    return $data;
}

function ai_match_clear_cache(int $userId): void {
    if (!isset($_SESSION['_ai_match_cache'])) {
        return;
    }
    foreach (array_keys($_SESSION['_ai_match_cache']) as $key) {
        if (str_starts_with($key, $userId . ':')) {
            unset($_SESSION['_ai_match_cache'][$key]);
        }
    }
}

function ai_source_is_ai(string $source): bool {
    return in_array($source, ['groq', 'openrouter', 'ai', 'assistant'], true);
}

/** Public-facing label — never expose vendor names (Groq/OpenRouter). */
function ai_public_mode(?string $internal): string {
    return ai_source_is_ai($internal ?? '') ? 'assistant' : 'system';
}

function ai_sanitize_pricing_for_client(array $result): array {
    unset($result['ai_source']);
    if (isset($result['note']) && str_contains($result['note'], 'Groq')) {
        $result['note'] = 'ارزش‌گذاری هوشمند سواپین بر اساس مشخصات و آگهی‌های مشابه.';
    }
    return $result;
}

function ai_sanitize_match_row_for_client(array $row): array {
    unset($row['ai_source']);
    return $row;
}

function ai_sanitize_match_payload_for_client(array $data): array {
    $data['source'] = ai_public_mode($data['source'] ?? 'system');
    if (!empty($data['matches'])) {
        $data['matches'] = array_map('ai_sanitize_match_row_for_client', $data['matches']);
    }
    return $data;
}
