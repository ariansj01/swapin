<?php
// includes/seo.php — SEO helpers (JSON-LD, canonical)

function seo_canonical(?string $override = null): string {
    if ($override !== null && $override !== '') {
        return seo_resolve_canonical($override);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return rtrim(APP_URL, '/') . $path;
}

function seo_is_valid_canonical_url(string $url): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    return str_starts_with($url, '/');
}

/**
 * Return a safe canonical URL — ignore non-URL values (e.g. page titles stored by mistake).
 */
function seo_resolve_canonical(?string $override, ?string $fallback = null): string {
    $fallback ??= seo_canonical();
    $override = trim((string) $override);

    if ($override === '' || !seo_is_valid_canonical_url($override)) {
        return $fallback;
    }

    if (str_starts_with($override, '/')) {
        return rtrim(APP_URL, '/') . $override;
    }

    return $override;
}

/** Normalize canonical before persisting in CMS/admin forms. */
function seo_sanitize_stored_canonical(string $url): string {
    $url = trim($url);

    return ($url !== '' && seo_is_valid_canonical_url($url)) ? $url : '';
}

function seo_json_ld_website(): array {
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'WebSite',
        'name'        => APP_NAME,
        'alternateName' => APP_NAME_EN,
        'url'         => APP_URL . '/',
        'description' => 'بازار هوشمند مبادله کالا با کالا در ایران',
        'inLanguage'  => 'fa-IR',
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => APP_URL . '/?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

function seo_json_ld_organization(): array {
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => APP_NAME,
        'url'      => APP_URL . '/',
        'logo'     => LOGO_URL,
        'contactPoint' => [
            '@type'       => 'ContactPoint',
            'telephone'   => '+98-998-153-4269',
            'contactType' => 'customer service',
            'email'       => 'info@swaapin.ir',
            'areaServed'  => 'IR',
            'availableLanguage' => 'Persian',
        ],
        'address' => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'تهران',
            'streetAddress'   => 'مرکز نوآوری اکباتان',
            'addressCountry'  => 'IR',
        ],
    ];
}

function seo_json_ld_product(array $listing, ?string $imageUrl, string $pageUrl): array {
    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $listing['title'],
        'description' => mb_strimwidth(strip_tags($listing['description'] ?? ''), 0, 300),
        'url'         => $pageUrl,
        'category'    => $listing['cat_name'] ?? '',
        'itemCondition' => 'https://schema.org/UsedCondition',
        'offers' => [
            '@type'         => 'Offer',
            'priceCurrency' => 'IRT',
            'availability'  => 'https://schema.org/InStock',
            'url'           => $pageUrl,
            'price'         => max(0, (int)($listing['estimated_value'] ?? 0)),
        ],
    ];
    if ($imageUrl) {
        $schema['image'] = $imageUrl;
    }
    if (!empty($listing['seller_name'])) {
        $schema['brand'] = ['@type' => 'Brand', 'name' => APP_NAME];
    }
    return $schema;
}

function seo_json_ld_breadcrumbs(array $items): array {
    $list = [];
    foreach ($items as $i => $item) {
        $entry = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $item['name'],
        ];
        if (!empty($item['url'])) {
            $entry['item'] = $item['url'];
        }
        $list[] = $entry;
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
}

function seo_clean_text(string $s, int $maxLen = 0): string {
    $s = preg_replace('/\s+/u', ' ', trim(strip_tags($s)));
    $s = preg_replace("/[«»\"'`]+/u", '', $s);
    if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
        $s = mb_strimwidth($s, 0, $maxLen, '…');
    }
    return $s;
}

function seo_listing_title(array $listing): string {
    $title       = seo_clean_text((string)($listing['title'] ?? ''), 50);
    $catName     = seo_clean_text((string)($listing['cat_name']  ?? ''));
    $city        = seo_clean_text((string)($listing['city']      ?? ''));
    $want        = seo_clean_text((string)($listing['want_in_return'] ?? ''), 35);
    $mode        = (string)($listing['listing_mode'] ?? 'swap');

    $suffix = ' | ' . APP_NAME;
    $prefix = ($mode === 'sell') ? 'فروش ' : 'معاوضه ';

    $parts = [];

    if ($title !== '' && $title !== '0') {
        $parts[] = $prefix . $title;
    } else {
        $parts[] = $prefix . trim($catName . ' مناسب');
    }

    if ($want !== '') {
        $parts[] = 'با ' . $want;
    }

    if ($city !== '') {
        $parts[] = 'در ' . $city;
    }

    $combined = implode(' | ', $parts);

    if (mb_strlen($combined . $suffix) > 65) {
        $combined = mb_strimwidth($combined, 0, 63 - mb_strlen($suffix), '…');
    }

    return $combined . $suffix;
}

function seo_listing_description(array $listing): string {
    $title       = seo_clean_text((string)($listing['title'] ?? ''));
    $catName     = seo_clean_text((string)($listing['cat_name']  ?? ''));
    $city        = seo_clean_text((string)($listing['city']      ?? ''));
    $want        = seo_clean_text((string)($listing['want_in_return'] ?? ''), 60);
    $description = seo_clean_text((string)($listing['description'] ?? ''));
    $condition   = seo_clean_text((string)($listing['condition'] ?? ''));
    $mode        = (string)($listing['listing_mode'] ?? 'swap');
    $value       = (float)($listing['estimated_value'] ?? 0);

    $sentences = [];

    if ($mode === 'sell') {
        $head = 'خرید و فروش ';
    } else {
        $head = 'معاوضه ';
    }
    $head .= ($catName ?: 'کالا');
    if ($city)  $head .= ' در ' . $city;
    $sentences[] = $head . ' در پلتفرم سواپین.';

    if ($title && $title !== $catName) {
        $sentences[] = 'عنوان آگهی: ' . $title . '.';
    }

    if ($want && $mode !== 'sell') {
        $sentences[] = 'درخواست تعویض با: ' . $want . '.';
    }

    if ($condition && $condition !== 'good') {
        $condMap = [
            'new'         => 'نو',
            'like_new'    => 'در حد نو',
            'good'        => 'خوب',
            'fair'        => 'قابل قبول',
            'for_parts'   => 'قطعی',
        ];
        $c = $condMap[$condition] ?? $condition;
        $sentences[] = 'وضعیت کالا: ' . $c . '.';
    }

    if ($value > 0) {
        $toman = (int)round($value);
        if ($toman >= 1_000_000) {
            $sentences[] = 'ارزش تخمینی حدود ' . number_format($toman / 1_000_000, 1, '.', '') . ' میلیون تومان.';
        } else {
            $sentences[] = 'ارزش تخمینی حدود ' . number_format($toman) . ' تومان.';
        }
    }

    if ($description !== '' && count($sentences) < 4) {
        $extra = mb_strimwidth($description, 0, 80, '…');
        if (mb_strlen($extra) > 20) {
            $sentences[] = $extra;
        }
    }

    $out = implode(' ', $sentences);
    if (mb_strlen($out) > 160) {
        $out = mb_strimwidth($out, 0, 158, '…');
    }
    return $out;
}
