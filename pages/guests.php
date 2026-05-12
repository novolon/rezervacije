<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

if (!is_logged_in()) {
    redirect_to_login();
}
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php');
    exit;
}

$pdo = getDB();
refresh_subscription_session($pdo);

if (!empty($_SESSION['payment_failed'])) {
    require_once '../includes/payment_failed_block.php';
    exit;
}

$isAdmin   = $_SESSION['role'] === 'admin';
$fullName  = $_SESSION['full_name'];
$hasFeature = user_has_feature($pdo, (int)$_SESSION['user_id'], 'guest_database');

// Naloži restavracije za sidebar
$restaurants = [];
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, r.color FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
} elseif (!empty($_SESSION['restaurant_id'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM restaurants WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}

$pendingCount = 0;
if ($isAdmin) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM reservations r JOIN restaurant_admins ra ON r.restaurant_id=ra.restaurant_id WHERE ra.user_id=? AND r.status='pending'");
    $s->execute([$_SESSION['user_id']]); $pendingCount = (int)$s->fetchColumn();
} elseif (!empty($_SESSION['restaurant_id'])) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE restaurant_id=? AND status='pending'");
    $s->execute([$_SESSION['restaurant_id']]); $pendingCount = (int)$s->fetchColumn();
}

// Aktivna restavracija iz sidebara (session)
$activeRestId = 0;
if (!empty($_SESSION['restaurant_id'])) {
    $allowed = array_column($restaurants, 'id');
    if (in_array((int)$_SESSION['restaurant_id'], $allowed)) {
        $activeRestId = (int)$_SESSION['restaurant_id'];
    }
}
if (!$activeRestId && !empty($restaurants)) {
    $activeRestId = (int)$restaurants[0]['id'];
}
// URL ?rest_id=X ohrani za direktne linke iz drawer-ja
if (!empty($_GET['rest_id'])) {
    $urlRest = (int)$_GET['rest_id'];
    if (in_array($urlRest, array_column($restaurants, 'id'))) $activeRestId = $urlRest;
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('nav.guests') ?> – <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=4">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css?v=3">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/rezble.css?v=<?= @filemtime(__DIR__ . '/../assets/css/rezble.css') ?>">
    <style>
        .guests-title { font-size: 1.4rem; font-weight: 700; color: #111827; margin: 0 0 20px; }
        .guests-toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
        .guests-search { flex: 1; min-width: 200px; max-width: 360px; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: .9rem; outline: none; }
        .guests-search:focus { border-color: #F59E0B; box-shadow: 0 0 0 3px rgba(245,158,11,.1); }
        .guests-rest-select { padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: .9rem; }

        .guests-table-wrap { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; }
        .guests-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .guests-table th { padding: 11px 14px; text-align: left; font-size: .75rem; font-weight: 600; color: #6B7280; text-transform: uppercase; letter-spacing: .05em; background: #F9FAFB; border-bottom: 1px solid #E5E7EB; }
        .guests-table td { padding: 12px 14px; border-bottom: 1px solid #F3F4F6; vertical-align: middle; }
        .guests-table tr:last-child td { border-bottom: none; }
        .guests-table tr:hover td { background: #FAFAFA; cursor: pointer; }
        .guest-name { font-weight: 600; color: #111827; }
        .guest-email { color: #6B7280; font-size: .82rem; }
        .tag-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem; font-weight: 600; background: #FEF3C7; color: #92400E; margin: 1px 2px; }
        .tag-pill.vip { background: #FEE2E2; color: #991B1B; }
        .tag-pill.blacklisted { background: #F3F4F6; color: #374151; }
        .stat-mini { font-size: .82rem; color: #374151; white-space: nowrap; }
        .stat-mini .num { font-weight: 600; color: #111827; }
        .btn-row { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 6px; font-size: .82rem; font-weight: 500; cursor: pointer; border: 1px solid #E5E7EB; background: #fff; color: #374151; transition: background .15s; }
        .btn-row:hover { background: #F9FAFB; }
        .guests-empty { padding: 48px; text-align: center; color: #9CA3AF; }
        .guests-pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-top: 1px solid #E5E7EB; font-size: .85rem; color: #6B7280; }
        .pagination-btns { display: flex; gap: 6px; }
        .pagination-btns button { padding: 5px 12px; border: 1px solid #D1D5DB; border-radius: 6px; background: #fff; cursor: pointer; font-size: .82rem; }
        .pagination-btns button:disabled { opacity: .4; cursor: default; }
        .pagination-btns button.active { background: #F59E0B; color: #fff; border-color: #F59E0B; }

        /* Modal profila */
        .guest-modal-body { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 640px) { .guest-modal-body { grid-template-columns: 1fr; } }
        .guest-modal-section { }
        .guest-modal-section h4 { font-size: .78rem; font-weight: 600; color: #9CA3AF; text-transform: uppercase; letter-spacing: .06em; margin: 0 0 10px; }
        .guest-info-row { display: flex; align-items: baseline; gap: 8px; margin-bottom: 6px; font-size: .9rem; }
        .guest-info-label { color: #6B7280; min-width: 80px; font-size: .82rem; }
        .guest-info-value { color: #111827; font-weight: 500; }
        .guest-stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
        .guest-stat-box { background: #F9FAFB; border-radius: 8px; padding: 10px 12px; text-align: center; }
        .guest-stat-box .val { font-size: 1.4rem; font-weight: 700; color: #111827; }
        .guest-stat-box .lbl { font-size: .72rem; color: #6B7280; margin-top: 2px; }
        .history-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .history-table th { padding: 6px 8px; text-align: left; color: #9CA3AF; font-size: .72rem; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #E5E7EB; }
        .history-table td { padding: 7px 8px; border-bottom: 1px solid #F3F4F6; }
        .history-table tr:last-child td { border-bottom: none; }
        .status-badge { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: .72rem; font-weight: 600; }
        .status-badge.confirmed { background: #D1FAE5; color: #065F46; }
        .status-badge.pending   { background: #FEF3C7; color: #92400E; }
        .status-badge.cancelled { background: #FEE2E2; color: #991B1B; }
        .status-badge.arrived   { background: #DBEAFE; color: #1E40AF; }
        .tags-edit { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 6px; }
        .tag-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: .75rem; font-weight: 600; cursor: pointer; border: 1.5px solid transparent; background: #F3F4F6; color: #374151; transition: all .15s; }
        .tag-btn.active { background: #FEF3C7; color: #92400E; border-color: #F59E0B; }
        .tag-btn.active.vip { background: #FEE2E2; color: #991B1B; border-color: #EF4444; }
        .notes-textarea { width: 100%; border: 1px solid #D1D5DB; border-radius: 8px; padding: 8px 10px; font-size: .875rem; resize: vertical; min-height: 70px; font-family: inherit; }
        .notes-textarea:focus { outline: none; border-color: #F59E0B; box-shadow: 0 0 0 3px rgba(245,158,11,.1); }
        .blacklist-row { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: .85rem; }

        /* Upsell banner */
        .upsell-banner { background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%); border: 1px solid #F59E0B; border-radius: 14px; padding: 32px 28px; text-align: center; margin: 40px auto; max-width: 500px; }
        .upsell-banner h2 { font-size: 1.15rem; font-weight: 700; color: #92400E; margin: 0 0 10px; }
        .upsell-banner p  { color: #78350F; font-size: .9rem; margin: 0 0 20px; line-height: 1.5; }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/design.css?v=<?= @filemtime(__DIR__ . '/../assets/css/design.css') ?>">
    <script>
    window.__LANG__ = '<?= get_lang() ?>';
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="<?= BASE_PATH ?>/assets/js/i18n.js"></script>
</head>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<div class="guests-wrap">

<?php if (!$hasFeature): ?>
<!-- Upsell za Basic/Trial -->
<div class="upsell-banner">
    <h2><?= t('guests.upsell_title') ?></h2>
    <p><?= t_raw('guests.upsell_text') ?></p>
    <a href="<?= BASE_PATH ?>/pages/billing.php" class="btn btn-primary"><?= t('guests.upgrade_btn') ?></a>
</div>
<?php else: ?>

<h1 class="guests-title"><?= t('guests.title') ?></h1>

<!-- Toolbar -->
<div class="guests-toolbar">
    <input type="hidden" id="rest-select" value="<?= $activeRestId ?>">
    <input type="search" id="guests-search" name="q" class="guests-search"
           placeholder="<?= t('guests.search_placeholder') ?>"
           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
           data-form-type="other" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
</div>

<!-- Tabela -->
<div class="guests-table-wrap">
    <table class="guests-table">
        <thead>
            <tr>
                <th><?= t('guests.col_guest') ?></th>
                <th><?= t('guests.col_phone') ?></th>
                <th><?= t('guests.col_visits') ?></th>
                <th><?= t('guests.col_last_visit') ?></th>
                <th><?= t('guests.col_tags') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="guests-tbody">
            <tr><td colspan="6" class="guests-empty"><?= t('common.loading') ?></td></tr>
        </tbody>
    </table>
    <div class="guests-pagination" id="guests-pagination" style="display:none">
        <span id="pagination-info"></span>
        <div class="pagination-btns" id="pagination-btns"></div>
    </div>
</div>

<?php endif; ?>
</div><!-- .guests-wrap -->

<!-- ── Modal profila gosta ─────────────────────────────────────── -->
<div id="guest-modal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeGuestModal()">
<div class="modal-box" style="max-width:700px;width:calc(100% - 32px);max-height:90vh;overflow-y:auto">
    <div class="modal-header">
        <h3 class="modal-title" id="gm-title"><?= t('guests.modal_title') ?></h3>
        <button class="modal-close" onclick="closeGuestModal()">✕</button>
    </div>
    <div class="modal-body" id="gm-body">
        <div style="text-align:center;padding:32px;color:#9CA3AF"><?= t('common.loading') ?></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-outline" onclick="closeGuestModal()"><?= t('common.close') ?></button>
        <button class="btn btn-primary" id="gm-save-btn" onclick="saveGuestProfile()"><?= t('common.save') ?></button>
    </div>
</div>
</div>

<script>
const BASE = '<?= BASE_PATH ?>';
const PREDEFINED_TAGS = [
    'VIP',
    t('guests.tag_allergy'),
    t('guests.tag_special'),
    t('guests.tag_regular'),
    'No-show',
    t('guests.tag_vegetarian'),
];

let state = {
    restId: <?= $activeRestId ?>,
    search: '',
    offset: 0,
    limit: 50,
    total: 0,
    currentGuest: null,
    pendingTags: [],
    searchTimer: null,
};

// ── Inicializacija ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const restSel = document.getElementById('rest-select');
    const searchEl = document.getElementById('guests-search');

    if (restSel && restSel.tagName === 'SELECT') {
        restSel.addEventListener('change', () => {
            state.restId = parseInt(restSel.value) || 0;
            state.offset = 0;
            loadGuests();
        });
    }

    if (searchEl) {
        searchEl.addEventListener('input', () => {
            clearTimeout(state.searchTimer);
            state.searchTimer = setTimeout(() => {
                state.search = searchEl.value.trim();
                state.offset = 0;
                loadGuests();
            }, 350);
        });
    }

    if (state.restId) loadGuests();
});

// ── Naloži seznam gostov ────────────────────────────────────────
async function loadGuests() {
    if (!state.restId) {
        document.getElementById('guests-tbody').innerHTML = `<tr><td colspan="6" class="guests-empty">${t('guests.select_restaurant')}</td></tr>`;
        document.getElementById('guests-pagination').style.display = 'none';
        return;
    }

    const params = new URLSearchParams({
        restaurant_id: state.restId,
        limit: state.limit,
        offset: state.offset,
    });
    if (state.search) params.set('search', state.search);

    const tbody = document.getElementById('guests-tbody');
    tbody.innerHTML = `<tr><td colspan="6" class="guests-empty" style="color:#9CA3AF">${t('common.loading')}</td></tr>`;

    try {
        const res = await fetch(`${BASE}/api/guests.php?${params}`);
        const json = await res.json();
        if (!json.success) { tbody.innerHTML = `<tr><td colspan="6" class="guests-empty">${esc(json.error || t('common.error'))}</td></tr>`; return; }

        state.total = json.data.total;
        renderGuests(json.data.guests);
        renderPagination();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="guests-empty">${t('guests.load_error')}</td></tr>`;
    }
}

function renderGuests(guests) {
    const tbody = document.getElementById('guests-tbody');
    if (!guests.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="guests-empty">${t('guests.empty')}${state.search ? ' ' + t('guests.empty_search_prefix') + ' "' + esc(state.search) + '"' : ''}.</td></tr>`;
        return;
    }
    tbody.innerHTML = guests.map(g => {
        const name = [g.first_name, g.last_name].filter(Boolean).join(' ') || '—';
        const tags = (g.tags || []).slice(0, 3).map(t =>
            `<span class="tag-pill${t === 'VIP' ? ' vip' : ''}">${esc(t)}</span>`
        ).join('');
        const lastVisit = g.last_visit ? fmtDate(g.last_visit) : '—';
        const blackTag  = g.is_blacklisted ? `<span class="tag-pill blacklisted">${t('guests.blacklisted')}</span>` : '';
        const subline   = g.email || (g.phone ? g.phone : '—');
        const [idVal, idType] = g.email ? [g.email, 'email'] : [g.phone, 'phone'];
        return `<tr onclick="openGuestModal('${esc(idVal)}','${idType}')">
            <td>
                <div class="guest-name">${esc(name)}</div>
                <div class="guest-email">${esc(subline)}</div>
            </td>
            <td class="stat-mini">${esc(g.phone || '—')}</td>
            <td class="stat-mini"><span class="num">${g.total_visits}</span>${g.no_shows ? ` <span style="color:#EF4444;font-size:.75rem">(${g.no_shows} ns)</span>` : ''}</td>
            <td class="stat-mini">${lastVisit}</td>
            <td>${tags}${blackTag}</td>
            <td><button class="btn-row" onclick="event.stopPropagation();openGuestModal('${esc(idVal)}','${idType}')">${t('common.open')}</button></td>
        </tr>`;
    }).join('');
}

function renderPagination() {
    const el = document.getElementById('guests-pagination');
    const info = document.getElementById('pagination-info');
    const btns = document.getElementById('pagination-btns');
    const pages = Math.ceil(state.total / state.limit);
    const current = Math.floor(state.offset / state.limit);

    if (state.total <= state.limit) { el.style.display = 'none'; return; }
    el.style.display = 'flex';

    const from = state.offset + 1;
    const to   = Math.min(state.offset + state.limit, state.total);
    info.textContent = t('guests.pagination_range', { from, to, total: state.total });

    let html = `<button onclick="goPage(${current - 1})" ${current === 0 ? 'disabled' : ''}>‹</button>`;
    for (let i = 0; i < pages; i++) {
        if (pages > 7 && Math.abs(i - current) > 2 && i !== 0 && i !== pages - 1) {
            if (Math.abs(i - current) === 3) html += '<button disabled>…</button>';
            continue;
        }
        html += `<button onclick="goPage(${i})" class="${i === current ? 'active' : ''}">${i + 1}</button>`;
    }
    html += `<button onclick="goPage(${current + 1})" ${current >= pages - 1 ? 'disabled' : ''}>›</button>`;
    btns.innerHTML = html;
}

function goPage(p) {
    const pages = Math.ceil(state.total / state.limit);
    if (p < 0 || p >= pages) return;
    state.offset = p * state.limit;
    loadGuests();
}

// ── Modal profila gosta ────────────────────────────────────────
async function openGuestModal(value, type = 'email') {
    if (!state.restId) return;
    document.getElementById('gm-title').textContent = t('common.loading');
    document.getElementById('gm-body').innerHTML = `<div style="text-align:center;padding:32px;color:#9CA3AF">${t('common.loading')}</div>`;
    document.getElementById('guest-modal').style.display = 'flex';

    const params = new URLSearchParams({ restaurant_id: state.restId, [type]: value });
    const res  = await fetch(`${BASE}/api/guests.php?${params}`);
    const json = await res.json();
    if (!json.success) {
        document.getElementById('gm-body').innerHTML = `<div style="text-align:center;padding:32px;color:#EF4444">${esc(json.error || t('common.error'))}</div>`;
        return;
    }

    const g = json.data;
    state.currentGuest = g;
    state.pendingTags  = [...(g.tags || [])];

    document.getElementById('gm-title').textContent =
        ([g.first_name, g.last_name].filter(Boolean).join(' ') || g.email);

    const avgRating = g.avg_rating ? `⭐ ${g.avg_rating}` : '—';
    const firstVisit = g.first_visit ? fmtDate(g.first_visit) : '—';
    const lastVisit  = g.last_visit  ? fmtDate(g.last_visit)  : '—';

    const historyRows = (g.history || []).map(h => {
        const statusClass = h.status === 'confirmed' ? 'confirmed' : h.status === 'pending' ? 'pending' : h.status === 'cancelled' ? 'cancelled' : h.arrived_at ? 'arrived' : 'confirmed';
        const statusLbl   = { confirmed: t('guests.status_confirmed'), pending: t('guests.status_pending'), cancelled: t('guests.status_cancelled'), arrived: t('guests.status_arrived') }[h.status] || h.status;
        return `<tr>
            <td>${fmtDate(h.reservation_date)}</td>
            <td>${(h.reservation_time || '').slice(0,5)}</td>
            <td>${h.guest_count}</td>
            <td><span class="status-badge ${h.status}">${statusLbl}</span></td>
        </tr>`;
    }).join('') || `<tr><td colspan="4" style="color:#9CA3AF;text-align:center;padding:12px">${t('guests.no_history')}</td></tr>`;

    const tagBtns = PREDEFINED_TAGS.map(t =>
        `<button class="tag-btn${state.pendingTags.includes(t) ? (t === 'VIP' ? ' active vip' : ' active') : ''}" onclick="toggleTag('${esc(t)}')" id="tagbtn-${t.replace(/[^a-z]/gi,'_')}">${esc(t)}</button>`
    ).join('');

    document.getElementById('gm-body').innerHTML = `
        <div class="guest-stat-grid">
            <div class="guest-stat-box"><div class="val">${g.total_visits}</div><div class="lbl">${t('guests.stat_visits')}</div></div>
            <div class="guest-stat-box"><div class="val">${g.total_covers}</div><div class="lbl">${t('guests.stat_covers')}</div></div>
            <div class="guest-stat-box"><div class="val">${avgRating}</div><div class="lbl">${t('guests.stat_rating')}</div></div>
        </div>

        <div class="guest-modal-body">
            <div class="guest-modal-section">
                <h4>${t('guests.section_contact')}</h4>
                ${g.email ? `<div class="guest-info-row"><span class="guest-info-label">${t('book.email')}</span><span class="guest-info-value">${esc(g.email)}</span></div>` : ''}
                ${!g.email && g.phone ? `<div class="guest-info-row"><span class="guest-info-label">${t('guests.col_phone')}</span><span class="guest-info-value">${esc(g.phone)}</span></div>` : ''}
                <div class="guest-info-row"><span class="guest-info-label">${t('book.first_name')}</span>
                    <input type="text" id="gm-first-name" value="${esc(g.first_name || '')}" placeholder="${t('book.first_name')}" style="flex:1;padding:4px 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:.875rem">
                </div>
                <div class="guest-info-row"><span class="guest-info-label">${t('book.last_name')}</span>
                    <input type="text" id="gm-last-name" value="${esc(g.last_name || '')}" placeholder="${t('book.last_name')}" style="flex:1;padding:4px 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:.875rem">
                </div>
                <div class="guest-info-row"><span class="guest-info-label">${t('book.phone')}</span>
                    <input type="text" id="gm-phone" value="${esc(g.phone || '')}" placeholder="${t('book.phone')}" style="flex:1;padding:4px 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:.875rem">
                </div>
                <div class="guest-info-row"><span class="guest-info-label">${t('guests.first_visit')}</span><span class="guest-info-value">${firstVisit}</span></div>
                <div class="guest-info-row"><span class="guest-info-label">${t('guests.last_visit')}</span><span class="guest-info-value">${lastVisit}</span></div>

                <h4 style="margin-top:16px">${t('guests.col_tags')}</h4>
                <div class="tags-edit" id="tags-edit">${tagBtns}</div>

                <div class="blacklist-row">
                    <input type="checkbox" id="gm-blacklisted" ${g.is_blacklisted ? 'checked' : ''}>
                    <label for="gm-blacklisted" style="cursor:pointer">${t('guests.blacklisted_guest')}</label>
                </div>
            </div>

            <div class="guest-modal-section">
                <h4>${t('guests.notes_label')}</h4>
                <textarea id="gm-notes" class="notes-textarea" placeholder="${t('guests.notes_placeholder')}">${esc(g.notes || '')}</textarea>

                <h4 style="margin-top:16px">${t('guests.history_title')}</h4>
                <div style="max-height:220px;overflow-y:auto;border:1px solid #E5E7EB;border-radius:8px">
                    <table class="history-table">
                        <thead><tr><th>${t('book.summary_date')}</th><th>${t('book.summary_time')}</th><th>${t('book.summary_guests')}</th><th>${t('guests.col_status')}</th></tr></thead>
                        <tbody>${historyRows}</tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
}

function closeGuestModal() {
    document.getElementById('guest-modal').style.display = 'none';
    state.currentGuest = null;
}

function toggleTag(tag) {
    const idx = state.pendingTags.indexOf(tag);
    if (idx >= 0) {
        state.pendingTags.splice(idx, 1);
    } else {
        state.pendingTags.push(tag);
    }
    const btnId = 'tagbtn-' + tag.replace(/[^a-z]/gi, '_');
    const btn = document.getElementById(btnId);
    if (btn) {
        const isVip = tag === 'VIP';
        btn.className = 'tag-btn' + (state.pendingTags.includes(tag) ? (isVip ? ' active vip' : ' active') : '');
    }
}

async function saveGuestProfile() {
    const g = state.currentGuest;
    if (!g) return;

    const saveBtn = document.getElementById('gm-save-btn');
    saveBtn.disabled = true;
    saveBtn.textContent = t('guests.saving');

    const body = {
        notes:         document.getElementById('gm-notes').value,
        tags:          state.pendingTags,
        is_blacklisted: document.getElementById('gm-blacklisted').checked ? 1 : 0,
        first_name:    document.getElementById('gm-first-name').value,
        last_name:     document.getElementById('gm-last-name').value,
        phone:         document.getElementById('gm-phone').value,
    };

    try {
        const res  = await fetch(`${BASE}/api/guests.php?id=${g.id}`, { method: 'PUT', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
        const json = await res.json();
        if (json.success) {
            closeGuestModal();
            loadGuests();
        } else {
            alert(t('common.error') + ': ' + (json.error || t('guests.unknown_error')));
        }
    } catch (e) {
        alert(t('guests.save_error'));
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = t('common.save');
    }
}

// ── Pomožne ────────────────────────────────────────────────────
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function fmtDate(d) {
    if (!d) return '—';
    const [y,m,day] = d.slice(0,10).split('-');
    return `${parseInt(day)}. ${parseInt(m)}. ${y}`;
}
</script>
</main>
</div><!-- /rz-app -->
</body>
</html>
