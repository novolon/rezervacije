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

// AI placeholder (Faza 3)
if ($action === 'ai_generate' || $action === 'ai_translate') {
    json_response(false, null, 'AI generacija na voljo v Fazi 3 (po dodatku ANTHROPIC_API_KEY v config.php).', 501);
}

json_response(false, null, 'Neznana akcija: ' . $action, 400);
