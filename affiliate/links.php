<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_auth.php';
require_once '../includes/affiliate_helper.php';
require_once '../includes/affiliate_session.php';

$sess = require_affiliate();
$pdo  = getDB();
$affId = (int)$sess['id'];

$aff = affiliate_get($pdo, $affId);
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Povezave & kode – Affiliate</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-layout">
<?php require_once '_nav.php'; ?>

<main class="aff-main">
    <div class="aff-topbar">
        <div class="aff-page-title">Povezave & kode</div>
    </div>

    <!-- Referenčna povezava -->
    <div class="aff-card">
        <div class="aff-card-title">Vaša referenčna povezava</div>
        <div class="aff-code-box">
            <span class="aff-code-text" id="ref-link">–</span>
            <button class="aff-copy-btn" onclick="copyEl('ref-link', this)">Kopiraj</button>
        </div>
        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
            <button class="aff-btn-sm" onclick="openQr()">QR koda</button>
            <button class="aff-btn-sm" onclick="document.getElementById('utm-section').style.display = document.getElementById('utm-section').style.display === 'none' ? '' : 'none'">UTM generator ↕</button>
        </div>
    </div>

    <!-- UTM Generator -->
    <div id="utm-section" style="display:none">
        <div class="aff-card">
            <div class="aff-card-title">UTM generator</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="aff-form-group" style="margin:0">
                    <label>Vir (utm_source)</label>
                    <input type="text" id="utm-source" placeholder="npr. instagram" oninput="buildUtm()">
                </div>
                <div class="aff-form-group" style="margin:0">
                    <label>Medij (utm_medium)</label>
                    <input type="text" id="utm-medium" placeholder="npr. story" oninput="buildUtm()">
                </div>
                <div class="aff-form-group" style="margin:0">
                    <label>Kampanja (utm_campaign)</label>
                    <input type="text" id="utm-campaign" placeholder="npr. poletje2025" oninput="buildUtm()">
                </div>
                <div class="aff-form-group" style="margin:0">
                    <label>Vsebina (utm_content)</label>
                    <input type="text" id="utm-content" placeholder="neobvezno" oninput="buildUtm()">
                </div>
            </div>
            <div class="aff-code-box" style="margin-top:14px">
                <span class="aff-code-text" id="utm-link" style="font-size:.78rem">–</span>
                <button class="aff-copy-btn" onclick="copyEl('utm-link', this)">Kopiraj</button>
            </div>
        </div>
    </div>

    <!-- Popustna koda -->
    <div id="discount-section" style="display:none">
        <div class="aff-card">
            <div class="aff-card-title">Vaša popustna koda</div>
            <div class="aff-code-box" style="background:#ECFDF5;border-color:#6EE7B7">
                <span class="aff-code-text" id="discount-code" style="color:#065F46">–</span>
                <button class="aff-copy-btn" style="border-color:#6EE7B7;color:#065F46" onclick="copyEl('discount-code', this)">Kopiraj kodo</button>
            </div>
            <p style="margin:10px 0 4px;font-size:.82rem;color:var(--aff-ink-mute)">Kombinirani link (atribucija + popust):</p>
            <div class="aff-code-box">
                <span class="aff-code-text" id="combo-link" style="font-size:.78rem">–</span>
                <button class="aff-copy-btn" onclick="copyEl('combo-link', this)">Kopiraj link</button>
            </div>
            <p style="margin:10px 0 0;font-size:.82rem;color:var(--aff-ink-mute)" id="discount-desc">–</p>
        </div>
    </div>

    <!-- Marketinški materiali -->
    <div class="aff-card">
        <div class="aff-card-title">Marketinški materiali</div>
        <p style="color:var(--aff-ink-mute);font-size:.88rem;margin:0 0 16px">Priporočeni teksti za objave na socialnih omrežjih in email sporočila.</p>
        <div style="display:flex;flex-direction:column;gap:12px">
            <div class="aff-material-block">
                <div class="aff-material-label">Kratka objava (social media)</div>
                <div class="aff-material-text" id="txt-short">–</div>
                <button class="aff-btn-sm" onclick="copyEl('txt-short', this)">Kopiraj</button>
            </div>
            <div class="aff-material-block">
                <div class="aff-material-label">Daljša objava / email</div>
                <div class="aff-material-text" id="txt-long">–</div>
                <button class="aff-btn-sm" onclick="copyEl('txt-long', this)">Kopiraj</button>
            </div>
        </div>
    </div>
