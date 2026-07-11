<?php

// Load environment variables
require_once __DIR__ . '/load_env.php';
load_env(__DIR__ . '/../.env');

// ─── Core Configuration ────────────────────────────────────────────────────
define('APP_NAME',          'سواَپین');
define('APP_NAME_EN',       'Swapin');
define('CREDIT_UNIT',             'تومان');
define('DEFAULT_CURRENCY_CODE', 'IRT');
define('DEFAULT_CURRENCY_LABEL',  CREDIT_UNIT);
define('ADMIN_EMAIL',       getenv('SWAPIN_ADMIN_EMAIL') ?: 'info@swaapin.ir');
define('APP_URL',           getenv('SWAPIN_APP_URL') ?: 'https://swaapin.ir'); // http://localhost/swaapin - https://swaapin.ir
define('LOGO_URL',          APP_URL . '/src/img/swapin-dark-png.png');
define('UPLOAD_URL',        APP_URL . '/uploads/');
define('UPLOAD_DIR',        __DIR__ . '/../uploads');
define('STORAGE_DIR',       __DIR__ . '/../storage');
define('PRIVATE_UPLOAD_DIR', STORAGE_DIR . '/private');
define('MAX_IMAGES',        8);
// Environment: 'auto' | 'development' | 'production' (or SWAPIN_ENV env var)
define('APP_ENV',           'development'); // موقتاً برای دیباگ
define('OTP_EXPIRE',        120);
define('LISTINGS_PER_PAGE', 12);
define('WELCOME_BONUS',     10000000);
define('PLATFORM_FEE_RATE', 0.02); // ۲٪ کارمزد روی معاملات موفق
define('STORE_LISTING_BONUS', 50);  // سقف اضافه برای فروشگاه‌ها

// ─── Database ──────────────────────────────────────────────────────────────
define('DB_HOST', getenv('SWAPIN_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SWAPIN_DB_NAME') ?: 'swapin'); // kala_b_kala
define('DB_USER', getenv('SWAPIN_DB_USER') ?: 'ltze_swapin_kP%user'); // ltze_swapin_kP%user
define('DB_PASS', getenv('SWAPIN_DB_PASS') !== false ? (string)getenv('SWAPIN_DB_PASS') : 'kP%B!-)+*75p'); // kP%B!-)+*75p
define('DB_CHAR', 'utf8mb4');

require_once __DIR__ . '/security.php';
send_security_headers();

