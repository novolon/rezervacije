<?php
/**
 * Blog renderer – CTA bannerji, sidebar widget-i, listing kartice.
 * Markup ujema design system iz blog-reference (paper/cream/forest/terracotta).
 *
 * Vsi user-facing teksti pridejo iz lang fajlov (booked.* ključi),
 * ne iz DB-ja. DB hrani samo članke same.
 */

if (!function_exists('t')) {
    require_once __DIR__ . '/lang.php';
}

/** Vrne BASE_PATH brez trailing slash. */
function blog_base_url(): string {
    return rtrim(BASE_PATH, '/');
}

/**
 * Vrne uporabnikov preferirani blog jezik iz `rzlang` cookie-ja (postavi ga app login / lang switcher),
 * ali null če ni nastavljen / je nevalden / je 'sl'.
 *
 * Uporabljaj za redirect z `/booked` na `/{lang}/booked`, ko je app v ne-SL jeziku.
 */
function blog_user_preferred_lang(): ?string {
    $c = $_COOKIE['rzlang'] ?? '';
    if (!is_string($c) || $c === '' || $c === 'sl') return null;
    if (!in_array($c, BLOG_LANGS, true)) return null;
    return $c;
}

/** Public URL za blog post v želenem jeziku. */
function blog_post_url(string $slug, string $lang = 'sl'): string {
    $base = blog_base_url();
    if ($lang === 'sl') return $base . '/booked/' . $slug;
    return $base . '/' . $lang . '/booked/' . $slug;
}
function blog_listing_url(string $lang = 'sl'): string {
    $base = blog_base_url();
    return ($lang === 'sl') ? $base . '/booked' : $base . '/' . $lang . '/booked';
}
function blog_category_url(string $slug, string $lang = 'sl'): string {
    $base = blog_base_url();
    return ($lang === 'sl') ? $base . '/booked/c/' . $slug : $base . '/' . $lang . '/booked/c/' . $slug;
}
function blog_tag_url(string $slug, string $lang = 'sl'): string {
    $base = blog_base_url();
    return ($lang === 'sl') ? $base . '/booked/t/' . $slug : $base . '/' . $lang . '/booked/t/' . $slug;
}
function blog_author_url(string $slug, string $lang = 'sl'): string {
    $base = blog_base_url();
    return ($lang === 'sl') ? $base . '/booked/avtor/' . $slug : $base . '/' . $lang . '/booked/author/' . $slug;
}
function blog_feed_url(string $lang = 'sl'): string {
    return blog_base_url() . '/booked/feed-' . $lang . '.xml';
}
function blog_absolute_url(string $relative): string {
    $appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if (preg_match('#^https?://#', $relative)) return $relative;
    return $appUrl . $relative;
}

/**
 * Renderira CTA shortcode kot stiliran banner.
 * Tipi: register, pricing, demo, subscribe.
 */
function blog_render_cta(string $type, string $args = '', string $lang = 'sl'): string {
    $base = blog_base_url();
    $type = strtolower($type);

    switch ($type) {
        case 'register':
            return '<div class="cta cta-register">'
                . '<h4>' . t('booked.cta.register.heading') . '</h4>'
                . '<p>' . t('booked.cta.register.body') . '</p>'
                . '<a href="' . htmlspecialchars($base . '/register.php', ENT_QUOTES) . '" class="btn btn-primary btn-lg">' . t('booked.cta.register.button') . ' →</a>'
                . '</div>';

        case 'pricing':
            return '<div class="cta cta-pricing">'
                . '<div>'
                . '<h4>' . t('booked.cta.pricing.heading') . '</h4>'
                . '</div>'
                . '<a href="' . htmlspecialchars($base . '/home/#pricing', ENT_QUOTES) . '" class="btn btn-dark">' . t('booked.cta.pricing.button') . ' →</a>'
                . '</div>';

        case 'demo':
            return '<div class="cta cta-demo">'
                . '<div>'
                . '<h4>' . t('booked.cta.demo.heading') . '</h4>'
                . '<p>' . t('booked.cta.demo.body') . '</p>'
                . '</div>'
                . '<a href="mailto:hello@rezervacije.si?subject=Demo" class="btn btn-outline">' . t('booked.cta.demo.button') . ' →</a>'
                . '</div>';

        case 'subscribe':
            return blog_render_subscribe_inline('inline_post', $lang);

        default:
            return '';
    }
}

/**
 * Inline newsletter form (cream gradient subscribe).
 */
