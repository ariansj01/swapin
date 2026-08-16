<?php
/**
 * SEO canonical helpers — lightweight tests (no DB bootstrap).
 */
declare(strict_types=1);

define('APP_URL', 'https://swaapin.ir');

require_once __DIR__ . '/../includes/seo.php';

$passed = 0;
$failed = 0;

function assert_eq(string $expected, string $actual, string $label): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "OK  {$label}\n";
    } else {
        $failed++;
        echo "FAIL {$label}\n  expected: {$expected}\n  actual:   {$actual}\n";
    }
}

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

assert_true(!seo_is_valid_canonical_url('دستیار هوشمند سواَپین'), 'reject Persian title as canonical');
assert_true(seo_is_valid_canonical_url('https://swaapin.ir/page/ai-assistant'), 'accept absolute URL');
assert_true(seo_is_valid_canonical_url('/page/ai-assistant'), 'accept relative path');

assert_eq(
    'https://swaapin.ir/page/ai-assistant',
    seo_resolve_canonical('دستیار هوشمند سواَپین', 'https://swaapin.ir/page/ai-assistant'),
    'fallback when stored value is title'
);

assert_eq(
    'https://swaapin.ir/page/ai-assistant',
    seo_resolve_canonical('https://swaapin.ir/page/ai-assistant', 'https://swaapin.ir/page/other'),
    'use valid override'
);

assert_eq(
    'https://swaapin.ir/page/ai-assistant',
    seo_resolve_canonical('/page/ai-assistant', 'https://swaapin.ir/fallback'),
    'normalize relative override'
);

assert_eq('', seo_sanitize_stored_canonical('دستیار هوشمند سواَپین'), 'sanitize title to empty');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
