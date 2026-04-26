<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess  = require_affiliate();
$pdo   = getDB();
$affId = (int)$sess['id'];

$aff = affiliate_get($pdo, $affId);
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Profil – Affiliate</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-layout">
<?php require_once '_nav.php'; ?>

<main class="aff-main">
    <div class="aff-topbar">
        <div class="aff-page-title">Profil</div>
    </div>

    <!-- Profile info -->
    <div class="aff-card">
        <div class="aff-card-title">Osebni podatki & bančne informacije</div>
        <div id="profile-msg"></div>
        <form id="profile-form">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="aff-form-group">
                    <label for="full_name">Ime in priimek</label>
                    <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($aff['full_name'] ?? '', ENT_QUOTES) ?>" required>
                </div>
                <div class="aff-form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($aff['email'] ?? '', ENT_QUOTES) ?>" required>
                </div>
                <div class="aff-form-group">
                    <label for="legal_form">Pravna oblika</label>
                    <select id="legal_form" name="legal_form">
                        <option value="individual" <?= ($aff['legal_form'] ?? '') === 'individual' ? 'selected' : '' ?>>Fizična oseba</option>
                        <option value="sole_trader" <?= ($aff['legal_form'] ?? '') === 'sole_trader' ? 'selected' : '' ?>>S.P.</option>
                        <option value="company" <?= ($aff['legal_form'] ?? '') === 'company' ? 'selected' : '' ?>>Podjetje (d.o.o. / d.d.)</option>
                    </select>
                </div>
                <div class="aff-form-group">
                    <label for="tax_number">Davčna številka</label>
                    <input type="text" id="tax_number" name="tax_number" value="<?= htmlspecialchars($aff['tax_number'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="aff-form-group" style="grid-column:1/-1">
                    <label for="address">Naslov</label>
                    <input type="text" id="address" name="address" value="<?= htmlspecialchars($aff['address'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="aff-form-group" style="grid-column:1/-1">
                    <label for="iban">IBAN (za izplačila)</label>
                    <input type="text" id="iban" name="iban" value="<?= htmlspecialchars($aff['iban'] ?? '', ENT_QUOTES) ?>" placeholder="SI56XXXXXXXXXXXXXXXX" style="font-family:monospace">
                </div>
            </div>
            <div style="margin-top:8px">
                <button type="submit" class="aff-btn" style="max-width:200px">Shrani spremembe</button>
            </div>
        </form>
    </div>

    <!-- Change password -->
    <div class="aff-card" style="margin-top:20px">
        <div class="aff-card-title">Sprememba gesla</div>
        <div id="pw-msg"></div>
        <form id="pw-form">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="aff-form-group" style="grid-column:1/-1">
                    <label for="current_password">Trenutno geslo</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="aff-form-group">
                    <label for="new_password">Novo geslo</label>
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="8">
                </div>
                <div class="aff-form-group">
                    <label for="new_password_confirm">Potrdi novo geslo</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm" required autocomplete="new-password">
                </div>
            </div>
            <div style="margin-top:8px">
                <button type="submit" class="aff-btn" style="max-width:200px">Spremeni geslo</button>
            </div>
        </form>
    </div>

    <!-- Account info -->
    <div class="aff-card" style="margin-top:20px">
        <div class="aff-card-title">Podatki o računu</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:.88rem">
            <div>
                <div style="color:var(--aff-ink-mute);margin-bottom:4px">Referenčna koda</div>
                <code style="background:#F3F4F6;padding:3px 8px;border-radius:4px"><?= htmlspecialchars($aff['ref_code'] ?? '', ENT_QUOTES) ?></code>
            </div>
            <div>
                <div style="color:var(--aff-ink-mute);margin-bottom:4px">Status</div>
                <?php
                $st = $aff['status'] ?? '';
                $stMap = ['active'=>'<span class="aff-badge aff-badge--success">Aktiven</span>','pending'=>'<span class="aff-badge aff-badge--warning">V pregledu</span>','suspended'=>'<span class="aff-badge aff-badge--danger">Suspendiran</span>'];
                echo $stMap[$st] ?? htmlspecialchars($st, ENT_QUOTES);
                ?>
            </div>
            <div>
                <div style="color:var(--aff-ink-mute);margin-bottom:4px">Datum registracije</div>
                <?= htmlspecialchars(substr($aff['created_at'] ?? '', 0, 10), ENT_QUOTES) ?>
            </div>
            <div>
                <div style="color:var(--aff-ink-mute);margin-bottom:4px">Email potrjen</div>
                <?= $aff['email_verified_at'] ? '<span class="aff-badge aff-badge--success">Da</span>' : '<span class="aff-badge aff-badge--muted">Ne</span>' ?>
            </div>
        </div>
    </div>

    <div style="margin-top:16px;text-align:right">
        <a href="<?= BASE_PATH ?>/affiliate/logout.php" class="aff-btn-sm" style="color:var(--aff-danger)">Odjava</a>
    </div>
</main>
</div>

<script>
const BASE = <?= json_encode(BASE_PATH) ?>;

document.getElementById('profile-form').addEventListener('submit', async e => {
    e.preventDefault();
    const msg = document.getElementById('profile-msg');
    msg.innerHTML = '';
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    body.action = 'update_profile';
    try {
        const r = await fetch(BASE + '/api/affiliate.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
        const d = await r.json();
        if (d.success) {
            msg.innerHTML = '<div class="aff-success">Podatki shranjeni.</div>';
        } else {
            msg.innerHTML = '<div class="aff-error">' + (d.error ?? 'Napaka.') + '</div>';
        }
    } catch(err) {
        msg.innerHTML = '<div class="aff-error">Napaka pri shranjevanju.</div>';
    }
    setTimeout(() => msg.innerHTML = '', 4000);
});

document.getElementById('pw-form').addEventListener('submit', async e => {
    e.preventDefault();
    const msg = document.getElementById('pw-msg');
    msg.innerHTML = '';
    const np  = document.getElementById('new_password').value;
    const np2 = document.getElementById('new_password_confirm').value;
    if (np !== np2) { msg.innerHTML = '<div class="aff-error">Gesli se ne ujemata.</div>'; return; }
    const body = {
        action: 'change_password',
        current_password: document.getElementById('current_password').value,
        new_password: np,
    };
    try {
        const r = await fetch(BASE + '/api/affiliate.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
        const d = await r.json();
        if (d.success) {
            msg.innerHTML = '<div class="aff-success">Geslo spremenjeno.</div>';
            e.target.reset();
        } else {
            msg.innerHTML = '<div class="aff-error">' + (d.error ?? 'Napaka.') + '</div>';
        }
    } catch(err) {
        msg.innerHTML = '<div class="aff-error">Napaka.</div>';
    }
    setTimeout(() => msg.innerHTML = '', 4000);
});
</script>
</body>
</html>
