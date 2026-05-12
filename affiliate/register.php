<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_session.php';
require_once '../includes/mailer.php';

aff_session_start();
if (aff_is_logged_in()) { header('Location: ' . BASE_PATH . '/affiliate/dashboard.php'); exit; }

$errors  = [];
$success = false;
$post    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post['full_name']  = trim($_POST['full_name']  ?? '');
    $post['email']      = trim($_POST['email']      ?? '');
    $post['legal_form'] = in_array($_POST['legal_form'] ?? '', ['individual','sole_trader','company','foreign'])
                          ? $_POST['legal_form'] : 'individual';
    $post['iban']       = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
    $password           = $_POST['password']         ?? '';
    $confirm            = $_POST['password_confirm'] ?? '';

    if (!$post['full_name']) $errors[] = t('aff.register.err_name');
    if (!filter_var($post['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('aff.register.err_email');
    if (strlen($password) < 8) $errors[] = t('aff.register.err_pwlen');
    if ($password !== $confirm) $errors[] = t('aff.register.err_pwmatch');
    if ($post['iban'] && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $post['iban'])) {
        $errors[] = t('aff.register.err_iban');
    }
    if (empty($_POST['terms_consent'])) $errors[] = t('aff.register.err_terms');

    if (empty($errors)) {
        try {
            $pdo = getDB();

            $dup = $pdo->prepare("SELECT 1 FROM affiliates WHERE email = ?");
            $dup->execute([$post['email']]);
            if ($dup->fetchColumn()) {
                $errors[] = t('aff.register.err_dup');
            } else {
                require_once '../includes/affiliate_helper.php';
                $refCode = affiliate_generate_ref_code($pdo);
                $token   = bin2hex(random_bytes(32));

                $termsVer = $pdo->query("SELECT setting_value FROM affiliate_settings WHERE setting_key = 'terms_version'")->fetchColumn() ?: '1.0';

                $pdo->prepare("
                    INSERT INTO affiliates
                    (ref_code, email, password_hash, full_name, legal_form, iban,
                     status, terms_accepted_at, terms_version, marketing_consent, verification_token)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?)
                ")->execute([
                    $refCode,
                    $post['email'],
                    password_hash($password, PASSWORD_BCRYPT),
                    $post['full_name'],
                    $post['legal_form'],
                    $post['iban'] ?: null,
                    $termsVer,
                    !empty($_POST['marketing_consent']) ? 1 : 0,
                    $token,
                ]);

                send_affiliate_verify_email($post['email'], $post['full_name'], $token, _resolve_email_lang());
                $success = true;
            }
        } catch (\Throwable $e) {
            error_log('Affiliate register error: ' . $e->getMessage());
            $errors[] = t('aff.register.err_server');
        }
    }
}

$pageTitle = t('aff.register.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';

$reqMark = ' ' . t('aff.common.required_marker');
?>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:40px 16px">
<div style="width:100%;max-width:460px">

    <a href="<?= BASE_PATH ?>/" style="display:flex;align-items:center;gap:10px;margin-bottom:32px;text-decoration:none">
        <img src="<?= BASE_PATH ?>/assets/images/Rezble.svg" alt="Rezble" style="height:24px;width:auto;display:block">
        <span style="font-weight:500;color:var(--ink-mute);font-size:15px"><?= t('aff.brand_suffix') ?></span>
    </a>

    <?php if ($success): ?>
    <div class="rz-card" style="text-align:center;padding:40px 32px">
        <div style="font-size:2.5rem;margin-bottom:16px">✉️</div>
        <h2 style="margin:0 0 10px;font-size:22px;font-weight:700;color:var(--ink)"><?= t('aff.register.success_title') ?></h2>
        <p style="margin:0 0 24px;color:var(--ink-mute);font-size:14px;line-height:1.6"><?= t('aff.register.success_text') ?></p>
        <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-btn rz-btn-primary" style="display:inline-flex"><?= t('aff.register.success_cta') ?></a>
    </div>
    <?php else: ?>
    <div class="rz-card">
        <h1 class="rz-auth-title"><?= t('aff.register.title') ?></h1>
        <p class="rz-auth-sub"><?= t('aff.register.subtitle') ?></p>

        <?php if ($errors): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= implode('<br>', array_map(fn($e) => htmlspecialchars($e, ENT_QUOTES), $errors)) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" class="rz-auth-form">
            <div class="rz-form-2">
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.register.full_name') . $reqMark ?></label>
                    <input type="text" name="full_name" class="rz-input" value="<?= htmlspecialchars($post['full_name'] ?? '', ENT_QUOTES) ?>" required autofocus>
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.register.email') . $reqMark ?></label>
                    <input type="email" name="email" class="rz-input" value="<?= htmlspecialchars($post['email'] ?? '', ENT_QUOTES) ?>" required autocomplete="email">
                </div>
            </div>
            <div class="rz-field">
                <label class="rz-field-label"><?= t('aff.register.legal_form') . $reqMark ?></label>
                <select name="legal_form" class="rz-input">
                    <option value="individual"  <?= ($post['legal_form'] ?? '') === 'individual'  ? 'selected' : '' ?>><?= t('aff.register.legal_individual') ?></option>
                    <option value="sole_trader" <?= ($post['legal_form'] ?? '') === 'sole_trader' ? 'selected' : '' ?>><?= t('aff.register.legal_sole_trader') ?></option>
                    <option value="company"     <?= ($post['legal_form'] ?? '') === 'company'     ? 'selected' : '' ?>><?= t('aff.register.legal_company') ?></option>
                    <option value="foreign"     <?= ($post['legal_form'] ?? '') === 'foreign'     ? 'selected' : '' ?>><?= t('aff.register.legal_foreign') ?></option>
                </select>
            </div>
            <div class="rz-field">
                <label class="rz-field-label"><?= t('aff.register.iban') ?></label>
                <input type="text" name="iban" class="rz-input" value="<?= htmlspecialchars($post['iban'] ?? '', ENT_QUOTES) ?>" placeholder="SI56...">
            </div>
            <div class="rz-form-2">
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.register.password') . $reqMark ?></label>
                    <input type="password" name="password" class="rz-input" required autocomplete="new-password">
                </div>
                <div class="rz-field">
                    <label class="rz-field-label"><?= t('aff.register.password_confirm') . $reqMark ?></label>
                    <input type="password" name="password_confirm" class="rz-input" required>
                </div>
            </div>

            <div style="border-top:1px solid var(--line);padding-top:16px;display:flex;flex-direction:column;gap:10px">
                <label class="rz-check">
                    <input type="checkbox" name="terms_consent" required>
                    <?= t_raw('aff.register.terms_consent', [
                        'terms_link' => '<a href="' . htmlspecialchars(BASE_PATH . '/affiliate/terms.php', ENT_QUOTES) . '" target="_blank" class="rz-link">' . t('aff.register.terms_link_text') . '</a>',
                    ]) . ' ' . t('aff.common.required_marker') ?>
                </label>
                <label class="rz-check">
                    <input type="checkbox" name="marketing_consent">
                    <?= t('aff.register.marketing_consent') ?>
                </label>
            </div>

            <button type="submit" class="rz-btn rz-btn-primary" style="width:100%;justify-content:center;padding:11px;margin-top:4px"><?= t('aff.register.submit') ?></button>
        </form>
    </div>

    <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--ink-mute)">
        <?= t('aff.register.already_account') ?> <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-link"><?= t('aff.register.login_link') ?></a>
    </p>
    <?php endif; ?>
</div>
</div>
</body>
</html>