/**
 * GDPR consent checkbox za subscribe forme. Vsebuje povezavo na pages/privacy.php.
 *
 * Lang ključ booked.subscribe.gdpr.label podpira {link_open} in {link_close}
 * placeholdere za poljubno postavitev linka znotraj prevedene fraze.
 */
function blog_render_subscribe_gdpr(string $idPrefix = 'bksub'): string {
    $base       = blog_base_url();
    $checkboxId = $idPrefix . '-gdpr-' . substr(md5(microtime(true)), 0, 4);
    $privacyUrl = $base . '/pages/privacy.php';

    $rawLabel = t_raw('booked.subscribe.gdpr.label');
    // Vstavi link okrog besede med {link_open} in {link_close}
    if (strpos($rawLabel, '{link_open}') !== false) {
        $linkOpen  = '<a href="' . htmlspecialchars($privacyUrl, ENT_QUOTES) . '" target="_blank" rel="noopener" style="text-decoration:underline">';
        $linkClose = '</a>';
        // HTML escape ostalih delov, ohrani samo naš link tag
        $parts = preg_split('/(\{link_open\}|\{link_close\})/', $rawLabel);
        $built = '';
        $inLink = false;
        foreach ($parts as $part) {
            if ($part === '{link_open}') { $built .= $linkOpen; $inLink = true; continue; }
            if ($part === '{link_close}') { $built .= $linkClose; $inLink = false; continue; }
            $built .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        }
        $labelHtml = $built;
    } else {
        // Fallback: na koncu samo dodaj povezavo na privacy
        $labelHtml = htmlspecialchars($rawLabel, ENT_QUOTES, 'UTF-8')
            . ' <a href="' . htmlspecialchars($privacyUrl, ENT_QUOTES) . '" target="_blank" rel="noopener" style="text-decoration:underline">'
            . htmlspecialchars(t_raw('booked.subscribe.gdpr.link_text'), ENT_QUOTES, 'UTF-8')
            . '</a>';
    }

    return '<label for="' . $checkboxId . '" class="bk-gdpr-consent" style="display:flex;gap:8px;align-items:flex-start;font-size:12px;line-height:1.4;margin-top:8px;color:var(--text-2,inherit)">'
        . '<input type="checkbox" id="' . $checkboxId . '" name="gdpr_consent" value="1" required style="margin-top:2px;flex-shrink:0">'
        . '<span>' . $labelHtml . '</span>'
        . '</label>';
}

function blog_render_subscribe_inline(string $source = 'inline_post', string $lang = 'sl'): string {
    $base = blog_base_url();
    return '<div class="cta cta-subscribe">'
        . '<h4>' . t('booked.subscribe.heading') . '</h4>'
        . '<p>' . t('booked.subscribe.text') . '</p>'
        . '<form action="' . htmlspecialchars($base . '/api/blog_subscribe.php', ENT_QUOTES) . '" method="post" data-bk-subscribe>'
        . '<input type="hidden" name="source" value="' . htmlspecialchars($source, ENT_QUOTES) . '">'
        . '<input type="hidden" name="lang" value="' . htmlspecialchars($lang, ENT_QUOTES) . '">'
        . '<input type="email" name="email" required placeholder="' . t('booked.subscribe.email_placeholder') . '" class="input">'
        . '<button type="submit" class="btn btn-primary">' . t('booked.subscribe.cta') . '</button>'
        . blog_render_subscribe_gdpr('inline')
        . '<p class="bk-success" hidden>' . t('booked.subscribe.confirm_sent') . '</p>'
        . '<p class="bk-error" hidden></p>'
        . '</form>'
        . '</div>';
}

/**
 * Sidebar varianta subscribe forme — manjša, kot widget.
 */
function blog_render_subscribe_sidebar(string $lang = 'sl'): string {
    $base = blog_base_url();
    return '<div class="cta cta-subscribe" style="margin:0">'
        . '<h4 style="font-size:16px">' . t('booked.subscribe.heading') . '</h4>'
        . '<p style="font-size:13px">' . t('booked.subscribe.text') . '</p>'
        . '<form action="' . htmlspecialchars($base . '/api/blog_subscribe.php', ENT_QUOTES) . '" method="post" data-bk-subscribe style="flex-direction:column">'
        . '<input type="hidden" name="source" value="sidebar">'
        . '<input type="hidden" name="lang" value="' . htmlspecialchars($lang, ENT_QUOTES) . '">'
        . '<input type="email" name="email" required placeholder="' . t('booked.subscribe.email_placeholder') . '" class="input">'
        . '<button type="submit" class="btn btn-primary">' . t('booked.subscribe.cta') . '</button>'
        . blog_render_subscribe_gdpr('sidebar')
        . '<p class="bk-success" hidden>' . t('booked.subscribe.confirm_sent') . '</p>'
        . '<p class="bk-error" hidden></p>'
        . '</form>'
        . '</div>';
}

