<?php
/**
 * Blog helpers – slugi, render markdown→HTML, sanitizacija, SEO meta + JSON-LD.
 *
 * Pravilo: vsa logika, ki jo public stran (blog/post.php, blog/index.php, …)
 * potrebuje pred renderom, je tu. Renderer (CTA, kartice, share) je v
 * blog_renderer.php.
 */

require_once __DIR__ . '/Parsedown.php';
require_once __DIR__ . '/blog_renderer.php';

const BLOG_LANGS = ['sl', 'en', 'de', 'it', 'fr', 'hr', 'es', 'pt'];

/** Mapping HTML lang → og:locale */
const BLOG_OG_LOCALES = [
    'sl' => 'sl_SI', 'en' => 'en_US', 'de' => 'de_DE', 'it' => 'it_IT',
    'fr' => 'fr_FR', 'hr' => 'hr_HR', 'es' => 'es_ES', 'pt' => 'pt_PT',
];

/**
 * Pretvori naslov v URL-friendly slug.
 * Translit slovenskih in pogostih EU znakov.
 */
function blog_slug(string $text, int $maxLen = 80): string {
    $text = mb_strtolower($text, 'UTF-8');
    $map = [
        'č'=>'c','ć'=>'c','š'=>'s','ž'=>'z','đ'=>'d',
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','å'=>'a','ã'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ñ'=>'n','ý'=>'y','ÿ'=>'y',
        'ß'=>'ss','æ'=>'ae','œ'=>'oe',
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
        $text = preg_replace('/-[^-]*$/', '', $text); // ne reži na pol besede
    }
    return $text ?: 'post';
}

/**
 * Štetje besed v markdown stringu (po stripu HTML/markdown sintakse).
 */
function blog_word_count(string $md): int {
    // Odstrani code blocks, linke (ohrani besede), markdown sintakso
    $clean = preg_replace('/```[\s\S]*?```/', ' ', $md);
    $clean = preg_replace('/`[^`]+`/', ' ', $clean);
    $clean = preg_replace('/\!?\[([^\]]*)\]\([^)]*\)/', '$1', $clean);
    $clean = preg_replace('/[#>*_\-]+/', ' ', $clean);
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    if ($clean === '') return 0;
    return count(preg_split('/\s+/u', $clean));
}

/**
 * Bralni čas v minutah (200 wpm). Min 1.
 */
function blog_reading_time(int $wordCount): int {
    return max(1, (int)ceil($wordCount / 200));
}

/**
 * Vrne <picture> element z 4 srcset variantami za blog_media row.
 * Variants se shranjujejo kot JSON {480:"...",800:"...",1200:"...",1920:"..."}.
 */
function blog_render_picture(array $media, string $altText = '', string $sizes = '(max-width:768px) 100vw, 800px'): string {
    $variants = [];
    if (!empty($media['variants'])) {
        $variants = is_string($media['variants']) ? json_decode($media['variants'], true) : $media['variants'];
    }
    $base = blog_base_url();
    $resolveUrl = function ($path) use ($base) {
        if (preg_match('#^https?://#', $path)) return $path;
        return $base . '/' . ltrim($path, '/');
    };

    $bg = !empty($media['dominant_color']) ? ' style="background:' . htmlspecialchars($media['dominant_color'], ENT_QUOTES) . '"' : '';
    $alt = htmlspecialchars($altText, ENT_QUOTES);
    $w = (int)($media['width'] ?? 1200);
    $h = (int)($media['height'] ?? 675);

    if (!$variants) {
        $src = $resolveUrl($media['filename'] ?? '');
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="' . $alt . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async"' . $bg . '>';
    }

    $srcset = [];
    foreach ([480, 800, 1200, 1920] as $size) {
        if (!empty($variants[$size])) {
            $srcset[] = $resolveUrl($variants[$size]) . ' ' . $size . 'w';
        }
    }
    $defaultSrc = $resolveUrl($variants[1200] ?? $variants[800] ?? reset($variants));

    return '<img src="' . htmlspecialchars($defaultSrc, ENT_QUOTES) . '"'
        . ' srcset="' . htmlspecialchars(implode(', ', $srcset), ENT_QUOTES) . '"'
        . ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"'
        . ' alt="' . $alt . '"'
        . ' width="' . $w . '" height="' . $h . '"'
        . ' loading="lazy" decoding="async"'
        . $bg . '>';
}

/**
 * Render markdown → sanitized HTML + meta (TOC, word count, reading time).
 *
 * Pipeline:
 *   1. Parsedown: md → HTML (s shortcode placeholderji)
 *   2. Sanitize: allowlist tagov + atributov
 *   3. Resolve media: @@MEDIAREF:ID|alt@@ → <picture> z DB lookup-om
 *   4. Resolve CTA: @@CTA:type|args@@ → blog_render_cta()
 *   5. Parse TOC iz <h2> in <h3>
 *
 * @return array{html:string, toc:array, word_count:int, reading_time:int}
 */
