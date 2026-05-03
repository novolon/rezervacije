<?php
/**
 * Glavni sitemap.xml – vključuje blog listing, kategorije, tage, avtorje in objavljene
 * članke (vse jezikovne verzije z xhtml:link rel="alternate").
 *
 * Cache: zapis na disk za 1h da ne udari DB-ja na vsak request.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/blog_helpers.php';

$cacheFile = __DIR__ . '/sitemap.xml.cache';
$cacheTTL  = 3600;

header('Content-Type: application/xml; charset=UTF-8');

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    readfile($cacheFile);
    exit;
}

$pdo = getDB();

$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap-0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

$emitUrl = function (string $loc, ?string $lastmod, string $changefreq, string $priority, array $alternates = []) use (&$xml) {
    $xml .= "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1) . "</loc>\n";
    if ($lastmod) {
        $xml .= "    <lastmod>" . htmlspecialchars(date('Y-m-d', strtotime($lastmod)), ENT_QUOTES | ENT_XML1) . "</lastmod>\n";
    }
    $xml .= "    <changefreq>" . $changefreq . "</changefreq>\n";
    $xml .= "    <priority>" . $priority . "</priority>\n";
    foreach ($alternates as $hreflang => $url) {
        $xml .= '    <xhtml:link rel="alternate" hreflang="' . htmlspecialchars($hreflang, ENT_QUOTES | ENT_XML1) . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_XML1) . '"/>' . "\n";
    }
    $xml .= "  </url>\n";
};

// 1. Blog listing per jezik (samo če ima objave)
$langStmt = $pdo->query(
    "SELECT DISTINCT t.lang_code FROM blog_post_translations t
     JOIN blog_posts p ON p.id = t.post_id
     WHERE t.status = 'approved' AND p.status = 'published'
       AND (p.published_at IS NULL OR p.published_at <= NOW())"
);
$activeLangs = $langStmt->fetchAll(PDO::FETCH_COLUMN) ?: ['sl'];

$listingAlternates = [];
foreach ($activeLangs as $lc) {
    $listingAlternates[$lc] = blog_absolute_url(blog_listing_url($lc));
}
$listingAlternates['x-default'] = $listingAlternates['sl'] ?? reset($listingAlternates);

foreach ($activeLangs as $lc) {
    $emitUrl(blog_absolute_url(blog_listing_url($lc)), null, 'daily', '0.7', $listingAlternates);
}

// 2. Kategorije per jezik
$catStmt = $pdo->query("SELECT id, slug FROM blog_categories WHERE is_active = 1");
foreach ($catStmt->fetchAll() as $c) {
    $alts = [];
    foreach ($activeLangs as $lc) {
        $alts[$lc] = blog_absolute_url(blog_category_url($c['slug'], $lc));
    }
    $alts['x-default'] = $alts['sl'] ?? reset($alts);
    foreach ($activeLangs as $lc) {
        $emitUrl(blog_absolute_url(blog_category_url($c['slug'], $lc)), null, 'weekly', '0.6', $alts);
    }
}

// 3. Avtorji
$authStmt = $pdo->query("SELECT slug FROM blog_authors WHERE is_active = 1");
foreach ($authStmt->fetchAll() as $a) {
    $alts = [];
    foreach ($activeLangs as $lc) {
        $alts[$lc] = blog_absolute_url(blog_author_url($a['slug'], $lc));
    }
    $alts['x-default'] = $alts['sl'] ?? reset($alts);
    foreach ($activeLangs as $lc) {
        $emitUrl(blog_absolute_url(blog_author_url($a['slug'], $lc)), null, 'weekly', '0.4', $alts);
    }
}

// 4. Posti (vsak post ena <url> entry per approved jezik)
$postStmt = $pdo->query(
    "SELECT p.id, p.master_lang, p.published_at, t.updated_at
     FROM blog_posts p
     JOIN blog_post_translations t ON t.post_id = p.id AND t.lang_code = p.master_lang
     WHERE p.status = 'published'
       AND (p.published_at IS NULL OR p.published_at <= NOW())"
);
$posts = $postStmt->fetchAll();
foreach ($posts as $p) {
    $trStmt = $pdo->prepare(
        "SELECT lang_code, slug, updated_at FROM blog_post_translations
         WHERE post_id = ? AND status = 'approved'"
    );
    $trStmt->execute([$p['id']]);
    $trs = $trStmt->fetchAll();
    if (empty($trs)) continue;

    $alts = [];
    foreach ($trs as $tr) {
        $alts[$tr['lang_code']] = blog_absolute_url(blog_post_url($tr['slug'], $tr['lang_code']));
    }
    $alts['x-default'] = $alts[$p['master_lang']] ?? reset($alts);

    foreach ($trs as $tr) {
        $lastmod = $tr['updated_at'] ?: $p['published_at'];
        $emitUrl(
            blog_absolute_url(blog_post_url($tr['slug'], $tr['lang_code'])),
            $lastmod,
            'monthly',
            '0.8',
            $alts
        );
    }
}

$xml .= '</urlset>' . "\n";

@file_put_contents($cacheFile, $xml);
echo $xml;
