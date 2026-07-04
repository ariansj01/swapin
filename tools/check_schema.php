<?php
/**
 * Check DB schema vs admin panel expectations.
 * Usage: php tools/check_schema.php
 */
require_once __DIR__ . '/../includes/config.php';
require_cli();

header('Content-Type: text/plain; charset=utf-8');

$adminTables = [
    'disputes'            => 'migration_v2.sql → run_migration.php',
    'inspection_requests' => 'migration_v2.sql → run_migration.php',
    'support_tickets'     => 'migration_support.sql → run_support_migration.php',
    'support_messages'    => 'migration_support.sql → run_support_migration.php',
    'error_reports'       => 'migration_support.sql → run_support_migration.php',
];

$userCols = [
    'role'          => 'migration_admin.sql',
    'kyc_status'    => 'migration_v2.sql',
    'seller_type'   => 'migration_v2.sql',
    'store_name'    => 'migration_v2.sql',
    'national_id'   => 'migration_v2.sql',
    'bank_account'  => 'migration_v2.sql',
    'id_card_image' => 'migration_v2.sql',
    'kyc_note'      => 'migration_admin.sql (after v2)',
];

$listingCols = [
    'review_status' => 'migration_admin.sql',
    'review_note'   => 'migration_admin.sql',
];

$walletCols = [
    'ref_type', 'trade_id', 'listing_id', 'currency_code', 'currency',
];

echo "=== Admin tables ===\n";
foreach ($adminTables as $table => $migration) {
    $exists = DB::fetch('SHOW TABLES LIKE ?', [$table]);
    echo ($exists ? 'OK  ' : 'MISS') . "  $table  ($migration)\n";
}

echo "\n=== users columns ===\n";
foreach ($userCols as $col => $migration) {
    $row = DB::fetch('SHOW COLUMNS FROM users LIKE ?', [$col]);
    echo ($row ? 'OK  ' : 'MISS') . "  users.$col  ($migration)\n";
}

echo "\n=== listings columns ===\n";
foreach ($listingCols as $col => $migration) {
    $row = DB::fetch('SHOW COLUMNS FROM listings LIKE ?', [$col]);
    echo ($row ? 'OK  ' : 'MISS') . "  listings.$col  ($migration)\n";
}

echo "\n=== wallet_transactions columns ===\n";
foreach ($walletCols as $col) {
    $row = DB::fetch('SHOW COLUMNS FROM wallet_transactions LIKE ?', [$col]);
    echo ($row ? 'OK  ' : 'MISS') . "  wallet_transactions.$col  (migration_wallet.sql)\n";
}

echo "\nFix: php run_all_migrations.php\n";
