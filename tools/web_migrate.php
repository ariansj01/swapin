<?php
/**
 * Run migrations via web browser - use after login as admin!
 */
require_once __DIR__ . '/../includes/config.php';

// Optional: Only allow admins to run
if (false) { // Set to true if you want to restrict
    $user = auth_user();
    if (!$user || $user['role'] !== 'admin') {
        die('Access denied.');
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Run Migrations</title>
    <style>
        body { font-family: Tahoma, Arial; padding: 40px; direction: rtl; }
        .ok { color: green; }
        .skip { color: blue; }
        .fail { color: red; }
        pre { background: #f5f5f5; padding: 20px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>اجرای Migrationها</h1>
    <pre>
<?php

function runSqlFile($file, $pdo) {
    if (!file_exists($file)) {
        echo "FAIL: File $file not found\n";
        return false;
    }
    $sql = file_get_contents($file);
    // Remove comments
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $ok = 0;
    $skip = 0;
    $fail = 0;
    
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        if (!preg_match('/^(ALTER|CREATE|INSERT|UPDATE)/i', $stmt)) continue;
        
        try {
            $pdo->exec($stmt);
            $ok++;
            echo "<span class='ok'>OK</span>: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 80) . "\n";
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column') 
                || str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate entry')) {
                $skip++;
                echo "<span class='skip'>SKIP</span>: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 70) . "\n";
            } else {
                $fail++;
                echo "<span class='fail'>FAIL</span>: $msg\n";
                echo "  -> " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 100) . "\n";
            }
        }
    }
    echo "\nDone. OK=$ok, SKIP=$skip, FAIL=$fail\n";
    return $fail === 0;
}

$pdo = DB::pdo();

$migrations = [
    __DIR__ . '/../migration_v2.sql',
    __DIR__ . '/../migration_admin.sql',
    __DIR__ . '/../migration_support.sql',
    __DIR__ . '/../migration_wallet.sql',
];

foreach ($migrations as $migration) {
    echo "\n====================================\n";
    echo "Running: " . basename($migration) . "\n";
    echo "====================================\n";
    runSqlFile($migration, $pdo);
}

// Ensure admin account
echo "\n====================================\n";
echo "Syncing admin account...\n";
echo "====================================\n";
admin_ensure_default_admin();
$admin = DB::fetch('SELECT email, role FROM users WHERE email = ?', [ADMIN_EMAIL]);
if ($admin) {
    echo "<span class='ok'>OK</span>: Admin account ready: {$admin['email']} (role={$admin['role']})\n";
}
?>
    </pre>
    <p><strong>مهم:</strong> بعد از اتمام کار این فایل را حذف کنید!</p>
</body>
</html>
