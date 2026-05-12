<?php
/**
 * Anketa — pregled posameznih odgovorov + izvoz CSV (Excel-compatible).
 * Rezble design, admin shell s sidebarjem.
 */
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

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
} elseif (!empty($_SESSION['restaurant_id'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM restaurants WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['restaurant_id']]);
    $restaurants = $stmt->fetchAll();
}

$pageTitle = t('survey_results.page_title');
$extraCss  = ['main.css?v=4', 'design.css?v=2'];
require_once '../includes/html_head.php';
?>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">

<?php require_once '../includes/trial_banner.php'; ?>

<?php
$topbarTitle    = t('survey_results.page_title');
$topbarSubtitle = t('survey_results.subtitle');
// "Uredi anketo" pelje na restaurant-edit > Anketa tab. Privzeto prva restavracija
// (ali aktivna iz seje); v JS dinamično posodobimo glede na izbrano v filtru.
$_editRestId = !empty($_SESSION['restaurant_id'])
    ? (int)$_SESSION['restaurant_id']
    : (int)($restaurants[0]['id'] ?? 0);
ob_start();
if ($hasSurvey && $isAdmin && $_editRestId):
?>
    <a href="<?= BASE_PATH ?>/pages/restaurant-edit.php?id=<?= $_editRestId ?>#anketa" class="rz-btn" id="btn-edit-survey">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <span><?= t('survey_results.builder_link') ?></span>
    </a>
<?php
endif;
$topbarActions = ob_get_clean();
require_once '../includes/topbar.php';
?>

<div class="rz-wrap">

<?php if (!$hasSurvey): ?>

    <div class="rz-card" style="background:var(--terracotta-soft);border-color:#f4d4c4">
        <div style="padding:20px 22px;font-size:.92rem;color:var(--terracotta-2);line-height:1.55">
            <?= t_raw('survey_results.gate_notice') ?>
            <a href="<?= BASE_PATH ?>/pages/billing.php" style="color:var(--terracotta);font-weight:600;text-decoration:underline"><?= t('survey_results.gate_upgrade') ?></a>
        </div>
    </div>

