<?php
/** Club creation, storage, and moderation helpers. */

function clubs_ensure_tables(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!db_has_table('clubs')) {
        try {
            DB::query("
                CREATE TABLE `clubs` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `owner_id` INT UNSIGNED NOT NULL,
                    `name` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `slug` VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `description` VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `rules` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `category_id` INT UNSIGNED NOT NULL,
                    `cover_image` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `avatar_image` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `privacy` ENUM('public','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
                    `require_approval` TINYINT(1) NOT NULL DEFAULT 1,
                    `status` ENUM('pending','approved','rejected','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
                    `admin_note` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `reviewed_by` INT UNSIGNED DEFAULT NULL,
                    `reviewed_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_clubs_slug` (`slug`),
                    KEY `idx_clubs_owner` (`owner_id`),
                    KEY `idx_clubs_status` (`status`),
                    KEY `idx_clubs_category` (`category_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            swapin_debug_log('migration-error-clubs-create', ['msg' => $e->getMessage()]);
        }
    }

    if (!db_has_table('club_members')) {
        try {
            DB::query("
                CREATE TABLE `club_members` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `club_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `role` ENUM('owner','moderator','member') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
                    `status` ENUM('pending','active','banned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_club_member` (`club_id`, `user_id`),
                    KEY `idx_club_members_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            swapin_debug_log('migration-error-club-members-create', ['msg' => $e->getMessage()]);
        }
    }

    if (!db_has_table('club_follows')) {
        try {
            DB::query("
                CREATE TABLE `club_follows` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `club_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_club_follow` (`club_id`, `user_id`),
                    KEY `idx_club_follows_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            swapin_debug_log('migration-error-club-follows-create', ['msg' => $e->getMessage()]);
        }
    }

    if (!db_has_table('club_banners')) {
        try {
            DB::query("
                CREATE TABLE `club_banners` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `title` VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                    `subtitle` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                    `image` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                    `link_url` VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                    `cta_label` VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_club_banners_active` (`is_active`, `sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            swapin_debug_log('migration-error-club-banners-create', ['msg' => $e->getMessage()]);
        }
    }

    if (!db_has_table('club_messages')) {
        try {
            DB::query("
                CREATE TABLE `club_messages` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `club_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `body` VARCHAR(500) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_club_messages_club` (`club_id`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            swapin_debug_log('migration-error-club-messages-create', ['msg' => $e->getMessage()]);
        }
    }
}

function clubs_status_labels(): array {
    return [
        'pending'  => 'در انتظار تأیید',
        'approved' => 'تأیید شده',
        'rejected' => 'رد شده',
        'archived' => 'آرشیو',
    ];
}

function clubs_privacy_labels(): array {
    return [
        'public'  => 'کلاب عمومی',
        'private' => 'کلاب خصوصی',
    ];
}

function clubs_pending_count(): int {
    clubs_ensure_tables();
    if (!db_has_table('clubs')) {
        return 0;
    }
    try {
        return (int)(DB::fetch("SELECT COUNT(*) AS c FROM clubs WHERE status = 'pending'")['c'] ?? 0);
    } catch (Throwable) {
        return 0;
    }
}

function clubs_generate_slug(string $name, int $excludeId = 0): string {
    $slug = trim($name);
    $faDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $enDigits = ['0','1','2','3','4','5','6','7','8','9'];
    $slug = str_replace($faDigits, $enDigits, $slug);
    $persianMap = [
        'ا'=>'a','آ'=>'a','ب'=>'b','پ'=>'p','ت'=>'t','ث'=>'s','ج'=>'j','چ'=>'ch',
        'ح'=>'h','خ'=>'kh','د'=>'d','ذ'=>'z','ر'=>'r','ز'=>'z','ژ'=>'zh','س'=>'s',
        'ش'=>'sh','ص'=>'s','ض'=>'z','ط'=>'t','ظ'=>'z','ع'=>'a','غ'=>'gh','ف'=>'f',
        'ق'=>'q','ک'=>'k','گ'=>'g','ل'=>'l','م'=>'m','ن'=>'n','و'=>'v','ه'=>'h',
        'ی'=>'y','ئ'=>'y','ي'=>'y','ء'=>'',
        'ك'=>'k','ى'=>'y','ؤ'=>'u','إ'=>'a','أ'=>'a',
    ];
    $slug = strtr($slug, $persianMap);
    $slug = preg_replace('/[^a-zA-Z0-9_\-]+/u', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    $slug = mb_strtolower($slug, 'UTF-8');
    $reserved = ['create', 'index', 'join', 'follow', 'chat'];
    if ($slug === '' || preg_match('/^[_\-]+$/', $slug) || in_array($slug, $reserved, true)) {
        $slug = 'club-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    $base = $slug;
    $n = 1;
    while (true) {
        $row = DB::fetch('SELECT id FROM clubs WHERE slug = ? LIMIT 1', [$slug]);
        if (!$row || (int)$row['id'] === $excludeId) {
            return $slug;
        }
        $slug = $base . '-' . (++$n);
        if ($n > 1000) {
            return $base . '-' . time();
        }
    }
}

function clubs_form_defaults(): array {
    return [
        'name'              => '',
        'category_id'       => 0,
        'description'       => '',
        'rules'             => '',
        'privacy'           => 'public',
        'require_approval'  => 1,
        'accept_terms'      => 0,
    ];
}

function clubs_fields_from_input(array $input): array {
    $privacy = clean($input['privacy'] ?? 'public');
    if (!in_array($privacy, ['public', 'private'], true)) {
        $privacy = 'public';
    }
    return [
        'name'             => clean($input['name'] ?? ''),
        'category_id'      => (int)($input['category_id'] ?? 0),
        'description'      => clean($input['description'] ?? ''),
        'rules'            => clean($input['rules'] ?? ''),
        'privacy'          => $privacy,
        'require_approval' => isset($input['require_approval']) ? 1 : 0,
        'accept_terms'     => isset($input['accept_terms']) ? 1 : 0,
    ];
}

function clubs_image_empty(array $file): bool {
    return (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        || (string)($file['name'] ?? '') === '';
}

/** @return array{filename:?string,error:?string} */
function clubs_store_image(array $file, string $kind): array {
    $label = $kind === 'avatar' ? 'پروفایل' : ($kind === 'banner' ? 'بنر' : 'کاور');
    if (clubs_image_empty($file)) {
        return ['filename' => null, 'error' => null];
    }

    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['filename' => null, 'error' => "حجم تصویر {$label} نباید بیشتر از ۵ مگابایت باشد."];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['filename' => null, 'error' => "آپلود تصویر {$label} ناموفق بود."];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return ['filename' => null, 'error' => "آپلود تصویر {$label} ناموفق بود."];
    }

    $info = @getimagesize($tmp);
    $w = is_array($info) ? (int)($info[0] ?? 0) : 0;
    $h = is_array($info) ? (int)($info[1] ?? 0) : 0;
    $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
    $extMap = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/x-png' => 'png'];
    $ext = $extMap[$mime] ?? null;
    if ($ext === null) {
        $origExt = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($origExt, ['jpg', 'jpeg', 'png'], true) && is_array($info)) {
            $ext = $origExt === 'jpeg' ? 'jpg' : $origExt;
        }
    }
    if ($ext === null || !in_array($ext, ['jpg', 'png'], true)) {
        return ['filename' => null, 'error' => "تصویر {$label} فقط با فرمت JPG یا PNG پذیرفته می‌شود."];
    }
    if ($kind === 'avatar' && ($w < 200 || $h < 200)) {
        return ['filename' => null, 'error' => 'تصویر پروفایل باید حداقل ۲۰۰ × ۲۰۰ پیکسل باشد.'];
    }

    $dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        swapin_debug_log('club-upload-mkdir-failed', ['dir' => $dir, 'error' => error_get_last()]);
        return ['filename' => null, 'error' => "ذخیره تصویر {$label} ناموفق بود."];
    }

    $prefix = $kind === 'avatar' ? 'club_avatar' : ($kind === 'banner' ? 'club_ad' : 'club_cover');
    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . $filename;
    $moved = false;
    if (is_uploaded_file($tmp)) {
        $moved = @move_uploaded_file($tmp, $dest);
    }
    if (!$moved && is_readable($tmp)) {
        $moved = @copy($tmp, $dest);
        if ($moved) {
            @unlink($tmp);
        }
    }
    if (!$moved || !is_file($dest)) {
        swapin_debug_log('club-upload-move-failed', [
            'kind' => $kind,
            'tmp' => $tmp,
            'dest' => $dest,
            'writable' => is_writable($dir),
            'is_uploaded' => is_uploaded_file($tmp),
            'php_error' => error_get_last(),
        ]);
        return ['filename' => null, 'error' => "ذخیره تصویر {$label} ناموفق بود."];
    }
    @chmod($dest, 0644);
    return ['filename' => $filename, 'error' => null];
}

function clubs_create(int $ownerId, array $fields, array $files): array {
    clubs_ensure_tables();
    $errors = [];

    $name = $fields['name'] ?? '';
    $description = $fields['description'] ?? '';
    $rules = $fields['rules'] ?? '';
    $categoryId = (int)($fields['category_id'] ?? 0);
    $privacy = ($fields['privacy'] ?? 'public') === 'private' ? 'private' : 'public';
    $requireApproval = !empty($fields['require_approval']) ? 1 : 0;

    if ($name === '') {
        $errors['name'] = 'نام کلاب را وارد کنید.';
    } elseif (mb_strlen($name) < 3) {
        $errors['name'] = 'نام کلاب باید حداقل ۳ کاراکتر باشد.';
    } elseif (mb_strlen($name) > 50) {
        $errors['name'] = 'نام کلاب حداکثر ۵۰ کاراکتر است.';
    }

    if ($description === '') {
        $errors['description'] = 'درباره کلاب را بنویسید.';
    } elseif (mb_strlen($description) > 500) {
        $errors['description'] = 'توضیحات کلاب حداکثر ۵۰۰ کاراکتر است.';
    }

    if (mb_strlen($rules) > 2000) {
        $errors['rules'] = 'قوانین کلاب حداکثر ۲۰۰۰ کاراکتر است.';
    }

    if (!function_exists('wizard_validate_category_id') || !wizard_validate_category_id($categoryId)) {
        $errors['category_id'] = 'لطفاً یک دسته‌بندی معتبر انتخاب کنید.';
    }

    if (empty($fields['accept_terms'])) {
        $errors['accept_terms'] = 'برای ساخت کلاب باید قوانین سواپین را بپذیرید.';
    }

    $coverFile = $files['cover_image'] ?? [];
    $avatarFile = $files['avatar_image'] ?? [];

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $coverStore = clubs_store_image($coverFile, 'cover');
    if (!empty($coverStore['error'])) {
        return ['ok' => false, 'errors' => ['cover_image' => $coverStore['error']]];
    }
    $avatarStore = clubs_store_image($avatarFile, 'avatar');
    if (!empty($avatarStore['error'])) {
        return ['ok' => false, 'errors' => ['avatar_image' => $avatarStore['error']]];
    }
    $coverName = $coverStore['filename'];
    $avatarName = $avatarStore['filename'];

    $slug = clubs_generate_slug($name);

    try {
        DB::pdo()->beginTransaction();
        $id = DB::insert('clubs', [
            'owner_id'          => $ownerId,
            'name'              => $name,
            'slug'              => $slug,
            'description'       => $description,
            'rules'             => $rules !== '' ? $rules : null,
            'category_id'       => $categoryId,
            'cover_image'       => $coverName,
            'avatar_image'      => $avatarName,
            'privacy'           => $privacy,
            'require_approval'  => $requireApproval,
            'status'            => 'pending',
        ]);
        DB::insert('club_members', [
            'club_id' => $id,
            'user_id' => $ownerId,
            'role'    => 'owner',
            'status'  => 'active',
        ]);
        DB::pdo()->commit();
    } catch (Throwable $e) {
        if (DB::pdo()->inTransaction()) {
            DB::pdo()->rollBack();
        }
        swapin_debug_log('club-create-failed', ['msg' => $e->getMessage(), 'owner' => $ownerId]);
        return ['ok' => false, 'errors' => ['_form' => 'ثبت کلاب ناموفق بود. لطفاً دوباره تلاش کنید.']];
    }

    if (function_exists('notify_admins')) {
        notify_admins(
            'درخواست ساخت کلاب',
            'کلاب «' . $name . '» برای بررسی ارسال شد.',
            APP_URL . '/admin/clubs.php?id=' . $id
        );
    }

    return ['ok' => true, 'id' => $id, 'errors' => []];
}

function clubs_get(int $id): ?array {
    clubs_ensure_tables();
    if ($id <= 0) {
        return null;
    }
    return DB::fetch('SELECT * FROM clubs WHERE id = ?', [$id]);
}

function clubs_set_status(int $id, string $status, int $adminId, string $note = ''): bool {
    clubs_ensure_tables();
    if (!in_array($status, ['approved', 'rejected', 'archived', 'pending'], true)) {
        return false;
    }
    $club = clubs_get($id);
    if (!$club) {
        return false;
    }
    DB::update('clubs', [
        'status'      => $status,
        'admin_note'  => $note !== '' ? $note : ($club['admin_note'] ?? null),
        'reviewed_by' => $adminId,
        'reviewed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    $ownerId = (int)$club['owner_id'];
    if ($status === 'approved') {
        DB::insert('notifications', [
            'user_id' => $ownerId,
            'type'    => 'club',
            'title'   => 'کلاب شما تأیید شد',
            'body'    => 'کلاب «' . $club['name'] . '» تأیید شد و در سواپین فعال است.',
            'link'    => APP_URL . '/clubs/' . rawurlencode((string)($club['slug'] ?? '')),
            'is_read' => 0,
        ]);
    } elseif ($status === 'rejected') {
        $body = 'درخواست ساخت کلاب «' . $club['name'] . '» رد شد.';
        if ($note !== '') {
            $body .= ' دلیل: ' . $note;
        }
        DB::insert('notifications', [
            'user_id' => $ownerId,
            'type'    => 'club',
            'title'   => 'کلاب تأیید نشد',
            'body'    => $body,
            'link'    => APP_URL . '/clubs/create',
            'is_read' => 0,
        ]);
    }
    return true;
}

function clubs_approved_list(int $limit = 24): array {
    clubs_ensure_tables();
    if (!db_has_table('clubs')) {
        return [];
    }
    return DB::fetchAll(
        'SELECT cl.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug
         FROM clubs cl
         LEFT JOIN categories c ON c.id = cl.category_id
         WHERE cl.status = "approved"
         ORDER BY cl.created_at DESC
         LIMIT ' . max(1, min(100, $limit))
    );
}

function clubs_hub_url(string $catSlug = ''): string {
    $url = APP_URL . '/clubs';
    if ($catSlug !== '') {
        $url .= '?cat=' . rawurlencode($catSlug);
    }
    return $url;
}

function clubs_url(array $club, string $tab = '', string $q = ''): string {
    $url = APP_URL . '/clubs/' . rawurlencode((string)($club['slug'] ?? ''));
    $params = [];
    if ($tab !== '' && $tab !== 'listings') {
        $params['tab'] = $tab;
    }
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

function clubs_image_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
        return $path;
    }
    return UPLOAD_URL . ltrim($path, '/');
}

function clubs_fmt_compact(int $n): string {
    if ($n >= 1000000) {
        return persian_digits(rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.')) . 'M';
    }
    if ($n >= 1000) {
        return persian_digits(rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.')) . 'K';
    }
    return fmt_num($n);
}

function clubs_category_icon(?string $icon, string $name = ''): string {
    $icon = trim((string)$icon);
    if ($icon !== '') {
        if (!str_starts_with($icon, 'bi')) {
            $icon = 'bi ' . $icon;
        }
        return $icon;
    }
    $name = $name . '';
    if (str_contains($name, 'موبایل') || str_contains($name, 'دیجیتال')) return 'bi bi-phone';
    if (str_contains($name, 'خانه') || str_contains($name, 'آشپز')) return 'bi bi-house';
    if (str_contains($name, 'پوشاک') || str_contains($name, 'مد')) return 'bi bi-handbag';
    if (str_contains($name, 'خودرو') || str_contains($name, 'ماشین')) return 'bi bi-car-front';
    if (str_contains($name, 'ورزش')) return 'bi bi-trophy';
    if (str_contains($name, 'کتاب')) return 'bi bi-book';
    if (str_contains($name, 'کودک') || str_contains($name, 'نوزاد')) return 'bi bi-emoji-smile';
    return 'bi bi-people';
}

function clubs_main_categories(): array {
    try {
        return DB::fetchAll(
            'SELECT id, name, slug, icon FROM categories
             WHERE is_active = 1 AND (parent_id IS NULL OR parent_id = 0)
             ORDER BY sort_order, id'
        );
    } catch (Throwable) {
        return [];
    }
}

function clubs_root_category_id(int $categoryId): int {
    $cur = $categoryId;
    $safety = 20;
    while ($cur > 0 && $safety-- > 0) {
        $row = DB::fetch('SELECT id, parent_id FROM categories WHERE id = ?', [$cur]);
        if (!$row) {
            return $categoryId;
        }
        $pid = (int)($row['parent_id'] ?? 0);
        if ($pid <= 0) {
            return (int)$row['id'];
        }
        $cur = $pid;
    }
    return $categoryId;
}

function clubs_by_root_category(int $rootId): array {
    clubs_ensure_tables();
    $ids = function_exists('swaapin_category_descendant_ids')
        ? swaapin_category_descendant_ids($rootId)
        : [$rootId];
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        return DB::fetchAll(
            "SELECT cl.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug
             FROM clubs cl
             LEFT JOIN categories c ON c.id = cl.category_id
             WHERE cl.status = 'approved' AND cl.category_id IN ($ph)
             ORDER BY cl.created_at DESC",
            $ids
        );
    } catch (Throwable) {
        return [];
    }
}

function clubs_count_in_root(int $rootId): int {
    return count(clubs_by_root_category($rootId));
}

function clubs_banner_active(): ?array {
    clubs_ensure_tables();
    if (!db_has_table('club_banners')) {
        return null;
    }
    return DB::fetch('SELECT * FROM club_banners WHERE is_active = 1 ORDER BY sort_order ASC, id DESC LIMIT 1');
}

function clubs_banners_all(): array {
    clubs_ensure_tables();
    if (!db_has_table('club_banners')) {
        return [];
    }
    return DB::fetchAll('SELECT * FROM club_banners ORDER BY sort_order ASC, id DESC');
}

function clubs_banner_get(int $id): ?array {
    if ($id <= 0 || !db_has_table('club_banners')) {
        return null;
    }
    return DB::fetch('SELECT * FROM club_banners WHERE id = ?', [$id]);
}

function clubs_safe_link(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    return preg_match('#^(https?://|/)#i', $url) ? $url : '';
}

function clubs_banner_save(int $id, array $data): int {
    clubs_ensure_tables();
    if ($id > 0) {
        DB::update('club_banners', $data, 'id = ?', [$id]);
        $savedId = $id;
    } else {
        $savedId = DB::insert('club_banners', $data);
    }
    if ($savedId > 0 && !empty($data['is_active'])) {
        DB::query('UPDATE club_banners SET is_active = 0 WHERE id <> ?', [$savedId]);
    }
    return $savedId;
}

function clubs_banner_delete(int $id): bool {
    $row = clubs_banner_get($id);
    if (!$row) {
        return false;
    }
    DB::query('DELETE FROM club_banners WHERE id = ?', [$id]);
    if (!empty($row['image'])) {
        $file = UPLOAD_DIR . '/' . $row['image'];
        if (is_file($file)) {
            @unlink($file);
        }
    }
    return true;
}

function clubs_get_by_slug(string $slug): ?array {
    clubs_ensure_tables();
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }
    return DB::fetch(
        'SELECT cl.*, c.name AS category_name, c.icon AS category_icon, c.slug AS category_slug,
                u.name AS owner_name, u.avatar AS owner_avatar
         FROM clubs cl
         LEFT JOIN categories c ON c.id = cl.category_id
         LEFT JOIN users u ON u.id = cl.owner_id
         WHERE cl.slug = ? LIMIT 1',
        [$slug]
    );
}

function clubs_category_ids(array $club): array {
    $id = (int)($club['category_id'] ?? 0);
    if ($id <= 0) {
        return [];
    }
    if (function_exists('swaapin_category_descendant_ids')) {
        return swaapin_category_descendant_ids($id);
    }
    return [$id];
}

function clubs_stats(array $club): array {
    $clubId = (int)$club['id'];
    $members = 0;
    $posts = 0;
    $trades = 0;
    try {
        $members = (int)(DB::fetch(
            "SELECT COUNT(*) AS c FROM club_members WHERE club_id = ? AND status = 'active'",
            [$clubId]
        )['c'] ?? 0);
    } catch (Throwable) {}

    $catIds = clubs_category_ids($club);
    if ($catIds) {
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        try {
            $posts = (int)(DB::fetch(
                "SELECT COUNT(*) AS c FROM listings WHERE category_id IN ($ph) AND status = 'active' AND review_status = 'approved'",
                $catIds
            )['c'] ?? 0);
        } catch (Throwable) {}
        try {
            if (db_has_table('trades')) {
                $trades = (int)(DB::fetch(
                    "SELECT COUNT(*) AS c FROM trades t
                     JOIN listings l ON l.id = t.listing_id
                     WHERE l.category_id IN ($ph) AND t.status = 'completed'",
                    $catIds
                )['c'] ?? 0);
            }
        } catch (Throwable) {}
    }
    return ['members' => $members, 'posts' => $posts, 'trades' => $trades];
}

function clubs_member_avatars(int $clubId, int $limit = 3): array {
    try {
        return DB::fetchAll(
            "SELECT u.name, u.avatar FROM club_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.club_id = ? AND m.status = 'active'
             ORDER BY FIELD(m.role,'owner','moderator','member'), m.id
             LIMIT " . max(1, min(8, $limit)),
            [$clubId]
        );
    } catch (Throwable) {
        return [];
    }
}

function clubs_managers(int $clubId): array {
    try {
        return DB::fetchAll(
            "SELECT u.id, u.name, u.avatar, m.role FROM club_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.club_id = ? AND m.status = 'active' AND m.role IN ('owner','moderator')
             ORDER BY FIELD(m.role,'owner','moderator'), m.id LIMIT 8",
            [$clubId]
        );
    } catch (Throwable) {
        return [];
    }
}

function clubs_user_state(?array $user, int $clubId): array {
    $uid = (int)($user['id'] ?? 0);
    $state = ['member' => false, 'pending' => false, 'following' => false, 'role' => '', 'owner' => false];
    if ($uid <= 0 || $clubId <= 0) {
        return $state;
    }
    try {
        $row = DB::fetch('SELECT role, status FROM club_members WHERE club_id = ? AND user_id = ?', [$clubId, $uid]);
        if ($row) {
            $state['role'] = (string)$row['role'];
            $state['owner'] = $row['role'] === 'owner';
            $state['member'] = $row['status'] === 'active';
            $state['pending'] = $row['status'] === 'pending';
        }
    } catch (Throwable) {}
    try {
        $fol = DB::fetch('SELECT id FROM club_follows WHERE club_id = ? AND user_id = ?', [$clubId, $uid]);
        $state['following'] = (bool)$fol;
    } catch (Throwable) {}
    return $state;
}

function clubs_join(int $clubId, int $userId, bool $requireApproval): array {
    clubs_ensure_tables();
    $existing = DB::fetch('SELECT id, status FROM club_members WHERE club_id = ? AND user_id = ?', [$clubId, $userId]);
    if ($existing) {
        if ($existing['status'] === 'banned') {
            return ['ok' => false, 'error' => 'امکان عضویت در این کلاب وجود ندارد.'];
        }
        return ['ok' => true, 'status' => $existing['status']];
    }
    $status = $requireApproval ? 'pending' : 'active';
    DB::insert('club_members', [
        'club_id' => $clubId,
        'user_id' => $userId,
        'role'    => 'member',
        'status'  => $status,
    ]);
    return ['ok' => true, 'status' => $status];
}

function clubs_follow_toggle(int $clubId, int $userId): bool {
    clubs_ensure_tables();
    $row = DB::fetch('SELECT id FROM club_follows WHERE club_id = ? AND user_id = ?', [$clubId, $userId]);
    if ($row) {
        DB::query('DELETE FROM club_follows WHERE id = ?', [(int)$row['id']]);
        return false;
    }
    DB::insert('club_follows', ['club_id' => $clubId, 'user_id' => $userId]);
    return true;
}

function clubs_listings(array $club, string $mode, string $q, int $limit = 12, int $offset = 0): array {
    $catIds = clubs_category_ids($club);
    if (!$catIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($catIds), '?'));
    $sql = "SELECT l.*, u.name AS seller_name, u.avatar AS seller_avatar,
                   c.name AS cat_name,
                   (SELECT filename FROM listing_images WHERE listing_id = l.id AND is_primary = 1 LIMIT 1) AS thumb
            FROM listings l
            JOIN users u ON u.id = l.user_id
            JOIN categories c ON c.id = l.category_id
            WHERE l.category_id IN ($ph) AND l.status = 'active' AND l.review_status = 'approved'";
    $params = $catIds;
    if (db_has_column('listings', 'listing_mode')) {
        if ($mode === 'buy') {
            $sql .= " AND l.listing_mode IN ('sell','both')";
        } elseif ($mode === 'swap') {
            $sql .= " AND l.listing_mode IN ('swap','both')";
        }
    }
    $q = trim($q);
    if ($q !== '') {
        $sql .= ' AND (l.title LIKE ? OR l.description LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    try {
        return DB::fetchAll($sql, $params);
    } catch (Throwable) {
        return [];
    }
}

function clubs_members_list(int $clubId, int $limit = 24): array {
    try {
        return DB::fetchAll(
            "SELECT u.id, u.name, u.avatar, u.city, m.role, m.created_at
             FROM club_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.club_id = ? AND m.status = 'active'
             ORDER BY FIELD(m.role,'owner','moderator','member'), m.created_at
             LIMIT " . max(1, min(60, $limit)),
            [$clubId]
        );
    } catch (Throwable) {
        return [];
    }
}

function clubs_messages(int $clubId, int $limit = 20): array {
    try {
        return DB::fetchAll(
            "SELECT msg.*, u.name AS user_name, u.avatar AS user_avatar
             FROM club_messages msg
             JOIN users u ON u.id = msg.user_id
             WHERE msg.club_id = ?
             ORDER BY msg.id DESC
             LIMIT " . max(1, min(50, $limit)),
            [$clubId]
        );
    } catch (Throwable) {
        return [];
    }
}

function clubs_post_message(int $clubId, int $userId, string $body): array {
    $body = clean($body);
    if (mb_strlen($body) < 2) {
        return ['ok' => false, 'error' => 'متن پیام خیلی کوتاه است.'];
    }
    if (mb_strlen($body) > 500) {
        return ['ok' => false, 'error' => 'متن پیام حداکثر ۵۰۰ کاراکتر است.'];
    }
    DB::insert('club_messages', [
        'club_id' => $clubId,
        'user_id' => $userId,
        'body'    => $body,
    ]);
    return ['ok' => true];
}

function clubs_update_content(int $clubId, string $rules, string $description = ''): bool {
    clubs_ensure_tables();
    DB::update('clubs', [
        'rules'       => $rules !== '' ? $rules : null,
        'description' => mb_substr(trim($description), 0, 500),
    ], 'id = ?', [$clubId]);
    return true;
}
