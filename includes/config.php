<?php
// ─── Core Configuration ────────────────────────────────────────────────────
define('APP_NAME',          'سواپین');
define('APP_NAME_EN',       'Swapin');
define('CREDIT_UNIT',             'تومان');
define('DEFAULT_CURRENCY_CODE',   'IRR');
define('DEFAULT_CURRENCY_LABEL',  CREDIT_UNIT);
define('ADMIN_EMAIL',       getenv('SWAPIN_ADMIN_EMAIL') ?: 'admin@kalabkala.com');
define('APP_URL',           getenv('SWAPIN_APP_URL') ?: 'http://localhost/swaapin');
define('LOGO_URL',          APP_URL . '/src/img/swapin-dark-png.png');
define('UPLOAD_URL',        APP_URL . '/uploads/');
define('UPLOAD_DIR',        __DIR__ . '/../uploads/');
define('STORAGE_DIR',       __DIR__ . '/../storage/');
define('PRIVATE_UPLOAD_DIR', STORAGE_DIR . 'private/');
define('MAX_IMAGES',        8);
// Environment: 'auto' | 'development' | 'production' (or SWAPIN_ENV env var)
define('APP_ENV',           getenv('SWAPIN_ENV') ?: 'auto');
define('OTP_EXPIRE',        600);
define('LISTINGS_PER_PAGE', 12);
define('WELCOME_BONUS',     10000000);
define('PLATFORM_FEE_RATE', 0.02); // ۲٪ کارمزد روی معاملات موفق
define('STORE_LISTING_BONUS', 50);  // سقف اضافه برای فروشگاه‌ها

// ─── Database ──────────────────────────────────────────────────────────────
define('DB_HOST', getenv('SWAPIN_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SWAPIN_DB_NAME') ?: 'kala_b_kala');
define('DB_USER', getenv('SWAPIN_DB_USER') ?: 'root');
define('DB_PASS', getenv('SWAPIN_DB_PASS') !== false ? (string)getenv('SWAPIN_DB_PASS') : '');
define('DB_CHAR', 'utf8mb4');

require_once __DIR__ . '/security.php';
send_security_headers();

// ─── Session ───────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if (app_is_production() && $https) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// ─── Error handling ────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_DIR . 'logs/error.log');

// #region debug-point homepage-500-bootstrap
if (!defined('SWAPIN_REQUEST_ID')) {
    define('SWAPIN_REQUEST_ID', bin2hex(random_bytes(6)));
}

function swapin_debug_log(string $message, array $context = []): void {
    $parts = ['[swapin-debug]', '[req:' . SWAPIN_REQUEST_ID . ']', $message];
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $parts[] = $json;
        }
    }
    error_log(implode(' ', $parts));
}

set_exception_handler(function (Throwable $e): void {
    swapin_debug_log('uncaught-exception', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    ]);
    http_response_code(500);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }
    swapin_debug_log('fatal-shutdown', [
        'type' => $error['type'] ?? null,
        'message' => $error['message'] ?? '',
        'file' => $error['file'] ?? '',
        'line' => $error['line'] ?? 0,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    ]);
});
// #endregion

if (app_is_production()) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

define('WALLET_DEMO_DEPOSIT', !app_is_production());

// ══════════════════════════════════════════════════════════════════════════════
// DB — lightweight PDO wrapper
// ══════════════════════════════════════════════════════════════════════════════
class DB {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHAR;
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($phs)", array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function lastId(): int {
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): void {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        self::query("UPDATE `$table` SET $sets WHERE $where", [...array_values($data), ...$whereParams]);
    }
}

function db_table_columns(string $table): array {
    static $cache = [];
    $table = preg_replace('/[^a-z0-9_]/', '', $table);
    if (!isset($cache[$table])) {
        try {
            $cache[$table] = array_column(DB::fetchAll("SHOW COLUMNS FROM `$table`"), 'Field');
        } catch (Throwable) {
            $cache[$table] = [];
        }
    }
    return $cache[$table];
}

function db_has_column(string $table, string $column): bool {
    return in_array($column, db_table_columns($table), true);
}

function db_has_table(string $table): bool {
    return (bool) DB::fetch('SHOW TABLES LIKE ?', [$table]);
}

function db_filter_row(string $table, array $data): array {
    return array_intersect_key($data, array_flip(db_table_columns($table)));
}

// ══════════════════════════════════════════════════════════════════════════════
// Auth helpers
// ══════════════════════════════════════════════════════════════════════════════
function auth_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = DB::fetch('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['user_id']]);
    return $cached;
}

function require_auth(): array {
    $user = auth_user();
    if (!$user) {
        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header('Location: ' . APP_URL . '/auth/login.php?redirect=' . $redirect);
        exit;
    }
    return $user;
}

function login_user(int $uid): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $uid;
    DB::query('UPDATE users SET last_seen = NOW() WHERE id = ?', [$uid]);
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
}

function app_url(string $path = ''): string {
    $path = ltrim($path, '/');
    return $path === '' ? APP_URL . '/' : APP_URL . '/' . $path;
}

