<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess = require_affiliate();
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Priporočeni – Affiliate</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-layout">
<?php require_once '_nav.php'; ?>

<main class="aff-main">
    <div class="aff-topbar">
        <div class="aff-page-title">Priporočeni</div>
    </div>

    <div class="aff-card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
            <div class="aff-card-title" style="margin:0">Seznam priporočenih</div>
            <div style="display:flex;gap:8px;align-items:center">
                <select id="filter-status" class="aff-select" onchange="load()">
                    <option value="">Vsi statusi</option>
                    <option value="registered">Registriran</option>
                    <option value="trial">Trial</option>
                    <option value="converted">Plačnik</option>
                    <option value="churned">Odšel</option>
                </select>
            </div>
        </div>

        <div class="aff-table-wrap">
            <table class="aff-table" id="referrals-table">
                <thead>
                    <tr>
                        <th>Mesec registracije</th>
                        <th>Status</th>
                        <th>Atribucija</th>
                        <th>Plačila</th>
                        <th>Zasluženo</th>
                    </tr>
                </thead>
                <tbody id="referrals-body">
                    <tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute);padding:24px">Nalaganje…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;align-items:center"></div>
    </div>

    <p style="font-size:.8rem;color:var(--aff-ink-mute);margin-top:8px">
        Iz varstvenih razlogov so prikazani le mesec registracije, status in agregatni podatki. Osebni podatki priporočenih niso vidni.
    </p>
</main>
</div>

<script>
const BASE = <?= json_encode(BASE_PATH) ?>;
let currentPage = 1;

const STATUS_LABELS = {
    registered: '<span class="aff-badge aff-badge--info">Registriran</span>',
    trial:      '<span class="aff-badge aff-badge--warning">Trial</span>',
    converted:  '<span class="aff-badge aff-badge--success">Plačnik</span>',
    churned:    '<span class="aff-badge aff-badge--muted">Odšel</span>',
};
const ATTR_LABELS = {
    cookie: 'Cookie',
    code:   'Koda',
};

async function load(page) {
    if (page) currentPage = page;
    const status = document.getElementById('filter-status').value;
    const params = new URLSearchParams({ action: 'referrals', page: currentPage, per_page: 20 });
    if (status) params.set('status', status);

    const tbody = document.getElementById('referrals-body');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute);padding:24px">Nalaganje…</td></tr>';

    try {
        const r = await fetch(BASE + '/api/affiliate.php?' + params);
        const d = await r.json();
        if (!d.success) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute)">Napaka pri nalaganju.</td></tr>'; return; }

        if (!d.data.items.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute);padding:24px">Ni priporočenih.</td></tr>';
            renderPagination(0, 0);
            return;
        }

        tbody.innerHTML = d.data.items.map(row => `
            <tr>
                <td>${row.registered_ym}</td>
                <td>${STATUS_LABELS[row.status] ?? row.status}</td>
                <td>${ATTR_LABELS[row.attribution] ?? row.attribution}</td>
                <td>${row.paid_invoices}</td>
                <td>${row.earned_eur.toFixed(2)} €</td>
            </tr>
        `).join('');

        renderPagination(d.data.total, d.data.per_page);
    } catch(e) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute)">Napaka.</td></tr>';
    }
}

function renderPagination(total, perPage) {
    const pages = Math.ceil(total / perPage) || 1;
    const el = document.getElementById('pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= pages; i++) {
        html += `<button class="aff-btn-sm${i === currentPage ? ' active' : ''}" onclick="load(${i})">${i}</button>`;
    }
    el.innerHTML = '<span style="font-size:.82rem;color:var(--aff-ink-mute)">Stran:</span> ' + html;
}

load();
</script>
</body>
</html>
