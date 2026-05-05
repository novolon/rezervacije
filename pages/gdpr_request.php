<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';

// Pridobi seznam restavracij za dropdown
$pdo          = getDB();
$restaurants  = $pdo->query("SELECT id, name FROM restaurants WHERE is_active = 1 ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= t('gdpr_request.title') ?> – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
    <script>
    window.__T__ = <?= json_encode(get_lang_strings(), JSON_UNESCAPED_UNICODE) ?>;
    window.t = function(k, p) { var s = window.__T__[k] || k; if (p) { for (var x in p) s = s.split('{'+x+'}').join(p[x]); } return s; };
    </script>
    <style>
        body { background: #F9FAFB; }
        .legal-wrap { max-width: 580px; margin: 40px auto; padding: 0 20px 60px; }
        .legal-logo { display:flex; align-items:center; gap:10px; margin-bottom:32px; text-decoration:none; color:#111827; }
        .legal-logo span { font-size:1.1rem; font-weight:700; }
        .legal-card { background:#fff; border-radius:16px; padding:36px 40px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .legal-card h1 { font-size:1.4rem; font-weight:700; margin:0 0 8px; color:#111827; }
        .legal-card .subtitle { color:#6B7280; font-size:.875rem; margin:0 0 28px;line-height:1.5 }
        .legal-card a { color:#F59E0B; }
        .success-box { text-align:center;padding:32px 20px }
        .success-box svg { display:block;margin:0 auto 16px }
        .success-box h2 { font-size:1.2rem;font-weight:700;color:#111827;margin:0 0 8px }
        .success-box p { color:#6B7280;font-size:.875rem;line-height:1.6;margin:0 }
        @media (max-width:600px) { .legal-card { padding:24px 20px; } }
    </style>
</head>
<body>
<div class="legal-wrap">
    <a class="legal-logo" href="<?= BASE_PATH ?>/">
        <svg width="32" height="32" viewBox="0 0 40 40" fill="none">
            <rect width="40" height="40" rx="10" fill="#F59E0B"/>
            <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
        <span><?= h(APP_NAME) ?></span>
    </a>

    <div class="legal-card">
        <div id="form-section">
            <h1><?= t('gdpr_request.heading') ?></h1>
            <p class="subtitle">
                <?= t('gdpr_request.subtitle') ?><br>
                Preberite tudi našo <a href="<?= BASE_PATH ?>/pages/privacy.php" target="_blank"><?= t('gdpr_request.privacy_link') ?></a>.
            </p>

            <div id="req-error" class="error-msg" style="display:none"></div>

            <div class="form-group">
                <label for="req-email"><?= t('gdpr_request.email_label') ?> <span style="color:#EF4444">*</span></label>
                <input type="email" id="req-email" placeholder="janez@email.com" required autocomplete="email">
            </div>

            <div class="form-group">
                <label for="req-type"><?= t('gdpr_request.type_label') ?> <span style="color:#EF4444">*</span></label>
                <select id="req-type" required style="width:100%;padding:10px 14px;border:1px solid #E5E7EB;border-radius:10px;font-size:.9rem;color:#374151;background:#fff;appearance:none">
                    <option value=""><?= t('gdpr_request.type_placeholder') ?></option>
                    <option value="access"><?= t('gdpr_request.type_access') ?></option>
                    <option value="rectification"><?= t('gdpr_request.type_rectification') ?></option>
                    <option value="erasure"><?= t('gdpr_request.type_erasure') ?></option>
                    <option value="portability"><?= t('gdpr_request.type_portability') ?></option>
                </select>
            </div>

            <div class="form-group">
                <label for="req-restaurant"><?= t('gdpr_request.restaurant_label') ?> <span style="color:#6B7280;font-weight:400"><?= t('gdpr_request.restaurant_optional') ?></span></label>
                <select id="req-restaurant" style="width:100%;padding:10px 14px;border:1px solid #E5E7EB;border-radius:10px;font-size:.9rem;color:#374151;background:#fff;appearance:none">
                    <option value=""><?= t('gdpr_request.restaurant_all') ?></option>
                    <?php foreach ($restaurants as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button id="req-btn" onclick="submitRequest()"
                style="width:100%;padding:13px;background:#F59E0B;color:#fff;border:none;border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;margin-top:8px;transition:opacity .15s">
                <?= t('gdpr_request.submit_btn') ?>
            </button>

            <p style="font-size:.78rem;color:#9CA3AF;text-align:center;margin-top:14px;line-height:1.5">
                <?= t('gdpr_request.footer_note') ?>
            </p>
        </div>

        <div id="success-section" class="success-box" style="display:none">
            <svg width="48" height="48" fill="none" stroke="#22C55E" stroke-width="1.8" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>
            </svg>
            <h2><?= t('gdpr_request.success_title') ?></h2>
            <p><?= t('gdpr_request.success_desc') ?></p>
            <a href="<?= BASE_PATH ?>/" style="display:inline-block;margin-top:20px;font-size:.85rem;color:#6B7280"><?= t('gdpr_request.back_link') ?></a>
        </div>
    </div>
</div>
<script>
async function submitRequest() {
    const email      = document.getElementById('req-email').value.trim();
    const type       = document.getElementById('req-type').value;
    const restaurant = document.getElementById('req-restaurant').value;
    const errEl      = document.getElementById('req-error');
    const btn        = document.getElementById('req-btn');

    errEl.style.display = 'none';
    if (!email) { showErr(window.t('gdpr_request.err_email')); return; }
    if (!type)  { showErr(window.t('gdpr_request.err_type')); return; }

    btn.disabled = true;
    btn.textContent = window.t('gdpr_request.submitting');

    try {
        const res  = await fetch('<?= BASE_PATH ?>/api/gdpr.php?action=submit_request', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ email, type, restaurant_id: restaurant ? parseInt(restaurant) : null }),
        });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || window.t('gdpr_request.err_server'));
        document.getElementById('form-section').style.display    = 'none';
        document.getElementById('success-section').style.display = 'block';
    } catch (e) {
        showErr(e.message);
        btn.disabled    = false;
        btn.textContent = window.t('gdpr_request.submit_btn');
    }

    function showErr(msg) {
        errEl.textContent    = msg;
        errEl.style.display  = 'block';
    }
}
</script>
</body>
</html>
