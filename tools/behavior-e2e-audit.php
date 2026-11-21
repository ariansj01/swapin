<?php
/**
 * تست سرتاسری سیستم هوشمند سواپین (فاز ۰ تا ۶)
 *
 * این ابزار کل چرخه را روی دیتابیس همان محیطی که اجرا می‌شود می‌سنجد:
 *   ردیابی → پروفایل علایق → توصیه → ترغیب → کشف نیاز → یادگیری مستمر
 *
 * حالت‌ها:
 *   php tools/behavior-e2e-audit.php            # فقط‌خواندنی (dry-run): بررسی اسکیما و اجرای موتورهای خواندنی
 *   php tools/behavior-e2e-audit.php --apply     # اجرای کامل چرخه با کاربر آزمایشی موقت + پاکسازی خودکار
 *   php tools/behavior-e2e-audit.php --apply --keep  # اجرا بدون پاکسازی (برای بازرسی دستی)
 *
 * نکته: در حالت --apply یک کاربر موقت ساخته می‌شود و تمام رخدادها/وزن‌ها/نیازها/لاگ‌های
 *       مربوط به آن در پایان حذف می‌شوند؛ داده کاربران واقعی دست نمی‌خورد (به‌جز یادگیری
 *       کهنه‌سازیِ نیازها که دقیقاً همان کاری است که کرون روزانه انجام می‌دهد).
 *
 * پیشنهاد: ابتدا روی Staging اجرا شود.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/behavior.php';
require_once __DIR__ . '/../includes/profile_builder.php';
require_once __DIR__ . '/../includes/recommend.php';
require_once __DIR__ . '/../includes/nudge.php';
require_once __DIR__ . '/../includes/need_discovery.php';
require_once __DIR__ . '/../includes/learning.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool must be run from CLI.\n");
}

// ───────────────────────────── ابزارهای خروجی ─────────────────────────────

$GLOBALS['e2e_pass'] = 0;
$GLOBALS['e2e_fail'] = 0;
$GLOBALS['e2e_warn'] = 0;

function e2e_out(string $s = ''): void { echo $s . PHP_EOL; }

function e2e_head(string $title): void {
    e2e_out();
    e2e_out('── ' . $title . ' ' . str_repeat('─', max(0, 60 - mb_strlen($title))));
}

function e2e_check(string $label, bool $ok, string $detail = '', bool $warn = false): bool {
    if ($ok) {
        $GLOBALS['e2e_pass']++;
        e2e_out("  [PASS] {$label}" . ($detail !== '' ? " — {$detail}" : ''));
    } elseif ($warn) {
        $GLOBALS['e2e_warn']++;
        e2e_out("  [WARN] {$label}" . ($detail !== '' ? " — {$detail}" : ''));
    } else {
        $GLOBALS['e2e_fail']++;
        e2e_out("  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : ''));
    }
    return $ok;
}

function e2e_info(string $s): void { e2e_out('  · ' . $s); }

function e2e_ms(float $startedAt): string {
    return number_format((microtime(true) - $startedAt) * 1000, 1) . 'ms';
}

// ───────────────────────────── ورودی‌ها ─────────────────────────────

$opts     = getopt('', ['apply', 'keep', 'help']);
$apply    = isset($opts['apply']);
$keep     = isset($opts['keep']);

if (isset($opts['help'])) {
    e2e_out('Usage: php tools/behavior-e2e-audit.php [--apply] [--keep]');
    exit(0);
}

$tmpUserIds = [];   // کاربران موقت ساخته‌شده
$tmpAnonIds = [];   // شناسه‌های ناشناس استفاده‌شده

/**
 * پاکسازی کامل داده کاربران آزمایشی.
 */
