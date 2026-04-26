<?php
require_once 'includes/auth_check.php';
require_once 'includes/lang.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

if (is_logged_in()) {
    redirect_to_main();
}

$errors  = [];
$success = false;
$post    = [];

$validPlans    = ['basic', 'advanced', 'premium'];
$selectedPlan  = in_array($_GET['plan'] ?? '', $validPlans) ? $_GET['plan'] : 'basic';
$planNames     = ['basic' => 'Basic', 'advanced' => 'Advanced', 'premium' => 'Premium'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post['full_name']         = trim($_POST['full_name']         ?? '');
    $post['email']             = trim($_POST['email']             ?? '');
    $post['company_name']      = trim($_POST['company_name']      ?? '');
    $post['company_address']   = trim($_POST['company_address']   ?? '');
    $post['tax_number']        = trim($_POST['tax_number']        ?? '');
    $post['is_vat_registered'] = !empty($_POST['is_vat_registered']);
    $post['vat_id']            = strtoupper(trim($_POST['vat_id'] ?? ''));
    $password                  = $_POST['password']               ?? '';
    $confirm                   = $_POST['password_confirm']       ?? '';

    if (!$post['full_name']) {
        $errors[] = t('auth.err_name_required');
    }
    if (!$post['email'] || !filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('auth.err_email_invalid');
    }
    if (strlen($password) < 8) {
        $errors[] = t('auth.err_password_short');
    }
    if ($password !== $confirm) {
        $errors[] = t('auth.err_passwords_mismatch');
    }
    if (!$post['company_name']) {
        $errors[] = t('auth.err_company_name');
    }
    if (!$post['company_address']) {
        $errors[] = t('auth.err_company_address');
    }
    if (empty($_POST['gdpr_consent'])) {
        $errors[] = t('auth.err_gdpr_required');
    }
    if ($post['is_vat_registered']) {
        if (!$post['vat_id']) {
            $errors[] = t('auth.err_vat_id_required');
        } elseif (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,15}$/', $post['vat_id'])) {
            $errors[] = t('auth.err_vat_id_format');
        }
    } else {
        if (!$post['tax_number']) {
            $errors[] = t('auth.err_tax_number_required');
        } elseif (!preg_match('/^[A-Z0-9]{4,20}$/i', $post['tax_number'])) {
            $errors[] = t('auth.err_tax_number_format');
        }
    }

    if (empty($errors)) {
        try {
            $pdo = getDB();

            $hash              = password_hash($password, PASSWORD_BCRYPT);
            $trialEnds         = date('Y-m-d H:i:s', strtotime('+30 days'));
            $verificationToken = bin2hex(random_bytes(32));
            $gdprIp            = $_SERVER['REMOTE_ADDR'] ?? null;
            $gdprNow           = date('Y-m-d H:i:s');
            $marketingConsent  = !empty($_POST['marketing_consent']) ? 1 : 0;

            $pdo->prepare("
                INSERT INTO users
                    (email, password_hash, full_name, company_name, company_address,
                     tax_number, is_vat_registered, vat_id,
                     role, trial_ends_at, subscription_status, is_active, verification_token,
                     gdpr_consent_at, gdpr_consent_ip, dpa_consent_at,
                     marketing_consent, marketing_consent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'admin', ?, 'trial', 1, ?,
                        ?, ?, ?, ?, ?)
            ")->execute([
                $post['email'], $hash, $post['full_name'],
                $post['company_name'], $post['company_address'],
                $post['is_vat_registered'] ? null : $post['tax_number'],
                $post['is_vat_registered'] ? 1 : 0,
                $post['is_vat_registered'] ? $post['vat_id'] : null,
                $trialEnds, $verificationToken,
                $gdprNow, $gdprIp, $gdprNow,
                $marketingConsent, $marketingConsent ? $gdprNow : null,
            ]);

            $newUserId = (int) $pdo->lastInsertId();

            // Ustvari trial subscription z izbranim paketom.
            // status='trial' označuje brezplačno obdobje; po preteku mora zakupiti.
            $pdo->prepare("
                INSERT INTO subscriptions (user_id, plan_slug, status, ends_at)
                VALUES (?, ?, 'trial', ?)
            ")->execute([$newUserId, $selectedPlan, $trialEnds]);

            send_verification_email($post['email'], $post['full_name'], $verificationToken);

            $success = true;

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = t('auth.err_email_taken');
            } else {
                error_log('Register error: ' . $e->getMessage());
                $errors[] = t('auth.err_server');
            }
        }
    }
}
?>
<?php
$pageTitle = t('auth.register_title');
$extraCss  = ['login.css', 'register.css'];
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
            <?= h(APP_NAME) ?>
        </div>
        <div class="rz-auth-quote">
            <div class="rz-auth-eyebrow"><?= t('auth.register_start') ?></div>
            <h1 class="rz-auth-h"><?= nl2br(t('auth.register_hero')) ?></h1>
            <p class="rz-auth-p"><?= t('auth.register_hero_text') ?></p>
            <div class="rz-auth-stats">
                <div><span class="rz-auth-stat-val">30 dni</span><span class="rz-auth-stat-lbl"><?= t('auth.trial_stat_1') ?></span></div>
                <div><span class="rz-auth-stat-val">0 €</span><span class="rz-auth-stat-lbl"><?= t('auth.trial_stat_2') ?></span></div>
                <div><span class="rz-auth-stat-val">10 min</span><span class="rz-auth-stat-lbl"><?= t('auth.trial_stat_3') ?></span></div>
            </div>
        </div>
        <div class="rz-auth-foot"><?= t('auth.footer', ['year' => date('Y')]) ?></div>
    </div>

    <!-- Desna stran — forma -->
    <div class="rz-auth-right">
        <div class="rz-auth-card">
            <div class="rz-auth-switch">
                <a href="<?= BASE_PATH ?>/login.php" class="rz-btn" style="flex:1;justify-content:center;border:0;font-size:13px;font-weight:600;color:var(--ink-mute);border-radius:7px"><?= t('auth.login_btn') ?></a>
                <button class="is-sel" type="button"><?= t('auth.register_title') ?></button>
            </div>

            <h2 class="rz-auth-title"><?= t('auth.create_account_title') ?></h2>
            <p class="rz-auth-sub"><?= t('auth.create_account_subtitle') ?></p>

        <!-- Plan selector -->
        <div style="margin-bottom:20px">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#6B7280;font-weight:600;margin-bottom:8px"><?= t('auth.plan_select_label') ?></div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                <?php foreach ($planNames as $slug => $name):
                    $active = $slug === $selectedPlan;
                    $prices = ['basic' => '4,99 €', 'advanced' => '6,99 €', 'premium' => '9,99 €'];
                ?>
                <a href="?plan=<?= $slug ?>" style="text-decoration:none;display:block;border:2px solid <?= $active ? '#F59E0B' : '#E5E7EB' ?>;border-radius:8px;padding:10px 8px;text-align:center;background:<?= $active ? '#FFFBEB' : '#fff' ?>;cursor:pointer;transition:border-color .15s">
                    <div style="font-size:.8rem;font-weight:700;color:<?= $active ? '#92400E' : '#374151' ?>"><?= h($name) ?></div>
                    <div style="font-size:.7rem;color:#9CA3AF;margin-top:2px"><?= $prices[$slug] ?><?= t('auth.per_month_short') ?></div>
                </a>
                <?php endforeach; ?>
            </div>
            <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-top:10px;text-align:center;font-size:.82rem;color:#92400E">
                Začnete z <strong>30-dnevnim brezplačnim trialom</strong> paketa <strong><?= h($planNames[$selectedPlan]) ?></strong>.<br>
                <span style="color:#B45309;font-size:.75rem"><?= t('auth.trial_plan_info') ?></span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="1.8"><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
                <h2><?= t('auth.email_confirmed_title') ?></h2>
                <p><?= t('auth.email_confirmed_text') ?><br><strong><?= h($post['email']) ?></strong></p>
                <p style="font-size:.8rem;color:#9CA3AF"><?= t('auth.email_spam_hint') ?></p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login"><?= t('auth.back_to_login_btn') ?></a>
            </div>
        <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="error-msg" style="display:block">
                <?= implode('<br>', array_map(fn($e) => h($e), $errors)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="full_name"><?= t('auth.name_label') ?></label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= h($post['full_name'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="email"><?= t('auth.email_label') ?></label>
                <input type="email" id="email" name="email"
                       value="<?= h($post['email'] ?? '') ?>" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password"><?= t('auth.new_password_label') ?></label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password_confirm"><?= t('auth.confirm_password_label') ?></label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <div class="billing-section-title"><?= t('auth.company_data') ?></div>

            <div class="form-group">
                <label for="company_name"><?= t('auth.company_name_label') ?></label>
                <input type="text" id="company_name" name="company_name"
                       value="<?= h($post['company_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="company_address"><?= t('auth.company_address_label') ?></label>
                <input type="text" id="company_address" name="company_address"
                       value="<?= h($post['company_address'] ?? '') ?>"
                       placeholder="<?= t('auth.company_address_placeholder') ?>" required>
            </div>
            <div class="vat-checkbox-row">
                <input type="checkbox" id="is_vat_registered" name="is_vat_registered"
                       <?= !empty($post['is_vat_registered']) ? 'checked' : '' ?>>
                <label for="is_vat_registered"><?= t('auth.vat_checkbox') ?></label>
            </div>
            <div class="form-group" id="tax-number-group">
                <label for="tax_number"><?= t('auth.tax_number_label') ?></label>
                <input type="text" id="tax_number" name="tax_number"
                       value="<?= h($post['tax_number'] ?? '') ?>"
                       inputmode="numeric" maxlength="20" placeholder="12345678" required>
            </div>
            <div class="form-group" id="vat-id-group" style="display:none">
                <label for="vat_id"><?= t('auth.vat_id_label') ?></label>
                <input type="text" id="vat_id" name="vat_id"
                       value="<?= h($post['vat_id'] ?? '') ?>"
                       placeholder="SI12345678" maxlength="30"
                       style="text-transform:uppercase">
            </div>

            <div style="border-top:1px solid #E5E7EB;margin:20px 0 16px"></div>

            <div class="vat-checkbox-row" style="align-items:flex-start;gap:10px;margin-bottom:10px">
                <input type="checkbox" id="gdpr_consent" name="gdpr_consent"
                       <?= !empty($_POST['gdpr_consent']) ? 'checked' : '' ?> required
                       style="margin-top:3px;flex-shrink:0">
                <label for="gdpr_consent" style="font-size:.83rem;color:#374151;cursor:pointer">
                    Strinjam se s <a href="<?= BASE_PATH ?>/pages/terms.php" target="_blank" style="color:#F59E0B"><?= t('landing.footer_terms') ?></a>,
                    <a href="<?= BASE_PATH ?>/pages/privacy.php" target="_blank" style="color:#F59E0B"><?= t('common.privacy_policy') ?></a>
                    in Pogodbo o obdelavi podatkov (DPA). <span style="color:#EF4444">*</span>
                </label>
            </div>

            <div class="vat-checkbox-row" style="align-items:flex-start;gap:10px;margin-bottom:20px">
                <input type="checkbox" id="marketing_consent" name="marketing_consent"
                       <?= !empty($_POST['marketing_consent']) ? 'checked' : '' ?>
                       style="margin-top:3px;flex-shrink:0">
                <label for="marketing_consent" style="font-size:.83rem;color:#374151;cursor:pointer">
                    <?= t('auth.marketing_agree') ?>
                </label>
            </div>

            <button type="submit"><?= t('auth.create_btn') ?></button>
        </form>

        <p style="text-align:center;margin-top:20px;font-size:.875rem;color:var(--ink-mute)">
            <?= t('auth.already_have_account') ?>
            <a href="<?= BASE_PATH ?>/login.php" class="rz-link"><?= t('auth.login_link') ?></a>
        </p>

        <?php endif; ?>
        </div>
    </div>

</div>
<script>
(function () {
    const chk      = document.getElementById('is_vat_registered');
    const taxGroup = document.getElementById('tax-number-group');
    const vatGroup = document.getElementById('vat-id-group');
    const taxInput = document.getElementById('tax_number');
    const vatInput = document.getElementById('vat_id');
    if (!chk) return;
    function toggle() {
        const isDDV = chk.checked;
        taxGroup.style.display = isDDV ? 'none' : 'block';
        vatGroup.style.display = isDDV ? 'block' : 'none';
        taxInput.required = !isDDV;
        vatInput.required = isDDV;
    }
    chk.addEventListener('change', toggle);
    toggle();
})();
</script>
</body>
</html>
