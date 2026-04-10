<?php
// survey_results.php je preseljen v restaurant-edit.php (tab Anketa)
require_once '../includes/auth_check.php';
if (is_logged_in()) {
    header('Location: ' . BASE_PATH . '/pages/admin.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

if (!is_logged_in()) redirect_to_login();
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
$hasSurvey = user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey');
$hasExport = user_has_feature($pdo, (int)$_SESSION['user_id'], 'survey_export');

$restaurants = [];
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.name FROM restaurants r
        JOIN restaurant_admins ra ON r.id = ra.restaurant_id
        WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $restaurants = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Odgovori ankete – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=4">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css?v=3">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css?v=2">
    <style>
        .results-wrap{max-width:960px;margin:0 auto;padding:28px 16px 60px}
        .results-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:22px}
        .results-filters label{font-size:.8rem;font-weight:500;color:#374151;display:block;margin-bottom:4px}
        .results-filters select,.results-filters input{border:1px solid #D1D5DB;border-radius:8px;padding:8px 11px;font-size:.88rem;font-family:inherit;background:#fff;color:#111827}
        .results-filters select:focus,.results-filters input:focus{outline:none;border-color:#F59E0B}
        .btn-filter{background:#F59E0B;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:.88rem;font-weight:600;cursor:pointer;font-family:inherit;height:38px}
        .btn-filter:hover{background:#D97706}
        .btn-export{background:#fff;color:#374151;border:1px solid #D1D5DB;border-radius:8px;padding:9px 16px;font-size:.88rem;font-weight:500;cursor:pointer;font-family:inherit;height:38px;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
        .btn-export:hover{border-color:#F59E0B;color:#D97706}
        .btn-export.disabled{opacity:.5;cursor:default;pointer-events:none}
        .results-table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.07)}
        .results-table th{padding:12px 14px;font-size:.78rem;font-weight:600;color:#6B7280;text-align:left;border-bottom:1px solid #F3F4F6;white-space:nowrap;background:#F9FAFB}
        .results-table td{padding:11px 14px;font-size:.85rem;color:#374151;border-bottom:1px solid #F9FAFB;vertical-align:middle}
        .results-table tr:last-child td{border-bottom:none}
        .results-table tr:hover td{background:#FFFBEB;cursor:pointer}
        .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600}
        .badge-public{background:#D1FAE5;color:#065F46}
        .badge-anonymous{background:#DBEAFE;color:#1D4ED8}
        .badge-private{background:#F3F4F6;color:#6B7280}
        .badge-submitted{background:#D1FAE5;color:#065F46}
        .badge-sent{background:#FEF3C7;color:#92400E}
        .badge-pending{background:#F3F4F6;color:#9CA3AF}
        .empty-state{text-align:center;padding:50px 20px;color:#9CA3AF;font-size:.9rem}
        .gate-notice{background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:18px 20px;margin-bottom:20px;font-size:.9rem;color:#92400E}
        .gate-notice a{color:#B45309;font-weight:600}
        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:16px}
        .modal-overlay.open{display:flex}
        .modal-box{background:#fff;border-radius:14px;max-width:580px;width:100%;max-height:85vh;overflow-y:auto;padding:28px;position:relative;box-shadow:0 8px 30px rgba(0,0,0,.15)}
        .modal-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#9CA3AF;line-height:1;padding:4px}
        .modal-close:hover{color:#374151}
        .modal-title{font-size:1.05rem;font-weight:700;color:#111827;margin:0 0 4px;padding-right:30px}
        .modal-sub{font-size:.82rem;color:#9CA3AF;margin:0 0 20px}
        .answer-block{margin-bottom:18px}
        .answer-block:last-child{margin-bottom:0}
        .answer-q{font-size:.83rem;font-weight:600;color:#6B7280;margin-bottom:5px}
        .answer-val{font-size:.92rem;color:#111827;line-height:1.5}
        .stars-display{display:flex;gap:3px}
        .stars-display svg{display:block}
        @media(max-width:640px){.results-table th:nth-child(3),.results-table td:nth-child(3){display:none}}
    </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo" style="flex-shrink:0">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
        <?php if ($isAdmin): ?><?= plan_badge($_SESSION['plan_slug'] ?? 'trial') ?><?php endif; ?>
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Odgovori ankete</span>
    </div>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Razpored
        </a>
        <?php if ($isAdmin): ?>
        <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">Admin</a>
        <a href="<?= BASE_PATH ?>/pages/survey_builder.php" class="btn-header">Uredi anketo</a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header">Profil</a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
    <button class="hamburger-btn" id="hamburger-btn" onclick="document.getElementById('mobile-nav').classList.toggle('open')">
        <span></span><span></span><span></span>
    </button>
</header>

<div class="mobile-nav" id="mobile-nav">
    <div class="mobile-nav-user">👤 <?= h($fullName) ?></div>
    <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">Razpored</a>
    <?php if ($isAdmin): ?>
    <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">Admin</a>
    <a href="<?= BASE_PATH ?>/pages/survey_builder.php" class="btn-header">Uredi anketo</a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header">Profil</a>
    <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
</div>

<?php require_once '../includes/trial_banner.php'; ?>

<div class="results-wrap">

    <?php if (!$hasSurvey): ?>
    <div class="gate-notice">
        Ta funkcionalnost je na voljo v paketu <strong>Advanced</strong> ali višjem.
        <a href="<?= BASE_PATH ?>/pages/billing.php">Nadgradi paket →</a>
    </div>
    <?php else: ?>

    <!-- Filtri -->
    <div class="results-filters">
        <?php if ($isAdmin && count($restaurants) > 1): ?>
        <div>
            <label>Restavracija</label>
            <select id="f-restaurant">
                <option value="">Vse restavracije</option>
                <?php foreach ($restaurants as $r): ?>
                <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif (count($restaurants) === 1): ?>
        <input type="hidden" id="f-restaurant" value="<?= $restaurants[0]['id'] ?>">
        <?php endif; ?>

        <div>
            <label>Od datuma</label>
            <input type="date" id="f-from">
        </div>
        <div>
            <label>Do datuma</label>
            <input type="date" id="f-to">
        </div>
        <div>
            <label>Soglasje</label>
            <select id="f-consent">
                <option value="">Vse</option>
                <option value="public">Javno</option>
                <option value="anonymous">Anonimno</option>
                <option value="private">Zasebno</option>
            </select>
        </div>
        <button class="btn-filter" onclick="loadResults()">Prikaži</button>
        <?php if ($hasExport): ?>
        <a href="#" class="btn-export" id="btn-export" onclick="exportCsv(event)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Izvozi CSV
        </a>
        <?php else: ?>
        <span class="btn-export disabled" title="Na voljo v paketu Premium">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Izvozi CSV (Premium)
        </span>
        <?php endif; ?>
    </div>

    <div id="results-container">
        <div class="empty-state">Izberite filter in kliknite Prikaži.</div>
    </div>

    <?php endif; ?>
</div>

<!-- ── Modal ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="detail-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">×</button>
        <div id="modal-content"></div>
    </div>
</div>

<script>
const BASE = '<?= BASE_PATH ?>';

function loadResults() {
    const restId   = document.getElementById('f-restaurant')?.value || '';
    const from     = document.getElementById('f-from')?.value     || '';
    const to       = document.getElementById('f-to')?.value       || '';
    const consent  = document.getElementById('f-consent')?.value  || '';

    if (!restId) { alert('Izberite restavracijo.'); return; }

    const params = new URLSearchParams({ action: 'get_results', restaurant_id: restId });
    if (from)    params.set('date_from', from);
    if (to)      params.set('date_to',   to);
    if (consent) params.set('consent',   consent);

    document.getElementById('results-container').innerHTML = '<div class="empty-state">Nalagam...</div>';

    fetch(`${BASE}/api/survey.php?${params}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { alert(res.error || 'Napaka'); return; }
            renderTable(res.data || []);
        })
        .catch(() => alert('Napaka pri nalaganju.'));
}

const CONSENT_LABELS = { public:'Javno', anonymous:'Anonimno', private:'Zasebno' };
const CONSENT_BADGES = { public:'badge-public', anonymous:'badge-anonymous', private:'badge-private' };

function renderTable(rows) {
    const cont = document.getElementById('results-container');
    if (!rows.length) {
        cont.innerHTML = '<div class="empty-state">Ni odgovorov za izbrani filter.</div>';
        return;
    }
    let html = `
    <table class="results-table">
        <thead><tr>
            <th>Datum oddaje</th>
            <th>Gost</th>
            <th>Datum rezervacije</th>
            <th>Soglasje</th>
            <th>Status</th>
        </tr></thead>
        <tbody>`;
    rows.forEach(r => {
        const status = r.submitted_at
            ? `<span class="badge badge-submitted">Izpolnjena</span>`
            : r.email_sent_at
                ? `<span class="badge badge-sent">Email poslan</span>`
                : `<span class="badge badge-pending">Čaka pošiljanje</span>`;
        const consent = r.consent
            ? `<span class="badge ${CONSENT_BADGES[r.consent] || ''}">${CONSENT_LABELS[r.consent] || r.consent}</span>`
            : '–';
        const submitted = r.submitted_at ? fmtDate(r.submitted_at) : '–';
        html += `
        <tr onclick="openDetail(${r.id})">
            <td>${submitted}</td>
            <td>${hesc(r.guest_name)}</td>
            <td>${r.reservation_date ? fmtDate(r.reservation_date + ' ' + (r.reservation_time||'')) : '–'}</td>
            <td>${consent}</td>
            <td>${status}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    cont.innerHTML = html;
}

function fmtDate(s) {
    if (!s) return '–';
    const d = new Date(s.replace(' ', 'T'));
    if (isNaN(d)) return s;
    return d.toLocaleDateString('sl-SI', { day:'2-digit', month:'2-digit', year:'numeric' })
        + (s.includes(':') ? ' ' + d.toLocaleTimeString('sl-SI', { hour:'2-digit', minute:'2-digit' }) : '');
}

function hesc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function openDetail(id) {
    const mc = document.getElementById('modal-content');
    mc.innerHTML = '<div style="text-align:center;padding:30px;color:#9CA3AF">Nalagam...</div>';
    document.getElementById('detail-modal').classList.add('open');

    fetch(`${BASE}/api/survey.php?action=get_response_detail&id=${id}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { mc.innerHTML = '<p style="color:#EF4444">' + hesc(res.error) + '</p>'; return; }
            renderDetail(res.data);
        })
        .catch(() => mc.innerHTML = '<p style="color:#EF4444">Napaka pri nalaganju.</p>');
}

function renderDetail(data) {
    const { response: sr, answers } = data;
    const consentMap = { public:'Javno z imenom', anonymous:'Anonimno', private:'Ne strinja se z objavo' };
    const submitted = sr.submitted_at ? fmtDate(sr.submitted_at) : '–';

    let html = `
        <div class="modal-title">Odgovor ankete</div>
        <div class="modal-sub">Oddano: ${submitted} · Soglasje: ${consentMap[sr.consent] || '–'}</div>
    `;

    if (!answers || !answers.length) {
        html += '<p style="color:#9CA3AF;font-size:.88rem">Ni odgovorov.</p>';
    } else {
        answers.forEach(a => {
            html += '<div class="answer-block">';
            html += `<div class="answer-q">${hesc(a.question_text)}</div>`;
            if (a.type === 'rating' && a.answer_text) {
                const val = parseInt(a.answer_text);
                let stars = '<div class="stars-display">';
                for (let i = 1; i <= 5; i++) {
                    const filled = i <= val;
                    stars += `<svg width="18" height="18" viewBox="0 0 24 24" fill="${filled?'#F59E0B':'none'}" stroke="${filled?'#F59E0B':'#D1D5DB'}" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`;
                }
                stars += '</div>';
                html += `<div class="answer-val">${stars} <span style="font-size:.82rem;color:#6B7280;margin-left:4px">${val}/5</span></div>`;
            } else if (a.option_labels && a.option_labels.length) {
                html += `<div class="answer-val">${a.option_labels.map(l => hesc(l)).join(', ')}</div>`;
            } else if (a.answer_text) {
                html += `<div class="answer-val" style="white-space:pre-wrap">${hesc(a.answer_text)}</div>`;
            } else {
                html += `<div class="answer-val" style="color:#9CA3AF">–</div>`;
            }
            html += '</div>';
        });
    }

    document.getElementById('modal-content').innerHTML = html;
}

function closeModal() {
    document.getElementById('detail-modal').classList.remove('open');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function exportCsv(e) {
    e.preventDefault();
    const restId = document.getElementById('f-restaurant')?.value || '';
    if (!restId) { alert('Izberite restavracijo.'); return; }
    const from   = document.getElementById('f-from')?.value || '';
    const to     = document.getElementById('f-to')?.value   || '';
    const params = new URLSearchParams({ action: 'export_csv', restaurant_id: restId });
    if (from) params.set('date_from', from);
    if (to)   params.set('date_to',   to);
    window.location.href = `${BASE}/api/survey.php?${params}`;
}

// Ob nalaganju strani – če je samo ena restavracija, naložimo samodejno
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('f-restaurant');
    if (sel && sel.tagName === 'INPUT' && sel.value) loadResults();
});
</script>
</body>
</html>
