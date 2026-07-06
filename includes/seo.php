<?php
// includes/seo.php — SEO helpers (JSON-LD, canonical)

function seo_canonical(?string $override = null): string {
    if ($override) {
        return $override;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return rtrim(APP_URL, '/') . $path;
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
            'email'       => 'info@swapin.ir',
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
