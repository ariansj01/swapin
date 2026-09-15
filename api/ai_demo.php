<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ai_demo.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

csrf_verify_or_fail(true);
rate_limit_ip_or_fail('ai_demo', 40, 3600, true);

$mode = trim((string) ($_POST['mode'] ?? ''));
$allowed = ['moderate', 'pricing', 'chat', 'need_search', 'matching', 'swap'];
if (!in_array($mode, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_mode'], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_start();
try {
    $out = match ($mode) {
        'moderate' => ai_demo_run_moderate($_POST),
        'pricing' => ai_demo_run_pricing($_POST),
        'chat' => ai_demo_run_chat(
            (string) ($_POST['message'] ?? ''),
            json_decode((string) ($_POST['history'] ?? '[]'), true) ?: []
        ),
        'need_search' => ai_demo_run_need_search(
            (string) ($_POST['need'] ?? ''),
            (string) ($_POST['city'] ?? '')
        ),
        'matching' => ai_demo_run_matching($_POST),
        'swap' => ai_demo_run_swap($_POST),
    };
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'demo_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}
ob_end_clean();

if (empty($out['ok'])) {
    http_response_code(400);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
