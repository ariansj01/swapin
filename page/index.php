<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$slug = trim($_GET['slug'] ?? '');

if (!$slug) {
    http_response_code(404);
    exit;
}

$page = DB::fetch(
    'SELECT * FROM content_pages WHERE slug = ? AND status = "published" LIMIT 1',
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
        'canonical' => seo_resolve_canonical(
            $page['canonical_url'] ?? '',
            APP_URL . '/page/' . $page['slug']
        ),
        'robots' => ($page['index_status'] ?? 'index') === 'noindex'
            ? 'noindex,nofollow'
            : 'index,follow'
    ]
);

render_navbar(auth_user());

$faqs = [];

if (!empty($page['faq_json'])) {
    $decodedFaqs = json_decode($page['faq_json'], true);

    if (is_array($decodedFaqs)) {
        $faqs = array_values(array_filter($decodedFaqs, function ($faq) {
            return !empty($faq['question']) && !empty($faq['answer']);
        }));
    }
}

if ($faqs) {
    echo '<script type="application/ld+json">';
    echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($faq) {
            return [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer']
                ]
            ];
        }, $faqs)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo '</script>';
}
?>

<main class="content-page">
    <section class="container">
        <article class="cms-content">
            <h1><?= h($page['title']) ?></h1>
            <?= $page['content'] ?>
        </article>

        <?php if ($faqs): ?>
            <section class="cms-faq">
                <h2>سوالات متداول</h2>

                <?php foreach ($faqs as $faq): ?>
                    <details>
                        <summary><?= h($faq['question']) ?></summary>
                        <p><?= h($faq['answer']) ?></p>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </section>
</main>

<?php
if (function_exists('render_footer')) {
    render_footer();
}