function e2e_cleanup(array $userIds, array $anonIds): void {
    $userIds = array_values(array_filter(array_map('intval', $userIds)));
    $anonIds = array_values(array_filter(array_map('strval', $anonIds)));

    $phU = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : '';
    $phA = $anonIds ? implode(',', array_fill(0, count($anonIds), '?')) : '';

    // [sql, params] — پارامترها دقیقاً مطابق placeholderها
    $jobs = [];
    if ($userIds) {
        $sql = 'DELETE FROM user_events WHERE user_id IN (' . $phU . ')';
        $params = $userIds;
        if ($anonIds) {
            $sql .= ' OR anon_id IN (' . $phA . ')';
            $params = array_merge($params, $anonIds);
        }
        $jobs['user_events'] = [$sql, $params];
        $jobs['user_interest_weights'] = ['DELETE FROM user_interest_weights WHERE user_id IN (' . $phU . ')', $userIds];
        $jobs['user_needs']            = ['DELETE FROM user_needs WHERE user_id IN (' . $phU . ')', $userIds];
        $jobs['recommendation_log']    = ['DELETE FROM recommendation_log WHERE user_id IN (' . $phU . ')', $userIds];
        $jobs['users']                 = ['DELETE FROM users WHERE id IN (' . $phU . ')', $userIds];
    }

    foreach ($jobs as $table => [$sql, $params]) {
        try {
            $n = DB::query($sql, $params)->rowCount();
            e2e_info("پاکسازی {$table}: {$n} ردیف");
        } catch (Throwable $e) {
            e2e_info("پاکسازی {$table} ناموفق: " . $e->getMessage());
        }
    }
}

// تضمین پاکسازی در صورت خطای غیرمنتظره
register_shutdown_function(static function () use (&$tmpUserIds, &$tmpAnonIds, &$keep) {
    if ($keep) return;
    if (!$tmpUserIds && !$tmpAnonIds) return;
    e2e_out();
    e2e_out('پاکسازی نهایی داده آزمایشی:');
    e2e_cleanup($tmpUserIds, $tmpAnonIds);
});

// ───────────────────────────── بخش ۰: محیط و اسکیما ─────────────────────────────

e2e_out('تست سرتاسری سیستم هوشمند سواپین');
e2e_out('تاریخ: ' . date('Y-m-d H:i:s') . ' | حالت: ' . ($apply ? 'APPLY (نوشتن + پاکسازی)' : 'DRY-RUN (فقط‌خواندنی)'));

e2e_head('۰) محیط و اسکیما');

try {
    $dbName = (string)(DB::fetch('SELECT DATABASE() AS d')['d'] ?? '?');
    e2e_info('APP_URL: ' . (defined('APP_URL') ? APP_URL : '?'));
    e2e_info('دیتابیس: ' . $dbName);
} catch (Throwable $e) {
    e2e_check('اتصال به دیتابیس', false, $e->getMessage());
    exit(1);
}
e2e_check('اتصال به دیتابیس', true);

behavior_ensure_tables();
profile_ensure_tables();
recommend_ensure_tables();

$requiredTables = ['user_events', 'user_interest_weights', 'user_needs', 'recommendation_log'];
$missingTables  = [];
foreach ($requiredTables as $t) {
    if (!db_has_table($t)) $missingTables[] = $t;
}
e2e_check(
    'جداول سیستم هوشمند',
    !$missingTables,
    $missingTables ? 'غایب: ' . implode(', ', $missingTables) : 'هر ۴ جدول موجودند'
);

e2e_info('جداول پشتیبان موجود: '
    . 'listings=' . (db_has_table('listings') ? '✓' : '✗')
    . ' saved_listings=' . (db_has_table('saved_listings') ? '✓' : '✗')
    . ' saved_searches=' . (db_has_table('saved_searches') ? '✓' : '✗')
    . ' iso_requests=' . (db_has_table('iso_requests') ? '✓' : '✗'));

if ($missingTables) {
    e2e_out();
    e2e_out('جداول لازم ساخته نشدند؛ اجرای ادامه بی‌فایده است.');
    exit(1);
}

foreach ($requiredTables as $t) {
    try {
        $c = (int)(DB::fetch("SELECT COUNT(*) AS c FROM {$t}")['c'] ?? 0);
        e2e_info("تعداد ردیف {$t}: {$c}");
    } catch (Throwable $e) {}
}

