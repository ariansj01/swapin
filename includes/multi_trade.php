<?php
/**
 * مبادله چندنفره (Multi-party swap)
 * ------------------------------------------------------------------
 * این ماژول کاملاً مستقل از اتاق امن دو‌نفره است و هیچ تغییری در منطق
 * معامله دو‌نفره ایجاد نمی‌کند. یک «اتاق گروهی» با ۳ تا ۵ عضو می‌سازد که
 * همان فازهای معامله (کارمزد، ماب‌تفاوت، قرارداد، ارسال، تحویل، امتیاز) را
 * در قالب مسیرهای جفتی اجرا می‌کند و اعضای گروه وضعیت کل اتاق را می‌بینند.
 */  

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/v2.php';

const MT_ROOM_MIN = 3;
const MT_ROOM_MAX = 5;

const MT_STEP_FEE       = 'fee';
const MT_STEP_DIFF      = 'diff';
const MT_STEP_CONTRACT  = 'contract';
const MT_STEP_SHIPPING  = 'shipping';
const MT_STEP_DELIVERED = 'delivered';
const MT_STEP_RECEIVED  = 'received';
const MT_STEP_RATING    = 'rating';
const MT_STEP_DONE      = 'done';

function multi_trade_room_url(int $roomId): string
{
    return APP_URL . '/trades/group?id=' . $roomId;
}

function multi_trade_room_status_label(string $status): string
{
    return [
        'awaiting_seed' => 'در انتظار تأیید طرف مقابل',
        'forming'       => 'در حال تشکیل',
        'locked'        => 'بسته شده — انتخاب طرف معامله',
        'active'        => 'در جریان',
        'completed'     => 'تکمیل شده',
        'cancelled'     => 'لغو شده',
    ][$status] ?? $status;
}

function multi_trade_step_label(string $step): string
{
    return [
        MT_STEP_FEE       => 'پرداخت کارمزد',
        MT_STEP_DIFF      => 'تسویه ماب‌تفاوت',
        MT_STEP_CONTRACT  => 'امضای قرارداد',
        MT_STEP_SHIPPING  => 'تعیین زمان/روش ارسال',
        MT_STEP_DELIVERED => 'ارسال کالا',
        MT_STEP_RECEIVED  => 'تحویل و تأیید دریافت',
        MT_STEP_RATING    => 'ثبت امتیاز',
        MT_STEP_DONE      => 'تکمیل',
    ][$step] ?? $step;
}

