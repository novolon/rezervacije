<?php
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';

if (!is_logged_in()) {
    redirect_to_login();
}
if ($_SESSION['role'] !== 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/main.php');
    exit;
}

$fullName = $_SESSION['full_name'];
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/superadmin.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Superadmin</span>
    </div>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<div class="admin-layout">
<div class="admin-content">
    <h1 class="admin-page-title">Superadmin dashboard</h1>

    <!-- Stats -->
    <div id="stats-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:28px">
        <div class="stat-card" id="stat-admins"><div class="stat-val">…</div><div class="stat-lbl">Admini</div></div>
        <div class="stat-card" id="stat-trials"><div class="stat-val">…</div><div class="stat-lbl">Na trialu</div></div>
        <div class="stat-card" id="stat-restaurants"><div class="stat-val">…</div><div class="stat-lbl">Restavracije</div></div>
        <div class="stat-card" id="stat-users"><div class="stat-val">…</div><div class="stat-lbl">Uporabniki</div></div>
        <div class="stat-card" id="stat-today"><div class="stat-val">…</div><div class="stat-lbl">Danes rezervacij</div></div>
    </div>

    <!-- Tabs -->
    <div class="admin-tabs">
        <button class="admin-tab active" data-tab="sa-admins">Admini</button>
        <button class="admin-tab" data-tab="sa-restaurants">Restavracije</button>
        <button class="admin-tab" data-tab="sa-system">Sistem</button>
    </div>

    <!-- Panel: Admini -->
    <div id="panel-sa-admins" class="admin-panel active">
        <div class="admin-card">
            <div class="admin-card-header"><h2>Vsi admin računi</h2></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ime</th>
                            <th>Email</th>
                            <th>Restavracije</th>
                            <th>Subscription</th>
                            <th>Trial do</th>
                            <th>Status</th>
                            <th>Registriran</th>
                        </tr>
                    </thead>
                    <tbody id="sa-admins-tbody">
                        <tr><td colspan="7" class="table-empty">Nalagam…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel: Restavracije -->
    <div id="panel-sa-restaurants" class="admin-panel">
        <div class="admin-card">
            <div class="admin-card-header"><h2>Vse restavracije</h2></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ime</th>
                            <th>Lastnik</th>
                            <th>Email lastnika</th>
                            <th>Rezervacije (skupaj)</th>
                            <th>Status</th>
                            <th>Ustvarjena</th>
                        </tr>
                    </thead>
                    <tbody id="sa-restaurants-tbody">
                        <tr><td colspan="6" class="table-empty">Nalagam…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- Panel: Sistem -->
    <div id="panel-sa-system" class="admin-panel">
        <div class="admin-card" style="max-width:480px">
            <div class="admin-card-header"><h2>Testni email</h2></div>
            <div style="padding:20px 0 4px">
                <div id="test-mail-result" style="display:none;padding:10px 14px;border-radius:8px;font-size:.875rem;margin-bottom:16px"></div>
                <div class="admin-field" style="margin-bottom:14px">
                    <label>Prejemnik</label>
                    <input id="test-mail-to" type="email" placeholder="vas@email.com" style="width:100%;box-sizing:border-box">
                </div>
                <div class="admin-field" style="margin-bottom:20px">
                    <label>Vrsta emaila</label>
                    <select id="test-mail-type" style="width:100%;box-sizing:border-box">
                        <option value="verification">Potrditev emaila (ob registraciji)</option>
                        <option value="reset">Ponastavitev gesla</option>
                    </select>
                </div>
                <button id="test-mail-btn" class="btn btn-primary">Pošlji testni email</button>
            </div>
        </div>
    </div>

</div>
</div>

<div id="toast-container"></div>

