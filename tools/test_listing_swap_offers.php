<?php
/**
 * Lightweight tests for listing_swap_offers helpers (no full config bootstrap).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/listing_swap_offers.php';

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "OK  {$label}\n";
    } else {
        $failed++;
        echo "FAIL {$label}\n";
    }
}

assert_true(listing_swap_offer_message_max_length() === 500, 'message max length');
assert_true(
    listing_swap_offer_default_message() === 'من این آگهی را دارم و علاقه‌مند به معاوضه با آگهی شما هستم.',
    'default message'
);
assert_true(listing_swap_offer_status_label('pending') === 'در انتظار پاسخ', 'status label pending');

$activeSwap = ['status' => 'active', 'review_status' => 'approved', 'listing_mode' => 'swap'];
$activeBoth = ['status' => 'active', 'review_status' => 'approved', 'listing_mode' => 'both'];
$sellOnly = ['status' => 'active', 'review_status' => 'approved', 'listing_mode' => 'sell'];
$inactive = ['status' => 'draft', 'review_status' => 'approved', 'listing_mode' => 'swap'];

assert_true(listing_swap_offer_listing_swappable($activeSwap), 'swap listing swappable');
assert_true(listing_swap_offer_listing_swappable($activeBoth), 'both listing swappable');
assert_true(!listing_swap_offer_listing_swappable($sellOnly), 'sell listing not swappable');
assert_true(!listing_swap_offer_listing_swappable($inactive), 'inactive listing not swappable');
assert_true(!listing_swap_offer_listing_swappable(null), 'null listing not swappable');

$body = json_encode(['target_listing_id' => 42, 'message' => 'test'], JSON_UNESCAPED_UNICODE);
$stream = fopen('php://memory', 'r+');
fwrite($stream, $body);
rewind($stream);

// listing_swap_offer_read_request_body uses php://input; skip direct test

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
