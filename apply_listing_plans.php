<?php
define('SKIP_SESSION', true);
define('CLI_MODE', true);

require_once __DIR__ . '/includes/config.php';

/**
 * پلن آگهی‌های موجود را از ads-import.json اعمال می‌کند.
 * آگهی جدید نمی‌سازد — فقط listing_promotions + ستون‌های until.
 *
 *   php apply_listing_plans.php [ads.json]
 */

$adsFile = $argv[1] ?? __DIR__ . '/ads-import.json';

if (!file_exists($adsFile)) {
    fwrite(STDERR, "فایل آگهی پیدا نشد: $adsFile\n");
    exit(1);
}

$adsRaw = json_decode(file_get_contents($adsFile), true);
if (!is_array($adsRaw)) {
    fwrite(STDERR, "ساختار ads-import.json نامعتبر است\n");
    exit(1);
}

$validPromos = ['boost', 'featured', 'vip', 'targeted', 'ai', 'gold'];
$planNames = [
    'boost' => 'بازدید بیشتر',
    'featured' => 'داغ',
    'vip' => 'ویژه',
    'targeted' => 'هدفمند',
    'ai' => 'هوشمند',
    'gold' => 'طلایی',
];

$planned = [];
foreach ($adsRaw as $ad) {
    $plan = $ad['promotion_plan'] ?? $ad['subscription_plan'] ?? null;
    if (!$plan) {
        continue;
    }
    $title = trim((string)($ad['title'] ?? ''));
    if ($title === '') {
        continue;
    }
    $planned[] = $ad;
}

echo "اعمال پلن روی آگهی‌های موجود — " . count($planned) . " مورد در JSON\n\n";

$ok = 0;
$skip = 0;
$fail = 0;

$pdo = DB::pdo();
$pdo->beginTransaction();

try {
    foreach ($planned as $ad) {
        $title = trim($ad['title']);
        $plan = $ad['promotion_plan'] ?? $ad['subscription_plan'];
        if (!in_array($plan, $validPromos, true)) {
            echo "  رد: پلن نامعتبر {$plan} — {$title}\n";
            $skip++;
            continue;
        }

        $hours = (int)($ad['promotion_hours'] ?? (30 * 24));
        if ($hours < 1) {
            $hours = 24;
        }
        if ($hours > 365 * 24) {
            $hours = 365 * 24;
        }

        $matches = DB::fetchAll(
            'SELECT id, user_id, title FROM listings WHERE title = ? ORDER BY id ASC',
            [$title]
        );

        if (!$matches) {
            echo "  پیدا نشد: {$title}\n";
            $skip++;
            continue;
        }

        if (count($matches) > 1) {
            echo "  چند رکورد با همین عنوان (" . count($matches) . "): {$title} — همه به‌روز می‌شوند\n";
        }

        $startsAt = date('Y-m-d H:i:s');
        $endsAt = date('Y-m-d H:i:s', time() + ($hours * 3600));

        foreach ($matches as $listing) {
            $listingId = (int)$listing['id'];
            $userId = (int)$listing['user_id'];

            DB::insert('listing_promotions', [
                'listing_id'  => $listingId,
                'user_id'     => $userId,
                'plan'        => $plan,
                'starts_at'   => $startsAt,
                'ends_at'     => $endsAt,
                'amount_paid' => 0,
            ]);

            $promoUpdate = [];
            switch ($plan) {
                case 'boost':
                    $promoUpdate['bump_until'] = $endsAt;
                    break;
                case 'featured':
                    $promoUpdate['featured_until'] = $endsAt;
                    $promoUpdate['is_featured'] = 1;
                    break;
                case 'vip':
                    $promoUpdate['featured_until'] = $endsAt;
                    $promoUpdate['vip_until'] = $endsAt;
                    $promoUpdate['is_featured'] = 1;
                    break;
                case 'targeted':
                    $promoUpdate['targeted_until'] = $endsAt;
                    break;
                case 'ai':
                    $promoUpdate['ai_promo_until'] = $endsAt;
                    break;
                case 'gold':
                    $promoUpdate['bump_until'] = $endsAt;
                    $promoUpdate['featured_until'] = $endsAt;
                    $promoUpdate['vip_until'] = $endsAt;
                    $promoUpdate['targeted_until'] = $endsAt;
                    $promoUpdate['ai_promo_until'] = $endsAt;
                    $promoUpdate['is_featured'] = 1;
                    break;
            }

            if ($promoUpdate) {
                $promoUpdate = db_filter_row('listings', $promoUpdate);
                if ($promoUpdate) {
                    DB::update('listings', $promoUpdate, 'id = ?', [$listingId]);
                }
            }

            echo "  OK #{$listingId} {$planNames[$plan]} {$hours}h — {$title}\n";
            $ok++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "خطا: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nتمام شد. اعمال‌شده: {$ok} | رد/پیدانشد: {$skip} | خطا: {$fail}\n";
echo "آگهی جدید ساخته نشد.\n";