<style>
.stat-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: 18px 16px;
    text-align: center;
}
.stat-val {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--color-primary);
    line-height: 1;
    margin-bottom: 6px;
}
.stat-lbl {
    font-size: .75rem;
    color: var(--color-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 600;
}
.badge-trial   { background: #FEF3C7; color: #92400E; }
.badge-active  { background: #D1FAE5; color: #065F46; }
.badge-inactive{ background: #F3F4F6; color: #6B7280; }
</style>

<script>
window.APP_STATE = <?= json_encode([
    'userId' => (int)$_SESSION['user_id'],
    'role'   => 'superadmin',
    'base'   => BASE_PATH,
    'today'  => date('Y-m-d'),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= BASE_PATH ?>/assets/js/api.js"></script>
<script>
(function() {
    'use strict';

    function h(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    function toast(msg, type = 'success') {
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast toast-${type}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity .3s'; setTimeout(()=>t.remove(),300); }, 3200);
    }

    function fmtDate(str) {
        if (!str) return '—';
        return str.slice(0,10);
    }

    // ── Tabs ──────────────────────────────────────────────────────
    document.querySelectorAll('.admin-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.admin-panel').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById('panel-' + tab.dataset.tab)?.classList.add('active');
        });
    });

    // ── Stats ─────────────────────────────────────────────────────
    async function loadStats() {
        try {
            const s = await API.get('/api/superadmin.php?action=stats');
            document.querySelector('#stat-admins .stat-val').textContent      = s.admins;
            document.querySelector('#stat-trials .stat-val').textContent      = s.trials;
            document.querySelector('#stat-restaurants .stat-val').textContent = s.restaurants;
            document.querySelector('#stat-users .stat-val').textContent       = s.users;
            document.querySelector('#stat-today .stat-val').textContent       = s.today_reservations;
        } catch(e) { toast(e.message, 'error'); }
    }

    // ── Admini ────────────────────────────────────────────────────
    async function loadAdmins() {
        try {
            const admins = await API.get('/api/superadmin.php?action=admins');
            const tbody  = document.getElementById('sa-admins-tbody');
            if (!admins.length) {
                tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Ni adminov.</td></tr>`;
                return;
            }
            tbody.innerHTML = admins.map(a => `
                <tr>
                    <td><strong>${h(a.full_name)}</strong></td>
                    <td>${h(a.email)}</td>
                    <td style="text-align:center">${a.restaurant_count}</td>
                    <td><span class="badge badge-${h(a.subscription_status)}">${h(a.subscription_status)}</span></td>
                    <td>${fmtDate(a.trial_ends_at)}</td>
                    <td><span class="badge ${a.is_active==1?'badge-active':'badge-inactive'}">${a.is_active==1?'Aktiven':'Neaktiven'}</span></td>
                    <td>${fmtDate(a.created_at)}</td>
                </tr>
            `).join('');
        } catch(e) { toast(e.message, 'error'); }
    }

    // ── Restavracije ──────────────────────────────────────────────
    async function loadRestaurants() {
        try {
            const rests = await API.get('/api/superadmin.php?action=restaurants');
            const tbody = document.getElementById('sa-restaurants-tbody');
            if (!rests.length) {
                tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Ni restavracij.</td></tr>`;
                return;
            }
            tbody.innerHTML = rests.map(r => `
                <tr>
                    <td><strong>${h(r.name)}</strong></td>
                    <td>${h(r.owner_name)}</td>
                    <td>${h(r.owner_email)}</td>
                    <td style="text-align:center">${r.reservation_count}</td>
                    <td><span class="badge ${r.is_active==1?'badge-active':'badge-inactive'}">${r.is_active==1?'Aktivna':'Neaktivna'}</span></td>
                    <td>${fmtDate(r.created_at)}</td>
                </tr>
            `).join('');
        } catch(e) { toast(e.message, 'error'); }
    }

    // ── Testni email ──────────────────────────────────────────────
    document.getElementById('test-mail-btn')?.addEventListener('click', async () => {
        const email   = document.getElementById('test-mail-to').value.trim();
        const type    = document.getElementById('test-mail-type').value;
        const btn     = document.getElementById('test-mail-btn');
        const result  = document.getElementById('test-mail-result');

        if (!email) { toast('Vnesite email naslov.', 'error'); return; }

        btn.disabled = true;
        btn.textContent = 'Pošiljam…';
        result.style.display = 'none';

        try {
            await API.post('/api/superadmin.php', { email, type });
            result.textContent = 'Email uspešno poslan na ' + email;
            result.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:.875rem;margin-bottom:16px;background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7';
        } catch(e) {
            result.textContent = e.message;
            result.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:.875rem;margin-bottom:16px;background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Pošlji testni email';
        }
    });

    // ── Init ──────────────────────────────────────────────────────
    loadStats();
    loadAdmins();
    loadRestaurants();
})();
</script>
</body>
</html>