<?php else: ?>

    <!-- Filtri -->
    <div class="rz-card" style="margin-bottom:20px">
        <div class="rz-card-head">
            <h2 class="rz-card-title"><?= t('survey_results.filters_title') ?></h2>
        </div>
        <div style="padding:18px 22px;display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
            <?php if ($isAdmin && count($restaurants) > 1): ?>
            <div style="display:flex;flex-direction:column;gap:5px">
                <label style="font-size:.78rem;font-weight:600;color:var(--text-2)"><?= t('survey_results.label_restaurant') ?></label>
                <select id="f-restaurant" class="rz-input" style="min-width:180px">
                    <option value=""><?= t('survey_results.all_restaurants') ?></option>
                    <?php foreach ($restaurants as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php elseif (count($restaurants) === 1): ?>
            <input type="hidden" id="f-restaurant" value="<?= $restaurants[0]['id'] ?>">
            <?php endif; ?>

            <div style="display:flex;flex-direction:column;gap:5px">
                <label style="font-size:.78rem;font-weight:600;color:var(--text-2)"><?= t('survey_results.label_date_from') ?></label>
                <input type="date" id="f-from" class="rz-input">
            </div>
            <div style="display:flex;flex-direction:column;gap:5px">
                <label style="font-size:.78rem;font-weight:600;color:var(--text-2)"><?= t('survey_results.label_date_to') ?></label>
                <input type="date" id="f-to" class="rz-input">
            </div>
            <div style="display:flex;flex-direction:column;gap:5px">
                <label style="font-size:.78rem;font-weight:600;color:var(--text-2)"><?= t('survey_results.label_consent') ?></label>
                <select id="f-consent" class="rz-input">
                    <option value=""><?= t('survey_results.consent_all') ?></option>
                    <option value="public"><?= t('survey_results.consent_public') ?></option>
                    <option value="anonymous"><?= t('survey_results.consent_anonymous') ?></option>
                    <option value="private"><?= t('survey_results.consent_private') ?></option>
                </select>
            </div>
            <button class="rz-btn rz-btn-primary" onclick="loadResults()" style="height:38px;align-self:flex-end"><?= t('survey_results.btn_show') ?></button>
            <?php if ($hasExport): ?>
            <a href="#" class="rz-btn" id="btn-export" onclick="exportCsv(event)" style="height:38px;align-self:flex-end">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span><?= t('survey_results.btn_export') ?></span>
            </a>
            <?php else: ?>
            <span class="rz-btn" style="height:38px;align-self:flex-end;opacity:.5;cursor:default" title="<?= t('survey_results.btn_export_premium') ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?= t('survey_results.btn_export_premium') ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rezultati -->
    <div class="rz-card">
        <div class="rz-card-head">
            <div>
                <div class="rz-card-eyebrow"><?= t('survey_results.list_eyebrow') ?></div>
                <h2 class="rz-card-title"><?= t('survey_results.list_title') ?></h2>
            </div>
        </div>
        <div id="results-container" style="padding:8px 0">
            <div style="text-align:center;padding:48px 20px;color:var(--text-2);font-size:.9rem"><?= t('survey_results.empty_initial') ?></div>
        </div>
    </div>

<?php endif; ?>

</div>

</main>
</div>

<!-- Detail modal -->
<div id="detail-modal" style="display:none;position:fixed;inset:0;background:rgba(28,38,32,.55);z-index:1000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)" onclick="if(event.target===this)closeModal()">
    <div style="background:#fff;border-radius:14px;max-width:600px;width:100%;max-height:88vh;overflow-y:auto;padding:32px 30px;position:relative;box-shadow:0 24px 60px rgba(28,38,32,.25)">
        <button onclick="closeModal()" style="position:absolute;top:14px;right:14px;background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--text-2);line-height:1;padding:6px;border-radius:6px" onmouseover="this.style.background='var(--cream)'" onmouseout="this.style.background='none'">×</button>
        <div id="modal-content"></div>
    </div>
</div>

<style>
/* Local: tabela rezultatov v Rezble stilu */
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .88rem;
}
.sr-table th {
    padding: 10px 16px;
    text-align: left;
    font-size: .75rem;
    font-weight: 700;
    color: var(--text-2);
    text-transform: uppercase;
    letter-spacing: .04em;
    border-bottom: 1.5px solid var(--line);
    background: var(--cream);
    white-space: nowrap;
}
.sr-table td {
    padding: 12px 16px;
    color: var(--ink);
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
.sr-table tbody tr {
    transition: background .12s;
}
.sr-table tbody tr:hover {
    background: var(--cream);
    cursor: pointer;
}
.sr-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .02em;
}
.sr-badge-public      { background:#D1FAE5; color:#065F46 }
.sr-badge-anonymous   { background:#DBEAFE; color:#1E40AF }
.sr-badge-private     { background:var(--cream); color:var(--text-2) }
.sr-badge-submitted   { background:#D1FAE5; color:#065F46 }
.sr-badge-sent        { background:var(--terracotta-soft); color:var(--terracotta-2) }
.sr-badge-pending     { background:var(--cream); color:var(--text-2) }

.sr-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--text-2);
    font-size: .9rem;
}

.sr-answer {
    margin-bottom: 18px;
}
.sr-answer:last-child { margin-bottom: 0 }
.sr-answer-q {
    font-size: .8rem;
    font-weight: 700;
    color: var(--text-2);
    margin-bottom: 6px;
    letter-spacing: .02em;
    text-transform: uppercase;
}
.sr-answer-val {
    font-size: .95rem;
    color: var(--ink);
    line-height: 1.5;
}
.sr-stars {
    display: inline-flex;
    gap: 3px;
    align-items: center;
}
@media (max-width: 700px) {
    .sr-table th:nth-child(3), .sr-table td:nth-child(3) { display:none }
}
</style>

<script>
const BASE = '<?= BASE_PATH ?>';

function loadResults() {
    const restId   = document.getElementById('f-restaurant')?.value || '';
    const from     = document.getElementById('f-from')?.value     || '';
    const to       = document.getElementById('f-to')?.value       || '';
    const consent  = document.getElementById('f-consent')?.value  || '';

    if (!restId) { alert(window.t('survey_results.err_no_restaurant')); return; }

    const params = new URLSearchParams({ action: 'get_results', restaurant_id: restId });
    if (from)    params.set('date_from', from);
    if (to)      params.set('date_to',   to);
    if (consent) params.set('consent',   consent);

    document.getElementById('results-container').innerHTML = `<div class="sr-empty">${window.t('survey_results.loading')}</div>`;

    fetch(`${BASE}/api/survey.php?${params}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { alert(res.error || window.t('common.error')); return; }
            renderTable(res.data || []);
        })
        .catch(() => alert(window.t('survey_results.err_load')));
}

const CONSENT_LABELS = {
    public:    window.t('survey_results.consent_public'),
    anonymous: window.t('survey_results.consent_anonymous'),
    private:   window.t('survey_results.consent_private'),
};
const CONSENT_BADGES = { public:'sr-badge-public', anonymous:'sr-badge-anonymous', private:'sr-badge-private' };

function renderTable(rows) {
    const cont = document.getElementById('results-container');
    if (!rows.length) {
        cont.innerHTML = `<div class="sr-empty">${window.t('survey_results.empty_results')}</div>`;
        return;
    }
    let html = `
    <table class="sr-table">
        <thead><tr>
            <th>${window.t('survey_results.col_submitted')}</th>
            <th>${window.t('survey_results.col_guest')}</th>
            <th>${window.t('survey_results.col_date')}</th>
            <th>${window.t('survey_results.col_consent')}</th>
            <th>${window.t('survey_results.col_status')}</th>
        </tr></thead>
        <tbody>`;
    rows.forEach(r => {
        const status = r.submitted_at
            ? `<span class="sr-badge sr-badge-submitted">${window.t('survey_results.status_submitted')}</span>`
            : r.email_sent_at
                ? `<span class="sr-badge sr-badge-sent">${window.t('survey_results.status_sent')}</span>`
                : `<span class="sr-badge sr-badge-pending">${window.t('survey_results.status_pending')}</span>`;
        const consent = r.consent
            ? `<span class="sr-badge ${CONSENT_BADGES[r.consent] || ''}">${CONSENT_LABELS[r.consent] || r.consent}</span>`
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
    const loc = (window.__T__ && window.__T__['common.locale']) || 'sl-SI';
    return d.toLocaleDateString(loc, { day:'2-digit', month:'2-digit', year:'numeric' })
        + (s.includes(':') ? ' ' + d.toLocaleTimeString(loc, { hour:'2-digit', minute:'2-digit' }) : '');
}

function hesc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function openDetail(id) {
    const mc = document.getElementById('modal-content');
    mc.innerHTML = `<div style="text-align:center;padding:32px;color:var(--text-2)">${window.t('survey_results.loading')}</div>`;
    document.getElementById('detail-modal').style.display = 'flex';

    fetch(`${BASE}/api/survey.php?action=get_response_detail&id=${id}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { mc.innerHTML = '<p style="color:var(--terracotta-2)">' + hesc(res.error) + '</p>'; return; }
            renderDetail(res.data);
        })
        .catch(() => mc.innerHTML = `<p style="color:var(--terracotta-2)">${window.t('survey_results.err_load')}</p>`);
}

