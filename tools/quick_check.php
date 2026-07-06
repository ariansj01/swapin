<?php
/**
 * Quick DB schema check without session
 */
define('DB_HOST', getenv('SWAPIN_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SWAPIN_DB_NAME') ?: 'swapin');
define('DB_USER', getenv('SWAPIN_DB_USER') ?: 'ltze_swapin_kP%user');
define('DB_PASS', getenv('SWAPIN_DB_PASS') !== false ? (string)getenv('SWAPIN_DB_PASS') : 'kP%B!-)+*75p');
define('DB_CHAR', 'utf8mb4');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHAR,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("DB Connection Error: " . $e->getMessage() . "\n");
}

$adminTables = [
    'disputes',
    'inspection_requests',
    'support_tickets',
    'support_messages',
    'error_reports',
];

$userCols = [
    'role',
    'kyc_status',
    'seller_type',
    'store_name',
    'national_id',
    'bank_account',
    'id_card_image',
    'kyc_note',
];

echo "=== Admin tables ===\n";
foreach ($adminTables as $table) {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $exists = $stmt->fetch();
    echo ($exists ? 'OK  ' : 'MISS') . "  $table\n";
}

echo "\n=== users columns ===\n";
foreach ($userCols as $col) {
    $stmt = $pdo->prepare('SHOW COLUMNS FROM users LIKE ?');
    $stmt->execute([$col]);
    $row = $stmt->fetch();
    echo ($row ? 'OK  ' : 'MISS') . "  users.$col\n";
}

echo "\n";
