<?php
/**
 * KYC Service — staged verification, trade-value thresholds, risk scoring.
 */

const KYC_VALUE_THRESHOLD = 10_000_000; // ۱۰ میلیون تومان — آستانه سطح ۲

function kyc_run_migrations(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $cols = db_table_columns('users');
    $add = static function (string $sql) {
        try {
            DB::query($sql);
        } catch (Throwable $e) {
            swapin_debug_log('migration-error-kyc-service', ['msg' => $e->getMessage()]);
        }
    };

    if (!in_array('kyc_level', $cols, true)) {
        $add("ALTER TABLE `users` ADD COLUMN `kyc_level` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=none,1=identity,2=advanced' AFTER `kyc_status`");
    }
    if (!in_array('birth_cert_image', $cols, true)) {
        $add("ALTER TABLE `users` ADD COLUMN `birth_cert_image` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `id_card_image`");
    }
    if (!in_array('selfie_image', $cols, true)) {
        $add("ALTER TABLE `users` ADD COLUMN `selfie_image` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `birth_cert_image`");
    }
    if (!in_array('kyc_risk_score', $cols, true)) {
        $add("ALTER TABLE `users` ADD COLUMN `kyc_risk_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `kyc_note`");
    }
    if (!in_array('kyc_risk_level', $cols, true)) {
        $add("ALTER TABLE `users` ADD COLUMN `kyc_risk_level` ENUM('low','medium','high') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'low' AFTER `kyc_risk_score`");
    }
    if (!in_array('kyc_manual_review', $cols, true)) {
        $add("ALTER TABLE `users` ADD COLUMN `kyc_manual_review` TINYINT(1) NOT NULL DEFAULT 0 AFTER `kyc_risk_level`");
    }
}

function kyc_status_labels(): array {
    return [
        'UNVERIFIED'        => 'فقط ثبت‌نام',
        'PHONE_VERIFIED'    => 'موبایل تأییدشده',
        'IDENTITY_PENDING'  => 'در انتظار بررسی هویت',
        'IDENTITY_VERIFIED' => 'احراز سطح ۱',
        'ADVANCED_VERIFIED' => 'احراز سطح ۲',
        'IDENTITY_REJECTED' => 'رد شده',
        'MANUAL_REVIEW'     => 'بررسی دستی',
    ];
}

function kyc_resolve_status(array $user): string {
    if (!empty($user['kyc_manual_review'])) {
        return 'MANUAL_REVIEW';
    }

    $legacy = $user['kyc_status'] ?? 'none';
    if ($legacy === 'pending') {
        return 'IDENTITY_PENDING';
    }
    if ($legacy === 'rejected') {
        return 'IDENTITY_REJECTED';
    }
    if ($legacy === 'approved') {
        return ((int)($user['kyc_level'] ?? 0) >= 2) ? 'ADVANCED_VERIFIED' : 'IDENTITY_VERIFIED';
    }
    if (!empty($user['phone_verified_at'])) {
        return 'PHONE_VERIFIED';
    }

    return 'UNVERIFIED';
}

function kyc_tier_status_label(string $status): string {
    return kyc_status_labels()[$status] ?? $status;
}

/** @return array{status:string,label:string,level:int,phone_verified:bool,identity_verified:bool,advanced_verified:bool,risk_level:string} */
function kyc_public_info(array $user): array {
    $status = kyc_resolve_status($user);
    $level  = kyc_user_level($user);

    return [
        'status'            => $status,
        'label'             => kyc_tier_status_label($status),
        'level'             => $level,
        'phone_verified'    => !empty($user['phone_verified_at']),
        'identity_verified' => in_array($status, ['IDENTITY_VERIFIED', 'ADVANCED_VERIFIED'], true),
        'advanced_verified' => $status === 'ADVANCED_VERIFIED',
        'risk_level'        => strtoupper((string)($user['kyc_risk_level'] ?? 'low')),
    ];
}

function kyc_user_level(array $user): int {
    $status = kyc_resolve_status($user);
    if ($status === 'ADVANCED_VERIFIED') {
        return 2;
    }
    if ($status === 'IDENTITY_VERIFIED') {
        return 1;
    }
    if ($status === 'PHONE_VERIFIED') {
        return 0;
    }

    return -1;
}

function kyc_required_level_for_value(float $estimatedValue): int {
    return $estimatedValue >= KYC_VALUE_THRESHOLD ? 2 : 1;
}

function kyc_can_view_listings(array $user): bool {
    return true;
}

function kyc_can_post_and_chat(array $user): bool {
    if (($user['role'] ?? 'user') === 'admin') {
        return true;
    }
    return !empty($user['phone_verified_at']);
}

function kyc_can_offer_and_trade(array $user): bool {
    if (($user['role'] ?? 'user') === 'admin') {
        return true;
    }
    return kyc_user_level($user) >= 1;
}

function kyc_trade_value_from_listings(?array $listingA, ?array $listingB = null): float {
    $a = (float)($listingA['estimated_value'] ?? 0);
    $b = (float)($listingB['estimated_value'] ?? 0);
    return max($a, $b);
}

