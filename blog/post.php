<?php
/**
 * Booked – javni single post.
 *
 * URL: /booked/{slug}            (privzeti jezik)
 *      /{lang}/booked/{slug}     (eksplicitni jezik)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

$slug = trim($_GET['slug'] ?? '');
// Pomembno: ne uporabljaj get_lang() (cookie/session) — URL pot je avtoritativna.
// .htaccess pošlje ?lang=$1 za /{lang}/booked/... rute, /booked/... pa default = 'sl'.
$lang = $_GET['lang'] ?? 'sl';
if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';

/** Render 404 v Booked stilu (koristno tudi pri napaki slug-a). */
function _bk_render_404(string $lang): void {
    $bk = ['lang'=>$lang,'title'=>t('booked.error.not_found.title') . ' | Booked'];
    include __DIR__ . '/_header.php';
    echo '<main class="container text-center" style="padding:96px 20px">'
        . '<div class="mx-auto" style="width:80px;height:80px;border-radius:999px;background:var(--cream);display:flex;align-items:center;justify-content:center;margin:0 auto 32px;border:1px solid var(--cream-2)">'
        . '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-terracotta"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M9 10h.01M15 10h.01M9 14c1 1 2 1 3 1s2 0 3-1"/></svg>'
        . '</div>'
        . '<span class="eyebrow">404 · ' . t('booked.error.not_found.title') . '</span>'
        . '<h1 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:clamp(40px,5vw,64px);line-height:1;margin:16px 0 0">' . t('booked.error.not_found.title') . '</h1>'
        . '<p class="text-ink3 leading-relaxed" style="font-size:18px;max-width:28rem;margin:20px auto 0">' . t('booked.error.not_found.text') . '</p>'
        . '<div class="flex flex-wrap items-center justify-center gap-3" style="margin-top:32px">'
        . '<a href="' . htmlspecialchars(blog_listing_url($lang), ENT_QUOTES) . '" class="btn btn-primary">← ' . t('booked.error.not_found.cta') . '</a>'
        . '</div>'
        . '</main>';
    include __DIR__ . '/_footer.php';
}

if ($slug === '' || !preg_match('/^[a-z0-9-]{2,200}$/', $slug)) {
    http_response_code(404);
    _bk_render_404($lang);
    exit;
}

$pdo = getDB();

// Preview mode: superadmin lahko z ?preview=1 pogleda nepotrjene/neobjavljene članke.
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$isPreview = !empty($_GET['preview']) && (($_SESSION['role'] ?? '') === 'superadmin');

if ($isPreview) {
    $stmt = $pdo->prepare(
        "SELECT t.*, p.master_lang, p.published_at, p.scheduled_at, p.status AS post_status,
                p.category_id, p.author_id, p.hero_media_id, p.id AS post_id_master, p.view_count AS post_views
         FROM blog_post_translations t
         JOIN blog_posts p ON p.id = t.post_id
         WHERE t.slug = ? AND t.lang_code = ?
         LIMIT 1"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT t.*, p.master_lang, p.published_at, p.scheduled_at, p.status AS post_status,
                p.category_id, p.author_id, p.hero_media_id, p.id AS post_id_master, p.view_count AS post_views
         FROM blog_post_translations t
         JOIN blog_posts p ON p.id = t.post_id
         WHERE t.slug = ? AND t.lang_code = ?
           AND t.status = 'approved' AND p.status = 'published'
           AND (p.published_at IS NULL OR p.published_at <= NOW())
         LIMIT 1"
    );
}
$stmt->execute([$slug, $lang]);
$tr = $stmt->fetch();

if (!$tr) {
    http_response_code(404);
    _bk_render_404($lang);
    exit;
}

$postId = (int)$tr['post_id'];

$author = null;
if (!empty($tr['author_id'])) {
    $aStmt = $pdo->prepare("SELECT * FROM blog_authors WHERE id = ?");
    $aStmt->execute([$tr['author_id']]);
    $author = $aStmt->fetch() ?: null;
}

