<?php
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';

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
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('superadmin.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css">
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
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
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout"><?= t('superadmin.logout') ?></a>
    </div>
</header>

<div class="admin-layout">
<div class="admin-content">
    <h1 class="admin-page-title"><?= t('superadmin.heading') ?></h1>

    <!-- Stats -->
    <div id="stats-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:28px">
        <div class="stat-card" id="stat-admins"><div class="stat-val">…</div><div class="stat-lbl"><?= t('superadmin.stat_admins') ?></div></div>
        <div class="stat-card" id="stat-trials"><div class="stat-val">…</div><div class="stat-lbl"><?= t('superadmin.stat_trials') ?></div></div>
        <div class="stat-card" id="stat-restaurants"><div class="stat-val">…</div><div class="stat-lbl"><?= t('superadmin.stat_restaurants') ?></div></div>
        <div class="stat-card" id="stat-users"><div class="stat-val">…</div><div class="stat-lbl"><?= t('superadmin.stat_users') ?></div></div>
        <div class="stat-card" id="stat-today"><div class="stat-val">…</div><div class="stat-lbl"><?= t('superadmin.stat_today') ?></div></div>
    </div>

    <!-- Tabs -->
    <div class="admin-tabs">
        <button class="admin-tab active" data-tab="sa-admins"><?= t('superadmin.tab_admins') ?></button>
        <button class="admin-tab" data-tab="sa-restaurants"><?= t('superadmin.tab_restaurants') ?></button>
        <button class="admin-tab" data-tab="sa-discounts"><?= t('superadmin.tab_discounts') ?></button>
        <button class="admin-tab" data-tab="sa-affiliate">Affiliate</button>
        <button class="admin-tab" data-tab="sa-disc-codes">Kode za popust</button>
        <button class="admin-tab" data-tab="sa-system"><?= t('superadmin.tab_system') ?></button>
        <a href="<?= BASE_PATH ?>/pages/gdpr.php" class="admin-tab" style="text-decoration:none"><?= t('superadmin.tab_gdpr') ?></a>
        <a href="<?= BASE_PATH ?>/pages/superadmin_blog.php" class="admin-tab" style="text-decoration:none">Booked</a>
    </div>

    <!-- Panel: Admini -->
    <div id="panel-sa-admins" class="admin-panel active">
        <div class="admin-card">
            <div class="admin-card-header"><h2><?= t('superadmin.admins_title') ?></h2></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><?= t('superadmin.col_name') ?></th>
                            <th><?= t('superadmin.col_email') ?></th>
                            <th><?= t('superadmin.col_restaurants') ?></th>
                            <th><?= t('superadmin.col_plan') ?></th>
                            <th><?= t('superadmin.col_trial_end') ?></th>
                            <th><?= t('superadmin.col_status') ?></th>
                            <th><?= t('superadmin.col_registered') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="sa-admins-tbody">
                        <tr><td colspan="8" class="table-empty"><?= t('superadmin.loading') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel: Restavracije -->
    <div id="panel-sa-restaurants" class="admin-panel">
        <div class="admin-card">
            <div class="admin-card-header"><h2><?= t('superadmin.restaurants_title') ?></h2></div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><?= t('superadmin.col_name') ?></th>
                            <th><?= t('superadmin.col_owner') ?></th>
                            <th><?= t('superadmin.col_owner_email') ?></th>
                            <th><?= t('superadmin.col_reservations') ?></th>
                            <th><?= t('superadmin.col_status') ?></th>
                            <th><?= t('superadmin.col_registered') ?></th>
                        </tr>
                    </thead>
                    <tbody id="sa-restaurants-tbody">
                        <tr><td colspan="6" class="table-empty"><?= t('superadmin.loading') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- Panel: Popusti -->
    <div id="panel-sa-discounts" class="admin-panel">
        <div class="admin-card">
            <div class="admin-card-header" style="display:flex;align-items:center;justify-content:space-between">
                <h2><?= t('superadmin.discounts_title') ?></h2>
                <button class="btn btn-primary btn-sm" onclick="openCreateDiscount()"><?= t('superadmin.new_discount_btn') ?></button>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><?= t('superadmin.col_plan') ?></th>
                            <th><?= t('superadmin.col_promo') ?></th>
                            <th><?= t('superadmin.col_monthly') ?></th>
                            <th><?= t('superadmin.col_yearly') ?></th>
                            <th><?= t('superadmin.col_valid_from') ?></th>
                            <th><?= t('superadmin.col_valid_until') ?></th>
                            <th><?= t('superadmin.col_status') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="sa-discounts-tbody">
                        <tr><td colspan="8" class="table-empty"><?= t('superadmin.loading') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel: Affiliate -->
    <div id="panel-sa-affiliate" class="admin-panel">
        <div class="admin-card">
            <div class="admin-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
                <h2>Affiliate partnerji</h2>
                <div style="display:flex;gap:8px;align-items:center">
                    <select id="aff-filter-status" class="admin-select" onchange="loadAffiliates()" style="font-size:.85rem;padding:6px 10px">
                        <option value="">Vsi statusi</option>
                        <option value="pending">Čakajo</option>
                        <option value="active">Aktivni</option>
                        <option value="suspended">Suspendirani</option>
                        <option value="rejected">Zavrnjeni</option>
                    </select>
                    <button class="btn btn-sm btn-ghost" onclick="downloadPayoutCsv()">⬇ SEPA CSV</button>
                    <button class="btn btn-sm btn-primary" onclick="openPayoutBatch()">Izplačilo</button>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ime</th>
                            <th>Email</th>
                            <th>Ref. koda</th>
                            <th>Status</th>
                            <th>Prov. %</th>
                            <th>Izplačljivo</th>
                            <th>Registracija</th>
                            <th>Dejanja</th>
                        </tr>
                    </thead>
                    <tbody id="sa-affiliate-tbody">
                        <tr><td colspan="8" class="table-empty">Nalaganje…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Affiliate global nastavitve -->
        <div class="admin-card" style="margin-top:16px">
            <div class="admin-card-header"><h2>Globalne nastavitve affiliate</h2></div>
            <div style="padding:4px 0 8px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px" id="aff-settings-grid">
                <div class="admin-field">
                    <label>Privzeta provizija (%)</label>
                    <input type="number" id="aff-set-pct" min="0" max="100" step="0.1" style="width:100%;box-sizing:border-box">
                </div>
                <div class="admin-field">
                    <label>Hold dni (zadržanje)</label>
                    <input type="number" id="aff-set-hold" min="0" max="180" step="1" style="width:100%;box-sizing:border-box">
                </div>
                <div class="admin-field">
                    <label>Okno provizij (meseci)</label>
                    <input type="number" id="aff-set-window" min="1" max="60" step="1" style="width:100%;box-sizing:border-box">
                </div>
                <div class="admin-field">
                    <label>Min. izplačilo (€)</label>
                    <input type="number" id="aff-set-min" min="0" step="0.01" style="width:100%;box-sizing:border-box">
                </div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="saveAffSettings()">Shrani nastavitve</button>
        </div>
    </div>

    <!-- Panel: Kode za popust -->
    <div id="panel-sa-disc-codes" class="admin-panel">
        <div class="admin-card">
            <div class="admin-card-header" style="display:flex;align-items:center;justify-content:space-between">
                <h2>Kode za popust</h2>
                <button class="btn btn-primary btn-sm" onclick="openCreateDiscCode()">+ Nova koda</button>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Koda</th>
                            <th>Popust</th>
                            <th>Trajanje</th>
                            <th>Lastnik</th>
                            <th>Unovčenj</th>
                            <th>Status</th>
                            <th>Ustvarjena</th>
                            <th>Dejanja</th>
                        </tr>
                    </thead>
                    <tbody id="sa-disc-codes-tbody">
                        <tr><td colspan="8" class="table-empty">Nalaganje…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel: Sistem -->
    <div id="panel-sa-system" class="admin-panel">
        <div class="admin-card" style="max-width:480px">
            <div class="admin-card-header"><h2><?= t('superadmin.system_title') ?></h2></div>
            <div style="padding:20px 0 4px">
                <div id="test-mail-result" style="display:none;padding:10px 14px;border-radius:8px;font-size:.875rem;margin-bottom:16px"></div>
                <div class="admin-field" style="margin-bottom:14px">
                    <label><?= t('superadmin.email_recipient') ?></label>
                    <input id="test-mail-to" type="email" placeholder="vas@email.com" style="width:100%;box-sizing:border-box">
                </div>
                <div class="admin-field" style="margin-bottom:14px">
                    <label>Jezik emaila</label>
                    <select id="test-mail-lang" style="width:100%;box-sizing:border-box">
                        <option value="sl">Slovenščina</option>
                        <option value="en">English</option>
                        <option value="de">Deutsch</option>
                        <option value="it">Italiano</option>
                        <option value="fr">Français</option>
                        <option value="hr">Hrvatski</option>
                        <option value="es">Español</option>
                        <option value="pt">Português</option>
                    </select>
                </div>
                <div class="admin-field" style="margin-bottom:20px">
                    <label><?= t('superadmin.email_type_label') ?></label>
                    <select id="test-mail-type" style="width:100%;box-sizing:border-box">
                        <optgroup label="Račun & avtentikacija">
                            <option value="verification">Potrditev emaila ob registraciji</option>
                            <option value="reset">Ponastavitev gesla</option>
                            <option value="email_change">Sprememba email naslova</option>
                        </optgroup>
                        <optgroup label="Naročnina (Stripe)">
                            <option value="payment_failed">Plačilo ni uspelo</option>
                            <option value="upcoming_invoice">Opomnik pred zaračunanjem</option>
                            <option value="plan_changed">Sprememba paketa potrjena</option>
                            <option value="invoice_request">Zahtevek za predračun</option>
                        </optgroup>
                        <optgroup label="Rezervacije (gost)">
                            <option value="booking_pending_guest">Rezervacijska prošnja (gost)</option>
                            <option value="booking_confirmed_guest">Potrjena rezervacija (gost)</option>
                            <option value="booking_rejected_guest">Rezervacija ni mogoča (gost)</option>
                            <option value="booking_reminder_guest">Opomnik 24h pred (gost)</option>
                        </optgroup>
                        <optgroup label="Rezervacije (admin)">
                            <option value="booking_notify_admin">Obvestilo o rezervaciji (admin)</option>
                        </optgroup>
                        <optgroup label="Affiliate program">
                            <option value="affiliate_verify">Potrditev affiliate prijave</option>
                            <option value="affiliate_approved">Affiliate odobren</option>
                            <option value="affiliate_rejected">Affiliate zavrnjen</option>
                            <option value="affiliate_payout">Affiliate izplačilo</option>
                            <option value="affiliate_discount_granted">Affiliate popustna koda aktivna</option>
                        </optgroup>
                        <optgroup label="Drugo">
                            <option value="gdpr">GDPR potrditev zahtevka</option>
                            <option value="blog_subscribe">Blog newsletter potrditev</option>
                        </optgroup>
                        <optgroup label="Vse v enem batch-u">
                            <option value="all">📧 Pošlji VSE emaile naenkrat</option>
                        </optgroup>
                    </select>
                </div>
                <button id="test-mail-btn" class="btn btn-primary"><?= t('superadmin.send_test_btn') ?></button>
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
.badge-trial           { background: #FEF3C7; color: #92400E; }
.badge-active          { background: #D1FAE5; color: #065F46; }
.badge-inactive        { background: #F3F4F6; color: #6B7280; }
.badge-pending_invoice { background: #EDE9FE; color: #5B21B6; }
.badge-payment_failed  { background: #FEE2E2; color: #991B1B; }
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

    const PLAN_LABELS   = { trial:'Trial', basic:'Basic', advanced:'Advanced', premium:'Premium' };
    const STATUS_LABELS = {
        trial:           window.t('superadmin.status_trial'),
        active:          window.t('superadmin.status_active'),
        pending_invoice: window.t('superadmin.status_pending_invoice'),
        payment_failed:  window.t('superadmin.status_payment_failed'),
        canceled:        window.t('superadmin.status_canceled'),
        expired:         window.t('superadmin.status_expired'),
    };

    // ── Admini ────────────────────────────────────────────────────
    async function loadAdmins() {
        try {
            const admins = await API.get('/api/superadmin.php?action=admins');
            const tbody  = document.getElementById('sa-admins-tbody');
            if (!admins.length) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty">${window.t('superadmin.no_admins')}</td></tr>`;
                return;
            }
            tbody.innerHTML = admins.map(a => `
                <tr>
                    <td><strong>${h(a.full_name)}</strong></td>
                    <td>${h(a.email)}</td>
                    <td style="text-align:center">${a.restaurant_count}</td>
                    <td><span class="badge badge-${h(a.subscription_status)}">${STATUS_LABELS[a.subscription_status] ?? h(a.subscription_status)} – ${PLAN_LABELS[a.plan_slug] ?? h(a.plan_slug)}</span></td>
                    <td>${fmtDate(a.trial_ends_at)}</td>
                    <td><span class="badge ${a.is_active==1?'badge-active':'badge-inactive'}">${a.is_active==1?window.t('superadmin.admin_active'):window.t('superadmin.admin_inactive')}</span></td>
                    <td>${fmtDate(a.created_at)}</td>
                    <td>
                        <button class="btn btn-sm btn-ghost" style="font-size:.75rem;padding:4px 10px"
                                onclick="openAssignPlan(${a.id}, '${h(a.full_name)}')">
                            ${window.t('superadmin.assign_plan_btn')}
                        </button>
                    </td>
                </tr>
            `).join('');
        } catch(e) { toast(e.message, 'error'); }
    }

    // ── Dodeli paket modal ────────────────────────────────────────
    async function openAssignPlan(userId, userName) {
        // Najprej pridobi trenutno naročnino
        let currentSub = null;
        try {
            currentSub = await API.get(`/api/superadmin.php?action=subscription&user_id=${userId}`);
        } catch(e) {}

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'assign-plan-modal';
        overlay.innerHTML = `
        <div class="modal-box" style="max-width:400px">
            <div class="modal-header">
                <div class="modal-title">${window.t('superadmin.assign_plan_title', {name: h(userName)})}</div>
                <button class="modal-close" onclick="document.getElementById('assign-plan-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                ${currentSub ? `<p style="font-size:.83rem;color:var(--color-muted);margin-bottom:14px">${window.t('superadmin.current_plan')} <strong>${PLAN_LABELS[currentSub.plan_slug] ?? currentSub.plan_slug}</strong> (${currentSub.status})</p>` : ''}
                <div class="admin-field" style="margin-bottom:14px">
                    <label>${window.t('superadmin.plan_label')}</label>
                    <select id="ap-plan">
                        <option value="trial">Trial</option>
                        <option value="basic">Basic</option>
                        <option value="advanced">Advanced</option>
                        <option value="premium">Premium</option>
                    </select>
                </div>
                <div class="admin-field">
                    <label>${window.t('superadmin.expires_label')}</label>
                    <input type="date" id="ap-ends" min="${APP_STATE.today}">
                </div>
                <div id="ap-error" style="display:none;color:#991B1B;font-size:.83rem;margin-top:10px"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="document.getElementById('assign-plan-modal').remove()">${window.t('superadmin.cancel_btn')}</button>
                <button class="btn btn-primary" id="ap-save">${window.t('superadmin.assign_btn')}</button>
            </div>
        </div>`;

        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);

        // Nastavi trenutni paket v selectu
        if (currentSub?.plan_slug) {
            document.getElementById('ap-plan').value = currentSub.plan_slug;
        }

        document.getElementById('ap-save').addEventListener('click', async () => {
            const planSlug = document.getElementById('ap-plan').value;
            const endsAt   = document.getElementById('ap-ends').value || null;
            const errEl    = document.getElementById('ap-error');

            try {
                await API.post('/api/superadmin.php', { action: 'assign_plan', user_id: userId, plan_slug: planSlug, ends_at: endsAt });
                toast(window.t('superadmin.plan_assigned', {plan: PLAN_LABELS[planSlug]}));
                overlay.remove();
                loadAdmins();
                loadStats();
            } catch(e) {
                errEl.textContent = e.message;
                errEl.style.display = 'block';
            }
        });
    }
    window.openAssignPlan = openAssignPlan;

    // ── Restavracije ──────────────────────────────────────────────
    async function loadRestaurants() {
        try {
            const rests = await API.get('/api/superadmin.php?action=restaurants');
            const tbody = document.getElementById('sa-restaurants-tbody');
            if (!rests.length) {
                tbody.innerHTML = `<tr><td colspan="6" class="table-empty">${window.t('superadmin.no_restaurants')}</td></tr>`;
                return;
            }
            tbody.innerHTML = rests.map(r => `
                <tr>
                    <td><strong>${h(r.name)}</strong></td>
                    <td>${h(r.owner_name)}</td>
                    <td>${h(r.owner_email)}</td>
                    <td style="text-align:center">${r.reservation_count}</td>
                    <td><span class="badge ${r.is_active==1?'badge-active':'badge-inactive'}">${r.is_active==1?window.t('superadmin.restaurant_active'):window.t('superadmin.restaurant_inactive')}</span></td>
                    <td>${fmtDate(r.created_at)}</td>
                </tr>
            `).join('');
        } catch(e) { toast(e.message, 'error'); }
    }

    // ── Testni email ──────────────────────────────────────────────
    document.getElementById('test-mail-btn')?.addEventListener('click', async () => {
        const email   = document.getElementById('test-mail-to').value.trim();
        const type    = document.getElementById('test-mail-type').value;
        const lang    = document.getElementById('test-mail-lang')?.value || 'sl';
        const btn     = document.getElementById('test-mail-btn');
        const result  = document.getElementById('test-mail-result');

        if (!email) { toast(window.t('superadmin.no_email_error'), 'error'); return; }

        btn.disabled = true;
        btn.textContent = window.t('superadmin.sending');
        result.style.display = 'none';

        try {
            await API.post('/api/superadmin.php', { email, type, lang });
            result.textContent = window.t('superadmin.email_sent', {email: email});
            result.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:.875rem;margin-bottom:16px;background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7';
        } catch(e) {
            result.textContent = e.message;
            result.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:.875rem;margin-bottom:16px;background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5';
        } finally {
            btn.disabled = false;
            btn.textContent = window.t('superadmin.send_test_btn');
        }
    });

    // ── Popusti ───────────────────────────────────────────────────
    const PLAN_NAMES = { basic: 'Basic', advanced: 'Advanced', premium: 'Premium' };

    async function loadDiscounts() {
        try {
            const discounts = await API.get('/api/superadmin.php?action=discounts');
            const tbody = document.getElementById('sa-discounts-tbody');
            if (!discounts.length) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty">${window.t('superadmin.no_discounts')}</td></tr>`;
                return;
            }
            const now = new Date();
            tbody.innerHTML = discounts.map(d => {
                const from  = new Date(d.valid_from);
                const until = new Date(d.valid_until);
                const active = d.is_active == 1 && from <= now && until >= now;
                return `
                <tr>
                    <td><strong>${h(PLAN_NAMES[d.plan_slug] ?? d.plan_slug)}</strong></td>
                    <td>${h(d.label)}</td>
                    <td>${d.discounted_monthly ? d.discounted_monthly + ' €' : '—'}</td>
                    <td>${d.discounted_yearly  ? d.discounted_yearly  + ' €' : '—'}</td>
                    <td>${fmtDate(d.valid_from)}</td>
                    <td>${fmtDate(d.valid_until)}</td>
                    <td><span class="badge ${active ? 'badge-active' : 'badge-inactive'}">${active ? window.t('superadmin.discount_active') : window.t('superadmin.discount_inactive')}</span></td>
                    <td>
                        <button class="btn btn-sm btn-ghost" style="font-size:.75rem;padding:4px 10px;color:#991B1B"
                                onclick="deleteDiscount(${d.id}, '${h(d.label)}')">
                            ${window.t('superadmin.delete_btn')}
                        </button>
                    </td>
                </tr>`;
            }).join('');
        } catch(e) { toast(e.message, 'error'); }
    }

    async function deleteDiscount(id, label) {
        if (!confirm(window.t('superadmin.confirm_delete_discount', {label: label}))) return;
        try {
            await API.post('/api/superadmin.php', { action: 'delete_discount', id });
            toast(window.t('superadmin.discount_deleted'));
            loadDiscounts();
        } catch(e) { toast(e.message, 'error'); }
    }
    window.deleteDiscount = deleteDiscount;

    function openCreateDiscount() {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'create-discount-modal';
        overlay.innerHTML = `
        <div class="modal-box" style="max-width:440px">
            <div class="modal-header">
                <div class="modal-title">${window.t('superadmin.new_discount_title')}</div>
                <button class="modal-close" onclick="document.getElementById('create-discount-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
                <div class="admin-field">
                    <label>${window.t('superadmin.plan_label')}</label>
                    <select id="cd-plan">
                        <option value="basic">Basic</option>
                        <option value="advanced">Advanced</option>
                        <option value="premium">Premium</option>
                    </select>
                </div>
                <div class="admin-field">
                    <label>${window.t('superadmin.discount_label')}</label>
                    <input type="text" id="cd-label" placeholder="${window.t('superadmin.discount_label_placeholder')}" style="width:100%;box-sizing:border-box">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="admin-field">
                        <label>${window.t('superadmin.discount_monthly_label')}</label>
                        <input type="number" id="cd-monthly" step="0.01" min="0" placeholder="npr. 3.99" style="width:100%;box-sizing:border-box">
                    </div>
                    <div class="admin-field">
                        <label>${window.t('superadmin.discount_yearly_label')}</label>
                        <input type="number" id="cd-yearly" step="0.01" min="0" placeholder="npr. 39.99" style="width:100%;box-sizing:border-box">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="admin-field">
                        <label>${window.t('superadmin.col_valid_from')}</label>
                        <input type="date" id="cd-from" style="width:100%;box-sizing:border-box">
                    </div>
                    <div class="admin-field">
                        <label>${window.t('superadmin.col_valid_until')}</label>
                        <input type="date" id="cd-until" style="width:100%;box-sizing:border-box">
                    </div>
                </div>
                <div id="cd-error" style="display:none;color:#991B1B;font-size:.83rem"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="document.getElementById('create-discount-modal').remove()">${window.t('superadmin.cancel_btn')}</button>
                <button class="btn btn-primary" id="cd-save">${window.t('superadmin.create_discount_btn')}</button>
            </div>
        </div>`;

        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);

        // Nastavi today kot privzeti datum začetka
        document.getElementById('cd-from').value = APP_STATE.today;

        document.getElementById('cd-save').addEventListener('click', async () => {
            const planSlug = document.getElementById('cd-plan').value;
            const label    = document.getElementById('cd-label').value.trim();
            const monthly  = document.getElementById('cd-monthly').value;
            const yearly   = document.getElementById('cd-yearly').value;
            const validFrom  = document.getElementById('cd-from').value;
            const validUntil = document.getElementById('cd-until').value;
            const errEl    = document.getElementById('cd-error');
            errEl.style.display = 'none';

            if (!label)      { errEl.textContent = window.t('superadmin.err_label_required'); errEl.style.display='block'; return; }
            if (!validFrom || !validUntil) { errEl.textContent = window.t('superadmin.err_dates_required'); errEl.style.display='block'; return; }
            if (!monthly && !yearly) { errEl.textContent = window.t('superadmin.err_price_required'); errEl.style.display='block'; return; }

            const btn = document.getElementById('cd-save');
            btn.disabled = true; btn.textContent = window.t('superadmin.saving');

            try {
                await API.post('/api/superadmin.php', {
                    action: 'create_discount',
                    plan_slug: planSlug, label,
                    discounted_monthly: monthly || null,
                    discounted_yearly:  yearly  || null,
                    valid_from: validFrom, valid_until: validUntil,
                });
                toast(window.t('superadmin.discount_created'));
                overlay.remove();
                loadDiscounts();
            } catch(e) {
                errEl.textContent = e.message;
                errEl.style.display = 'block';
                btn.disabled = false; btn.textContent = window.t('superadmin.create_discount_btn');
            }
        });
    }
    window.openCreateDiscount = openCreateDiscount;

    // Naloži popuste ko se odpre tab
    document.querySelectorAll('.admin-tab').forEach(tab => {
        if (tab.dataset.tab === 'sa-discounts') {
            tab.addEventListener('click', loadDiscounts);
        }
    });

    // ── Affiliate ─────────────────────────────────────────────────
    const AFF_STATUS = {
        pending:   '<span class="badge badge-trial">Čaka</span>',
        active:    '<span class="badge badge-active">Aktiven</span>',
        suspended: '<span class="badge badge-payment_failed">Suspendiran</span>',
        rejected:  '<span class="badge badge-inactive">Zavrnjen</span>',
    };

    async function loadAffiliates() {
        const status = document.getElementById('aff-filter-status')?.value ?? '';
        const tbody  = document.getElementById('sa-affiliate-tbody');
        try {
            const params = '/api/affiliate_admin.php?action=list' + (status ? '&status=' + encodeURIComponent(status) : '');
            const d = await API.get(params);
            if (!d.items?.length) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Ni affiliate partnerjev.</td></tr>`;
                return;
            }
            tbody.innerHTML = d.items.map(a => `
                <tr>
                    <td><strong>${h(a.full_name)}</strong></td>
                    <td style="font-size:.82rem">${h(a.email)}</td>
                    <td><code style="background:#F3F4F6;padding:2px 6px;border-radius:4px;font-size:.8rem">${h(a.ref_code)}</code></td>
                    <td>${AFF_STATUS[a.status] ?? h(a.status)}</td>
                    <td style="text-align:center">${a.commission_percent ?? '—'}%</td>
                    <td style="text-align:right">${(+(a.payable_eur ?? 0)).toFixed(2)} €</td>
                    <td>${fmtDate(a.created_at)}</td>
                    <td>
                        <button class="btn btn-sm btn-ghost" onclick="openAffDetail(${a.id})">Uredi</button>
                    </td>
                </tr>
            `).join('');
        } catch(e) { toast(e.message, 'error'); }
    }

    async function loadAffSettings() {
        try {
            const s = await API.get('/api/affiliate_admin.php?action=settings');
            document.getElementById('aff-set-pct').value    = s.default_commission_percent ?? '';
            document.getElementById('aff-set-hold').value   = s.default_hold_days ?? '';
            document.getElementById('aff-set-window').value = s.default_commission_window_m ?? '';
            document.getElementById('aff-set-min').value    = s.min_payout_eur ?? '';
        } catch(e) {}
    }

    async function saveAffSettings() {
        try {
            await API.post('/api/affiliate_admin.php', {
                action: 'set_global',
                default_commission_percent:   document.getElementById('aff-set-pct').value,
                default_hold_days:            document.getElementById('aff-set-hold').value,
                default_commission_window_m:  document.getElementById('aff-set-window').value,
                min_payout_eur:               document.getElementById('aff-set-min').value,
            });
            toast('Nastavitve shranjene.');
        } catch(e) { toast(e.message, 'error'); }
    }
    window.saveAffSettings = saveAffSettings;

    async function openAffDetail(id) {
        let aff;
        try { aff = await API.get('/api/affiliate_admin.php?action=detail&id=' + id); }
        catch(e) { toast(e.message, 'error'); return; }

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'aff-detail-modal';
        overlay.innerHTML = `
        <div class="modal-box" style="max-width:520px">
            <div class="modal-header">
                <div class="modal-title">${h(aff.full_name)}</div>
                <button class="modal-close" onclick="document.getElementById('aff-detail-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.83rem">
                    <div><span style="color:var(--color-muted)">Email:</span> ${h(aff.email)}</div>
                    <div><span style="color:var(--color-muted)">IBAN:</span> ${h(aff.iban ?? '—')}</div>
                    <div><span style="color:var(--color-muted)">Ref koda:</span> <code>${h(aff.ref_code)}</code></div>
                    <div><span style="color:var(--color-muted)">Status:</span> ${AFF_STATUS[aff.status] ?? h(aff.status)}</div>
                    <div><span style="color:var(--color-muted)">Davčna:</span> ${h(aff.tax_number ?? '—')}</div>
                    <div><span style="color:var(--color-muted)">Naslov:</span> ${h(aff.address ?? '—')}</div>
                </div>
                <div style="border-top:1px solid var(--color-border);padding-top:12px">
                    <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--color-muted);margin-bottom:10px">Provizija</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
                        <div class="admin-field">
                            <label>% provizija</label>
                            <input type="number" id="ad-pct" value="${aff.commission_percent ?? ''}" min="0" max="100" step="0.1" style="width:100%;box-sizing:border-box" placeholder="privzeto">
                        </div>
                        <div class="admin-field">
                            <label>Hold dni</label>
                            <input type="number" id="ad-hold" value="${aff.hold_days ?? ''}" min="0" max="180" step="1" style="width:100%;box-sizing:border-box" placeholder="privzeto">
                        </div>
                        <div class="admin-field">
                            <label>Okno (mes.)</label>
                            <input type="number" id="ad-window" value="${aff.commission_window_months ?? ''}" min="1" max="60" step="1" style="width:100%;box-sizing:border-box" placeholder="privzeto">
                        </div>
                    </div>
                </div>
                <div style="border-top:1px solid var(--color-border);padding-top:12px">
                    <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--color-muted);margin-bottom:10px">Popustna koda</div>
                    ${aff.discount_enabled ? `
                    <div style="background:#ECFDF5;border:1px solid #6EE7B7;border-radius:8px;padding:10px 14px;font-size:.85rem;margin-bottom:10px">
                        <strong>${h(aff.discount_code ?? '—')}</strong> · ${aff.discount_percent}% popust
                        · ${aff.discount_duration === 'repeating' ? aff.discount_duration_months + ' mes.' : aff.discount_duration}
                    </div>
                    <button class="btn btn-sm btn-ghost" style="color:#991B1B" onclick="revokeAffDiscount(${id})">Prekliči popust</button>
                    ` : `
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
                        <div class="admin-field">
                            <label>Popust (%)</label>
                            <input type="number" id="ad-disc-pct" min="1" max="80" step="1" style="width:100%;box-sizing:border-box" placeholder="npr. 15">
                        </div>
                        <div class="admin-field">
                            <label>Trajanje</label>
                            <select id="ad-disc-dur" style="width:100%;box-sizing:border-box">
                                <option value="once">Enkrat</option>
                                <option value="repeating">N mesecev</option>
                                <option value="forever">Za vedno</option>
                            </select>
                        </div>
                        <div class="admin-field">
                            <label>Meseci</label>
                            <input type="number" id="ad-disc-months" min="1" max="24" step="1" style="width:100%;box-sizing:border-box" placeholder="če repeating">
                        </div>
                    </div>
                    <button class="btn btn-sm btn-primary" onclick="grantAffDiscount(${id})">Dodeli popust</button>
                    `}
                </div>
                <div id="ad-error" style="display:none;color:#991B1B;font-size:.83rem"></div>
            </div>
            <div class="modal-footer" style="justify-content:space-between">
                <div style="display:flex;gap:8px">
                    ${aff.status === 'pending' ? `
                        <button class="btn btn-sm btn-primary" onclick="affAction(${id},'approve')">Odobri</button>
                        <button class="btn btn-sm btn-ghost" style="color:#991B1B" onclick="affAction(${id},'reject')">Zavrni</button>
                    ` : ''}
                    ${aff.status === 'active' ? `
                        <button class="btn btn-sm btn-ghost" style="color:#D97706" onclick="affAction(${id},'suspend')">Suspendiraj</button>
                    ` : ''}
                    ${aff.status === 'suspended' ? `
                        <button class="btn btn-sm btn-primary" onclick="affAction(${id},'approve')">Aktiviraj</button>
                    ` : ''}
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-ghost" onclick="document.getElementById('aff-detail-modal').remove()">Zapri</button>
                    <button class="btn btn-primary" onclick="saveAffConfig(${id})">Shrani konfig</button>
                </div>
            </div>
        </div>`;

        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
    }
    window.openAffDetail = openAffDetail;

    async function saveAffConfig(id) {
        const errEl = document.getElementById('ad-error');
        errEl.style.display = 'none';
        try {
            await API.post('/api/affiliate_admin.php', {
                action: 'configure', id,
                commission_percent:       document.getElementById('ad-pct')?.value     || null,
                hold_days:                document.getElementById('ad-hold')?.value    || null,
                commission_window_months: document.getElementById('ad-window')?.value  || null,
            });
            toast('Konfiguracija shranjena.');
            document.getElementById('aff-detail-modal')?.remove();
            loadAffiliates();
        } catch(e) {
            errEl.textContent = e.message;
            errEl.style.display = 'block';
        }
    }
    window.saveAffConfig = saveAffConfig;

    async function affAction(id, action) {
        const labels = { approve: 'Odobri', reject: 'Zavrni', suspend: 'Suspendiraj' };
        if (!confirm('Akcija: ' + (labels[action] ?? action) + '?')) return;
        try {
            await API.post('/api/affiliate_admin.php', { action, id });
            toast('Uspešno.');
            document.getElementById('aff-detail-modal')?.remove();
            loadAffiliates();
        } catch(e) { toast(e.message, 'error'); }
    }
    window.affAction = affAction;

    async function grantAffDiscount(id) {
        const pct    = document.getElementById('ad-disc-pct')?.value;
        const dur    = document.getElementById('ad-disc-dur')?.value;
        const months = document.getElementById('ad-disc-months')?.value || null;
        const errEl  = document.getElementById('ad-error');
        errEl.style.display = 'none';
        if (!pct) { errEl.textContent = 'Vnesite % popusta.'; errEl.style.display = 'block'; return; }
        try {
            await API.post('/api/affiliate_admin.php', { action: 'grant_discount', id, percent_off: pct, duration: dur, duration_months: months });
            toast('Popust dodeljen!');
            document.getElementById('aff-detail-modal')?.remove();
            loadAffiliates();
        } catch(e) {
            errEl.textContent = e.message;
            errEl.style.display = 'block';
        }
    }
    window.grantAffDiscount = grantAffDiscount;

    async function revokeAffDiscount(id) {
        if (!confirm('Prekličete popustno kodo tega affiliate partnerja?')) return;
        try {
            await API.post('/api/affiliate_admin.php', { action: 'revoke_discount', id });
            toast('Popust preklican.');
            document.getElementById('aff-detail-modal')?.remove();
            loadAffiliates();
        } catch(e) { toast(e.message, 'error'); }
    }
    window.revokeAffDiscount = revokeAffDiscount;

    async function openPayoutBatch() {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'payout-modal';
        overlay.innerHTML = `
        <div class="modal-box" style="max-width:400px">
            <div class="modal-header">
                <div class="modal-title">Ustvari serijo izplačil</div>
                <button class="modal-close" onclick="document.getElementById('payout-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <p style="font-size:.85rem;color:var(--color-muted)">Sistem bo zajel vse izplačljive provizije in ustvaril izplačilne zapise. Referenčno številko boste dobili za identifikacijo pri banki.</p>
                <div class="admin-field" style="margin-top:12px">
                    <label>Referenca (neobvezno)</label>
                    <input type="text" id="po-ref" placeholder="npr. AFF-2025-01" style="width:100%;box-sizing:border-box">
                </div>
                <div id="po-error" style="display:none;color:#991B1B;font-size:.83rem;margin-top:8px"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="document.getElementById('payout-modal').remove()">Prekliči</button>
                <button class="btn btn-primary" onclick="confirmPayout()">Ustvari izplačila</button>
            </div>
        </div>`;
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
    }
    window.openPayoutBatch = openPayoutBatch;

    async function confirmPayout() {
        const ref   = document.getElementById('po-ref')?.value.trim() || null;
        const errEl = document.getElementById('po-error');
        errEl.style.display = 'none';
        try {
            const d = await API.post('/api/affiliate_admin.php', { action: 'create_payout_batch', batch_reference: ref });
            toast('Izplačila ustvarjena: ' + (d.count ?? 0) + ' partnerjev, skupaj ' + (+d.total_eur).toFixed(2) + ' €');
            document.getElementById('payout-modal')?.remove();
            loadAffiliates();
        } catch(e) {
            errEl.textContent = e.message;
            errEl.style.display = 'block';
        }
    }
    window.confirmPayout = confirmPayout;

    function downloadPayoutCsv() {
        window.open(APP_STATE.base + '/api/affiliate_admin.php?action=payout_csv', '_blank');
    }
    window.downloadPayoutCsv = downloadPayoutCsv;

    // ── Kode za popust ────────────────────────────────────────────
    const DISC_DUR_LABELS = { once: 'Enkrat', repeating: 'N mesecev', forever: 'Za vedno' };

    async function loadDiscCodes() {
        const tbody = document.getElementById('sa-disc-codes-tbody');
        try {
            const d = await API.get('/api/discount_codes.php?action=list');
            if (!d.items?.length) {
                tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Ni kod za popust.</td></tr>`;
                return;
            }
            tbody.innerHTML = d.items.map(c => `
                <tr>
                    <td><code style="background:#F3F4F6;padding:2px 8px;border-radius:4px;font-weight:700;font-size:.85rem">${h(c.code)}</code></td>
                    <td>${c.percent_off}%</td>
                    <td>${DISC_DUR_LABELS[c.duration] ?? c.duration}${c.duration === 'repeating' ? ' (' + c.duration_months + ' mes.)' : ''}</td>
                    <td style="font-size:.82rem">${c.owner_name ? h(c.owner_name) : '<span style="color:var(--color-muted)">Splošna</span>'}</td>
                    <td style="text-align:center">${c.redemption_count}</td>
                    <td><span class="badge ${c.is_active==1 ? 'badge-active' : 'badge-inactive'}">${c.is_active==1 ? 'Aktivna' : 'Neaktivna'}</span></td>
                    <td>${fmtDate(c.created_at)}</td>
                    <td>
                        <button class="btn btn-sm btn-ghost" onclick="viewRedemptions(${c.id}, '${h(c.code)}')">Unovčitve</button>
                        ${c.is_active==1 ? `<button class="btn btn-sm btn-ghost" style="color:#991B1B;margin-left:4px" onclick="deactivateCode(${c.id}, '${h(c.code)}')">Deaktiviraj</button>` : ''}
                    </td>
                </tr>
            `).join('');
        } catch(e) { toast(e.message, 'error'); }
    }

    async function deactivateCode(id, code) {
        if (!confirm('Deaktivirate kodo ' + code + '?')) return;
        try {
            await API.post('/api/discount_codes.php', { action: 'deactivate', id });
            toast('Koda deaktivirana.');
            loadDiscCodes();
        } catch(e) { toast(e.message, 'error'); }
    }
    window.deactivateCode = deactivateCode;

    async function viewRedemptions(id, code) {
        let rows;
        try { rows = await API.get('/api/discount_codes.php?action=redemptions&id=' + id); }
        catch(e) { toast(e.message, 'error'); return; }

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'redemptions-modal';
        overlay.innerHTML = `
        <div class="modal-box" style="max-width:560px">
            <div class="modal-header">
                <div class="modal-title">Unovčitve kode ${h(code)}</div>
                <button class="modal-close" onclick="document.getElementById('redemptions-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                ${!rows.items?.length ? '<p style="color:var(--color-muted)">Ni unovčitev.</p>' : `
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Datum</th><th>Email</th><th>Popust (€)</th></tr></thead>
                        <tbody>${rows.items.map(r => `
                            <tr>
                                <td>${fmtDate(r.redeemed_at)}</td>
                                <td>${h(r.user_email)}</td>
                                <td>${(+r.amount_off_eur).toFixed(2)}</td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                </div>`}
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="document.getElementById('redemptions-modal').remove()">Zapri</button>
            </div>
        </div>`;
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
    }
    window.viewRedemptions = viewRedemptions;

    function openCreateDiscCode() {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'create-disc-code-modal';
        overlay.innerHTML = `
        <div class="modal-box" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">Nova koda za popust</div>
                <button class="modal-close" onclick="document.getElementById('create-disc-code-modal').remove()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
                <div class="admin-field">
                    <label>Koda (pusti prazno za avtomatsko)</label>
                    <input type="text" id="dcc-code" placeholder="npr. POLETJE20" style="width:100%;box-sizing:border-box;text-transform:uppercase" oninput="this.value=this.value.toUpperCase()" maxlength="30">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="admin-field">
                        <label>Popust (%)</label>
                        <input type="number" id="dcc-pct" min="1" max="80" step="1" required style="width:100%;box-sizing:border-box" placeholder="npr. 20">
                    </div>
                    <div class="admin-field">
                        <label>Trajanje</label>
                        <select id="dcc-dur" style="width:100%;box-sizing:border-box" onchange="document.getElementById('dcc-months-row').style.display=this.value==='repeating'?'block':'none'">
                            <option value="once">Enkrat</option>
                            <option value="repeating">N mesecev</option>
                            <option value="forever">Za vedno</option>
                        </select>
                    </div>
                </div>
                <div class="admin-field" id="dcc-months-row" style="display:none">
                    <label>Število mesecev</label>
                    <input type="number" id="dcc-months" min="1" max="24" step="1" style="width:100%;box-sizing:border-box" placeholder="npr. 3">
                </div>
                <div class="admin-field">
                    <label>Ime za opis (neobvezno)</label>
                    <input type="text" id="dcc-name" placeholder="npr. Poletna akcija" style="width:100%;box-sizing:border-box">
                </div>
                <div id="dcc-error" style="display:none;color:#991B1B;font-size:.83rem"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="document.getElementById('create-disc-code-modal').remove()">Prekliči</button>
                <button class="btn btn-primary" onclick="submitCreateDiscCode()">Ustvari kodo</button>
            </div>
        </div>`;
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
    }
    window.openCreateDiscCode = openCreateDiscCode;

    async function submitCreateDiscCode() {
        const code    = document.getElementById('dcc-code').value.trim().toUpperCase() || null;
        const pct     = document.getElementById('dcc-pct').value;
        const dur     = document.getElementById('dcc-dur').value;
        const months  = document.getElementById('dcc-months').value || null;
        const name    = document.getElementById('dcc-name').value.trim() || null;
        const errEl   = document.getElementById('dcc-error');
        errEl.style.display = 'none';
        if (!pct) { errEl.textContent = 'Vnesite % popusta.'; errEl.style.display = 'block'; return; }
        try {
            const d = await API.post('/api/discount_codes.php', { action: 'create', code, percent_off: pct, duration: dur, duration_months: months, name });
            toast('Koda ustvarjena: ' + d.code);
            document.getElementById('create-disc-code-modal')?.remove();
            loadDiscCodes();
        } catch(e) {
            errEl.textContent = e.message;
            errEl.style.display = 'block';
        }
    }
    window.submitCreateDiscCode = submitCreateDiscCode;

    // Naloži ob kliku na tab
    document.querySelectorAll('.admin-tab').forEach(tab => {
        if (tab.dataset.tab === 'sa-affiliate') {
            tab.addEventListener('click', () => { loadAffiliates(); loadAffSettings(); });
        }
        if (tab.dataset.tab === 'sa-disc-codes') {
            tab.addEventListener('click', loadDiscCodes);
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
