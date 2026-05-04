<?php
/**
 * Booked editor – urejanje članka (master + 7 prevodov v zavihkih).
 * URL: /pages/blog_editor.php?id={postId} ali brez id-ja za nov članek.
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) redirect_to_login();
if ($_SESSION['role'] !== 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/main.php');
    exit;
}

$postId = (int)($_GET['id'] ?? 0);
$pdo = getDB();

// Naloži kategorije + avtorje + tage za UI
$categories = $pdo->query(
    "SELECT c.id, c.slug, COALESCE(ct.name, c.slug) AS name
     FROM blog_categories c
     LEFT JOIN blog_category_translations ct ON ct.category_id = c.id AND ct.lang_code = 'sl'
     WHERE c.is_active = 1 ORDER BY c.display_order, c.id"
)->fetchAll();

$authors = $pdo->query("SELECT id, slug, name FROM blog_authors WHERE is_active = 1 ORDER BY display_order, id")->fetchAll();
$tagsAll = $pdo->query(
    "SELECT t.id, t.slug, COALESCE(tt.name, t.slug) AS name
     FROM blog_tags t
     LEFT JOIN blog_tag_translations tt ON tt.tag_id = t.id AND tt.lang_code = 'sl'
     ORDER BY t.slug"
)->fetchAll();
?><!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booked editor – <?= h(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/blog_admin.css">
<!-- EasyMDE preko CDN (single script + css) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js" defer></script>
<script>
window.APP_STATE = { base: '<?= BASE_PATH ?>' };
window.BLOG_LANGS = ['sl','en','de','it','fr','hr','es','pt'];
window.BLOG_POST_ID = <?= (int)$postId ?>;
window.BLOG_CATEGORIES = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
window.BLOG_AUTHORS    = <?= json_encode($authors, JSON_UNESCAPED_UNICODE) ?>;
window.BLOG_TAGS       = <?= json_encode($tagsAll, JSON_UNESCAPED_UNICODE) ?>;
</script>
</head>
<body class="bk-editor-body">

<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/superadmin_blog.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#c8542b"/>
            <text x="14" y="20" text-anchor="middle" font-family="Inter,sans-serif" font-weight="800" font-size="18" fill="#fff">B</text>
        </svg>
        Booked
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.6);font-size:.85rem" id="bk-editor-title">
            <?= $postId ? 'Urejam članek #' . $postId : 'Nov članek' ?>
        </span>
    </div>
    <div class="header-actions">
        <span id="bk-autosave-status" style="color:rgba(255,255,255,.5);font-size:12px;margin-right:8px">—</span>
        <a href="<?= BASE_PATH ?>/pages/superadmin_blog.php" class="btn-header">← Nazaj</a>
    </div>
</header>

<div class="bk-editor-layout">
    <!-- Glavni stolpec: tabs + form + EasyMDE -->
    <div class="bk-editor-main">

        <!-- Lang tabs -->
        <div class="bk-lang-tabs-bar">
            <div class="bk-lang-tabs" id="bk-lang-tabs">
                <?php foreach (['sl','en','de','it','fr','hr','es','pt'] as $lc): ?>
                    <button type="button" class="bk-lang-tab<?= $lc === 'sl' ? ' active' : '' ?>" data-lang="<?= $lc ?>">
                        <span class="bk-lang-tab__code"><?= strtoupper($lc) ?></span>
                        <span class="bk-lang-tab__status" data-status-for="<?= $lc ?>" title="Status prevoda">—</span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="bk-copy-bar">
                <span style="font-size:12px;color:var(--color-muted);white-space:nowrap">Kopiraj iz:</span>
                <select id="bk-copy-from-lang" class="bk-input bk-input--sm">
                    <?php foreach (['sl','en','de','it','fr','hr','es','pt'] as $lc): ?>
                        <option value="<?= $lc ?>"><?= strtoupper($lc) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline btn-sm" id="bk-copy-lang-btn">← Kopiraj</button>
            </div>
        </div>

        <form id="bk-translation-form" class="bk-editor-form" autocomplete="off">
            <input type="hidden" name="post_id" id="bk-post-id" value="<?= (int)$postId ?>">
            <input type="hidden" name="lang_code" id="bk-active-lang" value="sl">

            <div class="bk-form-row">
                <label>Naslov
                    <input type="text" name="title" id="bk-title" placeholder="Naslov članka..." class="bk-input bk-input--big" required>
                </label>
            </div>

            <div class="bk-form-row bk-form-row--split">
                <label>Slug (URL)
                    <input type="text" name="slug" id="bk-slug" placeholder="kako-zmanjsati-no-show" class="bk-input">
                </label>
                <label>Bralni čas
                    <input type="text" id="bk-reading-time" readonly class="bk-input" placeholder="Auto">
                </label>
            </div>

            <div class="bk-form-row">
                <label>Kratek povzetek (excerpt)
                    <textarea name="excerpt" id="bk-excerpt" rows="2" class="bk-input" placeholder="Ena ali dve povedi, ki opišejo članek..."></textarea>
                </label>
            </div>

            <details class="bk-meta-details">
                <summary>SEO meta (title + description)</summary>
                <div class="bk-form-row">
                    <label>Meta title
                        <input type="text" name="meta_title" id="bk-meta-title" maxlength="60" class="bk-input">
                        <small style="color:var(--color-muted);font-size:11.5px"><span id="bk-meta-title-len">0</span>/60</small>
                    </label>
                </div>
                <div class="bk-form-row">
                    <label>Meta description
                        <textarea name="meta_description" id="bk-meta-description" rows="2" maxlength="160" class="bk-input"></textarea>
                        <small style="color:var(--color-muted);font-size:11.5px"><span id="bk-meta-desc-len">0</span>/160</small>
                    </label>
                </div>
            </details>

            <div class="bk-form-row">
                <label>Vsebina (markdown)
                    <textarea name="content_md" id="bk-content"></textarea>
                </label>
                <p style="font-size:12.5px;color:var(--color-muted);margin-top:6px">
                    Podpira: <code>**bold**</code>, <code>*italic*</code>, <code># Naslov</code>, <code>- seznam</code>,
                    <code>&gt; citat</code>, <code>[link](url)</code>, <code>![alt](media:42)</code>,
                    in CTA shortcode-i: <code>[cta:register]</code>, <code>[cta:pricing]</code>, <code>[cta:demo]</code>, <code>[cta:subscribe]</code>.
                </p>
            </div>

            <div class="bk-form-row" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                <button type="button" class="btn btn-outline" id="bk-save-btn">Shrani osnutek</button>
                <button type="button" class="btn btn-outline" id="bk-approve-translation">Odobri prevod</button>
                <button type="button" class="btn btn-outline" id="bk-reject-translation" style="margin-left:auto">Zavrni</button>
            </div>
        </form>

    </div>

    <!-- Stranski panel: meta podatki + objava -->
    <aside class="bk-editor-side">
        <div class="bk-side-card">
            <h3>Status</h3>
            <div id="bk-post-status-badge" class="bk-status-pill" data-status="draft">Osnutek</div>
            <div style="margin-top:14px;display:flex;flex-direction:column;gap:8px">
                <button type="button" class="btn btn-primary" id="bk-publish-btn">Objavi takoj</button>
                <button type="button" class="btn btn-outline" id="bk-schedule-btn">Zakaži objavo...</button>
                <button type="button" class="btn btn-outline" id="bk-pending-btn">Pošlji v pregled</button>
                <button type="button" class="btn btn-outline" id="bk-archive-btn">Arhiviraj</button>
                <a href="#" id="bk-preview-post-link" class="btn btn-outline" target="_blank" rel="noopener" style="display:none;text-align:center">👁️ Predogled (<span data-preview-lang>SL</span>) ↗</a>
                <a href="#" id="bk-view-post-link" class="btn btn-outline" target="_blank" rel="noopener" style="display:none;text-align:center">Poglej članek ↗</a>
            </div>
        </div>

        <div class="bk-side-card">
            <h3>Hero slika</h3>
            <div id="bk-hero-preview" class="bk-hero-preview">
                <div class="bk-hero-empty">Brez slike</div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
                <button type="button" class="btn btn-outline" id="bk-pick-hero">Izberi iz knjižnice</button>
                <button type="button" class="btn btn-outline" id="bk-clear-hero">Brez</button>
            </div>
        </div>

        <div class="bk-side-card">
            <h3>Meta</h3>
            <label class="bk-side-field">Master jezik
                <select id="bk-master-lang" class="bk-input">
                    <?php foreach (['sl','en','de','it','fr','hr','es','pt'] as $lc): ?>
                        <option value="<?= $lc ?>"><?= strtoupper($lc) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="bk-side-field">Kategorija
                <select id="bk-category" class="bk-input">
                    <option value="">— brez —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="bk-side-field">Avtor
                <select id="bk-author" class="bk-input">
                    <option value="">— brez —</option>
                    <?php foreach ($authors as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="bk-side-field">Tagi
                <div id="bk-tags-picker" class="bk-tag-picker">
                    <?php foreach ($tagsAll as $tg): ?>
                        <label class="bk-tag-chip"><input type="checkbox" value="<?= (int)$tg['id'] ?>"> <?= h($tg['name']) ?></label>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:6px;margin-top:8px">
                    <input type="text" id="bk-new-tag-input" class="bk-input" placeholder="Nov tag (sl ime)" style="font-size:12px;padding:4px 8px">
                    <button type="button" class="btn btn-outline btn-sm" id="bk-add-tag-btn">+</button>
                </div>
            </label>
            <button type="button" class="btn btn-outline" id="bk-save-meta" style="margin-top:8px;width:100%">Shrani meta</button>
        </div>

        <div class="bk-side-card">
            <h3>AI</h3>
            <p style="font-size:13px;color:var(--color-muted);margin:0 0 8px">Claude + DALL-E 3.</p>
            <button type="button" class="btn btn-outline" id="bk-ai-translate-all" style="width:100%">🌐 Prevedi v vse jezike</button>
            <button type="button" class="btn btn-outline" id="bk-ai-suggest-tags" style="width:100%;margin-top:6px">🏷️ Predlagaj tage</button>
            <button type="button" class="btn btn-outline" id="bk-ai-generate-image" style="width:100%;margin-top:6px">🖼️ Generiraj sliko z AI</button>
            <p id="bk-ai-status" style="font-size:12px;color:var(--color-muted);margin:8px 0 0;min-height:14px"></p>
        </div>
    </aside>
</div>

<!-- Modal-i -->
<div id="bk-media-modal" class="bk-modal" hidden>
    <div class="bk-modal__content" style="max-width:880px">
        <div class="bk-modal__header">
            <h3>Izberi sliko</h3>
            <button type="button" class="bk-modal__close" data-close-modal>×</button>
        </div>
        <div class="bk-modal__body">
            <input type="file" id="bk-modal-upload" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
            <button class="btn btn-outline" id="bk-modal-upload-btn" style="margin-bottom:16px">+ Naloži novo sliko</button>
            <div id="bk-modal-media-grid" class="bk-media-grid"></div>
        </div>
    </div>
</div>

<div id="bk-schedule-modal" class="bk-modal" hidden>
    <div class="bk-modal__content" style="max-width:420px">
        <div class="bk-modal__header">
            <h3>Zakaži objavo</h3>
            <button type="button" class="bk-modal__close" data-close-modal>×</button>
        </div>
        <div class="bk-modal__body">
            <label class="bk-side-field">Datum in čas
                <input type="datetime-local" id="bk-schedule-datetime" class="bk-input">
            </label>
            <button type="button" class="btn btn-primary" id="bk-confirm-schedule" style="margin-top:14px;width:100%">Zakaži</button>
        </div>
    </div>
</div>

<script src="<?= BASE_PATH ?>/assets/js/api.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/blog_editor.js" defer></script>
</body>
</html>
