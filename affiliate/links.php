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

$pageTitle = t('aff.links.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div class="rz-app" id="rz-app">
<?php require_once '_nav.php'; ?>

<main class="rz-main">
    <div class="rz-topbar">
        <div>
            <h1 class="rz-h1"><?= t('aff.links.title') ?></h1>
            <p class="rz-top-sub"><?= t('aff.links.subtitle') ?></p>
        </div>
    </div>

    <!-- Referenčna povezava -->
    <div class="rz-card" style="margin-bottom:16px">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px"><?= t('aff.links.ref_card_title') ?></h2>
        </div>
        <div style="background:var(--accent-soft);border:1px solid color-mix(in oklab,var(--accent) 30%,transparent);border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
            <span style="flex:1;font-size:13px;font-family:var(--font-mono);color:var(--ink);word-break:break-all" id="ref-link">–</span>
            <button class="rz-btn" onclick="copyEl('ref-link', this)" style="white-space:nowrap;flex-shrink:0"><?= t('aff.links.copy') ?></button>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
            <button class="rz-btn" onclick="openQr()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="3" height="3"/><rect x="18" y="14" width="3" height="3"/><rect x="18" y="18" width="3" height="3"/><rect x="14" y="18" width="3" height="3"/></svg>
                <?= t('aff.links.qr_btn') ?>
            </button>
            <button class="rz-btn" onclick="toggleUtm()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <?= t('aff.links.utm_btn') ?>
            </button>
        </div>
    </div>

    <!-- UTM Generator -->
    <div id="utm-section" style="display:none;margin-bottom:16px">
        <div class="rz-card">
            <div class="rz-card-head">
                <h2 class="rz-card-title" style="font-size:15px"><?= t('aff.links.utm_card_title') ?></h2>
            </div>
            <div class="rz-form-2" style="margin-bottom:16px">
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.links.utm_source') ?></label>
                    <input type="text" id="utm-source" class="rz-input" placeholder="<?= t('aff.links.utm_source_ph') ?>" oninput="buildUtm()">
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.links.utm_medium') ?></label>
                    <input type="text" id="utm-medium" class="rz-input" placeholder="<?= t('aff.links.utm_medium_ph') ?>" oninput="buildUtm()">
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.links.utm_campaign') ?></label>
                    <input type="text" id="utm-campaign" class="rz-input" placeholder="<?= t('aff.links.utm_campaign_ph') ?>" oninput="buildUtm()">
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.links.utm_content') ?></label>
                    <input type="text" id="utm-content" class="rz-input" placeholder="<?= t('aff.links.utm_content_ph') ?>" oninput="buildUtm()">
                </div>
            </div>
            <div style="background:var(--bg-sunken);border:1px solid var(--line);border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <span style="flex:1;font-size:12px;font-family:var(--font-mono);color:var(--ink);word-break:break-all" id="utm-link">–</span>
                <button class="rz-btn" onclick="copyEl('utm-link', this)" style="white-space:nowrap;flex-shrink:0"><?= t('aff.links.copy') ?></button>
            </div>
        </div>
    </div>

    <!-- Popustna koda -->
    <div id="discount-section" style="display:none;margin-bottom:16px">
        <div class="rz-card">
            <div class="rz-card-head">
                <h2 class="rz-card-title" style="font-size:15px"><?= t('aff.links.discount_card_title') ?></h2>
            </div>
            <div style="background:color-mix(in oklab,var(--success) 10%,transparent);border:1px solid color-mix(in oklab,var(--success) 25%,transparent);border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <span style="font-size:1.3rem;font-weight:800;letter-spacing:.12em;color:var(--success);font-family:var(--font-mono)" id="discount-code">–</span>
                <button class="rz-btn" onclick="copyEl('discount-code', this)" style="flex-shrink:0"><?= t('aff.links.discount_copy_code') ?></button>
            </div>
            <p style="font-size:12px;color:var(--ink-mute);margin:0 0 10px" id="discount-desc">–</p>
            <div style="background:var(--bg-sunken);border:1px solid var(--line);border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <span style="flex:1;font-size:12px;font-family:var(--font-mono);color:var(--ink);word-break:break-all" id="combo-link">–</span>
                <button class="rz-btn" onclick="copyEl('combo-link', this)" style="white-space:nowrap;flex-shrink:0"><?= t('aff.links.discount_copy_combo') ?></button>
            </div>
        </div>
    </div>

    <!-- Marketinški materiali -->
    <div class="rz-card">
        <div class="rz-card-head">
            <h2 class="rz-card-title" style="font-size:15px"><?= t('aff.links.materials_title') ?></h2>
        </div>
        <p style="color:var(--ink-mute);font-size:13px;margin:0 0 16px"><?= t('aff.links.materials_intro') ?></p>
        <div style="display:flex;flex-direction:column;gap:12px">
            <div style="background:var(--bg-sunken);border:1px solid var(--line);border-radius:10px;padding:16px">
                <div style="font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:8px"><?= t('aff.links.material_short_label') ?></div>
                <div style="font-size:13px;color:var(--ink);white-space:pre-line;line-height:1.5;margin-bottom:12px" id="txt-short">–</div>
                <button class="rz-btn" onclick="copyEl('txt-short', this)"><?= t('aff.links.copy') ?></button>
            </div>
            <div style="background:var(--bg-sunken);border:1px solid var(--line);border-radius:10px;padding:16px">
                <div style="font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:8px"><?= t('aff.links.material_long_label') ?></div>
                <div style="font-size:13px;color:var(--ink);white-space:pre-line;line-height:1.5;margin-bottom:12px" id="txt-long">–</div>
                <button class="rz-btn" onclick="copyEl('txt-long', this)"><?= t('aff.links.copy') ?></button>
            </div>
        </div>
    </div>
</main>
</div>

<!-- QR modal -->
<div id="qr-modal" style="display:none;position:fixed;inset:0;background:rgba(30,40,30,.5);z-index:1000;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)closeQr()">
    <div class="rz-card" style="text-align:center;max-width:320px;width:100%;margin:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <span style="font-weight:700;font-size:15px"><?= t('aff.links.qr_modal_title') ?></span>
            <button class="rz-iconbtn" onclick="closeQr()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M6 18 18 6"/></svg>
            </button>
        </div>
        <div id="qr-canvas" style="display:inline-block;border-radius:8px;overflow:hidden;line-height:0"></div>
        <p style="font-size:11px;color:var(--ink-mute);margin:12px 0 0"><?= t('aff.links.qr_modal_save_hint') ?></p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
const BASE     = <?= json_encode(BASE_PATH) ?>;
const REF      = <?= json_encode($aff['ref_code'] ?? '') ?>;
const APPURL   = <?= json_encode(APP_URL . BASE_PATH) ?>;
const REF_LINK = APPURL + '/?ref=' + REF;

// Translatable templates (placeholder zamenjamo client-side)
const TPL = {
    discountPerc:          <?= json_encode(t('aff.links.discount_perc'),           JSON_UNESCAPED_UNICODE) ?>,
    discountPercRepeating: <?= json_encode(t('aff.links.discount_perc_repeating'), JSON_UNESCAPED_UNICODE) ?>,
    discountForClients:    <?= json_encode(t('aff.links.discount_for_clients'),    JSON_UNESCAPED_UNICODE) ?>,
    textShort:             <?= json_encode(t_raw('aff.links.text_short'),                 JSON_UNESCAPED_UNICODE) ?>,
    textShortDiscountLine: <?= json_encode(t_raw('aff.links.text_short_discount_line'),   JSON_UNESCAPED_UNICODE) ?>,
    textLong:              <?= json_encode(t_raw('aff.links.text_long'),                  JSON_UNESCAPED_UNICODE) ?>,
    textLongDiscountLine:  <?= json_encode(t_raw('aff.links.text_long_discount_line'),    JSON_UNESCAPED_UNICODE) ?>,
    copied:                <?= json_encode(t('aff.common.copied'),                  JSON_UNESCAPED_UNICODE) ?>,
};
function tplFill(s, params) {
    return s.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m));
}

