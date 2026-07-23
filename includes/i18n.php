<?php
// Persian UI helpers — Swapin (سواپین)

function persian_digits(string $str): string {
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        $str
    );
}

function fmt_num(int|float $number, int $decimals = 0): string {
    return persian_digits(number_format($number, $decimals));
}

function fmt_credit(float $amount, bool $withUnit = true): string {
    $n = fmt_num($amount, 0);
    return $withUnit ? $n . ' ' . CREDIT_UNIT : $n;
}

/** First visible letter of a name (UTF-8 safe). Empty if not a letter. */
function user_initial(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $ch = mb_substr($name, 0, 1, 'UTF-8');
    if (preg_match('/^[a-zA-Z]$/u', $ch)) {
        return mb_strtoupper($ch, 'UTF-8');
    }
    // Persian / Arabic letters
    if (preg_match('/^[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]$/u', $ch)) {
        return $ch;
    }
    return '';
}

/** Avatar markup: photo, letter initial, or person icon (never broken "?"). */
function avatar_html(?string $avatar, string $name, string $size = 'md'): string {
    $sizeClass = 'avatar-' . preg_replace('/[^a-z]/', '', $size);
    if ($avatar) {
        return '<img class="avatar ' . $sizeClass . ' avatar--img" src="' . UPLOAD_URL . h($avatar) . '" alt="' . h($name) . '">';
    }
    $initial = user_initial($name);
    if ($initial !== '') {
        return '<div class="avatar ' . $sizeClass . '" aria-hidden="true">' . h($initial) . '</div>';
    }
    return '<div class="avatar ' . $sizeClass . ' avatar--icon" aria-hidden="true"><i class="bi bi-person-fill"></i></div>';
}

function condition_label(string $cond): string {
    return match ($cond) {
        'new'      => 'نو',
        'like_new' => 'در حد نو',
        'good'     => 'خوب',
        'fair'     => 'متوسط',
        'poor'     => 'ضعیف',
        default    => $cond,
    };
}

function category_label(string $slug, string $name = ''): string {
    $labels = [
        'electronics'     => 'دیجیتال',
        'clothing'        => 'پوشاک',
        'home-garden'     => 'خانه و ویلا',
        'books-media'     => 'کتاب و رسانه',
        'sports'          => 'ورزش',
        'toys-games'      => 'اسباب‌بازی و بازی',
        'vehicles'        => 'خودرو',
        'services'        => 'خدمات',
        'food-drink'      => 'غذا و نوشیدنی',
        'other'           => 'سایر',
        'phones'          => 'موبایل و تلفن',
        'laptops'         => 'لپ‌تاپ',
        'cameras'         => 'دوربین',
        'audio'           => 'صوتی',
        'gaming'          => 'گیم و کنسول',
        'mens-clothing'   => 'لباس مردانه',
        'womens-clothing' => 'لباس زنانه',
        'shoes'           => 'کفش',
        'furniture'       => 'مبلمان',
        'kitchen'         => 'آشپزخانه',
        'garden'          => 'باغ و فضای سبز',
        'books'           => 'کتاب',
        'movies'          => 'فیلم',
        'music'           => 'موسیقی',
        'tutoring'        => 'آموزش و تدریس',
        'repair-fix'      => 'تعمیرات',
        'creative'        => 'خلاقیت و هنر',
        'transport'       => 'حمل و نقل',
    ];

    return $labels[$slug] ?? ($name ?: $slug);
}

function category_url(string $slug): string {
    $urlMap = [
        'electronics' => '/category/digital',
        'home-garden' => '/category/home-and-villa',
    ];
    if (isset($urlMap[$slug])) {
        return APP_URL . $urlMap[$slug];
    }
    return APP_URL . '/category/' . $slug;
}

function want_type_label(string $type): string {
    return match ($type) {
        'item'    => 'کالا',
        'service' => 'خدمات',
        'credit'  => 'اعتبار',
        'any'     => 'هر نوع',
        default   => $type,
    };
}

function offer_status_label(string $status): string {
    return match ($status) {
        'pending'   => 'در انتظار',
        'accepted'  => 'پذیرفته‌شده',
        'rejected'  => 'رد شده',
        'cancelled' => 'لغو شده',
        'completed' => 'تکمیل‌شده',
        default     => $status,
    };
}

function trade_status_label(string $status): string {
    return match ($status) {
        'in_progress'     => 'در حال انجام',
        'user_a_confirmed' => 'تأیید طرف اول',
        'user_b_confirmed' => 'تأیید طرف دوم',
        'disputed'        => 'اختلاف',
        'completed'       => 'تکمیل‌شده',
        default           => $status,
    };
}

