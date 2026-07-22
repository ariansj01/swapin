<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';


$slug = trim($_GET['slug'] ?? '');

if (!$slug) {
    http_response_code(404);
    exit;
}


$page = DB::fetch(
    'SELECT * FROM content_pages 
     WHERE slug = ? 
     AND status = "published"
     LIMIT 1',
    [$slug]
);


if (!$page) {
    http_response_code(404);
    exit;
}


render_head(
    $page['meta_title'] ?: $page['title'],
    $page['meta_description'] ?? '',
    [
        'canonical' => $page['canonical_url'] ?: APP_URL.'/page/'.$page['slug'],
        'robots' => $page['index_status'] === 'noindex'
            ? 'noindex,nofollow'
            : 'index,follow'
    ]
);


render_navbar(auth_user());

if (!empty($page['faq_json'])) {

    $faqs = json_decode($page['faq_json'], true);

    if (is_array($faqs) && count($faqs)) {
        echo '<script type="application/ld+json">';
        echo json_encode([
            "@context"=>"https://schema.org",
            "@type"=>"FAQPage",
            "mainEntity"=>array_map(function($faq){
                return [
                    "@type"=>"Question",
                    "name"=>$faq['question'],
                    "acceptedAnswer"=>[
                        "@type"=>"Answer",
                        "text"=>$faq['answer']
                    ]
                ];
            }, $faqs)
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        echo '</script>';
    }
}