/**
 * Footer (forest dark) varianta subscribe forme.
 */
function blog_render_subscribe_footer(string $lang = 'sl'): string {
    $base = blog_base_url();
    return '<form action="' . htmlspecialchars($base . '/api/blog_subscribe.php', ENT_QUOTES) . '" method="post" data-bk-subscribe style="display:flex;flex-direction:column;gap:8px;max-width:24rem">'
        . '<div style="display:flex;gap:8px">'
        . '<input type="hidden" name="source" value="footer">'
        . '<input type="hidden" name="lang" value="' . htmlspecialchars($lang, ENT_QUOTES) . '">'
        . '<input type="email" name="email" required placeholder="' . t('booked.subscribe.email_placeholder') . '" class="input" style="flex:1;background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.15);color:#fff">'
        . '<button type="submit" class="btn btn-primary btn-sm">' . t('booked.subscribe.cta') . '</button>'
        . '</div>'
        . blog_render_subscribe_gdpr('footer')
        . '<p class="bk-success" hidden style="font-size:13px;color:#9ee2b3;margin:4px 0 0">' . t('booked.subscribe.confirm_sent') . '</p>'
        . '<p class="bk-error" hidden style="font-size:13px;color:#ffb9a7;margin:4px 0 0"></p>'
        . '</form>';
}

/**
 * Listing post card (grid).
 *
 * @param array $post Result row z naslednjimi polji:
 *   slug, title, excerpt, lang_code, hero_url, author_name, category_slug, category_name,
 *   published_at, reading_time_minutes, dominant_color, hero_alt
 */
function blog_render_post_card(array $post, string $variant = 'default'): string {
    $url = blog_post_url($post['slug'], $post['lang_code']);
    $hero = '';
    if (!empty($post['hero_url'])) {
        $heroSrc = htmlspecialchars($post['hero_url'], ENT_QUOTES);
        $alt = htmlspecialchars($post['hero_alt'] ?? $post['title'] ?? '', ENT_QUOTES);
        $hero = '<div class="post-thumb"><img src="' . $heroSrc . '" alt="' . $alt . '" loading="lazy" decoding="async"></div>';
    } else {
        $hero = '<div class="post-thumb"></div>';
    }

    $catChip = '';
    if (!empty($post['category_slug']) && !empty($post['category_name'])) {
        $catChip = '<span class="chip chip-cat" style="margin-bottom:12px">' . htmlspecialchars($post['category_name'], ENT_QUOTES) . '</span>';
    }

    $titleSize = $variant === 'small' ? 'font-size:19px' : 'font-size:24px';
    $titleLh   = 'line-height:1.18';

    $meta = [];
    if (!empty($post['published_at'])) {
        $date = date('j. n. Y', strtotime($post['published_at']));
        $meta[] = '<span>' . htmlspecialchars($date, ENT_QUOTES) . '</span>';
    }
    if (!empty($post['reading_time_minutes'])) {
        $meta[] = '<span>' . t('booked.minute_read', ['minutes' => (int)$post['reading_time_minutes']]) . '</span>';
    }
    $metaRow = !empty($meta) ? '<div class="meta" style="margin-top:20px">' . implode('', $meta) . '</div>' : '';

    $excerpt = '';
    if (!empty($post['excerpt'])) {
        $excerpt = '<p class="line-clamp-3" style="font-size:15px;color:var(--text-2);margin-top:12px;line-height:1.6">' . htmlspecialchars($post['excerpt'], ENT_QUOTES) . '</p>';
    }

    return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="card card-naked block" style="display:block">'
        . $hero
        . '<div style="padding-top:20px">'
        . $catChip
        . '<h2 class="font-sans font-bold tracking-tight" style="' . $titleSize . ';' . $titleLh . ';color:var(--ink);margin-top:12px">' . htmlspecialchars($post['title'], ENT_QUOTES) . '</h2>'
        . $excerpt
        . $metaRow
        . '</div>'
        . '</a>';
}

/**
 * Compact post card (3-col related/author grid).
 */
function blog_render_post_card_small(array $post): string {
    return blog_render_post_card($post, 'small');
}