$category = null;
if (!empty($tr['category_id'])) {
    $cStmt = $pdo->prepare(
        "SELECT c.id, c.slug, COALESCE(ct.name, c.slug) AS name
         FROM blog_categories c
         LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = ?
         WHERE c.id = ?"
    );
    $cStmt->execute([$lang, $tr['category_id']]);
    $category = $cStmt->fetch() ?: null;
}

$hero = null;
if (!empty($tr['hero_media_id'])) {
    $mStmt = $pdo->prepare("SELECT * FROM blog_media WHERE id = ?");
    $mStmt->execute([$tr['hero_media_id']]);
    $hero = $mStmt->fetch() ?: null;
}

$tagsStmt = $pdo->prepare(
    "SELECT t.slug, COALESCE(tt.name, t.slug) AS name
     FROM blog_post_tags pt
     JOIN blog_tags t ON t.id = pt.tag_id
     LEFT JOIN blog_tag_translations tt ON tt.tag_id = t.id AND tt.lang_code = ?
     WHERE pt.post_id = ?"
);
$tagsStmt->execute([$lang, $postId]);
$tags = $tagsStmt->fetchAll();

$hreflangs = blog_post_hreflangs($postId, $tr['master_lang']);
$canonical = blog_absolute_url(blog_post_url($tr['slug'], $lang));

// V preview načinu vrni hreflangs za VSE prevode (tudi draft), s ?preview=1 dodatkom,
// da lahko superadmin preklaplja jezike znotraj predogleda.
if ($isPreview) {
    $allTrStmt = $pdo->prepare("SELECT lang_code, slug FROM blog_post_translations WHERE post_id = ?");
    $allTrStmt->execute([$postId]);
    $previewHreflangs = [];
    foreach ($allTrStmt->fetchAll() as $r) {
        if (!empty($r['slug'])) {
            $previewHreflangs[$r['lang_code']] = blog_post_url($r['slug'], $r['lang_code']) . '?preview=1';
        }
    }
}

$ogImage = '';
if ($hero && !empty($hero['variants'])) {
    $variants = is_string($hero['variants']) ? json_decode($hero['variants'], true) : $hero['variants'];
    $ogImage  = blog_absolute_url('/' . ltrim($variants[1200] ?? $variants[800] ?? reset($variants), '/'));
}

$jsonld = blog_render_jsonld_post($tr, $tr, $author, $hero, $category);
$breadcrumbs = [
    ['name' => 'Booked', 'url' => blog_listing_url($lang)],
];
if ($category) {
    $breadcrumbs[] = ['name' => $category['name'], 'url' => blog_category_url($category['slug'], $lang)];
}
$breadcrumbs[] = ['name' => $tr['title'], 'url' => blog_post_url($tr['slug'], $lang)];
$jsonld .= "\n" . blog_render_jsonld_breadcrumbs($breadcrumbs);

// TOC + content HTML – vedno renderiraj iz content_md da so media captions vedno aktualni
$contentHtml = '';
$toc = [];
if (!empty($tr['content_md'])) {
    $rendered = blog_render_md($tr['content_md'], $lang);
    $contentHtml = $rendered['html'];
    $toc = $rendered['toc'];
} else {
    $contentHtml = $tr['content_html'] ?? '';
    $toc = is_string($tr['table_of_contents'] ?? null)
        ? (json_decode($tr['table_of_contents'], true) ?: [])
        : ($tr['table_of_contents'] ?? []);
}

// Če TOC ni shranjen v DB, izvleci iz HTML-a
if (empty($toc) && $contentHtml) {
    if (preg_match_all('/<h([23])\s+id="([^"]+)"[^>]*>(.*?)<\/h\1>/is', $contentHtml, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $text = preg_replace('/<a[^>]*class="anchor"[^>]*>.*?<\/a>/i', '', $m[3]);
            $text = trim(strip_tags($text));
            $toc[] = [
                'level'  => (int)$m[1],
                'text'   => $text,
                'anchor' => $m[2],
            ];
        }
    }
}

