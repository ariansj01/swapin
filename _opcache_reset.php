<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/admin.php';

// اندپوینت پاک‌سازی OPcache — فقط برای ادمین احراز هویت‌شده (جلوگیری از DoS)
require_admin();

header('Content-Type: text/plain; charset=utf-8');

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo 'OPcache reset successfully.';
} else {
    echo 'OPcache not available.';
}