/**
 * Sidebar widget – seznam zadnjih objav.
 */
function blog_render_sidebar_recent(array $posts, string $lang = 'sl'): string {
    if (empty($posts)) return '';
    $items = '';
    foreach ($posts as $p) {
        $url = blog_post_url($p['slug'], $p['lang_code'] ?? $lang);
        $cat = !empty($p['category_name']) ? ' · ' . htmlspecialchars($p['category_name'], ENT_QUOTES) : '';
        $date = !empty($p['published_at']) ? date('j. n. Y', strtotime($p['published_at'])) : '';
        $items .= '<li style="padding:0"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="group" style="display:block">'
            . '<div style="font-size:14.5px;font-weight:600;color:var(--ink);line-height:1.32" class="group-hover:text-terracotta">' . htmlspecialchars($p['title'], ENT_QUOTES) . '</div>'
            . '<div style="font-size:12.5px;color:var(--text-2);margin-top:4px">' . $date . $cat . '</div>'
            . '</a></li>';
    }
    return '<div>'
        . '<h3 class="eyebrow" style="margin:0 0 16px">' . t('booked.sidebar.recent') . '</h3>'
        . '<ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:16px">' . $items . '</ul>'
        . '</div>';
}

/**
 * Sidebar widget – kategorije.
 */
function blog_render_sidebar_categories(array $categories, string $lang = 'sl'): string {
    if (empty($categories)) return '';
    $items = '';
    foreach ($categories as $c) {
        $url = blog_category_url($c['slug'], $lang);
        $count = !empty($c['post_count']) ? '<span style="color:var(--muted);font-size:12.5px">' . (int)$c['post_count'] . '</span>' : '';
        $items .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:14.5px;color:var(--text)"><span>' . htmlspecialchars($c['name'], ENT_QUOTES) . '</span>' . $count . '</a></li>';
    }
    return '<div>'
        . '<h3 class="eyebrow" style="margin:0 0 16px">' . t('booked.sidebar.categories') . '</h3>'
        . '<ul style="list-style:none;padding:0;margin:0">' . $items . '</ul>'
        . '</div>';
}

/**
 * Pagination (pill-style).
 */
