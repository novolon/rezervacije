<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';

if (!is_logged_in()) redirect_to_login();
if ($_SESSION['role'] === 'superadmin') {
    header('Location: ' . BASE_PATH . '/pages/superadmin.php'); exit;
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_PATH . '/pages/main.php'); exit;
}

$pdo = getDB();
refresh_subscription_session($pdo);

if (!empty($_SESSION['payment_failed'])) {
    require_once '../includes/payment_failed_block.php';
    exit;
}

$userId      = (int)$_SESSION['user_id'];
$currentPlan = $_SESSION['plan_slug'] ?? 'trial';

$stmt = $pdo->prepare("
    SELECT full_name, email, company_name, company_address, tax_number, is_vat_registered, vat_id,
           email_change_pending
    FROM users WHERE id=?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nastavitve profila – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
</head>
<body>
<header class="app-header">
    <a href="<?= BASE_PATH ?>/pages/main.php" class="header-logo">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
            <rect width="28" height="28" rx="7" fill="#F59E0B"/>
            <path d="M7 10h14M7 14h14M7 18h9" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?= h(APP_NAME) ?>
        <?= plan_badge($currentPlan) ?>
    </a>
    <div class="header-restaurant">
        <span style="color:rgba(255,255,255,.5);font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Nastavitve profila</span>
    </div>
    <div class="header-actions">
        <span class="header-user"><?= h($user['full_name']) ?></span>
        <a href="<?= BASE_PATH ?>/pages/main.php" class="btn-header btn-header-admin">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Razpored
        </a>
        <a href="<?= BASE_PATH ?>/logout.php" class="btn-header btn-header-logout">Odjava</a>
    </div>
</header>

<?php require_once '../includes/trial_banner.php'; ?>

<div class="admin-layout">
<div class="admin-content" style="max-width:640px">
    <h1 class="admin-page-title">Nastavitve profila</h1>

    <!-- ── 1. Podatki o podjetju ─────────────────────────────── -->
    <div class="admin-card" style="margin-bottom:20px">
        <div class="admin-card-header"><h2>Podatki o podjetju</h2></div>
        <div style="padding:20px 24px">
            <div id="company-error" class="form-error" style="display:none;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:.875rem;margin-bottom:16px"></div>
            <div class="admin-field" style="margin-bottom:14px">
                <label>Naziv podjetja / organizacije</label>
                <input type="text" id="p-company-name" value="<?= h($user['company_name'] ?? '') ?>" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:14px">
                <label>Naslov podjetja</label>
                <input type="text" id="p-company-address" value="<?= h($user['company_address'] ?? '') ?>" placeholder="Ulica 1, 1000 Ljubljana" style="width:100%;box-sizing:border-box">
            </div>
            <div style="display:flex;align-items:center;gap:9px;margin-bottom:14px">
                <input type="checkbox" id="p-is-vat" <?= $user['is_vat_registered'] ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#F59E0B;cursor:pointer;flex-shrink:0">
                <label for="p-is-vat" style="font-size:.875rem;font-weight:500;text-transform:none;letter-spacing:0;color:#374151;cursor:pointer;margin:0">Sem zavezanec za DDV</label>
            </div>
            <div class="admin-field" id="p-tax-group" style="margin-bottom:14px">
                <label>Davčna številka</label>
                <input type="text" id="p-tax-number" value="<?= h($user['tax_number'] ?? '') ?>" inputmode="numeric" maxlength="20" placeholder="12345678" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" id="p-vat-group" style="margin-bottom:14px;display:none">
                <label>ID za DDV</label>
                <input type="text" id="p-vat-id" value="<?= h($user['vat_id'] ?? '') ?>" placeholder="SI12345678" maxlength="30" style="width:100%;box-sizing:border-box;text-transform:uppercase">
            </div>
            <button class="btn btn-primary" id="company-save-btn" onclick="saveCompany()">Shrani</button>
        </div>
    </div>

    <!-- ── 2. Sprememba gesla ─────────────────────────────────── -->
    <div class="admin-card" style="margin-bottom:20px">
        <div class="admin-card-header"><h2>Sprememba gesla</h2></div>
        <div style="padding:20px 24px">
            <div id="pw-error" class="form-error" style="display:none;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:.875rem;margin-bottom:16px"></div>
            <div class="admin-field" style="margin-bottom:14px">
                <label>Trenutno geslo</label>
                <input type="password" id="pw-current" autocomplete="current-password" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:14px">
                <label>Novo geslo (min. 8 znakov)</label>
                <input type="password" id="pw-new" autocomplete="new-password" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:20px">
                <label>Potrdi novo geslo</label>
                <input type="password" id="pw-confirm" autocomplete="new-password" style="width:100%;box-sizing:border-box">
            </div>
            <button class="btn btn-primary" id="pw-save-btn" onclick="savePassword()">Spremeni geslo</button>
        </div>
    </div>

    <!-- ── 3. Sprememba emaila ─────────────────────────────────── -->
    <div class="admin-card" style="margin-bottom:40px">
        <div class="admin-card-header"><h2>Sprememba email naslova</h2></div>
        <div style="padding:20px 24px">
            <div style="font-size:.85rem;color:#6B7280;margin-bottom:16px">
                Trenutni email: <strong><?= h($user['email']) ?></strong>
                <?php if ($user['email_change_pending']): ?>
                <br><span style="color:#F59E0B;font-size:.8rem">&#9203; Čaka potrditev: <strong><?= h($user['email_change_pending']) ?></strong></span>
                <?php endif; ?>
            </div>
            <div id="email-error" class="form-error" style="display:none;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:.875rem;margin-bottom:16px"></div>
            <div class="admin-field" style="margin-bottom:14px">
                <label>Trenutno geslo</label>
                <input type="password" id="email-pw" autocomplete="current-password" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:20px">
                <label>Nov email naslov</label>
                <input type="email" id="email-new" autocomplete="email" style="width:100%;box-sizing:border-box">
            </div>
            <button class="btn btn-primary" id="email-save-btn" onclick="requestEmailChange()">Pošlji potrditveni link</button>
            <p style="font-size:.78rem;color:#9CA3AF;margin-top:10px">Na nov email naslov vam pošljemo potrditveni link. Email se spremeni šele po kliku na link.</p>
        </div>
    </div>

</div>
</div>

<div id="toast-container"></div>

<script>
window.APP_STATE = { base: '<?= BASE_PATH ?>' };

function toast(msg, type = 'success') {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity .3s'; setTimeout(()=>t.remove(),300); }, 3200);
}

function showError(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = 'block';
}
function hideError(id) {
    document.getElementById(id).style.display = 'none';
}

async function apiPost(action, data) {
    const res = await fetch(APP_STATE.base + '/api/profile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data }),
    });
    return res.json();
}

