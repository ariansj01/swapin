<?php
/**
 * Chat between two regular users (buyer ↔ seller) — separate from support/store chats.
 * Conversations are created from a listing page and are also listed in the user panel.
 */

function user_chat_ensure_tables(): bool {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        if (!db_has_table('user_conversations')) {
            DB::query("
                CREATE TABLE IF NOT EXISTS `user_conversations` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `listing_id` INT UNSIGNED DEFAULT NULL,
                    `user_a_id` INT UNSIGNED NOT NULL,
                    `user_b_id` INT UNSIGNED NOT NULL,
                    `last_message_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_listing_pair` (`listing_id`,`user_a_id`,`user_b_id`),
                    KEY `idx_conv_user_a` (`user_a_id`,`last_message_at`),
                    KEY `idx_conv_user_b` (`user_b_id`,`last_message_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        if (!db_has_table('user_messages')) {
            DB::query("
                CREATE TABLE IF NOT EXISTS `user_messages` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `conversation_id` INT UNSIGNED NOT NULL,
                    `sender_id` INT UNSIGNED NOT NULL,
                    `body` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_msg_conv` (`conversation_id`,`id`),
                    KEY `idx_msg_unread` (`conversation_id`,`is_read`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $ok = true;
    } catch (Throwable $e) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('user-chat-tables-failed', ['error' => $e->getMessage()]);
        }
        $ok = false;
    }
    return $ok;
}

function user_chat_order_pair(int $u1, int $u2): array {
    return $u1 <= $u2 ? [$u1, $u2] : [$u2, $u1];
}

/** Finds or creates the conversation between two users (optionally tied to a listing). */
function user_chat_start(int $listingId, int $meId, int $otherId): ?int {
    if ($meId <= 0 || $otherId <= 0 || $meId === $otherId) {
        return null;
    }
    if (!user_chat_ensure_tables()) {
        return null;
    }
    [$a, $b] = user_chat_order_pair($meId, $otherId);
    $listingId = max(0, $listingId);

    $conv = DB::fetch(
        'SELECT id FROM user_conversations WHERE listing_id <=> ? AND user_a_id = ? AND user_b_id = ? LIMIT 1',
        [$listingId ?: null, $a, $b]
    );
    if ($conv) {
        return (int)$conv['id'];
    }

    return (int) DB::insert('user_conversations', [
        'listing_id'      => $listingId ?: null,
        'user_a_id'       => $a,
        'user_b_id'       => $b,
        'last_message_at' => null,
        'created_at'      => date('Y-m-d H:i:s'),
    ]);
}

/** Returns a conversation only if $userId is one of its members. */
function user_chat_conversation(int $convId, int $userId): ?array {
    if ($convId <= 0 || $userId <= 0 || !user_chat_ensure_tables()) {
        return null;
    }
    return DB::fetch(
        'SELECT c.*,
                oa.id AS other_id, oa.name AS other_name, oa.avatar AS other_avatar,
                oa.store_name AS other_store_name,
                l.title AS listing_title,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS listing_thumb
         FROM user_conversations c
         JOIN users oa ON oa.id = IF(c.user_a_id = ?, c.user_b_id, c.user_a_id)
         LEFT JOIN listings l ON l.id = c.listing_id
         WHERE c.id = ? AND (c.user_a_id = ? OR c.user_b_id = ?)
         LIMIT 1',
        [$userId, $convId, $userId, $userId]
    ) ?: null;
}

/** @return array<int, array<string, mixed>> */
function user_chat_list(int $userId, int $limit = 100): array {
    if ($userId <= 0 || !user_chat_ensure_tables()) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    return DB::fetchAll(
        'SELECT c.id, c.listing_id, c.last_message_at, c.created_at,
                oa.id AS other_id, oa.name AS other_name, oa.avatar AS other_avatar,
                l.title AS listing_title,
                (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS listing_thumb,
                (SELECT m.body FROM user_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                (SELECT m.created_at FROM user_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM user_messages m WHERE m.conversation_id = c.id AND m.sender_id <> ? AND m.is_read = 0) AS unread
         FROM user_conversations c
         JOIN users oa ON oa.id = IF(c.user_a_id = ?, c.user_b_id, c.user_a_id)
         LEFT JOIN listings l ON l.id = c.listing_id
         WHERE c.user_a_id = ? OR c.user_b_id = ?
         ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
         LIMIT ' . $limit,
        [$userId, $userId, $userId, $userId]
    );
}

/** @return array<int, array<string, mixed>> */
function user_chat_messages(int $convId, int $afterId = 0, int $limit = 200): array {
    if ($convId <= 0 || !user_chat_ensure_tables()) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    if ($afterId > 0) {
        return DB::fetchAll(
            'SELECT * FROM user_messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT ' . $limit,
            [$convId, $afterId]
        );
    }
    $rows = DB::fetchAll(
        'SELECT * FROM user_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . $limit,
        [$convId]
    );
    return array_reverse($rows);
}

function user_chat_send(int $convId, int $senderId, string $body): array {
    $body = trim($body);
    if ($body === '') {
        return ['error' => 'متن پیام را وارد کنید.'];
    }
    if (mb_strlen($body) > 2000) {
        $body = mb_substr($body, 0, 2000);
    }
    $conv = user_chat_conversation($convId, $senderId);
    if (!$conv) {
        return ['error' => 'گفتگو یافت نشد.'];
    }
    $now = date('Y-m-d H:i:s');
    $id = DB::insert('user_messages', [
        'conversation_id' => $convId,
        'sender_id'       => $senderId,
        'body'            => $body,
        'is_read'         => 0,
        'created_at'      => $now,
    ]);
    DB::update('user_conversations', ['last_message_at' => $now], 'id = ?', [$convId]);
    return ['id' => $id, 'created_at' => $now];
}

function user_chat_mark_read(int $convId, int $userId): void {
    if ($convId <= 0 || $userId <= 0 || !user_chat_ensure_tables()) {
        return;
    }
    DB::query(
        'UPDATE user_messages SET is_read = 1 WHERE conversation_id = ? AND sender_id <> ? AND is_read = 0',
        [$convId, $userId]
    );
}

function user_chat_unread_count(int $userId): int {
    static $cache = [];
    if ($userId <= 0) {
        return 0;
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    try {
        $row = DB::fetch(
            'SELECT COUNT(*) AS c FROM user_messages m
             JOIN user_conversations c ON c.id = m.conversation_id
             WHERE m.is_read = 0 AND m.sender_id <> ? AND (c.user_a_id = ? OR c.user_b_id = ?)',
            [$userId, $userId, $userId]
        );
        return $cache[$userId] = (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        return $cache[$userId] = 0;
    }
}
