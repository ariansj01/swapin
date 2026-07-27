<?php
require_once __DIR__ . '/includes/config.php';

echo "<h1 style='font-family:tahoma'>تست دقیق شبیه‌سازی listings/create.php مرحله ۳</h1>";

// دقیقاً همان کوئری $categories که در create.php خط 33-37 اجرا می‌شود
$categories = DB::fetchAll(
    'SELECT c.*, p.name AS parent_name FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active = 1 ORDER BY COALESCE(p.sort_order,c.sort_order), c.sort_order'
);

echo "<h2>الف) خروجی تابع render_wizard_category_options که در create.php فراخوانی می‌شود:</h2>";
$wizard_html = render_wizard_category_options($categories, 0);
echo "<select style='width:400px;padding:10px;font-size:14px' size='25'>"
     . "<option value=''>انتخاب دسته‌بندی…</option>"
     . $wizard_html
     . "</select>";

echo "<h2>ب) سورس HTML خام همان تابع:</h2>";
echo "<pre style='background:#222;color:#0f0;padding:10px;font-size:12px;overflow:auto'>"
     . htmlspecialchars($wizard_html, ENT_QUOTES, 'UTF-8')
     . "</pre>";

echo "<h2>پ) تعداد کل optgroup (باید دقیقاً ۱۰ تا باشد):</h2>";
preg_match_all('/<optgroup/', $wizard_html, $m);
echo "<p style='font-size:20px;font-weight:bold'>تعداد پیدا شده: " . count($m[0]) . " تا — ";
if (count($m[0]) === 10) {
    echo "<span style='color:green'>✅ درست است</span>";
} else {
    echo "<span style='color:red'>❌ اشتباه! باید ۱۰ باشد</span>";
}
echo "</p>";

echo "<h2>ت) آیا فایل create.php روی سرور همین تابع را صدا می‌زند؟</h2>";
$createFile = __DIR__ . '/listings/create.php';
if (file_exists($createFile)) {
    $src = file_get_contents($createFile);
    $callMatch = preg_match('/render_wizard_category_options\s*\(/', $src, $mm);
    if ($callMatch) {
        echo "<p style='color:green;font-size:18px'>✅ بله، فایل create.php تابع render_wizard_category_options را فراخوانی می‌کند.</p>";
    } else {
        echo "<p style='color:red;font-size:20px;font-weight:bold'>❌ اشتباه! فایل create.php روی سرور هنوز از حلقه دستی قدیمی استفاده می‌کند و render_wizard_category_options را صدا نمی‌زند.<br>راه‌حل: فایل listings/create.php نسخه جدید را دوباره روی سرور آپلود کنید.</p>";
    }

    echo "<h3>خطوط مربوط به دسته‌بندی در create.php سرور:</h3>";
    preg_match('/<select[^>]*name="category_id"[^>]*>.*?<\/select>/s', $src, $sel);
    if (!empty($sel)) {
        echo "<pre style='background:#f8f8f8;padding:10px;border:1px solid #ccc'>" . htmlspecialchars($sel[0], ENT_QUOTES, 'UTF-8') . "</pre>";
    }
} else {
    echo "<p style='color:red'>❌ فایل listings/create.php پیدا نشد!</p>";
}

echo "<h2>ث) وضعیت کش OPCache:</h2>";
if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    if ($status && !empty($status['opcache_enabled'])) {
        echo "<p style='color:orange'>⚠️ OPCache فعال است. اگر فایل i18n.php را آپدیت کرده‌اید ولی تغییرات را نمی‌بینید باید کش را پاک کنید.</p>";
        $scripts = $status['scripts'] ?? [];
        $i18nKey = null;
        foreach (array_keys($scripts) as $k) {
            if (stripos($k, 'i18n.php') !== false) {
                $i18nKey = $k;
                break;
            }
        }
        if ($i18nKey) {
            $info = $scripts[$i18nKey];
            echo "<p>مسیر کش شده: <code>" . htmlspecialchars($i18nKey) . "</code></p>";
            echo "<p>آخرین تغییر روی فایل کش‌شده: " . date('Y-m-d H:i:s', $info['mtime'] ?? 0) . "</p>";
            echo "<p>آخرین آپدیت شما فایل: باید جدیدتر از بالا باشد!</p>";
        }
        echo "<hr><p><strong>برای پاک کردن کش OPCache:</strong></p><ol>";
        echo "<li>اگر از cPanel استفاده می‌کنید: Software → Select PHP Version → OPCache → Reset</li>";
        echo "<li>اگر SSH دارید: سرویس PHP-FPM را ری‌استارت کنید (مثلاً: systemctl restart php8.1-fpm)</li>";
        echo "<li>یا یک فایل tmp_cache_clear.php بسازید و یک‌بار باز کنید:</li>";
        echo "</ol>";
        echo "<pre style='background:#eee;padding:5px'>&lt;?php opcache_reset(); echo 'OPCache reset شد.';</pre>";
    } else {
        echo "<p style='color:green'>✅ OPCache فعال نیست یا کش خالی است — پس مشکل از کش نیست.</p>";
    }
} else {
    echo "<p style='color:green'>✅ توابع OPCache موجود نیست — پس مشکل از کش نیست.</p>";
}

echo "<hr><h2 style='color:blue'>🎯 نتیجه نهایی تشخیص:</h2>";
$diagnosis = [];
if (count($m[0]) !== 10) $diagnosis[] = 'تابع رندرینگ اشتباه کار می‌کند (باید ۱۰ دسته نمایش دهد)';
if (empty($callMatch)) $diagnosis[] = 'فایل create.php روی سرور قدیمی است — تابع جدید را نمی‌زند';
if (!empty($i18nKey)) $diagnosis[] = 'OPCache فعال است — احتمالاً نسخه قدیمی i18n.php را سرو می‌کند';
if (empty($diagnosis)) {
    echo "<p style='color:green;font-size:18px'>✅ همه چیز از نظر کد درست است! اگر همچنان در صفحه واقعی اشتباه را می‌بینید:</p><ol>";
    echo "<li><strong>کش مرورگر را Clear کنید (Ctrl+Shift+Delete)</strong></li>";
    echo "<li>یا در Chrome/Edge F12 بزنید تب Network را باز کنید و تیک Disable Cache را بزنید و صفحه را refresh کنید</li>";
    echo "<li>اگر همچنان اشتباه بود، صفحه create.php را باز کنید و Ctrl+U کنید (View Source) — جستجو کنید برای optgroup — آن optgroup ها که در سورس HTML هستند دقیقاً همان چیزی است که مرورگر نمایش می‌دهد. اگر در سورس HTML ۱۰ دسته درست بود ولی مرورگر چیز دیگری نشان می‌دهد → مشکل از CSS/JS است که احتمالاً dropdown را جایگزین می‌کند.</li>";
    echo "</ol>";
} else {
    foreach ($diagnosis as $i => $d) {
        echo "<p style='color:red;font-size:18px'>" . ($i+1) . ". {$d}</p>";
    }
}
