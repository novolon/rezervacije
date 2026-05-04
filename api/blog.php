<?php
/**
 * Blog API – CRUD + workflow + iskanje.
 *
 * Vsi write endpointi: require_superadmin().
 * Iskalni endpoint je javen (ima samo objavljene approved članke).
 *
 * Akcije se lahko pošljejo kot ?action= (GET) ali v JSON body (POST/PUT).
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/blog_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method !== 'GET') {
    $body = get_body();
    if (empty($action) && !empty($body['action'])) $action = $body['action'];
}

// ─── Javne akcije (brez auth) ────────────────────────────────────
if ($action === 'search') {
    $lang = $_GET['lang'] ?? 'sl';
    if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';
    $q = trim($_GET['q'] ?? '');
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 8)));
    if ($q === '' || mb_strlen($q) < 2) {
        json_response(true, []);
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
                    COALESCE(ct.name, c.slug) AS category,
                    MATCH(t.title, t.excerpt, t.content_md) AGAINST (? IN NATURAL LANGUAGE MODE) AS rel
             FROM blog_post_translations t
             JOIN blog_posts p           ON p.id = t.post_id
             LEFT JOIN blog_categories c            ON c.id = p.category_id
             LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = t.lang_code
             WHERE t.lang_code = ?
               AND t.status = 'approved' AND p.status = 'published'
               AND (p.published_at IS NULL OR p.published_at <= NOW())
               AND MATCH(t.title, t.excerpt, t.content_md) AGAINST (? IN NATURAL LANGUAGE MODE) > 0
             ORDER BY rel DESC
             LIMIT $limit"
        );
        $stmt->execute([$q, $lang, $q]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'title'   => $r['title'],
                'excerpt' => $r['excerpt'],
                'url'     => blog_post_url($r['slug'], $r['lang_code']),
                'category' => $r['category'],
                'reading_time_minutes' => (int)$r['reading_time_minutes'],
            ];
        }
        json_response(true, $out);
    } catch (Throwable $e) {
        // Fallback če FULLTEXT index manjka — preprost LIKE
        $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
        $stmt = $pdo->prepare(
            "SELECT t.slug, t.title, t.excerpt, t.lang_code, t.reading_time_minutes,
                    COALESCE(ct.name, c.slug) AS category
             FROM blog_post_translations t
             JOIN blog_posts p           ON p.id = t.post_id
             LEFT JOIN blog_categories c            ON c.id = p.category_id
             LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = t.lang_code
             WHERE t.lang_code = ?
               AND t.status = 'approved' AND p.status = 'published'
               AND (p.published_at IS NULL OR p.published_at <= NOW())
               AND (t.title LIKE ? OR t.excerpt LIKE ? OR t.content_md LIKE ?)
             ORDER BY p.published_at DESC
             LIMIT $limit"
        );
        $stmt->execute([$lang, $like, $like, $like]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'title'   => $r['title'],
                'excerpt' => $r['excerpt'],
                'url'     => blog_post_url($r['slug'], $r['lang_code']),
                'category' => $r['category'],
                'reading_time_minutes' => (int)$r['reading_time_minutes'],
            ];
        }
        json_response(true, $out);
    }
}

// ─── Vsi ostali endpointi: superadmin only ───────────────────────
$session = require_superadmin();

// ─── Helpers ─────────────────────────────────────────────────────
function _bk_render_translation_html(array $tr, string $lang): array {
    $rendered = blog_render_md($tr['content_md'] ?? '', $lang);
    return [
        'content_html'         => $rendered['html'],
        'table_of_contents'    => $rendered['toc'],
        'reading_time_minutes' => $rendered['reading_time'],
        'word_count'           => $rendered['word_count'],
    ];
}

function _bk_load_post(PDO $pdo, int $postId): ?array {
    $stmt = $pdo->prepare(
        "SELECT p.*, a.name AS author_name, c.slug AS category_slug
         FROM blog_posts p
         LEFT JOIN blog_authors a    ON a.id = p.author_id
         LEFT JOIN blog_categories c ON c.id = p.category_id
         WHERE p.id = ?"
    );
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    if (!$post) return null;

    $tStmt = $pdo->prepare(
        "SELECT id, lang_code, slug, title, excerpt, content_md, content_html,
                meta_title, meta_description, status, ai_translated,
                approved_by, approved_at, view_count, reading_time_minutes,
                word_count, table_of_contents, og_image_media_id,
                created_at, updated_at
         FROM blog_post_translations WHERE post_id = ?"
    );
    $tStmt->execute([$postId]);
    $translations = [];
    foreach ($tStmt->fetchAll() as $row) {
        $row['table_of_contents'] = $row['table_of_contents'] ? json_decode($row['table_of_contents'], true) : [];
        $translations[$row['lang_code']] = $row;
    }

    $tagStmt = $pdo->prepare("SELECT tag_id FROM blog_post_tags WHERE post_id = ?");
    $tagStmt->execute([$postId]);
    $post['tag_ids'] = array_map('intval', $tagStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($post['hero_media_id'])) {
        $mStmt = $pdo->prepare("SELECT id, filename, variants, dominant_color, width, height FROM blog_media WHERE id = ?");
        $mStmt->execute([(int)$post['hero_media_id']]);
        $hm = $mStmt->fetch();
        if ($hm) {
            $variants = $hm['variants'] ? json_decode($hm['variants'], true) : [];
            $src = $variants[800] ?? $variants[480] ?? $variants[1200] ?? reset($variants) ?? $hm['filename'];
            $hm['preview_url'] = $src ? BASE_PATH . '/' . ltrim($src, '/') : '';
            $hm['variants'] = $variants;
            $post['hero_media'] = $hm;
        } else {
            $post['hero_media'] = null;
        }
    } else {
        $post['hero_media'] = null;
    }

    $post['translations'] = $translations;
    return $post;
}

// ─── Action dispatcher ───────────────────────────────────────────

// Aliasi za enostavnejše direktne klice
if ($action === 'get')  $action = 'get_post';
if ($action === 'list') $action = 'list_posts';

// LIST POSTS — vrne tabular pogled za admin Posts seznam
if ($action === 'list_posts') {
    $status   = $_GET['status'] ?? '';
    $catId    = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    $authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : 0;
    $search   = trim($_GET['search'] ?? '');
    $limit    = max(10, min(200, (int)($_GET['limit'] ?? 50)));

    $where = ['1=1'];
    $params = [];
    if ($status !== '' && in_array($status, ['draft','pending_review','scheduled','published','archived'], true)) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    if ($catId)    { $where[] = 'p.category_id = ?'; $params[] = $catId; }
    if ($authorId) { $where[] = 'p.author_id = ?';   $params[] = $authorId; }
    if ($search !== '') {
        $where[] = '(SELECT COUNT(*) FROM blog_post_translations t WHERE t.post_id = p.id AND t.title LIKE ?) > 0';
        $params[] = '%' . $search . '%';
    }

    $sql = "SELECT p.id, p.master_lang, p.status, p.published_at, p.scheduled_at,
                   p.ai_generated, p.view_count, p.created_at, p.updated_at,
                   a.name AS author_name,
                   c.slug AS category_slug,
                   (SELECT COALESCE(ct.name, c.slug) FROM blog_category_translations ct
                      WHERE ct.category_id = c.id AND ct.lang_code = p.master_lang LIMIT 1) AS category_name,
                   (SELECT t.title FROM blog_post_translations t
                      WHERE t.post_id = p.id AND t.lang_code = p.master_lang LIMIT 1) AS master_title,
                   (SELECT t.slug FROM blog_post_translations t
                      WHERE t.post_id = p.id AND t.lang_code = p.master_lang LIMIT 1) AS master_slug,
                   (SELECT GROUP_CONCAT(CONCAT(t2.lang_code,':',t2.status) ORDER BY t2.lang_code SEPARATOR ',')
                      FROM blog_post_translations t2 WHERE t2.post_id = p.id) AS translations_summary
            FROM blog_posts p
            LEFT JOIN blog_authors a    ON a.id = p.author_id
            LEFT JOIN blog_categories c ON c.id = p.category_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.updated_at DESC
            LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();
    foreach ($posts as &$p) {
        $matrix = [];
        if (!empty($p['translations_summary'])) {
            foreach (explode(',', $p['translations_summary']) as $pair) {
                [$lc, $st] = explode(':', $pair);
                $matrix[$lc] = $st;
            }
        }
        $p['translation_matrix'] = $matrix;
        unset($p['translations_summary']);
    }
    json_response(true, $posts);
}

// GET POST
if ($action === 'get_post') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'id obvezen.', 400);
    $post = _bk_load_post($pdo, $id);
    if (!$post) json_response(false, null, 'Članek ne obstaja.', 404);
    json_response(true, $post);
}

// CREATE POST (master članek)
if ($action === 'create_post' && $method === 'POST') {
    $body = $body ?? get_body();
    $masterLang = $body['master_lang'] ?? 'sl';
    if (!in_array($masterLang, BLOG_LANGS, true)) json_response(false, null, 'Neveljaven jezik.', 400);
    $title = trim($body['title'] ?? '');
    if ($title === '') json_response(false, null, 'Naslov obvezen.', 400);

    $slug = trim($body['slug'] ?? '');
    if ($slug === '') $slug = blog_slug($title);

    // Preveri unikatnost slug-a
    $check = $pdo->prepare("SELECT 1 FROM blog_post_translations WHERE lang_code = ? AND slug = ?");
    $check->execute([$masterLang, $slug]);
    if ($check->fetchColumn()) {
        $slug = $slug . '-' . substr(md5(microtime(true)), 0, 6);
    }

    $contentMd = $body['content_md'] ?? '';
    $rendered = blog_render_md($contentMd, $masterLang);

    try {
        $pdo->beginTransaction();
        $insP = $pdo->prepare(
            "INSERT INTO blog_posts (master_lang, category_id, author_id, hero_media_id, status, ai_generated, ai_topic, ai_prompt, ai_model, created_by)
             VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)"
        );
        $insP->execute([
            $masterLang,
            !empty($body['category_id']) ? (int)$body['category_id'] : null,
            !empty($body['author_id']) ? (int)$body['author_id'] : null,
            !empty($body['hero_media_id']) ? (int)$body['hero_media_id'] : null,
            !empty($body['ai_generated']) ? 1 : 0,
            $body['ai_topic'] ?? null,
            $body['ai_prompt'] ?? null,
            $body['ai_model'] ?? null,
            (int)$session['user_id'],
        ]);
        $postId = (int)$pdo->lastInsertId();

        $insT = $pdo->prepare(
            "INSERT INTO blog_post_translations
             (post_id, lang_code, slug, title, excerpt, content_md, content_html,
              meta_title, meta_description, table_of_contents, reading_time_minutes,
              word_count, status, ai_translated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 0)"
        );
        $insT->execute([
            $postId,
            $masterLang,
            $slug,
            $title,
            $body['excerpt'] ?? null,
            $contentMd,
            $rendered['html'],
            $body['meta_title'] ?? null,
            $body['meta_description'] ?? null,
            json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE),
            $rendered['reading_time'],
            $rendered['word_count'],
        ]);

        // Tagi
        if (!empty($body['tag_ids']) && is_array($body['tag_ids'])) {
            $tagStmt = $pdo->prepare("INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
            foreach ($body['tag_ids'] as $tagId) {
                $tagStmt->execute([$postId, (int)$tagId]);
            }
        }

        $pdo->commit();
        json_response(true, ['id' => $postId, 'slug' => $slug]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('blog create_post: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri ustvarjanju.', 500);
    }
}

// SAVE TRANSLATION (autosave + manual)
if ($action === 'save_translation' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $postId = (int)($body['post_id'] ?? 0);
    $lang   = $body['lang_code'] ?? '';
    if (!$postId || !in_array($lang, BLOG_LANGS, true)) json_response(false, null, 'post_id in lang_code obvezna.', 400);

    $existsStmt = $pdo->prepare("SELECT 1 FROM blog_posts WHERE id = ?");
    $existsStmt->execute([$postId]);
    if (!$existsStmt->fetchColumn()) json_response(false, null, 'Članek ne obstaja.', 404);

    $title = trim($body['title'] ?? '');
    $slug  = trim($body['slug'] ?? '');
    if ($title === '') json_response(false, null, 'Naslov obvezen.', 400);
    if ($slug === '') $slug = blog_slug($title);

    // Preveri unikatnost slug-a (razen za isti translation)
    $checkSlug = $pdo->prepare(
        "SELECT id FROM blog_post_translations
         WHERE lang_code = ? AND slug = ? AND post_id <> ? LIMIT 1"
    );
    $checkSlug->execute([$lang, $slug, $postId]);
    if ($checkSlug->fetchColumn()) {
        $slug = $slug . '-' . substr(md5(microtime(true)), 0, 6);
    }

    $contentMd = $body['content_md'] ?? '';
    $rendered = blog_render_md($contentMd, $lang);

    // Preveri, ali translation že obstaja
    $exStmt = $pdo->prepare("SELECT id, content_md FROM blog_post_translations WHERE post_id = ? AND lang_code = ?");
    $exStmt->execute([$postId, $lang]);
    $existing = $exStmt->fetch();

    try {
        $pdo->beginTransaction();
        if ($existing) {
            // Revizija – shrani trenuten content_md kot snapshot pred prepisom
            if (!empty($existing['content_md']) && $existing['content_md'] !== $contentMd) {
                $pdo->prepare("INSERT INTO blog_revisions (translation_id, content_md_snapshot, edited_by) VALUES (?, ?, ?)")
                    ->execute([(int)$existing['id'], $existing['content_md'], (int)$session['user_id']]);
            }
            $pdo->prepare(
                "UPDATE blog_post_translations
                 SET slug = ?, title = ?, excerpt = ?, content_md = ?, content_html = ?,
                     meta_title = ?, meta_description = ?, og_image_media_id = ?,
                     table_of_contents = ?, reading_time_minutes = ?, word_count = ?
                 WHERE id = ?"
            )->execute([
                $slug, $title,
                $body['excerpt'] ?? null,
                $contentMd, $rendered['html'],
                $body['meta_title'] ?? null,
                $body['meta_description'] ?? null,
                !empty($body['og_image_media_id']) ? (int)$body['og_image_media_id'] : null,
                json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE),
                $rendered['reading_time'],
                $rendered['word_count'],
                (int)$existing['id'],
            ]);
            $trId = (int)$existing['id'];
        } else {
            $pdo->prepare(
                "INSERT INTO blog_post_translations
                 (post_id, lang_code, slug, title, excerpt, content_md, content_html,
                  meta_title, meta_description, og_image_media_id, table_of_contents,
                  reading_time_minutes, word_count, status, ai_translated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
            )->execute([
                $postId, $lang, $slug, $title,
                $body['excerpt'] ?? null,
                $contentMd, $rendered['html'],
                $body['meta_title'] ?? null,
                $body['meta_description'] ?? null,
                !empty($body['og_image_media_id']) ? (int)$body['og_image_media_id'] : null,
                json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE),
                $rendered['reading_time'],
                $rendered['word_count'],
                !empty($body['ai_translated']) ? 1 : 0,
            ]);
            $trId = (int)$pdo->lastInsertId();
        }
        // Posodobi post.updated_at
        $pdo->prepare("UPDATE blog_posts SET updated_at = NOW() WHERE id = ?")->execute([$postId]);
        $pdo->commit();
        json_response(true, [
            'translation_id' => $trId,
            'slug'           => $slug,
            'reading_time_minutes' => $rendered['reading_time'],
            'word_count'     => $rendered['word_count'],
            'toc_count'      => count($rendered['toc']),
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('blog save_translation: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// SAVE POST META (kategorija/avtor/hero/tagi/master_lang)
if ($action === 'save_post_meta' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $postId = (int)($body['post_id'] ?? 0);
    if (!$postId) json_response(false, null, 'post_id obvezen.', 400);

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE blog_posts
             SET category_id = ?, author_id = ?, hero_media_id = ?
             WHERE id = ?"
        )->execute([
            isset($body['category_id']) && $body['category_id'] !== '' ? (int)$body['category_id'] : null,
            isset($body['author_id'])   && $body['author_id']   !== '' ? (int)$body['author_id']   : null,
            isset($body['hero_media_id']) && $body['hero_media_id'] !== '' ? (int)$body['hero_media_id'] : null,
            $postId,
        ]);
        if (isset($body['tag_ids']) && is_array($body['tag_ids'])) {
            $pdo->prepare("DELETE FROM blog_post_tags WHERE post_id = ?")->execute([$postId]);
            $tagStmt = $pdo->prepare("INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
            foreach ($body['tag_ids'] as $tagId) $tagStmt->execute([$postId, (int)$tagId]);
        }
        $pdo->commit();
        json_response(true, null, 'Shranjeno.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('blog save_post_meta: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

// CHANGE TRANSLATION STATUS (approve / reject / pending_review / draft)
if ($action === 'change_translation_status' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $trId = (int)($body['translation_id'] ?? 0);
    $status = $body['status'] ?? '';
    if (!$trId) json_response(false, null, 'translation_id obvezen.', 400);
    if (!in_array($status, ['draft','pending_review','approved','rejected'], true)) {
        json_response(false, null, 'Neveljaven status.', 400);
    }
    $approvedBy = $approvedAt = null;
    if ($status === 'approved') {
        $approvedBy = (int)$session['user_id'];
        $approvedAt = date('Y-m-d H:i:s');
    }
    $pdo->prepare(
        "UPDATE blog_post_translations SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?"
    )->execute([$status, $approvedBy, $approvedAt, $trId]);
    json_response(true, null, 'Status spremenjen.');
}

// CHANGE POST STATUS (draft / pending_review / scheduled / published / archived)
if ($action === 'change_post_status' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $postId = (int)($body['post_id'] ?? 0);
    $status = $body['status'] ?? '';
    $publishedAt = $body['published_at'] ?? null;
    $scheduledAt = $body['scheduled_at'] ?? null;
    if (!$postId) json_response(false, null, 'post_id obvezen.', 400);
    if (!in_array($status, ['draft','pending_review','scheduled','published','archived'], true)) {
        json_response(false, null, 'Neveljaven status.', 400);
    }

    // Convenience: če je published in publishedAt prazen, nastavi NOW
    if ($status === 'published' && empty($publishedAt)) {
        $publishedAt = date('Y-m-d H:i:s');
    }
    if ($status === 'scheduled' && empty($scheduledAt)) {
        json_response(false, null, 'Datum objave je obvezen za scheduled status.', 400);
    }

    $approvedBy = $approvedAt = null;
    if ($status === 'published') {
        $approvedBy = (int)$session['user_id'];
        $approvedAt = date('Y-m-d H:i:s');
    }

    $pdo->prepare(
        "UPDATE blog_posts SET status = ?, published_at = ?, scheduled_at = ?, approved_by = ?, approved_at = ?
         WHERE id = ?"
    )->execute([$status, $publishedAt, $scheduledAt, $approvedBy, $approvedAt, $postId]);
    json_response(true, null, 'Status spremenjen.');
}

// DELETE POST
if ($action === 'delete_post' && in_array($method, ['POST','DELETE'], true)) {
    $body = $body ?? get_body();
    $postId = (int)($body['post_id'] ?? $_GET['id'] ?? 0);
    if (!$postId) json_response(false, null, 'post_id obvezen.', 400);
    $pdo->prepare("DELETE FROM blog_posts WHERE id = ?")->execute([$postId]);
    json_response(true, null, 'Izbrisano.');
}

// ─── KATEGORIJE ──────────────────────────────────────────────────
if ($action === 'list_categories') {
    $cats = $pdo->query("SELECT id, slug, display_order, is_active FROM blog_categories ORDER BY display_order, id")->fetchAll();
    $allTrans = $pdo->query("SELECT category_id, lang_code, name, description FROM blog_category_translations")->fetchAll();
    $transMap = [];
    foreach ($allTrans as $t) {
        $transMap[(int)$t['category_id']][$t['lang_code']] = ['name' => $t['name'], 'desc' => $t['description']];
    }
    $out = [];
    foreach ($cats as $c) {
        $tid = (int)$c['id'];
        $names = [];
        $descs = [];
        foreach ($transMap[$tid] ?? [] as $lc => $d) {
            $names[$lc] = $d['name'];
            if ($d['desc'] !== null && $d['desc'] !== '') $descs[$lc] = $d['desc'];
        }
        $c['names'] = $names;
        $c['descriptions'] = $descs;
        $out[] = $c;
    }
    json_response(true, $out);
}

if ($action === 'save_category' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? 0);
    $slug = trim($body['slug'] ?? '');
    if ($slug === '') json_response(false, null, 'slug obvezen.', 400);
    $names = $body['names'] ?? [];
    $descs = $body['descriptions'] ?? [];
    $displayOrder = (int)($body['display_order'] ?? 0);
    $isActive = !empty($body['is_active']) ? 1 : 0;

    try {
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare("UPDATE blog_categories SET slug = ?, display_order = ?, is_active = ? WHERE id = ?")
                ->execute([$slug, $displayOrder, $isActive, $id]);
            $pdo->prepare("DELETE FROM blog_category_translations WHERE category_id = ?")->execute([$id]);
        } else {
            $pdo->prepare("INSERT INTO blog_categories (slug, display_order, is_active) VALUES (?, ?, ?)")
                ->execute([$slug, $displayOrder, $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        $insT = $pdo->prepare(
            "INSERT INTO blog_category_translations (category_id, lang_code, name, description) VALUES (?, ?, ?, ?)"
        );
        foreach (BLOG_LANGS as $lc) {
            $name = trim($names[$lc] ?? '');
            $desc = isset($descs[$lc]) ? trim($descs[$lc]) : '';
            if ($name !== '') $insT->execute([$id, $lc, $name, $desc ?: null]);
        }
        $pdo->commit();
        json_response(true, ['id' => $id]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('blog save_category: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju kategorije.', 500);
    }
}

if ($action === 'delete_category' && in_array($method, ['POST','DELETE'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'id obvezen.', 400);
    $pdo->prepare("DELETE FROM blog_categories WHERE id = ?")->execute([$id]);
    json_response(true, null, 'Izbrisano.');
}

// ─── TAGI ────────────────────────────────────────────────────────
if ($action === 'list_tags') {
    $tags = $pdo->query(
        "SELECT t.id, t.slug, (SELECT COUNT(*) FROM blog_post_tags pt WHERE pt.tag_id = t.id) AS post_count
         FROM blog_tags t ORDER BY t.slug"
    )->fetchAll();
    $allTrans = $pdo->query("SELECT tag_id, lang_code, name FROM blog_tag_translations")->fetchAll();
    $transMap = [];
    foreach ($allTrans as $t) {
        $transMap[(int)$t['tag_id']][$t['lang_code']] = $t['name'];
    }
    foreach ($tags as &$tg) {
        $tg['names'] = $transMap[(int)$tg['id']] ?? [];
    }
    json_response(true, $tags);
}

if ($action === 'save_tag' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? 0);
    $slug = trim($body['slug'] ?? '');
    if ($slug === '') json_response(false, null, 'slug obvezen.', 400);
    $names = $body['names'] ?? [];
    try {
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare("UPDATE blog_tags SET slug = ? WHERE id = ?")->execute([$slug, $id]);
            $pdo->prepare("DELETE FROM blog_tag_translations WHERE tag_id = ?")->execute([$id]);
        } else {
            $pdo->prepare("INSERT INTO blog_tags (slug) VALUES (?)")->execute([$slug]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO blog_tag_translations (tag_id, lang_code, name) VALUES (?, ?, ?)");
        foreach (BLOG_LANGS as $lc) {
            $n = trim($names[$lc] ?? '');
            if ($n !== '') $ins->execute([$id, $lc, $n]);
        }
        $pdo->commit();
        json_response(true, ['id' => $id]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('blog save_tag: ' . $e->getMessage());
        json_response(false, null, 'Napaka pri shranjevanju.', 500);
    }
}

if ($action === 'delete_tag' && in_array($method, ['POST','DELETE'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'id obvezen.', 400);
    $pdo->prepare("DELETE FROM blog_tags WHERE id = ?")->execute([$id]);
    json_response(true, null, 'Izbrisano.');
}

// ─── AVTORJI ─────────────────────────────────────────────────────
if ($action === 'list_authors') {
    $stmt = $pdo->prepare("SELECT * FROM blog_authors ORDER BY display_order, id");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['bio_translations'] = $r['bio_translations'] ? json_decode($r['bio_translations'], true) : [];
        $r['social_links']     = $r['social_links']     ? json_decode($r['social_links'], true)     : [];
    }
    json_response(true, $rows);
}

if ($action === 'save_author' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? 0);
    $slug = trim($body['slug'] ?? '');
    $name = trim($body['name'] ?? '');
    if ($slug === '' || $name === '') json_response(false, null, 'slug in name obvezna.', 400);
    $avatar = $body['avatar_url'] ?? null;
    $bio    = $body['bio_translations'] ?? null;
    $social = $body['social_links']     ?? null;
    $isActive = !empty($body['is_active']) ? 1 : 0;
    $displayOrder = (int)($body['display_order'] ?? 0);

    if ($id) {
        $pdo->prepare(
            "UPDATE blog_authors
             SET slug=?, name=?, avatar_url=?, bio_translations=?, social_links=?, display_order=?, is_active=?
             WHERE id=?"
        )->execute([
            $slug, $name, $avatar,
            $bio ? json_encode($bio, JSON_UNESCAPED_UNICODE) : null,
            $social ? json_encode($social, JSON_UNESCAPED_UNICODE) : null,
            $displayOrder, $isActive, $id,
        ]);
    } else {
        $pdo->prepare(
            "INSERT INTO blog_authors (slug, name, avatar_url, bio_translations, social_links, display_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $slug, $name, $avatar,
            $bio ? json_encode($bio, JSON_UNESCAPED_UNICODE) : null,
            $social ? json_encode($social, JSON_UNESCAPED_UNICODE) : null,
            $displayOrder, $isActive,
        ]);
        $id = (int)$pdo->lastInsertId();
    }
    json_response(true, ['id' => $id]);
}

if ($action === 'delete_author' && in_array($method, ['POST','DELETE'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'id obvezen.', 400);
    $pdo->prepare("DELETE FROM blog_authors WHERE id = ?")->execute([$id]);
    json_response(true, null, 'Izbrisano.');
}

// ─── TOPIC QUEUE ─────────────────────────────────────────────────
if ($action === 'list_topics') {
    $stmt = $pdo->prepare(
        "SELECT q.*, p.id AS gen_post_id,
                (SELECT t.title FROM blog_post_translations t WHERE t.post_id = q.generated_post_id LIMIT 1) AS gen_title
         FROM blog_topic_queue q
         LEFT JOIN blog_posts p ON p.id = q.generated_post_id
         ORDER BY q.status='queued' DESC, q.scheduled_for ASC, q.created_at DESC
         LIMIT 200"
    );
    $stmt->execute();
    json_response(true, $stmt->fetchAll());
}

if ($action === 'save_topic' && in_array($method, ['POST','PUT'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? 0);
    $topic = trim($body['topic'] ?? '');
    if ($topic === '') json_response(false, null, 'Tema obvezna.', 400);
    $brief = $body['brief'] ?? null;
    $kw = $body['target_keyword'] ?? null;
    $lc = $body['desired_lang'] ?? 'sl';
    if (!in_array($lc, BLOG_LANGS, true)) $lc = 'sl';
    $words = max(300, min(5000, (int)($body['desired_word_count'] ?? 1200)));
    $sched = !empty($body['scheduled_for']) ? $body['scheduled_for'] : null;
    if ($sched && !preg_match('/^\d{4}-\d{2}-\d{2}/', $sched)) {
        json_response(false, null, 'Neveljaven datum.', 400);
    }

    if ($id) {
        $pdo->prepare(
            "UPDATE blog_topic_queue
             SET topic=?, brief=?, target_keyword=?, desired_lang=?, desired_word_count=?, scheduled_for=?
             WHERE id=?"
        )->execute([$topic, $brief, $kw, $lc, $words, $sched, $id]);
    } else {
        $pdo->prepare(
            "INSERT INTO blog_topic_queue (topic, brief, target_keyword, desired_lang, desired_word_count, scheduled_for, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$topic, $brief, $kw, $lc, $words, $sched, (int)$session['user_id']]);
        $id = (int)$pdo->lastInsertId();
    }
    json_response(true, ['id' => $id]);
}

if ($action === 'delete_topic' && in_array($method, ['POST','DELETE'], true)) {
    $body = $body ?? get_body();
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, null, 'id obvezen.', 400);
    $pdo->prepare("DELETE FROM blog_topic_queue WHERE id = ?")->execute([$id]);
    json_response(true, null, 'Izbrisano.');
}

// ─── SUBSCRIBERS list ────────────────────────────────────────────
if ($action === 'list_subscribers') {
    $stmt = $pdo->prepare("SELECT id, email, lang_code, source, confirmed_at, unsubscribed_at, created_at FROM blog_subscribers ORDER BY created_at DESC LIMIT 1000");
    $stmt->execute();
    json_response(true, $stmt->fetchAll());
}

// ─── Stats za superadmin nadzorno ploščo ─────────────────────────
if ($action === 'stats') {
    $out = [];
    $out['posts_total']  = (int)$pdo->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn();
    $out['posts_published'] = (int)$pdo->query("SELECT COUNT(*) FROM blog_posts WHERE status = 'published'")->fetchColumn();
    $out['posts_draft']  = (int)$pdo->query("SELECT COUNT(*) FROM blog_posts WHERE status IN ('draft','pending_review','scheduled')")->fetchColumn();
    $out['translations_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM blog_post_translations WHERE status = 'pending_review'")->fetchColumn();
    $out['subscribers']  = (int)$pdo->query("SELECT COUNT(*) FROM blog_subscribers WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL")->fetchColumn();
    $out['topics_queued'] = (int)$pdo->query("SELECT COUNT(*) FROM blog_topic_queue WHERE status = 'queued'")->fetchColumn();
    json_response(true, $out);
}

// ─────────────────────────────────────────────────────────────────
// AI endpointi (Faza 3) — Anthropic Claude + OpenAI DALL-E 3
// ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/blog_anthropic.php';
require_once __DIR__ . '/../includes/blog_openai.php';
require_once __DIR__ . '/../includes/blog_unsplash.php';

/**
 * Helper: AI category_hint → blog_categories.id (ali NULL).
 * AI vrne enega od: operations | guest-experience | marketing | technology | growth
 */
