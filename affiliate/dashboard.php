<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess  = require_affiliate();
$pdo   = getDB();
$affId = (int)$sess['id'];
$aff   = affiliate_get($pdo, $affId);
$isPending = $aff && $aff['status'] === 'pending';

$pageTitle = 'Pregled – Affiliate';
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div class="rz-app" id="rz-app">
<?php require_once '_nav.php'; ?>

<main class="rz-main">
    <div class="rz-topbar">
        <div>
            <h1 class="rz-h1">Pregled</h1>
            <p class="rz-top-sub">Vaš affiliate dashboard</p>
        </div>
    </div>

    <?php if ($isPending): ?>
    <div class="rz-card" style="text-align:center;padding:40px;border:1.5px dashed var(--warning)">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:14px"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <h2 style="margin:0 0 8px;font-size:18px;font-weight:700;color:var(--ink)">Čakamo na odobritev</h2>
        <p style="margin:0;color:var(--ink-mute);font-size:14px;max-width:360px;margin:0 auto">Vaša prijava je pod pregledom. Ko jo odobrimo, boste prejeli email in dostop do polnega dashboarda.</p>
    </div>
    <?php else: ?>

    <!-- KPI pas -->
    <div class="rz-kpis" style="grid-template-columns:repeat(5,1fr)" id="kpis">
        <div class="rz-kpi"><div class="rz-kpi-label">Kliki (30 dni)</div><div class="rz-kpi-value" id="stat-clicks">–</div></div>
        <div class="rz-kpi"><div class="rz-kpi-label">Registracije</div><div class="rz-kpi-value" id="stat-referrals">–</div></div>
        <div class="rz-kpi"><div class="rz-kpi-label">Aktivni plačniki</div><div class="rz-kpi-value" id="stat-converted">–</div></div>
        <div class="rz-kpi is-accent"><div class="rz-kpi-label">Skupaj zasluženo</div><div class="rz-kpi-value" id="stat-earned">–</div></div>
        <div class="rz-kpi is-accent"><div class="rz-kpi-label">Izplačljivo</div><div class="rz-kpi-value" id="stat-payable">–</div></div>
    </div>

    <!-- Ref koda -->
    <div class="rz-card">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px">Vaša referenčna koda</h2>
        </div>
        <div style="background:var(--accent-soft);border:1px solid color-mix(in oklab,var(--accent) 30%,transparent);border-radius:10px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px">
            <span style="font-size:1.4rem;font-weight:800;letter-spacing:.12em;color:var(--accent);font-family:var(--font-mono)" id="ref-code"><?= htmlspecialchars($aff['ref_code'] ?? '', ENT_QUOTES) ?></span>
            <button class="rz-btn" onclick="copyText('<?= APP_URL . BASE_PATH ?>/?ref=<?= urlencode($aff['ref_code'] ?? '') ?>', this)" style="white-space:nowrap;flex-shrink:0">Kopiraj link</button>
        </div>
        <p style="margin:10px 0 0;font-size:12px;color:var(--ink-mute)">
            Vaša referenčna povezava:
            <code style="background:var(--bg-sunken);border:1px solid var(--line);padding:2px 7px;border-radius:5px;font-family:var(--font-mono);font-size:11px"><?= htmlspecialchars(APP_URL . BASE_PATH . '/?ref=' . ($aff['ref_code'] ?? ''), ENT_QUOTES) ?></code>
        </p>
    </div>

    <!-- Popustna koda -->
    <div id="discount-section" style="display:none">
        <div class="rz-card">
            <div class="rz-card-head">
                <h2 class="rz-card-title" style="font-size:15px">Vaša popustna koda za stranke</h2>
            </div>
            <div style="background:color-mix(in oklab,var(--success) 10%,transparent);border:1px solid color-mix(in oklab,var(--success) 25%,transparent);border-radius:10px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px">
                <span style="font-size:1.4rem;font-weight:800;letter-spacing:.12em;color:var(--success);font-family:var(--font-mono)" id="discount-code">–</span>
                <button class="rz-btn" onclick="copyDiscountCode(this)" style="flex-shrink:0">Kopiraj kombinirani link</button>
            </div>
            <p style="margin:10px 0 10px;font-size:12px;color:var(--ink-mute)">
                Kombinirani link (atribucija + popust): <code style="background:var(--bg-sunken);border:1px solid var(--line);padding:2px 7px;border-radius:5px;font-family:var(--font-mono);font-size:11px" id="combo-link">–</code>
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:4px">
                <div class="rz-kpi" style="padding:14px 16px"><div class="rz-kpi-label">Unovčenj</div><div class="rz-kpi-value" style="font-size:20px" id="disc-redemptions">–</div></div>
                <div class="rz-kpi" style="padding:14px 16px"><div class="rz-kpi-label">Skupni popust</div><div class="rz-kpi-value" style="font-size:20px" id="disc-total-off">–</div></div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</main>
</div>

<script>
const BASE   = <?= json_encode(BASE_PATH) ?>;
const REF    = <?= json_encode($aff['ref_code'] ?? '') ?>;
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
    copyText(document.getElementById('combo-link').textContent, btn);
}

<?php if (!$isPending): ?>
loadStats();
loadDiscount();
<?php endif; ?>
</script>
</body>
</html>
