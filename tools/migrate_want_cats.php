<?php
/**
 * Data Migration Tool: Structured "Want" Category
 * Converts free-text 'want_in_return' to 'want_category_id' where possible.
 */

require_once dirname(__DIR__) . '/includes/v2.php';

// 1. Get all active categories for mapping
$categories = DB::fetchAll('SELECT id, name, slug FROM categories WHERE is_active = 1');
$mapping = [];
foreach ($categories as $cat) {
    // Basic normalization: lowercase and trim
    $mapping[mb_strtolower($cat['name'])] = (int)$cat['id'];
}

// 2. Fetch listings that need migration
$listings = DB::fetchAll("SELECT id, want_in_return FROM listings WHERE want_category_id IS NULL AND want_in_return IS NOT NULL AND want_in_return != ''");

echo "Starting migration for " . count($listings) . " listings..." . PHP_EOL;

$migratedCount = 0;
$skippedCount = 0;

foreach ($listings as $listing) {
    $text = mb_strtolower($listing['want_in_return']);
    $matchedId = null;

    // Strategy A: Direct match
    if (isset($mapping[$text])) {
        $matchedId = $mapping[$text];
    } else {
        // Strategy B: Keyword search in text
        foreach ($mapping as $name => $id) {
            if (mb_strpos($text, $name) !== false) {
                $matchedId = $id;
                break; // Take the first match
            }
        }
    }

    if ($matchedId) {
        DB::update('listings', ['want_category_id' => $matchedId], 'id = ?', [$listing['id']]);
        $migratedCount++;
        echo "Migrated Listing #{$listing['id']}: '{$listing['want_in_return']}' -> Cat ID {$matchedId}" . PHP_EOL;
    } else {
        $skippedCount++;
        // echo "Skipped Listing #{$listing['id']}: '{$listing['want_in_return']}' (No match found)" . PHP_EOL;
    }
}

echo "---" . PHP_EOL;
echo "Migration finished." . PHP_EOL;
echo "Total Migrated: {$migratedCount}" . PHP_EOL;
echo "Total Skipped (Manual Review Needed): {$skippedCount}" . PHP_EOL;
echo "---" . PHP_EOL;
