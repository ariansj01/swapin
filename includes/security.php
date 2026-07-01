<?php
/**
 * Security helpers — CSRF, rate limiting, CLI guard, private media.
 */

function app_is_production(): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $env = defined('APP_ENV') ? APP_ENV : 'auto';
    if ($env === 'production') {
        return $cached = true;
    }
    if ($env === 'development') {
        return $cached = false;
    }

    if (PHP_SAPI === 'cli') {
        return $cached = false;
    }

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $localHosts = ['localhost', '127.0.0.1'];
    if (in_array($host, $localHosts, true)) {
        return $cached = false;
    }
    foreach ($localHosts as $local) {
        if (str_starts_with($host, $local . ':')) {
            return $cached = false;
        }
    }

    return $cached = true;
}

function require_cli(): void {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden: CLI only.\n";
        exit(1);
    }
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_meta_tag(): string {
    return '<meta name="csrf-token" content="' . h(csrf_token()) . '">';
}

function csrf_token_from_request(): string {
    return (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}

function csrf_verify(): bool {
    $token = csrf_token_from_request();
    return $token !== '' && hash_equals(csrf_token(), $token);
}

function csrf_verify_or_fail(bool $json = false): void {
    if (csrf_verify()) {
        return;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'csrf_invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(403);
    exit('Invalid request.');
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function rate_limit_storage_dir(): string {
    $dir = defined('STORAGE_DIR') ? STORAGE_DIR . '/rate_limit' : __DIR__ . '/../storage/rate_limit';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** @return true if allowed, false if rate limited */
function rate_limit_ip(string $action, int $maxAttempts, int $windowSeconds): bool {
    $ip   = preg_replace('/[^a-fA-F0-9\.:]/', '', client_ip()) ?: '0';
    $file = rate_limit_storage_dir() . '/' . hash('sha256', $action . '|' . $ip) . '.json';
    $now  = time();
    $data = ['count' => 0, 'start' => $now];

    if (is_readable($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if ($now - (int)($data['start'] ?? 0) > $windowSeconds) {
        $data = ['count' => 0, 'start' => $now];
    }

    $data['count'] = (int)($data['count'] ?? 0) + 1;
    file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] <= $maxAttempts;
}

function rate_limit_ip_or_fail(string $action, int $maxAttempts, int $windowSeconds, bool $json = false): void {
    if (rate_limit_ip($action, $maxAttempts, $windowSeconds)) {
        return;
    }

    if ($json) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(429);
    exit('Too many requests.');
}

function safe_redirect_path(string $path): string {
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return '';
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
        return '';
    }
    if (str_contains($path, '://') || str_contains($path, '\\')) {
        return '';
    }
    return $path;
}

function private_media_url(int $userId): string {
    return APP_URL . '/media/private.php?u=' . $userId;
}

function resolve_private_upload_path(string $filename): ?string {
    $filename = basename($filename);
    if ($filename === '') {
        return null;
    }

    $dirs = [];
    if (defined('PRIVATE_UPLOAD_DIR')) {
        $dirs[] = PRIVATE_UPLOAD_DIR;
    }
    if (defined('UPLOAD_DIR')) {
        $dirs[] = UPLOAD_DIR;
    }

    foreach ($dirs as $dir) {
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (is_readable($path)) {
            return $path;
        }
    }

    return null;
}

function serve_private_file(string $path, string $downloadName = ''): void {
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    if (!str_starts_with($mime, 'image/')) {
        http_response_code(403);
        exit('Forbidden.');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if ($downloadName !== '') {
        header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"');
    }
    readfile($path);
    exit;
}
