<?php
// بررسی انطباق دسته‌بندی آگهی‌ها با عنوان و توضیحات

$host = 'localhost';
$dbnames = ['swapin', 'kala_b_kala'];
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$pdo = null;
foreach ($dbnames as $dbname) {
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5]);
        break;
    } catch (PDOException $e) {
        try {
            $pdo = new PDO($dsn, $user, 'zqesB)3t9k1s%zCN', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5]);
            break;
        } catch (PDOException $e2) { $pdo = null; }
    }
}
if (!$pdo) die("❌ اتصال دیتابیس ناموفق\n");

// گرفتن دسته‌بندی‌ها
$cats = $pdo->query("SELECT id, name, parent_id FROM categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$catMap = [];
$catKeywords = [];
foreach ($cats as $c) {
    $catMap[$c['id']] = $c;
    // استخراج کلمات کلیدی از نام دسته
    $kw = preg_split('/[\s,،\-\/()]+/u', $c['name'], -1, PREG_SPLIT_NO_EMPTY);
    $catKeywords[$c['id']] = $kw;
}

echo "دسته‌بندی‌ها:\n";
foreach ($cats as $c) {
    $p = $c['parent_id'] ? " (زیرمجموعه #{$c['parent_id']} {$catMap[$c['parent_id']]['name']})" : '';
    echo "  #{$c['id']}: {$c['name']}$p\n";
}
echo "\n";

// گرفتن آگهی‌های اخیر
$ads = $pdo->query("SELECT l.id, l.title, l.description, l.category_id 
                    FROM listings l ORDER BY l.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);

echo "بررسی " . count($ads) . " آگهی برای انطباق دسته‌بندی...\n\n";
echo str_repeat("═", 110) . "\n";
printf("%-5s | %-35s | %-25s | %s\n", "ID", "عنوان (خلاصه)", "دسته فعلی", "مشکوک / پیشنهاد");
echo str_repeat("─", 110) . "\n";

// واژگان کلیدی برای تشخیص دسته (ساده)
$categoryHints = [
    1  => ['املاک','آپارتمان','خانه','ویلا','زمین','ملک','پارکینگ','متراژ','طبقه','رهن','اجاره','خرید','فروش خانه','باغچه','نقشه'],
    2  => ['خودرو','پراید','پژو','سان','تافا','بنز','بی‌ام‌و','سمند','کوییک','سانتافه','مزدا','هیوندای','ماشین','خودروی'],
    3  => ['موتور','موتورسیکلت','هوندا','یاماها','اندرویدس','برلی','کویر','موتوری','دوچرخه موتوری'],
    7  => ['موبایل','گوشی','آیفون','سامسونگ','سنگ','ردمی','شیائومی','اپل','galaxy','s23','s24','نوکیا','گوگل','موبایلی'],
    8  => ['لپتاپ','لپ تاپ','لپ‌تاپ','مک بوک','مک‌بوک','lenovo','thinkpad','ایسوس','اچ‌پی','hp','dell','acer','کامپیوتر','pc'],
    9  => ['دوربین','کانن','سونی','نیکون','canon','nikon','عکاسی','لنز','عدسی','دوربینی'],
    14 => ['استخدام','کاری','کارمند','تعمیرکار','حمل و نقل','حمل بار','کارگر','دستیار','خدمات','نرم‌افزار','برنامه‌نویس'],
    32 => ['لباس','لباس','پیراهن','شلوار','کت','ست','کوکتل','مانتو','پوشاک','کفش','کیف','اکسسوری','لباسی'],
    15 => ['کتاب','درسی','ریاضی','فیزیک','شیمی','زبان','فارسی','نگارش','نشر','مدرسه','دانشگاه'],
    29 => ['اسباب بازی','گوشی‌اسباب','ترکیبی','دوچرخه‌کودک','اویان','اسباب‌بازی'],
    16 => ['صوتی','تصویری','سینما','تلویزیون','آمپلی‌فایر','ساندبار','ساندبار','هدست','هدفون','تلویزیونی','سینمایی'],
    17 => ['آلات موسیقی','گیتار','ویولن','سنتور','پیانو','درام','تمبور','کوزه','نی','ساز'],
    10 => ['لوازم خانگی','یخچال','کولر','گازی','آبگرمکن','لباسشویی','ظرفشویی','ماشین لباس','ماشین ظرف','جاروبرقی','آسپره','خانگی','آبگرم'],
    18 => ['صنعت','ابزار','آجرآلت','پمپ','سرام','مته','سوراخ کن','صنعتی','آلات'],
    19 => ['حیوانات','گاو','گوسفند','گوسفندی','گاوصندوق','گاوشیرده','طاووس','هندی','خرگوش','عروس هلندی','خروس','پرندگان','دام','پرورش','گوسفندی','گاوشیرده'],
    33 => ['باغچه','گیاه','گل','پوشاک','گیاهان','شکوفه','درختچه','اپارتمانی','باغبانی'],
    20 => ['ورزشی','تناسب اندام','وزنه','چرخ','توپ','تاپیر','دوچرخه','کوهپیمایی','ورزشی','اسکیت','کوهنوردی'],
    21 => ['سفر','تور','هتل','مسافرت','اقامت','سیاحتی','سفره','تور'],
    22 => ['خدمات','سرویس','تعمیر','ماشینآلات','درگاه','آموزش','موسیقی آموزشی','خدماتی'],
    31 => ['خوراکی','نان','شکلات','شربت','غذایی','نوشیدنی','عناب','روشنا','خوراک','غذا','داروگیاهی','کمک‌درمانی'],
    34 => ['وسایل شخصی','ساعت','عینک','عطر','جیب‌کاغذی','اسکناس‌دست','دستبند','گردنبند','شخصی','اکسسوری'],
];

$suspects = [];

foreach ($ads as $a) {
    $lid = $a['id'];
    $title = $a['title'];
    $desc  = $a['description'];
    $cid   = $a['category_id'];
    $cname = $catMap[$cid]['name'] ?? "ناشناخته#$cid";

    $text = $title . ' ' . $desc;
    $matchedCats = [];
    foreach ($categoryHints as $hcid => $kws) {
        $score = 0;
        foreach ($kws as $kw) {
            if (mb_stripos($text, $kw) !== false) $score++;
        }
        if ($score >= 1) $matchedCats[$hcid] = $score;
    }
    arsort($matchedCats);

    $topCats = array_slice(array_keys($matchedCats), 0, 3, true);
    $topScores = array_slice($matchedCats, 0, 3, true);

    $isSuspect = false;
    $suggestion = '';
    if (!empty($matchedCats)) {
        $bestCid = key($matchedCats);
        $bestScore = current($matchedCats);
        $currentScore = $matchedCats[$cid] ?? 0;
        if ($bestCid !== $cid && $bestScore >= $currentScore + 1) {
            $isSuspect = true;
            $bestName = $catMap[$bestCid]['name'] ?? "#$bestCid";
            $suggestion = "اشتباه! → پیشنهاد #$bestCid $bestName";
        } elseif ($currentScore === 0 && $bestScore >= 1) {
            $isSuspect = true;
            $bestName = $catMap[$bestCid]['name'] ?? "#$bestCid";
            $suggestion = "عدم تطابق! بهتر: #$bestCid $bestName";
        }
    }

    if ($isSuspect) {
        $suspects[] = [
            'id' => $lid,
            'title' => $title,
            'desc' => $desc,
            'cur_cat' => $cid,
            'cur_cat_name' => $cname,
            'matched' => $matchedCats,
            'suggestion' => $suggestion,
        ];
    }

    $shortTitle = mb_substr($title, 0, 33);
    $shortCat = mb_substr($cname, 0, 23);
    $flag = $isSuspect ? "⚠️  $suggestion" : "✅";
    printf("%-5s | %-35s | %-25s | %s\n", $lid, $shortTitle, $shortCat, $flag);
}

echo str_repeat("═", 110) . "\n";
echo "\n⚠️  مجموع موارد مشکوک: " . count($suspects) . " مورد\n\n";

if ($suspects) {
    echo "═══ گزارش تفصیلی موارد مشکوک ═══\n\n";
    foreach ($suspects as $s) {
        echo "────────────────────────────────────────\n";
        echo "آگهی #{$s['id']}\n";
        echo "  عنوان : {$s['title']}\n";
        echo "  توضیح: " . mb_substr($s['desc'], 0, 120) . "\n";
        echo "  دسته فعلی  : #{$s['cur_cat']} {$s['cur_cat_name']}\n";
        echo "  {$s['suggestion']}\n";
        $scoreStr = [];
        foreach (array_slice($s['matched'], 0, 5, true) as $hcid => $sc) {
            $n = $catMap[$hcid]['name'] ?? "#$hcid";
            $scoreStr[] = "#$hcid $n($sc)";
        }
        echo "  تطبیقات: " . implode(' | ', $scoreStr) . "\n";
    }
}