</main>
</div>

<!-- QR modal -->
<div id="qr-modal" class="aff-modal-overlay" style="display:none" onclick="if(event.target===this)closeQr()">
    <div class="aff-modal-box" style="text-align:center;max-width:340px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <strong>QR koda</strong>
            <button onclick="closeQr()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--aff-ink-mute)">✕</button>
        </div>
        <canvas id="qr-canvas" style="width:200px;height:200px;image-rendering:pixelated"></canvas>
        <p style="font-size:.8rem;color:var(--aff-ink-mute);margin:12px 0 0">Shranite sliko z desnim klikom → Shrani sliko</p>
    </div>
</div>

<script>
const BASE   = <?= json_encode(BASE_PATH) ?>;
const REF    = <?= json_encode($aff['ref_code'] ?? '') ?>;
const APPURL = <?= json_encode(APP_URL . BASE_PATH) ?>;
const REF_LINK = APPURL + '/?ref=' + REF;

document.getElementById('ref-link').textContent = REF_LINK;

// UTM builder
function buildUtm() {
    const src  = document.getElementById('utm-source').value.trim();
    const med  = document.getElementById('utm-medium').value.trim();
    const cam  = document.getElementById('utm-campaign').value.trim();
    const con  = document.getElementById('utm-content').value.trim();
    let url = REF_LINK;
    const params = [];
    if (src) params.push('utm_source=' + encodeURIComponent(src));
    if (med) params.push('utm_medium=' + encodeURIComponent(med));
    if (cam) params.push('utm_campaign=' + encodeURIComponent(cam));
    if (con) params.push('utm_content=' + encodeURIComponent(con));
    if (params.length) url += '&' + params.join('&');
    document.getElementById('utm-link').textContent = url;
}

// Copy helper
function copyEl(id, btn) {
    const text = document.getElementById(id).textContent;
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Kopirano!';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

// Discount
async function loadDiscount() {
    try {
        const r = await fetch(BASE + '/api/affiliate.php?action=discount_stats');
        const d = await r.json();
        if (!d.success || !d.data.enabled || !d.data.is_active) return;
        document.getElementById('discount-section').style.display = '';
        document.getElementById('discount-code').textContent = d.data.code;
        const combo = REF_LINK + '&code=' + d.data.code;
        document.getElementById('combo-link').textContent = combo;
        const pct = d.data.percent_off;
        const dur = d.data.duration === 'repeating'
            ? pct + '% popust za ' + d.data.duration_months + ' mesecev'
            : pct + '% popust';
        document.getElementById('discount-desc').textContent = 'Vaši priporočenci dobijo: ' + dur + '.';
        buildTexts(d.data.code, pct, combo);
    } catch(e) {}
}

function buildTexts(code, pct, comboLink) {
    const short = `Upravljajte rezervacije za vaš lokal brez headacheov 🍽️\nPrvi mesec z ${pct}% popustom z mojo kodo: ${code}\n${comboLink}`;
    const long  = `Hej!\n\nČe vodiš restavracijo, gostilno ali bar – Rezervacije.si ti omogočajo enostavno upravljanje rezervacij, miz in gostov.\n\nZ mojo priporočilno kodo dobiš ${pct}% popust na naročnino.\n\nPoklopi link in si oglej: ${comboLink}\n\nLep pozdrav`;
    document.getElementById('txt-short').textContent = short;
    document.getElementById('txt-long').textContent  = long;
}

// QR code (simple URL → QR via Google Charts API)
function openQr() {
    document.getElementById('qr-modal').style.display = 'flex';
    const canvas = document.getElementById('qr-canvas');
    const ctx = canvas.getContext('2d');
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => { canvas.width = img.width; canvas.height = img.height; ctx.drawImage(img, 0, 0); };
    img.src = 'https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=' + encodeURIComponent(REF_LINK) + '&choe=UTF-8';
}
function closeQr() { document.getElementById('qr-modal').style.display = 'none'; }

loadDiscount();
buildTexts('', 0, REF_LINK);
</script>
</body>
</html>
