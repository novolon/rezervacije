<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess = require_affiliate();

$pageTitle = t('aff.referrals.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div class="rz-app" id="rz-app">
<?php require_once '_nav.php'; ?>

<main class="rz-main">
    <div class="rz-topbar">
        <div>
            <h1 class="rz-h1"><?= t('aff.referrals.title') ?></h1>
            <p class="rz-top-sub"><?= t('aff.referrals.subtitle') ?></p>
        </div>
        <div class="rz-top-actions">
            <select id="filter-status" class="rz-input" style="width:auto;font-size:13px" onchange="load()">
                <option value=""><?= t('aff.referrals.filter_all') ?></option>
                <option value="signed_up"><?= t('aff.referrals.status_signed_up') ?></option>
                <option value="converted"><?= t('aff.referrals.status_converted') ?></option>
                <option value="churned"><?= t('aff.referrals.status_churned') ?></option>
                <option value="rejected"><?= t('aff.referrals.status_rejected') ?></option>
            </select>
        </div>
    </div>

    <div class="rz-card">
        <div style="overflow-x:auto">
            <table class="rz-table" id="referrals-table">
                <thead>
                    <tr>
                        <th><?= t('aff.referrals.col_registered_ym') ?></th>
                        <th><?= t('aff.referrals.col_status') ?></th>
                        <th><?= t('aff.referrals.col_attribution') ?></th>
                        <th><?= t('aff.referrals.col_paid_invoices') ?></th>
                        <th><?= t('aff.referrals.col_earned') ?></th>
                    </tr>
                </thead>
                <tbody id="referrals-body">
                    <tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px"><?= t('aff.referrals.loading') ?></td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" style="display:flex;gap:6px;justify-content:flex-end;margin-top:14px;align-items:center"></div>
    </div>

    <p style="font-size:11px;color:var(--ink-mute);margin-top:8px">
        <?= t('aff.referrals.privacy_note') ?>
    </p>
</main>
</div>

<script>
const BASE = <?= json_encode(BASE_PATH) ?>;
const T = {
    status_signed_up: <?= json_encode(t('aff.referrals.status_signed_up'), JSON_UNESCAPED_UNICODE) ?>,
    status_converted: <?= json_encode(t('aff.referrals.status_converted'), JSON_UNESCAPED_UNICODE) ?>,
    status_churned:   <?= json_encode(t('aff.referrals.status_churned'),   JSON_UNESCAPED_UNICODE) ?>,
    status_rejected:  <?= json_encode(t('aff.referrals.status_rejected'),  JSON_UNESCAPED_UNICODE) ?>,
    attr_cookie:      <?= json_encode(t('aff.referrals.attr_cookie'),      JSON_UNESCAPED_UNICODE) ?>,
    attr_code:        <?= json_encode(t('aff.referrals.attr_code'),        JSON_UNESCAPED_UNICODE) ?>,
    attr_url:         <?= json_encode(t('aff.referrals.attr_url'),         JSON_UNESCAPED_UNICODE) ?>,
    empty:            <?= json_encode(t('aff.referrals.empty'),            JSON_UNESCAPED_UNICODE) ?>,
    loading:          <?= json_encode(t('aff.referrals.loading'),          JSON_UNESCAPED_UNICODE) ?>,
    error:            <?= json_encode(t('aff.referrals.error'),            JSON_UNESCAPED_UNICODE) ?>,
    error_load:       <?= json_encode(t('aff.referrals.error_load'),       JSON_UNESCAPED_UNICODE) ?>,
    page_label:       <?= json_encode(t('aff.referrals.page_label'),       JSON_UNESCAPED_UNICODE) ?>,
};

let currentPage = 1;

const STATUS = {
    signed_up: '<span class="rz-chip" style="background:color-mix(in oklab,var(--info) 12%,transparent);color:var(--info);border-color:transparent">' + T.status_signed_up + '</span>',
    converted: '<span class="rz-chip rz-chip-confirmed">' + T.status_converted + '</span>',
    churned:   '<span class="rz-chip rz-chip-mute">' + T.status_churned + '</span>',
    rejected:  '<span class="rz-chip" style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border-color:transparent">' + T.status_rejected + '</span>',
};
const ATTR = { cookie: T.attr_cookie, code: T.attr_code, url: T.attr_url };

async function load(page) {
    if (page) currentPage = page;
    const status = document.getElementById('filter-status').value;
    const params = new URLSearchParams({ action: 'referrals', page: currentPage, per_page: 20 });
    if (status) params.set('status', status);

    const tbody = document.getElementById('referrals-body');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">' + T.loading + '</td></tr>';

    try {
        const r = await fetch(BASE + '/api/affiliate.php?' + params);
        const d = await r.json();
        if (!d.success) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute)">' + T.error_load + '</td></tr>'; return; }

        if (!d.data.items.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute);padding:32px">' + T.empty + '</td></tr>';
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
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ink-mute)">' + T.error + '</td></tr>';
    }
}

function renderPagination(total, perPage) {
    const pages = Math.ceil(total / perPage) || 1;
    const el = document.getElementById('pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = '<span style="font-size:11px;color:var(--ink-mute)">' + T.page_label + '</span> ';
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