// داده مرجع: یک دسته با آگهی فعال و یک آگهی نمونه
$catRow = null;
$lstRow = null;
try {
    $catRow = DB::fetch(
        'SELECT l.category_id AS cid, c.name AS cname, c.parent_id AS pid, COUNT(*) AS c
         FROM listings l JOIN categories c ON c.id = l.category_id
         WHERE ' . listing_public_sql('l') . ' AND l.category_id IS NOT NULL
         GROUP BY l.category_id ORDER BY c DESC LIMIT 1'
    );
    if ($catRow && (int)$catRow['cid'] > 0) {
        $lstRow = DB::fetch(
            'SELECT l.id, l.title, l.brand_id, l.model_id FROM listings l
             WHERE ' . listing_public_sql('l') . ' AND l.category_id = ?
             ORDER BY l.created_at DESC LIMIT 1',
            [(int)$catRow['cid']]
        );
    }
} catch (Throwable $e) {}

$sampleCid = $catRow ? (int)$catRow['cid'] : 0;
$sampleCat = $catRow ? (string)$catRow['cname'] : '';
$sampleLid = $lstRow ? (int)$lstRow['id'] : 0;
$sampleBid = $lstRow ? (int)($lstRow['brand_id'] ?? 0) : 0;
$sampleMid = $lstRow ? (int)($lstRow['model_id'] ?? 0) : 0;

if ($sampleCid > 0) {
    e2e_info("دسته نمونه: #{$sampleCid} «{$sampleCat}» ({$catRow['c']} آگهی) | آگهی نمونه: #{$sampleLid}");
} else {
    e2e_check('یافتن دسته/آگهی نمونه', false, 'هیچ آگهی فعالی برای تست توصیه/ردیابی یافت نشد', true);
}

// ───────────────────────────── حالت DRY-RUN ─────────────────────────────

if (!$apply) {
    e2e_head('DRY-RUN) اجرای موتورهای خواندنی روی فعال‌ترین کاربر واقعی');

    $act = DB::fetch(
        'SELECT user_id, COUNT(*) AS c FROM user_events
         WHERE user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
         GROUP BY user_id ORDER BY c DESC LIMIT 1'
    );

    if (!$act) {
        e2e_check('یافتن کاربر دارای رفتار', false, 'هیچ رخداد رفتاری با user_id در ۹۰ روز اخیر نیست', true);
        e2e_info('برای داده واقعی، ردیابی سمت کلاینت باید فعال باشد و کرون پروفایل اجرا شود.');
    } else {
        $activeUid = (int)$act['user_id'];
        e2e_info("کاربر نمونه: #{$activeUid} با {$act['c']} رخداد در ۹۰ روز");

        $t = microtime(true);
        $maps = recommend_interest_maps($activeUid);
        $wTopCat = $maps['category'] ? array_key_first($maps['category']) : 0;
        e2e_check('نقشه علایق (user_interest_weights)', (bool)($maps['category'] || $maps['brand'] || $maps['model'] || $maps['keyword']),
            'دسته=' . count($maps['category']) . ' برند=' . count($maps['brand']) . ' مدل=' . count($maps['model']) . ' کلیدواژه=' . count($maps['keyword'])
            . ' | دسته برتر=' . ($wTopCat ?: '—') . ' (' . e2e_ms($t) . ')', true);

        $t = microtime(true);
        $recs = recommend_for_user($activeUid, 6);
        $recReason = $recs ? (string)($recs[0]['recommend_reason'] ?? '') : '';
        e2e_check('موتور توصیه (recommend_for_user)', $recs ? true : false,
            count($recs) . ' آیتم | نمونه دلیل: ' . ($recReason ?: '—') . ' (' . e2e_ms($t) . ')', true);

        $n = nudge_analyze_user($activeUid);
        e2e_info('نتیجه ترغیب: ' . (!empty($n['show']) ? "نمایش ({$n['kind']})" : 'بدون نمایش'));

        $sig = learning_recommendation_signals($activeUid);
        e2e_info('سیگنال یادگیری: category=' . count($sig['category']) . ' brand=' . count($sig['brand']) . ' model=' . count($sig['model']));

        $needs = need_discovery_recent($activeUid, 5);
        e2e_info('نیازهای فعال کاربر: ' . count($needs));
    }

    if ($sampleCid > 0) {
        e2e_head('DRY-RUN) عملکرد کوئری نامزدها (EXPLAIN)');
        try {
            $sql = 'SELECT l.id FROM listings l JOIN users u ON u.id = l.user_id JOIN categories c ON c.id = l.category_id
                    WHERE ' . listing_public_sql('l') . ' AND (c.id = ? OR c.parent_id = ?)
                    ORDER BY (l.featured_until > NOW()) DESC, (l.bump_until > NOW()) DESC, l.created_at DESC LIMIT 60';
            $ex = DB::fetchAll('EXPLAIN ' . $sql, [$sampleCid, $sampleCid]);
            foreach ($ex as $row) {
                e2e_info('table=' . ($row['table'] ?? '?') . ' type=' . ($row['type'] ?? '?')
                    . ' key=' . ($row['key'] ?? 'NULL') . ' rows=' . ($row['rows'] ?? '?')
                    . ' extra=' . ($row['Extra'] ?? ''));
            }
        } catch (Throwable $e) {
            e2e_info('EXPLAIN ناموفق: ' . $e->getMessage());
        }
    }

    e2e_out();
    e2e_out('برای اجرای کامل چرخه (نوشتن + پاکسازی خودکار): php tools/behavior-e2e-audit.php --apply');
    e2e_out();
    e2e_out(sprintf('خلاصه DRY-RUN → PASS=%d  FAIL=%d  WARN=%d',
        $GLOBALS['e2e_pass'], $GLOBALS['e2e_fail'], $GLOBALS['e2e_warn']));
    exit($GLOBALS['e2e_fail'] > 0 ? 1 : 0);
}

