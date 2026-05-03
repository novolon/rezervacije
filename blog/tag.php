<?php
/**
 * Booked – tag listing.
 * URL: /booked/t/{slug}  ali  /{lang}/booked/t/{slug}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

$slug = trim($_GET['slug'] ?? '');
$lang = $_GET['lang'] ?? get_lang();
if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$pdo = getDB();

$tStmt = $pdo->prepare(
    "SELECT t.id, t.slug, COALESCE(tt.name, t.slug) AS name
     FROM blog_tags t
     LEFT JOIN blog_tag_translations tt ON tt.tag_id = t.id AND tt.lang_code = ?
     WHERE t.slug = ? LIMIT 1"
);
$tStmt->execute([$lang, $slug]);
$tag = $tStmt->fetch();
if (!$tag) {
    http_response_code(404);
    $bk = ['lang'=>$lang,'title'=>t('booked.error.not_found.title')];
    include __DIR__ . '/_header.php';
    echo '<main class="container text-center" style="padding:96px 20px"><h2 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:36px">' . t('booked.error.not_found.title') . '</h2></main>';
    include __DIR__ . '/_footer.php';
    exit;
}

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM blog_post_translations t
     JOIN blog_posts p           ON p.id = t.post_id
     JOIN blog_post_tags pt      ON pt.post_id = p.id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND pt.tag_id = ? AND (p.published_at IS NULL OR p.published_at <= NOW())"
);
$totalStmt->execute([$lang, $tag['id']]);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT DISTINCT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
            p.published_at,
            c.slug AS category_slug, ct.name AS category_name,
            m.variants AS hero_variants, m.dominant_color, m.alt_translations
     FROM blog_post_translations t
     JOIN blog_posts p           ON p.id = t.post_id
     JOIN blog_post_tags pt      ON pt.post_id = p.id
     LEFT JOIN blog_categories c ON c.id = p.category_id
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = t.lang_code
     LEFT JOIN blog_media m      ON m.id = p.hero_media_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND pt.tag_id = ? AND (p.published_at IS NULL OR p.published_at <= NOW())
     ORDER BY p.published_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$lang, $tag['id']]);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['hero_url'] = '';
    $r['hero_alt'] = '';
    if (!empty($r['hero_variants'])) {
        $variants = is_string($r['hero_variants']) ? json_decode($r['hero_variants'], true) : $r['hero_variants'];
        $r['hero_url'] = blog_base_url() . '/' . ltrim($variants[800] ?? $variants[1200] ?? reset($variants), '/');
    }
    if (!empty($r['alt_translations'])) {
        $alts = is_string($r['alt_translations']) ? json_decode($r['alt_translations'], true) : $r['alt_translations'];
        $r['hero_alt'] = $alts[$lang] ?? $alts['sl'] ?? $r['title'];
    }
}
unset($r);

$canonical = blog_absolute_url(blog_tag_url($tag['slug'], $lang) . ($page > 1 ? '?page=' . $page : ''));

$bk = [
    'lang'        => $lang,
    'title'       => '#' . $tag['name'] . ' | Booked',
    'description' => t('booked.tags.heading') . ': ' . $tag['name'],
    'canonical'   => $canonical,
    'og_type'     => 'website',
];
include __DIR__ . '/_header.php';
?>

<header class="container" style="padding:64px 20px 32px">
    <span class="eyebrow"><?= t('booked.tags.heading') ?></span>
    <h1 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:clamp(40px,5vw,64px);line-height:1;letter-spacing:-0.025em;margin:16px 0 0">
        #<?= htmlspecialchars($tag['name'], ENT_QUOTES) ?>
    </h1>
    <p class="text-ink3" style="margin-top:16px;font-size:16px"><?= (int)$total ?> člankov</p>
</header>

<main class="container" style="padding:32px 20px 64px">
    <?php if (empty($rows)): ?>
        <div class="text-center" style="padding:64px 20px;color:var(--muted)">
            <h2 class="text-ink" style="font-size:22px"><?= t('booked.empty_state') ?></h2>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <?php foreach ($rows as $r) echo blog_render_post_card($r); ?>
        </div>
        <?= blog_render_pagination($page, $totalPages, blog_tag_url($tag['slug'], $lang)) ?>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/_footer.php'; ?>
