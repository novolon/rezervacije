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
$bkJsonLd      = $bk['jsonld']      ?? '';
$bkRobots      = $bk['robots']      ?? 'index,follow';
$bkBase        = blog_base_url();
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($bkLang, ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                        $url = $_SERVER['REQUEST_URI'] ?? '/';
                        $sep = (strpos($url, '?') === false) ? '?' : '&';
                        $url = preg_replace('/([?&])lang=[a-z]{2}/', '$1lang=' . $lc, $url, 1, $cnt);
                        if (!$cnt) $url .= $sep . 'lang=' . $lc;
                    ?>
                        <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" style="display:block;padding:8px 12px;border-radius:6px;font-size:13.5px;color:<?= $lc === $bkLang ? 'var(--ink)' : 'var(--text-2)' ?>;font-weight:<?= $lc === $bkLang ? 600 : 500 ?>"><?= strtoupper($lc) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
            <a href="<?= htmlspecialchars($bkBase . '/register.php', ENT_QUOTES) ?>" class="btn btn-primary btn-sm"><?= t('booked.cta.register.button') ?></a>
        </div>
    </div>
</header>
