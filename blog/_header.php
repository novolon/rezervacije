<?php
/**
 * Booked – shared header (sticky paper navbar + brandmark + lang/CTA).
 *
 * Pred include nastavi $bk array:
 *   $bk = [
 *     'title'=>..., 'description'=>..., 'canonical'=>..., 'og_image'=>...,
 *     'og_type'=>'website|article|profile', 'lang'=>..., 'hreflangs'=>[...],
 *     'jsonld'=>'<script>...</script>',
 *   ];
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/lang.php';
}
require_once __DIR__ . '/../includes/blog_helpers.php';

$bk = isset($bk) && is_array($bk) ? $bk : [];
$bkLang        = $bk['lang']        ?? get_lang();
$bkTitle       = $bk['title']       ?? (t('booked.brand') . ' – ' . t('booked.tagline'));
$bkDesc        = $bk['description'] ?? t('booked.meta_description');
$bkCanonical   = $bk['canonical']   ?? '';
$bkOgImage     = $bk['og_image']    ?? '';
$bkOgType      = $bk['og_type']     ?? 'website';
$bkHreflangs   = $bk['hreflangs']   ?? [];
$bkLangUrls    = $bk['lang_switcher_urls'] ?? $bkHreflangs; // UI switcher; lahko vsebuje preview-only URL-je
$bkJsonLd      = $bk['jsonld']      ?? '';
$bkRobots      = $bk['robots']      ?? 'index,follow';
$bkBase        = blog_base_url();
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($bkLang, ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($bkBase . '/assets/images/icon.svg', ENT_QUOTES) ?>">
<?= blog_render_meta_tags([
    'title'       => $bkTitle,
    'description' => $bkDesc,
    'canonical'   => $bkCanonical,
    'ogImage'     => $bkOgImage,
    'ogType'      => $bkOgType,
    'lang'        => $bkLang,
    'hreflangs'   => $bkHreflangs,
    'robots'      => $bkRobots,
    'siteName'    => 'Booked — by Rezble',
]) ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400;1,8..60,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($bkBase . '/assets/css/blog.css', ENT_QUOTES) ?>">
<?= $bkJsonLd ?>
</head>
<body class="font-sans">
<div class="progress" id="progress" aria-hidden="true"><div class="progress-bar" id="progressBar"></div></div>

<header class="navbar">
    <div class="container flex items-center justify-between" style="height:64px">
        <a href="<?= htmlspecialchars(blog_listing_url($bkLang), ENT_QUOTES) ?>" class="flex items-center gap-3 group">
            <span class="brandmark">B</span>
            <span class="flex items-baseline gap-2">
                <span class="text-[17px] font-bold tracking-tight text-ink">Booked</span>
                <span class="hidden sm:inline text-[12px] text-ink3"><?= t('booked.tagline') ?></span>
            </span>
        </a>
        <nav class="hidden md:flex items-center gap-1" aria-label="Booked">
            <a href="<?= htmlspecialchars(blog_listing_url($bkLang), ENT_QUOTES) ?>" class="nav-link">Booked</a>
            <a href="<?= htmlspecialchars($bkBase . '/home/#pricing', ENT_QUOTES) ?>" class="nav-link"><?= t('booked.cta.pricing.button') ?></a>
        </nav>
        <div class="flex items-center gap-2">
            <details class="hidden sm:block" style="position:relative">
                <summary class="nav-link" style="list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                    <?= strtoupper($bkLang) ?>
                </summary>
                <div class="dropdown" style="position:absolute;right:0;top:calc(100% + 4px);min-width:120px;padding:6px;z-index:60">
                    <?php foreach (BLOG_LANGS as $lc):
                        // Switcher povezavo postavi klicalna stran (lang_switcher_urls / hreflangs).
                        // Če prevod ne obstaja, padi nazaj na listing v ciljnem jeziku.
                        $url = $bkLangUrls[$lc] ?? '';
                        if (!$url) $url = blog_listing_url($lc);
                        // Pretvori absolute (https://app.rezervacije.si/...) v relative na isti domeni
                        $url = preg_replace('#^https?://[^/]+#', '', $url);
                        $hasTranslation = !empty($bkLangUrls[$lc]);
                    ?>
                        <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"
                           style="display:block;padding:8px 12px;border-radius:6px;font-size:13.5px;color:<?= $lc === $bkLang ? 'var(--ink)' : 'var(--text-2)' ?>;font-weight:<?= $lc === $bkLang ? 600 : 500 ?>"
                           title="<?= $hasTranslation ? '' : 'Prevod ne obstaja — vodi na seznam v tem jeziku' ?>">
                            <?= strtoupper($lc) ?><?= $hasTranslation ? '' : ' ↗' ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
            <a href="<?= htmlspecialchars($bkBase . '/register.php', ENT_QUOTES) ?>" class="btn btn-primary btn-sm"><?= t('booked.cta.register.button') ?></a>
        </div>
    </div>
</header>

<?php
// ── Subscribe / unsubscribe success modal (po confirm linkih iz emaila) ──
$_bk_modal_show = false; $_bk_modal_key = '';
if (!empty($_GET['subscribed'])) {
    $_bk_modal_show = true;
    $_bk_modal_key  = 'success';
} elseif (!empty($_GET['unsubscribed'])) {
    $_bk_modal_show = true;
    $_bk_modal_key  = 'unsubscribed_modal';
}
if ($_bk_modal_show):
    $_bk_modal_title = $_bk_modal_key === 'success'
        ? t('booked.subscribe.success_modal.title')
        : t('booked.subscribe.unsubscribed_modal.title');
    $_bk_modal_text  = $_bk_modal_key === 'success'
        ? t('booked.subscribe.success_modal.text')
        : t('booked.subscribe.unsubscribed_modal.text');
    $_bk_modal_close = $_bk_modal_key === 'success'
        ? t('booked.subscribe.success_modal.close')
        : t('booked.subscribe.unsubscribed_modal.close');
?>
<div id="bk-subscribe-modal" role="dialog" aria-modal="true" aria-labelledby="bk-subscribe-modal-title"
     style="position:fixed;inset:0;background:rgba(20,18,15,.55);z-index:9998;display:flex;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px)">
    <div style="background:#fff;border-radius:18px;padding:32px 28px;max-width:440px;width:100%;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.25);position:relative">
        <div style="width:64px;height:64px;border-radius:999px;background:#D1FADF;margin:0 auto 16px;display:flex;align-items:center;justify-content:center">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2F7D52" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <?php if ($_bk_modal_key === 'success'): ?>
                    <path d="M20 6 9 17l-5-5"/>
                <?php else: ?>
                    <path d="M18 6 6 18M6 6l12 12"/>
                <?php endif; ?>
            </svg>
        </div>
        <h2 id="bk-subscribe-modal-title" style="font:600 22px/1.25 'Source Serif 4',serif;margin:0 0 12px;color:var(--ink,#1a1a1a)"><?= $_bk_modal_title ?></h2>
        <p style="font-size:14.5px;line-height:1.55;color:var(--text-2,#5a5a5a);margin:0 0 24px"><?= $_bk_modal_text ?></p>
        <button type="button" id="bk-subscribe-modal-close" class="btn btn-primary" style="width:100%;justify-content:center"><?= $_bk_modal_close ?></button>
    </div>
</div>
<script>
(function(){
    var modal = document.getElementById('bk-subscribe-modal');
    if (!modal) return;
    function close(){
        modal.style.display = 'none';
        // Odstrani query param brez reload-a
        try {
            var u = new URL(location.href);
            u.searchParams.delete('subscribed');
            u.searchParams.delete('unsubscribed');
            history.replaceState(null, '', u.toString());
        } catch(_){}
    }
    document.getElementById('bk-subscribe-modal-close').addEventListener('click', close);
    modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
})();
</script>
<?php endif; ?>
