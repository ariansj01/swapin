<?php
require_once __DIR__ . '/../includes/config.php';

require_cli();

header('Content-Type: text/plain; charset=utf-8');

$demoEmail = 'demo.user1@swapin.local';

$u = DB::fetch('SELECT name, email, city FROM users WHERE email = ?', [$demoEmail]);
if (!$u) {
    echo "Demo user not found. Please run seed_demo.php first.\n";
    exit(1);
}

$l = DB::fetch(
    'SELECT title FROM listings l JOIN users u ON u.id = l.user_id WHERE u.email = ? LIMIT 1',
    [$demoEmail]
);
if (!$l) {
    echo "Demo listing not found for {$demoEmail}.\n";
    exit(1);
}

echo "User: {$u['name']} ({$u['city']})\n";
echo "Listing: {$l['title']}\n";
