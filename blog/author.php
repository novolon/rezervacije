<?php
/**
 * Booked – avtor (centered hero z avatar + grid).
 * URL: /booked/avtor/{slug}  ali  /{lang}/booked/author/{slug}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

$slug = trim($_GET['slug'] ?? '');
if (!isset($_GET['lang']) && $slug !== '') {
    $pref = blog_user_preferred_lang();
    if ($pref) { header('Location: ' . blog_author_url($slug, $pref), true, 302); exit; }
}
$lang = $_GET['lang'] ?? 'sl';
if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

$pdo = getDB();

$aStmt = $pdo->prepare("SELECT * FROM blog_authors WHERE slug = ? AND is_active = 1 LIMIT 1");
$aStmt->execute([$slug]);
$author = $aStmt->fetch();
if (!$author) {
    http_response_code(404);
    $bk = ['lang'=>$lang,'title'=>t('booked.error.not_found.title')];
    include __DIR__ . '/_header.php';
    echo '<main class="container text-center" style="padding:96px 20px"><h2 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:36px">' . t('booked.error.not_found.title') . '</h2></main>';
    include __DIR__ . '/_footer.php';
    exit;
}

$bio = '';
if (!empty($author['bio_translations'])) {
    $bios = is_string($author['bio_translations']) ? json_decode($author['bio_translations'], true) : $author['bio_translations'];
    $bio = $bios[$lang] ?? $bios['sl'] ?? '';
}
$socials = [];
if (!empty($author['social_links'])) {
    $socials = is_string($author['social_links']) ? json_decode($author['social_links'], true) : $author['social_links'];
}

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND p.author_id = ? AND (p.published_at IS NULL OR p.published_at <= NOW())"
);
$totalStmt->execute([$lang, $author['id']]);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
            p.published_at,
            c.slug AS category_slug, ct.name AS category_name,
            m.variants AS hero_variants, m.dominant_color, m.alt_translations
     FROM blog_post_translations t
     JOIN blog_posts p           ON p.id = t.post_id
     LEFT JOIN blog_categories c ON c.id = p.category_id
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = t.lang_code
     LEFT JOIN blog_media m      ON m.id = p.hero_media_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND p.author_id = ? AND (p.published_at IS NULL OR p.published_at <= NOW())
     ORDER BY p.published_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$lang, $author['id']]);
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

$canonical = blog_absolute_url(blog_author_url($author['slug'], $lang) . ($page > 1 ? '?page=' . $page : ''));

$bk = [
    'lang'        => $lang,
    'title'       => $author['name'] . ' | Booked',
    'description' => $bio ?: ($author['name'] . ' – ' . t('booked.author.heading')),
    'canonical'   => $canonical,
    'og_type'     => 'profile',
];
include __DIR__ . '/_header.php';
?>

<header class="container text-center" style="padding:80px 20px">
    <?php if (!empty($author['avatar_url'])): ?>
        <img src="<?= htmlspecialchars($author['avatar_url'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($author['name'], ENT_QUOTES) ?>" class="ring-1 ring-border1 shadow-md mx-auto" width="88" height="88" style="width:88px;height:88px;border-radius:50%;object-fit:cover;margin:0 auto">
    <?php else: ?>
        <div class="ring-1 ring-border1 shadow-md mx-auto" style="width:88px;height:88px;border-radius:50%;margin:0 auto;background:linear-gradient(135deg,var(--forest-3),var(--forest-2));color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:32px">
            <?= htmlspecialchars(mb_substr($author['name'] ?? '?', 0, 1, 'UTF-8'), ENT_QUOTES) ?>
        </div>
    <?php endif; ?>
    <span class="eyebrow block" style="margin-top:24px"><?= t('booked.author.heading') ?></span>
    <h1 class="font-sans font-extrabold tracking-tight text-ink" style="font-size:clamp(36px,5vw,56px);line-height:1;letter-spacing:-0.025em;margin:12px 0 0">
        <?= htmlspecialchars($author['name'], ENT_QUOTES) ?>
    </h1>
    <?php if ($bio): ?>
        <p class="text-ink3 leading-relaxed mx-auto" style="font-size:clamp(17px,2vw,18px);max-width:36rem;margin-top:20px"><?= htmlspecialchars($bio, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if (!empty($socials)): ?>
        <div class="flex items-center justify-center gap-3" style="margin-top:24px">
            <?php foreach ($socials as $key => $url): ?>
                <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" class="iconbtn" aria-label="<?= htmlspecialchars($key, ENT_QUOTES) ?>" target="_blank" rel="noopener">
                    <?php if ($key === 'twitter' || $key === 'x'): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2H21l-6.49 7.41L22.5 22h-6.81l-4.74-6.2L5.4 22H2.643l6.94-7.93L1.5 2h6.96l4.28 5.66L18.244 2Z"/></svg>
                    <?php elseif ($key === 'linkedin'): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M4.98 3.5C4.98 4.88 3.87 6 2.5 6S0 4.88 0 3.5 1.12 1 2.5 1s2.48 1.12 2.48 2.5zM.22 8h4.56v14H.22zM8.5 8h4.37v1.92h.06c.61-1.16 2.1-2.38 4.32-2.38C21.6 7.54 23 9.7 23 13.32V22h-4.56v-7.7c0-1.84-.03-4.2-2.56-4.2-2.56 0-2.95 2-2.95 4.06V22H8.38V8z"/></svg>
                    <?php else: ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="text-[13px] text-ink3" style="margin-top:24px"><?= (int)$total ?> člankov</div>
</header>

<main class="container" style="padding:0 20px 64px">
    <div style="border-top:1px solid var(--border);padding-top:40px">
        <h2 class="font-sans font-bold tracking-tight text-ink" style="font-size:clamp(22px,3vw,26px);margin:0 0 32px"><?= t('booked.author.all_posts') ?></h2>
        <?php if (empty($rows)): ?>
            <div class="text-center" style="padding:64px 20px;color:var(--muted)">
                <p><?= t('booked.empty_state') ?></p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php foreach ($rows as $r) echo blog_render_post_card_small($r); ?>
            </div>
            <?= blog_render_pagination($page, $totalPages, blog_author_url($author['slug'], $lang)) ?>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/_footer.php'; ?>
