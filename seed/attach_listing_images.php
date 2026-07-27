<?php
define('SKIP_SESSION', true);
require_once __DIR__ . '/../includes/config.php';

echo "=== Attaching images to listings ===\n";

$imageMap = [
    249 => 'tara.webp',
    251 => 'cac53384.jpg',
    254 => 'samsung-s24ultra4.webp',
    250 => 'images(8).jpg',
];

$uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
$attached = 0;
$skipped = 0;

foreach ($imageMap as $listingId => $filename) {
    $listingId = (int)$listingId;
    $filename = basename((string)$filename);
    $fullPath = $uploadDir . $filename;

    $listing = DB::fetch('SELECT id, title FROM listings WHERE id = ? LIMIT 1', [$listingId]);
    if (!$listing) {
        echo "SKIP #{$listingId}: listing not found\n";
        $skipped++;
        continue;
    }

    if (!is_file($fullPath)) {
        echo "SKIP #{$listingId}: file not found -> {$filename}\n";
        $skipped++;
        continue;
    }

    $existing = DB::fetch(
        'SELECT id FROM listing_images WHERE listing_id = ? AND filename = ? LIMIT 1',
        [$listingId, $filename]
    );
    if ($existing) {
        echo "SKIP #{$listingId}: image already attached -> {$filename}\n";
        $skipped++;
        continue;
    }

    $imageStats = DB::fetch(
        'SELECT COUNT(*) AS total, COALESCE(MAX(sort_order), -1) AS max_sort, MAX(is_primary) AS has_primary
         FROM listing_images
         WHERE listing_id = ?',
        [$listingId]
    ) ?: [];

    $total = (int)($imageStats['total'] ?? 0);
    $maxSort = (int)($imageStats['max_sort'] ?? -1);
    $hasPrimary = (int)($imageStats['has_primary'] ?? 0) === 1;

    DB::insert('listing_images', [
        'listing_id' => $listingId,
        'filename' => $filename,
        'is_primary' => (!$hasPrimary && $total === 0) ? 1 : 0,
        'sort_order' => $maxSort + 1,
    ]);

    echo "OK   #{$listingId}: {$listing['title']} -> {$filename}\n";
    $attached++;
}

echo "\nDone. Attached: {$attached}, Skipped: {$skipped}\n";
