<?php
foreach (['localhost', '127.0.0.1'] as $host) {
    try {
        new PDO("mysql:host=$host;dbname=kala_b_kala;charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        echo "$host: OK\n";
    } catch (Throwable $e) {
        echo "$host: " . $e->getMessage() . "\n";
    }
}
