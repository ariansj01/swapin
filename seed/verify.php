<?php
require_once __DIR__ . '/../includes/config.php';

require_cli();
$u = DB::fetch("SELECT name, email, city FROM users WHERE email = 'demo.user1@swapin.local'");
$l = DB::fetch("SELECT title FROM listings l JOIN users u ON u.id = l.user_id WHERE u.email = 'demo.user1@swapin.local' LIMIT 1");
echo "User: {$u['name']} ({$u['city']})\n";
echo "Listing: {$l['title']}\n";
