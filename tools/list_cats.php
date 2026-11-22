<?php
require_once __DIR__ . '/includes/v2.php';
$cats = DB::fetchAll('SELECT id, name FROM categories WHERE is_active = 1');
foreach ($cats as $cat) {
    echo $cat['id'] . ': ' . $cat['name'] . PHP_EOL;
}
