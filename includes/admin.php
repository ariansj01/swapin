<?php
/**
 * Admin authentication and moderation helpers.
 */

function is_admin_user(?array $user): bool {
    return $user !== null && ($user['role'] ?? 'user') === 'admin';
}

function auth_admin(): ?array {
    $user = auth_user();
    return is_admin_user($user) ? $user : null;
}

function require_admin(): array {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/admin/login.php');
        exit;
    }

    $user = auth_user();
    if (!$user || !is_admin_user($user)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit;
    }

    return $user;
}

function admin_pending_counts(): array {
    $tickets = 0;
    $errors  = 0;
    try {
        $tickets = support_open_ticket_count();
        $errors  = support_new_error_count();
    } catch (Throwable) {}

    $listings = 0;
    $kyc = 0;
    $inspections = 0;
    $disputes = 0;
    $users = 0;

    try {
        $users = (int)(DB::fetch('SELECT COUNT(*) AS c FROM users WHERE is_active = 1')['c'] ?? 0);
    } catch (Throwable) {}

    try {
        if (db_has_column('listings', 'review_status')) {
            $listings = (int)(DB::fetch('SELECT COUNT(*) AS c FROM listings WHERE review_status = "pending" AND status = "active"')['c'] ?? 0);
        }
    } catch (Throwable) {}

    try {
        if (db_has_column('users', 'kyc_status')) {
            $kyc = (int)(DB::fetch('SELECT COUNT(*) AS c FROM users WHERE kyc_status = "pending"')['c'] ?? 0);
        }
    } catch (Throwable) {}

    try {
        if (db_has_table('inspection_requests')) {
            $inspections = (int)(DB::fetch('SELECT COUNT(*) AS c FROM inspection_requests WHERE status IN ("pending","scheduled")')['c'] ?? 0);
        }
    } catch (Throwable) {}

    try {
        if (db_has_table('disputes')) {
            $disputes = (int)(DB::fetch('SELECT COUNT(*) AS c FROM disputes WHERE status IN ("open","reviewing")')['c'] ?? 0);
        }
    } catch (Throwable) {}

    return [
        'listings'    => $listings,
        'kyc'         => $kyc,
        'inspections' => $inspections,
        'disputes'    => $disputes,
        'tickets'     => $tickets + $errors,
        'users'       => $users,
    ];
}

function admin_approve_kyc(int $userId, string $note = ''): void {
    DB::update('users', [
        'kyc_status'         => 'approved',
        'kyc_note'           => $note ?: null,
        'verification_level' => 3,
    ], 'id = ?', [$userId]);
}

function admin_reject_kyc(int $userId, string $note): void {
    DB::update('users', [
        'kyc_status' => 'rejected',
        'kyc_note'   => $note,
    ], 'id = ?', [$userId]);
}

function admin_approve_listing(int $listingId, string $note = ''): void {
    DB::update('listings', [
        'review_status' => 'approved',
        'review_note'   => $note ?: null,
        'updated_at'    => date('Y-m-d H:i:s'),
    ], 'id = ?', [$listingId]);
}

function admin_reject_listing(int $listingId, string $note): void {
    DB::update('listings', [
        'review_status' => 'rejected',
        'review_note'   => $note,
        'updated_at'    => date('Y-m-d H:i:s'),
    ], 'id = ?', [$listingId]);
}

function admin_resolve_inspection(int $requestId, string $result, string $report = ''): bool {
    if (!in_array($result, ['passed', 'failed', 'conditional'], true)) return false;

    $req = DB::fetch('SELECT * FROM inspection_requests WHERE id = ?', [$requestId]);
    if (!$req) return false;

    DB::update('inspection_requests', [
        'status'  => 'done',
        'result'  => $result,
        'report'  => $report ?: null,
        'done_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$requestId]);

    $inspStatus = match ($result) {
        'passed'       => 'approved',
        'failed'       => 'rejected',
        'conditional'  => 'approved',
        default        => 'none',
    };
    DB::update('listings', [
        'inspection_status' => $inspStatus,
    ], 'id = ?', [(int)$req['listing_id']]);

    return true;
}

function admin_resolve_dispute(int $disputeId, string $status, string $note = ''): bool {
    if (!in_array($status, ['resolved_a', 'resolved_b', 'dismissed', 'reviewing'], true)) return false;

    $dispute = DB::fetch('SELECT * FROM disputes WHERE id = ?', [$disputeId]);
    if (!$dispute) return false;

    DB::update('disputes', [
        'status'      => $status,
        'admin_note'  => $note ?: null,
        'resolved_at' => in_array($status, ['resolved_a', 'resolved_b', 'dismissed'], true)
            ? date('Y-m-d H:i:s') : null,
    ], 'id = ?', [$disputeId]);

    if ($status === 'dismissed') {
        DB::update('trades', ['status' => 'in_progress'], 'id = ?', [(int)$dispute['trade_id']]);
    }

    return true;
}

function admin_toggle_user_active(int $userId, bool $active): void {
    DB::update('users', ['is_active' => $active ? 1 : 0], 'id = ? AND role != "admin"', [$userId]);
}

function admin_ensure_default_admin(): void {
    admin_sync_credentials(false);
}

/** Ensure admin role exists; password set only when explicitly requested (CLI + SWAPIN_ADMIN_PASS). */
function admin_sync_credentials(bool $resetPassword = false): void {
    $email = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@kalabkala.com';
    $user  = DB::fetch('SELECT id FROM users WHERE email = ?', [$email]);

    if ($user) {
        $update = ['role' => 'admin', 'is_active' => 1];
        if ($resetPassword) {
            $pass = getenv('SWAPIN_ADMIN_PASS') ?: '';
            if ($pass === '') {
                return;
            }
            $update['password_hash'] = password_hash($pass, PASSWORD_BCRYPT);
        }
        DB::update('users', $update, 'id = ?', [(int)$user['id']]);
        return;
    }

    $pass = getenv('SWAPIN_ADMIN_PASS') ?: '';
    if ($pass === '') {
        return;
    }

    DB::insert('users', [
        'name'               => 'مدیر سیستم',
        'email'              => $email,
        'phone'              => '+989000000001',
        'password_hash'      => password_hash($pass, PASSWORD_BCRYPT),
        'role'               => 'admin',
        'credit_balance'     => 0,
        'verification_level' => 3,
        'is_active'          => 1,
        'kyc_status'         => 'approved',
        'seller_type'        => 'personal',
        'subscription_plan'  => 'none',
    ]);
}