function renderDetail(data) {
    const { response: sr, answers } = data;
    const consentMap = {
        public:    window.t('survey_results.consent_public_full'),
        anonymous: window.t('survey_results.consent_anonymous_full'),
        private:   window.t('survey_results.consent_private_full'),
    };
    const submitted = sr.submitted_at ? fmtDate(sr.submitted_at) : '–';

    let html = `
        <h2 style="font-family:'Source Serif 4',Georgia,serif;font-size:1.4rem;font-weight:700;color:var(--ink);margin:0 0 6px;letter-spacing:-0.01em;line-height:1.2">${window.t('survey_results.detail_title')}</h2>
        <div style="font-size:.82rem;color:var(--text-2);margin:0 0 22px">${window.t('survey_results.detail_submitted_label')}: <strong>${submitted}</strong> · ${window.t('survey_results.detail_consent_label')}: <strong>${consentMap[sr.consent] || '–'}</strong></div>
    `;

    if (!answers || !answers.length) {
        html += `<p style="color:var(--text-2);font-size:.88rem">${window.t('survey_results.detail_no_answers')}</p>`;
    } else {
        answers.forEach(a => {
            html += '<div class="sr-answer">';
            html += `<div class="sr-answer-q">${hesc(a.question_text)}</div>`;
            if (a.type === 'rating' && a.answer_text) {
                const val = parseInt(a.answer_text);
                let stars = '<div class="sr-stars">';
                for (let i = 1; i <= 5; i++) {
                    const filled = i <= val;
                    stars += `<svg width="20" height="20" viewBox="0 0 24 24" fill="${filled?'#c8542b':'none'}" stroke="${filled?'#c8542b':'#e8dcc9'}" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`;
                }
                stars += `<span style="font-size:.85rem;color:var(--text-2);margin-left:8px">${val}/5</span></div>`;
                html += `<div class="sr-answer-val">${stars}</div>`;
            } else if (a.option_labels && a.option_labels.length) {
                html += `<div class="sr-answer-val">${a.option_labels.map(l => hesc(l)).join(', ')}</div>`;
            } else if (a.answer_text) {
                html += `<div class="sr-answer-val" style="white-space:pre-wrap">${hesc(a.answer_text)}</div>`;
            } else {
                html += `<div class="sr-answer-val" style="color:var(--text-2)">–</div>`;
            }
            html += '</div>';
        });
    }

    document.getElementById('modal-content').innerHTML = html;
}