// ───────────────────────────── ساخت کاربر آزمایشی ─────────────────────────────

e2e_head('آماده‌سازی) ساخت کاربر آزمایشی موقت');

$uid = 0;
try {
    $email = 'e2e-' . bin2hex(random_bytes(4)) . '@example.test';
    $phone = '+989' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $uid = (int)DB::insert('users', db_filter_row('users', [
        'name'               => 'E2E Audit User',
        'email'              => $email,
        'phone'              => $phone,
        'password_hash'      => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'role'               => 'user',
        'is_active'          => 1,
        'credit_balance'     => 0,
        'verification_level' => 1,
    ]));
    if ($uid > 0) $tmpUserIds[] = $uid;
} catch (Throwable $e) {
    e2e_check('ساخت کاربر آزمایشی', false, $e->getMessage());
}
e2e_check('ساخت کاربر آزمایشی', $uid > 0, $uid > 0 ? "user_id={$uid}" : 'ناموفق');
if ($uid <= 0) {
    e2e_out('ادامه تست بدون کاربر ممکن نیست.');
    exit(1);
}

// کاربر سرد (بدون هیچ رخداد) برای تست cold-start توصیه
$coldUid = 0;
try {
    $coldUid = (int)DB::insert('users', db_filter_row('users', [
        'name'               => 'E2E Cold User',
        'email'              => 'e2e-cold-' . bin2hex(random_bytes(4)) . '@example.test',
        'phone'              => '+988' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
        'password_hash'      => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'role'               => 'user',
        'is_active'          => 1,
        'credit_balance'     => 0,
        'verification_level' => 1,
    ]));
    if ($coldUid > 0) $tmpUserIds[] = $coldUid;
} catch (Throwable $e) {}
e2e_info('کاربر سرد (cold-start): #' . ($coldUid ?: '—'));

// ───────────────────────────── بخش ۱: ردیابی ─────────────────────────────

e2e_head('۱) ردیابی رفتار (user_events)');

$anon = bin2hex(random_bytes(16));
$tmpAnonIds[] = $anon;

// رخدادهای ناشناس (قبل از ورود) — سپس اتصال به حساب
$anonEvents = [
    ['event_type' => 'view_page'],
    ['event_type' => 'view_listing', 'object_type' => 'listing', 'object_id' => $sampleLid, 'category_id' => $sampleCid],
    ['event_type' => 'view_category', 'category_id' => $sampleCid],
    ['event_type' => 'search', 'keywords' => $sampleCat],
];
$nAnon = behavior_log_events($anonEvents, null, $anon);
e2e_check('ثبت رخداد ناشناس', $nAnon === count($anonEvents), "ثبت‌شده: {$nAnon}");

