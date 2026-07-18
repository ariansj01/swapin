<?php
require_once __DIR__ . '/../includes/config.php';

$redir = safe_redirect_path(clean($_POST['redirect'] ?? $_GET['redirect'] ?? ''));
$loginUrl = APP_URL . '/auth/login' . ($redir ? '?redirect=' . urlencode($redir) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $loginUrl);
    exit;
}

if (!google_login_enabled()) {
    $_SESSION['auth_error'] = 'ورود با گوگل در حال حاضر فعال نیست.';
    header('Location: ' . $loginUrl);
    exit;
}

if (!csrf_verify()) {
    $_SESSION['auth_error'] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    header('Location: ' . $loginUrl);
    exit;
}

$limit = rate_limit_ip_status('google_login', 20, 900);
if (!$limit['allowed']) {
    $_SESSION['auth_error'] = 'تعداد تلاش‌های ورود زیاد شده است. چند دقیقه دیگر دوباره تلاش کنید.';
    header('Location: ' . $loginUrl);
    exit;
}
rate_limit_ip('google_login', 20, 900);

$credential = trim((string) ($_POST['credential'] ?? ''));
if ($credential === '') {
    $_SESSION['auth_error'] = 'ورود با گوگل کامل نشد. لطفاً دوباره تلاش کنید.';
    header('Location: ' . $loginUrl);
    exit;
}

try {
    $claims = google_verify_id_token($credential);
    if (!$claims) {
        throw new RuntimeException('اعتبارسنجی حساب گوگل انجام نشد. لطفاً دوباره تلاش کنید.');
    }

    $result = google_find_or_create_user($claims);
    login_user((int) $result['user_id']);

    unset($_SESSION['auth_error'], $_SESSION['otp_phone_raw'], $_SESSION['otp_phone_intl'], $_SESSION['last_otp_send']);

    $dest = $redir
        ? APP_URL . $redir
        : APP_URL . ((bool) ($result['is_new'] ?? false) ? '/?welcome=1' : '/');
    header('Location: ' . $dest);
    exit;
} catch (Throwable $e) {
    swapin_debug_log('google-login-failed', [
        'message' => $e->getMessage(),
        'ip' => client_ip(),
    ]);
    $_SESSION['auth_error'] = $e->getMessage() ?: 'ورود با گوگل ناموفق بود.';
    header('Location: ' . $loginUrl);
    exit;
}