function blog_render_pagination(int $page, int $totalPages, string $baseUrl): string {
    if ($totalPages <= 1) return '';
    $sep = (strpos($baseUrl, '?') === false) ? '?' : '&';
    $prevDisabled = $page <= 1;
    $nextDisabled = $page >= $totalPages;

    $pageLink = function (int $p) use ($baseUrl, $sep, $page) {
        $url = $baseUrl . ($p > 1 ? $sep . 'page=' . $p : '');
        $isActive = $p === $page;
        $cls = $isActive
            ? 'background:var(--ink);color:#fff'
            : 'color:var(--text)';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;font-size:14px;font-weight:600;text-decoration:none;' . $cls . '">' . $p . '</a>';
    };

    $arrowLeft  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>';
    $arrowRight = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>';

    $html = '<nav style="margin-top:56px;display:flex;align-items:center;justify-content:center;gap:8px" aria-label="' . t('booked.pagination.label') . '">';
    if ($prevDisabled) {
        $html .= '<span class="iconbtn" style="opacity:.4">' . $arrowLeft . '</span>';
    } else {
        $html .= '<a href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($page - 1), ENT_QUOTES) . '" class="iconbtn" aria-label="' . t('booked.pagination.prev') . '">' . $arrowLeft . '</a>';
    }

    // Stranske številke (max 5 vidnih + ellipsis)
    $maxButtons = 5;
    $start = max(1, $page - 2);
    $end   = min($totalPages, $start + $maxButtons - 1);
    if ($end - $start < $maxButtons - 1) $start = max(1, $end - $maxButtons + 1);

    if ($start > 1) {
        $html .= $pageLink(1);
        if ($start > 2) $html .= '<span style="padding:0 4px;color:var(--muted)">…</span>';
    }
    for ($p = $start; $p <= $end; $p++) {
        $html .= $pageLink($p);
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<span style="padding:0 4px;color:var(--muted)">…</span>';
        $html .= $pageLink($totalPages);
    }

    if ($nextDisabled) {
        $html .= '<span class="iconbtn" style="opacity:.4">' . $arrowRight . '</span>';
    } else {
        $html .= '<a href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($page + 1), ENT_QUOTES) . '" class="iconbtn" aria-label="' . t('booked.pagination.next') . '">' . $arrowRight . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

/**
 * Share buttons – social + copy link (icon style).
 */
function blog_render_share_buttons(string $url, string $title): string {
    $u = rawurlencode($url);
    $t = rawurlencode($title);
    return '<div style="margin-top:32px;display:flex;align-items:center;gap:12px;flex-wrap:wrap" data-bk-share data-url="' . htmlspecialchars($url, ENT_QUOTES) . '">'
        . '<span style="font-size:13px;color:var(--text-2);font-weight:500;margin-right:4px">' . t('booked.share') . ':</span>'
        . '<a class="iconbtn" href="https://twitter.com/intent/tweet?url=' . $u . '&text=' . $t . '" target="_blank" rel="noopener" aria-label="X">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2H21l-6.49 7.41L22.5 22h-6.81l-4.74-6.2L5.4 22H2.643l6.94-7.93L1.5 2h6.96l4.28 5.66L18.244 2Zm-1.19 18.4h1.86L7.04 3.5H5.05l12.004 16.9Z"/></svg>'
        . '</a>'
        . '<a class="iconbtn" href="https://www.linkedin.com/sharing/share-offsite/?url=' . $u . '" target="_blank" rel="noopener" aria-label="LinkedIn">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M4.98 3.5C4.98 4.88 3.87 6 2.5 6S0 4.88 0 3.5 1.12 1 2.5 1s2.48 1.12 2.48 2.5zM.22 8h4.56v14H.22zM8.5 8h4.37v1.92h.06c.61-1.16 2.1-2.38 4.32-2.38C21.6 7.54 23 9.7 23 13.32V22h-4.56v-7.7c0-1.84-.03-4.2-2.56-4.2-2.56 0-2.95 2-2.95 4.06V22H8.38V8z"/></svg>'
        . '</a>'
        . '<a class="iconbtn" href="https://www.facebook.com/sharer/sharer.php?u=' . $u . '" target="_blank" rel="noopener" aria-label="Facebook">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.5-3.9 3.78-3.9 1.1 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>'
        . '</a>'
        . '<a class="iconbtn" href="mailto:?subject=' . $t . '&body=' . $u . '" aria-label="E-pošta">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>'
        . '</a>'
        . '<button type="button" class="iconbtn" data-bk-copy data-copied="' . t('booked.share.copied') . '" aria-label="' . t('booked.share.copy') . '">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>'
        . '</button>'
        . '</div>';
}

/**
 * Author card (in-article).
 */
function blog_render_author_card(array $author, string $lang = 'sl'): string {
    if (empty($author)) return '';
    $bio = '';
    if (!empty($author['bio_translations'])) {
        $bios = is_string($author['bio_translations']) ? json_decode($author['bio_translations'], true) : $author['bio_translations'];
        $bio  = $bios[$lang] ?? $bios['sl'] ?? '';
    }
    $avatar = '';
    if (!empty($author['avatar_url'])) {
        $avatar = '<img class="ring-1 ring-border1" src="' . htmlspecialchars($author['avatar_url'], ENT_QUOTES) . '" alt="' . htmlspecialchars($author['name'], ENT_QUOTES) . '" width="64" height="64" style="width:64px;height:64px;border-radius:50%;object-fit:cover;flex-shrink:0">';
    } else {
        $initial = mb_substr($author['name'] ?? '?', 0, 1, 'UTF-8');
        $avatar = '<div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--forest-3),var(--forest-2));color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;flex-shrink:0">' . htmlspecialchars($initial, ENT_QUOTES) . '</div>';
    }
    $url = blog_author_url($author['slug'] ?? '', $lang);
    return '<aside style="margin-top:48px;padding:24px;border:1px solid var(--border);background:#fff;border-radius:22px;display:flex;gap:20px;align-items:flex-start">'
        . $avatar
        . '<div>'
        . '<div class="eyebrow" style="margin-bottom:4px">' . t('booked.author.heading') . '</div>'
        . '<h3 style="font-size:18px;font-weight:700;color:var(--ink);margin:0 0 8px">' . htmlspecialchars($author['name'], ENT_QUOTES) . '</h3>'
        . ($bio ? '<p style="font-size:14.5px;color:var(--text-2);line-height:1.6;margin:0">' . htmlspecialchars($bio, ENT_QUOTES) . '</p>' : '')
        . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-flex;align-items:center;gap:6px;margin-top:12px;font-size:14px;font-weight:600;color:var(--terracotta)">'
        . t('booked.author.all_posts') . ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>'
        . '</a>'
        . '</div>'
        . '</aside>';
}
