<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

rate_limit_ip_or_fail('saved_searches', 60, 3600, true);
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $searches = fetch_user_saved_searches($uid);
    echo json_encode(['ok' => true, 'searches' => $searches], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_verify_or_fail(true);
$action = clean($_POST['action'] ?? 'create');

if ($action === 'create') {
    $result = create_saved_search($uid, $_POST);
    if (isset($result['error'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $result['id']], JSON_UNESCAPED_UNICODE);
    exit;
}

$searchId = (int)($_POST['search_id'] ?? 0);
if (!$searchId) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    delete_saved_search($uid, $searchId);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'toggle_alert') {
    toggle_saved_search_alert($uid, $searchId, !empty($_POST['enabled']));
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
