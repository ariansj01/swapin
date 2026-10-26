<?php
/**
 * Store API keys — per-store credentials used by external integrations
 * to publish listings on behalf of a store.
 *
 * Keys are stored hashed (sha256). The plain key is only shown once,
 * right after it is generated from the admin panel.
 */

function store_api_ensure_tables(): bool {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        if (!db_has_table('store_api_keys')) {
            DB::query("
                CREATE TABLE IF NOT EXISTS `store_api_keys` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NOT NULL,
                    `key_prefix` VARCHAR(16) NOT NULL,
                    `key_hash` CHAR(64) NOT NULL,
                    `label` VARCHAR(120) DEFAULT NULL,
                    `created_by` INT UNSIGNED DEFAULT NULL,
                    `last_used_at` DATETIME DEFAULT NULL,
                    `revoked_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_store_api_hash` (`key_hash`),
                    KEY `idx_store_api_user` (`user_id`,`revoked_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $ok = true;
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('store-api-tables-failed', ['error' => $e->getMessage()]);
        }
        $ok = false;
    }
    return $ok;
}

/** Currently active key row for a store (hashes only — no plain key). */
function store_api_active_key(int $userId): ?array {
    if ($userId <= 0 || !store_api_ensure_tables()) {
        return null;
    }
    return DB::fetch(
        'SELECT id, user_id, key_prefix, label, created_by, last_used_at, created_at
         FROM store_api_keys
         WHERE user_id = ? AND revoked_at IS NULL
         ORDER BY id DESC LIMIT 1',
        [$userId]
    ) ?: null;
}

/**
 * Revokes every active key of the store and issues a fresh one.
 *
 * @return array{key:string,prefix:string}|null  The plain key is returned only here.
 */
function store_api_generate_key(int $userId, ?string $label = null, ?int $adminId = null): ?array {
    if ($userId <= 0 || !store_api_ensure_tables()) {
        return null;
    }

    DB::query('UPDATE store_api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);

    $raw = 'swk_' . bin2hex(random_bytes(20));
    $prefix = substr($raw, 0, 12);
    DB::insert('store_api_keys', [
        'user_id'    => $userId,
        'key_prefix' => $prefix,
        'key_hash'   => hash('sha256', $raw),
        'label'      => ($label !== null && trim($label) !== '') ? mb_substr(trim($label), 0, 120) : null,
        'created_by' => $adminId ?: null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return ['key' => $raw, 'prefix' => $prefix];
}

function store_api_revoke(int $userId): void {
    if ($userId <= 0 || !store_api_ensure_tables()) {
        return;
    }
    DB::query('UPDATE store_api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
}

/** Reads the API key from X-Api-Key (preferred) or Authorization: Bearer. */
function store_api_request_key(): ?string {
    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', (string)$auth, $m)) {
            $key = $m[1];
        }
    }
    return $key !== '' ? $key : null;
}

/** Returns the store owner row for a valid key, or null. */
function store_api_auth(): ?array {
    $key = store_api_request_key();
    if (!$key || !store_api_ensure_tables()) {
        return null;
    }
    $row = DB::fetch(
        'SELECT id, user_id FROM store_api_keys WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1',
        [hash('sha256', $key)]
    );
    if (!$row) {
        return null;
    }
    $user = DB::fetch('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$row['user_id']]);
    if (!$user) {
        return null;
    }
    if (!is_store_seller($user) || empty($user['store_name'])) {
        return null;
    }
    DB::query('UPDATE store_api_keys SET last_used_at = NOW() WHERE id = ?', [(int)$row['id']]);
    return $user;
}
