<?php
// ─── Core Configuration ────────────────────────────────────────────────────
define('APP_NAME',          'سواپین');
define('APP_NAME_EN',       'Swapin');
define('CREDIT_UNIT',       'SWP');
define('APP_URL',           'http://localhost/swaapin');
define('LOGO_URL',          APP_URL . '/src/img/swapin-dark-png.png');
define('UPLOAD_URL',        APP_URL . '/uploads/');
define('UPLOAD_DIR',        __DIR__ . '/../uploads/');
define('MAX_IMAGES',        8);
define('OTP_EXPIRE',        600);
define('LISTINGS_PER_PAGE', 12);
define('WELCOME_BONUS',     10000000);
define('PLATFORM_FEE_RATE', 0.02); // ۲٪ کارمزد روی معاملات موفق
define('STORE_LISTING_BONUS', 50);  // سقف اضافه برای فروشگاه‌ها

// ─── Database ──────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'kala_b_kala');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHAR', 'utf8mb4');

// ─── Session ───────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// ─── Error handling (dev mode) ─────────────────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

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
    session_destroy();
    session_start();
    session_regenerate_id(true);
}

function app_url(string $path = ''): string {
    $path = ltrim($path, '/');
    return $path === '' ? APP_URL . '/' : APP_URL . '/' . $path;
}

// ══════════════════════════════════════════════════════════════════════════════
// Wallet / Credit helpers
// ══════════════════════════════════════════════════════════════════════════════
function credit_transact(int $userId, string $type, float $amount, string $note = '', int $refId = 0): void {
    DB::pdo()->beginTransaction();
    try {
        DB::query(
            'UPDATE users SET credit_balance = credit_balance + ? WHERE id = ?',
            [$amount, $userId]
        );
        $user = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userId]);
        DB::insert('wallet_transactions', [
            'user_id'       => $userId,
            'type'          => $type,
            'amount'        => $amount,
            'balance_after' => $user['credit_balance'],
            'note'          => $note,
            'ref_id'        => $refId ?: null,
        ]);
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

function upload_image(array $file, string $prefix = 'img'): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) return null;

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) return null;

    $ext      = $allowed[$mime];
    $filename = $prefix . '_' . uniqid() . '_' . time() . '.' . $ext;
    $dest     = UPLOAD_DIR . $filename;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;

    return $filename;
}

function timeago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'همین الان';
    if ($diff < 3600)   return (int)($diff / 60) . ' دقیقه پیش';
    if ($diff < 86400)  return (int)($diff / 3600) . ' ساعت پیش';
    if ($diff < 604800) return (int)($diff / 86400) . ' روز پیش';
    return persian_date($datetime);
}

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/v2.php';

if (is_readable(__DIR__ . '/ai_secrets.php')) {
    require_once __DIR__ . '/ai_secrets.php';
} else {
    define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
    define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');
}
require_once __DIR__ . '/ai.php';