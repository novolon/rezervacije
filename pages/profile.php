<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/plans.php';
require_once '../includes/lang.php';

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
$fullName    = $_SESSION['full_name'] ?? '';
$isAdmin     = true;

$stmt = $pdo->prepare("
    SELECT r.id, r.name, r.color FROM restaurants r
    JOIN restaurant_admins ra ON r.id = ra.restaurant_id
    WHERE ra.user_id = ? AND r.is_active = 1 ORDER BY r.name
");
$stmt->execute([$userId]);
$restaurants = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reservations r
    JOIN restaurant_admins ra ON r.restaurant_id = ra.restaurant_id
    WHERE ra.user_id = ? AND r.status = 'pending'
");
$stmt->execute([$userId]);
$pendingCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT full_name, email, company_name, company_address, tax_number, is_vat_registered, vat_id,
           email_change_pending
    FROM users WHERE id=?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('profile.page_title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/rezble.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/design.css?v=1">
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
    <script src="<?= BASE_PATH ?>/assets/js/i18n.js?v=1"></script>
</head>
<body>

<div id="rz-app" class="rz-app">
<?php require_once '../includes/sidebar.php'; ?>
<main class="rz-main">
<?php require_once '../includes/trial_banner.php'; ?>

<div class="admin-layout">
<div class="admin-content" style="max-width:640px">
    <h1 class="admin-page-title"><?= t('profile.title') ?></h1>

    <!-- ── 1. Podatki o podjetju ─────────────────────────────── -->
    <div class="admin-card" style="margin-bottom:20px">
        <div class="admin-card-header"><h2><?= t('profile.company_section') ?></h2></div>
        <div style="padding:20px 24px">
            <div id="company-error" class="form-error" style="display:none;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:.875rem;margin-bottom:16px"></div>
            <div class="admin-field" style="margin-bottom:14px">
                <label><?= t('profile.company_name') ?></label>
                <input type="text" id="p-company-name" value="<?= h($user['company_name'] ?? '') ?>" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:14px">
                <label><?= t('profile.company_address') ?></label>
                <input type="text" id="p-company-address" value="<?= h($user['company_address'] ?? '') ?>" placeholder="<?= t('profile.company_address_placeholder') ?>" style="width:100%;box-sizing:border-box">
            </div>
            <div style="display:flex;align-items:center;gap:9px;margin-bottom:14px">
                <input type="checkbox" id="p-is-vat" <?= $user['is_vat_registered'] ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#F59E0B;cursor:pointer;flex-shrink:0">
                <label for="p-is-vat" style="font-size:.875rem;font-weight:500;text-transform:none;letter-spacing:0;color:#374151;cursor:pointer;margin:0"><?= t('profile.is_vat') ?></label>
            </div>
            <div class="admin-field" id="p-tax-group" style="margin-bottom:14px">
                <label><?= t('profile.tax_number') ?></label>
                <input type="text" id="p-tax-number" value="<?= h($user['tax_number'] ?? '') ?>" inputmode="numeric" maxlength="20" placeholder="<?= t('profile.tax_placeholder') ?>" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" id="p-vat-group" style="margin-bottom:14px;display:none">
                <label><?= t('profile.vat_id') ?></label>
                <input type="text" id="p-vat-id" value="<?= h($user['vat_id'] ?? '') ?>" placeholder="<?= t('profile.vat_placeholder') ?>" maxlength="30" style="width:100%;box-sizing:border-box;text-transform:uppercase">
            </div>
            <button class="btn btn-primary" id="company-save-btn" onclick="saveCompany()"><?= t('profile.save') ?></button>
        </div>
    </div>

    <!-- ── 2. Sprememba gesla ─────────────────────────────────── -->
    <div class="admin-card" style="margin-bottom:20px">
        <div class="admin-card-header"><h2><?= t('profile.password_section') ?></h2></div>
        <div style="padding:20px 24px">
            <div id="pw-error" class="form-error" style="display:none;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:.875rem;margin-bottom:16px"></div>
            <div class="admin-field" style="margin-bottom:14px">
                <label><?= t('profile.current_password') ?></label>
                <input type="password" id="pw-current" autocomplete="current-password" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:14px">
                <label><?= t('profile.new_password') ?></label>
                <input type="password" id="pw-new" autocomplete="new-password" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:20px">
                <label><?= t('profile.confirm_password') ?></label>
                <input type="password" id="pw-confirm" autocomplete="new-password" style="width:100%;box-sizing:border-box">
            </div>
            <button class="btn btn-primary" id="pw-save-btn" onclick="savePassword()"><?= t('profile.change_password_btn') ?></button>
        </div>
    </div>

    <!-- ── 3. Jezik vmesnika ─────────────────────────────────────── -->
    <div class="admin-card" style="margin-bottom:20px">
        <div class="admin-card-header"><h2><?= t('profile.lang_section') ?></h2></div>
        <div style="padding:20px 24px">
            <p style="font-size:.875rem;color:#6B7280;margin:0 0 14px"><?= t('profile.lang_desc') ?></p>
            <div style="display:flex;gap:10px">
                <?php $curLang = get_lang(); ?>
                <a href="?lang=sl" class="btn <?= $curLang === 'sl' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇸🇮</span> Slovenščina
                </a>
                <a href="?lang=en" class="btn <?= $curLang === 'en' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇬🇧</span> English
                </a>
                <a href="?lang=de" class="btn <?= $curLang === 'de' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇩🇪</span> Deutsch
                </a>
                <a href="?lang=it" class="btn <?= $curLang === 'it' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇮🇹</span> Italiano
                </a>
                <a href="?lang=fr" class="btn <?= $curLang === 'fr' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇫🇷</span> Français
                </a>
                <a href="?lang=hr" class="btn <?= $curLang === 'hr' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇭🇷</span> Hrvatski
                </a>
                <a href="?lang=es" class="btn <?= $curLang === 'es' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇪🇸</span> Español
                </a>
                <a href="?lang=pt" class="btn <?= $curLang === 'pt' ? 'btn-primary' : 'btn-secondary' ?>"
                   style="text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                    <span>🇵🇹</span> Português
                </a>
            </div>
        </div>
    </div>

    <!-- ── 4. Sprememba emaila ─────────────────────────────────── -->
    <div class="admin-card" style="margin-bottom:40px">
        <div class="admin-card-header"><h2><?= t('profile.email_section') ?></h2></div>
        <div style="padding:20px 24px">
            <div style="font-size:.85rem;color:#6B7280;margin-bottom:16px">
                <?= t('profile.current_email') ?> <strong><?= h($user['email']) ?></strong>
                <?php if ($user['email_change_pending']): ?>
                <br><span style="color:#F59E0B;font-size:.8rem">&#9203; <?= t('profile.pending_email') ?> <strong><?= h($user['email_change_pending']) ?></strong></span>
                <?php endif; ?>
            </div>
            <div id="email-error" class="form-error" style="display:none;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:.875rem;margin-bottom:16px"></div>
            <div class="admin-field" style="margin-bottom:14px">
                <label><?= t('profile.email_password') ?></label>
                <input type="password" id="email-pw" autocomplete="current-password" style="width:100%;box-sizing:border-box">
            </div>
            <div class="admin-field" style="margin-bottom:20px">
                <label><?= t('profile.new_email') ?></label>
                <input type="email" id="email-new" autocomplete="email" style="width:100%;box-sizing:border-box">
            </div>
            <button class="btn btn-primary" id="email-save-btn" onclick="requestEmailChange()"><?= t('profile.send_confirm_btn') ?></button>
            <p style="font-size:.78rem;color:#9CA3AF;margin-top:10px"><?= t('profile.email_note') ?></p>
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
    btn.disabled = true; btn.textContent = window.t('profile.saving');

    const isDDV = document.getElementById('p-is-vat').checked;
    try {
        const d = await apiPost('update_company', {
            company_name:    document.getElementById('p-company-name').value,
            company_address: document.getElementById('p-company-address').value,
            is_vat_registered: isDDV,
            tax_number: isDDV ? '' : document.getElementById('p-tax-number').value,
            vat_id:     isDDV ? document.getElementById('p-vat-id').value : '',
        });
        if (d.success) { toast(d.message || window.t('profile.saved')); }
        else { showError('company-error', d.error || window.t('common.error')); }
    } catch(e) { showError('company-error', window.t('profile.err_connection')); }
    finally { btn.disabled = false; btn.textContent = window.t('profile.save'); }
}

// ── Sprememba gesla ───────────────────────────────────────────
async function savePassword() {
    hideError('pw-error');
    const btn = document.getElementById('pw-save-btn');
    btn.disabled = true; btn.textContent = window.t('profile.changing_password');

    try {
        const d = await apiPost('change_password', {
            current_password: document.getElementById('pw-current').value,
            new_password:     document.getElementById('pw-new').value,
            confirm_password: document.getElementById('pw-confirm').value,
        });
        if (d.success) {
            toast(d.message || window.t('profile.password_changed'));
            document.getElementById('pw-current').value = '';
            document.getElementById('pw-new').value = '';
            document.getElementById('pw-confirm').value = '';
        } else { showError('pw-error', d.error || window.t('common.error')); }
    } catch(e) { showError('pw-error', window.t('profile.err_connection')); }
    finally { btn.disabled = false; btn.textContent = window.t('profile.change_password_btn'); }
}

// ── Zahteva za spremembo emaila ───────────────────────────────
async function requestEmailChange() {
    hideError('email-error');
    const btn = document.getElementById('email-save-btn');
    btn.disabled = true; btn.textContent = window.t('profile.sending_confirm');

    try {
        const d = await apiPost('request_email_change', {
            password:  document.getElementById('email-pw').value,
            new_email: document.getElementById('email-new').value,
        });
        if (d.success) {
            toast(d.message || window.t('profile.confirm_link_sent'));
            document.getElementById('email-pw').value = '';
            document.getElementById('email-new').value = '';
        } else { showError('email-error', d.error || window.t('common.error')); }
    } catch(e) { showError('email-error', window.t('profile.err_connection')); }
    finally { btn.disabled = false; btn.textContent = window.t('profile.send_confirm_btn'); }
}
</script>
</main>
</div><!-- /rz-app -->
</body>
</html>
