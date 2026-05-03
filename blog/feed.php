<?php
/**
 * Booked – RSS 2.0 feed.
 * URL: /booked/feed-{lang}.xml  →  blog/feed.php?lang={lang}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

$lang = $_GET['lang'] ?? 'sl';
if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';

// Force lang load (lahko se kličemo direktno brez ?lang=)
_rz_load_lang($lang);

$pdo = getDB();

$stmt = $pdo->prepare(
    "SELECT t.slug, t.title, t.excerpt, t.content_html, t.lang_code, t.updated_at,
            p.published_at, a.name AS author_name
     FROM blog_post_translations t
     JOIN blog_posts p           ON p.id = t.post_id
     LEFT JOIN blog_authors a    ON a.id = p.author_id
     WHERE t.lang_code = ? AND t.status = 'approved' AND p.status = 'published'
       AND (p.published_at IS NULL OR p.published_at <= NOW())
     ORDER BY p.published_at DESC
     LIMIT 30"
);
$stmt->execute([$lang]);
$rows = $stmt->fetchAll();

header('Content-Type: application/rss+xml; charset=UTF-8');

$siteUrl  = rtrim(APP_URL, '/');
$selfUrl  = blog_absolute_url(blog_feed_url($lang));
$channelTitle = 'Booked — ' . strtoupper($lang);
$channelDesc  = t('booked.meta_description');
$buildDate = date(DATE_RSS);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
    <title><?= htmlspecialchars($channelTitle, ENT_QUOTES | ENT_XML1) ?></title>
    <link><?= htmlspecialchars(blog_absolute_url(blog_listing_url($lang)), ENT_QUOTES | ENT_XML1) ?></link>
    <description><?= htmlspecialchars($channelDesc, ENT_QUOTES | ENT_XML1) ?></description>
    <language><?= htmlspecialchars($lang, ENT_QUOTES | ENT_XML1) ?></language>
    <lastBuildDate><?= $buildDate ?></lastBuildDate>
    <atom:link href="<?= htmlspecialchars($selfUrl, ENT_QUOTES | ENT_XML1) ?>" rel="self" type="application/rss+xml"/>
    <generator>Rezble Booked</generator>

    <?php foreach ($rows as $r):
        $url = blog_absolute_url(blog_post_url($r['slug'], $r['lang_code']));
        $pub = !empty($r['published_at']) ? date(DATE_RSS, strtotime($r['published_at'])) : $buildDate;
    ?>
    <item>
        <title><?= htmlspecialchars($r['title'], ENT_QUOTES | ENT_XML1) ?></title>
        <link><?= htmlspecialchars($url, ENT_QUOTES | ENT_XML1) ?></link>
        <guid isPermaLink="true"><?= htmlspecialchars($url, ENT_QUOTES | ENT_XML1) ?></guid>
        <pubDate><?= $pub ?></pubDate>
        <?php if (!empty($r['author_name'])): ?>
            <dc:creator><?= htmlspecialchars($r['author_name'], ENT_QUOTES | ENT_XML1) ?></dc:creator>
        <?php endif; ?>
        <?php if (!empty($r['excerpt'])): ?>
            <description><?= htmlspecialchars($r['excerpt'], ENT_QUOTES | ENT_XML1) ?></description>
        <?php endif; ?>
        <content:encoded><![CDATA[<?= $r['content_html'] ?>]]></content:encoded>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
