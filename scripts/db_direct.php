<?php
$env = parse_ini_file(__DIR__ . '/../.env');
$host = $env['SWAPIN_DB_HOST'] ?? 'localhost';
$name = $env['SWAPIN_DB_NAME'] ?? 'kala_b_kala';
$user = $env['SWAPIN_DB_USER'] ?? 'root';
$pass = $env['SWAPIN_DB_PASS'] ?? '';

$mysqli = new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_error) {
    die("DB Connect failed: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
