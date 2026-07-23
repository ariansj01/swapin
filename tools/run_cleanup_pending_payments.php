<?php
/**
 * Cleanup old pending payments
 * Usage: php run_cleanup_pending_payments.php
 * Can be set up as a cron job (e.g., daily)
 */

require_once __DIR__ . '/../includes/config.php';

// Expire pending payments older than 24 hours
$hoursThreshold = 24;

try {
    $result = DB::query(
        "UPDATE payments 
         SET status = 'expired', 
             last_error = 'Payment expired after {$hoursThreshold} hours of being pending',
             updated_at = NOW()
         WHERE status = 'pending' 
           AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
        [$hoursThreshold]
    );

    $affectedRows = $result->rowCount();
    echo "Successfully expired {$affectedRows} pending payment(s) older than {$hoursThreshold} hours.\n";
    swapin_debug_log('cleanup_pending_payments', [
        'hours_threshold' => $hoursThreshold,
        'affected_rows' => $affectedRows
    ]);

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    swapin_debug_log('cleanup_pending_payments_error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
}
