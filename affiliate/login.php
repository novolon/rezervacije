<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_session.php';

aff_session_start();
if (aff_is_logged_in()) { header('Location: ' . BASE_PATH . '/affiliate/dashboard.php'); exit; }

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $errors[] = t('aff.login.err_empty');
    } else {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $aff  = $stmt->fetch();

            if (!$aff || !password_verify($password, $aff['password_hash'])) {
                $errors[] = t('aff.login.err_credentials');
            } elseif (!$aff['email_verified_at']) {
                $errors[] = t('aff.login.err_unverified');
            } elseif ($aff['status'] === 'rejected') {
                $errors[] = t('aff.login.err_rejected');
            } else {
                session_regenerate_id(true);
                aff_session_set($aff);
                header('Location: ' . BASE_PATH . '/affiliate/dashboard.php');
                exit;
            }
        } catch (\Throwable $e) {
            error_log('Affiliate login error: ' . $e->getMessage());
            $errors[] = t('aff.login.err_server');
        }
    }
}

$pageTitle = t('aff.login.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:40px 16px">
<div style="width:100%;max-width:420px">

    <a href="<?= BASE_PATH ?>/" style="display:flex;align-items:center;gap:10px;margin-bottom:32px;text-decoration:none">
        <img src="<?= BASE_PATH ?>/assets/images/Rezble.svg" alt="Rezble" style="height:24px;width:auto;display:block">
        <span style="font-weight:500;color:var(--ink-mute);font-size:15px"><?= t('aff.brand_suffix') ?></span>
    </a>

    <div class="rz-card">
        <h1 class="rz-auth-title"><?= t('aff.login.title') ?></h1>
        <p class="rz-auth-sub"><?= t('aff.login.subtitle') ?></p>

        <?php if (($_GET['err'] ?? '') === 'suspended'): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= t('aff.login.err_suspended') ?>
        </div>
        <?php endif; ?>
        <?php if ($errors): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= htmlspecialchars($errors[0], ENT_QUOTES) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on" class="rz-auth-form">
            <div class="rz-field">
                <label class="rz-field-label"><?= t('aff.login.email') ?></label>
                <input type="email" name="email" class="rz-input" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required autofocus autocomplete="email">
            </div>
            <div class="rz-field">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <label class="rz-field-label"><?= t('aff.login.password') ?></label>
                    <a href="<?= BASE_PATH ?>/affiliate/forgot-password.php" class="rz-link"><?= t('aff.login.forgot') ?></a>
                </div>
                <input type="password" name="password" class="rz-input" required autocomplete="current-password">
            </div>
            <button type="submit" class="rz-btn rz-btn-primary" style="width:100%;justify-content:center;padding:11px"><?= t('aff.login.submit') ?></button>
        </form>
    </div>

    <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--ink-mute)">
        <?= t('aff.login.no_account') ?> <a href="<?= BASE_PATH ?>/affiliate/register.php" class="rz-link"><?= t('aff.login.signup_link') ?></a>
    </p>
</div>
</div>
</body>
</html>
