<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess = require_affiliate();

$pageTitle = 'Zaslužki – Affiliate';
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div class="rz-app" id="rz-app">
<?php require_once '_nav.php'; ?>

<main class="rz-main">
    <div class="rz-topbar">
        <div>
            <h1 class="rz-h1">Zaslužki</h1>
            <p class="rz-top-sub">Pregled provizij in izplačil</p>
        </div>
    </div>

    <!-- Povzetek -->
    <div class="rz-kpis" id="summary-grid">
        <div class="rz-kpi is-accent"><div class="rz-kpi-label">Skupaj zasluženo</div><div class="rz-kpi-value" id="s-total">–</div></div>
        <div class="rz-kpi"><div class="rz-kpi-label">V čakanju (45 dni)</div><div class="rz-kpi-value" id="s-pending">–</div></div>
        <div class="rz-kpi is-accent"><div class="rz-kpi-label">Izplačljivo</div><div class="rz-kpi-value" id="s-payable">–</div></div>
        <div class="rz-kpi"><div class="rz-kpi-label">Izplačano</div><div class="rz-kpi-value" id="s-paid">–</div></div>
    </div>

    <!-- Provizije -->
    <div class="rz-card" style="margin-bottom:16px">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px">Provizije</h2>
            <select id="filter-status" class="rz-input" style="width:auto;font-size:13px" onchange="loadCommissions()">
                <option value="">Vsi statusi</option>
                <option value="pending">V čakanju</option>
                <option value="payable">Izplačljivo</option>
                <option value="paid">Izplačano</option>
                <option value="void">Razveljavljeno</option>
                <option value="clawback">Clawback</option>
            </select>
        </div>
        <div style="overflow-x:auto">
            <table class="rz-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Znesek naročnine (€)</th>
                        <th>Provizija (€)</th>
                        <th>Status</th>
                        <th>Na voljo od</th>
                    </tr>
                </thead>
                <tbody id="commissions-body">
                    <tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">Nalaganje…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="comm-pagination" style="display:flex;gap:6px;justify-content:flex-end;margin-top:14px;align-items:center"></div>
    </div>

    <!-- Izplačila -->
    <div class="rz-card">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px">Izplačila</h2>
        </div>
        <div style="overflow-x:auto">
            <table class="rz-table">
                <thead>
                    <tr>
                        <th>Datum zahteve</th>
                        <th>Znesek (€)</th>
                        <th>Status</th>
                        <th>Referenca</th>
                    </tr>
                </thead>
                <tbody id="payouts-body">
                    <tr><td colspan="4" style="text-align:center;color:var(--ink-mute);padding:32px">Nalaganje…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</main>
</div>

<script>
const BASE = <?= json_encode(BASE_PATH) ?>;
let commPage = 1;

const COMM_STATUS = {
    pending:  '<span class="rz-chip" style="background:color-mix(in oklab,var(--warning) 15%,transparent);color:var(--warning);border-color:transparent">V čakanju</span>',
    payable:  '<span class="rz-chip rz-chip-confirmed">Izplačljivo</span>',
    paid:     '<span class="rz-chip rz-chip-mute">Izplačano</span>',
    void:     '<span class="rz-chip rz-chip-mute">Razveljavljeno</span>',
    clawback: '<span class="rz-chip" style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border-color:transparent">Clawback</span>',
};
const PAYOUT_STATUS = {
    pending:    '<span class="rz-chip" style="background:color-mix(in oklab,var(--warning) 15%,transparent);color:var(--warning);border-color:transparent">Čaka</span>',
    processing: '<span class="rz-chip" style="background:color-mix(in oklab,var(--info) 12%,transparent);color:var(--info);border-color:transparent">V obdelavi</span>',
    paid:       '<span class="rz-chip rz-chip-confirmed">Izplačano</span>',
    failed:     '<span class="rz-chip" style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border-color:transparent">Napaka</span>',
};

async function loadSummary() {
    try {
        const r = await fetch(BASE + '/api/affiliate.php?action=earnings&summary=1');
        const d = await r.json();
        if (!d.success) return;
        document.getElementById('s-total').textContent   = d.data.total_earned.toFixed(2) + ' €';
        document.getElementById('s-pending').textContent = d.data.pending.toFixed(2) + ' €';
        document.getElementById('s-payable').textContent = d.data.payable.toFixed(2) + ' €';
        document.getElementById('s-paid').textContent    = d.data.paid.toFixed(2) + ' €';
    } catch(e) {}
}

async function loadCommissions(page) {
    if (page) commPage = page;
    const status = document.getElementById('filter-status').value;
    const params = new URLSearchParams({ action: 'earnings', page: commPage, per_page: 20 });
    if (status) params.set('status', status);

    const tbody = document.getElementById('commissions-body');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">Nalaganje…</td></tr>';

    try {
        const r = await fetch(BASE + '/api/affiliate.php?' + params);
        const d = await r.json();
        if (!d.success) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute)">Napaka.</td></tr>'; return; }

        if (!d.data.items.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">Ni provizij.</td></tr>';
            renderPagination('comm-pagination', 0, 0, loadCommissions);
            return;
        }

        tbody.innerHTML = d.data.items.map(row => `
            <tr>
                <td style="font-family:var(--font-mono);font-size:12px">${row.date}</td>
                <td>${(+row.invoice_amount_eur).toFixed(2)}</td>
                <td style="font-weight:700;color:var(--success)">${(+row.commission_eur).toFixed(2)}</td>
                <td>${COMM_STATUS[row.status] ?? row.status}</td>
                <td style="font-size:12px;color:var(--ink-mute)">${row.available_at ?? '–'}</td>
            </tr>
        `).join('');

        renderPagination('comm-pagination', d.data.total, d.data.per_page, loadCommissions);
    } catch(e) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute)">Napaka.</td></tr>'; }
}

async function loadPayouts() {
    const tbody = document.getElementById('payouts-body');
    try {
        const r = await fetch(BASE + '/api/affiliate.php?action=payouts');
        const d = await r.json();
        if (!d.success || !d.data.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--ink-mute);padding:32px">Ni izplačil.</td></tr>';
            return;
        }
        tbody.innerHTML = d.data.map(row => `
            <tr>
                <td style="font-family:var(--font-mono);font-size:12px">${row.requested_at?.substring(0,10) ?? '–'}</td>
                <td style="font-weight:700">${(+row.amount_eur).toFixed(2)} €</td>
                <td>${PAYOUT_STATUS[row.status] ?? row.status}</td>
                <td style="font-size:12px;color:var(--ink-mute)">${row.batch_reference ?? '–'}</td>
            </tr>
        `).join('');
    } catch(e) { tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--ink-mute)">Napaka.</td></tr>'; }
}

function renderPagination(elId, total, perPage, fn) {
    const pages = Math.ceil(total / perPage) || 1;
    const el = document.getElementById(elId);
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '<span style="font-size:11px;color:var(--ink-mute)">Stran:</span> ';
    for (let i = 1; i <= pages; i++) {
        html += `<button class="rz-btn" style="min-width:32px;justify-content:center;padding:6px 10px;font-size:12px" onclick="(${fn.name})(${i})">${i}</button>`;
    }
    el.innerHTML = html;
}

loadSummary();
loadCommissions();
loadPayouts();
</script>
</body>
</html>
