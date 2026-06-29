<?php
/**
 * Run migration_admin.sql and ensure default admin account exists.
 * Usage: php run_admin_migration.php
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/migration_admin.sql';
if (!file_exists($sqlFile)) {
    echo "migration_admin.sql not found.\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
$sql = preg_replace('/^--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$pdo  = DB::pdo();
$ok   = 0;
$skip = 0;
$fail = 0;

foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    if (!preg_match('/^(ALTER|CREATE|INSERT|UPDATE)/i', $stmt)) continue;

    try {
        $pdo->exec($stmt);
        $ok++;
        echo "OK: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 90) . "\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate column')
            || str_contains($msg, 'already exists')
            || str_contains($msg, 'Duplicate entry')) {
            $skip++;
            echo "SKIP: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 70) . "\n";
        } else {
            $fail++;
            echo "FAIL: $msg\n";
            echo "  -> " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 100) . "\n";
        }
    }
}

admin_ensure_default_admin();

$admin = DB::fetch('SELECT email, role FROM users WHERE email = ?', [ADMIN_EMAIL]);
echo "\nDone. OK=$ok SKIP=$skip FAIL=$fail\n";
if ($admin) {
    echo "Admin login: {$admin['email']} / " . ADMIN_DEFAULT_PASS . " (role={$admin['role']})\n";
} else {
    echo "WARNING: admin account was not created.\n";
}
exit($fail > 0 ? 1 : 0);