function blog_render_md(string $md, string $lang = 'sl'): array {
    $parser = new Parsedown();
    $html = $parser->text($md);

    // Sanitize – allowlist tagov + atributov
    $html = blog_sanitize_html($html);

    // Resolve media references @@MEDIAREF:ID|alt@@
    $html = preg_replace_callback('/@@MEDIAREF:(\d+)\|([^|@]*)(?:\|([^@]*))?@@/', function ($m) use ($lang) {
        $mediaId      = (int)$m[1];
        $altFromMd    = $m[2];
        $capFromMd    = $m[3] ?? '';
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM blog_media WHERE id = ? LIMIT 1");
            $stmt->execute([$mediaId]);
            $media = $stmt->fetch();
            if (!$media) return '';
            $alt = $altFromMd;
            if (!empty($media['alt_translations'])) {
                $alts = is_string($media['alt_translations']) ? json_decode($media['alt_translations'], true) : $media['alt_translations'];
                $alt  = $alts[$lang] ?? $alts['sl'] ?? $altFromMd;
            }
            $caption = '';
            $cap = '';
            if (!empty($media['caption_translations'])) {
                $caps = is_string($media['caption_translations']) ? json_decode($media['caption_translations'], true) : $media['caption_translations'];
                $cap  = $caps[$lang] ?? $caps['sl'] ?? '';
            }
            if ($cap === '' && $capFromMd !== '') $cap = $capFromMd;
            if ($cap !== '') $caption = '<figcaption>' . htmlspecialchars($cap, ENT_QUOTES) . '</figcaption>';
            return '<figure class="bk-figure">' . blog_render_picture($media, $alt) . $caption . '</figure>';
        } catch (Throwable $e) {
            return '';
        }
    }, $html);

    // Resolve CTA placeholders @@CTA:type|args@@
    $html = preg_replace_callback('/@@CTA:([a-z_-]+)(?:\|([^@]*))?@@/', function ($m) use ($lang) {
        $type = $m[1];
        $args = $m[2] ?? '';
        return blog_render_cta($type, $args, $lang);
    }, $html);

    // Generate TOC iz h2/h3 (z anchor-i, ki jih je Parsedown že dodal)
    $toc = [];
    if (preg_match_all('/<h([23])\s+id="([^"]+)"[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            // Odstrani <a class="anchor">#</a> iz teksta in stripaj ostale tag-e
            $text = preg_replace('/<a[^>]*class="anchor"[^>]*>.*?<\/a>/i', '', $m[3]);
            $text = trim(strip_tags($text));
            $toc[] = [
                'level'  => (int)$m[1],
                'text'   => $text,
                'anchor' => $m[2],
            ];
        }
    }

    $words = blog_word_count($md);
    return [
        'html'         => $html,
        'toc'          => $toc,
        'word_count'   => $words,
        'reading_time' => blog_reading_time($words),
    ];
}

/**
 * Konzervativen HTML sanitizer – allowlist tagov + atributov.
 * Cilj: dovoli markdown output (h2-h6, p, lists, blockquote, code, em, strong,
 * a, img, picture, figure, figcaption, hr, br, table) a strip vse, kar bi
 * lahko bilo XSS (script, iframe, style, on*).
 */
function blog_sanitize_html(string $html): string {
    if ($html === '') return '';
    // Hitri pre-pass: odstrani <script>, <style>, <iframe>, <object>, <embed> bloke
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|textarea|button|select)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|textarea|button|select)\b[^>]*/?>#is', '', $html);

    // Strip on* atribute (onclick, onerror, onload, …) iz vseh tagov
    $html = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $html);
    $html = preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $html);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/i', '', $html);

    // Strip javascript: URL-je v href/src
    $html = preg_replace('#(href|src)\s*=\s*"\s*javascript:[^"]*"#i', '$1="#"', $html);
    $html = preg_replace("#(href|src)\s*=\s*'\s*javascript:[^']*'#i", '$1="#"', $html);

    return $html;
}

/**
 * Vrne polno hreflang mapping za članek (vsi approved prevodi + x-default).
 *
 * @param int $postId
 * @return array<string,string>  ['sl'=>'/booked/...','en'=>'/en/booked/...', 'x-default'=>'...']
 */
function blog_post_hreflangs(int $postId, string $masterLang = 'sl'): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT lang_code, slug FROM blog_post_translations
         WHERE post_id = ? AND status = 'approved'"
    );
    $stmt->execute([$postId]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['lang_code']] = blog_absolute_url(blog_post_url($r['slug'], $r['lang_code']));
    }
    if (isset($out[$masterLang])) {
        $out['x-default'] = $out[$masterLang];
    } elseif (!empty($out)) {
        $out['x-default'] = reset($out);
    }
    return $out;
}