function closeModal() {
    document.getElementById('detail-modal').style.display = 'none';
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function exportCsv(e) {
    e.preventDefault();
    const restId = document.getElementById('f-restaurant')?.value || '';
    if (!restId) { alert(window.t('survey_results.err_no_restaurant')); return; }
    const from   = document.getElementById('f-from')?.value || '';
    const to     = document.getElementById('f-to')?.value   || '';
    const params = new URLSearchParams({ action: 'export_csv', restaurant_id: restId });
    if (from) params.set('date_from', from);
    if (to)   params.set('date_to',   to);
    window.location.href = `${BASE}/api/survey.php?${params}`;
}

// "Uredi anketo" link sledi izbrani restavraciji v filtru
function updateEditSurveyLink() {
    const sel = document.getElementById('f-restaurant');
    const btn = document.getElementById('btn-edit-survey');
    if (!btn || !sel) return;
    const id = sel.value || '';
    if (!id) return; // pri "Vse" pustimo trenutni link (default first)
    btn.href = `${BASE}/pages/restaurant-edit.php?id=${encodeURIComponent(id)}#anketa`;
}

// Ob nalaganju strani – če je samo ena restavracija, naložimo samodejno
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('f-restaurant');
    if (sel && sel.tagName === 'INPUT' && sel.value) loadResults();
    if (sel && sel.tagName === 'SELECT') {
        sel.addEventListener('change', updateEditSurveyLink);
        updateEditSurveyLink();
    }
});
</script>
</body>
</html>
