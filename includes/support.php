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
        APP_URL . '/admin/tickets?id=' . $ticketId
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
            'link'    => APP_URL . '/support/view?id=' . $ticketId,
            'is_read' => 0,
        ]);
        if (mail_is_enabled()) {
            $user = DB::fetch('SELECT email, name FROM users WHERE id = ?', [(int)$ticket['user_id']]);
            if ($user && !empty($user['email'])) {
                send_mail_to_user(
                    $user['email'],
                    'پاسخ به تیکت: ' . $ticket['subject'],
                    '<p>' . nl2br(h($body)) . '</p>',
                    APP_URL . '/support/view?id=' . $ticketId
                );
            }
        }
    } else {
        notify_admins(
            'پاسخ کاربر در تیکت #' . $ticketId,
            mb_strimwidth($body, 0, 300),
            APP_URL . '/admin/tickets?id=' . $ticketId
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
        APP_URL . '/admin/tickets?tab=errors&id=' . $reportId
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
    static $done = false;
    if ($done) return;
    $done = true;

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
          `sender_type` enum('guest','admin','ai') COLLATE utf8mb4_unicode_ci NOT NULL,
          `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
          `is_read` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_chat_id` (`chat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Existing installs: widen sender_type so AI replies can be stored.
    try {
        $col = DB::fetch(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'live_chat_messages' AND COLUMN_NAME = 'sender_type'
             LIMIT 1"
        );
        if ($col && strpos((string)($col['COLUMN_TYPE'] ?? ''), "'ai'") === false) {
            DB::query("ALTER TABLE `live_chat_messages` MODIFY COLUMN `sender_type` ENUM('guest','admin','ai') COLLATE utf8mb4_unicode_ci NOT NULL");
        }
    } catch (Throwable) {
    }
}

function live_chat_start(string $name, array $transcript = [], string $phone = ''): array {
    $name   = clean($name);
    $phone  = clean($phone);
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

    // Transfer the prior AI conversation so the agent has full context.
    $lastId = 0;
    foreach (array_slice($transcript, -20) as $turn) {
        if (!is_array($turn)) continue;
        $body = clean((string)($turn['content'] ?? ''));
        if ($body === '') continue;
        $sender = ($turn['role'] ?? '') === 'user' ? 'guest' : 'ai';
        $lastId = (int) DB::insert('live_chat_messages', [
            'chat_id'     => $chatId,
            'sender_type' => $sender,
            'body'        => mb_substr($body, 0, 4000),
            'is_read'     => $sender === 'guest' ? 0 : 1,
        ]);
    }

    notify_admins(
        'چت زنده جدید: ' . $name,
        'کاربر ' . $name . ' درخواست ارتباط با پشتیبان انسانی کرد.',
        APP_URL . '/admin/live_chat?id=' . $chatId
    );

    return ['chat_id' => $chatId, 'token' => $token, 'last_id' => $lastId];
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

/* ── پشتیبانی آنلاین: پرسش‌های آماده (FAQ) ─────────────────────────────── */

/** نرمال‌سازی متن فارسی برای تطبیق کلیدواژه‌ها (یکسان‌سازی ی/ک، حذف نیم‌فاصله و علائم). */
function live_chat_faq_normalize(string $text): string {
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text);
    } else {
        $text = strtolower($text);
    }
    $text = str_replace(
        ["\xE2\x80\x8C", 'ي', 'ك', 'ۀ', 'ة', 'أ', 'إ', 'آ', '‌'],
        [' ', 'ی', 'ک', 'ه', 'ه', 'ا', 'ا', 'ا', ' '],
        $text
    );
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', (string)$text);
    return trim((string)$text);
}

/** فهرست ۱۰ پرسش و پاسخ آماده پشتیبانی آنلاین. */
function live_chat_faq_items(): array {
    return [
        [
            'id'       => 'register',
            'q'        => 'چطور در سواپین ثبت‌نام کنم؟',
            'keywords' => ['ثبت نام', 'ثبتنام', 'عضویت', 'عضو', 'اکانت', 'حساب کاربری', 'ساخت حساب', 'ورود به سایت'],
            'a'        => 'برای ثبت‌نام در سواپین روی دکمه «ورود / ثبت‌نام» در بالای سایت بزنید، شماره موبایل خود را وارد کنید و کد پیامک‌شده را تأیید کنید. ثبت‌نام رایگان است و پس از آن می‌توانید آگهی ثبت کنید.',
        ],
        [
            'id'       => 'create-listing',
            'q'        => 'چطور کالایم را برای معاوضه ثبت کنم؟',
            'keywords' => ['ثبت اگهی', 'ثبت کالا', 'درج اگهی', 'اضافه کردن کالا', 'ساخت اگهی', 'ثبت کردن کالا', 'ثبت کنم', 'کالام'],
            'a'        => 'از دکمه «ثبت کالا» در منو، فرم چندمرحله‌ای را کامل کنید: دسته‌بندی، عنوان و توضیحات، وضعیت کالا، ارزش تقریبی و موقعیت مکانی. پس از تأیید، آگهی شما در سایت منتشر می‌شود.',
        ],
        [
            'id'       => 'swap',
            'q'        => 'معاوضه در سواپین چطور انجام می‌شود؟',
            'keywords' => ['معاوضه', 'تعویض', 'تبادل', 'سواپ', 'تهاتر', 'معامله پایاپای'],
            'a'        => 'در سواپین کالای خود را برای معاوضه ثبت می‌کنید و می‌توانید به آگهی‌های دیگران پیشنهاد معاوضه بدهید. اگر دو طرف توافق کنند، معامله نهایی می‌شود؛ در صورت نیاز می‌توانید مابه‌التفاوت نقدی هم تعیین کنید.',
        ],
        [
            'id'       => 'pricing',
            'q'        => 'ارزش کالایم را چطور تعیین کنم؟',
            'keywords' => ['قیمت', 'ارزش', 'چقدر', 'تخمین', 'ارزش‌گذاری', 'قیمت‌گذاری', 'برآورد قیمت', 'مبلغ'],
            'a'        => 'هنگام ثبت آگهی، فیلد «ارزش تقریبی» را وارد کنید. برای برآورد بهتر می‌توانید از فرم ارزش‌گذاری سریع دستیار سواپین استفاده کنید که بر اساس آگهی‌های مشابه بازار، قیمت تقریبی را پیشنهاد می‌دهد.',
        ],
        [
            'id'       => 'promotion',
            'q'        => 'چطور آگهی‌ام را ویژه کنم؟',
            'keywords' => ['ویژه', 'ویژه‌سازی', 'پلن', 'ارتقا', 'تبلیغ', 'دیده شدن', 'برجسته'],
            'a'        => 'در صفحه آگهی خود، گزینه «ارتقای آگهی» را انتخاب کنید. پلن‌های مختلفی مثل «داغ»، «ویژه» و «طلایی» وجود دارد که آگهی شما را در بخش‌های ویژه و بالای نتایج نمایش می‌دهد.',
        ],
        [
            'id'       => 'payment',
            'q'        => 'پرداخت و امنیت معامله چطور است؟',
            'keywords' => ['پرداخت', 'کیف پول', 'امنیت', 'امن', 'تراکنش', 'درگاه', 'کارت به کارت'],
            'a'        => 'سواپین واسطه پرداخت بین کاربران نیست؛ معامله‌ها معمولاً حضوری یا با توافق دو طرف انجام می‌شود. برای امنیت بیشتر، پیش از پرداخت کالا را از نزدیک بررسی کنید و از واریز وجه قبل از تحویل خودداری کنید.',
        ],
        [
            'id'       => 'review',
            'q'        => 'آگهی من چقدر طول می‌کشد تأیید شود؟',
            'keywords' => ['تأیید', 'تایید', 'بررسی', 'انتشار', 'زمان تأیید', 'در انتظار', 'منتشر نشده'],
            'a'        => 'آگهی‌ها پس از ثبت توسط سیستم و کارشناسان بررسی می‌شوند. معمولاً این بررسی سریع انجام می‌شود و وضعیت آگهی شما در بخش «آگهی‌های من» مشخص است. اگر آگهی تأیید نشد، دلیل آن برایتان نمایش داده می‌شود.',
        ],
        [
            'id'       => 'contact',
            'q'        => 'چطور با فروشنده تماس بگیرم؟',
            'keywords' => ['تماس', 'ارتباط', 'شماره', 'پیام دادن', 'گفتگو با فروشنده', 'اطلاعات تماس'],
            'a'        => 'برای حفظ امنیت، اطلاعات تماس فروشنده در صفحه آگهی نمایش داده می‌شود. می‌توانید از بخش پیشنهاد معاوضه یا پیام درون‌سایتی با فروشنده ارتباط بگیرید و درباره معامله صحبت کنید.',
        ],
        [
            'id'       => 'report',
            'q'        => 'چطور یک آگهی متخلف را گزارش کنم؟',
            'keywords' => ['گزارش', 'تخلف', 'شکایت', 'کلاهبرداری', 'جعلی', 'تقلبی', 'سوء استفاده'],
            'a'        => 'در صفحه هر آگهی گزینه «گزارش تخلف» وجود دارد. پس از ثبت گزارش، تیم بررسی سواپین موضوع را بررسی می‌کند. برای پیگیری می‌توانید از بخش پشتیبانی هم تیکت ثبت کنید.',
        ],
        [
            'id'       => 'edit-delete',
            'q'        => 'چطور آگهی‌ام را ویرایش یا حذف کنم؟',
            'keywords' => ['ویرایش', 'حذف', 'پاک', 'پاک کردن', 'تغییر اگهی', 'اصلاح', 'حذف کردن اگهی'],
            'a'        => 'وارد بخش «آگهی‌های من» شوید، آگهی موردنظر را باز کنید و از گزینه‌های «ویرایش» یا «حذف» استفاده کنید. پس از ویرایش، آگهی دوباره بررسی و منتشر می‌شود.',
        ],
    ];
}

/** فهرست پرسش‌ها برای نمایش به‌صورت پیشنهاد در ویجت چت. */
function live_chat_faq_questions(): array {
    $out = [];
    foreach (live_chat_faq_items() as $item) {
        $out[] = ['id' => $item['id'], 'q' => $item['q']];
    }
    return $out;
}

/** یافتن یک پرسش آماده با شناسه. */
function live_chat_faq_by_id(string $id): ?array {
    foreach (live_chat_faq_items() as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }
    return null;
}

/** تطبیق پیام کاربر با نزدیک‌ترین پرسش آماده بر اساس کلیدواژه‌ها. */
function live_chat_faq_match(string $message): ?array {
    $norm = live_chat_faq_normalize($message);
    if ($norm === '') {
        return null;
    }
    $best = null;
    $bestScore = 0;
    foreach (live_chat_faq_items() as $item) {
        $score = 0;
        foreach ($item['keywords'] as $kw) {
            $k = live_chat_faq_normalize((string)$kw);
            if ($k === '' || mb_strlen($k) < 3) {
                continue;
            }
            if (str_contains($norm, $k)) {
                $score += mb_strlen($k);
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $item;
        }
    }
    return $bestScore >= 3 ? $best : null;
}
