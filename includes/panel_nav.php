<?php
/** Shared panel navigation — sidebar & header dropdown stay in sync. */

function panel_nav_promote_href(int $userId): string {
    $url = APP_URL;
    $row = DB::fetch(
        'SELECT id FROM listings WHERE user_id = ? AND status = "active" ORDER BY updated_at DESC, id DESC LIMIT 1',
        [$userId]
    );
    return $row
        ? $url . '/listings/promote.php?id=' . (int)$row['id']
        : $url . '/listings/my';
}

function panel_nav_counts(int $userId): array {
    return [
        'offers' => (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status = "pending"',
            [$userId]
        )['c'] ?? 0),
    ];
}

/**
 * @return array<string, array{0:string,1:string,2:string,3:int}>
 */
function panel_nav_items(array $user): array {
    $url     = APP_URL;
    $uid     = (int)$user['id'];
    $counts  = panel_nav_counts($uid);
    $promote = panel_nav_promote_href($uid);

    $items = [
        'dashboard'    => [$url . '/dashboard',           'داشبورد',       'bi-speedometer2',    0],
        'my'           => [$url . '/listings/my',          'آگهی‌های من',   'bi-grid',            0],
        'promote'      => [$promote,                       'ارتقای آگهی',   'bi-rocket-takeoff',  0],
        'saved'        => [$url . '/listings/saved',        'علاقه‌مندی‌ها', 'bi-heart',           0],
        'trades'       => [$url . '/trades',               'اتاق معامله',   'bi-shield-lock',     $counts['offers']],
        'wallet'       => [$url . '/wallet',               'کیف پول',       'bi-wallet2',         0],
        'subscription' => [$url . '/subscription',        'اشتراک',        'bi-gem',             0],
        'settings'     => [$url . '/profile/edit',         'احراز هویت',    'bi-shield-check',    0],
        'support'      => [$url . '/support',              'پشتیبانی',      'bi-headset',         0],
        'profile'      => [$url . '/profile',              'پروفایل',       'bi-person',          0],
    ];

    if (is_store_seller($user)) {
        $store = [$url . '/store', 'پنل فروشگاه', 'bi-shop', 0];
        $items = array_slice($items, 0, 1, true)
            + ['store' => $store]
            + array_slice($items, 1, null, true);
    }

    return $items;
}