$linked = behavior_link_anon($anon, $uid);
$stillAnon = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM user_events WHERE anon_id = ? AND user_id IS NULL',
    [$anon]
)['c'] ?? 0);
e2e_check('اتصال مهمان به حساب پس از ورود', $stillAnon === 0, "به‌روزشده: {$linked} | باقی‌مانده ناشناس: {$stillAnon}");

// رخدادهای کاربر لاگین‌شده (شامل ذخیره و کلیک)
$userEvents = [
    ['event_type' => 'click_listing', 'object_type' => 'listing', 'object_id' => $sampleLid, 'category_id' => $sampleCid],
    ['event_type' => 'view_listing', 'object_type' => 'listing', 'object_id' => $sampleLid, 'category_id' => $sampleCid, 'brand_id' => $sampleBid, 'model_id' => $sampleMid],
    ['event_type' => 'save_listing', 'object_type' => 'listing', 'object_id' => $sampleLid, 'category_id' => $sampleCid, 'brand_id' => $sampleBid],
    ['event_type' => 'view_listing', 'object_type' => 'listing', 'object_id' => $sampleLid, 'category_id' => $sampleCid],
];
$nUser = behavior_log_events($userEvents, $uid, $anon);
e2e_check('ثبت رخداد کاربر لاگین‌شده', $nUser === count($userEvents), "ثبت‌شده: {$nUser}");

$totalEvents = (int)(DB::fetch('SELECT COUNT(*) AS c FROM user_events WHERE user_id = ?', [$uid])['c'] ?? 0);
e2e_check('جمع رخدادهای کاربر آزمایشی', $totalEvents >= count($anonEvents) + count($userEvents),
    "کل: {$totalEvents} (انتظار ≥" . (count($anonEvents) + count($userEvents)) . ')');

// اعتبارسنجی: رخداد نامعتبر نباید ثبت شود
$bad = behavior_log_events([['event_type' => 'not_a_real_event']], $uid, $anon);
e2e_check('رد رخداد نامعتبر', $bad === 0, "ثبت‌شده: {$bad}");

// ───────────────────────────── بخش ۲: پروفایل ─────────────────────────────

e2e_head('۲) ساخت پروفایل علایق (user_interest_weights)');

$t = microtime(true);
$build = profile_build_user($uid);
$dur  = e2e_ms($t);
e2e_check('اجرای profile_build_user', !empty($build['ok']), "وزن‌ها={$build['weights']} نیازها={$build['needs']} ({$dur})");

$topCat = DB::fetch(
    'SELECT dimension, ref_id, ref_key, weight, confidence, signals FROM user_interest_weights
     WHERE user_id = ? AND dimension = "category" ORDER BY weight DESC LIMIT 1',
    [$uid]
);
$topCatOk = $topCat && (int)$topCat['ref_id'] === $sampleCid;
e2e_check('دسته برتر مطابق رفتار شبیه‌سازی‌شده', (bool)$topCatOk,
    $topCat ? "برتر=#{$topCat['ref_id']} (انتظار #{$sampleCid}) weight={$topCat['weight']} conf={$topCat['confidence']}" : 'هیچ وزنی ثبت نشد');

$wCount = (int)(DB::fetch('SELECT COUNT(*) AS c FROM user_interest_weights WHERE user_id = ?', [$uid])['c'] ?? 0);
e2e_check('وجود وزن‌های علاقه', $wCount > 0, "تعداد ردیف وزن: {$wCount}");

// ───────────────────────────── بخش ۳: توصیه ─────────────────────────────

e2e_head('۳) موتور توصیه (recommend)');

$t = microtime(true);
$recs = recommend_for_user($uid, 6);
$dur  = e2e_ms($t);
e2e_check('توصیه برای کاربر گرم', count($recs) > 0, count($recs) . " آیتم ({$dur})");

