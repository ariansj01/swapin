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
    if ($slug === '' || preg_match('/^[_\-]+$/', $slug)) {
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
    $label = $kind === 'avatar' ? 'پروفایل' : 'کاور';
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

    $dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'clubs' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        swapin_debug_log('club-upload-mkdir-failed', ['dir' => $dir, 'error' => error_get_last()]);
        return ['filename' => null, 'error' => "ذخیره تصویر {$label} ناموفق بود."];
    }
    @chmod($dir, 0777);

    $filename = ($kind === 'avatar' ? 'avatar' : 'cover') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
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
    return ['filename' => 'clubs/' . $filename, 'error' => null];
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
            'link'    => APP_URL . '/clubs',
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
        'SELECT cl.*, c.name AS category_name
         FROM clubs cl
         LEFT JOIN categories c ON c.id = cl.category_id
         WHERE cl.status = "approved"
         ORDER BY cl.created_at DESC
         LIMIT ' . max(1, min(100, $limit))
    );
}