function tx_type_label(string $type): array {
    return match ($type) {
        'deposit'      => ['arrow-down-circle', 'واریز', 'success'],
        'withdraw'     => ['arrow-up-circle', 'برداشت', 'danger'],
        'trade_credit' => ['arrow-down-circle', 'دریافت معامله', 'success'],
        'trade_debit'  => ['arrow-up-circle', 'پرداخت معامله', 'danger'],
        'fee'          => ['dash-circle', 'کارمزد', 'warning'],
        'refund'       => ['arrow-counterclockwise', 'بازگشت وجه', 'info'],
        default        => ['circle', 'تراکنش', 'info'],
    };
}



/**
 * Convert Gregorian date to Jalali (Persian) date
 * Source: https://github.com/sallar/jDateTime/blob/master/src/jDateTime.php
 * @param int $gy Gregorian year
 * @param int $gm Gregorian month
 * @param int $gd Gregorian day
 * @return array [year, month, day] in Jalali
 */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $jm = 1;
    $j_d_m = [0, 31, 62, 93, 124, 155, 186, 216, 246, 276, 306, 336];
    while ($jm < 12 && $days >= $j_d_m[$jm]) $jm++;
    $jd = 1 + $days - $j_d_m[$jm - 1];
    return [$jy, $jm, $jd];
}

/**
 * Get Persian (Jalali) month name
 * @param int $month Month number (1-12)
 * @return string Month name in Persian
 */
function persian_jalali_month(int $month): string {
  return [
    '', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد',
    'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
  ][$month] ?? '';
}

/**
 * Format date as Persian (Jalali) date
 * @param string|int $datetime DateTime string or timestamp
 * @return string Formatted Persian date
 */
function persian_date(string|int $datetime): string {
  $timestamp = is_int($datetime) ? $datetime : strtotime($datetime);
  [$jy, $jm, $jd] = gregorian_to_jalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
  return persian_digits(persian_jalali_month($jm) . ' ' . $jd . '، ' . $jy);
}

/**
 * Format datetime as Persian (Jalali) date and time
 * @param string|int $datetime DateTime string or timestamp
 * @return string Formatted Persian datetime
 */
function persian_datetime(string|int $datetime): string {
  $timestamp = is_int($datetime) ? $datetime : strtotime($datetime);
  [$jy, $jm, $jd] = gregorian_to_jalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
  return persian_digits(persian_jalali_month($jm) . ' ' . $jd . '، ' . $jy . ' — ' . date('G:i', $timestamp));
}

/**
 * Convert Jalali (Persian) date to Gregorian
 * @param int $jy Jalali year
 * @param int $jm Jalali month
 * @param int $jd Jalali day
 * @return array [year, month, day] in Gregorian
 */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array {
  $jy += 1595;
  $days = -355668 + (365 * $jy) + ((int)($jy / 33)) * 8 + ((int)((($jy % 33) + 3) / 4)) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
  $gy = 400 * ((int)($days / 146097));
  $days %= 146097;
  if ($days > 36524) {
    $gy += 100 * ((int)(--$days / 36524));
    $days %= 36524;
    if ($days >= 365) $days++;
  }
  $gy += 4 * ((int)($days / 1461));
  $days %= 1461;
  if ($days > 365) {
    $gy += (int)(($days - 1) / 365);
    $days = ($days - 1) % 365;
  }
  $gd = $days + 1;
  $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  $gm = 0;
  while ($gm < 13 && $gd > $sal_a[$gm]) {
    $gd -= $sal_a[$gm++];
  }
  return [$gy, $gm, $gd];
}

/**
 * Parse Jalali date input (e.g. 1404/04/23) to Gregorian Y-m-d for DB storage.
 */
function parse_jalali_date_input(string $input): ?string {
    $input = trim(str_replace(['-', '.'], '/', $input));
    $input = strtr($input, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $input = strtr($input, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $input, $m)) {
        return null;
    }
    [$gy, $gm, $gd] = jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
    if ($gm < 1 || $gm > 12 || $gd < 1 || $gd > 31) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * Parse shipping date from Jalali (1404/04/23) or Gregorian (2025-07-13) input.
 */
function parse_shipping_date_input(string $input): ?string {
    $jalali = parse_jalali_date_input($input);
    if ($jalali) {
        return $jalali;
    }
    $input = trim($input);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        return $input;
    }
    return null;
}

function shipping_label(string $method): string {
  return match ($method) {
    'in_person' => 'تحویل حضوری',
    'post'      => 'پست',
    'tipax'     => 'تیپاکس',
    'courier'   => 'پیک',
    'swapin_secure' => 'تحویل حضوری (ارسال در محل)',
    default     => $method,
  };
}
