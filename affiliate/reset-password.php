<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';
require_once '../includes/affiliate_session.php';
aff_session_start();

$token   = trim($_GET['token'] ?? '');
$errors  = [];
$success = false;

if (!$token) { header('Location: ' . BASE_PATH . '/affiliate/login.php'); exit; }

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE reset_token = ? AND reset_token_expires > NOW()");
    $stmt->execute([$token]);
    $aff  = $stmt->fetch();
    if (!$aff) $invalid = true;
} catch (\Throwable $e) {
    error_log('Affiliate reset-password DB error: ' . $e->getMessage());
    $invalid = true;
}

if (!isset($invalid) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = $_POST['password']         ?? '';
    $pw2 = $_POST['password_confirm'] ?? '';
    if (strlen($pw) < 8) $errors[] = t('aff.reset.err_pwlen');
    if ($pw !== $pw2)    $errors[] = t('aff.reset.err_pwmatch');

    if (empty($errors)) {
        $pdo->prepare("UPDATE affiliates SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
            ->execute([password_hash($pw, PASSWORD_BCRYPT), $aff['id']]);
        $success = true;
    }
}

$pageTitle = t('aff.reset.page_title');
$extraCss  = ['design.css'];
require_once '../includes/html_head.php';
?>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:40px 16px">
<div style="width:100%;max-width:420px">

    <a href="<?= BASE_PATH ?>/affiliate/login.php" style="display:flex;align-items:center;gap:10px;margin-bottom:32px;text-decoration:none">
        <img src="<?= BASE_PATH ?>/assets/images/Rezble.svg" alt="Rezble" style="height:24px;width:auto;display:block">
        <span style="font-weight:500;color:var(--ink-mute);font-size:15px"><?= t('aff.brand_suffix') ?></span>
    </a>

    <div class="rz-card">
        <h1 class="rz-auth-title"><?= t('aff.reset.title') ?></h1>

        <?php if (isset($invalid)): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:12px 14px;font-size:13px;margin-bottom:16px">
            <?= t('aff.reset.invalid_token') ?>
        </div>
        <a href="<?= BASE_PATH ?>/affiliate/forgot-password.php" class="rz-btn rz-btn-primary" style="display:inline-flex"><?= t('aff.reset.request_new') ?></a>

        <?php elseif ($success): ?>
        <div style="background:color-mix(in oklab,var(--success) 12%,transparent);color:var(--success);border:1px solid color-mix(in oklab,var(--success) 25%,transparent);border-radius:8px;padding:12px 14px;font-size:13px;margin-bottom:20px">
            <?= t('aff.reset.success') ?>
        </div>
        <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-btn rz-btn-primary" style="display:inline-flex"><?= t('aff.reset.success_cta') ?></a>

        <?php else: ?>
        <?php if ($errors): ?>
        <div style="background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border:1px solid color-mix(in oklab,var(--danger) 25%,transparent);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px">
            <?= htmlspecialchars($errors[0], ENT_QUOTES) ?>
        </div>
        <?php endif; ?>
        <form method="POST" action="?token=<?= urlencode($token) ?>" class="rz-auth-form">
            <div class="rz-field">
                <label class="rz-field-label"><?= t('aff.reset.password') ?></label>
                <input type="password" name="password" class="rz-input" required autofocus autocomplete="new-password">
            </div>
            <div class="rz-field">
                <label class="rz-field-label"><?= t('aff.reset.password_confirm') ?></label>
                <input type="password" name="password_confirm" class="rz-input" required>
            </div>
            <button type="submit" class="rz-btn rz-btn-primary" style="width:100%;justify-content:center;padding:11px"><?= t('aff.reset.submit') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>
</div>
</body>
</html>
