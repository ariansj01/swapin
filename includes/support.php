<?php
/**
 * Support tickets and error report helpers.
 */

function support_category_labels(): array {
    return [
        'account' => 'حساب کاربری',
        'listing' => 'آگهی',
        'payment' => 'پرداخت و کیف پول',
        'trade'   => 'معامله',
        'bug'     => 'گزارش خطا',
        'other'   => 'سایر',
    ];
}

function support_status_labels(): array {
    return [
        'open'     => 'باز',
        'answered' => 'پاسخ داده شده',
        'closed'   => 'بسته شده',
    ];
}

function error_report_status_labels(): array {
    return [
        'new'       => 'جدید',
        'reviewing' => 'در حال بررسی',
        'resolved'  => 'حل شده',
        'dismissed' => 'رد شده',
    ];
}

function notify_admins(string $title, string $body, string $link = ''): void {
    $admins = DB::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1');
    foreach ($admins as $a) {
        DB::insert('notifications', [
            'user_id' => (int)$a['id'],
            'type'    => 'admin',
            'title'   => mb_strimwidth($title, 0, 200),
            'body'    => $body,
            'link'    => $link ?: null,
            'is_read' => 0,
        ]);
    }
    if (function_exists('send_mail_to_admins') && mail_is_enabled()) {
        send_mail_to_admins($title, nl2br(h($body)), $link);
    }
}

function create_support_ticket(int $userId, string $subject, string $category, string $body): array {
    $subject = clean($subject);
    $body    = clean($body);
    $errors  = [];

    if (mb_strlen($subject) < 3)  $errors['subject'] = 'موضوع باید حداقل ۳ کاراکتر باشد.';
    if (mb_strlen($body) < 10)    $errors['body']    = 'متن باید حداقل ۱۰ کاراکتر باشد.';
    if (!isset(support_category_labels()[$category])) $errors['category'] = 'دسته‌بندی نامعتبر است.';

    if ($errors) return ['errors' => $errors];

    $ticketId = DB::insert('support_tickets', [
        'user_id'  => $userId,
        'subject'  => $subject,
        'category' => $category,
        'status'   => 'open',
    ]);

    DB::insert('support_messages', [
        'ticket_id'   => $ticketId,
        'sender_type' => 'user',
        'sender_id'   => $userId,
        'body'        => $body,
    ]);

    notify_admins(
        'تیکت پشتیبانی جدید: ' . $subject,
        mb_strimwidth($body, 0, 300),
        APP_URL . '/admin/tickets.php?id=' . $ticketId
    );

    return ['ticket_id' => $ticketId];
}

function add_ticket_message(int $ticketId, string $senderType, int $senderId, string $body): array {
    $body = clean($body);
    if (mb_strlen($body) < 2) return ['error' => 'پیام خیلی کوتاه است.'];

    $ticket = DB::fetch('SELECT * FROM support_tickets WHERE id = ?', [$ticketId]);
    if (!$ticket) return ['error' => 'تیکت یافت نشد.'];
    if ($ticket['status'] === 'closed') return ['error' => 'این تیکت بسته شده است.'];

    DB::insert('support_messages', [
        'ticket_id'   => $ticketId,
        'sender_type' => $senderType,
        'sender_id'   => $senderId,
        'body'        => $body,
    ]);

    $newStatus = $senderType === 'admin' ? 'answered' : 'open';
    DB::update('support_tickets', [
        'status'     => $newStatus,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$ticketId]);

    if ($senderType === 'admin') {
        DB::insert('notifications', [
            'user_id' => (int)$ticket['user_id'],
            'type'    => 'support',
            'title'   => 'پاسخ به تیکت: ' . mb_strimwidth($ticket['subject'], 0, 100),
            'body'    => mb_strimwidth($body, 0, 300),
            'link'    => APP_URL . '/support/view.php?id=' . $ticketId,
            'is_read' => 0,
        ]);
        if (mail_is_enabled()) {
            $user = DB::fetch('SELECT email, name FROM users WHERE id = ?', [(int)$ticket['user_id']]);
            if ($user && !empty($user['email'])) {
                send_mail_to_user(
                    $user['email'],
                    'پاسخ به تیکت: ' . $ticket['subject'],
                    '<p>' . nl2br(h($body)) . '</p>',
                    APP_URL . '/support/view.php?id=' . $ticketId
                );
            }
        }
    } else {
        notify_admins(
            'پاسخ کاربر در تیکت #' . $ticketId,
            mb_strimwidth($body, 0, 300),
            APP_URL . '/admin/tickets.php?id=' . $ticketId
        );
    }

    return ['ok' => true];
}

