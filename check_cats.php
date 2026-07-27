<?php
require_once __DIR__ . '/includes/config.php';

echo "<h2>1) خروجی مستقیم تابع render_wizard_category_options:</h2>";
echo "<pre style='background:#f5f5f5;padding:10px;border:1px solid #ccc'>"
     . htmlspecialchars(render_wizard_category_options([], 0), ENT_QUOTES, 'UTF-8')
     . "</pre>";

echo "<h2>2) خروجی HTML رندرشده (همان چیزی که کاربر می‌بیند):</h2>";
echo "<select size='30' style='width:400px;padding:10px'>"
     . render_wizard_category_options([], 0)
     . "</select>";

echo "<h2>3) وضعیت جدول categories — دسته‌های والد:</h2>";
$rows = DB::fetchAll('SELECT id, parent_id, name, slug, is_active, sort_order FROM categories WHERE (parent_id IS NULL OR parent_id = 0) ORDER BY sort_order, id');
echo "<table border='1' cellpadding='8'><tr><th>ID</th><th>parent_id</th><th>name (DB)</th><th>slug</th><th>is_active</th><th>sort_order</th><th>category_label()</th></tr>";
foreach ($rows as $r) {
    echo "<tr>
        <td>{$r['id']}</td>
        <td>" . var_export($r['parent_id'], true) . "</td>
        <td>" . htmlspecialchars($r['name']) . "</td>
        <td>{$r['slug']}</td>
        <td>" . ($r['is_active'] ? '<span style="color:green">1✅</span>' : '<span style="color:red">0❌</span>') . "</td>
        <td>{$r['sort_order']}</td>
        <td>" . htmlspecialchars(category_label($r['slug'], $r['name'])) . "</td>
    </tr>";
}
echo "</table>";

echo "<h2>4) ده اسلاگ مورد نظر ما — آیا در جدول وجود دارند؟</h2>";
$wanted = ['electronics','clothing','home-garden','books-media','sports','toys-games','vehicles','services','food-drink','home-appliances'];
$wantedLabels = ['دیجیتال','پوشاک','خانه و ویلا','کتاب و رسانه','ورزش','اسباب‌بازی و بازی','خودرو','خدمات','غذا و نوشیدنی','لوازم خانگی'];
echo "<table border='1' cellpadding='8'><tr><th>#</th><th>اسلاگ</th><th>لیبل مورد انتظار</th><th>وضعیت در DB</th></tr>";
foreach ($wanted as $i => $slug) {
    $found = DB::fetch("SELECT id, name, is_active, parent_id FROM categories WHERE (parent_id IS NULL OR parent_id = 0) AND slug = ?", [$slug]);
    if ($found) {
        $status = ($found['is_active'] ? '<span style="color:green">FOUND & ACTIVE ✅</span>' : '<span style="color:orange">FOUND BUT INACTIVE ⚠️</span>')
                . " (id={$found['id']}, name=" . htmlspecialchars($found['name']) . ")";
    } else {
        $status = '<span style="color:red;font-weight:bold">MISSING ❌</span>';
    }
    echo "<tr><td>" . ($i+1) . "</td><td>{$slug}</td><td>{$wantedLabels[$i]}</td><td>{$status}</td></tr>";
}
echo "</table>";

echo "<h2>5) آخرین لاگ‌های swapin-debug:</h2>";
$logFile = __DIR__ . '/storage/logs/error.log';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $debug = [];
    foreach ($lines as $line) {
        if (strpos($line, 'swapin-debug') !== false && (strpos($line, 'wizard_cat') !== false || strpos($line, 'migration_cat') !== false)) {
            $debug[] = $line;
        }
    }
    $debug = array_slice($debug, -30);
    echo "<pre style='background:#000;color:#0f0;padding:10px'>" . htmlspecialchars(implode("\n", $debug), ENT_QUOTES, 'UTF-8') . "</pre>";
    if (empty($debug)) {
        echo "<p style='color:orange'>⚠️ هیچ لاگ wizard_cat یا migration_cat یافت نشد — یعنی تابع wizard_ensure_parents_exist هرگز اجرا نشده!</p>";
    }
} else {
    echo "<p style='color:red'>❌ فایل لاگ پیدا نشد: " . htmlspecialchars($logFile) . "</p>";
}
