<?php

function google_auth_credentials_path(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $envPath = trim((string) getenv('SWAPIN_GOOGLE_CLIENT_JSON'));
    if ($envPath !== '' && is_readable($envPath)) {
        return $cached = $envPath;
    }

    $candidates = glob(dirname(__DIR__) . '/client_secret_*.apps.googleusercontent.com.json') ?: [];
    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            return $cached = $candidate;
        }
    }

    return $cached = '';
}

function google_auth_config(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $clientId = trim((string) getenv('SWAPIN_GOOGLE_CLIENT_ID'));
    if ($clientId !== '') {
        return $cached = ['client_id' => $clientId];
    }

    $path = google_auth_credentials_path();
    if ($path === '') {
        return $cached = [];
    }

    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return $cached = [];
    }

    $web = is_array($decoded['web'] ?? null) ? $decoded['web'] : [];
    $clientId = trim((string) ($web['client_id'] ?? ''));
    if ($clientId === '') {
        return $cached = [];
    }

    return $cached = [
        'client_id' => $clientId,
        'project_id' => trim((string) ($web['project_id'] ?? '')),
    ];
}

function google_client_id(): string {
    return (string) (google_auth_config()['client_id'] ?? '');
}

function google_login_enabled(): bool {
    return google_client_id() !== '';
}

function google_http_get_json(string $url): ?array {
    $body = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
    }

    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : null;
}

function google_verify_id_token(string $idToken): ?array {
    $idToken = trim($idToken);
    $clientId = google_client_id();
    if ($idToken === '' || $clientId === '') {
        return null;
    }

    $payload = google_http_get_json(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken)
    );
    if (!is_array($payload) || empty($payload['sub']) || empty($payload['email'])) {
        return null;
    }

    $aud = trim((string) ($payload['aud'] ?? ''));
    $iss = trim((string) ($payload['iss'] ?? ''));
    $exp = (int) ($payload['exp'] ?? 0);
    $emailVerified = in_array((string) ($payload['email_verified'] ?? ''), ['true', '1'], true);

    if ($aud !== $clientId) {
        return null;
    }
    if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        return null;
    }
    if ($exp > 0 && $exp < time()) {
        return null;
    }
    if (!$emailVerified) {
        return null;
    }

    $email = strtolower(trim((string) $payload['email']));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        $name = strstr($email, '@', true) ?: 'کاربر سواپین';
    }

    return [
        'sub' => trim((string) $payload['sub']),
        'email' => $email,
        'name' => $name,
        'picture' => trim((string) ($payload['picture'] ?? '')),
        'email_verified' => true,
    ];
}

function google_find_or_create_user(array $claims): array {
    $googleId = trim((string) ($claims['sub'] ?? ''));
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    $name = trim((string) ($claims['name'] ?? ''));
    $picture = trim((string) ($claims['picture'] ?? ''));

    if ($googleId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('اطلاعات حساب گوگل معتبر نیست.');
    }

    DB::pdo()->beginTransaction();

    try {
        $user = DB::fetch('SELECT * FROM users WHERE google_id = ? LIMIT 1', [$googleId]);
        if (!$user) {
            $user = DB::fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        }

        if ($user) {
            if ((int) ($user['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('حساب کاربری شما غیرفعال است.');
            }

            $updates = [];

            if (empty($user['google_id'])) {
                $updates['google_id'] = $googleId;
            } elseif ((string) $user['google_id'] !== $googleId) {
                throw new RuntimeException('این ایمیل قبلاً به حساب دیگری متصل شده است.');
            }

            if ($picture !== '' && empty($user['avatar'])) {
                $updates['avatar'] = $picture;
            }

            if (db_has_column('users', 'email_verified_at') && empty($user['email_verified_at'])) {
                $updates['email_verified_at'] = date('Y-m-d H:i:s');
            }

            if ($name !== '' && trim((string) ($user['name'] ?? '')) === '') {
                $updates['name'] = $name;
            }

            if (!empty($updates)) {
                DB::update('users', db_filter_row('users', $updates), 'id = ?', [(int) $user['id']]);
            }

            DB::pdo()->commit();

            return [
                'user_id' => (int) $user['id'],
                'is_new' => false,
            ];
        }

        $userId = DB::insert('users', db_filter_row('users', [
            'name' => $name,
            'email' => $email,
            'phone' => null,
            'city' => null,
            'avatar' => $picture !== '' ? $picture : null,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
            'credit_balance' => 0,
            'verification_level' => 1,
            'google_id' => $googleId,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]));

        DB::pdo()->commit();

        return [
            'user_id' => $userId,
            'is_new' => true,
        ];
    } catch (Throwable $e) {
        if (DB::pdo()->inTransaction()) {
            DB::pdo()->rollBack();
        }
        throw $e;
    }
}
