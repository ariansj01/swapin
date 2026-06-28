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

function persian_month(int $m): string {
    return ['', 'ژانویه', 'فوریه', 'مارس', 'آوریل', 'مه', 'ژوئن', 'ژوئیه', 'اوت', 'سپتامبر', 'اکتبر', 'نوامبر', 'دسامبر'][$m] ?? '';
}

function persian_date(string $datetime): string {
    $ts = strtotime($datetime);
    return persian_month((int)date('n', $ts)) . ' ' . date('j', $ts) . '، ' . date('Y', $ts);
}

function persian_datetime(string $datetime): string {
    $ts = strtotime($datetime);
    return persian_date($datetime) . ' — ' . date('G:i', $ts);
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