// ══════════════════════════════════════════════════════════════════════════════
// Wallet / Credit helpers
//
// ref_type + ref_id semantics:
//   none                — welcome bonus, demo deposit (ref_id NULL)
//   trade               — ref_id + trade_id = trades.id; listing_id = user's listing in trade
//   trade_offer         — ref_id = trade_offers.id
//   listing             — ref_id + listing_id = listings.id (generic listing fee)
//   listing_bump        — ref_id = listing_bumps.id; listing_id = listings.id
//   subscription_order  — ref_id = subscription_orders.id
//   inspection_request  — ref_id = inspection_requests.id; listing_id = listings.id
//   external            — ref_id = payment gateway / bank reference number
// ══════════════════════════════════════════════════════════════════════════════
function wallet_listing_for_trade_user(array $trade, int $userId): ?int {
    if ((int)$userId === (int)$trade['user_a_id']) {
        return (int)$trade['listing_a_id'];
    }
    if ((int)$userId === (int)$trade['user_b_id'] && !empty($trade['listing_b_id'])) {
        return (int)$trade['listing_b_id'];
    }
    return null;
}

function credit_transact(int $userId, string $type, float $amount, string $note = '', array $ctx = []): void {
    $refType      = $ctx['ref_type'] ?? 'none';
    $refId        = isset($ctx['ref_id']) ? (int)$ctx['ref_id'] : null;
    $tradeId      = isset($ctx['trade_id']) ? (int)$ctx['trade_id'] : null;
    $listingId    = isset($ctx['listing_id']) ? (int)$ctx['listing_id'] : null;
    $currencyCode = $ctx['currency_code'] ?? DEFAULT_CURRENCY_CODE;
    $currency     = $ctx['currency'] ?? DEFAULT_CURRENCY_LABEL;

    if ($tradeId && $refType === 'none') {
        $refType = 'trade';
    }
    if ($tradeId && !$refId) {
        $refId = $tradeId;
    }

    DB::pdo()->beginTransaction();
    try {
        DB::query(
            'UPDATE users SET credit_balance = credit_balance + ? WHERE id = ?',
            [$amount, $userId]
        );
        $user = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userId]);
        $row  = [
            'user_id'       => $userId,
            'type'          => $type,
            'ref_type'      => $refType,
            'amount'        => $amount,
            'balance_after' => $user['credit_balance'],
            'currency_code' => $currencyCode,
            'currency'      => $currency,
            'note'          => $note,
            'ref_id'        => $refId ?: null,
            'trade_id'      => $tradeId ?: null,
            'listing_id'    => $listingId ?: null,
        ];
        // Omit columns not yet migrated (older DB before migration_wallet.sql)
        static $walletCols = null;
        if ($walletCols === null) {
            $walletCols = array_column(DB::fetchAll('SHOW COLUMNS FROM wallet_transactions'), 'Field');
        }
        $row = array_intersect_key($row, array_flip($walletCols));
        DB::insert('wallet_transactions', $row);
        DB::pdo()->commit();
    } catch (Throwable $e) {
        DB::pdo()->rollBack();
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Utility helpers
// ══════════════════════════════════════════════════════════════════════════════
function clean(string $val): string {
    return trim(htmlspecialchars_decode(strip_tags($val)));
}

function h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function paginate(int $total, int $perPage, int $page): array {
    $pages  = (int)ceil($total / $perPage);
    $page   = max(1, min($page, max(1, $pages)));
    $offset = ($page - 1) * $perPage;
    return [
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $page,
        'offset'   => $offset,
        'has_prev' => $page > 1,
        'has_next' => $page < $pages,
    ];
}

function validate_uploaded_image(array $file): ?array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return null;
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return null;
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return null;
    }

    return ['ext' => $allowed[$mime], 'mime' => $mime];
}

function store_uploaded_image(array $file, string $prefix, string $destDir): ?string {
    $valid = validate_uploaded_image($file);
    if (!$valid) {
        return null;
    }

    $filename = $prefix . '_' . uniqid('', true) . '_' . time() . '.' . $valid['ext'];
    $destDir  = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $dest = $destDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return $filename;
}

function upload_image(array $file, string $prefix = 'img'): ?string {
    return store_uploaded_image($file, $prefix, UPLOAD_DIR);
}

function upload_private_image(array $file, string $prefix = 'kyc'): ?string {
    return store_uploaded_image($file, $prefix, PRIVATE_UPLOAD_DIR);
}

function timeago(string $datetime): string {
    $diff = max(0, time() - strtotime($datetime));
    if ($diff < 60)       return 'چند لحظه پیش';
    if ($diff < 3600)     return (int)($diff / 60) . ' دقیقه پیش';
    if ($diff < 86400)    return (int)($diff / 3600) . ' ساعت پیش';
    if ($diff < 604800)   return (int)($diff / 86400) . ' روز پیش';
    if ($diff < 2592000)  return (int)($diff / 604800) . ' هفته پیش';
    if ($diff < 31536000) return (int)($diff / 2592000) . ' ماه پیش';
    return (int)($diff / 31536000) . ' سال پیش';
}

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/listing_validator.php';
require_once __DIR__ . '/v2.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/support.php';

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
}

if (is_readable(__DIR__ . '/mail_secrets.php')) {
    require_once __DIR__ . '/mail_secrets.php';
} else {
    define('MAIL_ENABLED', false);
    define('EMAILJS_ENABLED', false);
}
require_once __DIR__ . '/mail.php';

if (is_readable(__DIR__ . '/ai_secrets.php')) {
    require_once __DIR__ . '/ai_secrets.php';
} else {
    define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
    define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');
    define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
    define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'meta-llama/llama-3.3-70b-instruct');
}
require_once __DIR__ . '/ai.php';
