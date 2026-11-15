<?php
// api/catalog.php
// برند/مدل/تیپ برای فهرست انتخاب فرم ثبت آگهی (سند معماری سئو — بخش ۱، ۲ و ۶).
//   ?cat={categoryId}                      → برندها
//   ?cat={categoryId}&brand={brandId}      → مدل‌های همان برند
//   ?cat={categoryId}&brand=&model={modelId} → تیپ‌های همان مدل

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function catalog_api_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    catalog_api_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

rate_limit_ip_or_fail('catalog_lookup', 240, 3600, true);

$catId   = (int) ($_GET['cat'] ?? 0);
$brandId = (int) ($_GET['brand'] ?? 0);
$modelId = (int) ($_GET['model'] ?? 0);

if ($catId <= 0) {
    catalog_api_json(['ok' => false, 'error' => 'category_required'], 422);
}

$brands = swaapin_catalog_brands($catId);
if ($brands === []) {
    catalog_api_json(['ok' => true, 'brands' => [], 'models' => [], 'trims' => []]);
}

$models = [];
if ($brandId > 0 && swaapin_catalog_node_by_id($brandId, $catId, 'brand')) {
    $models = swaapin_catalog_children($brandId, 'model');
}

$trims = [];
if ($modelId > 0 && in_array($modelId, array_map('intval', array_column($models, 'id')), true)) {
    $trims = swaapin_catalog_children($modelId, 'trim');
}

catalog_api_json([
    'ok'     => true,
    'brands' => $brands,
    'models' => $models,
    'trims'  => $trims,
]);
