<?php
require_once __DIR__ . '/../includes/config.php';

$user = require_auth();
if ($user['onboarding_completed']) {
    $redir = safe_redirect_path(clean($_GET['redirect'] ?? ''));
    header('Location: ' . ($redir ? APP_URL . $redir : APP_URL . '/dashboard'));
    exit;
}

header('Location: ' . APP_URL . '/listings/create.php');
exit;
