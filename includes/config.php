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
define('PLATFORM_FEE_RATE', 0.01); // ۱٪ کارمزد روی معاملات موفق
define('STORE_LISTING_BONUS', 50);  // سقف اضافه برای فروشگاه‌ها
define('WALLET_TOPUP_URL', APP_URL . '/wallet?action=topup'); // آدرس صفحه شارژ کیف پول

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

// ─── Auto-run migrations ───────────────────────────────────────────────────
try {
    // Add trade_rating column to reviews table if it doesn't exist
    $reviewColumns = db_table_columns('reviews');
    if (!in_array('trade_rating', $reviewColumns)) {
        DB::query('ALTER TABLE `reviews` ADD COLUMN `trade_rating` TINYINT UNSIGNED NULL COMMENT "1-5" AFTER `rating`');
    }
    
    // Add onboarding columns to users table if they don't exist
    $usersColumns = db_table_columns('users');
    if (!in_array('onboarding_completed', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `onboarding_completed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `updated_at`');
    }
    if (!in_array('primary_goal', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `primary_goal` ENUM("swap","buy","sell","any") COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `onboarding_completed`');
    }
    if (!in_array('interested_categories', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `interested_categories` JSON DEFAULT NULL COMMENT "Array of category IDs" AFTER `primary_goal`');
    }
    if (!in_array('typical_value_range', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `typical_value_range` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `interested_categories`');
    }
    if (!in_array('can_ship', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `can_ship` TINYINT(1) DEFAULT NULL AFTER `typical_value_range`');
    }
    if (!in_array('google_id', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `google_id` VARCHAR(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `email`');
    }
    if (!in_array('phone_verified_at', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `phone_verified_at` DATETIME DEFAULT NULL AFTER `phone`');
    }
    if (!in_array('email_verified_at', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `google_id`');
    }
    if (!in_array('phone_verified_at', $usersColumns)) {
        DB::query('ALTER TABLE `users` ADD COLUMN `phone_verified_at` DATETIME DEFAULT NULL AFTER `phone`');
    }
    if (!db_has_index('users', 'uq_google_id')) {
        DB::query('ALTER TABLE `users` ADD UNIQUE KEY `uq_google_id` (`google_id`)');
    }
    
    // Create payments table for SEP gateway
    if (!db_has_table('payments')) {
        DB::query('
            CREATE TABLE `payments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `type` ENUM("wallet_topup","listing_promotion") COLLATE utf8mb4_unicode_ci NOT NULL,
                `amount` DECIMAL(12,0) NOT NULL,
                `res_num` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `ref_num` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `trace_no` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `state` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `status` ENUM("pending","success","failed","canceled","processing_failed") COLLATE utf8mb4_unicode_ci DEFAULT "pending",
                `gateway` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT "sep",
                `meta` JSON DEFAULT NULL,
                `processed_at` DATETIME DEFAULT NULL,
                `last_error` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_payments_resnum` (`res_num`),
                KEY `idx_payments_user` (`user_id`),
                KEY `idx_payments_refnum` (`ref_num`),
                CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    // Fix: Add `subscription_purchase` to payments.type ENUM
    if (db_has_table('payments')) {
        $paymentTableInfo = DB::fetch("SHOW CREATE TABLE `payments`");
        if ($paymentTableInfo && !empty($paymentTableInfo['Create Table']) && strpos($paymentTableInfo['Create Table'], "'subscription_purchase'") === false) {
            try {
                DB::query("ALTER TABLE `payments` MODIFY COLUMN `type` ENUM('wallet_topup','listing_promotion','subscription_purchase') COLLATE utf8mb4_unicode_ci NOT NULL");
            } catch (Throwable $e) {
                // Ignore if it fails, maybe another request is already doing it
                swapin_debug_log('migration-error-payments-type', ['msg' => $e->getMessage()]);
            }
        }
        if ($paymentTableInfo && !empty($paymentTableInfo['Create Table'])) {
            $needsProcessingFailed = strpos($paymentTableInfo['Create Table'], "'processing_failed'") === false;
            $needsExpired = strpos($paymentTableInfo['Create Table'], "'expired'") === false;
            if ($needsProcessingFailed || $needsExpired) {
                try {
                    DB::query("ALTER TABLE `payments` MODIFY COLUMN `status` ENUM('pending','success','failed','canceled','processing_failed','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'");
                } catch (Throwable $e) {
                    swapin_debug_log('migration-error-payments-status', ['msg' => $e->getMessage()]);
                }
            }
        }
        if (!db_has_column('payments', 'processed_at')) {
            try {
                DB::query("ALTER TABLE `payments` ADD COLUMN `processed_at` DATETIME DEFAULT NULL AFTER `meta`");
            } catch (Throwable $e) {
                swapin_debug_log('migration-error-payments-processed-at', ['msg' => $e->getMessage()]);
            }
        }
        if (!db_has_column('payments', 'last_error')) {
            try {
                DB::query("ALTER TABLE `payments` ADD COLUMN `last_error` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `processed_at`");
            } catch (Throwable $e) {
                swapin_debug_log('migration-error-payments-last-error', ['msg' => $e->getMessage()]);
            }
        }
    }

    if (db_has_table('wallet_transactions')) {
        $walletTableInfo = DB::fetch("SHOW CREATE TABLE `wallet_transactions`");
        if ($walletTableInfo && !empty($walletTableInfo['Create Table']) && strpos($walletTableInfo['Create Table'], "'payment'") === false) {
            try {
                DB::query("ALTER TABLE `wallet_transactions` MODIFY COLUMN `ref_type` ENUM('none','trade','trade_offer','listing','subscription_order','listing_bump','inspection_request','external','payment') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'Entity type that ref_id points to'");
            } catch (Throwable $e) {
                swapin_debug_log('migration-error-wallet-ref-type', ['msg' => $e->getMessage()]);
            }
        }
        if (!db_has_column('wallet_transactions', 'payment_id')) {
            try {
                DB::query("ALTER TABLE `wallet_transactions` ADD COLUMN `payment_id` INT UNSIGNED DEFAULT NULL AFTER `ref_id`");
            } catch (Throwable $e) {
                swapin_debug_log('migration-error-wallet-payment-id', ['msg' => $e->getMessage()]);
            }
        }
        if (!db_has_column('wallet_transactions', 'bank_ref_num')) {
            try {
                DB::query("ALTER TABLE `wallet_transactions` ADD COLUMN `bank_ref_num` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `payment_id`");
            } catch (Throwable $e) {
                swapin_debug_log('migration-error-wallet-bank-ref-num', ['msg' => $e->getMessage()]);
            }
        }
        if (db_has_column('wallet_transactions', 'payment_id') && !db_has_index('wallet_transactions', 'idx_wallet_payment')) {
            try {
                DB::query("ALTER TABLE `wallet_transactions` ADD KEY `idx_wallet_payment` (`payment_id`)");
            } catch (Throwable $e) {
                swapin_debug_log('migration-error-wallet-payment-index', ['msg' => $e->getMessage()]);
            }
        }
        if ($walletTableInfo && !empty($walletTableInfo['Create Table']) && strpos($walletTableInfo['Create Table'], 'fk_wallet_payment') === false && db_has_column('wallet_transactions', 'payment_id')) {
            try {
                DB::query("ALTER TABLE `wallet_transactions` ADD CONSTRAINT `fk_wallet_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL");
            } catch (Throwable $e) {
                swapin_debug_log('migration-error-wallet-payment-fk', ['msg' => $e->getMessage()]);
            }
        }
    }
    
    
    // Add new columns to trades table if they don't exist
    $tradesColumns = db_table_columns('trades');
    if (!in_array('step', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `step` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT "1=offer_accepted,2=fee_payment,3=diff_payment,4=contract,5=shipping,6=tracking,7=delivered,8=rating,9=completed" AFTER `status`');
    }
    if (!in_array('fee_paid', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `fee_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `step`');
    }
    if (!in_array('user_a_fee_paid', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_a_fee_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `fee_paid`');
    }
    if (!in_array('user_b_fee_paid', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_b_fee_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_a_fee_paid`');
    }
    if (in_array('fee_paid', $tradesColumns)) {
        DB::query('UPDATE `trades` SET `user_a_fee_paid` = 1, `user_b_fee_paid` = 1 WHERE `fee_paid` = 1 AND (`user_a_fee_paid` = 0 OR `user_b_fee_paid` = 0)');
    }
    if (!in_array('diff_paid', $tradesColumns)) {
        $afterFeeCol = in_array('user_b_fee_paid', $tradesColumns) ? 'user_b_fee_paid' : 'fee_paid';
        DB::query("ALTER TABLE `trades` ADD COLUMN `diff_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$afterFeeCol}`");
    }
    if (!in_array('user_a_shipping_date', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_a_shipping_date` DATE DEFAULT NULL AFTER `shipping_method`');
    }
    if (!in_array('user_a_shipping_time', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_a_shipping_time` TIME DEFAULT NULL AFTER `user_a_shipping_date`');
    }
    if (!in_array('user_b_shipping_date', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_b_shipping_date` DATE DEFAULT NULL AFTER `user_a_shipping_time`');
    }
    if (!in_array('user_b_shipping_time', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_b_shipping_time` TIME DEFAULT NULL AFTER `user_b_shipping_date`');
    }
    if (!in_array('user_a_shipping_method', $tradesColumns)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_a_shipping_method` ENUM('in_person','post','tipax','courier') DEFAULT NULL AFTER `user_b_shipping_time`");
    }
    if (!in_array('user_b_shipping_method', $tradesColumns)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `user_b_shipping_method` ENUM('in_person','post','tipax','courier') DEFAULT NULL AFTER `user_a_shipping_method`");
    }
    if (!in_array('proposed_shipping_date', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `proposed_shipping_date` DATE DEFAULT NULL AFTER `user_b_shipping_time`');
    }
    if (!in_array('proposed_shipping_time', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `proposed_shipping_time` TIME DEFAULT NULL AFTER `proposed_shipping_date`');
    }
    if (!in_array('user_a_delivered', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_a_delivered` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tracking_code_b`');
    }
    if (!in_array('user_b_delivered', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_b_delivered` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_a_delivered`');
    }
    if (!in_array('trade_rated', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `trade_rated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_b_delivered`');
    }
    if (!in_array('user_a_received', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_a_received` TINYINT(1) NOT NULL DEFAULT 0 AFTER `trade_rated`');
    }
    if (!in_array('user_b_received', $tradesColumns)) {
        DB::query('ALTER TABLE `trades` ADD COLUMN `user_b_received` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_a_received`');
    }
    if (!in_array('selected_payment_method', $tradesColumns)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `selected_payment_method` ENUM('in_person','bnpl','cash') DEFAULT NULL AFTER `user_b_received`");
    }
    if (!in_array('selected_shipping_method', $tradesColumns)) {
        DB::query("ALTER TABLE `trades` ADD COLUMN `selected_shipping_method` ENUM('courier','post','swapin_secure') DEFAULT NULL AFTER `selected_payment_method`");
    }
    
    // Create secure_room_messages table if it doesn't exist
    if (!db_has_table('secure_room_messages')) {
        DB::query('
            CREATE TABLE `secure_room_messages` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `trade_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `type` ENUM("text","image","file","pdf","video") COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT "text",
                `body` TEXT COLLATE utf8mb4_unicode_ci,
                `file_path` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_secure_trade` (`trade_id`),
                KEY `idx_secure_user` (`user_id`),
                CONSTRAINT `fk_secure_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_secure_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
    
    // Create listing_promotions table if it doesn't exist
    if (!db_has_table('listing_promotions')) {
        DB::query('
            CREATE TABLE `listing_promotions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listing_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `plan` ENUM("boost","featured","vip","targeted","ai","gold") COLLATE utf8mb4_unicode_ci NOT NULL,
                `starts_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ends_at` TIMESTAMP NOT NULL,
                `amount_paid` DECIMAL(12,0) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_promo_listing` (`listing_id`),
                KEY `idx_promo_user` (`user_id`),
                CONSTRAINT `fk_promo_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_promo_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
    
    // Add promotion columns to listings table if they don't exist
    $listingsColumns = db_table_columns('listings');
    if (!in_array('vip_until', $listingsColumns)) {
        DB::query('ALTER TABLE `listings` ADD COLUMN `vip_until` TIMESTAMP NULL DEFAULT NULL AFTER `featured_until`');
    }
    if (!in_array('targeted_until', $listingsColumns)) {
        DB::query('ALTER TABLE `listings` ADD COLUMN `targeted_until` TIMESTAMP NULL DEFAULT NULL AFTER `vip_until`');
    }
    if (!in_array('ai_promo_until', $listingsColumns)) {
        DB::query('ALTER TABLE `listings` ADD COLUMN `ai_promo_until` TIMESTAMP NULL DEFAULT NULL AFTER `targeted_until`');
    }
} catch (Throwable $e) {
    // Ignore migration errors, just log them
    swapin_debug_log('migration_error', ['message' => $e->getMessage()]);
}

auto_expire_listings();

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

function db_has_index(string $table, string $indexName): bool {
    $table = preg_replace('/[^a-z0-9_]/', '', $table);
    $indexName = preg_replace('/[^a-z0-9_]/', '', $indexName);
    if ($table === '' || $indexName === '') {
        return false;
    }
    try {
        return (bool) DB::fetch("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$indexName]);
    } catch (Throwable) {
        return false;
    }
}

function iran_cities(): array {
    return array_values(array_unique([
        // Tehran
        'تهران', 'اسلام‌شهر', 'ملارد', 'پاکدشت', 'شهریار', 'قرچک', 'بومهن', 'ورامین', 'ری', 'نسیم‌شهر', 'شهرری', 'فردیس', 'چهاردانگ', 'آبعلی', 'آبشار', 'آرادان',
        // Alborz
        'کرج', 'اکباتان', 'نظرآباد', 'آزادشهر', 'تالش', 'کلاردشت', 'نوشهر',
        // Khorasan Razavi
        'مشهد', 'نیشابور', 'سبزوار', 'قائن', 'تایباد', 'تربت‌جام', 'تربت‌حیدریه', 'طرقبه', 'چناران', 'خواف', 'درگز', 'رشتخوار', 'سرخس', 'فریمان', 'کاشمر', 'کنگان', 'گناباد',
        // Khorasan North
        'بجنورد', 'شیروان', 'اسفراین', 'جاجرم', 'درق', 'شهور', 'مانه و سملقان', 'گرمه',
        // Khorasan South
        'بیرجند', 'طبس', 'فردوس', 'سرایان', 'نهبندان', 'درمیان', 'بشرویه', 'خوسف', 'اسدیه', 'سربیشه', 'زیرکوه', 'مود',
        // Isfahan
        'اصفهان', 'کاشان', 'خمین', 'شوشتر', 'شاهین‌شهر', 'کمشاه', 'نایین', 'آران و بیدگل', 'بروجن', 'چادگان', 'خوانسار', 'دهاقان', 'سامان', 'شهرضا', 'فلاورجان', 'قمصر', 'گلپایگان', 'لنجان', 'مبارکه', 'میمه', 'نجف‌آباد', 'نطنز',
        // Fars
        'شیراز', 'مرودشت', 'جهرم', 'فسا', 'کازرون', 'نورآباد', 'ایج', 'ارسنجان', 'بوانات', 'حاجی‌آباد', 'درود', 'سپیدان', 'ششده', 'صغاد', 'فامور', 'قیر و کارزین', 'کوار', 'لار', 'ماماسانی', 'مهر', 'نودان', 'لامرد',
        // East Azarbaijan
        'تبریز', 'مرند', 'جلفا', 'خوی', 'سراب', 'شبستر', 'عجب‌شیر', 'کلیبر', 'مراغه', 'میانه', 'بستان‌آباد', 'بناب', 'اهر', 'اسکو', 'آذرشهر', 'چهارمنه', 'حسن‌آباد', 'خسروشهر', 'زرنق', 'سی‌سنگان', 'شادگان', 'شرف‌خانه', 'صوفیان', 'طالقان', 'کوهبنان', 'کماره', 'گلباش', 'ورزقان', 'وسفجان', 'هریس', 'هشترود',
        // West Azarbaijan
        'ارومیه', 'خوی', 'مراغه', 'میانه', 'نقده', 'ماکو', 'پیرانشهر', 'سردشت', 'اشنویه', 'باروق', 'بوکان', 'چالدران', 'کشاورز', 'محمدیار', 'نمق', 'رضی', 'سلماس', 'سیس', 'شوط',
        // Ardabil
        'اردبیل', 'مشکین‌شهر', 'نمین', 'پارس‌آباد', 'خلخال', 'بیله‌سوار', 'گرمی',
        // Qom
        'قم', 'قمصر',
        // Khuzestan
        'اهواز', 'دزفول', 'بندر‌امیر', 'بهبهان', 'خرمشهر', 'آبادان', 'سوسنگرد', 'شادگان', 'مسجد‌سلیمان', 'اندیمشک', 'حمیدیه', 'بندرماهشهر', 'چرام', 'رامشیر', 'راژئه', 'شوش', 'گتوند', 'هفتگل',
        // Kermanshah
        'کرمانشاه', 'حسن‌آباد', 'قصرشیرین', 'اسلام‌آباد غرب', 'جوانرود', 'سرپل‌ذهاب', 'سنقر', 'صحنه', 'پاوه', 'کنگاور', 'گیلانغرب', 'هرسین',
        // Kurdistan
        'سنندج', 'سقز', 'بانه', 'مریوان', 'کامیاران', 'بیجار', 'دهگلان', 'قروه', 'دیواندره',
        // Lorestan
        'خرم‌آباد', 'بروجرد', 'الشتر', 'دورود', 'فیروزآباد', 'کوهدشت', 'ازنا', 'پل‌دختر', 'نورآباد', 'چالشگرد', 'گراب',
        // Chaharmahal and Bakhtiari
        'شهرکرد', 'فارسان', 'ناغان',
        // Ilam
        'ایلام', 'ایوان', 'شیروان', 'دهلران', 'دره‌شهر',
        // Kohgiluyeh and Boyer-Ahmad
        'یاسوج', 'دهدشت', 'لنده', 'چرام',
        // Bushehr
        'بوشهر', 'دشتی', 'خورموج', 'بنک', 'دیر', 'تنگستان', 'شبانکاره', 'جم', 'برازجان', 'عسلویه',
        // Fars (continued)
        'ماماسانی',
        // Hormozgan
        'بندرعباس', 'قشم', 'جاسک', 'کیش', 'میناب', 'دشتستان', 'سیریک', 'خمیر', 'رودان', 'شهرک', 'بستک',
        // Sistan and Baluchistan
        'زاهدان', 'چابهار', 'کان', 'سراوان', 'یرمحمدی', 'خاش', 'نیک‌شهر', 'سرباز', 'بمپور', 'اسپکه', 'قصرقند', 'کنارک', 'زرآباد',
        // Kerman
        'کرمان', 'رفسنجان', 'سیرجان', 'زرند', 'بم', 'جیرفت', 'اهلوان', 'اسلام‌آباد شرقی', 'بافت', 'برخوار', 'جبالبارز', 'خاتم', 'راین', 'رودبار', 'سادق', 'شهربابک', 'صاحب‌آباد', 'عنق‌آباد', 'فهرج', 'قلعه‌گنج', 'کهنوج',
        // Yazd
        'یزد', 'میبد', 'تفت', 'حاجی‌آباد', 'سادوق', 'اشکذر', 'اردکان', 'بافق', 'مهریز', 'نیر', 'هرات',
        // Semnan
        'سمنان', 'دامغان', 'شاهرود', 'گرمسار', 'بیستون', 'سرخه', 'میامی', 'کلاته', 'مهدیشهر', 'ایوانکی',
        // Markazi
        'اراک', 'دلیجان', 'محلات', 'کمیجان', 'تفرش', 'ساوه', 'شازند', 'فراهان', 'گروه', 'زندیان', 'ماه‌نشان',
        // Qazvin
        'قزوین', 'بوئین‌زهرا', 'آبیک', 'تاکستان', 'رازمیان',
        // Zanjan
        'زنجان', 'خدابنده', 'قیدار', 'ارک', 'سجلاس', 'زرین‌آباد', 'هراز', 'نوروزی', 'ابهر', 'شهرچراغ',
        // Hamadan
        'همدان', 'ملایر', 'نهاوند', 'اسدآباد', 'بهار', 'تویسرکان', 'رزن', 'سراب‌قماس', 'فامنین', 'کبودرآهنگ', 'لاله‌آباد',
        // Markazi (continued)
        'خمین',
        // Lorestan (continued)
        'میمه',
        // Gilan
        'رشت', 'لاهیجان', 'لنگرود', 'رودسر', 'صومعه‌سرا', 'شفت', 'فومن', 'چابکسر', 'خمام', 'آستارا', 'راز', 'تالش', 'ماسال', 'سنگر', 'کلاچای',
        // Mazandaran
        'ساری', 'بابلسر', 'آمل', 'نکا', 'بابل', 'رودهن', 'نور', 'چالوس', 'رامسر', 'تنکابن', 'جویبار', 'شیرگاه', 'قائم‌شهر', 'عباس‌آباد',
        // Golestan
        'گرگان', 'گنبدکاووس', 'علی‌آباد کتول', 'آق‌قلا', 'بندرترکمن', 'کلار‌آباد', 'میندان', 'نوکنده', 'مراوه', 'رامیان',
    ]));
}

function render_city_options(string $selected = ''): string {
    $options = '';
    $cities = iran_cities();
    foreach ($cities as $city) {
        $selectedAttr = $city === $selected ? ' selected' : '';
        $options .= '<option value="' . h($city) . '"' . $selectedAttr . '>' . h($city) . '</option>';
    }
    if ($selected !== '' && !in_array($selected, $cities, true)) {
        $options = '<option value="' . h($selected) . '" selected>' . h($selected) . '</option>' . $options;
    }
    return $options;
}

function auto_expire_listings(): int {
    if (!db_has_table('listings')) {
        return 0;
    }

    $stmt = DB::query(
        "UPDATE listings
         SET status = 'expired', updated_at = NOW()
         WHERE status = 'active' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    return $stmt->rowCount();
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
//   external            — ref_id = external reference when no internal payment row exists
//   payment             — ref_id + payment_id = payments.id; bank_ref_num = gateway reference number
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
    $refId        = isset($ctx['ref_id']) && is_numeric((string)$ctx['ref_id']) ? (int)$ctx['ref_id'] : null;
    $paymentId    = isset($ctx['payment_id']) && is_numeric((string)$ctx['payment_id']) ? (int)$ctx['payment_id'] : null;
    $tradeId      = isset($ctx['trade_id']) ? (int)$ctx['trade_id'] : null;
    $listingId    = isset($ctx['listing_id']) ? (int)$ctx['listing_id'] : null;
    $bankRefNum   = isset($ctx['bank_ref_num']) ? trim((string)$ctx['bank_ref_num']) : null;
    $currencyCode = $ctx['currency_code'] ?? DEFAULT_CURRENCY_CODE;
    $currency     = $ctx['currency'] ?? DEFAULT_CURRENCY_LABEL;

    if ($tradeId && $refType === 'none') {
        $refType = 'trade';
    }
    if ($tradeId && !$refId) {
        $refId = $tradeId;
    }
    if ($paymentId && $refType === 'none') {
        $refType = 'payment';
    }
    if ($paymentId && !$refId) {
        $refId = $paymentId;
    }

    $pdo = DB::pdo();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
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
            'payment_id'    => $paymentId ?: null,
            'bank_ref_num'  => $bankRefNum !== '' ? $bankRefNum : null,
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
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Utility helpers
// ══════════════════════════════════════════════════════════════════════════════
function clean(string $val): string {
    return trim(htmlspecialchars_decode(strip_tags($val)));
}

function is_valid_phone(string $phone): bool {
    // Basic validation for Iranian phone numbers (starts with 09 and is 11 digits long)
    // This can be expanded for more rigorous validation if needed.
    return (bool) preg_match('/^09\d{9}$/', $phone);
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
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $maxSize = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $extMap = [
        'jpg'  => 'jpg',
        'jpeg' => 'jpg',
        'png'  => 'png',
        'webp' => 'webp',
        'gif'  => 'gif',
    ];

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = @finfo_file($finfo, $file['tmp_name']);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower(trim($detected));
            }
            @finfo_close($finfo);
        }
    }

    if (!$mime && function_exists('mime_content_type')) {
        $detected = @mime_content_type($file['tmp_name']);
        if (is_string($detected) && $detected !== '') {
            $mime = strtolower(trim($detected));
        }
    }

    $info = @getimagesize($file['tmp_name']);
    $imageMime = is_array($info) && !empty($info['mime'])
        ? strtolower((string)$info['mime'])
        : null;
    if (!$mime && $imageMime) {
        $mime = $imageMime;
    }

    $originalExt = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $normalizedExt = $extMap[$originalExt] ?? null;
    $normalizedMime = $mime && isset($allowed[$mime]) ? $mime : null;
    if (!$normalizedMime && $imageMime && isset($allowed[$imageMime])) {
        $normalizedMime = $imageMime;
    }

    if ($normalizedMime) {
        $ext = $allowed[$normalizedMime];
    } elseif ($normalizedExt && $imageMime) {
        $ext = $normalizedExt;
    } else {
        return null;
    }

    if ($info === false) {
        return null;
    }

    return ['ext' => $ext, 'mime' => $normalizedMime ?: ($imageMime ?: 'image/' . $ext)];
}

function store_uploaded_image(array $file, string $prefix, string $destDir): ?string {
    $valid = validate_uploaded_image($file);
    if (!$valid) {
        return null;
    }

    $filename = $prefix . '_' . uniqid('', true) . '_' . time() . '.' . $valid['ext'];
    $destDir  = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR;

    if (!is_dir($destDir)) {
        if (!@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            if (function_exists('swapin_debug_log')) {
                swapin_debug_log('upload-mkdir-failed', ['dir' => $destDir]);
            }
            return null;
        }
    }

    // Ensure web server can write (fixes Permission denied on Linux hosts)
    if (!is_writable($destDir)) {
        @chmod($destDir, 0775);
    }
    if (!is_writable($destDir)) {
        @chmod($destDir, 0777);
    }
    if (!is_writable($destDir)) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('upload-dir-not-writable', ['dir' => $destDir]);
        }
        return null;
    }

    $dest = $destDir . $filename;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('upload-move-failed', [
                'tmp'  => $file['tmp_name'] ?? '',
                'dest' => $dest,
                'writable' => is_writable($destDir),
            ]);
        }
        return null;
    }

    @chmod($dest, 0644);
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
require_once __DIR__ . '/google_auth.php';

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
require_once __DIR__ . '/google_auth.php';
require_once __DIR__ . '/sep_payment.php';