// Rendiraj CTA shortcode placeholderje (<!--CTA:type-->) v aktualne CTA bannerje
if ($contentHtml) {
    $contentHtml = preg_replace_callback('/<!--CTA:([a-z_-]+)-->/', function ($m) use ($lang) {
        return blog_render_cta($m[1], '', $lang);
    }, $contentHtml);
}

blog_increment_view((int)$tr['id']);

$related = blog_fetch_related($postId, $tr['category_id'] ? (int)$tr['category_id'] : null, $lang, 3);
foreach ($related as &$rr) {
    $rr['hero_alt'] = '';
    if (!isset($rr['category_slug']) && $category) {
        $rr['category_slug'] = $category['slug'];
        $rr['category_name'] = $category['name'];
    }
}
unset($rr);

$bk = [
    'lang'        => $lang,
    'title'       => ($isPreview ? '[PREVIEW] ' : '') . ($tr['meta_title'] ?: $tr['title']) . ' | Booked',
    'description' => $tr['meta_description'] ?: $tr['excerpt'] ?: '',
    'canonical'   => $canonical,
    'og_image'    => $ogImage,
    'og_type'     => 'article',
    'hreflangs'   => $isPreview ? [] : $hreflangs, // SEO: brez hreflang link tagov v preview (noindex)
    'lang_switcher_urls' => $isPreview ? ($previewHreflangs ?? []) : $hreflangs, // UI switcher
    'jsonld'      => $isPreview ? '' : $jsonld,    // brez JSON-LD v preview
    'robots'      => $isPreview ? 'noindex,nofollow' : 'index,follow',
];
include __DIR__ . '/_header.php';

if ($isPreview) {
    echo '<div style="background:#fff3cd;border-bottom:2px solid #ffc107;padding:10px 16px;text-align:center;font:13px/1.4 -apple-system,sans-serif;color:#664d03;position:sticky;top:0;z-index:9999">'
        . '<strong>👁️ PREDOGLED</strong> · status članka: <code>' . htmlspecialchars($tr['post_status'], ENT_QUOTES) . '</code>'
        . ' · status prevoda: <code>' . htmlspecialchars($tr['status'], ENT_QUOTES) . '</code>'
        . ' · jezik: <code>' . htmlspecialchars($lang, ENT_QUOTES) . '</code>'
        . ' · <a href="' . BASE_PATH . '/pages/blog_editor.php?id=' . (int)$tr['post_id'] . '" style="color:#664d03;font-weight:600">← Nazaj v editor</a>'
        . '</div>';
}

$publishedDate = !empty($tr['published_at'] ?? $tr['updated_at']) ? date('j. n. Y', strtotime($tr['published_at'] ?? $tr['updated_at'])) : '';
?>