function kyc_check_trade(array $user, float $estimatedValue): ?string {
    if (($user['role'] ?? 'user') === 'admin') {
        return null;
    }

    $status = kyc_resolve_status($user);
    if ($status === 'MANUAL_REVIEW') {
        return 'حساب شما در حال بررسی دستی است. لطفاً منتظر تأیید تیم پشتیبانی باشید.';
    }
    if ($status === 'IDENTITY_PENDING') {
        return 'مدارک احراز هویت شما در حال بررسی است.';
    }
    if ($status === 'IDENTITY_REJECTED') {
        return 'احراز هویت شما رد شده — لطفاً مدارک را دوباره ارسال کنید.';
    }
    if (empty($user['phone_verified_at'])) {
        return 'برای پیشنهاد و معامله، ابتدا شماره موبایل خود را تأیید کنید.';
    }
    if (!user_profile_is_complete($user)) {
        return 'برای پیشنهاد و معامله، ابتدا پروفایل خود را تکمیل کنید (نام و شهر).';
    }
    if (!kyc_can_offer_and_trade($user)) {
        return 'برای پیشنهاد و معامله، احراز هویت سطح ۱ (کد ملی) لازم است.';
    }

    $required = kyc_required_level_for_value($estimatedValue);
    $current  = (int)($user['kyc_level'] ?? 0);
    if ($required === 2 && $current < 2) {
        return 'ارزش تقریبی این معامله بالای '
            . number_format(KYC_VALUE_THRESHOLD)
            . ' تومان است — احراز هویت سطح ۲ (کارت ملی + شناسنامه + سلفی) لازم است.';
    }

    return null;
}

function kyc_check_listing_action(array $user): ?string {
    if (($user['role'] ?? 'user') === 'admin') {
        return null;
    }
    if (empty($user['phone_verified_at'])) {
        return 'برای ثبت آگهی و چت، ابتدا شماره موبایل خود را تأیید کنید.';
    }
    if (!user_profile_is_complete($user)) {
        return 'برای ثبت آگهی، ابتدا پروفایل خود را تکمیل کنید (نام و شهر).';
    }
    return null;
}

/** @return array{score:int,level:string,reasons:array<int,string>} */
function kyc_compute_risk(int $userId): array {
    $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return ['score' => 0, 'level' => 'LOW', 'reasons' => []];
    }

    $score   = 0;
    $reasons = [];

    $accountAgeDays = (time() - strtotime($user['created_at'] ?? 'now')) / 86400;
    if ($accountAgeDays < 7) {
        $score += 25;
        $reasons[] = 'حساب تازه‌ساخته';
    } elseif ($accountAgeDays < 30) {
        $score += 10;
    }

    $highValueListings = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = "active" AND estimated_value >= ?',
        [$userId, KYC_VALUE_THRESHOLD]
    )['c'] ?? 0);
    if ($highValueListings >= 3) {
        $score += 30;
        $reasons[] = 'چند آگهی با ارزش بالا';
    } elseif ($highValueListings >= 1) {
        $score += 12;
    }

    $totalListings = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        [$userId]
    )['c'] ?? 0);
    if ($totalListings >= 10) {
        $score += 20;
        $reasons[] = 'ثبت آگهی‌های زیاد در مدت کوتاه';
    }

    if (db_has_table('trades')) {
        $failedTrades = (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM trades WHERE (user_a_id = ? OR user_b_id = ?) AND status IN ("canceled","disputed")',
            [$userId, $userId]
        )['c'] ?? 0);
        if ($failedTrades >= 2) {
            $score += 25;
            $reasons[] = 'معاملات ناموفق یا اختلافی';
        }
    }

    if (db_has_table('support_tickets') && db_has_column('support_tickets', 'reported_user_id')) {
        $reports = (int)(DB::fetch(
            'SELECT COUNT(*) AS c FROM support_tickets WHERE reported_user_id = ?',
            [$userId]
        )['c'] ?? 0);
        if ($reports >= 1) {
            $score += 20;
            $reasons[] = 'گزارش‌شده توسط کاربران';
        }
    }

    $level = 'LOW';
    if ($score >= 70) {
        $level = 'HIGH';
    } elseif ($score >= 40) {
        $level = 'MEDIUM';
    }

    return ['score' => min(100, $score), 'level' => $level, 'reasons' => $reasons];
}

function kyc_persist_risk(int $userId): array {
    $risk = kyc_compute_risk($userId);
    $update = [
        'kyc_risk_score' => $risk['score'],
        'kyc_risk_level' => strtolower($risk['level']),
    ];
    if ($risk['level'] === 'HIGH') {
        $update['kyc_manual_review'] = 1;
    }
    if (db_has_column('users', 'kyc_risk_score')) {
        DB::update('users', $update, 'id = ?', [$userId]);
    }
    return $risk;
}

