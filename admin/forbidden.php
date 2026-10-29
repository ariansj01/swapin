<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin.php';
$user = auth_user();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>دسترسی غیرمجاز</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/src/css/main.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/src/css/admin.css">
</head>
<body class="bg-light">
    <div class="container py-5 text-center">
        <div class="card shadow-sm mx-auto" style="max-width: 500px;">
            <div class="card-body p-5">
                <i class="bi bi-exclamation-triangle text-danger display-1 mb-4"></i>
                <h2 class="mb-3">دسترسی محدود شده</h2>
                <p class="text-muted mb-4">
                    شما اجازه دسترسی به این بخش را ندارید. در صورت نیاز با مدیر اصلی سیستم تماس بگیرید.
                </p>
                <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-primary">بازگشت به پیشخوان</a>
            </div>
        </div>
    </div>
</body>
</html>
