<?php
require_once 'includes/auth_check.php';
require_once 'includes/lang.php';

if (is_logged_in()) {
    redirect_to_main();
}
?>
<?php
$pageTitle = t('auth.login_title');
$extraCss  = ['design.css'];
require_once 'includes/html_head.php';
?>
<body>

<div class="rz-auth">

    <!-- Leva stran — branding -->
    <div class="rz-auth-left">
        <div class="rz-auth-brand">
            <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true">
                <rect width="32" height="32" rx="7" fill="#C4704B"/>
                <path d="M9 8v16l4-4h5a5 5 0 0 0 5-5v-4a3 3 0 0 0-3-3H9Z" fill="#fff"/>
            </svg>
            Rezble
        </div>
        <div class="rz-auth-quote">
            <div class="rz-auth-eyebrow"><?= t('auth.tagline') ?></div>
            <h1 class="rz-auth-h"><?= nl2br(t('auth.hero_heading')) ?></h1>
            <p class="rz-auth-p"><?= t('auth.hero_text') ?></p>
            <div class="rz-auth-stats">
                <div>
                    <span class="rz-auth-stat-val">+127%</span>
                    <span class="rz-auth-stat-lbl"><?= t('auth.stat_reservations') ?></span>
                </div>
                <div>
                    <span class="rz-auth-stat-val">11 min</span>
                    <span class="rz-auth-stat-lbl"><?= t('auth.stat_time_saved') ?></span>
                </div>
                <div>
                    <span class="rz-auth-stat-val">390+</span>
                    <span class="rz-auth-stat-lbl"><?= t('auth.stat_restaurants') ?></span>
                </div>
            </div>
        </div>
        <div class="rz-auth-foot"><?= t('auth.footer', ['year' => date('Y')]) ?></div>
    </div>

    <!-- Desna stran — forma -->
    <div class="rz-auth-right">
        <div class="rz-auth-card">
            <div class="rz-auth-switch">
                <button class="is-sel" onclick="showTab('login',this)"><?= t('auth.login_btn') ?></button>
                <a href="<?= BASE_PATH ?>/register.php" class="rz-btn" style="flex:1;justify-content:center;border:0;font-size:13px;font-weight:600;color:var(--ink-mute);border-radius:7px"><?= t('auth.register_title') ?></a>
            </div>

            <h2 class="rz-auth-title"><?= t('auth.welcome_back') ?></h2>
            <p class="rz-auth-sub"><?= t('auth.login_subtitle') ?></p>

            <div id="error-msg" style="display:none;background:color-mix(in oklab, var(--danger) 10%, transparent);color:var(--danger);border:1px solid color-mix(in oklab, var(--danger) 30%, transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px"></div>
            <div id="verify-msg" style="display:none;background:var(--accent-soft);border:1px solid var(--accent);border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:var(--ink-soft)">
                <strong><?= t('auth.verify_email_title') ?></strong><br>
                <?= t('auth.verify_email_text') ?>
            </div>

            <form id="login-form" class="rz-auth-form" autocomplete="off">
                <div class="rz-field">
                    <label class="rz-field-label" for="email"><?= t('auth.email_label') ?></label>
                    <input class="rz-input" type="text" id="email" name="email" required autofocus autocomplete="username" placeholder="<?= t('auth.email_placeholder') ?>">
                </div>
                <div class="rz-field">
                    <div class="rz-auth-row">
                        <label class="rz-field-label" for="password"><?= t('auth.password_label') ?></label>
                        <a href="<?= BASE_PATH ?>/forgot-password.php" class="rz-link"><?= t('auth.forgot_password') ?></a>
                    </div>
                    <input class="rz-input" type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <label class="rz-check">
                    <input type="checkbox" id="remember-me">
                    <?= t('auth.remember_me') ?>
                </label>
                <button type="submit" id="login-btn" class="rz-btn rz-btn-primary" style="justify-content:center;padding:12px 18px;font-size:14px">
                    <span id="btn-text"><?= t('auth.login_btn') ?></span>
                    <span id="btn-loading" style="display:none">…</span>
                </button>
            </form>
        </div>
    </div>

</div>

<script>
const BASE_PATH = '<?= BASE_PATH ?>';
const T_LOGIN_ERR   = <?= json_encode(t('auth.js_login_error')) ?>;
const T_NETWORK_ERR = <?= json_encode(t('auth.js_network_error')) ?>;
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
                errDiv.textContent   = json.error || T_LOGIN_ERR;
                errDiv.style.display = 'block';
            }
            btn.disabled = false;
            document.getElementById('btn-text').style.display    = 'inline';
            document.getElementById('btn-loading').style.display = 'none';
        }
    } catch(err) {
        errDiv.textContent   = T_NETWORK_ERR;
        errDiv.style.display = 'block';
        btn.disabled = false;
        document.getElementById('btn-text').style.display    = 'inline';
        document.getElementById('btn-loading').style.display = 'none';
    }
});
</script>
</body>
</html>