function kyc_submit(int $userId, array $data): array {
    $errors = [];
    $nid    = clean($data['national_id'] ?? '');
    $bank   = clean($data['bank_account'] ?? '');
    $type   = clean($data['seller_type'] ?? 'personal');
    $store  = clean($data['store_name'] ?? '');

    if (!$nid || !validate_national_id($nid)) {
        $errors['national_id'] = 'کد ملی معتبر ۱۰ رقمی وارد کنید';
    }
    if (!$bank || !preg_match('/^IR?\d{24}$|^(\d{10,30})$/', str_replace([' ', '-'], '', $bank))) {
        $errors['bank_account'] = 'شماره شبا یا حساب بانکی معتبر وارد کنید';
    }
    if (!in_array($type, ['personal', 'store'], true)) {
        $errors['seller_type'] = 'نوع فروشنده نامعتبر است';
    }
    if ($type === 'store' && mb_strlen($store) < 2) {
        $errors['store_name'] = 'نام فروشگاه الزامی است';
    }
    if (empty($data['id_card_image'])) {
        $errors['id_card_image'] = 'تصویر کارت ملی الزامی است';
    }

    $wantsAdvanced = !empty($data['birth_cert_image']) || !empty($data['selfie_image']);
    if ($wantsAdvanced) {
        if (empty($data['birth_cert_image'])) {
            $errors['birth_cert_image'] = 'تصویر شناسنامه برای سطح ۲ الزامی است';
        }
        if (empty($data['selfie_image'])) {
            $errors['selfie_image'] = 'تصویر سلفی برای سطح ۲ الزامی است';
        }
    }

    if (!empty($errors)) {
        return $errors;
    }

    $payload = [
        'national_id'   => $nid,
        'bank_account'  => str_replace([' ', '-'], '', $bank),
        'id_card_image' => $data['id_card_image'],
        'seller_type'   => $type,
        'store_name'    => $type === 'store' ? $store : null,
        'kyc_status'    => 'pending',
        'kyc_level'     => 0,
    ];
    if (!empty($data['birth_cert_image']) && db_has_column('users', 'birth_cert_image')) {
        $payload['birth_cert_image'] = $data['birth_cert_image'];
    }
    if (!empty($data['selfie_image']) && db_has_column('users', 'selfie_image')) {
        $payload['selfie_image'] = $data['selfie_image'];
    }

    DB::update('users', $payload, 'id = ?', [$userId]);
    kyc_persist_risk($userId);

    return [];
}

function kyc_admin_approve(int $userId, string $note = ''): void {
    $user = DB::fetch('SELECT id, birth_cert_image, selfie_image FROM users WHERE id = ?', [$userId]);
    $level = 1;
    if ($user && !empty($user['birth_cert_image']) && !empty($user['selfie_image'])) {
        $level = 2;
    }

    DB::update('users', [
        'kyc_status'         => 'approved',
        'kyc_level'          => $level,
        'kyc_note'           => $note ?: null,
        'kyc_manual_review'  => 0,
        'verification_level' => $level >= 2 ? 3 : 2,
    ], 'id = ?', [$userId]);
}

function kyc_admin_reject(int $userId, string $note): void {
    DB::update('users', [
        'kyc_status' => 'rejected',
        'kyc_level'  => 0,
        'kyc_note'   => $note,
    ], 'id = ?', [$userId]);
}

function kyc_store_status_ui(array $user): array {
    $info   = kyc_public_info($user);
    $status = $info['status'];

    $class = 'store-kyc-status--pending';
    $icon  = 'bi-shield-exclamation';
    $title = 'وضعیت احراز هویت';
    $desc  = $info['label'];

    if (in_array($status, ['IDENTITY_VERIFIED', 'ADVANCED_VERIFIED'], true)) {
        $class = 'store-kyc-status--verified';
        $icon  = 'bi-patch-check-fill';
        $desc  = $status === 'ADVANCED_VERIFIED'
            ? 'احراز سطح ۲ — معاملات با ارزش بالا مجاز است'
            : 'احراز سطح ۱ — کد ملی تأیید شده';
    } elseif ($status === 'IDENTITY_PENDING' || $status === 'MANUAL_REVIEW') {
        $class = 'store-kyc-status--pending';
        $icon  = 'bi-hourglass-split';
        $desc  = $status === 'MANUAL_REVIEW'
            ? 'حساب در حال بررسی دستی تیم امنیت'
            : 'مدارک در انتظار بررسی ادمین';
    } elseif ($status === 'IDENTITY_REJECTED') {
        $class = 'store-kyc-status--rejected';
        $icon  = 'bi-x-circle-fill';
        $desc  = 'مدارک رد شده — لطفاً دوباره ارسال کنید';
    } elseif ($status === 'PHONE_VERIFIED') {
        $class = 'store-kyc-status--pending';
        $icon  = 'bi-phone-fill';
        $desc  = 'موبایل تأیید شده — برای معامله، احراز سطح ۱ را تکمیل کنید';
    } else {
        $class = 'store-kyc-status--pending';
        $icon  = 'bi-shield';
        $desc  = 'احراز هویت تکمیل نشده';
    }

    return compact('class', 'icon', 'title', 'desc', 'info');
}

kyc_run_migrations();
