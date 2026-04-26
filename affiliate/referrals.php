<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess = require_affiliate();

$pageTitle = 'Priporočeni – Affiliate';
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div class="rz-app" id="rz-app">
<?php require_once '_nav.php'; ?>

<main class="rz-main">
    <div class="rz-topbar">
        <div>
            <h1 class="rz-h1">Priporočeni</h1>
            <p class="rz-top-sub">Seznam vaših priporočenih (agregatni podatki, brez osebnih podatkov)</p>
        </div>
        <div class="rz-top-actions">
            <select id="filter-status" class="rz-input" style="width:auto;font-size:13px" onchange="load()">
                <option value="">Vsi statusi</option>
                <option value="signed_up">Registriran</option>
                <option value="converted">Plačnik</option>
                <option value="churned">Odšel</option>
                <option value="rejected">Zavrnjen</option>
            </select>
        </div>
    </div>

    <div class="rz-card">
        <div style="overflow-x:auto">
            <table class="rz-table" id="referrals-table">
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
                    <tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">Nalaganje…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" style="display:flex;gap:6px;justify-content:flex-end;margin-top:14px;align-items:center"></div>
    </div>

    <p style="font-size:11px;color:var(--ink-mute);margin-top:8px">
        Iz varstvenih razlogov so prikazani le mesec registracije, status in agregatni podatki. Osebni podatki priporočenih niso vidni.
    </p>
</main>
</div>

<script>
const BASE = <?= json_encode(BASE_PATH) ?>;
let currentPage = 1;

const STATUS = {
    signed_up: '<span class="rz-chip" style="background:color-mix(in oklab,var(--info) 12%,transparent);color:var(--info);border-color:transparent">Registriran</span>',
    converted: '<span class="rz-chip rz-chip-confirmed">Plačnik</span>',
    churned:   '<span class="rz-chip rz-chip-mute">Odšel</span>',
    rejected:  '<span class="rz-chip" style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border-color:transparent">Zavrnjen</span>',
};
const ATTR = { cookie: 'Cookie', code: 'Koda', url: 'URL' };

async function load(page) {
    if (page) currentPage = page;
    const status = document.getElementById('filter-status').value;
    const params = new URLSearchParams({ action: 'referrals', page: currentPage, per_page: 20 });
    if (status) params.set('status', status);

    const tbody = document.getElementById('referrals-body');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">Nalaganje…</td></tr>';

    try {
        const r = await fetch(BASE + '/api/affiliate.php?' + params);
        const d = await r.json();
        if (!d.success) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute)">Napaka pri nalaganju.</td></tr>'; return; }

        if (!d.data.items.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">Ni priporočenih.</td></tr>';
            renderPagination(0, 0);
            return;
        }

        tbody.innerHTML = d.data.items.map(row => `
            <tr>
                <td style="font-family:var(--font-mono);font-size:12px">${row.registered_ym}</td>
                <td>${STATUS[row.status] ?? row.status}</td>
                <td style="color:var(--ink-mute);font-size:12px">${ATTR[row.attribution] ?? row.attribution}</td>
                <td style="font-weight:600">${row.paid_invoices}</td>
                <td style="font-weight:700;color:var(--success)">${(+row.earned_eur).toFixed(2)} €</td>
            </tr>
        `).join('');

        renderPagination(d.data.total, d.data.per_page);
    } catch(e) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute)">Napaka.</td></tr>';
    }
}

function renderPagination(total, perPage) {
    const pages = Math.ceil(total / perPage) || 1;
    const el = document.getElementById('pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '<span style="font-size:11px;color:var(--ink-mute)">Stran:</span> ';
    for (let i = 1; i <= pages; i++) {
        const active = i === currentPage ? ';background:var(--secondary);color:#fff;border-color:var(--secondary)' : '';
        html += `<button class="rz-btn" style="min-width:32px;justify-content:center;padding:6px 10px;font-size:12px${active}" onclick="load(${i})">${i}</button>`;
    }
    el.innerHTML = html;
}

load();
</script>
</body>
</html>
