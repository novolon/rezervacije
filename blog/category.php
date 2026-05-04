<?php
/**
 * Booked – kategorija (forest dark hero + grid).
 * URL: /booked/c/{slug}  ali  /{lang}/booked/c/{slug}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

$slug = trim($_GET['slug'] ?? '');
$lang = $_GET['lang'] ?? 'sl';
if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$pdo = getDB();

$cStmt = $pdo->prepare(
    "SELECT c.id, c.slug, COALESCE(ct.name, c.slug) AS name,
            ct.description, ct.meta_title, ct.meta_description
     FROM blog_categories c
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = ?
     WHERE c.slug = ? AND c.is_active = 1 LIMIT 1"
);
$cStmt->execute([$lang, $slug]);
$cat = $cStmt->fetch();
if (!$cat) {
    http_response_code(404);
    $bk = ['lang'=>$lang,'title'=>t('booked.error.not_found.title')];
    include __DIR__ . '/_header.php';
    echo '<main class="container text-center" style="padding:96px 20px"><h2 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:36px">' . t('booked.error.not_found.title') . '</h2></main>';
    include __DIR__ . '/_footer.php';
    exit;
}

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND p.category_id = ? AND (p.published_at IS NULL OR p.published_at <= NOW())"
);
$totalStmt->execute([$lang, $cat['id']]);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
            p.published_at,
            a.name AS author_name,
            m.variants AS hero_variants, m.dominant_color, m.alt_translations
     FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     LEFT JOIN blog_authors a ON a.id = p.author_id
     LEFT JOIN blog_media m   ON m.id = p.hero_media_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND p.category_id = ? AND (p.published_at IS NULL OR p.published_at <= NOW())
     ORDER BY p.published_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$lang, $cat['id']]);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['category_slug'] = $cat['slug'];
    $r['category_name'] = $cat['name'];
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

// Druge kategorije za chip nav
$otherCats = $pdo->prepare(
    "SELECT c.slug, COALESCE(ct.name, c.slug) AS name
     FROM blog_categories c
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = ?
     WHERE c.is_active = 1 ORDER BY c.display_order, c.id"
);
$otherCats->execute([$lang]);
$allCats = $otherCats->fetchAll();

$canonical = blog_absolute_url(blog_category_url($cat['slug'], $lang) . ($page > 1 ? '?page=' . $page : ''));

$bk = [
    'lang'        => $lang,
    'title'       => ($cat['meta_title'] ?: $cat['name']) . ' | Booked',
    'description' => $cat['meta_description'] ?: ($cat['description'] ?: t('booked.meta_description')),
    'canonical'   => $canonical,
    'og_type'     => 'website',
];
include __DIR__ . '/_header.php';
?>

<header class="hero-dark" style="padding:80px 0">
    <div class="container">
        <nav class="text-[13px] flex items-center gap-1.5 flex-wrap" aria-label="Breadcrumbs" style="color:rgba(255,255,255,.6);margin-bottom:32px">
            <a href="<?= htmlspecialchars(blog_listing_url($lang), ENT_QUOTES) ?>" class="hover:text-white">Booked</a>
            <span>›</span>
            <span class="text-white"><?= htmlspecialchars($cat['name'], ENT_QUOTES) ?></span>
        </nav>
        <span class="eyebrow" style="color:rgba(255,255,255,.6)"><?= t('booked.category.heading') ?> · <?= (int)$total ?> <?= ((int)$total) === 1 ? 'članek' : 'člankov' ?></span>
        <h1 class="font-sans font-extrabold tracking-tight text-white" style="font-size:clamp(48px,7vw,84px);line-height:0.98;letter-spacing:-0.03em;margin:16px 0 0">
            <?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>
        </h1>
        <?php if (!empty($cat['description'])): ?>
            <p style="font-size:clamp(18px,2vw,20px);color:rgba(255,255,255,.7);max-width:42rem;line-height:1.6;margin-top:24px"><?= htmlspecialchars($cat['description'], ENT_QUOTES) ?></p>
        <?php endif; ?>

        <div class="flex flex-wrap gap-2" style="margin-top:40px">
            <a href="<?= htmlspecialchars(blog_listing_url($lang), ENT_QUOTES) ?>" class="chip">← <?= t('booked.read_more') ?></a>
            <?php foreach ($allCats as $c): ?>
                <a href="<?= htmlspecialchars(blog_category_url($c['slug'], $lang), ENT_QUOTES) ?>" class="chip<?= $c['slug'] === $cat['slug'] ? ' active' : '' ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</header>

<main class="container" style="padding:64px 20px">
    <?php if (empty($rows)): ?>
        <div class="text-center" style="padding:64px 20px;color:var(--muted)">
            <h2 class="text-ink" style="font-size:22px"><?= t('booked.empty_state') ?></h2>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <?php foreach ($rows as $r) echo blog_render_post_card($r); ?>
        </div>
        <?= blog_render_pagination($page, $totalPages, blog_category_url($cat['slug'], $lang)) ?>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/_footer.php'; ?>
