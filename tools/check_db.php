<?php
require_once dirname(__DIR__) . '/includes/config.php';
echo "--- TABLES ---" . PHP_EOL;
$tables = DB::fetchAll('SHOW TABLES');
foreach ($tables as $t) {
    echo current($t) . PHP_EOL;
}
echo "--- LISTINGS DESC ---" . PHP_EOL;
$desc = DB::fetchAll('DESC listings');
print_r($desc);