function _bk_category_id_by_hint(PDO $pdo, $hint) {
    static $map = [
        'operations'      => 'operativa',
        'guest-experience'=> 'goste',
        'marketing'       => 'marketing-gostincev',
        'technology'      => 'tehnologija',
        'growth'          => 'marketing-gostincev', // growth + marketing dela isto kategorijo za zdaj
    ];
    if (!$hint || !isset($map[$hint])) return null;
    $stmt = $pdo->prepare("SELECT id FROM blog_categories WHERE slug = ? LIMIT 1");
    $stmt->execute([$map[$hint]]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Helper: za vsak tag name → najdi obstoječ ali ustvari nov, vrne tag_id.
 * @param string[] $tagNames  (v $masterLang)
 */
function _bk_resolve_tag_ids(PDO $pdo, array $tagNames, $masterLang) {
    $ids = [];
    foreach ($tagNames as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $slug = blog_slug($name);
        if ($slug === '') continue;
        // Obstaja po slug?
        $stmt = $pdo->prepare("SELECT id FROM blog_tags WHERE slug = ?");
        $stmt->execute([$slug]);
        $tagId = $stmt->fetchColumn();
        if (!$tagId) {
            $pdo->prepare("INSERT INTO blog_tags (slug) VALUES (?)")->execute([$slug]);
            $tagId = (int)$pdo->lastInsertId();
            // Translation za master jezik
            $pdo->prepare("INSERT IGNORE INTO blog_tag_translations (tag_id, lang_code, name) VALUES (?, ?, ?)")
                ->execute([$tagId, $masterLang, $name]);
        }
        $ids[] = (int)$tagId;
    }
    return array_unique($ids);
}

/**
 * Helper: poišči media ID-je referencirane v markdown vsebini (![...](media:N)).
 */
function _bk_extract_media_ids_from_md($md) {
    if (preg_match_all('/!\[[^\]]*\]\(media:(\d+)\)/', $md, $m)) {
        return array_unique(array_map('intval', $m[1]));
    }
    return [];
}

// ── 1. SUGGEST TOPICS ────────────────────────────────────────────
if ($action === 'ai_suggest_topics' && $method === 'POST') {
    $body  = $body ?? get_body();
    $count = max(10, min(30, (int)($body['count'] ?? 20)));
    $persist = !empty($body['persist']); // true = vstavi v topic_queue, false = samo vrni preview

    try {
        // Naloži obstoječe teme za "izogibanje"
        $existing = [];
        $rows = $pdo->query("SELECT topic FROM blog_topic_queue ORDER BY created_at DESC LIMIT 60")->fetchAll();
        foreach ($rows as $r) $existing[] = $r['topic'];
        // Plus naslovi že objavljenih master prevodov
        $rows2 = $pdo->query(
            "SELECT t.title FROM blog_post_translations t
             JOIN blog_posts p ON p.id = t.post_id
             WHERE t.lang_code = p.master_lang
             ORDER BY p.id DESC LIMIT 60"
        )->fetchAll();
        foreach ($rows2 as $r) $existing[] = $r['title'];

        $topics = blog_ai_suggest_topics($count, $existing);

        $insertedIds = [];
        if ($persist) {
            $stmt = $pdo->prepare(
                "INSERT INTO blog_topic_queue (topic, brief, target_keyword, desired_lang, desired_word_count, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($topics as $t) {
                $topic = trim((string)($t['topic'] ?? ''));
                if ($topic === '') continue;
                $lang = $t['desired_lang'] ?? 'en';
                if (!in_array($lang, BLOG_LANGS, true)) $lang = 'en';
                $stmt->execute([
                    mb_substr($topic, 0, 500),
                    $t['brief']             ?? null,
                    $t['target_keyword']    ?? null,
                    $lang,
                    (int)($t['desired_word_count'] ?? 700),
                    !empty($t['category_hint']) ? ('category_hint:' . $t['category_hint']) : null,
                    (int)$session['user_id'],
                ]);
                $insertedIds[] = (int)$pdo->lastInsertId();
            }
        }
        json_response(true, [
            'topics'        => $topics,
            'inserted_ids'  => $insertedIds,
            'count'         => count($topics),
        ]);
    } catch (Throwable $e) {
        error_log('blog ai_suggest_topics: ' . $e->getMessage());
        json_response(false, null, 'AI predlogi spodleteli: ' . $e->getMessage(), 500);
    }
}

// ── 2. GENERATE ARTICLE ──────────────────────────────────────────
if ($action === 'ai_generate' && $method === 'POST') {
    $body = $body ?? get_body();

    @set_time_limit(300);  // generacija + slike trajajo ~30-90s
    @ini_set('max_execution_time', '300');

    // Vir teme: lahko iz queue (topic_id) ali direktno iz body
    $topicId = (int)($body['topic_id'] ?? 0);
    $topic = ''; $brief = ''; $targetKeyword = ''; $lang = 'en';
    $wordCount = 700; $categoryHint = null;

    if ($topicId) {
        $tStmt = $pdo->prepare("SELECT * FROM blog_topic_queue WHERE id = ? LIMIT 1");
        $tStmt->execute([$topicId]);
        $tq = $tStmt->fetch();
        if (!$tq) json_response(false, null, 'Tema ne obstaja.', 404);
        if ($tq['status'] === 'generated' && !empty($tq['generated_post_id'])) {
            json_response(false, null, 'Tema je že bila generirana (post #' . $tq['generated_post_id'] . ').', 409);
        }
        $topic         = $tq['topic'];
        $brief         = $tq['brief'] ?? '';
        $targetKeyword = $tq['target_keyword'] ?? '';
        $lang          = $tq['desired_lang'] ?: 'en';
        $wordCount     = (int)$tq['desired_word_count'] ?: 700;
        if (!empty($tq['notes']) && preg_match('/category_hint:([\w-]+)/', $tq['notes'], $m)) {
            $categoryHint = $m[1];
        }
    } else {
        $topic         = trim($body['topic'] ?? '');
        $brief         = $body['brief'] ?? '';
        $targetKeyword = $body['target_keyword'] ?? '';
        $lang          = $body['lang'] ?? 'en';
        $wordCount     = (int)($body['word_count'] ?? 700);
        $categoryHint  = $body['category_hint'] ?? null;
    }
    if ($topic === '') json_response(false, null, 'Manjka tema.', 400);
    if (!in_array($lang, BLOG_LANGS, true)) $lang = 'en';

    $maxInline    = max(0, min(3, (int)($body['max_inline_images'] ?? 2)));
    // Default: defer (text only). Frontend nato pokliče ai_apply_post_image za vsako sliko posebej,
    // tako da se izognemo gateway timeout-u na Synology (~60s).
    $generateImgs = isset($body['generate_images']) ? (bool)$body['generate_images'] : false;
    $imgQuality   = $body['image_quality'] ?? 'standard'; // standard | hd

    try {
        // 1) Generiraj članek
        $article = blog_ai_generate_article($topic, $lang, [
            'brief'             => $brief,
            'target_keyword'    => $targetKeyword,
            'word_count'        => $wordCount,
            'max_inline_images' => $generateImgs ? $maxInline : 0,
        ]);
        if ($categoryHint && empty($article['category_hint'])) $article['category_hint'] = $categoryHint;

        // 2) Generiraj slike
        $heroMediaId = null;
        $contentMd   = $article['content_md'];

        if ($generateImgs) {
            // Hero
            if (!empty($article['hero_image']['prompt'])) {
                $heroMeta = blog_openai_generate_image(
                    $article['hero_image']['prompt'],
                    $article['hero_image']['alt']     ?? $article['title'],
                    $article['hero_image']['caption'] ?? '',
                    $lang,
                    (int)$session['user_id'],
                    ['quality' => $imgQuality]
                );
                $heroMediaId = (int)$heroMeta['id'];
            }
            // Inline
            $inline = $article['inline_images'] ?? [];
            foreach ($inline as $img) {
                $idx = (int)($img['index'] ?? 0);
                if ($idx < 1 || empty($img['prompt'])) continue;
                if ($idx > $maxInline) continue; // safety guard

                $imgMeta = blog_openai_generate_image(
                    $img['prompt'],
                    $img['alt']     ?? '',
                    $img['caption'] ?? '',
                    $lang,
                    (int)$session['user_id'],
                    ['quality' => $imgQuality]
                );
                $alt   = str_replace(["\r","\n",'"','`'], ' ', $img['alt'] ?? '');
                $cap   = trim((string)($img['caption'] ?? ''));
                $cap   = str_replace(["\r","\n",'"','`'], ' ', $cap);
                $repl  = '![' . $alt . '](media:' . (int)$imgMeta['id'] . ($cap !== '' ? ' "' . $cap . '"' : '') . ')';
                $contentMd = str_replace('<!--IMG:' . $idx . '-->', $repl, $contentMd);
            }
            // Pošlji še morebitne neaktivirane placeholderje v "delete" — brez slike
            $contentMd = preg_replace('/^\s*<!--IMG:\d+-->\s*$/m', '', $contentMd);
        }

        // 3) Slug unikatnost
        $slug = !empty($article['slug']) ? $article['slug'] : blog_slug($article['title']);
        $check = $pdo->prepare("SELECT 1 FROM blog_post_translations WHERE lang_code = ? AND slug = ?");
        $check->execute([$lang, $slug]);
        if ($check->fetchColumn()) $slug .= '-' . substr(md5(microtime(true)), 0, 6);

        // 4) Render markdown za content_html + TOC + reading_time
        $rendered = blog_render_md($contentMd, $lang);

        // 5) Insert post + translation (transakcija)
        $pdo->beginTransaction();

        $catId = _bk_category_id_by_hint($pdo, $article['category_hint'] ?? $categoryHint);

        $insP = $pdo->prepare(
            "INSERT INTO blog_posts
             (master_lang, category_id, hero_media_id, status, ai_generated, ai_topic, ai_prompt, ai_model, created_by)
             VALUES (?, ?, ?, 'draft', 1, ?, ?, ?, ?)"
        );
        $insP->execute([
            $lang,
            $catId,
            $heroMediaId,
            mb_substr($topic, 0, 255),
            $brief !== '' ? mb_substr($brief, 0, 1000) : null,
            BLOG_AI_MODEL_GENERATE,
            (int)$session['user_id'],
        ]);
        $postId = (int)$pdo->lastInsertId();

        $insT = $pdo->prepare(
            "INSERT INTO blog_post_translations
             (post_id, lang_code, slug, title, excerpt, content_md, content_html,
              meta_title, meta_description, table_of_contents, reading_time_minutes,
              word_count, status, ai_translated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 0)"
        );
        $insT->execute([
            $postId,
            $lang,
            $slug,
            mb_substr($article['title'], 0, 255),
            $article['excerpt'] ?? null,
            $contentMd,
            $rendered['html'],
            $article['meta_title']       ?? null,
            $article['meta_description'] ?? null,
            json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE),
            $rendered['reading_time'],
            $rendered['word_count'],
        ]);

        // Tagi
        if (!empty($article['tags']) && is_array($article['tags'])) {
            $tagIds = _bk_resolve_tag_ids($pdo, $article['tags'], $lang);
            $tagStmt = $pdo->prepare("INSERT IGNORE INTO blog_post_tags (post_id, tag_id) VALUES (?, ?)");
            foreach ($tagIds as $tagId) $tagStmt->execute([$postId, $tagId]);
        }

        // Topic queue marker
        if ($topicId) {
            $pdo->prepare("UPDATE blog_topic_queue SET status='generated', generated_post_id = ? WHERE id = ?")
                ->execute([$postId, $topicId]);
        }

        $pdo->commit();

        // Pripravi image_prompts za frontend, da naredi sequence klicev na ai_apply_post_image
        $imagePrompts = null;
        if (!$generateImgs) {
            $imagePrompts = [
                'hero'   => !empty($article['hero_image']['prompt']) ? [
                    'prompt'  => $article['hero_image']['prompt'],
                    'alt'     => $article['hero_image']['alt']     ?? $article['title'],
                    'caption' => $article['hero_image']['caption'] ?? '',
                ] : null,
                'inline' => array_values(array_filter(array_map(function($img) use ($maxInline) {
                    $idx = (int)($img['index'] ?? 0);
                    if ($idx < 1 || $idx > $maxInline || empty($img['prompt'])) return null;
                    return [
                        'index'   => $idx,
                        'prompt'  => $img['prompt'],
                        'alt'     => $img['alt']     ?? '',
                        'caption' => $img['caption'] ?? '',
                    ];
                }, $article['inline_images'] ?? []))),
            ];
        }

        json_response(true, [
            'post_id'       => $postId,
            'master_lang'   => $lang,
            'slug'          => $slug,
            'title'         => $article['title'],
            'hero_media_id' => $heroMediaId,
            'inline_count'  => $generateImgs ? count($article['inline_images'] ?? []) : 0,
            'word_count'    => $rendered['word_count'],
            'reading_time'  => $rendered['reading_time'],
            'editor_url'    => BASE_PATH . '/pages/blog_editor.php?id=' . $postId,
            'image_prompts' => $imagePrompts, // null če smo slike že generirali sinhrono
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('blog ai_generate: ' . $e->getMessage());
        json_response(false, null, 'AI generacija članka spodletela: ' . $e->getMessage(), 500);
    }
}

// ── 2b. APPLY ONE IMAGE TO POST (deferred image gen za ai_generate) ─
if ($action === 'ai_apply_post_image' && $method === 'POST') {
    $body     = $body ?? get_body();
    $postId   = (int)($body['post_id'] ?? 0);
    $role     = $body['role'] ?? '';        // 'hero' | 'inline'
    $idx      = (int)($body['index']  ?? 0); // 1..N za inline
    $provider = $body['provider'] ?? 'dalle'; // 'dalle' | 'unsplash'
    $prompt   = trim($body['prompt']      ?? '');
    $alt      = trim($body['alt']         ?? '');
    $caption  = trim($body['caption']     ?? '');
    $unsplashId = trim($body['unsplash_id'] ?? '');
    $lang     = $body['lang'] ?? '';
    if (!$postId || !in_array($role, ['hero','inline'], true)) {
        json_response(false, null, 'post_id in role obvezna.', 400);
    }
    if ($provider === 'unsplash' && $unsplashId === '') {
        json_response(false, null, 'unsplash_id obvezen za provider=unsplash.', 400);
    }
    if ($provider === 'dalle' && $prompt === '') {
        json_response(false, null, 'prompt obvezen za provider=dalle.', 400);
    }

    @set_time_limit(120);

    $post = _bk_load_post($pdo, $postId);
    if (!$post) json_response(false, null, 'Članek ne obstaja.', 404);
    $masterLang = $post['master_lang'];
    if (!$lang || !in_array($lang, BLOG_LANGS, true)) $lang = $masterLang;

    try {
        // 1) Generiraj/pridobi sliko po providerju
        if ($provider === 'unsplash') {
            $meta = blog_unsplash_download_and_save(
                $unsplashId,
                $alt !== '' ? $alt : $prompt,
                $caption,
                $lang,
                (int)$session['user_id']
            );
            // Unsplash baka attribution v caption — uporabi "caption_with_attribution"
            $caption = $meta['caption_with_attribution'] ?? $caption;
        } else {
            $meta = blog_openai_generate_image(
                $prompt,
                $alt !== '' ? $alt : $prompt,
                $caption,
                $lang,
                (int)$session['user_id'],
                ['quality' => $body['quality'] ?? 'standard']
            );
        }
        $mediaId = (int)$meta['id'];

        // 2) Apliciraj na post
        if ($role === 'hero') {
            $pdo->prepare("UPDATE blog_posts SET hero_media_id = ? WHERE id = ?")
                ->execute([$mediaId, $postId]);
            json_response(true, [
                'media_id'      => $mediaId,
                'role'          => 'hero',
                'preview_url'   => $meta['preview_url'],
                'dominant_color'=> $meta['dominant_color'],
            ]);
        }

        // inline: zamenjaj <!--IMG:N--> v master translation content_md
        if ($idx < 1) json_response(false, null, 'index obvezen za inline (1..N).', 400);
        $tStmt = $pdo->prepare("SELECT id, content_md FROM blog_post_translations WHERE post_id = ? AND lang_code = ?");
        $tStmt->execute([$postId, $masterLang]);
        $tr = $tStmt->fetch();
        if (!$tr) json_response(false, null, 'Master translation ne obstaja.', 404);

        $altClean = str_replace(["\r","\n",'"','`'], ' ', $alt !== '' ? $alt : $prompt);
        $capClean = str_replace(["\r","\n",'"','`'], ' ', $caption);
        $repl     = '![' . $altClean . '](media:' . $mediaId . ($capClean !== '' ? ' "' . $capClean . '"' : '') . ')';
        $newMd    = str_replace('<!--IMG:' . $idx . '-->', $repl, $tr['content_md']);

        $rendered = blog_render_md($newMd, $masterLang);
        $pdo->prepare(
            "UPDATE blog_post_translations
             SET content_md = ?, content_html = ?, table_of_contents = ?,
                 reading_time_minutes = ?, word_count = ?
             WHERE id = ?"
        )->execute([
            $newMd, $rendered['html'],
            json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE),
            $rendered['reading_time'],
            $rendered['word_count'],
            (int)$tr['id'],
        ]);
        $pdo->prepare("UPDATE blog_posts SET updated_at = NOW() WHERE id = ?")->execute([$postId]);

        json_response(true, [
            'media_id'    => $mediaId,
            'role'        => 'inline',
            'index'       => $idx,
            'preview_url' => $meta['preview_url'],
        ]);
    } catch (Throwable $e) {
        error_log('blog ai_apply_post_image: ' . $e->getMessage());
        json_response(false, null, 'Generacija slike spodletela: ' . $e->getMessage(), 500);
    }
}

// ── 3. TRANSLATE POST → vsi/izbrani jeziki ───────────────────────
if ($action === 'ai_translate_post' && $method === 'POST') {
    $body = $body ?? get_body();
    $postId = (int)($body['post_id'] ?? 0);
    if (!$postId) json_response(false, null, 'post_id obvezen.', 400);

    @set_time_limit(420);
    @ini_set('max_execution_time', '420');

    $post = _bk_load_post($pdo, $postId);
    if (!$post) json_response(false, null, 'Članek ne obstaja.', 404);

    $masterLang = $post['master_lang'];
    $masterTr   = $post['translations'][$masterLang] ?? null;
    if (!$masterTr) json_response(false, null, 'Manjka master prevod (' . $masterLang . ').', 400);

    // Cilji: če body['target_langs'] poslan, uporabi to; sicer vsi BLOG_LANGS razen master
    $targetLangs = $body['target_langs'] ?? null;
    if (!is_array($targetLangs) || empty($targetLangs)) {
        $targetLangs = array_values(array_filter(BLOG_LANGS, function($l) use ($masterLang) {
            return $l !== $masterLang;
        }));
    } else {
        $targetLangs = array_values(array_filter($targetLangs, function($l) use ($masterLang) {
            return in_array($l, BLOG_LANGS, true) && $l !== $masterLang;
        }));
    }
    if (empty($targetLangs)) json_response(false, null, 'Ni veljavnih ciljnih jezikov.', 400);

    $overwriteExisting = !empty($body['overwrite']); // privzeto: že obstoječih ne prepiše

    $results = [];
    foreach ($targetLangs as $tl) {
        try {
            // Preveri obstoječ prevod
            $existsStmt = $pdo->prepare("SELECT id, status FROM blog_post_translations WHERE post_id = ? AND lang_code = ?");
            $existsStmt->execute([$postId, $tl]);
            $existing = $existsStmt->fetch();
            if ($existing && !$overwriteExisting) {
                $results[] = ['lang' => $tl, 'status' => 'skipped', 'reason' => 'already_exists'];
                continue;
            }

            $translated = blog_ai_translate_translation($masterTr, $masterLang, $tl);

            // Slug: vedno sanitiziraj prek blog_slug() ne glede na to, kaj je AI vrnil.
            // (AI včasih vrne slug z diakritiko ali presledki, kar lahko ne ustreza shemi.)
            $rawSlug = !empty($translated['slug']) ? $translated['slug'] : $translated['title'];
            $slug    = blog_slug($rawSlug);
            if ($slug === '' || $slug === 'post') $slug = blog_slug($translated['title']);
            $checkSlug = $pdo->prepare(
                "SELECT id FROM blog_post_translations WHERE lang_code = ? AND slug = ? AND post_id <> ? LIMIT 1"
            );
            $checkSlug->execute([$tl, $slug, $postId]);
            if ($checkSlug->fetchColumn()) $slug .= '-' . substr(md5(microtime(true)), 0, 6);

            $contentMd = $translated['content_md'];
            $rendered  = blog_render_md($contentMd, $tl);

            $pdo->beginTransaction();
            if ($existing) {
                $pdo->prepare(
                    "UPDATE blog_post_translations
                     SET slug = ?, title = ?, excerpt = ?, content_md = ?, content_html = ?,
                         meta_title = ?, meta_description = ?,
                         table_of_contents = ?, reading_time_minutes = ?, word_count = ?,
                         status = 'draft', ai_translated = 1
                     WHERE id = ?"
                )->execute([
                    $slug,
                    mb_substr($translated['title'], 0, 255),
                    $translated['excerpt'] ?? null,
                    $contentMd, $rendered['html'],
                    $translated['meta_title']       ?? null,
                    $translated['meta_description'] ?? null,
                    json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE),
                    $rendered['reading_time'],
                    $rendered['word_count'],
                    (int)$existing['id'],
                ]);
                $trId = (int)$existing['id'];
            } else {
                $pdo->prepare(
                    "INSERT INTO blog_post_translations
                     (post_id, lang_code, slug, title, excerpt, content_md, content_html,
                      meta_title, meta_description, table_of_contents, reading_time_minutes,
                      word_count, status, ai_translated)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 1)"
                )->execute([
                    $postId, $tl, $slug,
                    mb_substr($translated['title'], 0, 255),
                    $translated['excerpt'] ?? null,
                    $contentMd, $rendered['html'],
                    $translated['meta_title']       ?? null,
                    $translated['meta_description'] ?? null,
                    json_encode($rendered['toc'], JSON_UNESCAPED_UNICODE),
                    $rendered['reading_time'],
                    $rendered['word_count'],
                ]);
                $trId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE blog_posts SET updated_at = NOW() WHERE id = ?")->execute([$postId]);
            $pdo->commit();

            $results[] = ['lang' => $tl, 'status' => 'ok', 'translation_id' => $trId, 'slug' => $slug];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('blog ai_translate_post[' . $tl . ']: ' . $e->getMessage());
            $results[] = ['lang' => $tl, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }

    // Prevedi alt/caption za referencirane medije (hero + inline)
    try {
        $mediaIds = _bk_extract_media_ids_from_md($masterTr['content_md']);
        if (!empty($post['hero_media_id'])) $mediaIds[] = (int)$post['hero_media_id'];
        $mediaIds = array_unique($mediaIds);
        if (!empty($mediaIds)) {
            $okLangs = array_values(array_filter(array_map(function($r) {
                return $r['status'] === 'ok' ? $r['lang'] : null;
            }, $results)));
            if (!empty($okLangs)) {
                $in = implode(',', array_map('intval', $mediaIds));
                $mStmt = $pdo->query("SELECT id, alt_translations, caption_translations FROM blog_media WHERE id IN ($in)");
                while ($m = $mStmt->fetch()) {
                    $alts = $m['alt_translations']     ? json_decode($m['alt_translations'], true)     : [];
                    $caps = $m['caption_translations'] ? json_decode($m['caption_translations'], true) : [];
                    $sourceAlt = $alts[$masterLang] ?? '';
                    $sourceCap = $caps[$masterLang] ?? '';
                    if ($sourceAlt === '' && $sourceCap === '') continue;
                    // Prevedi samo v jezike, ki še nimajo alta (oz. če overwrite)
                    $needLangs = array_values(array_filter($okLangs, function($lc) use ($alts, $overwriteExisting) {
                        return $overwriteExisting || empty($alts[$lc]);
                    }));
                    if (empty($needLangs)) continue;
                    $tr = blog_ai_translate_media_alts($sourceAlt, $sourceCap, $masterLang, $needLangs);
                    foreach ($needLangs as $lc) {
                        if (!empty($tr['alt'][$lc]))     $alts[$lc] = $tr['alt'][$lc];
                        if (!empty($tr['caption'][$lc])) $caps[$lc] = $tr['caption'][$lc];
                    }
                    $pdo->prepare("UPDATE blog_media SET alt_translations = ?, caption_translations = ? WHERE id = ?")
                        ->execute([
                            $alts ? json_encode($alts, JSON_UNESCAPED_UNICODE) : null,
                            $caps ? json_encode($caps, JSON_UNESCAPED_UNICODE) : null,
                            (int)$m['id'],
                        ]);
                }
            }
        }
    } catch (Throwable $e) {
        // Prevod alt-ov ne sme blokirati uspešnih prevodov posta
        error_log('blog ai_translate_post media alts: ' . $e->getMessage());
    }

    $okCount = 0; $errCount = 0; $skipCount = 0;
    foreach ($results as $r) {
        if ($r['status'] === 'ok') $okCount++;
        elseif ($r['status'] === 'error') $errCount++;
        else $skipCount++;
    }
    json_response(true, [
        'post_id'      => $postId,
        'results'      => $results,
        'ok_count'     => $okCount,
        'error_count'  => $errCount,
        'skipped'      => $skipCount,
    ]);
}

// ── 4a. GENERATE SINGLE IMAGE (z user promptom) ──────────────────
if ($action === 'ai_generate_image_single' && $method === 'POST') {
    $body     = $body ?? get_body();
    $provider = $body['provider'] ?? 'dalle'; // 'dalle' | 'unsplash'
    $prompt   = trim($body['prompt']      ?? '');
    $alt      = trim($body['alt']         ?? '');
    $caption  = trim($body['caption']     ?? '');
    $unsplashId = trim($body['unsplash_id'] ?? '');
    $lang     = $body['lang'] ?? 'sl';
    if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';
    if ($provider === 'dalle' && $prompt === '')   json_response(false, null, 'prompt obvezen.', 400);
    if ($provider === 'unsplash' && $unsplashId === '') json_response(false, null, 'unsplash_id obvezen.', 400);
    @set_time_limit(120);
    try {
        if ($provider === 'unsplash') {
            $meta = blog_unsplash_download_and_save(
                $unsplashId,
                $alt !== '' ? $alt : $prompt,
                $caption,
                $lang,
                (int)$session['user_id']
            );
        } else {
            $meta = blog_openai_generate_image(
                $prompt,
                $alt !== '' ? $alt : $prompt,
                $caption,
                $lang,
                (int)$session['user_id'],
                ['quality' => $body['quality'] ?? 'standard']
            );
        }
        json_response(true, $meta);
    } catch (Throwable $e) {
        error_log('blog ai_generate_image_single: ' . $e->getMessage());
        json_response(false, null, 'Generacija slike spodletela: ' . $e->getMessage(), 500);
    }
}

// ── 4b. UNSPLASH SEARCH (vrne array thumb-ov za picker) ──────────
if ($action === 'unsplash_search' && in_array($method, ['GET','POST'], true)) {
    $body  = ($method === 'POST') ? ($body ?? get_body()) : [];
    $query = trim($body['query']  ?? $_GET['query'] ?? $_GET['q'] ?? '');
    $perPage = (int)($body['per_page'] ?? $_GET['per_page'] ?? 8);
    $orientation = $body['orientation'] ?? $_GET['orientation'] ?? 'landscape';
    if ($query === '') json_response(false, null, 'query obvezen.', 400);
    try {
        $rows = blog_unsplash_search($query, $perPage, $orientation);
        json_response(true, ['photos' => $rows]);
    } catch (Throwable $e) {
        error_log('blog unsplash_search: ' . $e->getMessage());
        json_response(false, null, 'Unsplash iskanje spodletelo: ' . $e->getMessage(), 500);
    }
}

// ── 4. SUGGEST TAGS ──────────────────────────────────────────────
if ($action === 'ai_suggest_tags' && $method === 'POST') {
    $body  = $body ?? get_body();
    $title = trim($body['title'] ?? '');
    $md    = $body['content_md'] ?? '';
    $lang  = $body['lang'] ?? 'sl';
    if ($title === '' || $md === '') json_response(false, null, 'title in content_md obvezna.', 400);
    if (!in_array($lang, BLOG_LANGS, true)) $lang = 'sl';
    try {
        $tags = blog_ai_suggest_tags($title, $md, $lang);
        json_response(true, ['tags' => $tags]);
    } catch (Throwable $e) {
        json_response(false, null, 'AI predlogi tagov spodleteli: ' . $e->getMessage(), 500);
    }
}

json_response(false, null, 'Neznana akcija: ' . $action, 400);
