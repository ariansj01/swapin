<?php
/**
 * Run all DB migrations in the correct order.
 * Usage: php run_all_migrations.php
 */
require_once __DIR__ . '/includes/config.php';

require_cli();

header('Content-Type: text/plain; charset=utf-8');

$scripts = [
    'run_migration.php'         => 'v2 (KYC, escrow, disputes, inspections, …)',
    'run_admin_migration.php'   => 'admin role + listing moderation',
    'run_support_migration.php' => 'support tickets + error reports',
    'run_wallet_migration.php'  => 'wallet ref columns + currency',
];

$failed = false;

foreach ($scripts as $file => $label) {
    echo "\n========== $label ($file) ==========\n";
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/' . $file), $code);
    if ($code !== 0) {
        $failed = true;
        echo "STOPPED: $file exited with code $code\n";
        break;
    }
}

echo $failed ? "\nSome migrations failed.\n" : "\nAll migrations completed.\n";
exit($failed ? 1 : 0);
