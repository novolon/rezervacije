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
            <svg viewBox="0 0 104 32" xmlns="http://www.w3.org/2000/svg" aria-label="Rezble" style="height:26px;width:auto">
                <path fill="currentColor" d="M31.04,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.87-.21,1.56-.64,2.08-1.07,1.3-2.16,2.3-3.25,3.02-1.1.69-2.39,1.04-3.89,1.04s-2.69-.39-3.72-1.17c-1.01-.78-1.97-2.12-2.88-4.02-.74-1.52-1.32-2.64-1.74-3.35-.42-.74-.85-1.26-1.27-1.57-.4-.31-.91-.5-1.51-.57-.09.47-.35,1.94-.77,4.42-.18,1.12-.29,1.8-.34,2.04-.22,1.36-.67,2.41-1.34,3.15-.67.71-1.69,1.07-3.05,1.07-1.5,0-2.83-.44-3.99-1.31-1.14-.89-2.02-2.12-2.65-3.69-.63-1.59-.94-3.38-.94-5.39,0-3.75.65-6.99,1.94-9.72,1.32-2.73,3.15-4.8,5.5-6.23,2.37-1.45,5.1-2.18,8.18-2.18,2.15,0,3.94.32,5.4.97,1.45.65,2.53,1.54,3.22,2.68.72,1.14,1.07,2.42,1.07,3.85,0,1.25-.3,2.48-.91,3.69-.58,1.18-1.46,2.23-2.65,3.15-1.18.92-2.63,1.6-4.32,2.04,1.07.29,1.9.76,2.48,1.41s1.16,1.6,1.74,2.85c.63,1.34,1.24,2.32,1.84,2.95.63.63,1.34.94,2.15.94.72,0,1.4-.23,2.04-.7.65-.49,1.46-1.32,2.45-2.48.27-.31.57-.47.91-.47ZM8.45,20.94c-.76,0-1.29-.18-1.58-.54-.27-.36-.4-.76-.4-1.21,0-.54.17-.96.5-1.27.36-.31.76-.47,1.21-.47h.84c.36-2.19.69-4.08,1.01-5.66.29-1.45,1.23-2.18,2.82-2.18,1.27,0,1.91.57,1.91,1.71,0,.25-.01.44-.03.57l-1.01,5.56c1.21-.07,2.3-.36,3.29-.87,1.01-.51,1.8-1.23,2.38-2.14.6-.92.91-1.95.91-3.12,0-1.41-.48-2.51-1.44-3.32-.96-.8-2.37-1.21-4.22-1.21-2.19,0-4.11.55-5.77,1.64-1.63,1.07-2.91,2.68-3.82,4.83-.92,2.12-1.37,4.71-1.37,7.77,0,1.43.15,2.66.44,3.69.29,1.03.65,1.8,1.07,2.31.42.51.83.77,1.21.77.29,0,.53-.15.7-.44.2-.29.36-.76.47-1.41l.91-5.03Z"/>
                <path fill="currentColor" d="M43.56,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.83,1.01-2,1.93-3.52,2.78-1.5.85-3.11,1.27-4.83,1.27-2.35,0-4.17-.64-5.46-1.91-1.3-1.27-1.94-3.02-1.94-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.96,1.97-5.6,2.61.56,1.03,1.62,1.54,3.18,1.54,1.01,0,2.15-.35,3.42-1.04,1.3-.71,2.41-1.64,3.35-2.78.27-.31.57-.47.91-.47ZM35.11,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z"/>
                <path fill="currentColor" d="M58.17,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.83-.21,1.52-.64,2.08-1.05,1.36-2.36,2.43-3.92,3.22-1.54.78-3.29,1.17-5.23,1.17-1.52,0-2.84-.22-3.96-.67-1.12-.47-1.98-1.09-2.58-1.88-.58-.8-.87-1.7-.87-2.68,0-1.47.59-2.78,1.78-3.92,1.18-1.14,2.88-2.23,5.1-3.28l-5.5.13c-.49.02-.87-.15-1.14-.5-.25-.38-.37-.83-.37-1.34s.12-1.01.37-1.41c.27-.42.63-.64,1.07-.64,1.03,0,2.4.08,4.12.23.36.02,1.01.07,1.94.13.96.07,1.77.1,2.41.1.22,0,.65-.09,1.27-.27.11-.02.32-.08.64-.17.34-.09.61-.13.84-.13.36,0,.65.16.87.47.25.31.37.79.37,1.44,0,.71-.17,1.27-.5,1.68-.34.4-.86.76-1.58,1.07-2.03.87-3.72,1.81-5.06,2.81-1.34.98-2.01,1.97-2.01,2.95,0,.63.29,1.14.87,1.54.58.4,1.44.6,2.58.6,1.25,0,2.51-.31,3.79-.94,1.3-.63,2.46-1.57,3.49-2.85.27-.31.57-.47.91-.47Z"/>
                <path fill="currentColor" d="M74.2,21.21c.29,0,.51.15.67.44.16.29.23.66.23,1.11,0,.56-.08.99-.23,1.31-.16.29-.4.49-.74.6-1.34.47-2.82.74-4.43.8-.45,1.85-1.3,3.35-2.55,4.49-1.23,1.14-2.59,1.71-4.09,1.71-2.26,0-3.9-.86-4.93-2.58-1.03-1.72-1.54-4.21-1.54-7.47,0-2.88.36-6.01,1.07-9.38.72-3.4,1.75-6.28,3.12-8.65,1.39-2.39,3.03-3.59,4.93-3.59,1.03,0,1.85.45,2.48,1.34.63.87.94,2.01.94,3.42,0,1.83-.35,3.65-1.04,5.46-.69,1.81-1.84,3.71-3.45,5.7,1.5.11,2.72.74,3.65,1.88.94,1.12,1.5,2.5,1.68,4.15,1.05-.07,2.3-.29,3.75-.67.13-.04.29-.07.47-.07ZM64.95,3.32c-.45,0-.94.67-1.47,2.01-.51,1.32-.99,3.12-1.44,5.39-.45,2.28-.78,4.77-1.01,7.47,1.48-2.7,2.65-5.08,3.52-7.14.89-2.08,1.34-3.92,1.34-5.53,0-.71-.09-1.26-.27-1.64-.16-.38-.38-.57-.67-.57ZM63.21,28.11c.69,0,1.31-.29,1.84-.87.54-.58.89-1.42,1.07-2.51-.69-.47-1.23-1.08-1.61-1.84-.36-.76-.54-1.56-.54-2.41,0-.31.04-.74.13-1.27h-.1c-.92,0-1.69.46-2.31,1.37-.6.89-.91,2.1-.91,3.62,0,1.27.23,2.25.7,2.92.49.67,1.06,1.01,1.71,1.01Z"/>
                <path fill="currentColor" d="M84.92,24.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.89-.21,1.59-.64,2.08-.96,1.18-2.01,2.16-3.15,2.92-1.12.76-2.39,1.14-3.82,1.14-1.97,0-3.43-.89-4.39-2.68-.94-1.79-1.41-4.1-1.41-6.94s.35-5.83,1.04-9.32c.72-3.48,1.75-6.48,3.12-8.98,1.39-2.5,3.03-3.75,4.93-3.75,1.07,0,1.91.5,2.51,1.51.63.98.94,2.4.94,4.26,0,2.66-.74,5.74-2.21,9.25-1.47,3.51-3.48,6.98-6,10.42.16.92.41,1.57.77,1.98.36.38.83.57,1.41.57.92,0,1.72-.26,2.41-.77.69-.54,1.58-1.44,2.65-2.71.27-.31.57-.47.91-.47ZM80.79,3.32c-.51,0-1.1.93-1.74,2.78-.65,1.85-1.22,4.15-1.71,6.9-.49,2.75-.76,5.38-.8,7.91,1.59-2.61,2.85-5.23,3.79-7.84.94-2.64,1.41-5.04,1.41-7.2,0-1.7-.31-2.55-.94-2.55Z"/>
                <path fill="currentColor" d="M95.15,25.03c.29,0,.51.13.67.4.18.27.27.64.27,1.11,0,.8-.19,1.5-.57,2.08-.63.96-1.45,1.71-2.48,2.25-1.01.54-2.21.8-3.62.8-2.15,0-3.81-.64-4.99-1.91-1.18-1.3-1.78-3.04-1.78-5.23,0-1.54.32-2.97.97-4.29.65-1.34,1.54-2.4,2.68-3.18,1.16-.78,2.47-1.17,3.92-1.17,1.3,0,2.34.39,3.12,1.17.78.76,1.17,1.8,1.17,3.12,0,1.54-.56,2.87-1.68,3.99-1.1,1.09-2.97,1.97-5.63,2.61.54,1.03,1.44,1.54,2.72,1.54.92,0,1.66-.21,2.25-.64.6-.42,1.3-1.14,2.08-2.14.27-.34.57-.5.91-.5ZM89.65,19.17c-.83,0-1.53.48-2.11,1.44-.56.96-.84,2.12-.84,3.48v.07c1.32-.31,2.36-.78,3.12-1.41.76-.63,1.14-1.35,1.14-2.18,0-.42-.12-.76-.37-1.01-.22-.27-.54-.4-.94-.4Z"/>
                <path fill="#c8542b" d="M100.75,31.66c-.98,0-1.73-.27-2.25-.8-.49-.54-.74-1.24-.74-2.11,0-1.01.28-1.81.84-2.41.58-.6,1.39-.9,2.41-.9s1.72.25,2.21.74c.51.47.77,1.17.77,2.11,0,1.03-.29,1.85-.87,2.48-.58.6-1.37.9-2.38.9Z"/>
            </svg>
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
            <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
                <?php $langSwitcherTheme = 'light'; require __DIR__ . '/includes/lang_switcher.php'; ?>
            </div>
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
            if (window.posthog) {
                if (json.data && json.data.userId) {
                    window.posthog.identify(String(json.data.userId), {
                        email: email,
                        role:  json.data.role || null,
                        name:  json.data.fullName || null,
                    });
                }
                window.posthog.capture('login_success', { role: json.data?.role || null });
            }
            window.location.href = json.data.redirect || BASE_PATH + '/pages/main.php';
        } else {
            // Nepotrjen email – posebno sporočilo
            if (json.error === 'email_not_verified') {
                verifyDiv.style.display = 'block';
            } else {
                errDiv.textContent   = json.error || T_LOGIN_ERR;
                errDiv.style.display = 'block';
            }
            if (window.posthog) {
                window.posthog.capture('login_failed', { reason: json.error || 'unknown' });
            }
            btn.disabled = false;
            document.getElementById('btn-text').style.display    = 'inline';
            document.getElementById('btn-loading').style.display = 'none';
        }
    } catch(err) {
        errDiv.textContent   = T_NETWORK_ERR;
        errDiv.style.display = 'block';
        if (window.posthog) window.posthog.capture('login_failed', { reason: 'network_error' });
        btn.disabled = false;
        document.getElementById('btn-text').style.display    = 'inline';
        document.getElementById('btn-loading').style.display = 'none';
    }
});
</script>
</body>
</html>
