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
<title>Zaslužki – Affiliate</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-layout">
<?php require_once '_nav.php'; ?>

<main class="aff-main">
    <div class="aff-topbar">
        <div class="aff-page-title">Zaslužki</div>
    </div>

    <!-- Summary cards -->
    <div class="aff-stats-grid" id="summary-grid" style="margin-bottom:20px">
        <div class="aff-stat-card"><div class="aff-stat-label">Skupaj zasluženo</div><div class="aff-stat-val green" id="s-total">–</div></div>
        <div class="aff-stat-card"><div class="aff-stat-label">V čakanju (45 dni)</div><div class="aff-stat-val" id="s-pending">–</div></div>
        <div class="aff-stat-card"><div class="aff-stat-label">Izplačljivo</div><div class="aff-stat-val green" id="s-payable">–</div></div>
        <div class="aff-stat-card"><div class="aff-stat-label">Izplačano</div><div class="aff-stat-val" id="s-paid">–</div></div>
    </div>

    <!-- Commissions table -->
    <div class="aff-card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
            <div class="aff-card-title" style="margin:0">Provizije</div>
            <select id="filter-status" class="aff-select" onchange="loadCommissions()">
                <option value="">Vsi statusi</option>
                <option value="pending">V čakanju</option>
                <option value="payable">Izplačljivo</option>
                <option value="paid">Izplačano</option>
                <option value="voided">Razveljavljeno</option>
                <option value="clawback">Clawback</option>
            </select>
        </div>

        <div class="aff-table-wrap">
            <table class="aff-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Znesek (€)</th>
                        <th>Provizija (€)</th>
                        <th>Status</th>
                        <th>Na voljo od</th>
                    </tr>
                </thead>
                <tbody id="commissions-body">
                    <tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute);padding:24px">Nalaganje…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="comm-pagination" style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;align-items:center"></div>
    </div>

    <!-- Payouts table -->
    <div class="aff-card" style="margin-top:20px">
        <div class="aff-card-title">Izplačila</div>
        <div class="aff-table-wrap">
            <table class="aff-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Znesek (€)</th>
                        <th>Status</th>
                        <th>Referenca</th>
                    </tr>
                </thead>
                <tbody id="payouts-body">
                    <tr><td colspan="4" style="text-align:center;color:var(--aff-ink-mute);padding:24px">Nalaganje…</td></tr>
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
    pending:  '<span class="aff-badge aff-badge--warning">V čakanju</span>',
    payable:  '<span class="aff-badge aff-badge--success">Izplačljivo</span>',
    paid:     '<span class="aff-badge aff-badge--muted">Izplačano</span>',
    voided:   '<span class="aff-badge aff-badge--muted">Razveljavljeno</span>',
    clawback: '<span class="aff-badge aff-badge--danger">Clawback</span>',
};
const PAYOUT_STATUS = {
    pending:   '<span class="aff-badge aff-badge--warning">Čaka</span>',
    processing:'<span class="aff-badge aff-badge--info">V obdelavi</span>',
    paid:      '<span class="aff-badge aff-badge--success">Izplačano</span>',
    failed:    '<span class="aff-badge aff-badge--danger">Napaka</span>',
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
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px">Nalaganje…</td></tr>';

    try {
        const r = await fetch(BASE + '/api/affiliate.php?' + params);
        const d = await r.json();
        if (!d.success) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute)">Napaka.</td></tr>'; return; }

        if (!d.data.items.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute);padding:24px">Ni provizij.</td></tr>';
            renderPagination('comm-pagination', 0, 0, loadCommissions);
            return;
        }

        tbody.innerHTML = d.data.items.map(row => `
            <tr>
                <td>${row.date}</td>
                <td>${(+row.invoice_amount_eur).toFixed(2)}</td>
                <td>${(+row.commission_eur).toFixed(2)}</td>
                <td>${COMM_STATUS[row.status] ?? row.status}</td>
                <td>${row.available_at ?? '–'}</td>
            </tr>
        `).join('');

        renderPagination('comm-pagination', d.data.total, d.data.per_page, loadCommissions);
    } catch(e) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--aff-ink-mute)">Napaka.</td></tr>'; }
}

async function loadPayouts() {
    const tbody = document.getElementById('payouts-body');
    try {
        const r = await fetch(BASE + '/api/affiliate.php?action=payouts');
        const d = await r.json();
        if (!d.success || !d.data.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--aff-ink-mute);padding:24px">Ni izplačil.</td></tr>';
            return;
        }
        tbody.innerHTML = d.data.map(row => `
            <tr>
                <td>${row.requested_at?.substring(0,10) ?? '–'}</td>
                <td>${(+row.amount_eur).toFixed(2)}</td>
                <td>${PAYOUT_STATUS[row.status] ?? row.status}</td>
                <td>${row.batch_reference ?? '–'}</td>
            </tr>
        `).join('');
    } catch(e) { tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--aff-ink-mute)">Napaka.</td></tr>'; }
}

function renderPagination(elId, total, perPage, fn) {
    const pages = Math.ceil(total / perPage) || 1;
    const el = document.getElementById(elId);
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '<span style="font-size:.82rem;color:var(--aff-ink-mute)">Stran:</span> ';
    for (let i = 1; i <= pages; i++) {
        html += `<button class="aff-btn-sm" onclick="(${fn.name})(${i})">${i}</button>`;
    }
    el.innerHTML = html;
}

loadSummary();
loadCommissions();
loadPayouts();
</script>
</body>
</html>