/**
 * Renderira <link rel="alternate" hreflang> tags.
 */
function blog_render_hreflang_tags(array $hreflangs): string {
    $out = '';
    foreach ($hreflangs as $lang => $url) {
        $out .= '<link rel="alternate" hreflang="' . htmlspecialchars($lang, ENT_QUOTES) . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . "\n";
    }
    return $out;
}

/**
 * Renderira osnovne SEO meta tags + Open Graph + Twitter Card.
 *
 * @param array $opts {
 *   title, description, canonical, ogImage, ogType (default "article"),
 *   lang, hreflangs, robots (default "index,follow"), siteName
 * }
 */
function blog_render_meta_tags(array $opts): string {
    $title       = $opts['title'] ?? '';
    $description = $opts['description'] ?? '';
    $canonical   = $opts['canonical'] ?? '';
    $ogImage     = $opts['ogImage'] ?? '';
    $ogType      = $opts['ogType'] ?? 'article';
    $lang        = $opts['lang'] ?? 'sl';
    $robots      = $opts['robots'] ?? 'index,follow';
    $siteName    = $opts['siteName'] ?? 'Booked — by Rezble';
    $hreflangs   = $opts['hreflangs'] ?? [];
    $locale      = BLOG_OG_LOCALES[$lang] ?? 'sl_SI';

    $out  = '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>' . "\n";
    if ($description !== '') {
        $out .= '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">' . "\n";
    }
    $out .= '<meta name="robots" content="' . htmlspecialchars($robots, ENT_QUOTES) . '">' . "\n";
    if ($canonical !== '') {
        $out .= '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES) . '">' . "\n";
    }
    // Open Graph
    $out .= '<meta property="og:type" content="' . htmlspecialchars($ogType, ENT_QUOTES) . '">' . "\n";
    $out .= '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES) . '">' . "\n";
    if ($description !== '') {
        $out .= '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">' . "\n";
    }
    if ($canonical !== '') {
        $out .= '<meta property="og:url" content="' . htmlspecialchars($canonical, ENT_QUOTES) . '">' . "\n";
    }
    if ($ogImage !== '') {
        $out .= '<meta property="og:image" content="' . htmlspecialchars($ogImage, ENT_QUOTES) . '">' . "\n";
        $out .= '<meta property="og:image:width" content="1200">' . "\n";
        $out .= '<meta property="og:image:height" content="630">' . "\n";
    }
    $out .= '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES) . '">' . "\n";
    $out .= '<meta property="og:locale" content="' . htmlspecialchars($locale, ENT_QUOTES) . '">' . "\n";

    // Twitter
    $out .= '<meta name="twitter:card" content="' . ($ogImage ? 'summary_large_image' : 'summary') . '">' . "\n";
    $out .= '<meta name="twitter:title" content="' . htmlspecialchars($title, ENT_QUOTES) . '">' . "\n";
    if ($description !== '') {
        $out .= '<meta name="twitter:description" content="' . htmlspecialchars($description, ENT_QUOTES) . '">' . "\n";
    }
    if ($ogImage !== '') {
        $out .= '<meta name="twitter:image" content="' . htmlspecialchars($ogImage, ENT_QUOTES) . '">' . "\n";
    }

    // hreflang
    $out .= blog_render_hreflang_tags($hreflangs);

    // RSS
    $out .= '<link rel="alternate" type="application/rss+xml" title="Booked – ' . strtoupper($lang) . '" href="' . htmlspecialchars(blog_absolute_url(blog_feed_url($lang)), ENT_QUOTES) . '">' . "\n";

    return $out;
}

/**
 * JSON-LD BlogPosting schema.
 */
