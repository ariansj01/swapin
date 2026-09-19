<?php
/**
 * Mobile API v1 bootstrap — JSON only, Bearer token auth.
 * Isolated from the PWA session UI; does not change web behavior.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Swaapin-Api: v1');

function api_ensure_tokens_table(): void
{
    if (db_has_table('api_tokens')) {
        return;
    }
    DB::query("
        CREATE TABLE `api_tokens` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `device_name` VARCHAR(120) NULL,
            `last_used_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_api_tokens_hash` (`token_hash`),
            KEY `idx_api_tokens_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

api_ensure_tokens_table();

function api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_ok(array $data = [], int $status = 200): never
{
    api_json(['ok' => true] + $data, $status);
}

function api_error(string $error, int $status = 400, string $message = ''): never
{
    $out = ['ok' => false, 'error' => $error];
    if ($message !== '') {
        $out['message'] = $message;
    }
    api_json($out, $status);
}

function api_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function api_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST ?: [];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function api_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $uri = rawurldecode($uri);
    if (preg_match('#/api/v1(?:/index\.php)?(?:/|$)(.*)$#', $uri, $m)) {
        return trim($m[1], '/');
    }
    return '';
}

function api_normalize_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', normalize_digits($phone)) ?? '';
    if (str_starts_with($digits, '98') && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        $digits = '0' . $digits;
    }
    if (!preg_match('/^09[0-9]{9}$/', $digits)) {
        return null;
    }
    return '+' . preg_replace('/[^0-9]/', '', '98' . substr($digits, 1));
}

function api_phone_local(string $intl): string
{
    $digits = preg_replace('/\D+/', '', $intl) ?? '';
    if (str_starts_with($digits, '98') && strlen($digits) >= 12) {
        return '0' . substr($digits, 2);
    }
    return $digits;
}

function api_image_url(?string $filename): ?string
{
    if (!$filename) {
        return null;
    }
    return rtrim(UPLOAD_URL, '/') . '/' . ltrim($filename, '/');
}

function api_user_public(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'name' => (string)($user['name'] ?? ''),
        'phone' => api_phone_local((string)($user['phone'] ?? '')),
        'city' => $user['city'] ?? null,
        'avatar' => api_image_url($user['avatar'] ?? null),
        'rating' => isset($user['rating']) ? (float)$user['rating'] : 0,
        'rating_count' => (int)($user['rating_count'] ?? 0),
        'credit_balance' => (int)($user['credit_balance'] ?? 0),
        'verification_level' => (int)($user['verification_level'] ?? 0),
        'profile_complete' => user_profile_is_complete($user),
        'is_store' => function_exists('is_store_seller') && is_store_seller($user),
    ];
}

function api_listing_card(array $row): array
{
    $mode = trim((string)($row['listing_mode'] ?? 'swap')) ?: 'swap';
    return [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'city' => $row['city'] ?? null,
        'neighborhood' => $row['neighborhood'] ?? null,
        'thumb' => api_image_url($row['thumb'] ?? null),
        'estimated_value' => (int)($row['estimated_value'] ?? 0),
        'sell_price' => isset($row['sell_price']) ? (float)$row['sell_price'] : 0,
        'listing_mode' => $mode,
        'want_in_return' => $row['want_in_return'] ?? '',
        'category' => $row['cat_name'] ?? null,
        'category_slug' => $row['cat_slug'] ?? null,
        'seller_name' => $row['seller_name'] ?? null,
        'seller_rating' => isset($row['seller_rating']) ? (float)$row['seller_rating'] : 0,
        'created_at' => $row['created_at'] ?? null,
        'time_ago' => !empty($row['created_at']) ? timeago($row['created_at']) : '',
    ];
}

function api_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

function api_issue_token(int $userId, string $device = 'android'): string
{
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    DB::insert('api_tokens', [
        'user_id' => $userId,
        'token_hash' => $hash,
        'device_name' => mb_substr($device, 0, 120),
        'last_used_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 180),
    ]);
    return $raw;
}

function api_auth_user(): ?array
{
    $raw = api_bearer_token();
    if (!$raw) {
        return null;
    }
    $hash = hash('sha256', $raw);
    $row = DB::fetch(
        'SELECT t.user_id, t.expires_at FROM api_tokens t WHERE t.token_hash = ? LIMIT 1',
        [$hash]
    );
    if (!$row) {
        return null;
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        return null;
    }
    $user = DB::fetch('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$row['user_id']]);
    if (!$user) {
        return null;
    }
    DB::query('UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?', [$hash]);
    return $user;
}

function api_require_user(): array
{
    $user = api_auth_user();
    if (!$user) {
        api_error('login_required', 401, 'برای ادامه وارد شوید.');
    }
    return $user;
}