function submit_error_report(?int $userId, string $message, string $steps, string $pageUrl): array {
    $message  = clean($message);
    $steps    = clean($steps);
    $pageUrl  = clean($pageUrl);
    $errors   = [];

    if (mb_strlen($message) < 10) $errors['message'] = 'توضیح خطا باید حداقل ۱۰ کاراکتر باشد.';

    if ($errors) return ['errors' => $errors];

    $reportId = DB::insert('error_reports', [
        'user_id'    => $userId ?: null,
        'page_url'   => $pageUrl ?: null,
        'message'    => $message,
        'steps'      => $steps ?: null,
        'user_agent' => mb_strimwidth($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'status'     => 'new',
    ]);

    notify_admins(
        'گزارش خطای جدید #' . $reportId,
        mb_strimwidth($message, 0, 300),
        APP_URL . '/admin/tickets.php?tab=errors&id=' . $reportId
    );

    return ['report_id' => $reportId];
}

function admin_close_ticket(int $ticketId): void {
    DB::update('support_tickets', [
        'status'    => 'closed',
        'closed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$ticketId]);
}

function admin_resolve_error_report(int $reportId, string $status, string $note = ''): bool {
    if (!isset(error_report_status_labels()[$status])) return false;
    $report = DB::fetch('SELECT id FROM error_reports WHERE id = ?', [$reportId]);
    if (!$report) return false;

    DB::update('error_reports', [
        'status'      => $status,
        'admin_note'  => $note ?: null,
        'resolved_at' => in_array($status, ['resolved', 'dismissed'], true) ? date('Y-m-d H:i:s') : null,
    ], 'id = ?', [$reportId]);

    return true;
}

function support_open_ticket_count(): int {
    return (int)(DB::fetch('SELECT COUNT(*) AS c FROM support_tickets WHERE status = "open"')['c'] ?? 0);
}

function support_new_error_count(): int {
    return (int)(DB::fetch('SELECT COUNT(*) AS c FROM error_reports WHERE status = "new"')['c'] ?? 0);
}

/* ── Live Chat helpers ──────────────────────────────────────────────────── */

function live_chat_ensure_tables(): void {
    if (!db_has_table('live_chats')) {
        DB::query("CREATE TABLE IF NOT EXISTS `live_chats` (
          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
          `session_token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
          `guest_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `guest_phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
          `status` enum('open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `last_message_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `idx_session_token` (`session_token`),
          KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!db_has_table('live_chat_messages')) {
        DB::query("CREATE TABLE IF NOT EXISTS `live_chat_messages` (
          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
          `chat_id` int UNSIGNED NOT NULL,
          `sender_type` enum('guest','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
          `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
          `is_read` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_chat_id` (`chat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function live_chat_start(string $name, string $phone): array {
    $name  = clean($name);
    $phone = clean($phone);
    $errors = [];
    if (mb_strlen($name) < 2) $errors['name'] = 'نام را وارد کنید.';
    if (!preg_match('/^09\d{9}$/', $phone)) $errors['phone'] = 'شماره موبایل معتبر وارد کنید.';
    if ($errors) return ['errors' => $errors];

    $token = bin2hex(random_bytes(16));
    live_chat_ensure_tables();

    $chatId = DB::insert('live_chats', [
        'session_token' => $token,
        'guest_name'    => $name,
        'guest_phone'   => $phone,
        'status'        => 'open',
    ]);

    notify_admins(
        'چت زنده جدید: ' . $name,
        'کاربر ' . $name . ' (' . $phone . ') شروع به چت کرد.',
        APP_URL . '/admin/live_chat.php?id=' . $chatId
    );

    return ['chat_id' => $chatId, 'token' => $token];
}

function live_chat_send_message(string $token, string $body): array {
    $body = clean($body);
    if (mb_strlen($body) < 1) return ['error' => 'پیام خالی است.'];

    live_chat_ensure_tables();
    $chat = DB::fetch('SELECT * FROM live_chats WHERE session_token = ?', [$token]);
    if (!$chat) return ['error' => 'جلسه چت یافت نشد.'];
    if ($chat['status'] === 'closed') return ['error' => 'این چت بسته شده است.'];

    DB::insert('live_chat_messages', [
        'chat_id'     => (int)$chat['id'],
        'sender_type' => 'guest',
        'body'        => $body,
        'is_read'     => 0,
    ]);

    DB::update('live_chats', [
        'last_message_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [(int)$chat['id']]);

    return ['ok' => true];
}

function live_chat_get_messages(string $token, int $afterId = 0): array {
    live_chat_ensure_tables();
    $chat = DB::fetch('SELECT id FROM live_chats WHERE session_token = ?', [$token]);
    if (!$chat) return [];

    $sql = 'SELECT * FROM live_chat_messages WHERE chat_id = ?';
    $params = [(int)$chat['id']];
    if ($afterId > 0) {
        $sql .= ' AND id > ?';
        $params[] = $afterId;
    }
    $sql .= ' ORDER BY id ASC';

    return DB::fetchAll($sql, $params);
}

function live_chat_admin_list(): array {
    live_chat_ensure_tables();
    return DB::fetchAll(
        'SELECT c.*, (SELECT COUNT(*) FROM live_chat_messages m WHERE m.chat_id = c.id AND m.sender_type = "guest" AND m.is_read = 0) AS unread
         FROM live_chats c ORDER BY c.last_message_at DESC'
    );
}

function live_chat_admin_get(int $chatId): ?array {
    live_chat_ensure_tables();
    $chat = DB::fetch('SELECT * FROM live_chats WHERE id = ?', [$chatId]);
    if (!$chat) return null;
    $messages = DB::fetchAll(
        'SELECT * FROM live_chat_messages WHERE chat_id = ? ORDER BY id ASC',
        [$chatId]
    );
    DB::update('live_chat_messages', ['is_read' => 1], 'chat_id = ? AND sender_type = "guest"', [$chatId]);
    return ['chat' => $chat, 'messages' => $messages];
}

function live_chat_admin_reply(int $chatId, int $adminId, string $body): array {
    $body = clean($body);
    if (mb_strlen($body) < 1) return ['error' => 'پیام خالی است.'];

    live_chat_ensure_tables();
    $chat = DB::fetch('SELECT * FROM live_chats WHERE id = ?', [$chatId]);
    if (!$chat) return ['error' => 'چت یافت نشد.'];
    if ($chat['status'] === 'closed') return ['error' => 'این چت بسته شده است.'];

    DB::insert('live_chat_messages', [
        'chat_id'     => $chatId,
        'sender_type' => 'admin',
        'body'        => $body,
        'is_read'     => 1,
    ]);

    DB::update('live_chats', [
        'last_message_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$chatId]);

    return ['ok' => true];
}

function live_chat_admin_close(int $chatId): void {
    live_chat_ensure_tables();
    DB::update('live_chats', ['status' => 'closed'], 'id = ?', [$chatId]);
}

function live_chat_unread_count(): int {
    live_chat_ensure_tables();
    return (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM live_chat_messages m
         JOIN live_chats c ON c.id = m.chat_id
         WHERE m.sender_type = "guest" AND m.is_read = 0 AND c.status = "open"'
    )['c'] ?? 0);
}
