<?php
/**
 * Admin stran: Čakalna lista – pregled in upravljanje po datumu/restavraciji.
 * Dostopna za: admin (Advanced/Premium), superadmin.
 */
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/waitlist_notifier.php';

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

$isAdmin    = $_SESSION['role'] === 'admin';
$fullName   = $_SESSION['full_name'];
$hasFeature = user_has_feature($pdo, (int)$_SESSION['user_id'], 'waitlist');

// Naloži restavracije admina
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

$defaultRestId = count($restaurants) === 1 ? (int)$restaurants[0]['id'] : 0;

// ── POST: ročne akcije admina ──────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasFeature) {
    $wlId  = (int)($_POST['wl_id'] ?? 0);
    $act   = trim($_POST['act'] ?? '');

    if ($wlId && in_array($act, ['notify', 'remove'])) {
        // Preveri da admin ima dostop do te čakalne liste
        $check = $pdo->prepare("
            SELECT w.*, r.name AS rest_name FROM waitlist w
            JOIN restaurants r ON r.id = w.restaurant_id
            JOIN restaurant_admins ra ON ra.restaurant_id = r.id
            WHERE w.id = ? AND ra.user_id = ?
        ");
        $check->execute([$wlId, $_SESSION['user_id']]);
        $entry = $check->fetch();

        if ($entry) {
            if ($act === 'remove') {
                $pdo->prepare("UPDATE waitlist SET status = 'removed' WHERE id = ?")
                    ->execute([$wlId]);
                $actionMsg = 'Vnos odstranjen.';
            } elseif ($act === 'notify' && $entry['status'] === 'waiting') {
                // Ročno sproži obvestilo za tega gosta (preskoči FIFO)
                $expiresAt = date('Y-m-d H:i:s', time() + 2 * 3600);
                $pdo->prepare("
                    UPDATE waitlist SET status = 'notified', notified_at = NOW(), expires_at = ?
                    WHERE id = ?
                ")->execute([$expiresAt, $wlId]);
                try {
                    // Pridobi polni vnos za email
                    $fullEntry = $pdo->prepare("
                        SELECT w.*, r.name AS rest_name, r.contact_email, r.contact_phone
                        FROM waitlist w JOIN restaurants r ON r.id = w.restaurant_id
                        WHERE w.id = ?
                    ");
                    $fullEntry->execute([$wlId]);
                    $fe = $fullEntry->fetch();
                    if ($fe) _send_waitlist_notify_email($fe);
                } catch (Throwable $e) { error_log('Admin manual notify error: ' . $e->getMessage()); }
                $actionMsg = 'Obvestilo poslano gostu.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Čakalna lista – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css?v=4">
    <style>
        .wl-wrap  { max-width: 1000px; margin: 0 auto; padding: 28px 20px 60px; }
        .wl-title { font-size: 1.4rem; font-weight: 700; color: #111827; margin: 0 0 20px; }
        .wl-toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
        .wl-select  { padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: .9rem; outline: none; background: #fff; }
        .wl-select:focus { border-color: #F59E0B; box-shadow: 0 0 0 3px rgba(245,158,11,.1); }
        .wl-date    { padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: .9rem; outline: none; }
        .wl-date:focus { border-color: #F59E0B; box-shadow: 0 0 0 3px rgba(245,158,11,.1); }
        .wl-table   { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .wl-table th { padding: 10px 12px; text-align: left; background: #F9FAFB; color: #6B7280; font-weight: 600; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #E5E7EB; }
        .wl-table td { padding: 10px 12px; border-bottom: 1px solid #F3F4F6; color: #374151; vertical-align: middle; }
        .wl-table tr:last-child td { border-bottom: none; }
        .wl-table tr:hover td { background: #FAFAFA; }
        .table-wrap { background: #fff; border-radius: 12px; border: 1px solid #E5E7EB; overflow: hidden; }
        .badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 600; white-space: nowrap; }
        .badge-waiting   { background: #FEF3C7; color: #92400E; }
        .badge-notified  { background: #DBEAFE; color: #1D4ED8; }
        .badge-confirmed { background: #D1FAE5; color: #065F46; }
        .badge-expired   { background: #FEE2E2; color: #991B1B; }
        .badge-removed   { background: #F3F4F6; color: #6B7280; }
        .action-btn { font-size: .78rem; padding: 4px 10px; border-radius: 6px; border: 1px solid; cursor: pointer; font-weight: 500; transition: all .15s; }
        .btn-notify { background: #EFF6FF; border-color: #BFDBFE; color: #1D4ED8; }
        .btn-notify:hover { background: #DBEAFE; }
        .btn-remove { background: #FEF2F2; border-color: #FECACA; color: #DC2626; }
        .btn-remove:hover { background: #FEE2E2; }
        .empty-state { text-align: center; padding: 60px 20px; color: #9CA3AF; }
        .upsell-box { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 12px; padding: 28px; text-align: center; margin-top: 40px; }
        .upsell-box h3 { margin: 0 0 8px; font-size: 1.1rem; color: #92400E; }
        .upsell-box p  { margin: 0 0 16px; color: #78350F; font-size: .9rem; }
        .msg-ok { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; border-radius: 8px; padding: 10px 16px; font-size: .88rem; margin-bottom: 16px; }
        .wl-table tr.clickable { cursor: pointer; }
        .wl-table tr.clickable:hover td { background: #FEF9EE; }
        /* Detail modal */
        .wl-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; padding:16px; }
        .wl-modal-overlay.open { display:flex; }
        .wl-modal { background:#fff; border-radius:14px; max-width:540px; width:100%; max-height:88vh; overflow-y:auto; padding:26px; position:relative; box-shadow:0 8px 30px rgba(0,0,0,.15); }
        .wl-modal-close { position:absolute; top:14px; right:14px; background:none; border:none; font-size:1.3rem; cursor:pointer; color:#9CA3AF; line-height:1; }
        .wl-modal h3 { margin:0 0 18px; font-size:1rem; font-weight:700; color:#111827; }
        .wl-detail-grid { display:grid; grid-template-columns:130px 1fr; gap:8px 12px; font-size:.875rem; }
        .wl-detail-label { color:#6B7280; font-weight:500; }
        .wl-detail-value { color:#111827; }
        .wl-modal-actions { display:flex; gap:8px; margin-top:18px; padding-top:16px; border-top:1px solid #F3F4F6; }
    </style>
</head>
<body>
<?php require_once '../includes/trial_banner.php'; ?>

<header class="app-header">
    <div class="header-logo">
        <div class="logo-icon">R</div>
        <span class="logo-text"><?= h(APP_NAME) ?></span>
    </div>
    <div class="header-nav">
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">Rezervacije</a>
        <a href="<?= BASE_PATH ?>/pages/stats.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Statistika
        </a>
        <?php if (user_has_feature($pdo, (int)$_SESSION['user_id'], 'guest_database')): ?>
        <a href="<?= BASE_PATH ?>/pages/guests.php" class="btn-header btn-header-admin">Gostje</a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/pages/waitlist.php" class="btn-header btn-header-admin" style="background:#FEF3C7;color:#92400E">Čakalna lista</a>
        <a href="<?= BASE_PATH ?>/pages/admin.php" class="btn-header btn-header-admin">Admin</a>
        <a href="<?= BASE_PATH ?>/pages/billing.php" class="btn-header btn-header-admin">Paketi</a>
        <a href="<?= BASE_PATH ?>/pages/profile.php" class="btn-header">Profil</a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<div class="wl-wrap">
    <h1 class="wl-title">Čakalna lista</h1>

    <?php if (!$hasFeature): ?>
    <div class="upsell-box">
        <h3>Čakalna lista – Advanced/Premium</h3>
        <p>Z nadgradnjo paketa gostje ne bodo več izgubili mesta, ko se sprosti termin. Sistem jih samodejno obvesti.</p>
        <a href="<?= BASE_PATH ?>/pages/billing.php" style="display:inline-block;background:#F59E0B;color:#fff;padding:10px 24px;border-radius:8px;font-weight:600;text-decoration:none">
            Nadgradi paket
        </a>
    </div>
    <?php else: ?>

    <?php if ($actionMsg): ?>
    <div class="msg-ok"><?= h($actionMsg) ?></div>
    <?php endif; ?>

    <div class="wl-toolbar">
        <?php if (count($restaurants) > 1): ?>
        <select id="filter-rest" class="wl-select" onchange="loadWaitlist()">
            <option value="0">Vse restavracije</option>
            <?php foreach ($restaurants as $r): ?>
            <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select id="filter-status" class="wl-select" onchange="loadWaitlist()">
            <option value="">Vse stanje</option>
            <option value="waiting">Čakajoči</option>
            <option value="notified">Obveščeni</option>
            <option value="confirmed">Potrjeni</option>
            <option value="expired">Potekli</option>
            <option value="removed">Odstranjeni</option>
        </select>
        <input type="date" id="filter-date" class="wl-date" onchange="loadWaitlist()"
               value="<?= date('Y-m-d') ?>" title="Filtriraj po datumu">
        <button onclick="clearDateFilter()" class="action-btn" style="border-color:#D1D5DB;color:#6B7280">
            Vse datume
        </button>
    </div>

    <div class="table-wrap">
        <table class="wl-table">
            <thead>
                <tr>
                    <th>Ime</th>
                    <th>Email / Tel</th>
                    <th>Datum</th>
                    <th>Čas</th>
                    <th>Gostje</th>
                    <th>Stanje</th>
                    <th>Vpisan</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="wl-tbody">
                <tr><td colspan="8" class="empty-state">Nalagam...</td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: podrobnosti čakalne liste -->
<div id="wl-detail-overlay" class="wl-modal-overlay" onclick="if(event.target===this)closeDetailModal()">
    <div class="wl-modal">
        <button class="wl-modal-close" onclick="closeDetailModal()">×</button>
        <h3>Podrobnosti čakalne liste</h3>
        <div id="wl-detail-body" class="wl-detail-grid"></div>
        <div id="wl-detail-actions" class="wl-modal-actions"></div>
    </div>
</div>

<script>
const BASE_PATH  = <?= json_encode(BASE_PATH) ?>;
const DEFAULT_REST = <?= $defaultRestId ?>;

function clearDateFilter() {
    document.getElementById('filter-date').value = '';
    loadWaitlist();
}

async function loadWaitlist() {
    const restId = document.getElementById('filter-rest')?.value ?? DEFAULT_REST;
    const status = document.getElementById('filter-status')?.value ?? '';
    const date   = document.getElementById('filter-date')?.value ?? '';

    const params = new URLSearchParams();
    if (restId && restId != '0') params.set('restaurant_id', restId);
    if (status)  params.set('status', status);
    if (date)    params.set('date', date);

    const tbody = document.getElementById('wl-tbody');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:#9CA3AF">Nalagam...</td></tr>';

    try {
        const res  = await fetch(`${BASE_PATH}/api/waitlist_admin.php?${params}`);
        const json = await res.json();
        if (!json.success) throw new Error(json.error);

        const rows = json.data;
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:#9CA3AF">Ni vnosov za izbrane filtre.</td></tr>';
            return;
        }

        // Shrani podatke vrstic za modal
        window._wlRows = {};
        rows.forEach(r => { window._wlRows[r.id] = r; });

        tbody.innerHTML = rows.map(r => {
            const badge = `<span class="badge ${badgeClass[r.status] || ''}">${statusLabel[r.status] || r.status}</span>`;
            const timePref = r.time_preference || '—';
            const d = new Date(r.date + 'T12:00:00');
            const dateStr = `${d.getDate()}. ${d.getMonth()+1}. ${d.getFullYear()}`;
            const createdStr = r.created_at ? r.created_at.substring(0, 16) : '—';

            let actions = '';
            if (r.status === 'waiting') {
                actions = `
                    <button class="action-btn btn-notify" onclick="event.stopPropagation();doAction(${r.id},'notify')" title="Ročno pošlji obvestilo">Obvesti</button>
                    <button class="action-btn btn-remove" onclick="event.stopPropagation();doAction(${r.id},'remove')" title="Odstrani z liste">Odstrani</button>
                `;
            } else if (r.status === 'notified') {
                actions = `<button class="action-btn btn-remove" onclick="event.stopPropagation();doAction(${r.id},'remove')">Odstrani</button>`;
            }

            const phone = r.phone ? `<br><span style="color:#9CA3AF;font-size:.8rem">${escHtml(r.phone)}</span>` : '';

            return `<tr class="clickable" onclick="openDetailModal(${r.id})">
                <td><strong>${escHtml(r.first_name)} ${escHtml(r.last_name)}</strong>${r.rest_count > 1 ? `<br><span style="color:#9CA3AF;font-size:.8rem">${escHtml(r.rest_name)}</span>` : ''}</td>
                <td>${escHtml(r.email)}${phone}</td>
                <td>${dateStr}</td>
                <td>${escHtml(timePref)}</td>
                <td>${r.guests}</td>
                <td>${badge}</td>
                <td style="color:#9CA3AF;font-size:.8rem">${createdStr}</td>
                <td style="white-space:nowrap">${actions}</td>
            </tr>`;
        }).join('');

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:40px;color:#EF4444">Napaka: ${e.message}</td></tr>`;
    }
}

async function doAction(wlId, act) {
    if (act === 'remove' && !confirm('Odstraniti vnos s čakalne liste?')) return;

    const form = new FormData();
    form.append('wl_id', wlId);
    form.append('act', act);

    try {
        const res  = await fetch(`${BASE_PATH}/api/waitlist_admin.php`, { method: 'POST', body: form });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        loadWaitlist();
    } catch (e) {
        alert('Napaka: ' + e.message);
    }
}

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(str) {
    if (!str) return '—';
    const d = new Date(str.replace(' ', 'T'));
    return `${d.getDate()}. ${d.getMonth()+1}. ${d.getFullYear()}`;
}
function fmtDateTime(str) {
    if (!str) return '—';
    return str.substring(0, 16).replace('T', ' ');
}

const statusLabel = {
    waiting: 'Čaka', notified: 'Obveščen', confirmed: 'Potrjen',
    expired: 'Potekel', removed: 'Odstranjen',
};
const badgeClass = {
    waiting: 'badge-waiting', notified: 'badge-notified',
    confirmed: 'badge-confirmed', expired: 'badge-expired', removed: 'badge-removed',
};

function openDetailModal(id) {
    const r = (window._wlRows || {})[id];
    if (!r) return;

    const res = r.reservation;

    // Naslov: ime iz rezervacije ali ime iz čakalne liste
    const displayName = (res && res.guest_name) ? res.guest_name : (r.first_name + ' ' + r.last_name).trim();
    document.querySelector('#wl-detail-overlay .wl-modal h3').textContent = displayName || 'Podrobnosti';

    function row(label, value) {
        return `<span class="wl-detail-label">${label}</span><span class="wl-detail-value">${value}</span>`;
    }
    let _firstSection = true;
    function sectionHeader(text) {
        const style = _firstSection
            ? 'grid-column:1/-1;margin-bottom:4px;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#9CA3AF;font-weight:700'
            : 'grid-column:1/-1;margin-top:12px;margin-bottom:2px;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#9CA3AF;font-weight:700;border-top:1px solid #F3F4F6;padding-top:10px';
        _firstSection = false;
        return `<span style="${style}">${text}</span>`;
    }

    let html = '';

    // ── Vpis na čakalno listo ──────────────────────────────────
    html += sectionHeader('Čakalna lista');
    if (r.rest_count > 1) html += row('Restavracija', escHtml(r.rest_name));
    html += row('Datum', fmtDate(r.date + 'T12:00:00'));
    html += row('Želen čas', escHtml(r.time_preference || '—'));
    html += row('Gostje', r.guests);
    html += row('Stanje', `<span class="badge ${badgeClass[r.status] || ''}">${statusLabel[r.status] || r.status}</span>`);
    html += row('Vpisan', fmtDateTime(r.created_at));
    if (r.notified_at) html += row('Obveščen', fmtDateTime(r.notified_at));
    if (r.status === 'notified' && r.expires_at) html += row('Rok za potrditev', fmtDateTime(r.expires_at));
    if (r.confirmed_at) html += row('Potrjen', fmtDateTime(r.confirmed_at));

    // ── Kontaktni podatki ─────────────────────────────────────
    html += sectionHeader('Kontakt');
    html += row('Ime', escHtml(displayName));
    html += row('Email', escHtml(r.email));
    html += row('Telefon', escHtml((res && res.res_phone) ? res.res_phone : (r.phone || '—')));

    // ── Rezervacija ───────────────────────────────────────────
    if (res) {
        const resStatusLabel = { confirmed: 'Potrjena', pending: 'V čakanju', cancelled: 'Preklicana', arrived: 'Prispel' };
        html += sectionHeader('Rezervacija');
        if (res.reservation_time) html += row('Čas', escHtml(res.reservation_time.substring(0, 5)));
        if (res.guest_count)      html += row('Število oseb', res.guest_count);
        if (res.duration)         html += row('Trajanje', res.duration + ' min');
        if (res.res_status)       html += row('Status', escHtml(resStatusLabel[res.res_status] || res.res_status));
        if (res.notes)            html += row('Opomba', escHtml(res.notes));

        // Custom polja
        const fvs = r.field_values || [];
        if (fvs.length) {
            fvs.forEach(fv => { html += row(escHtml(fv.label), escHtml(fv.value || '—')); });
        }
    }

    // ── Profil gosta ──────────────────────────────────────────
    const gp = r.guest_profile;
    if (gp) {
        html += sectionHeader('Profil gosta');
        html += row('Obiski', gp.total_visits || 0);
        if (gp.last_visit) html += row('Zadnji obisk', fmtDate(gp.last_visit));
        // VIP iz JSON tags
        try {
            const tags = JSON.parse(gp.tags || '[]');
            if (Array.isArray(tags) && tags.some(t => String(t).toUpperCase() === 'VIP')) {
                html += row('Oznaka', '<span style="display:inline-block;background:#FEF3C7;color:#92400E;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:999px;border:1px solid #FDE68A">VIP</span>');
            }
        } catch(e) {}
    }

    document.getElementById('wl-detail-body').innerHTML = html;

    let actionsHtml = '';
    if (r.status === 'waiting') {
        actionsHtml = `
            <button class="action-btn btn-notify" onclick="doAction(${r.id},'notify');closeDetailModal()">Pošlji obvestilo</button>
            <button class="action-btn btn-remove" onclick="doAction(${r.id},'remove');closeDetailModal()">Odstrani</button>`;
    } else if (r.status === 'notified') {
        actionsHtml = `<button class="action-btn btn-remove" onclick="doAction(${r.id},'remove');closeDetailModal()">Odstrani</button>`;
    }
    document.getElementById('wl-detail-actions').innerHTML = actionsHtml;

    document.getElementById('wl-detail-overlay').classList.add('open');
}

function closeDetailModal() {
    document.getElementById('wl-detail-overlay').classList.remove('open');
}

// Tipka Escape zapre modal
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetailModal(); });

// Ob nalaganju strani
loadWaitlist();
</script>
</body>
</html>