function multi_trade_notify(int $userId, string $title, string $body, string $link): void
{
    if (!db_has_table('notifications')) {
        return;
    }
    DB::insert('notifications', [
        'user_id' => $userId,
        'type'    => 'multi_trade',
        'title'   => $title,
        'body'    => $body,
        'link'    => $link,
        'is_read' => 0,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ساخت جداول (خودگردان؛ مستقل از میگریشن‌های config.php)
// ══════════════════════════════════════════════════════════════════════════════
function multi_trade_ensure_tables(): bool
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }
    $done = false;
    try {
        if (!db_has_table('multi_trade_rooms')) {
            DB::query("
                CREATE TABLE `multi_trade_rooms` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `seed_trade_id` INT UNSIGNED DEFAULT NULL,
                    `seed_offer_id` INT UNSIGNED DEFAULT NULL,
                    `initiated_by` INT UNSIGNED NOT NULL,
                    `status` ENUM('awaiting_seed','forming','locked','active','completed','cancelled')
                        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'awaiting_seed',
                    `members_min` TINYINT UNSIGNED NOT NULL DEFAULT 3,
                    `members_max` TINYINT UNSIGNED NOT NULL DEFAULT 5,
                    `locked_at` DATETIME DEFAULT NULL,
                    `diff_settled` TINYINT(1) NOT NULL DEFAULT 0,
                    `completed_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_mtr_seed_trade` (`seed_trade_id`),
                    KEY `idx_mtr_seed_offer` (`seed_offer_id`),
                    KEY `idx_mtr_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        if (!db_has_table('multi_trade_members')) {
            DB::query("
                CREATE TABLE `multi_trade_members` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `room_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `listing_id` INT UNSIGNED DEFAULT NULL,
                    `status` ENUM('invited','active','declined','left')
                        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
                    `is_seed` TINYINT(1) NOT NULL DEFAULT 0,
                    `join_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `close_vote` TINYINT(1) NOT NULL DEFAULT 0,
                    `close_voted_at` DATETIME DEFAULT NULL,
                    `target_member_id` INT UNSIGNED DEFAULT NULL,
                    `fee_amount` DECIMAL(15,0) NOT NULL DEFAULT 0,
                    `fee_paid` TINYINT(1) NOT NULL DEFAULT 0,
                    `fee_paid_at` DATETIME DEFAULT NULL,
                    `diff_amount` DECIMAL(15,0) NOT NULL DEFAULT 0,
                    `diff_paid` TINYINT(1) NOT NULL DEFAULT 0,
                    `diff_settled` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_mtm_room_user` (`room_id`,`user_id`),
                    KEY `idx_mtm_user` (`user_id`),
                    KEY `idx_mtm_room` (`room_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        if (!db_has_table('multi_trade_join_requests')) {
            DB::query("
                CREATE TABLE `multi_trade_join_requests` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `room_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `listing_id` INT UNSIGNED DEFAULT NULL,
                    `status` ENUM('pending','approved','rejected','cancelled')
                        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_mtjr_room` (`room_id`,`status`),
                    KEY `idx_mtjr_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        if (!db_has_table('multi_trade_join_votes')) {
            DB::query("
                CREATE TABLE `multi_trade_join_votes` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `request_id` INT UNSIGNED NOT NULL,
                    `member_id` INT UNSIGNED NOT NULL,
                    `voter_user_id` INT UNSIGNED NOT NULL,
                    `decision` ENUM('approve','reject') COLLATE utf8mb4_unicode_ci NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_mtjv` (`request_id`,`member_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        if (!db_has_table('multi_trade_tracks')) {
            DB::query("
                CREATE TABLE `multi_trade_tracks` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `room_id` INT UNSIGNED NOT NULL,
                    `deliverer_member_id` INT UNSIGNED NOT NULL,
                    `receiver_member_id` INT UNSIGNED NOT NULL,
                    `deliverer_signed` TINYINT(1) NOT NULL DEFAULT 0,
                    `receiver_signed` TINYINT(1) NOT NULL DEFAULT 0,
                    `shipping_method` ENUM('in_person','post','tipax','courier')
                        COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `shipping_date` DATE DEFAULT NULL,
                    `shipping_time` VARCHAR(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `deliverer_shipped` TINYINT(1) NOT NULL DEFAULT 0,
                    `shipped_at` DATETIME DEFAULT NULL,
                    `tracking_code` VARCHAR(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `receiver_received` TINYINT(1) NOT NULL DEFAULT 0,
                    `received_at` DATETIME DEFAULT NULL,
                    `deliverer_rated` TINYINT(1) NOT NULL DEFAULT 0,
                    `receiver_rated` TINYINT(1) NOT NULL DEFAULT 0,
                    `completed_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_mtt_deliverer` (`room_id`,`deliverer_member_id`),
                    UNIQUE KEY `uniq_mtt_receiver` (`room_id`,`receiver_member_id`),
                    KEY `idx_mtt_room` (`room_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        if (!db_has_table('multi_trade_messages')) {
            DB::query("
                CREATE TABLE `multi_trade_messages` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `room_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED DEFAULT NULL,
                    `type` ENUM('text','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
                    `body` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_mtmsg_room` (`room_id`,`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        if (db_has_table('trade_offers') && !db_has_column('trade_offers', 'group_requested_by')) {
            DB::query("ALTER TABLE `trade_offers` ADD COLUMN `group_requested_by` INT UNSIGNED DEFAULT NULL COMMENT 'کاربری که درخواست مبادله چندنفره داده'");
        }
        $done = true;
    } catch (Throwable $e) {
        swapin_debug_log('multi-trade-ensure-tables', ['msg' => $e->getMessage()]);
        $done = false;
    }
    return $done;
}

// ══════════════════════════════════════════════════════════════════════════════
// خواندن اتاق و اعضا
// ══════════════════════════════════════════════════════════════════════════════
function multi_trade_room(int $roomId): ?array
{
    return DB::fetch('SELECT * FROM multi_trade_rooms WHERE id = ?', [$roomId]) ?: null;
}

function multi_trade_room_for_seed_trade(int $tradeId): ?array
{
    return DB::fetch(
        'SELECT * FROM multi_trade_rooms WHERE seed_trade_id = ? AND status <> "cancelled" ORDER BY id DESC LIMIT 1',
        [$tradeId]
    ) ?: null;
}

function multi_trade_room_for_seed_offer(int $offerId): ?array
{
    return DB::fetch(
        'SELECT * FROM multi_trade_rooms WHERE seed_offer_id = ? AND status <> "cancelled" ORDER BY id DESC LIMIT 1',
        [$offerId]
    ) ?: null;
}

/**
 * @return array<int,array<string,mixed>> اعضای اتاق (پیش‌فرض فقط فعال‌ها)
 */
function multi_trade_members(int $roomId, bool $activeOnly = true): array
{
    $sql = 'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar, u.city AS user_city, u.rating AS user_rating,
                   l.title AS listing_title, l.estimated_value AS listing_value, l.category_id AS listing_category_id,
                   (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS listing_thumb
            FROM multi_trade_members m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN listings l ON l.id = m.listing_id
            WHERE m.room_id = ?';
    if ($activeOnly) {
        $sql .= " AND m.status = 'active'";
    }
    $sql .= ' ORDER BY m.join_order ASC, m.id ASC';
    return DB::fetchAll($sql, [$roomId]);
}

function multi_trade_member(int $roomId, int $userId): ?array
{
    return DB::fetch(
        'SELECT m.*, u.name AS user_name, u.avatar AS user_avatar, u.rating AS user_rating,
                l.title AS listing_title, l.estimated_value AS listing_value,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS listing_thumb
         FROM multi_trade_members m
         JOIN users u ON u.id = m.user_id
         LEFT JOIN listings l ON l.id = m.listing_id
         WHERE m.room_id = ? AND m.user_id = ? LIMIT 1',
        [$roomId, $userId]
    ) ?: null;
}

function multi_trade_members_map(int $roomId, bool $activeOnly = true): array
{
    $map = [];
    foreach (multi_trade_members($roomId, $activeOnly) as $m) {
        $map[(int)$m['id']] = $m;
    }
    return $map;
}

function multi_trade_active_count(int $roomId): int
{
    $row = DB::fetch("SELECT COUNT(*) AS c FROM multi_trade_members WHERE room_id = ? AND status = 'active'", [$roomId]);
    return (int)($row['c'] ?? 0);
}

/**
 * اتاق باز (قابل عضویت) که کاربر در آن عضو فعال است.
 */
function multi_trade_open_room_for_user(int $userId): ?array
{
    return DB::fetch(
        "SELECT r.* FROM multi_trade_rooms r
         JOIN multi_trade_members m ON m.room_id = r.id
         WHERE m.user_id = ? AND m.status = 'active' AND r.status = 'forming'
         ORDER BY r.id DESC LIMIT 1",
        [$userId]
    ) ?: null;
}

/**
 * نقشه‌ی مالک‌آگهی => شناسه اتاق باز (برای نمایش بدج «مبادله چندنفره» روی کارت‌ها).
 * یک کوئری، در همان درخواست کش می‌شود تا N+1 ایجاد نشود.
 *
 * @return array<int,int>
 */
function multi_trade_open_rooms_by_owner(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!db_has_table('multi_trade_rooms') || !db_has_table('multi_trade_members')) {
        return $map;
    }
    try {
        $rows = DB::fetchAll(
            "SELECT m.user_id, r.id AS room_id
             FROM multi_trade_rooms r
             JOIN multi_trade_members m ON m.room_id = r.id AND m.status = 'active'
             WHERE r.status = 'forming'
               AND (SELECT COUNT(*) FROM multi_trade_members x WHERE x.room_id = r.id AND x.status = 'active') < r.members_max
             ORDER BY r.id ASC"
        );
        foreach ($rows as $row) {
            $map[(int)$row['user_id']] = (int)$row['room_id'];
        }
    } catch (Throwable $e) {
        swapin_debug_log('multi-trade-open-rooms-map', ['msg' => $e->getMessage()]);
    }
    return $map;
}

function multi_trade_room_for_owner(int $userId): ?int
{
    return multi_trade_open_rooms_by_owner()[$userId] ?? null;
}

/**
 * نقشه‌ی آگهی => شناسه اتاق باز (برای بدج «مبادله چندنفره» روی کارت آگهی و صفحه جزئیات).
 * فقط اتاق‌های «در حال تشکیل» که ظرفیت دارند. یک کوئری، در همان درخواست کش می‌شود.
 *
 * @return array<int,int>
 */
function multi_trade_open_rooms_by_listing(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!db_has_table('multi_trade_rooms') || !db_has_table('multi_trade_members')) {
        return $map;
    }
    try {
        $rows = DB::fetchAll(
            "SELECT m.listing_id, r.id AS room_id
             FROM multi_trade_rooms r
             JOIN multi_trade_members m ON m.room_id = r.id AND m.status = 'active'
             WHERE r.status = 'forming' AND m.listing_id IS NOT NULL
               AND (SELECT COUNT(*) FROM multi_trade_members x WHERE x.room_id = r.id AND x.status = 'active') < r.members_max
             ORDER BY r.id ASC"
        );
        foreach ($rows as $row) {
            $lid = (int)$row['listing_id'];
            if ($lid > 0 && !isset($map[$lid])) {
                $map[$lid] = (int)$row['room_id'];
            }
        }
    } catch (Throwable $e) {
        swapin_debug_log('multi-trade-open-rooms-by-listing', ['msg' => $e->getMessage()]);
    }
    return $map;
}

/**
 * اتاق‌های کاربر برای فهرست «مبادله‌های چندنفره».
 */
function multi_trade_rooms_for_user(int $userId): array
{
    if (!db_has_table('multi_trade_rooms')) {
        return [];
    }
    return DB::fetchAll(
        "SELECT r.*, m.status AS my_status,
                (SELECT COUNT(*) FROM multi_trade_members x WHERE x.room_id = r.id AND x.status = 'active') AS members_count,
                (SELECT GROUP_CONCAT(u2.name SEPARATOR '، ')
                   FROM multi_trade_members m2 JOIN users u2 ON u2.id = m2.user_id
                  WHERE m2.room_id = r.id AND m2.status = 'active') AS member_names
         FROM multi_trade_rooms r
         JOIN multi_trade_members m ON m.room_id = r.id AND m.user_id = ?
         WHERE r.status <> 'cancelled'
         ORDER BY r.id DESC",
        [$userId]
    );
}

/**
 * درخواست‌های تأییدنشده‌ی کاربر جاری در اتاق‌های دیگر (برای دعوت به تأیید).
 */
function multi_trade_pending_invites_for_user(int $userId): array
{
    if (!db_has_table('multi_trade_rooms')) {
        return [];
    }
    return DB::fetchAll(
        "SELECT r.id AS room_id, m.id AS member_id, u.name AS initiator_name
         FROM multi_trade_members m
         JOIN multi_trade_rooms r ON r.id = m.room_id
         JOIN users u ON u.id = r.initiated_by
         WHERE m.user_id = ? AND m.status = 'invited' AND r.status = 'awaiting_seed'",
        [$userId]
    );
}

// ══════════════════════════════════════════════════════════════════════════════
// تبدیل پیشنهاد/معامله دو‌نفره به اتاق گروهی
// ══════════════════════════════════════════════════════════════════════════════
/**
 * ثبت درخواست «مبادله چندنفره» روی یک پیشنهاد فعال (نفر اول پیشنهاد می‌دهد).
 */
function multi_trade_request_on_offer(int $offerId, int $userId): array
{
    if (!multi_trade_ensure_tables()) {
        return ['error' => 'سامانه مبادله چندنفره در دسترس نیست.'];
    }
    $offer = DB::fetch(
        'SELECT o.*, l.user_id AS listing_owner FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE o.id = ?',
        [$offerId]
    );
    if (!$offer) {
        return ['error' => 'پیشنهاد یافت نشد.'];
    }
    if (!in_array((string)$offer['status'], ['pending', 'negotiating', 'counter_offered'], true)) {
        return ['error' => 'این پیشنهاد دیگر فعال نیست.'];
    }
    $owner = (int)$offer['listing_owner'];
    $from  = (int)$offer['from_user_id'];
    if ($userId !== $owner && $userId !== $from) {
        return ['error' => 'دسترسی ندارید.'];
    }
    DB::query('UPDATE trade_offers SET group_requested_by = ? WHERE id = ?', [$userId, $offerId]);

    $otherId = $userId === $owner ? $from : $owner;
    multi_trade_notify(
        $otherId,
        'درخواست مبادله چندنفره',
        'طرف مقابل پیشنهاد داد این معامله به‌صورت «مبادله چندنفره» (۳ تا ۵ نفر) انجام شود.',
        APP_URL . '/trades?tab=received'
    );
    return ['ok' => true];
}

/**
 * ساخت اتاق گروهی از یک معامله دو‌نفره.
 *
 * @param bool $bothAccepted وقتی true است هر دو عضو فعال می‌شوند (تبدیل با رضایت هر دو).
 */
function multi_trade_create_from_trade(int $tradeId, int $initiatorId, bool $bothAccepted): array
{
    if (!multi_trade_ensure_tables()) {
        return ['error' => 'سامانه مبادله چندنفره در دسترس نیست.'];
    }
    $trade = DB::fetch('SELECT * FROM trades WHERE id = ?', [$tradeId]);
    if (!$trade) {
        return ['error' => 'معامله یافت نشد.'];
    }
    $a = (int)$trade['user_a_id'];
    $b = (int)$trade['user_b_id'];
    if ($initiatorId !== $a && $initiatorId !== $b) {
        return ['error' => 'دسترسی ندارید.'];
    }
    $existing = multi_trade_room_for_seed_trade($tradeId);
    if ($existing) {
        return ['ok' => true, 'room_id' => (int)$existing['id'], 'existing' => true];
    }
    $status = $bothAccepted ? 'forming' : 'awaiting_seed';
    $roomId = DB::insert('multi_trade_rooms', [
        'seed_trade_id' => $tradeId,
        'initiated_by'  => $initiatorId,
        'status'        => $status,
        'members_min'   => MT_ROOM_MIN,
        'members_max'   => MT_ROOM_MAX,
    ]);

    $otherId = $initiatorId === $a ? $b : $a;
    $listingOf = function (int $uid) use ($trade): ?int {
        if ($uid === (int)$trade['user_a_id']) return (int)$trade['listing_a_id'];
        if ($uid === (int)$trade['user_b_id']) return !empty($trade['listing_b_id']) ? (int)$trade['listing_b_id'] : null;
        return null;
    };

    DB::insert('multi_trade_members', [
        'room_id'    => $roomId,
        'user_id'    => $initiatorId,
        'listing_id' => $listingOf($initiatorId),
        'status'     => 'active',
        'is_seed'    => 1,
        'join_order' => 1,
    ]);
    DB::insert('multi_trade_members', [
        'room_id'    => $roomId,
        'user_id'    => $otherId,
        'listing_id' => $listingOf($otherId),
        'status'     => $bothAccepted ? 'active' : 'invited',
        'is_seed'    => 1,
        'join_order' => 2,
    ]);

    multi_trade_system_message($roomId, 'اتاق مبادله چندنفره ساخته شد. با پیوستن حداقل '
        . MT_ROOM_MIN . ' عضو می‌توانید اتاق را ببندید و طرف معامله را انتخاب کنید.');

    if (!$bothAccepted) {
        multi_trade_notify(
            $otherId,
            'دعوت به مبادله چندنفره',
            'طرف مقابل درخواست تبدیل این معامله به «مبادله چندنفره» داده است. تأیید کنید تا اتاق فعال شود.',
            multi_trade_room_url($roomId)
        );
    } else {
        multi_trade_notify(
            $otherId,
            'مبادله چندنفره آغاز شد',
            'این معامله به اتاق مبادله چندنفره تبدیل شد.',
            multi_trade_room_url($roomId)
        );
    }
    return ['ok' => true, 'room_id' => $roomId];
}

/**
 * تبدیل پیشنهاد پذیرفته‌شده: پس از accept_trade_offer صدا زده می‌شود.
 */
function multi_trade_create_from_accepted_offer(int $offerId, int $tradeId, int $acceptorId): void
{
    if (!multi_trade_ensure_tables()) {
        return;
    }
    $offer = DB::fetch('SELECT group_requested_by FROM trade_offers WHERE id = ?', [$offerId]);
    if (!$offer) {
        return;
    }
    $requestedBy = (int)($offer['group_requested_by'] ?? 0);
    if ($requestedBy <= 0) {
        return;
    }
    multi_trade_create_from_trade($tradeId, $requestedBy, true);
}

// ══════════════════════════════════════════════════════════════════════════════
// دعوت و درخواست عضویت
// ══════════════════════════════════════════════════════════════════════════════
function multi_trade_accept_invite(int $roomId, int $userId): array
{
    if (!multi_trade_ensure_tables()) {
        return ['error' => 'سامانه در دسترس نیست.'];
    }
    $room = multi_trade_room($roomId);
    if (!$room || $room['status'] !== 'awaiting_seed') {
        return ['error' => 'این دعوت دیگر معتبر نیست.'];
    }
    $member = multi_trade_member($roomId, $userId);
    if (!$member || $member['status'] !== 'invited') {
        return ['error' => 'دعوت برای شما یافت نشد.'];
    }
    DB::update('multi_trade_members', ['status' => 'active'], 'id = ?', [(int)$member['id']]);
    DB::update('multi_trade_rooms', ['status' => 'forming'], 'id = ?', [$roomId]);

    multi_trade_system_message($roomId, 'طرف مقابل دعوت را پذیرفت. اتاق اکنون برای پیوستن اعضای جدید باز است.');
    foreach (multi_trade_members($roomId) as $m) {
        if ((int)$m['id'] !== (int)$member['id']) {
            multi_trade_notify(
                (int)$m['user_id'],
                'مبادله چندنفره فعال شد',
                'طرف مقابل دعوت مبادله چندنفره را پذیرفت.',
                multi_trade_room_url($roomId)
            );
        }
    }
    return ['ok' => true];
}

function multi_trade_decline_invite(int $roomId, int $userId): array
{
    $member = multi_trade_member($roomId, $userId);
    if (!$member || $member['status'] !== 'invited') {
        return ['error' => 'دعوت برای شما یافت نشد.'];
    }
    DB::update('multi_trade_members', ['status' => 'declined'], 'id = ?', [(int)$member['id']]);
    DB::update('multi_trade_rooms', ['status' => 'cancelled'], 'id = ?', [$roomId]);
    return ['ok' => true];
}

/**
 * ارسال درخواست عضویت در اتاق (از طریق بدج/صفحه آگهی).
 */
function multi_trade_join_request(int $roomId, int $userId, int $listingId): array
{
    if (!multi_trade_ensure_tables()) {
        return ['error' => 'سامانه در دسترس نیست.'];
    }
    $room = multi_trade_room($roomId);
    if (!$room || $room['status'] !== 'forming') {
        return ['error' => 'این اتاق در حال حاضر عضو نمی‌پذیرد.'];
    }
    if (multi_trade_member($roomId, $userId)) {
        return ['error' => 'شما از قبل عضو این اتاق هستید.'];
    }
    if (multi_trade_active_count($roomId) >= (int)$room['members_max']) {
        return ['error' => 'ظرفیت این اتاق تکمیل شده است.'];
    }
    $listing = DB::fetch('SELECT id FROM listings WHERE id = ? AND user_id = ?', [$listingId, $userId]);
    if (!$listing) {
        return ['error' => 'آگهی انتخابی معتبر نیست.'];
    }
    $pending = DB::fetch(
        "SELECT id FROM multi_trade_join_requests WHERE room_id = ? AND user_id = ? AND status = 'pending' LIMIT 1",
        [$roomId, $userId]
    );
    if ($pending) {
        return ['ok' => true, 'request_id' => (int)$pending['id'], 'existing' => true];
    }
    $requestId = DB::insert('multi_trade_join_requests', [
        'room_id'    => $roomId,
        'user_id'    => $userId,
        'listing_id' => $listingId,
        'status'     => 'pending',
    ]);
    $user = DB::fetch('SELECT name FROM users WHERE id = ?', [$userId]);
    $name = $user['name'] ?? 'کاربر';
    foreach (multi_trade_members($roomId) as $m) {
        multi_trade_notify(
            (int)$m['user_id'],
            'درخواست عضویت در مبادله چندنفره',
            $name . ' درخواست پیوستن به اتاق شما را دارد. تأیید همه اعضا لازم است.',
            multi_trade_room_url($roomId)
        );
    }
    multi_trade_system_message($roomId, $name . ' درخواست پیوستن داد؛ در انتظار تأیید همه اعضا.');
    return ['ok' => true, 'request_id' => $requestId];
}

function multi_trade_join_requests_pending(int $roomId): array
{
    return DB::fetchAll(
        "SELECT jr.*, u.name AS user_name, u.avatar AS user_avatar,
                l.title AS listing_title, l.estimated_value AS listing_value,
                (SELECT COUNT(*) FROM multi_trade_join_votes v WHERE v.request_id = jr.id AND v.decision = 'approve') AS approve_count,
                (SELECT COUNT(*) FROM multi_trade_join_votes v WHERE v.request_id = jr.id AND v.decision = 'reject') AS reject_count
         FROM multi_trade_join_requests jr
         JOIN users u ON u.id = jr.user_id
         LEFT JOIN listings l ON l.id = jr.listing_id
         WHERE jr.room_id = ? AND jr.status = 'pending'
         ORDER BY jr.id ASC",
        [$roomId]
    );
}

function multi_trade_join_request_for_user(int $roomId, int $userId): ?array
{
    return DB::fetch(
        "SELECT * FROM multi_trade_join_requests WHERE room_id = ? AND user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
        [$roomId, $userId]
    ) ?: null;
}

/**
 * رأی یک عضو به درخواست عضویت. با تأیید همه اعضا، عضو جدید اضافه می‌شود.
 */
function multi_trade_vote_join(int $requestId, int $voterUserId, bool $approve): array
{
    if (!multi_trade_ensure_tables()) {
        return ['error' => 'سامانه در دسترس نیست.'];
    }
    $req = DB::fetch("SELECT * FROM multi_trade_join_requests WHERE id = ? AND status = 'pending'", [$requestId]);
    if (!$req) {
        return ['error' => 'درخواست یافت نشد یا بسته شده است.'];
    }
    $roomId = (int)$req['room_id'];
    $room = multi_trade_room($roomId);
    if (!$room || $room['status'] !== 'forming') {
        return ['error' => 'اتاق دیگر عضو نمی‌پذیرد.'];
    }
    $voter = multi_trade_member($roomId, $voterUserId);
    if (!$voter || $voter['status'] !== 'active') {
        return ['error' => 'شما عضو فعال این اتاق نیستید.'];
    }
    DB::query(
        "INSERT INTO multi_trade_join_votes (request_id, member_id, voter_user_id, decision)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE decision = VALUES(decision), created_at = NOW()",
        [$requestId, (int)$voter['id'], $voterUserId, $approve ? 'approve' : 'reject']
    );

    $applicantId = (int)$req['user_id'];
    if (!$approve) {
        DB::query("UPDATE multi_trade_join_requests SET status = 'rejected' WHERE id = ?", [$requestId]);
        multi_trade_system_message($roomId, 'یکی از اعضا با پیوستن عضو جدید مخالفت کرد؛ درخواست رد شد.');
        multi_trade_notify($applicantId, 'درخواست عضویت رد شد',
            'درخواست پیوستن شما به اتاق مبادله چندنفره رد شد.', multi_trade_room_url($roomId));
        return ['ok' => true, 'rejected' => true];
    }

    // شرط تأیید همه اعضای فعال
    $activeIds = array_map(fn($m) => (int)$m['id'], multi_trade_members($roomId));
    $approvedIds = array_map(fn($r) => (int)$r['member_id'], DB::fetchAll(
        "SELECT member_id FROM multi_trade_join_votes WHERE request_id = ? AND decision = 'approve'",
        [$requestId]
    ));
    sort($activeIds);
    sort($approvedIds);
    if ($activeIds !== $approvedIds) {
        return ['ok' => true, 'waiting' => true];
    }

    // همه تأیید کردند
    if (multi_trade_active_count($roomId) >= (int)$room['members_max']) {
        DB::query("UPDATE multi_trade_join_requests SET status = 'rejected' WHERE id = ?", [$requestId]);
        multi_trade_notify($applicantId, 'ظرفیت اتاق تکمیل شد',
            'هم‌زمان با تأیید شما، ظرفیت اتاق تکمیل شد.', multi_trade_room_url($roomId));
        return ['error' => 'ظرفیت اتاق تکمیل شده است.'];
    }
    $maxOrder = (int)(DB::fetch('SELECT MAX(join_order) AS o FROM multi_trade_members WHERE room_id = ?', [$roomId])['o'] ?? 0);
    $memberId = DB::insert('multi_trade_members', [
        'room_id'    => $roomId,
        'user_id'    => $applicantId,
        'listing_id' => (int)$req['listing_id'],
        'status'     => 'active',
        'is_seed'    => 0,
        'join_order' => $maxOrder + 1,
    ]);
    DB::query("UPDATE multi_trade_join_requests SET status = 'approved' WHERE id = ?", [$requestId]);

    $uname = DB::fetch('SELECT name FROM users WHERE id = ?', [$applicantId]);
    multi_trade_system_message($roomId, ($uname['name'] ?? 'عضو جدید') . ' با تأیید همه اعضا به اتاق پیوست.');
    foreach (multi_trade_members($roomId) as $m) {
        multi_trade_notify((int)$m['user_id'], 'عضو جدید به اتاق پیوست',
            ($uname['name'] ?? 'عضو جدید') . ' به اتاق مبادله چندنفره پیوست.', multi_trade_room_url($roomId));
    }
    multi_trade_notify($applicantId, 'به اتاق پیوستید',
        'همه اعضا با پیوستن شما موافقت کردند.', multi_trade_room_url($roomId));
    return ['ok' => true, 'member_id' => $memberId];
}

// ══════════════════════════════════════════════════════════════════════════════
// بستن اتاق (رأی همه اعضا)
// ══════════════════════════════════════════════════════════════════════════════
function multi_trade_vote_close(int $roomId, int $userId): array
{
    if (!multi_trade_ensure_tables()) {
        return ['error' => 'سامانه در دسترس نیست.'];
    }
    $room = multi_trade_room($roomId);
    if (!$room || !in_array($room['status'], ['forming', 'locked'], true)) {
        return ['error' => 'اتاق در وضعیت قابل بستن نیست.'];
    }
    $member = multi_trade_member($roomId, $userId);
    if (!$member || $member['status'] !== 'active') {
        return ['error' => 'شما عضو فعال این اتاق نیستید.'];
    }
    if (multi_trade_active_count($roomId) < MT_ROOM_MIN) {
        return ['error' => 'برای بستن اتاق حداقل ' . MT_ROOM_MIN . ' عضو لازم است.'];
    }
    DB::query('UPDATE multi_trade_members SET close_vote = 1, close_voted_at = NOW() WHERE id = ?', [(int)$member['id']]);
    multi_trade_system_message($roomId, 'یک عضو با بستن اتاق موافقت کرد.');

    if (multi_trade_all_close_votes($roomId)) {
        DB::update('multi_trade_rooms', ['status' => 'locked', 'locked_at' => date('Y-m-d H:i:s')], 'id = ?', [$roomId]);
        multi_trade_system_message($roomId, 'همه اعضا موافقت کردند؛ اتاق بسته شد. اکنون هر عضو باید انتخاب کند کالایش را به چه کسی تحویل دهد.');
        foreach (multi_trade_members($roomId) as $m) {
            multi_trade_notify((int)$m['user_id'], 'اتاق بسته شد — انتخاب طرف معامله',
                'اتاق مبادله چندنفره بسته شد. لطفاً طرف مقابل خود را انتخاب کنید.',
                multi_trade_room_url($roomId));
        }
    }
    return ['ok' => true, 'locked' => $room['status'] === 'locked' || multi_trade_all_close_votes($roomId)];
}

function multi_trade_all_close_votes(int $roomId): bool
{
    $row = DB::fetch(
        "SELECT COUNT(*) AS c, SUM(close_vote = 1) AS v
         FROM multi_trade_members WHERE room_id = ? AND status = 'active'",
        [$roomId]
    );
    $c = (int)($row['c'] ?? 0);
    $v = (int)($row['v'] ?? 0);
    return $c >= MT_ROOM_MIN && $c === $v;
}

// ══════════════════════════════════════════════════════════════════════════════
// انتخاب طرف معامله + اعتبارسنجی چرخه کامل
// ══════════════════════════════════════════════════════════════════════════════
function multi_trade_select_target(int $roomId, int $userId, int $targetMemberId): array
{
    $room = multi_trade_room($roomId);
    if (!$room || $room['status'] !== 'locked') {
        return ['error' => 'اکنون امکان انتخاب طرف معامله نیست.'];
    }
    $member = multi_trade_member($roomId, $userId);
    if (!$member || $member['status'] !== 'active') {
        return ['error' => 'شما عضو فعال این اتاق نیستید.'];
    }
    if ($targetMemberId === (int)$member['id']) {
        return ['error' => 'نمی‌توانید خودتان را انتخاب کنید.'];
    }
    $target = DB::fetch("SELECT id FROM multi_trade_members WHERE id = ? AND room_id = ? AND status = 'active'", [$targetMemberId, $roomId]);
    if (!$target) {
        return ['error' => 'عضو انتخابی معتبر نیست.'];
    }
    DB::update('multi_trade_members', ['target_member_id' => $targetMemberId], 'id = ?', [(int)$member['id']]);

    $result = ['ok' => true];
    if (multi_trade_targets_complete($roomId)) {
        $validation = multi_trade_validate_cycle($roomId);
        if (!$validation['ok']) {
            $result['cycle_error'] = $validation['error'];
        } else {
            $activation = multi_trade_activate_room($roomId);
            if (isset($activation['error'])) {
                $result['cycle_error'] = $activation['error'];
            } else {
                $result['activated'] = true;
            }
        }
    }
    return $result;
}

function multi_trade_targets_complete(int $roomId): bool
{
    $row = DB::fetch(
        "SELECT COUNT(*) AS c, SUM(target_member_id IS NOT NULL AND target_member_id <> 0) AS t
         FROM multi_trade_members WHERE room_id = ? AND status = 'active'",
        [$roomId]
    );
    return (int)($row['c'] ?? 0) > 0 && (int)($row['c'] ?? 0) === (int)($row['t'] ?? 0);
}

/**
 * @return array{ok:bool,cycle?:int[],error?:string}
 */
function multi_trade_validate_cycle(int $roomId): array
{
    $members = multi_trade_members($roomId);
    $ids = array_map(fn($m) => (int)$m['id'], $members);
    if (count($ids) < MT_ROOM_MIN) {
        return ['ok' => false, 'error' => 'تعداد اعضا کمتر از حد مجاز است.'];
    }
    $targets = [];
    foreach ($members as $m) {
        $t = (int)($m['target_member_id'] ?? 0);
        if ($t <= 0) {
            return ['ok' => false, 'error' => 'همه اعضا باید طرف معامله را انتخاب کنند.'];
        }
        $targets[(int)$m['id']] = $t;
    }
    $indeg = array_fill_keys($ids, 0);
    foreach ($ids as $id) {
        $t = $targets[$id];
        if ($t === $id) {
            return ['ok' => false, 'error' => 'هیچ‌کس نمی‌تواند خودش را انتخاب کند.'];
        }
        if (!in_array($t, $ids, true)) {
            return ['ok' => false, 'error' => 'انتخاب یکی از اعضا نامعتبر است.'];
        }
        $indeg[$t]++;
    }
    foreach ($indeg as $id => $c) {
        if ($c !== 1) {
            return ['ok' => false, 'error' => 'هر عضو باید دقیقاً از یک نفر کالا دریافت کند؛ انتخاب‌ها را اصلاح کنید.'];
        }
    }
    // یک چرخه‌ی واحد باید همه اعضا را پوشش دهد
    $start = $ids[0];
    $cur   = $start;
    $seen  = [];
    $n     = count($ids);
    for ($i = 0; $i < $n; $i++) {
        $seen[] = $cur;
        $cur = $targets[$cur];
    }
    if ($cur !== $start || count(array_unique($seen)) !== $n) {
        return ['ok' => false, 'error' => 'انتخاب‌ها یک چرخه‌ی کامل تشکیل نمی‌دهند (چند چرخه‌ی جدا یا ناقص است).'];
    }
    return ['ok' => true, 'cycle' => $seen];
}

/**
 * فعال‌سازی اتاق: ساخت مسیرهای جفتی، محاسبه کارمزد و ماب‌تفاوت.
 */
function multi_trade_activate_room(int $roomId): array
{
    $room = multi_trade_room($roomId);
    if (!$room) {
        return ['error' => 'اتاق یافت نشد.'];
    }
    $members = multi_trade_members($roomId);
    if (count($members) < MT_ROOM_MIN) {
        return ['error' => 'تعداد اعضا کمتر از حد مجاز است.'];
    }
    $byId = [];
    foreach ($members as $m) {
        $byId[(int)$m['id']] = $m;
    }
    $valOf = function (?array $m): float {
        return $m ? (float)($m['listing_value'] ?? 0) : 0.0;
    };

    // نقشه‌ی دریافت‌کننده‌ی هر عضو = عضوی که این عضو را انتخاب کرده
    $giverToMe = [];
    foreach ($members as $m) {
        $giverToMe[(int)$m['target_member_id']] = (int)$m['id'];
    }

    $existing = (int)(DB::fetch('SELECT COUNT(*) AS c FROM multi_trade_tracks WHERE room_id = ?', [$roomId])['c'] ?? 0);

    foreach ($members as $m) {
        $mid    = (int)$m['id'];
        $target = (int)$m['target_member_id'];

        // کارمزد این عضو بر اساس ارزش آگهی خودش
        $ownListingId = (int)($m['listing_id'] ?? 0);
        $fee = 0;
        if ($ownListingId > 0) {
            $listing = DB::fetch(
                'SELECT l.category_id, l.estimated_value, u.provider_type
                 FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ?',
                [$ownListingId]
            );
            if ($listing) {
                $rate = get_commission_rate_for((int)$listing['category_id'], (string)($listing['provider_type'] ?? ''));
                $val  = (float)$listing['estimated_value'];
                $fee  = $val > 0 ? max(1, (int)round($val * $rate)) : 0;
            }
        }
        // ماب‌تفاوت = ارزش کالای دریافتی − ارزش کالای خودم
        $receivedFrom = $giverToMe[$mid] ?? 0;
        $receivedVal  = isset($byId[$receivedFrom]) ? $valOf($byId[$receivedFrom]) : 0.0;
        $diff = (int)round($receivedVal - $valOf($m));

        DB::update('multi_trade_members', [
            'fee_amount'  => $fee,
            'diff_amount' => $diff,
        ], 'id = ?', [$mid]);

        if ($existing === 0) {
            DB::insert('multi_trade_tracks', [
                'room_id'             => $roomId,
                'deliverer_member_id' => $mid,
                'receiver_member_id'  => $target,
            ]);
        }
    }

    DB::update('multi_trade_rooms', ['status' => 'active', 'diff_settled' => 0], 'id = ?', [$roomId]);
    multi_trade_system_message($roomId, 'چرخه‌ی معامله تأیید شد و مسیرهای جفتی ساخته شدند. فرآیند معامله آغاز شد.');
    foreach ($members as $m) {
        multi_trade_notify((int)$m['user_id'], 'چرخه‌ی معامله تأیید شد',
            'انتخاب‌ها چرخه‌ی کامل ساخت. فرآیند معامله آغاز شد.', multi_trade_room_url($roomId));
    }
    return ['ok' => true];
}

function multi_trade_targets_map(int $roomId): array
{
    $map = [];
    foreach (multi_trade_members($roomId) as $m) {
        $map[(int)$m['id']] = (int)($m['target_member_id'] ?? 0);
    }
    return $map;
}

// ══════════════════════════════════════════════════════════════════════════════
// مسیرهای جفتی و مراحل
// ══════════════════════════════════════════════════════════════════════════════
function multi_trade_tracks(int $roomId): array
{
    return DB::fetchAll(
        'SELECT tr.*,
                dm.user_id AS deliverer_user_id, dm.listing_id AS deliverer_listing_id,
                rm.user_id AS receiver_user_id, rm.listing_id AS receiver_listing_id,
                du.name AS deliverer_name, ru.name AS receiver_name,
                dl.title AS deliverer_listing_title, rl.title AS receiver_listing_title
         FROM multi_trade_tracks tr
         JOIN multi_trade_members dm ON dm.id = tr.deliverer_member_id
         JOIN multi_trade_members rm ON rm.id = tr.receiver_member_id
         JOIN users du ON du.id = dm.user_id
         JOIN users ru ON ru.id = rm.user_id
         LEFT JOIN listings dl ON dl.id = dm.listing_id
         LEFT JOIN listings rl ON rl.id = rm.listing_id
         WHERE tr.room_id = ?
         ORDER BY tr.id ASC',
        [$roomId]
    );
}

function multi_trade_track(int $trackId): ?array
{
    return DB::fetch('SELECT * FROM multi_trade_tracks WHERE id = ?', [$trackId]) ?: null;
}

/**
 * مرحله‌ی فعلی یک مسیر جفتی.
 */
function multi_trade_track_step(array $track, array $room, array $memberById): string
{
    $d = $memberById[(int)$track['deliverer_member_id']] ?? null;
    $r = $memberById[(int)$track['receiver_member_id']] ?? null;
    if (!$d || !$r) {
        return MT_STEP_DONE;
    }
    if (!((int)$d['fee_paid'] && (int)$r['fee_paid'])) {
        return MT_STEP_FEE;
    }
    if (!(int)$room['diff_settled']) {
        return MT_STEP_DIFF;
    }
    if (!((int)$track['deliverer_signed'] && (int)$track['receiver_signed'])) {
        return MT_STEP_CONTRACT;
    }
    if (empty($track['shipping_date'])) {
        return MT_STEP_SHIPPING;
    }
    if (!(int)$track['deliverer_shipped']) {
        return MT_STEP_DELIVERED;
    }
    if (!(int)$track['receiver_received']) {
        return MT_STEP_RECEIVED;
    }
    if (!(int)$track['deliverer_rated'] || !(int)$track['receiver_rated']) {
        return MT_STEP_RATING;
    }
    return MT_STEP_DONE;
}

function multi_trade_pay_fee(int $roomId, int $userId): array
{
    $room = multi_trade_room($roomId);
    if (!$room || $room['status'] !== 'active') {
        return ['error' => 'اتاق در وضعیت پرداخت نیست.'];
    }
    $member = multi_trade_member($roomId, $userId);
    if (!$member || $member['status'] !== 'active') {
        return ['error' => 'شما عضو این اتاق نیستید.'];
    }
    if ((int)$member['fee_paid']) {
        return ['ok' => true, 'already' => true];
    }
    $fee = (float)$member['fee_amount'];
    if ($fee > 0) {
        $u = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userId]);
        if ((float)($u['credit_balance'] ?? 0) < $fee) {
            return ['error' => 'موجودی کیف پول برای کارمزد کافی نیست. نیاز به ' . fmt_credit($fee - (float)($u['credit_balance'] ?? 0)) . ' دارید.'];
        }
        credit_transact($userId, 'fee', -$fee, 'کارمزد مبادله چندنفره اتاق #' . $roomId, [
            'ref_type' => 'external', 'listing_id' => (int)($member['listing_id'] ?? 0),
        ]);
    }
    DB::query('UPDATE multi_trade_members SET fee_paid = 1, fee_paid_at = NOW() WHERE id = ?', [(int)$member['id']]);
    multi_trade_system_message($roomId, $member['user_name'] . ' کارمزد خود را پرداخت کرد.');
    multi_trade_maybe_complete($roomId);
    return ['ok' => true];
}

function multi_trade_pay_diff(int $roomId, int $userId): array
{
    $room = multi_trade_room($roomId);
    if (!$room || $room['status'] !== 'active' || (int)$room['diff_settled']) {
        return ['error' => 'تسویه ماب‌تفاوت در این اتاق فعال نیست.'];
    }
    $member = multi_trade_member($roomId, $userId);
    if (!$member || $member['status'] !== 'active') {
        return ['error' => 'شما عضو این اتاق نیستید.'];
    }
    $diff = (float)$member['diff_amount'];
    if ($diff <= 0) {
        DB::query('UPDATE multi_trade_members SET diff_paid = 1 WHERE id = ?', [(int)$member['id']]);
        multi_trade_settle_diff_if_ready($roomId);
        return ['ok' => true, 'nothing_due' => true];
    }
    if ((int)$member['diff_paid']) {
        return ['ok' => true, 'already' => true];
    }
    $u = DB::fetch('SELECT credit_balance FROM users WHERE id = ?', [$userId]);
    if ((float)($u['credit_balance'] ?? 0) < $diff) {
        return ['error' => 'موجودی کیف پول برای ماب‌تفاوت کافی نیست. نیاز به ' . fmt_credit($diff - (float)($u['credit_balance'] ?? 0)) . ' دارید.'];
    }
    credit_transact($userId, 'trade_debit', -$diff, 'نگهداری ماب‌تفاوت مبادله چندنفره اتاق #' . $roomId, [
        'ref_type' => 'external', 'listing_id' => (int)($member['listing_id'] ?? 0),
    ]);
    DB::query('UPDATE multi_trade_members SET diff_paid = 1 WHERE id = ?', [(int)$member['id']]);
    multi_trade_system_message($roomId, $member['user_name'] . ' ماب‌تفاوت خود را واریز کرد.');
    multi_trade_settle_diff_if_ready($roomId);
    return ['ok' => true];
}

function multi_trade_settle_diff_if_ready(int $roomId): void
{
    $room = multi_trade_room($roomId);
    if (!$room || (int)$room['diff_settled']) {
        return;
    }
    $members = multi_trade_members($roomId);
    $payers   = array_filter($members, fn($m) => (float)$m['diff_amount'] > 0);
    $receivers = array_filter($members, fn($m) => (float)$m['diff_amount'] < 0);
    foreach ($payers as $m) {
        if (!(int)$m['diff_paid']) {
            return; // هنوز همه پرداخت نکرده‌اند
        }
    }
    $total = 0.0;
    foreach ($receivers as $m) {
        $total += abs((float)$m['diff_amount']);
    }
    foreach ($receivers as $m) {
        credit_transact((int)$m['user_id'], 'trade_credit', abs((float)$m['diff_amount']),
            'دریافت ماب‌تفاوت مبادله چندنفره اتاق #' . $roomId, [
                'ref_type' => 'external', 'listing_id' => (int)($m['listing_id'] ?? 0),
            ]);
    }
    DB::query('UPDATE multi_trade_members SET diff_settled = 1 WHERE room_id = ?', [$roomId]);
    DB::update('multi_trade_rooms', ['diff_settled' => 1], 'id = ?', [$roomId]);
    if ($total > 0) {
        multi_trade_system_message($roomId, 'ماب‌تفاوت گروه تسویه شد.');
    } else {
        multi_trade_system_message($roomId, 'ماب‌تفاوتی برای تسویه وجود نداشت.');
    }
}

function multi_trade_sign_track(int $trackId, int $userId): array
{
    $track = multi_trade_track($trackId);
    if (!$track) {
        return ['error' => 'مسیر معامله یافت نشد.'];
    }
    $room = multi_trade_room((int)$track['room_id']);
    $member = multi_trade_member((int)$track['room_id'], $userId);
    if (!$member) {
        return ['error' => 'دسترسی ندارید.'];
    }
    if ((int)$member['id'] === (int)$track['deliverer_member_id']) {
        DB::query('UPDATE multi_trade_tracks SET deliverer_signed = 1 WHERE id = ?', [$trackId]);
    } elseif ((int)$member['id'] === (int)$track['receiver_member_id']) {
        DB::query('UPDATE multi_trade_tracks SET receiver_signed = 1 WHERE id = ?', [$trackId]);
    } else {
        return ['error' => 'شما عضو این مسیر نیستید.'];
    }
    multi_trade_system_message((int)$track['room_id'], $member['user_name'] . ' قرارداد را امضا کرد.');
    multi_trade_maybe_complete((int)$track['room_id']);
    return ['ok' => true];
}

function multi_trade_set_track_shipping(int $trackId, int $userId, string $method, string $date, string $time): array
{
    $track = multi_trade_track($trackId);
    if (!$track) {
        return ['error' => 'مسیر معامله یافت نشد.'];
    }
    $member = multi_trade_member((int)$track['room_id'], $userId);
    if (!$member || (int)$member['id'] !== (int)$track['deliverer_member_id']) {
        return ['error' => 'فقط تحویل‌دهنده می‌تواند زمان ارسال را تعیین کند.'];
    }
    if (!in_array($method, ['in_person', 'post', 'tipax', 'courier'], true)) {
        return ['error' => 'روش ارسال نامعتبر است.'];
    }
    if ($date === '') {
        return ['error' => 'تاریخ ارسال را وارد کنید.'];
    }
    DB::query(
        'UPDATE multi_trade_tracks SET shipping_method = ?, shipping_date = ?, shipping_time = ? WHERE id = ?',
        [$method, $date, $time !== '' ? $time : null, $trackId]
    );
    multi_trade_system_message((int)$track['room_id'], $member['user_name'] . ' زمان و روش ارسال را تعیین کرد.');
    return ['ok' => true];
}

function multi_trade_mark_shipped(int $trackId, int $userId, string $trackingCode): array
{
    $track = multi_trade_track($trackId);
    if (!$track) {
        return ['error' => 'مسیر معامله یافت نشد.'];
    }
    $member = multi_trade_member((int)$track['room_id'], $userId);
    if (!$member || (int)$member['id'] !== (int)$track['deliverer_member_id']) {
        return ['error' => 'فقط تحویل‌دهنده می‌تواند ارسال را ثبت کند.'];
    }
    DB::query(
        'UPDATE multi_trade_tracks SET deliverer_shipped = 1, shipped_at = NOW(), tracking_code = ? WHERE id = ?',
        [$trackingCode !== '' ? $trackingCode : null, $trackId]
    );
    multi_trade_system_message((int)$track['room_id'], $member['user_name'] . ' کالا را ارسال کرد.');
    return ['ok' => true];
}

function multi_trade_confirm_received(int $trackId, int $userId): array
{
    $track = multi_trade_track($trackId);
    if (!$track) {
        return ['error' => 'مسیر معامله یافت نشد.'];
    }
    $member = multi_trade_member((int)$track['room_id'], $userId);
    if (!$member || (int)$member['id'] !== (int)$track['receiver_member_id']) {
        return ['error' => 'فقط گیرنده می‌تواند دریافت را تأیید کند.'];
    }
    DB::query('UPDATE multi_trade_tracks SET receiver_received = 1, received_at = NOW() WHERE id = ?', [$trackId]);
    multi_trade_system_message((int)$track['room_id'], $member['user_name'] . ' دریافت کالا را تأیید کرد.');
    return ['ok' => true];
}

function multi_trade_rate_track(int $trackId, int $userId, int $score, string $comment = ''): array
{
    $track = multi_trade_track($trackId);
    if (!$track) {
        return ['error' => 'مسیر معامله یافت نشد.'];
    }
    $member = multi_trade_member((int)$track['room_id'], $userId);
    if (!$member) {
        return ['error' => 'دسترسی ندارید.'];
    }
    $isDeliverer = (int)$member['id'] === (int)$track['deliverer_member_id'];
    $isReceiver  = (int)$member['id'] === (int)$track['receiver_member_id'];
    if (!$isDeliverer && !$isReceiver) {
        return ['error' => 'شما عضو این مسیر نیستید.'];
    }
    $peerMemberId = $isDeliverer ? (int)$track['receiver_member_id'] : (int)$track['deliverer_member_id'];
    if ($isDeliverer) {
        DB::query('UPDATE multi_trade_tracks SET deliverer_rated = 1 WHERE id = ?', [$trackId]);
    } else {
        DB::query('UPDATE multi_trade_tracks SET receiver_rated = 1 WHERE id = ?', [$trackId]);
    }
    // ثبت امتیاز پایدار در جدول reviews نیازمند trade_id دو‌نفره است؛
    // در مبادله چندنفره امتیاز به‌صورت یادداشت در پیام سیستمی ثبت می‌شود.
    if ($score >= 1 && $score <= 5 && $comment !== '') {
        $reviewer = DB::fetch('SELECT name FROM users WHERE id = ?', [$userId]);
        $peerRow  = DB::fetch('SELECT u.name AS n FROM multi_trade_members m JOIN users u ON u.id = m.user_id WHERE m.id = ?', [$peerMemberId]);
        multi_trade_system_message((int)$track['room_id'],
            ($reviewer['name'] ?? 'عضو') . ' برای ' . ($peerRow['n'] ?? 'طرف مقابل') . ' امتیاز ' . $score . ' از ۵ ثبت کرد.');
    }
    multi_trade_maybe_complete((int)$track['room_id']);
    return ['ok' => true];
}

function multi_trade_maybe_complete(int $roomId): void
{
    $room = multi_trade_room($roomId);
    if (!$room || $room['status'] !== 'active') {
        return;
    }
    $memberById = multi_trade_members_map($roomId);
    $tracks = multi_trade_tracks($roomId);
    if (!$tracks) {
        return;
    }
    foreach ($tracks as $t) {
        if (multi_trade_track_step($t, $room, $memberById) !== MT_STEP_DONE) {
            return;
        }
    }
    DB::update('multi_trade_rooms', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], 'id = ?', [$roomId]);
    multi_trade_system_message($roomId, 'تمام مسیرهای معامله تکمیل شدند. مبادله چندنفره با موفقیت پایان یافت.');
    foreach (multi_trade_members($roomId) as $m) {
        multi_trade_notify((int)$m['user_id'], 'مبادله چندنفره تکمیل شد',
            'همه مراحل همه اعضا با موفقیت انجام شد.', multi_trade_room_url($roomId));
    }
}

/**
 * خلاصه‌ی وضعیت کل اتاق برای نمایش «تخته‌وضعیت».
 */
function multi_trade_board(int $roomId): array
{
    $room = multi_trade_room($roomId);
    $members = multi_trade_members($roomId);
    $memberById = [];
    foreach ($members as $m) {
        $memberById[(int)$m['id']] = $m;
    }
    $tracks = multi_trade_tracks($roomId);
    $stepByTrack = [];
    foreach ($tracks as $t) {
        $stepByTrack[(int)$t['id']] = multi_trade_track_step($t, $room, $memberById);
    }
    $cycle = [];
    if ($room && in_array($room['status'], ['active', 'completed'], true)) {
        $targets = [];
        foreach ($members as $m) {
            $targets[(int)$m['id']] = (int)$m['target_member_id'];
        }
        if ($targets) {
            $start = array_key_first($targets);
            $cur = $start;
            for ($i = 0; $i < count($targets); $i++) {
                $cycle[] = $cur;
                $cur = $targets[$cur] ?? 0;
                if (!$cur) break;
            }
        }
    }
    return [
        'room'         => $room,
        'members'      => $members,
        'memberById'   => $memberById,
        'tracks'       => $tracks,
        'stepByTrack'  => $stepByTrack,
        'cycle'        => $cycle,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// چت گروهی
// ══════════════════════════════════════════════════════════════════════════════
function multi_trade_system_message(int $roomId, string $body): void
{
    if (!db_has_table('multi_trade_messages')) {
        return;
    }
    DB::insert('multi_trade_messages', [
        'room_id' => $roomId,
        'user_id' => null,
        'type'    => 'system',
        'body'    => $body,
    ]);
}

function multi_trade_send_message(int $roomId, int $userId, string $body): array
{
    $member = multi_trade_member($roomId, $userId);
    if (!$member || $member['status'] !== 'active') {
        return ['error' => 'شما عضو این اتاق نیستید.'];
    }
    $body = trim($body);
    if ($body === '') {
        return ['error' => 'متن پیام خالی است.'];
    }
    $id = DB::insert('multi_trade_messages', [
        'room_id' => $roomId,
        'user_id' => $userId,
        'type'    => 'text',
        'body'    => mb_substr($body, 0, 2000),
    ]);
    return ['ok' => true, 'id' => $id];
}

function multi_trade_messages(int $roomId, int $sinceId = 0): array
{
    if (!db_has_table('multi_trade_messages')) {
        return [];
    }
    return DB::fetchAll(
        'SELECT msg.*, u.name AS user_name, u.avatar AS user_avatar
         FROM multi_trade_messages msg
         LEFT JOIN users u ON u.id = msg.user_id
         WHERE msg.room_id = ? AND msg.id > ?
         ORDER BY msg.id ASC
         LIMIT 200',
        [$roomId, $sinceId]
    );
}

/**
 * همگام‌سازی جداول از طریق میگریشن config.php (?migrate=1).
 */
function multi_trade_migrate(): void
{
    multi_trade_ensure_tables();
}
