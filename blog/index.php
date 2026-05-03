<?php
/**
 * Booked – javna listing stran.
 *
 * URL: /booked  ali  /{lang}/booked
 * Filtri: ?page=N
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

$lang = get_lang();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$pdo = getDB();

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND (p.published_at IS NULL OR p.published_at <= NOW())"
);
$totalStmt->execute([$lang]);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
            p.published_at, p.id AS post_id,
            c.slug AS category_slug,
            ct.name AS category_name,
            a.name AS author_name,
            m.variants AS hero_variants,
            m.dominant_color,
            m.alt_translations
     FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     LEFT JOIN blog_categories c            ON c.id = p.category_id
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = t.lang_code
     LEFT JOIN blog_authors a               ON a.id = p.author_id
     LEFT JOIN blog_media m                 ON m.id = p.hero_media_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND (p.published_at IS NULL OR p.published_at <= NOW())
     ORDER BY p.published_at DESC, p.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$lang]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['hero_url'] = '';
    $r['hero_alt'] = '';
    if (!empty($r['hero_variants'])) {
        $variants = is_string($r['hero_variants']) ? json_decode($r['hero_variants'], true) : $r['hero_variants'];
        if (!empty($variants[800]))      $r['hero_url'] = blog_base_url() . '/' . ltrim($variants[800], '/');
        elseif (!empty($variants[1200])) $r['hero_url'] = blog_base_url() . '/' . ltrim($variants[1200], '/');
        elseif (!empty($variants))       $r['hero_url'] = blog_base_url() . '/' . ltrim(reset($variants), '/');
    }
    if (!empty($r['alt_translations'])) {
        $alts = is_string($r['alt_translations']) ? json_decode($r['alt_translations'], true) : $r['alt_translations'];
        $r['hero_alt'] = $alts[$lang] ?? $alts['sl'] ?? $r['title'];
    }
}
unset($r);

// Kategorije za chip filter + sidebar (z post count-om)
$catStmt = $pdo->prepare(
    "SELECT c.slug, COALESCE(ct.name, c.slug) AS name,
            (SELECT COUNT(*) FROM blog_posts p2
             JOIN blog_post_translations t2 ON t2.post_id = p2.id AND t2.lang_code = ?
             WHERE p2.category_id = c.id AND p2.status = 'published'
               AND t2.status = 'approved'
               AND (p2.published_at IS NULL OR p2.published_at <= NOW())) AS post_count
     FROM blog_categories c
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = ?
     WHERE c.is_active = 1
     ORDER BY c.display_order, c.id"
);
$catStmt->execute([$lang, $lang]);
$categories = $catStmt->fetchAll();

// Recent posts (sidebar)
$recentStmt = $pdo->prepare(
    "SELECT t.slug, t.title, t.lang_code, p.published_at,
            COALESCE(ct.name, c.slug) AS category_name
     FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     LEFT JOIN blog_categories c            ON c.id = p.category_id
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = t.lang_code
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND (p.published_at IS NULL OR p.published_at <= NOW())
     ORDER BY p.published_at DESC
     LIMIT 4"
);
$recentStmt->execute([$lang]);
$recent = $recentStmt->fetchAll();

// SEO: hreflangs za listing
$hrefStmt = $pdo->query(
    "SELECT DISTINCT t.lang_code FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     WHERE t.status = 'approved' AND p.status = 'published'
       AND (p.published_at IS NULL OR p.published_at <= NOW())"
);
$hreflangs = [];
foreach ($hrefStmt->fetchAll(PDO::FETCH_COLUMN) as $lc) {
    $hreflangs[$lc] = blog_absolute_url(blog_listing_url($lc));
}
if (!empty($hreflangs)) $hreflangs['x-default'] = $hreflangs['sl'] ?? reset($hreflangs);

$canonical = blog_absolute_url(blog_listing_url($lang) . ($page > 1 ? '?page=' . $page : ''));

// JSON-LD Blog
$jsonld = '<script type="application/ld+json">' . json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Blog',
    'name'        => 'Booked — by Rezble',
    'description' => t('booked.meta_description'),
    'url'         => blog_absolute_url(blog_listing_url($lang)),
    'inLanguage'  => $lang,
    'publisher'   => [
        '@type' => 'Organization',
        'name'  => 'Rezble',
        'url'   => rtrim(APP_URL, '/'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

$bk = [
    'lang'        => $lang,
    'title'       => t('booked.meta_title'),
    'description' => t('booked.meta_description'),
    'canonical'   => $canonical,
    'og_type'     => 'website',
    'hreflangs'   => $hreflangs,
    'jsonld'      => $jsonld,
];
include __DIR__ . '/_header.php';
?>

<!-- Hero -->
<header class="container" style="padding-top:64px;padding-bottom:32px">
    <div style="max-width:768px">
        <span class="eyebrow">Booked</span>
        <h1 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:clamp(56px,8vw,108px);line-height:0.95;letter-spacing:-0.035em;margin:16px 0 0">
            Booked<span class="text-terracotta">.</span>
        </h1>
        <p class="text-ink3 leading-relaxed" style="font-size:clamp(19px,2vw,22px);max-width:42rem;margin-top:24px">
            <?= t('booked.meta_description') ?>
        </p>
    </div>

    <!-- Search -->
    <div class="relative" style="max-width:36rem;margin-top:40px">
        <div class="relative">
            <svg class="absolute" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="left:20px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input
                type="search"
                class="input shadow-sm"
                placeholder="<?= t('booked.search.placeholder') ?>"
                data-bk-search-input
                data-api-base="<?= htmlspecialchars(blog_base_url(), ENT_QUOTES) ?>"
                data-lang="<?= htmlspecialchars($lang, ENT_QUOTES) ?>"
                style="padding:16px 48px 16px 56px;border-radius:999px;font-size:15px"
                autocomplete="off">
        </div>
        <div class="dropdown" data-bk-search-results style="display:none;position:absolute;z-index:30;left:0;right:0;margin-top:8px;overflow:hidden"></div>
    </div>

    <!-- Category filters -->
    <?php if (!empty($categories)): ?>
    <div class="flex flex-wrap gap-2" style="margin-top:32px">
        <a href="<?= htmlspecialchars(blog_listing_url($lang), ENT_QUOTES) ?>" class="chip active"><?= t('booked.brand') ?></a>
        <?php foreach ($categories as $c): ?>
            <a class="chip" href="<?= htmlspecialchars(blog_category_url($c['slug'], $lang), ENT_QUOTES) ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</header>

<main class="container grid grid-cols-1 lg:grid-cols-12 gap-12" style="padding-bottom:64px;margin-top:24px">
    <div class="lg:col-span-8">
        <?php if (empty($rows)): ?>
            <div class="text-center" style="padding:64px 20px;color:var(--muted)">
                <h2 class="text-ink" style="font-size:22px;margin:0 0 12px"><?= t('booked.empty_state') ?></h2>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <?php foreach ($rows as $r) echo blog_render_post_card($r); ?>
            </div>
            <?= blog_render_pagination($page, $totalPages, blog_listing_url($lang)) ?>
        <?php endif; ?>
    </div>

    <aside class="hidden lg:block lg:col-span-4">
        <div class="sticky" style="top:96px;display:flex;flex-direction:column;gap:32px">
            <?= blog_render_sidebar_recent($recent, $lang) ?>
            <?= blog_render_sidebar_categories($categories, $lang) ?>
            <?= blog_render_subscribe_sidebar($lang) ?>
        </div>
    </aside>
</main>

<?php include __DIR__ . '/_footer.php'; ?>