<main>
    <!-- Breadcrumbs -->
    <div class="container" style="padding-top:24px">
        <nav class="text-[13px] text-ink3 flex items-center gap-1.5 flex-wrap" aria-label="Breadcrumbs">
            <a href="<?= htmlspecialchars(blog_listing_url($lang), ENT_QUOTES) ?>" class="hover:text-ink">Booked</a>
            <span class="text-muted">›</span>
            <?php if ($category): ?>
                <a href="<?= htmlspecialchars(blog_category_url($category['slug'], $lang), ENT_QUOTES) ?>" class="hover:text-ink"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></a>
                <span class="text-muted">›</span>
            <?php endif; ?>
            <span class="text-ink truncate" style="max-width:60ch"><?= htmlspecialchars($tr['title'], ENT_QUOTES) ?></span>
        </nav>
    </div>

    <div class="container grid grid-cols-1 lg:grid-cols-12 gap-10" style="margin-top:32px">
        <!-- TOC sidebar (desktop) -->
        <aside class="hidden lg:block lg:col-span-3" aria-label="<?= t('booked.toc.title') ?>">
            <?php if (!empty($toc)): ?>
            <div class="sticky" style="top:96px">
                <div class="toc">
                    <div class="toc-title"><?= t('booked.toc.title') ?></div>
                    <nav>
                        <?php foreach ($toc as $item): ?>
                            <a href="#<?= htmlspecialchars($item['anchor'], ENT_QUOTES) ?>" data-toc="<?= (int)$item['level'] ?>"<?= ((int)$item['level']) === 3 ? ' style="padding-left:24px;font-size:13px"' : '' ?>><?= htmlspecialchars($item['text'], ENT_QUOTES) ?></a>
                        <?php endforeach; ?>
                    </nav>
                    <?php if (!empty($tr['reading_time_minutes']) || !empty($tr['word_count'])): ?>
                    <div style="margin-top:32px;padding-top:24px;border-top:1px solid var(--border);font-size:12.5px;color:var(--text-2);display:flex;flex-direction:column;gap:8px">
                        <?php if (!empty($tr['reading_time_minutes'])): ?>
                            <div class="flex items-center gap-2"><span style="width:6px;height:6px;border-radius:999px;background:var(--terracotta)"></span><?= t('booked.minute_read', ['minutes' => (int)$tr['reading_time_minutes']]) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($tr['word_count'])): ?>
                            <div><?= (int)$tr['word_count'] ?> besed</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Main article column -->
        <article class="lg:col-span-7">
            <?php if ($category): ?>
                <a href="<?= htmlspecialchars(blog_category_url($category['slug'], $lang), ENT_QUOTES) ?>" class="chip chip-cat" style="margin-bottom:20px"><?= htmlspecialchars($category['name'], ENT_QUOTES) ?></a>
            <?php endif; ?>

            <h1 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:var(--fs-h1);line-height:var(--lh-tight);letter-spacing:-0.025em;margin:12px 0 20px">
                <?= htmlspecialchars($tr['title'], ENT_QUOTES) ?>
            </h1>

            <?php if (!empty($tr['excerpt'])): ?>
                <p class="text-ink3 leading-relaxed" style="font-size:19px;max-width:60ch"><?= htmlspecialchars($tr['excerpt'], ENT_QUOTES) ?></p>
            <?php endif; ?>

            <!-- Meta row -->
            <div class="flex items-center gap-4 flex-wrap" style="margin-top:28px">
                <?php if ($author): ?>
                    <a href="<?= htmlspecialchars(blog_author_url($author['slug'], $lang), ENT_QUOTES) ?>" class="flex items-center gap-3" style="text-decoration:none">
                        <?php if (!empty($author['avatar_url'])): ?>
                            <img class="ring-1 ring-border1" src="<?= htmlspecialchars($author['avatar_url'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($author['name'], ENT_QUOTES) ?>" width="40" height="40" style="width:40px;height:40px;border-radius:50%;object-fit:cover">
                        <?php else: ?>
                            <span class="ring-1 ring-border1" style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--forest-3),var(--forest-2));color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:16px"><?= htmlspecialchars(mb_substr($author['name'] ?? '?', 0, 1, 'UTF-8'), ENT_QUOTES) ?></span>
                        <?php endif; ?>
                        <div style="font-size:14px">
                            <div class="font-semibold text-ink"><?= htmlspecialchars($author['name'], ENT_QUOTES) ?></div>
                            <div class="text-ink3"><?= t('booked.author.heading') ?></div>
                        </div>
                    </a>
                    <span class="hidden sm:block" style="width:1px;height:32px;background:var(--border)"></span>
                <?php endif; ?>
                <div class="text-[13px] text-ink3 flex items-center gap-3 flex-wrap">
                    <?php if ($publishedDate): ?>
                        <time datetime="<?= htmlspecialchars($tr['published_at'] ?? $tr['updated_at'], ENT_QUOTES) ?>"><?= htmlspecialchars($publishedDate, ENT_QUOTES) ?></time>
                        <span class="text-muted">·</span>
                    <?php endif; ?>
                    <?php if (!empty($tr['reading_time_minutes'])): ?>
                        <span><?= t('booked.minute_read', ['minutes' => (int)$tr['reading_time_minutes']]) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TOC mobile -->
            <?php if (!empty($toc)): ?>
            <details class="lg-hide toc-mobile" style="margin-top:32px">
                <summary>
                    <span><?= t('booked.toc.title') ?> · <?= count($toc) ?> razdelkov</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                </summary>
                <div class="toc toc-list">
                    <?php foreach ($toc as $item): ?>
                        <a href="#<?= htmlspecialchars($item['anchor'], ENT_QUOTES) ?>"<?= ((int)$item['level']) === 3 ? ' style="padding-left:24px"' : '' ?>><?= htmlspecialchars($item['text'], ENT_QUOTES) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>

            <!-- Hero image -->
            <?php if ($hero):
                $heroAlt = '';
                if (!empty($hero['alt_translations'])) {
                    $alts = is_string($hero['alt_translations']) ? json_decode($hero['alt_translations'], true) : $hero['alt_translations'];
                    $heroAlt = $alts[$lang] ?? $alts['sl'] ?? $tr['title'];
                }
                $heroAlt = $heroAlt ?: $tr['title'];
                $variants = is_string($hero['variants'] ?? null) ? json_decode($hero['variants'], true) : ($hero['variants'] ?? []);
                $heroSrc = !empty($variants) ? blog_base_url() . '/' . ltrim($variants[1920] ?? $variants[1200] ?? $variants[800] ?? reset($variants), '/') : '';
                $cap = '';
                if (!empty($hero['caption_translations'])) {
                    $caps = is_string($hero['caption_translations']) ? json_decode($hero['caption_translations'], true) : $hero['caption_translations'];
                    $cap  = $caps[$lang] ?? $caps['sl'] ?? '';
                }
            ?>
                <figure style="margin-top:40px;margin-left:0;margin-right:0">
                    <?php if ($heroSrc): ?>
                        <img src="<?= htmlspecialchars($heroSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($heroAlt, ENT_QUOTES) ?>" width="<?= (int)($hero['width'] ?? 1200) ?>" height="<?= (int)($hero['height'] ?? 675) ?>" loading="eager" decoding="async" style="width:100%;height:auto;display:block;border-radius:22px;border:1px solid var(--border)">
                    <?php endif; ?>
                    <?php if ($cap): ?>
                        <figcaption style="font-family:var(--ff-sans);font-size:13px;color:var(--muted);text-align:center;margin-top:12px"><?= htmlspecialchars($cap, ENT_QUOTES) ?></figcaption>
                    <?php endif; ?>
                </figure>
            <?php endif; ?>

            <!-- Article body -->
            <div class="prose" style="margin-top:48px">
                <?= $contentHtml ?>
            </div>

            <?php if (!empty($tags)): ?>
                <div class="flex flex-wrap gap-2" style="margin-top:48px">
                    <?php foreach ($tags as $tg): ?>
                        <a href="<?= htmlspecialchars(blog_tag_url($tg['slug'], $lang), ENT_QUOTES) ?>" class="chip chip-tag"># <?= htmlspecialchars($tg['name'], ENT_QUOTES) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?= blog_render_share_buttons($canonical, $tr['title']) ?>

            <?php if ($author): ?>
                <?= blog_render_author_card($author, $lang) ?>
            <?php endif; ?>
        </article>

        <!-- spacer column -->
        <aside class="hidden lg:block lg:col-span-2"></aside>
    </div>

    <!-- Related -->
    <?php if (!empty($related)): ?>
    <section class="container" style="margin-top:96px">
        <div class="flex items-end justify-between" style="margin-bottom:32px">
            <h2 class="font-sans font-bold tracking-tight text-ink" style="font-size:clamp(26px,3vw,32px);margin:0"><?= t('booked.related_posts') ?></h2>
            <a href="<?= htmlspecialchars(blog_listing_url($lang), ENT_QUOTES) ?>" class="text-[14px] font-semibold text-ink3 hover:text-ink"><?= t('booked.read_more') ?> →</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <?php foreach ($related as $r) echo blog_render_post_card_small($r); ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/_footer.php'; ?>
