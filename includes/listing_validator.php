<?php
/**
 * Listing content validation — regex, bad words, nonsense detection.
 */

function listing_bad_words(): array {
    return [
        'کس', 'کیر', 'جنده', 'حروم', 'لاشی', 'سگ', 'گوه',
        'fuck', 'shit', 'bitch', 'asshole', 'porn', 'xxx',
        'کلاهبرداری', 'ponzi', 'هرزنامه', 'اسکم', 'scam',
        'سود تضمینی', 'ضمانت سود', 'پولدار شو', 'میلیونر شو',
        'رایگان ۱۰۰', 'رایگان 100', 'هک', 'hack', 'crack',
    ];
}

function listing_nonsense_patterns(): array {
    return [
        '/لورم\s*ایپس/u'                          => 'متن آزمایشی (لورم ایپس) مجاز نیست',
        '/\b(?:asdf|qwerty|zxcv|test123|abc123)\b/iu' => 'متن آگهی نامعتبر یا آزمایشی است',
        '/(?:^|\s)(?:test|تست)(?:\s|$)/iu'        => 'عنوان یا توضیحات آزمایشی پذیرفته نمی‌شود',
        '/(.)\1{6,}/u'                            => 'تکرار بیش از حد یک کاراکتر مجاز نیست',
        '/^[\d\s\p{P}\p{S}]+$/u'                  => 'متن باید شامل حروف معتبر باشد',
        '/(?:یک\s*میلیارد|صد\s*در\s*صد\s*سود)/u' => 'ادعاهای غیرواقعی یا کلاهبرداری ممنوع است',
    ];
}

function validate_listing_content(array $fields): array {
    $errors = [];
    $title       = trim($fields['title'] ?? '');
    $description = trim($fields['description'] ?? '');
    $want        = trim($fields['want_in_return'] ?? '');
    $combined    = "$title $description $want";

    // ── Title ───────────────────────────────────────────────────────────────
    if (!preg_match('/[\p{L}\x{0600}-\x{06FF}]{3,}/u', $title)) {
        $errors['title'] = 'عنوان باید حداقل ۳ حرف فارسی یا انگلیسی داشته باشد';
    } elseif (!preg_match('/^[\p{L}\p{N}\x{0600}-\x{06FF}\s\-\/\(\)\.,،\+&\']{5,200}$/u', $title)) {
        $errors['title'] = 'عنوان شامل کاراکترهای غیرمجاز است';
    } elseif (preg_match('/^[\d\s\p{P}]+$/u', $title)) {
        $errors['title'] = 'عنوان نباید فقط عدد و علامت باشد';
    }

    // ── Description ─────────────────────────────────────────────────────────
    if (!preg_match('/[\p{L}\x{0600}-\x{06FF}]{10,}/u', $description)) {
        $errors['description'] = 'توضیحات باید متن واقعی و قابل فهم (حداقل ۱۰ حرف) باشد';
    } elseif (mb_strlen(preg_replace('/\s+/u', '', $description)) < 15) {
        $errors['description'] = 'توضیحات خیلی کوتاه یا نامفهوم است';
    }

    // ── Want in return ──────────────────────────────────────────────────────
    if ($want !== '' && mb_strlen($want) >= 10) {
        if (!preg_match('/[\p{L}\x{0600}-\x{06FF}]{3,}/u', $want)) {
            $errors['want_in_return'] = 'بخش «در ازای» باید شامل حروف معتبر باشد';
        }
    }

    // ── Coherence: description should not be copy-paste of title ───────────
    if ($title !== '' && $description !== '') {
        $tNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $title));
        $dNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $description));
        if ($tNorm === $dNorm) {
            $errors['description'] = 'توضیحات نباید عین عنوان باشد — مشخصات کالا را بنویسید';
        } elseif (mb_strlen($description) < mb_strlen($title) * 1.5) {
            similar_text($tNorm, mb_substr($dNorm, 0, mb_strlen($tNorm) + 20), $pct);
            if ($pct > 85) {
                $errors['description'] = 'توضیحات باید جزئیات بیشتری نسبت به عنوان داشته باشد';
            }
        }
    }

    // ── Bad words ───────────────────────────────────────────────────────────
    $lower = mb_strtolower($combined);
    foreach (listing_bad_words() as $word) {
        if ($word === '') continue;
        if (mb_strpos($lower, mb_strtolower($word)) !== false) {
            $errors['content'] = 'متن آگهی شامل کلمات نامناسب یا ممنوع است';
            break;
        }
    }

    // ── Nonsense / scam patterns ────────────────────────────────────────────
    if (preg_match('/(?:https?:\/\/|www\.)/i', $title)) {
        $errors['title'] = 'قرار دادن لینک در عنوان مجاز نیست';
    } elseif (empty($errors['content'])) {
        foreach (listing_nonsense_patterns() as $pattern => $msg) {
            if (@preg_match($pattern, $combined) === 1) {
                $key = str_contains($msg, 'عنوان') ? 'title' : 'description';
                if (!isset($errors[$key])) {
                    $errors[$key] = $msg;
                }
                break;
            }
        }
    }

    // URL in description limit (max 2 links)
    if (preg_match_all('/(?:https?:\/\/|www\.)\S+/i', $description, $m) && count($m[0]) > 2) {
        $errors['description'] = 'حداکثر ۲ لینک در توضیحات مجاز است';
    }

    return $errors;
}

/** SQL fragment for publicly visible listings */
function listing_public_sql(string $alias = 'l'): string {
    return "{$alias}.status = 'active' AND {$alias}.review_status = 'approved'";
}

function listing_review_label(string $status): string {
    return match ($status) {
        'pending'  => 'در انتظار تأیید',
        'approved' => 'تأیید شده',
        'rejected' => 'رد شده',
        default    => $status,
    };
}

function listing_review_badge(string $status): string {
    return match ($status) {
        'pending'  => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        default    => 'info',
    };
}
