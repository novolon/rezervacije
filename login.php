<?php
require_once 'includes/auth_check.php';

if (is_logged_in()) {
    redirect_to_main();
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prijava – <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">
            <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                <rect width="40" height="40" rx="10" fill="#F59E0B"/>
                <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <h1><?= APP_NAME ?></h1>
        <p class="subtitle">Rezervacijski sistem</p>

        <div id="error-msg" class="error-msg" style="display:none"></div>

        <!-- Opozorilo za nepotrjen email -->
        <div id="verify-msg" style="display:none;background:#FEF3C7;border:1px solid #F59E0B;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:.85rem;color:#92400E;text-align:center">
            <strong>Preverite vaš email!</strong><br>
            Pred prijavo potrdite email naslov. Preverite mapo Spam.
        </div>

        <form id="login-form" autocomplete="off">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autofocus autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Geslo</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <div class="form-group remember-row" style="justify-content:space-between;align-items:center">
                <label class="remember-label">
                    <input type="checkbox" id="remember-me">
                    Zapomni si me (30 dni)
                </label>
                <a href="<?= BASE_PATH ?>/forgot-password.php"
                   style="font-size:.8rem;color:#F59E0B;text-decoration:none;font-weight:500">
                    Pozabljeno geslo?
                </a>
            </div>
            <button type="submit" id="login-btn">
                <span id="btn-text">Prijava</span>
                <span id="btn-loading" style="display:none">...</span>
            </button>
        </form>

        <p style="text-align:center;margin-top:20px;font-size:.875rem;color:#6B7280">
            Nimaš računa?
            <a href="<?= BASE_PATH ?>/register.php" style="color:#F59E0B;font-weight:600;text-decoration:none">Registracija</a>
        </p>
    </div>
</div>

<script>
const BASE_PATH = '<?= BASE_PATH ?>';
document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn        = document.getElementById('login-btn');
    const errDiv     = document.getElementById('error-msg');
    const verifyDiv  = document.getElementById('verify-msg');
    const email      = document.getElementById('email').value.trim();
    const password   = document.getElementById('password').value;
    const rememberMe = document.getElementById('remember-me').checked;

    btn.disabled = true;
    document.getElementById('btn-text').style.display    = 'none';
    document.getElementById('btn-loading').style.display = 'inline';
    errDiv.style.display   = 'none';
    verifyDiv.style.display = 'none';

    try {
        const res  = await fetch(BASE_PATH + '/api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password, remember_me: rememberMe })
        });
        const json = await res.json();

        if (json.success) {
            window.location.href = json.data.redirect || BASE_PATH + '/pages/main.php';
        } else {
            // Nepotrjen email – posebno sporočilo
            if (json.error === 'email_not_verified') {
                verifyDiv.style.display = 'block';
            } else {
                errDiv.textContent   = json.error || 'Napaka pri prijavi.';
                errDiv.style.display = 'block';
            }
            btn.disabled = false;
            document.getElementById('btn-text').style.display    = 'inline';
            document.getElementById('btn-loading').style.display = 'none';
        }
    } catch(err) {
        errDiv.textContent   = 'Napaka pri povezavi s strežnikom.';
        errDiv.style.display = 'block';
        btn.disabled = false;
        document.getElementById('btn-text').style.display    = 'inline';
        document.getElementById('btn-loading').style.display = 'none';
    }
});
</script>
</body>
</html>