// ── DDV toggle ────────────────────────────────────────────────
(function() {
    const chk      = document.getElementById('p-is-vat');
    const taxGroup = document.getElementById('p-tax-group');
    const vatGroup = document.getElementById('p-vat-group');
    const taxInput = document.getElementById('p-tax-number');
    const vatInput = document.getElementById('p-vat-id');
    function toggle() {
        const isDDV = chk.checked;
        taxGroup.style.display = isDDV ? 'none' : 'block';
        vatGroup.style.display = isDDV ? 'block' : 'none';
    }
    chk.addEventListener('change', toggle);
    toggle();
    vatInput.addEventListener('input', () => { vatInput.value = vatInput.value.toUpperCase(); });
})();

// ── Shrani podjetje ───────────────────────────────────────────
async function saveCompany() {
    hideError('company-error');
    const btn = document.getElementById('company-save-btn');
    btn.disabled = true; btn.textContent = 'Shranjujem…';

    const isDDV = document.getElementById('p-is-vat').checked;
    try {
        const d = await apiPost('update_company', {
            company_name:    document.getElementById('p-company-name').value,
            company_address: document.getElementById('p-company-address').value,
            is_vat_registered: isDDV,
            tax_number: isDDV ? '' : document.getElementById('p-tax-number').value,
            vat_id:     isDDV ? document.getElementById('p-vat-id').value : '',
        });
        if (d.success) { toast(d.message || 'Shranjeno.'); }
        else { showError('company-error', d.error || 'Napaka.'); }
    } catch(e) { showError('company-error', 'Napaka pri povezavi.'); }
    finally { btn.disabled = false; btn.textContent = 'Shrani'; }
}

// ── Sprememba gesla ───────────────────────────────────────────
async function savePassword() {
    hideError('pw-error');
    const btn = document.getElementById('pw-save-btn');
    btn.disabled = true; btn.textContent = 'Spreminjam…';

    try {
        const d = await apiPost('change_password', {
            current_password: document.getElementById('pw-current').value,
            new_password:     document.getElementById('pw-new').value,
            confirm_password: document.getElementById('pw-confirm').value,
        });
        if (d.success) {
            toast(d.message || 'Geslo spremenjeno.');
            document.getElementById('pw-current').value = '';
            document.getElementById('pw-new').value = '';
            document.getElementById('pw-confirm').value = '';
        } else { showError('pw-error', d.error || 'Napaka.'); }
    } catch(e) { showError('pw-error', 'Napaka pri povezavi.'); }
    finally { btn.disabled = false; btn.textContent = 'Spremeni geslo'; }
}

// ── Zahteva za spremembo emaila ───────────────────────────────
async function requestEmailChange() {
    hideError('email-error');
    const btn = document.getElementById('email-save-btn');
    btn.disabled = true; btn.textContent = 'Pošiljam…';

    try {
        const d = await apiPost('request_email_change', {
            password:  document.getElementById('email-pw').value,
            new_email: document.getElementById('email-new').value,
        });
        if (d.success) {
            toast(d.message || 'Potrditveni link poslan.');
            document.getElementById('email-pw').value = '';
            document.getElementById('email-new').value = '';
        } else { showError('email-error', d.error || 'Napaka.'); }
    } catch(e) { showError('email-error', 'Napaka pri povezavi.'); }
    finally { btn.disabled = false; btn.textContent = 'Pošlji potrditveni link'; }
}
</script>
</body>
</html>
