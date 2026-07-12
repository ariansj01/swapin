<?php
// Persian UI helpers — Swapin (سواپین)

function fmt_credit(float $amount, bool $withUnit = true): string {
    $n = number_format($amount, 0);
    return $withUnit ? $n . ' ' . CREDIT_UNIT : $n;
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
        'electronics'     => 'الکترونیک',
        'clothing'        => 'پوشاک',
        'home-garden'     => 'خانه و باغ',
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
  return persian_jalali_month($jm) . ' ' . $jd . '، ' . $jy;
}

/**
 * Format datetime as Persian (Jalali) date and time
 * @param string|int $datetime DateTime string or timestamp
 * @return string Formatted Persian datetime
 */
function persian_datetime(string|int $datetime): string {
  $timestamp = is_int($datetime) ? $datetime : strtotime($datetime);
  [$jy, $jm, $jd] = gregorian_to_jalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
  return persian_jalali_month($jm) . ' ' . $jd . '، ' . $jy . ' — ' . date('G:i', $timestamp);
}

function shipping_label(string $method): string {
  return match ($method) {
    'in_person' => 'تحویل حضوری',
    'post'      => 'پست',
    'tipax'     => 'تیپاکس',
    'courier'   => 'پیک',
    default     => $method,
  };
}