function blog_render_jsonld_post(array $post, array $translation, ?array $author, ?array $hero, ?array $category): string {
    $lang = $translation['lang_code'];
    $url  = blog_absolute_url(blog_post_url($translation['slug'], $lang));
    $imgs = [];
    if (!empty($hero['variants'])) {
        $variants = is_string($hero['variants']) ? json_decode($hero['variants'], true) : $hero['variants'];
        foreach ([1200, 800, 480] as $w) {
            if (!empty($variants[$w])) $imgs[] = blog_absolute_url('/' . ltrim($variants[$w], '/'));
        }
    }
    $authorObj = null;
    if ($author) {
        $authorObj = [
            '@type' => 'Person',
            'name'  => $author['name'],
            'url'   => blog_absolute_url(blog_author_url($author['slug'], $lang)),
        ];
    }
    $appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $publisher = [
        '@type' => 'Organization',
        'name'  => 'Rezble',
        'logo'  => [
            '@type' => 'ImageObject',
            'url'   => $appUrl . '/home/logo-512.png',
        ],
    ];

    $data = [
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'headline'         => $translation['title'],
        'description'      => $translation['meta_description'] ?? $translation['excerpt'] ?? '',
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => $url,
        ],
        'datePublished'    => !empty($post['published_at']) ? date('c', strtotime($post['published_at'])) : null,
        'dateModified'     => !empty($translation['updated_at']) ? date('c', strtotime($translation['updated_at'])) : null,
        'inLanguage'       => $lang,
        'wordCount'        => (int)($translation['word_count'] ?? 0),
        'publisher'        => $publisher,
    ];
    if ($authorObj)            $data['author']         = $authorObj;
    if (!empty($imgs))         $data['image']          = $imgs;
    if ($category && !empty($category['name'])) $data['articleSection'] = $category['name'];

    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>';
}

/**
 * JSON-LD BreadcrumbList schema.
 */
function blog_render_jsonld_breadcrumbs(array $items): string {
    if (empty($items)) return '';
    $list = [];
    foreach ($items as $i => $it) {
        $list[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $it['name'],
            'item'     => $it['url'] ? blog_absolute_url($it['url']) : null,
        ];
    }
    $data = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}

/**
 * Pridobi članek (post + translation) po slugu in jeziku, samo če je objavljen
 * in prevod approved (ali fallback na master, če prevod ni odobren).
 *
 * @return array|null  ['post'=>..., 'translation'=>..., 'fallback_used'=>bool]
 */
function blog_fetch_post_for_public(string $slug, string $lang): ?array {
    $pdo = getDB();
    // 1. Direct match: poišči translation z (slug, lang) ki je approved + post published
    $stmt = $pdo->prepare(
        "SELECT t.*, p.master_lang, p.published_at, p.scheduled_at, p.status AS post_status,
                p.category_id, p.author_id, p.hero_media_id, p.id AS post_id_full
         FROM blog_post_translations t
         JOIN blog_posts p ON p.id = t.post_id
         WHERE t.slug = ? AND t.lang_code = ?
           AND t.status = 'approved' AND p.status = 'published'
           AND (p.published_at IS NULL OR p.published_at <= NOW())
         LIMIT 1"
    );
    $stmt->execute([$slug, $lang]);
    $row = $stmt->fetch();
    if ($row) {
        return ['translation' => $row, 'fallback_used' => false];
    }
    return null;
}

/**
 * Inkrementiraj view counter za translation (non-blocking, best-effort).
 */
function blog_increment_view(int $translationId): void {
    try {
        $pdo = getDB();
        $pdo->prepare("UPDATE blog_post_translations SET view_count = view_count + 1 WHERE id = ?")->execute([$translationId]);
        $pdo->prepare("UPDATE blog_posts p JOIN blog_post_translations t ON t.post_id = p.id SET p.view_count = p.view_count + 1 WHERE t.id = ?")->execute([$translationId]);
    } catch (Throwable $e) {
        // nepomembno
    }
}

/**
 * Vrne sorodne članke za dani post (ista kategorija, isti jezik, exclude self).
 */
function blog_fetch_related(int $postId, ?int $categoryId, string $lang, int $limit = 3): array {
    $pdo = getDB();
    if ($categoryId) {
        $stmt = $pdo->prepare(
            "SELECT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
                    p.published_at, m.variants, m.dominant_color
             FROM blog_post_translations t
             JOIN blog_posts p ON p.id = t.post_id
             LEFT JOIN blog_media m ON m.id = p.hero_media_id
             WHERE p.category_id = ? AND p.id <> ?
               AND t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
               AND (p.published_at IS NULL OR p.published_at <= NOW())
             ORDER BY p.published_at DESC
             LIMIT " . (int)$limit
        );
        $stmt->execute([$categoryId, $postId, $lang]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
                    p.published_at, m.variants, m.dominant_color
             FROM blog_post_translations t
             JOIN blog_posts p ON p.id = t.post_id
             LEFT JOIN blog_media m ON m.id = p.hero_media_id
             WHERE p.id <> ? AND t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
               AND (p.published_at IS NULL OR p.published_at <= NOW())
             ORDER BY p.published_at DESC
             LIMIT " . (int)$limit
        );
        $stmt->execute([$postId, $lang]);
    }
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        if (!empty($r['variants'])) {
            $variants = is_string($r['variants']) ? json_decode($r['variants'], true) : $r['variants'];
            $r['hero_url'] = blog_base_url() . '/' . ltrim($variants[800] ?? $variants[1200] ?? reset($variants), '/');
        } else {
            $r['hero_url'] = '';
        }
    }
    return $rows;
}
