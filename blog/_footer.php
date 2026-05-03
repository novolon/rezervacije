<?php
/**
 * Booked – shared footer (forest dark, 4-col grid).
 */
$bkLang = $bkLang ?? get_lang();
$bkBase = $bkBase ?? blog_base_url();

$pdo = isset($pdo) ? $pdo : null;
if (!$pdo) {
    try { $pdo = getDB(); } catch (Throwable $e) { $pdo = null; }
}
$footerCats = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT c.slug, COALESCE(ct.name, c.slug) AS name
             FROM blog_categories c
             LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = ?
             WHERE c.is_active = 1 ORDER BY c.display_order, c.id LIMIT 5"
        );
        $stmt->execute([$bkLang]);
        $footerCats = $stmt->fetchAll();
    } catch (Throwable $e) {}
}
?>
<footer class="footer">
    <div class="container grid grid-cols-1 md:grid-cols-12 gap-10">
        <div class="md:col-span-5">
            <div class="flex items-center gap-3 mb-4">
                <span class="brandmark">B</span>
                <span class="text-white text-[17px] font-bold tracking-tight">Booked</span>
            </div>
            <p style="font-size:14px;color:rgba(255,255,255,.6);max-width:24rem;line-height:1.6;margin-bottom:24px"><?= t('booked.tagline') ?></p>
            <?= blog_render_subscribe_footer($bkLang) ?>
        </div>
        <div class="md:col-span-3">
            <h5>Booked</h5>
            <a href="<?= htmlspecialchars(blog_listing_url($bkLang), ENT_QUOTES) ?>"><?= t('booked.read_more') ?></a>
            <?php foreach ($footerCats as $c): ?>
                <a href="<?= htmlspecialchars(blog_category_url($c['slug'], $bkLang), ENT_QUOTES) ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></a>
            <?php endforeach; ?>
            <a href="<?= htmlspecialchars($bkBase . '/booked/feed-' . $bkLang . '.xml', ENT_QUOTES) ?>">RSS</a>
        </div>
        <div class="md:col-span-2">
            <h5>Rezble</h5>
            <a href="<?= htmlspecialchars($bkBase . '/home/', ENT_QUOTES) ?>">Produkt</a>
            <a href="<?= htmlspecialchars($bkBase . '/home/#pricing', ENT_QUOTES) ?>"><?= t('booked.cta.pricing.button') ?></a>
            <a href="<?= htmlspecialchars($bkBase . '/register.php', ENT_QUOTES) ?>"><?= t('booked.cta.register.button') ?></a>
            <a href="mailto:hello@rezervacije.si">Kontakt</a>
        </div>
        <div class="md:col-span-2">
            <h5>Pravno</h5>
            <a href="<?= htmlspecialchars($bkBase . '/pages/terms.php', ENT_QUOTES) ?>">Pogoji</a>
            <a href="<?= htmlspecialchars($bkBase . '/pages/privacy.php', ENT_QUOTES) ?>">Zasebnost</a>
        </div>
    </div>
    <div class="container" style="margin-top:48px;padding-top:32px;border-top:1px solid rgba(255,255,255,.1);display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;font-size:13px;color:rgba(255,255,255,.5)">
        <span>© <?= date('Y') ?> Rezble · Ljubljana</span>
        <span>Booked v1.0</span>
    </div>
</footer>

<button class="scrollTop" type="button" aria-label="Top">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m18 15-6-6-6 6"/></svg>
</button>

<script>window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
window.t = function(k,p){var s=window.__T__[k]||k;if(p)for(var x in p)s=s.replace('{'+x+'}',p[x]);return s;};</script>
<script src="<?= htmlspecialchars($bkBase . '/assets/js/blog_public.js', ENT_QUOTES) ?>" defer></script>
</body>
</html>
