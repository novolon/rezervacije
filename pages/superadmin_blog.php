<?php
/**
 * Booked admin – CRUD UI za superadmin.
 * Sub-tabi: Posts / Topics / Categories / Tags / Authors / Media / Subscribers
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';

if (!is_logged_in()) {
    redirect_to_login();
}
if ($_SESSION['role'] !== 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/main.php');
    exit;
}

$fullName = $_SESSION['full_name'] ?? '';
$activeTab = $_GET['tab'] ?? 'posts';
$validTabs = ['posts','topics','categories','tags','authors','media','subscribers'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'posts';
?><!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
<title>Booked admin – <?= h(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/blog_admin.css">
<script>
window.APP_STATE = { base: '<?= BASE_PATH ?>' };
window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
window.t = function(k,p){var s=window.__T__[k]||k;if(p)for(var x in p)s=s.split('{'+x+'}').join(p[x]);return s;};
window.BLOG_LANGS = ['sl','en','de','it','fr','hr','es','pt'];
</script>
</head>
<body>

<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/superadmin.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#c8542b"/>
            <text x="14" y="20" text-anchor="middle" font-family="Inter,sans-serif" font-weight="800" font-size="18" fill="#fff" letter-spacing="-0.6">B</text>
        </svg>
        Booked
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Magazin admin</span>
    </div>
    <div class="header-actions">
        <a href="<?= BASE_PATH ?>/pages/superadmin.php" class="btn-header">← Superadmin</a>
        <a href="<?= BASE_PATH ?>/booked" class="btn-header" target="_blank">Live blog ↗</a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<div class="admin-layout">
<div class="admin-content">

<h1 class="admin-page-title">Booked – upravljanje vsebine</h1>

<!-- Stats -->
<div id="bk-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:28px">
    <div class="stat-card"><div class="stat-val" data-stat="posts_total">…</div><div class="stat-lbl">Vseh člankov</div></div>
    <div class="stat-card"><div class="stat-val" data-stat="posts_published">…</div><div class="stat-lbl">Objavljeni</div></div>
    <div class="stat-card"><div class="stat-val" data-stat="posts_draft">…</div><div class="stat-lbl">V pripravi</div></div>
    <div class="stat-card"><div class="stat-val" data-stat="translations_pending">…</div><div class="stat-lbl">Prevodi za pregled</div></div>
    <div class="stat-card"><div class="stat-val" data-stat="subscribers">…</div><div class="stat-lbl">Naročniki</div></div>
    <div class="stat-card"><div class="stat-val" data-stat="topics_queued">…</div><div class="stat-lbl">Teme v vrsti</div></div>
</div>

<div class="admin-tabs">
    <button class="admin-tab<?= $activeTab==='posts' ? ' active':'' ?>" data-tab="posts">Članki</button>
    <button class="admin-tab<?= $activeTab==='topics' ? ' active':'' ?>" data-tab="topics">Teme (AI vrsta)</button>
    <button class="admin-tab<?= $activeTab==='categories' ? ' active':'' ?>" data-tab="categories">Kategorije</button>
    <button class="admin-tab<?= $activeTab==='tags' ? ' active':'' ?>" data-tab="tags">Tagi</button>
    <button class="admin-tab<?= $activeTab==='authors' ? ' active':'' ?>" data-tab="authors">Avtorji</button>
    <button class="admin-tab<?= $activeTab==='media' ? ' active':'' ?>" data-tab="media">Slike</button>
    <button class="admin-tab<?= $activeTab==='subscribers' ? ' active':'' ?>" data-tab="subscribers">Naročniki</button>
</div>

<!-- ─── PANEL: POSTS ───────────────────────────────────────────── -->
<div id="panel-posts" class="admin-panel<?= $activeTab==='posts' ? ' active':'' ?>">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Članki</h2>
            <div style="display:flex;gap:8px">
                <select id="bk-filter-status" class="admin-field-input" style="padding:7px 12px;font-size:13px">
                    <option value="">Vsi statusi</option>
                    <option value="draft">Osnutek</option>
                    <option value="pending_review">V pregledu</option>
                    <option value="scheduled">Zakazano</option>
                    <option value="published">Objavljeno</option>
                    <option value="archived">Arhivirano</option>
                </select>
                <input type="search" id="bk-filter-search" name="q" placeholder="Iskanje po naslovu..." class="admin-field-input" style="padding:7px 12px;font-size:13px"
                       autocomplete="off" data-form-type="other" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                <button class="btn btn-primary" id="bk-new-post">+ Nov članek</button>
                <button class="btn btn-outline" id="bk-ai-generate" title="Na voljo v Fazi 3">✨ AI</button>
            </div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:40%">Naslov</th>
                        <th>Kategorija</th>
                        <th>Status</th>
                        <th style="width:170px">Prevodi</th>
                        <th>Objavljeno</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody id="bk-posts-tbody">
                    <tr><td colspan="6" class="table-empty">Nalagam...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── PANEL: TOPICS ──────────────────────────────────────────── -->
<div id="panel-topics" class="admin-panel<?= $activeTab==='topics' ? ' active':'' ?>">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Teme za AI generacijo</h2>
            <div style="display:flex;gap:8px">
                <button class="btn btn-outline" id="bk-ai-suggest-topics-btn">✨ Predlagaj 20 idej z AI</button>
                <button class="btn btn-primary" id="bk-new-topic">+ Nova tema</button>
            </div>
        </div>
        <p style="padding:0 24px 12px;color:var(--color-muted);font-size:13.5px;margin:0">
            Klikni <strong>✨ Predlagaj</strong> da Claude predlaga ideje za članke (na podlagi Rezble funkcionalnosti). Za vsako temo lahko nato klikneš
            <strong>Generiraj</strong> — AI napiše članek + ustvari hero + inline slike z DALL-E 3, ki ga nato urediš v editorju.
        </p>
        <p id="bk-ai-topics-status" style="padding:0 24px 12px;font-size:13px;min-height:16px;margin:0"></p>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Tema</th>
                        <th>Ključna beseda</th>
                        <th>Jezik</th>
                        <th>Datum</th>
                        <th>Status</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody id="bk-topics-tbody">
                    <tr><td colspan="6" class="table-empty">Nalagam...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── PANEL: CATEGORIES ──────────────────────────────────────── -->
<div id="panel-categories" class="admin-panel<?= $activeTab==='categories' ? ' active':'' ?>">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Kategorije</h2>
            <button class="btn btn-primary" id="bk-new-category">+ Nova kategorija</button>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Ime (SL)</th>
                        <th>Ime (EN)</th>
                        <th>Aktivna</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody id="bk-categories-tbody">
                    <tr><td colspan="5" class="table-empty">Nalagam...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── PANEL: TAGS ────────────────────────────────────────────── -->
<div id="panel-tags" class="admin-panel<?= $activeTab==='tags' ? ' active':'' ?>">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Tagi</h2>
            <button class="btn btn-primary" id="bk-new-tag">+ Nov tag</button>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Ime (SL)</th>
                        <th>Ime (EN)</th>
                        <th>Št. člankov</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody id="bk-tags-tbody">
                    <tr><td colspan="5" class="table-empty">Nalagam...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── PANEL: AUTHORS ─────────────────────────────────────────── -->
<div id="panel-authors" class="admin-panel<?= $activeTab==='authors' ? ' active':'' ?>">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Avtorji</h2>
            <button class="btn btn-primary" id="bk-new-author">+ Nov avtor</button>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Slug</th>
                        <th>Ime</th>
                        <th>Aktiven</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody id="bk-authors-tbody">
                    <tr><td colspan="5" class="table-empty">Nalagam...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── PANEL: MEDIA ───────────────────────────────────────────── -->
<div id="panel-media" class="admin-panel<?= $activeTab==='media' ? ' active':'' ?>">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Knjižnica slik</h2>
            <div>
                <input type="file" id="bk-upload-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
                <button class="btn btn-primary" id="bk-upload-btn">+ Naloži sliko</button>
            </div>
        </div>
        <div id="bk-media-grid" class="bk-media-grid">
            <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--color-muted)">Nalagam...</div>
        </div>
    </div>
</div>

<!-- ─── PANEL: SUBSCRIBERS ─────────────────────────────────────── -->
<div id="panel-subscribers" class="admin-panel<?= $activeTab==='subscribers' ? ' active':'' ?>">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Newsletter naročniki</h2>
            <button class="btn btn-outline" id="bk-export-subscribers">Izvozi CSV</button>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Jezik</th>
                        <th>Vir</th>
                        <th>Potrjen</th>
                        <th>Odjavljen</th>
                        <th>Datum</th>
                    </tr>
                </thead>
                <tbody id="bk-subscribers-tbody">
                    <tr><td colspan="6" class="table-empty">Nalagam...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div></div>

<script src="<?= BASE_PATH ?>/assets/js/api.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/modal.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/blog_admin.js"></script>
</body>
</html>
