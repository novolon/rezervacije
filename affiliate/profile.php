<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess  = require_affiliate();
$pdo   = getDB();
$affId = (int)$sess['id'];
$aff   = affiliate_get($pdo, $affId);

$pageTitle = t('aff.profile.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div class="rz-app" id="rz-app">
<?php require_once '_nav.php'; ?>

<main class="rz-main">
    <div class="rz-topbar">
        <div>
            <h1 class="rz-h1"><?= t('aff.profile.title') ?></h1>
            <p class="rz-top-sub"><?= t('aff.profile.subtitle') ?></p>
        </div>
    </div>

    <!-- Osebni podatki -->
    <div class="rz-card" style="margin-bottom:16px">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px"><?= t('aff.profile.personal_card_title') ?></h2>
        </div>
        <div id="profile-msg"></div>
        <form id="profile-form" class="rz-form">
            <div class="rz-form-2">
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.profile.full_name') ?></label>
                    <input type="text" id="full_name" name="full_name" class="rz-input" value="<?= htmlspecialchars($aff['full_name'] ?? '', ENT_QUOTES) ?>" required>
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.profile.email') ?></label>
                    <input type="email" id="email" name="email" class="rz-input" value="<?= htmlspecialchars($aff['email'] ?? '', ENT_QUOTES) ?>" required>
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.profile.legal_form') ?></label>
                    <select id="legal_form" name="legal_form" class="rz-input">
                        <option value="individual"  <?= ($aff['legal_form'] ?? '') === 'individual'  ? 'selected' : '' ?>><?= t('aff.profile.legal_individual') ?></option>
                        <option value="sole_trader" <?= ($aff['legal_form'] ?? '') === 'sole_trader' ? 'selected' : '' ?>><?= t('aff.profile.legal_sole_trader') ?></option>
                        <option value="company"     <?= ($aff['legal_form'] ?? '') === 'company'     ? 'selected' : '' ?>><?= t('aff.profile.legal_company') ?></option>
                        <option value="foreign"     <?= ($aff['legal_form'] ?? '') === 'foreign'     ? 'selected' : '' ?>><?= t('aff.profile.legal_foreign') ?></option>
                    </select>
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.profile.tax_number') ?></label>
                    <input type="text" id="tax_number" name="tax_number" class="rz-input" value="<?= htmlspecialchars($aff['tax_number'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="rz-field" style="grid-column:1/-1">
                    <label class="rz-field-label"><?= t('aff.profile.address') ?></label>
                    <input type="text" id="address" name="address" class="rz-input" value="<?= htmlspecialchars($aff['address'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="rz-field" style="grid-column:1/-1">
                    <label class="rz-field-label"><?= t('aff.profile.iban') ?></label>
                    <input type="text" id="iban" name="iban" class="rz-input" value="<?= htmlspecialchars($aff['iban'] ?? '', ENT_QUOTES) ?>" placeholder="SI56..." style="font-family:var(--font-mono)">
                </div>
            </div>
            <div>
                <button type="submit" class="rz-btn rz-btn-primary"><?= t('aff.profile.save') ?></button>
            </div>
        </form>
    </div>

    <!-- Sprememba gesla -->
    <div class="rz-card" style="margin-bottom:16px">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px"><?= t('aff.profile.password_card_title') ?></h2>
        </div>
        <div id="pw-msg"></div>
        <form id="pw-form" class="rz-form">
            <div class="rz-field">
                <label class="rz-field-label"><?= t('aff.profile.password_current') ?></label>
                <input type="password" id="current_password" name="current_password" class="rz-input" required autocomplete="current-password">
            </div>
            <div class="rz-form-2">
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.profile.password_new') ?></label>
                    <input type="password" id="new_password" name="new_password" class="rz-input" required autocomplete="new-password" minlength="8">
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.profile.password_confirm') ?></label>
                    <input type="password" id="new_password_confirm" class="rz-input" required autocomplete="new-password">
                </div>
            </div>
            <div>
                <button type="submit" class="rz-btn"><?= t('aff.profile.password_submit') ?></button>
            </div>
        </form>
    </div>

    <!-- Podatki o računu -->
    <div class="rz-card">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px"><?= t('aff.profile.account_card_title') ?></h2>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;font-size:13px">
            <div>
                <div style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:6px"><?= t('aff.profile.label_ref_code') ?></div>
                <code style="background:var(--accent-soft);border:1px solid color-mix(in oklab,var(--accent) 25%,transparent);padding:4px 10px;border-radius:6px;font-family:var(--font-mono);font-size:13px;font-weight:700;color:var(--accent)"><?= htmlspecialchars($aff['ref_code'] ?? '', ENT_QUOTES) ?></code>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:6px"><?= t('aff.profile.label_status') ?></div>
                <?php
                $st = $aff['status'] ?? '';
                $stMap = [
                    'active'    => '<span class="rz-chip rz-chip-confirmed">' . t('aff.profile.status_active') . '</span>',
                    'pending'   => '<span class="rz-chip" style="background:color-mix(in oklab,var(--warning) 15%,transparent);color:var(--warning);border-color:transparent">' . t('aff.profile.status_pending') . '</span>',
                    'suspended' => '<span class="rz-chip" style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border-color:transparent">' . t('aff.profile.status_suspended') . '</span>',
                ];
                echo $stMap[$st] ?? htmlspecialchars($st, ENT_QUOTES);
                ?>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:6px"><?= t('aff.profile.label_registered_at') ?></div>
                <span style="font-family:var(--font-mono)"><?= htmlspecialchars(substr($aff['created_at'] ?? '', 0, 10), ENT_QUOTES) ?></span>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:6px"><?= t('aff.profile.label_email_verified') ?></div>
                <?= $aff['email_verified_at']
                    ? '<span class="rz-chip rz-chip-confirmed">' . t('aff.profile.yes') . '</span>'
                    : '<span class="rz-chip rz-chip-mute">' . t('aff.profile.no') . '</span>' ?>
            </div>
        </div>
    </div>
</main>
</div>

<script>
const BASE = <?= json_encode(BASE_PATH) ?>;
const T = {
    saved:        <?= json_encode(t('aff.profile.saved'),         JSON_UNESCAPED_UNICODE) ?>,
    err_save:     <?= json_encode(t('aff.profile.err_save'),      JSON_UNESCAPED_UNICODE) ?>,
    err_generic:  <?= json_encode(t('aff.profile.err_save_generic'), JSON_UNESCAPED_UNICODE) ?>,
    pw_saved:     <?= json_encode(t('aff.profile.password_saved'), JSON_UNESCAPED_UNICODE) ?>,
    err_pwmatch:  <?= json_encode(t('aff.profile.err_pwmatch'),   JSON_UNESCAPED_UNICODE) ?>,
};

function showMsg(elId, text, ok) {
    const el = document.getElementById(elId);
    const col = ok ? 'var(--success)' : 'var(--danger)';
    const bg  = ok ? 'color-mix(in oklab,var(--success) 12%,transparent)' : 'color-mix(in oklab,var(--danger) 10%,transparent)';
    const bd  = ok ? 'color-mix(in oklab,var(--success) 25%,transparent)' : 'color-mix(in oklab,var(--danger) 25%,transparent)';
    el.innerHTML = `<div style="background:${bg};color:${col};border:1px solid ${bd};border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px">${text}</div>`;
    setTimeout(() => el.innerHTML = '', 4000);
}

document.getElementById('profile-form').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    body.action = 'update_profile';
    try {
        const r = await fetch(BASE + '/api/affiliate.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
        const d = await r.json();
        showMsg('profile-msg', d.success ? T.saved : (d.error ?? T.err_generic), d.success);
    } catch(err) {
        showMsg('profile-msg', T.err_save, false);
    }
});

document.getElementById('pw-form').addEventListener('submit', async e => {
    e.preventDefault();
    const np  = document.getElementById('new_password').value;
    const np2 = document.getElementById('new_password_confirm').value;
    if (np !== np2) { showMsg('pw-msg', T.err_pwmatch, false); return; }
    const body = {
        action: 'change_password',
        current_password: document.getElementById('current_password').value,
        new_password: np,
    };
    try {
        const r = await fetch(BASE + '/api/affiliate.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
        const d = await r.json();
        showMsg('pw-msg', d.success ? T.pw_saved : (d.error ?? T.err_generic), d.success);
        if (d.success) e.target.reset();
    } catch(err) {
        showMsg('pw-msg', T.err_save, false);
    }
});
</script>
</body>
</html>
