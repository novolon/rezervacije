<?php
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';

if (!is_logged_in() || $_SESSION['role'] !== 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/main.php');
    exit;
}

$pdo      = getDB();
$fullName = $_SESSION['full_name'];

// Pridobi vse zahtevke
$requests = $pdo->query("
    SELECT gr.*, r.name AS restaurant_name
    FROM gdpr_requests gr
    LEFT JOIN restaurants r ON r.id = gr.restaurant_id
    ORDER BY gr.requested_at DESC
")->fetchAll();

$statusLabels = [
    'pending'    => t('gdpr.status_pending'),
    'processing' => t('gdpr.status_processing'),
    'completed'  => t('gdpr.status_completed'),
    'rejected'   => t('gdpr.status_rejected'),
];
$typeLabels = [
    'access'        => t('gdpr.type_access'),
    'rectification' => t('gdpr.type_rectification'),
    'erasure'       => t('gdpr.type_erasure'),
    'portability'   => t('gdpr.type_portability'),
];
$statusColors = [
    'pending'    => '#FEF3C7;color:#92400E',
    'processing' => '#DBEAFE;color:#1E40AF',
    'completed'  => '#D1FAE5;color:#065F46',
    'rejected'   => '#FEE2E2;color:#991B1B',
];
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('gdpr.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/modal.css">
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
    <style>
        .gdpr-table { width:100%;border-collapse:collapse }
        .gdpr-table th { text-align:left;font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6B7280;padding:8px 12px;border-bottom:2px solid #E5E7EB }
        .gdpr-table td { padding:12px;font-size:.875rem;border-bottom:1px solid #F3F4F6;vertical-align:top }
        .gdpr-table tr:hover td { background:#F9FAFB }
        .badge { display:inline-block;padding:3px 9px;border-radius:20px;font-size:.75rem;font-weight:600 }
        .actions-row { display:flex;gap:8px;align-items:center;flex-wrap:wrap }
        .btn-sm { padding:5px 12px;border:none;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer;transition:opacity .15s }
        .btn-sm:hover { opacity:.8 }
        .btn-erase { background:#FEE2E2;color:#991B1B }
        .btn-export { background:#DBEAFE;color:#1E40AF }
        .modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;align-items:center;justify-content:center }
        .modal-overlay.open { display:flex }
        .modal-box { background:#fff;border-radius:16px;padding:28px 32px;max-width:480px;width:100%;margin:16px }
        .modal-box h3 { margin:0 0 16px;font-size:1.1rem;font-weight:700 }
        .modal-box select, .modal-box textarea { width:100%;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:.875rem;box-sizing:border-box;font-family:inherit }
        .modal-box textarea { resize:vertical;min-height:80px }
        .modal-label { font-size:.8rem;font-weight:600;color:#374151;margin:14px 0 5px;display:block }
    </style>
</head>
<body>

<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/superadmin.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600"><?= t('gdpr.header_label') ?></span>
    </div>
    <div class="header-actions">
        <span class="header-user">👤 <?= h($fullName) ?></span>
        <a href="<?= BASE_PATH ?>/pages/superadmin.php" class="btn-header"><?= t('gdpr.back_superadmin') ?></a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout"><?= t('gdpr.logout') ?></a>
    </div>
</header>

<div class="admin-layout">
<div class="admin-content">
    <h1 class="admin-page-title"><?= t('gdpr.page_heading') ?></h1>

    <div id="msg-box" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:10px;font-size:.875rem"></div>

    <!-- Orodna vrstica: erase_user / erase_guest / export -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:22px;align-items:center">
        <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
            onclick="openEraseUser()"><?= t('gdpr.btn_erase_user') ?></button>
        <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
            onclick="openEraseGuest()"><?= t('gdpr.btn_erase_guest') ?></button>
        <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
            onclick="openExportUser()"><?= t('gdpr.btn_export_user') ?></button>
        <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
            onclick="openExportGuest()"><?= t('gdpr.btn_export_guest') ?></button>
        <a href="<?= BASE_PATH ?>/pages/gdpr_request.php" target="_blank"
           class="btn-sm" style="background:#FEF3C7;color:#92400E;padding:8px 16px;text-decoration:none">
            <?= t('gdpr.public_page') ?></a>
    </div>

    <?php if (empty($requests)): ?>
    <div style="text-align:center;padding:60px 20px;color:#9CA3AF">
        <div style="font-size:2.5rem;margin-bottom:12px">📋</div>
        <p><?= t('gdpr.no_requests') ?></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;background:#fff;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)">
        <table class="gdpr-table">
            <thead>
                <tr>
                    <th><?= t('gdpr.col_id') ?></th>
                    <th><?= t('gdpr.col_email') ?></th>
                    <th><?= t('gdpr.col_type') ?></th>
                    <th><?= t('gdpr.col_restaurant') ?></th>
                    <th><?= t('gdpr.col_status') ?></th>
                    <th><?= t('gdpr.col_date') ?></th>
                    <th><?= t('gdpr.col_actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $req): ?>
            <?php
                $sc = $statusColors[$req['status']] ?? '#F3F4F6;color:#374151';
                $tl = $typeLabels[$req['type']]     ?? $req['type'];
                $sl = $statusLabels[$req['status']] ?? $req['status'];
            ?>
            <tr>
                <td><?= (int)$req['id'] ?></td>
                <td><?= h($req['requester_email']) ?></td>
                <td><?= h($tl) ?></td>
                <td><?= $req['restaurant_name'] ? h($req['restaurant_name']) : '<span style="color:#9CA3AF">–</span>' ?></td>
                <td><span class="badge" style="background:<?= $sc ?>"><?= h($sl) ?></span></td>
                <td style="white-space:nowrap;color:#6B7280"><?= h(date('d.m.Y H:i', strtotime($req['requested_at']))) ?></td>
                <td>
                    <div class="actions-row">
                        <button class="btn-sm" style="background:#F3F4F6;color:#374151"
                            onclick="openStatusModal(<?= (int)$req['id'] ?>, '<?= h($req['status']) ?>', <?= $req['notes'] ? "'" . addslashes(h($req['notes'])) . "'" : "''" ?>)">
                            <?= t('gdpr.edit_status') ?>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Modal: Uredi status -->
<div class="modal-overlay" id="modal-status">
    <div class="modal-box">
        <h3><?= t('gdpr.modal_edit_title') ?></h3>
        <input type="hidden" id="modal-req-id">
        <label class="modal-label"><?= t('gdpr.modal_status_label') ?></label>
        <select id="modal-status-sel">
            <option value="pending"><?= t('gdpr.status_pending') ?></option>
            <option value="processing"><?= t('gdpr.status_processing') ?></option>
            <option value="completed"><?= t('gdpr.status_completed') ?></option>
            <option value="rejected"><?= t('gdpr.status_rejected') ?></option>
        </select>
        <label class="modal-label"><?= t('gdpr.modal_notes_label') ?></label>
        <textarea id="modal-notes" placeholder="<?= t('gdpr.modal_notes_placeholder') ?>"></textarea>
        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end">
            <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
                onclick="closeModals()"><?= t('gdpr.modal_cancel') ?></button>
            <button class="btn-sm" style="background:#F59E0B;color:#fff;padding:8px 16px"
                onclick="saveStatus()"><?= t('gdpr.modal_save') ?></button>
        </div>
    </div>
</div>

<!-- Modal: Erase user -->
<div class="modal-overlay" id="modal-erase-user">
    <div class="modal-box">
        <h3><?= t('gdpr.modal_erase_user_title') ?></h3>
        <p style="font-size:.85rem;color:#6B7280;margin:0 0 14px;line-height:1.5"><?= t('gdpr.modal_erase_user_desc') ?></p>
        <label class="modal-label"><?= t('gdpr.modal_user_id_label') ?></label>
        <input type="number" id="erase-user-id" placeholder="<?= t('gdpr.modal_user_id_placeholder') ?>"
            style="width:100%;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:.875rem;box-sizing:border-box">
        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end">
            <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
                onclick="closeModals()"><?= t('gdpr.modal_cancel') ?></button>
            <button class="btn-sm btn-erase" style="padding:8px 16px"
                onclick="eraseUser()"><?= t('gdpr.modal_anonymize') ?></button>
        </div>
    </div>
</div>

<!-- Modal: Erase guest -->
<div class="modal-overlay" id="modal-erase-guest">
    <div class="modal-box">
        <h3><?= t('gdpr.modal_erase_guest_title') ?></h3>
        <p style="font-size:.85rem;color:#6B7280;margin:0 0 14px;line-height:1.5"><?= t('gdpr.modal_erase_guest_desc') ?></p>
        <label class="modal-label"><?= t('gdpr.modal_guest_email_label') ?></label>
        <input type="email" id="erase-guest-email" placeholder="<?= t('gdpr.modal_guest_email_placeholder') ?>"
            style="width:100%;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:.875rem;box-sizing:border-box">
        <label class="modal-label"><?= t('gdpr.modal_rest_id_label') ?></label>
        <input type="number" id="erase-guest-rid" placeholder="<?= t('gdpr.modal_rest_id_placeholder') ?>"
            style="width:100%;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:.875rem;box-sizing:border-box;margin-top:6px">
        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end">
            <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
                onclick="closeModals()"><?= t('gdpr.modal_cancel') ?></button>
            <button class="btn-sm btn-erase" style="padding:8px 16px"
                onclick="eraseGuest()"><?= t('gdpr.modal_anonymize') ?></button>
        </div>
    </div>
</div>

<!-- Modal: Export user -->
<div class="modal-overlay" id="modal-export-user">
    <div class="modal-box">
        <h3><?= t('gdpr.modal_export_user_title') ?></h3>
        <label class="modal-label"><?= t('gdpr.modal_user_id_label') ?></label>
        <input type="number" id="export-user-id" placeholder="<?= t('gdpr.modal_user_id_placeholder') ?>"
            style="width:100%;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:.875rem;box-sizing:border-box">
        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end">
            <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
                onclick="closeModals()"><?= t('gdpr.modal_cancel') ?></button>
            <button class="btn-sm btn-export" style="padding:8px 16px"
                onclick="exportUser()"><?= t('gdpr.modal_export_json') ?></button>
        </div>
    </div>
</div>

<!-- Modal: Export guest -->
<div class="modal-overlay" id="modal-export-guest">
    <div class="modal-box">
        <h3><?= t('gdpr.modal_export_guest_title') ?></h3>
        <label class="modal-label"><?= t('gdpr.modal_guest_email_label') ?></label>
        <input type="email" id="export-guest-email" placeholder="<?= t('gdpr.modal_guest_email_placeholder') ?>"
            style="width:100%;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:.875rem;box-sizing:border-box">
        <label class="modal-label"><?= t('gdpr.modal_rest_id_label') ?></label>
        <input type="number" id="export-guest-rid" placeholder="<?= t('gdpr.modal_rest_id_placeholder') ?>"
            style="width:100%;padding:9px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:.875rem;box-sizing:border-box;margin-top:6px">
        <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end">
            <button class="btn-sm" style="background:#F3F4F6;color:#374151;padding:8px 16px"
                onclick="closeModals()"><?= t('gdpr.modal_cancel') ?></button>
            <button class="btn-sm btn-export" style="padding:8px 16px"
                onclick="exportGuest()"><?= t('gdpr.modal_export_json') ?></button>
        </div>
    </div>
</div>

<script>
const API = '<?= BASE_PATH ?>/api/gdpr.php';

function openStatusModal(id, status, notes) {
    document.getElementById('modal-req-id').value         = id;
    document.getElementById('modal-status-sel').value     = status;
    document.getElementById('modal-notes').value          = notes;
    document.getElementById('modal-status').classList.add('open');
}
function openEraseUser()   { document.getElementById('modal-erase-user').classList.add('open'); }
function openEraseGuest()  { document.getElementById('modal-erase-guest').classList.add('open'); }
function openExportUser()  { document.getElementById('modal-export-user').classList.add('open'); }
function openExportGuest() { document.getElementById('modal-export-guest').classList.add('open'); }

function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}

function showMsg(text, ok) {
    const el = document.getElementById('msg-box');
    el.textContent   = text;
    el.style.display = 'block';
    el.style.background = ok ? '#D1FAE5' : '#FEE2E2';
    el.style.color      = ok ? '#065F46' : '#991B1B';
    el.style.border     = 'none';
    setTimeout(() => { el.style.display = 'none'; }, 4000);
}

async function apiPost(action, body) {
    const res  = await fetch(API + '?action=' + action, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
    });
    return res.json();
}

async function saveStatus() {
    const id     = parseInt(document.getElementById('modal-req-id').value);
    const status = document.getElementById('modal-status-sel').value;
    const notes  = document.getElementById('modal-notes').value.trim();
    const json   = await apiPost('update_status', { id, status, notes });
    closeModals();
    if (json.success) { showMsg(window.t('gdpr.status_updated'), true); setTimeout(() => location.reload(), 1200); }
    else              { showMsg(json.error || window.t('gdpr.err_generic'), false); }
}

async function eraseUser() {
    const userId = parseInt(document.getElementById('erase-user-id').value);
    if (!userId || !confirm(window.t('gdpr.confirm_anonymize'))) return;
    const json = await apiPost('erase_user', { user_id: userId });
    closeModals();
    json.success ? showMsg(window.t('gdpr.user_anonymized'), true) : showMsg(json.error || window.t('gdpr.err_generic'), false);
}

async function eraseGuest() {
    const email = document.getElementById('erase-guest-email').value.trim();
    const rid   = parseInt(document.getElementById('erase-guest-rid').value);
    if (!email || !rid || !confirm(window.t('gdpr.confirm_anonymize'))) return;
    const json = await apiPost('erase_guest', { email, restaurant_id: rid });
    closeModals();
    json.success ? showMsg(window.t('gdpr.guest_anonymized', {count: json.data.anonymized_reservations}), true)
                 : showMsg(json.error || window.t('gdpr.err_generic'), false);
}

function exportUser() {
    const uid = parseInt(document.getElementById('export-user-id').value);
    if (!uid) return;
    closeModals();
    // POST z redirect ni trivialen – použijemo formo
    const f = document.createElement('form');
    f.method = 'POST'; f.action = API + '?action=export_user';
    const i  = document.createElement('input');
    i.type = 'hidden'; i.name = 'body'; // Raje uporabi fetch + blob
    // Fetch + blob download
    fetch(API + '?action=export_user', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: uid }),
    }).then(r => {
        if (!r.ok) return r.json().then(j => { throw new Error(j.error || window.t('gdpr.err_generic')); });
        return r.blob();
    }).then(blob => {
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href = url; a.download = 'gdpr_export_user_' + uid + '.json'; a.click();
        URL.revokeObjectURL(url);
    }).catch(e => showMsg(e.message, false));
}

function exportGuest() {
    const email = document.getElementById('export-guest-email').value.trim();
    const rid   = parseInt(document.getElementById('export-guest-rid').value);
    if (!email || !rid) return;
    closeModals();
    fetch(API + '?action=export_guest', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, restaurant_id: rid }),
    }).then(r => {
        if (!r.ok) return r.json().then(j => { throw new Error(j.error || window.t('gdpr.err_generic')); });
        return r.blob();
    }).then(blob => {
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href = url; a.download = 'gdpr_export_guest_' + Date.now() + '.json'; a.click();
        URL.revokeObjectURL(url);
    }).catch(e => showMsg(e.message, false));
}

// Zapri modal s klikom izven
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModals(); });
});
</script>
</body>
</html>
