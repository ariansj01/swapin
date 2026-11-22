<?php
/**
 * کرون: ساخت پروفایل هوشمند کاربران (فاز ۰/۲)
 *
 * Usage:
 *   php tools/behavior-profile-cron.php [--days=30] [--limit=0] [--prune-days=90]
 *
 * --days       : بازه فعالیت اخیر کاربران برای بازسازی پروفایل (پیش‌فرض ۳۰)
 * --limit      : حداکثر تعداد کاربر در هر اجرا (پیش‌فرض ۰ = بی‌نهایت)
 * --prune-days : حذف رخدادهای قدیمی‌تر از این تعداد روز (پیش‌فرض ۹۰، ۰ = غیرفعال)
 *
 * پیشنهاد زمان‌بندی: اجرای ساعتی یا روزانه.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/behavior.php';
require_once __DIR__ . '/../includes/profile_builder.php';
require_once __DIR__ . '/../includes/learning.php';

$opts = getopt('', ['days::', 'limit::', 'prune-days::']);
$days      = isset($opts['days']) ? max(1, (int)$opts['days']) : 30;
$limit     = isset($opts['limit']) ? max(0, (int)$opts['limit']) : 0;
$pruneDays = isset($opts['prune-days']) ? max(0, (int)$opts['prune-days']) : 90;

try {
    $result = profile_build_all_users($days, $limit);
    echo "Profiles rebuilt: {$result['built']} / {$result['users']} users (days={$days}).\n";

    if ($pruneDays > 0) {
        $removed = profile_prune_events($pruneDays);
        echo "Pruned {$removed} old event(s) older than {$pruneDays} day(s).\n";
    }

    // فاز ۶: یادگیری مستمر — بستن حلقه توصیه و نیازها
    $clicked   = learning_mark_recommendation_clicks(14);
    $stale     = learning_mark_stale_needs(90);
    $prunedRec = learning_prune_recommendations($pruneDays > 0 ? max(30, $pruneDays) : 90);
    echo "Learning: {$clicked} recommendation click(s) marked, {$stale} need(s) stale, {$prunedRec} recommendation log(s) pruned.\n";

    swapin_debug_log('behavior-profile-cron', [
        'days'       => $days,
        'limit'      => $limit,
        'users'      => $result['users'],
        'built'      => $result['built'],
        'prune_days' => $pruneDays,
        'learning'   => [
            'recommendation_clicks' => $clicked,
            'stale_needs'           => $stale,
            'pruned_recommendations'=> $prunedRec,
        ],
    ]);
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    swapin_debug_log('behavior-profile-cron-error', [
        'message' => $e->getMessage(),
    ]);
    exit(1);
}