if ($recs) {
    $allScored = true;
    $allReason = true;
    foreach ($recs as $r) {
        if (!isset($r['recommend_score']) || !is_numeric($r['recommend_score'])) $allScored = false;
        if (empty($r['recommend_reason'])) $allReason = false;
    }
    e2e_check('همه آیتم‌ها امتیاز دارند', $allScored);
    e2e_check('همه آیتم‌ها دلیل دارند', $allReason, 'نمونه: ' . (string)($recs[0]['recommend_reason'] ?? '—'));

    $sortedOk = true;
    for ($i = 1; $i < count($recs); $i++) {
        if ((float)$recs[$i - 1]['recommend_score'] < (float)$recs[$i]['recommend_score']) { $sortedOk = false; break; }
    }
    e2e_check('مرتب‌سازی نزولی بر اساس امتیاز', $sortedOk);

    $containsSeen = false;
    foreach ($recs as $r) {
        if ($sampleLid > 0 && (int)$r['id'] === $sampleLid) { $containsSeen = true; break; }
    }
    e2e_check('حذف آگهی‌های اخیراً دیده‌شده از توصیه', $sampleLid > 0 ? !$containsSeen : true,
        $sampleLid > 0 ? 'آگهی #' . $sampleLid . ' نباید در توصیه باشد' : 'آگهی نمونه موجود نیست', true);
}

if ($coldUid > 0) {
    $t = microtime(true);
    $coldRecs = recommend_for_user($coldUid, 6);
    e2e_check('cold-start: عقب‌نشینی به آگهی‌های تازه', count($coldRecs) > 0,
        count($coldRecs) . ' آیتم برای کاربر بدون رفتار (' . e2e_ms($t) . ')');
}

// ───────────────────────────── بخش ۴: ترغیب ─────────────────────────────

e2e_head('۴) ترغیب هوشمند (nudge)');

$nudge = nudge_analyze_user($uid);
$nudgeOk = !empty($nudge['show']) && ($nudge['kind'] ?? '') === 'start_listing';
e2e_check('تشخیص کاوشگر منفعل (بدون آگهی، دارای رفتار)', $nudgeOk,
    $nudgeOk ? "kind={$nudge['kind']} | پیام: " . mb_substr((string)$nudge['message'], 0, 50) . '…'
             : 'نمایش: ' . (!empty($nudge['show']) ? $nudge['kind'] : 'خیر'));

// ───────────────────────────── بخش ۵: کشف نیاز ─────────────────────────────

e2e_head('۵) کشف نیاز از زبان طبیعی (need discovery)');

$needText = 'دنبال لپ‌تاپ گیمینگ دست‌دوم در تهران تا ۳۰ میلیون';
$t = microtime(true);
$disc = need_discovery_discover($uid, $needText, null, false);
$dur  = e2e_ms($t);
e2e_check('کشف نیاز', !empty($disc['ok']) && (int)$disc['need_id'] > 0,
    "need_id={$disc['need_id']} منبع=" . ($disc['source'] ?? '?') . " نتایج={$disc['total']} ({$dur})");
if (!empty($disc['filters'])) {
    e2e_info('فیلترهای استخراج‌شده: ' . json_encode($disc['filters'], JSON_UNESCAPED_UNICODE));
}

// ضدتکرار: ثبت مجدد همان متن در همان روز باید همان شناسه را برگرداند
$dupId = need_discovery_record($uid, $disc['filters'] ?? [], $needText, 'chat');
e2e_check('ضدتکرار نیاز در ۲۴ ساعت', (int)$dupId === (int)$disc['need_id'],
    "need_id اول={$disc['need_id']} دوم={$dupId}");

$recentNeeds = need_discovery_recent($uid, 5);
e2e_check('نمایش نیازهای فعال کاربر', count($recentNeeds) > 0, count($recentNeeds) . ' نیاز فعال');

// ───────────────────────────── بخش ۶: یادگیری مستمر ─────────────────────────────

e2e_head('۶) یادگیری مستمر (learning)');

// ثبت نمایش توصیه‌ها و سپس کلیک روی یکی از آن‌ها
$logged = recommend_log_impressions($uid, $recs, 'e2e-audit');
e2e_check('ثبت نمایش توصیه‌ها (recommendation_log)', $logged > 0, "{$logged} ردیف");

