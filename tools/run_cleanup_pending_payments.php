<?php

require_once __DIR__.'/../includes/config.php';

DB::query("
UPDATE payments
SET status='canceled'
WHERE status='pending'
AND created_at < NOW() - INTERVAL 24 HOUR
");

echo "Pending payments cleaned\n";
