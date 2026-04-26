<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess = require_affiliate();
$pdo  = getDB();
$affId = (int)$sess['id'];

// Pridobi affiliate podatke
$aff = affiliate_get($pdo, $affId);
$isPending = $aff && $aff['status'] === 'pending';
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard – Affiliate</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-layout">
<?php require_once '_nav.php'; ?>

<main class="aff-main">
    <div class="aff-topbar">
        <div class="aff-page-title">Pregled</div>
    </div>

    <?php if ($isPending): ?>
    <div class="aff-pending-banner">
        <h2>⏳ Čakamo na odobritev</h2>
        <p>Vaša prijava je pod pregledom. Ko jo odobrimo, boste prejeli email in dostop do polnega dashboarda.</p>
    </div>
    <?php else: ?>

    <!-- Statistike -->
    <div class="aff-stats-grid" id="stats-grid">
        <div class="aff-stat-card"><div class="aff-stat-label">Kliki (30 dni)</div><div class="aff-stat-val" id="stat-clicks">–</div></div>
        <div class="aff-stat-card"><div class="aff-stat-label">Registracije</div><div class="aff-stat-val" id="stat-referrals">–</div></div>
        <div class="aff-stat-card"><div class="aff-stat-label">Aktivni plačniki</div><div class="aff-stat-val" id="stat-converted">–</div></div>
        <div class="aff-stat-card"><div class="aff-stat-label">Skupaj zasluženo</div><div class="aff-stat-val green" id="stat-earned">–</div></div>
        <div class="aff-stat-card"><div class="aff-stat-label">Payable (takoj)</div><div class="aff-stat-val green" id="stat-payable">–</div></div>
    </div>

    <!-- Ref koda -->
    <div class="aff-card">
        <div class="aff-card-title">Vaša referenčna koda</div>
        <div class="aff-code-box">
            <span class="aff-code-text" id="ref-code"><?= htmlspecialchars($aff['ref_code'] ?? '', ENT_QUOTES) ?></span>
            <button class="aff-copy-btn" onclick="copyText('<?= BASE_PATH ?>/?ref=<?= urlencode($aff['ref_code'] ?? '') ?>', this)">Kopiraj link</button>
        </div>
        <p style="margin:10px 0 0;font-size:.82rem;color:var(--aff-ink-mute)">
            Referenčna povezava: <code style="background:#F3F4F6;padding:2px 6px;border-radius:4px;font-size:.82rem"><?= APP_URL . BASE_PATH ?>/?ref=<?= htmlspecialchars($aff['ref_code'] ?? '', ENT_QUOTES) ?></code>
        </p>
    </div>

    <!-- Discount koda (če je aktivna) -->
    <div id="discount-section" style="display:none">
        <div class="aff-card">
            <div class="aff-card-title">Vaša popustna koda</div>
            <div class="aff-code-box" style="background:#ECFDF5;border-color:#6EE7B7">
                <span class="aff-code-text" id="discount-code" style="color:#065F46">–</span>
                <button class="aff-copy-btn" style="border-color:#6EE7B7;color:#065F46" onclick="copyDiscountCode(this)">Kopiraj link</button>
            </div>
            <p style="margin:10px 0 0;font-size:.82rem;color:var(--aff-ink-mute)">
                Kombinirani link (atribucija + popust): <code style="background:#F3F4F6;padding:2px 6px;border-radius:4px;font-size:.82rem" id="combo-link">–</code>
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px">
                <div class="aff-stat-card" style="padding:14px"><div class="aff-stat-label">Unovčenj</div><div class="aff-stat-val" id="disc-redemptions">–</div></div>
                <div class="aff-stat-card" style="padding:14px"><div class="aff-stat-label">Skupni popust</div><div class="aff-stat-val" id="disc-total-off">–</div></div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</main>
</div>

<script>
const BASE = <?= json_encode(BASE_PATH) ?>;
const REF  = <?= json_encode($aff['ref_code'] ?? '') ?>;
const APPURL = <?= json_encode(APP_URL . BASE_PATH) ?>;

async function loadStats() {
    try {
        const r = await fetch(BASE + '/api/affiliate.php?action=stats&days=30');
        const d = await r.json();
        if (!d.success) return;
        document.getElementById('stat-clicks').textContent    = d.data.clicks;
        document.getElementById('stat-referrals').textContent = d.data.referrals;
        document.getElementById('stat-converted').textContent = d.data.converted;
        document.getElementById('stat-earned').textContent    = d.data.total_earned_eur.toFixed(2) + ' €';
        document.getElementById('stat-payable').textContent   = d.data.payable_eur.toFixed(2) + ' €';
    } catch(e) {}
}

async function loadDiscount() {
    try {
        const r = await fetch(BASE + '/api/affiliate.php?action=discount_stats');
        const d = await r.json();
        if (!d.success || !d.data.enabled || !d.data.is_active) return;
        document.getElementById('discount-section').style.display = '';
        document.getElementById('discount-code').textContent = d.data.code;
        const combo = APPURL + '/?ref=' + REF + '&code=' + d.data.code;
        document.getElementById('combo-link').textContent = combo;
        document.getElementById('disc-redemptions').textContent = d.data.redemptions;
        document.getElementById('disc-total-off').textContent   = d.data.total_off_eur.toFixed(2) + ' €';
    } catch(e) {}
}

function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Kopirano!';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

function copyDiscountCode(btn) {
    const link = document.getElementById('combo-link').textContent;
    copyText(link, btn);
}

<?php if (!$isPending): ?>
loadStats();
loadDiscount();
<?php endif; ?>
</script>
</body>
</html>