if ($recs) {
    $clickedListing = (int)$recs[0]['id'];
    behavior_log_events([[
        'event_type'  => 'click_listing',
        'object_type' => 'listing',
        'object_id'   => $clickedListing,
        'category_id' => (int)($recs[0]['category_id'] ?? 0),
    ]], $uid, $anon);

    $marked = learning_mark_recommendation_clicks(14);
    e2e_check('علامت‌گذاری کلیک روی توصیه', $marked >= 1, "به‌روزشده: {$marked}");

    $clickedRow = DB::fetch(
        'SELECT clicked FROM recommendation_log WHERE user_id = ? AND listing_id = ? ORDER BY id DESC LIMIT 1',
        [$uid, $clickedListing]
    );
    e2e_check('ثبت بازخورد مثبت روی آگهی کلیک‌شده', (int)($clickedRow['clicked'] ?? 0) === 1,
        'آگهی #' . $clickedListing);

    $sig = learning_recommendation_signals($uid);
    e2e_check('بازگشت سیگنال یادگیری به علایق',
        (bool)($sig['category'] || $sig['brand'] || $sig['model']),
        'دسته=' . count($sig['category']) . ' برند=' . count($sig['brand']) . ' مدل=' . count($sig['model']));
}

// کهنه‌سازی نیاز قدیمی
$oldNeedId = (int)DB::insert('user_needs', [
    'user_id'    => $uid,
    'source'     => 'event',
    'need_text'  => 'E2E stale need',
    'confidence' => 10,
    'status'     => 'active',
    'created_at' => date('Y-m-d H:i:s', time() - 100 * 86400),
    'updated_at' => date('Y-m-d H:i:s', time() - 100 * 86400),
]);
$stale = learning_mark_stale_needs(90);
$staleRow = DB::fetch('SELECT status FROM user_needs WHERE id = ? LIMIT 1', [$oldNeedId]);
e2e_check('کهنه‌سازی نیازهای قدیمی', ($staleRow['status'] ?? '') === 'stale',
    "تغییر‌یافته: {$stale} | وضعیت نیاز آزمایشی: " . ($staleRow['status'] ?? '?'));

// ───────────────────────────── عملکرد (EXPLAIN) ─────────────────────────────

e2e_head('عملکرد) بررسی استفاده از ایندکس‌ها');

try {
    $ex = DB::fetchAll(
        'EXPLAIN SELECT event_type, category_id, brand_id, model_id, keywords, created_at
         FROM user_events WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
         ORDER BY id DESC LIMIT 5000',
        [$uid]
    );
    foreach ($ex as $row) {
        e2e_info('user_events: type=' . ($row['type'] ?? '?') . ' key=' . ($row['key'] ?? 'NULL')
            . ' rows=' . ($row['rows'] ?? '?') . ' extra=' . ($row['Extra'] ?? ''));
    }
} catch (Throwable $e) {}

// ───────────────────────────── پاکسازی ─────────────────────────────

e2e_head('پاکسازی) حذف داده آزمایشی');

if ($keep) {
    e2e_out('  [KEEP] پاکسازی غیرفعال است. برای حذف دستی:');
    e2e_out('  DELETE FROM user_events WHERE user_id IN (' . implode(',', $tmpUserIds) . ');');
    e2e_out('  DELETE FROM user_interest_weights WHERE user_id IN (' . implode(',', $tmpUserIds) . ');');
    e2e_out('  DELETE FROM user_needs WHERE user_id IN (' . implode(',', $tmpUserIds) . ');');
    e2e_out('  DELETE FROM recommendation_log WHERE user_id IN (' . implode(',', $tmpUserIds) . ');');
    e2e_out('  DELETE FROM users WHERE id IN (' . implode(',', $tmpUserIds) . ');');
} else {
    e2e_cleanup($tmpUserIds, $tmpAnonIds);
    $tmpUserIds = [];
    $tmpAnonIds = [];
    e2e_out('  [DONE] داده آزمایشی پاک شد.');
}

// ───────────────────────────── خلاصه ─────────────────────────────

e2e_out();
e2e_out(str_repeat('═', 64));
e2e_out(sprintf('خلاصه نهایی → PASS=%d  FAIL=%d  WARN=%d',
    $GLOBALS['e2e_pass'], $GLOBALS['e2e_fail'], $GLOBALS['e2e_warn']));
e2e_out(str_repeat('═', 64));

exit($GLOBALS['e2e_fail'] > 0 ? 1 : 0);