// ─── Session ───────────────────────────────────────────────────────────────
if (!defined('SKIP_SESSION') && session_status() === PHP_SESSION_NONE) {
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
    $table = preg_replace('/[^a-z0-9_]/', '', $table);
    try {
        return (bool) DB::fetch("SHOW TABLES LIKE '$table'");
    } catch (Throwable) {
        return false;
    }
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
        header('Location: ' . APP_URL . '/auth/login?redirect=' . $redirect);
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

function normalize_digits(string $val): string {
    return strtr($val, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
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

// Mail configuration from mail_secrets.php or defaults
$mailSecretsPath = __DIR__ . '/mail_secrets.php';
if (file_exists($mailSecretsPath)) {
    require_once $mailSecretsPath;
}

// Set mail defaults if not defined
if (!defined('MAIL_ENABLED')) {
    define('MAIL_ENABLED', true);
}
if (!defined('MAIL_SMTP_HOST')) {
    define('MAIL_SMTP_HOST', 'mail.swaapin.ir');
}
if (!defined('MAIL_SMTP_PORT')) {
    define('MAIL_SMTP_PORT', 587);
}
if (!defined('MAIL_SMTP_SECURE')) {
    define('MAIL_SMTP_SECURE', 'tls');
}
if (!defined('MAIL_SMTP_USER')) {
    define('MAIL_SMTP_USER', 'info@swaapin.ir');
}
if (!defined('MAIL_SMTP_PASS')) {
    define('MAIL_SMTP_PASS', 'nuBrX0zz');
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', 'info@swaapin.ir');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'سواَپین');
}
if (!defined('MAIL_REPLY_TO')) {
    define('MAIL_REPLY_TO', 'info@swaapin.ir');
}
if (!defined('MAIL_ADMIN_TO')) {
    define('MAIL_ADMIN_TO', 'info@swaapin.ir');
}
if (!defined('EMAILJS_ENABLED')) {
    define('EMAILJS_ENABLED', false);
}
if (!defined('EMAILJS_PUBLIC_KEY')) {
    define('EMAILJS_PUBLIC_KEY', '');
}
if (!defined('EMAILJS_SERVICE_ID')) {
    define('EMAILJS_SERVICE_ID', '');
}
if (!defined('EMAILJS_CONTEMPLATE_ID')) {
    define('EMAILJS_CONTEMPLATE_ID', '');
}
require_once __DIR__ . '/mail.php';

// SMS configuration from sms_secrets.php or defaults
$smsSecretsPath = __DIR__ . '/sms_secrets.php';
if (file_exists($smsSecretsPath)) {
    require_once $smsSecretsPath;
}

// Set defaults if not defined
if (!defined('SMS_ENABLED')) {
    define('SMS_ENABLED', true);
}
if (!defined('SMS_IRANPAYAMAK_API_KEY')) {
    define('SMS_IRANPAYAMAK_API_KEY', 'XmuNKUha9fZhz365DH2BjkKIoNyNXhaHX0dqxq2pQW24vXbKXQ');
}
if (!defined('SMS_IRANPAYAMAK_PATTERN_CODE')) {
    define('SMS_IRANPAYAMAK_PATTERN_CODE', 'mMR4lJx2Jq');
}
if (!defined('SMS_IRANPAYAMAK_LINE_NUMBER')) {
    define('SMS_IRANPAYAMAK_LINE_NUMBER', '50002178584000');
}
if (!defined('SMS_NUMBER_FORMAT')) {
    define('SMS_NUMBER_FORMAT', 'english');
}
if (!defined('SMS_OTP_ATTRIBUTE_MAP')) {
    define('SMS_OTP_ATTRIBUTE_MAP', [
    'code' => '{code}',
    'minutes' => '{minutes}',
]);
}
require_once __DIR__ . '/sms.php';

// AI configuration from ai_secrets.php or defaults
$aiSecretsPath = __DIR__ . '/ai_secrets.php';
if (file_exists($aiSecretsPath)) {
    require_once $aiSecretsPath;
}

// Set AI defaults if not defined (should be set in ai_secrets.php)
if (!defined('GROQ_API_KEY')) {
    define('GROQ_API_KEY', '');
}
if (!defined('GROQ_MODEL')) {
    define('GROQ_MODEL', 'llama-3.3-70b-versatile');
}
if (!defined('OPENROUTER_API_KEY')) {
    define('OPENROUTER_API_KEY', '');
}
if (!defined('OPENROUTER_MODEL')) {
    define('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct');
}
if (!defined('AI_CHAT_USER_LIMIT')) {
    define('AI_CHAT_USER_LIMIT', 30);
}
if (!defined('AI_CHAT_USER_WINDOW')) {
    define('AI_CHAT_USER_WINDOW', 3600);
}
if (!defined('AI_MATCH_REFRESH_LIMIT')) {
    define('AI_MATCH_REFRESH_LIMIT', 6);
}
if (!defined('AI_MATCH_REFRESH_WINDOW')) {
    define('AI_MATCH_REFRESH_WINDOW', 3600);
}
if (!defined('AI_VALUATE_USER_LIMIT')) {
    define('AI_VALUATE_USER_LIMIT', 3);
}
if (!defined('AI_VALUATE_USER_WINDOW')) {
    define('AI_VALUATE_USER_WINDOW', 900);
}
require_once __DIR__ . '/ai.php';