document.getElementById('ref-link').textContent = REF_LINK;
document.getElementById('utm-link').textContent = REF_LINK;

function toggleUtm() {
    const el = document.getElementById('utm-section');
    el.style.display = el.style.display === 'none' ? '' : 'none';
}

function buildUtm() {
    const src  = document.getElementById('utm-source').value.trim();
    const med  = document.getElementById('utm-medium').value.trim();
    const cam  = document.getElementById('utm-campaign').value.trim();
    const con  = document.getElementById('utm-content').value.trim();
    let url = REF_LINK;
    const p = [];
    if (src) p.push('utm_source=' + encodeURIComponent(src));
    if (med) p.push('utm_medium=' + encodeURIComponent(med));
    if (cam) p.push('utm_campaign=' + encodeURIComponent(cam));
    if (con) p.push('utm_content=' + encodeURIComponent(con));
    if (p.length) url += '&' + p.join('&');
    document.getElementById('utm-link').textContent = url;
}

function copyEl(id, btn) {
    navigator.clipboard.writeText(document.getElementById(id).textContent).then(() => {
        const orig = btn.textContent;
        btn.textContent = TPL.copied;
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

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
            ? tplFill(TPL.discountPercRepeating, { pct: pct, months: d.data.duration_months })
            : tplFill(TPL.discountPerc,          { pct: pct });
        document.getElementById('discount-desc').textContent = tplFill(TPL.discountForClients, { desc: dur });
        buildTexts(d.data.code, pct, combo);
    } catch(e) {}
}

function buildTexts(code, pct, comboLink) {
    const link = comboLink || REF_LINK;
    const shortDisc = pct > 0 ? tplFill(TPL.textShortDiscountLine, { pct: pct, code: code }) : '';
    const longDisc  = pct > 0 ? tplFill(TPL.textLongDiscountLine,  { pct: pct })             : '';
    document.getElementById('txt-short').textContent = tplFill(TPL.textShort, { discountLine: shortDisc, link: link });
    document.getElementById('txt-long').textContent  = tplFill(TPL.textLong,  { discountLine: longDisc,  link: link });
}

let _qrRendered = false;
function openQr() {
    const modal = document.getElementById('qr-modal');
    modal.style.display = 'flex';
    if (_qrRendered) return;
    _qrRendered = true;
    const el = document.getElementById('qr-canvas');
    el.innerHTML = '';
    new QRCode(el, {
        text:         REF_LINK,
        width:        200,
        height:       200,
        colorDark:    '#1B4332',
        colorLight:   '#ffffff',
        correctLevel: QRCode.CorrectLevel.H,
    });
}
function closeQr() { document.getElementById('qr-modal').style.display = 'none'; }
document.getElementById('qr-modal').style.display = 'none';

loadDiscount();
buildTexts('', 0, REF_LINK);
</script>
</body>
</html>
