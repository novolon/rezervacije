<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/lang.php';

$token = trim($_GET['token'] ?? '');
$msg   = '';
$ok    = false;

if ($token) {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE verification_token = ? AND email_verified_at IS NULL");
        $stmt->execute([$token]);
        $aff  = $stmt->fetch();

        if ($aff) {
            $pdo->prepare("UPDATE affiliates SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?")
                ->execute([$aff['id']]);
            $ok  = true;
            $msg = t('aff.verify.success_msg');
        } else {
            $msg = t('aff.verify.invalid_token');
        }
    } catch (\Throwable $e) {
        error_log('Affiliate verify-email error: ' . $e->getMessage());
        $msg = t('aff.verify.server_error');
    }
} else {
    $msg = t('aff.verify.missing_token');
}

$pageTitle = t('aff.verify.page_title');
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

    <div class="rz-card" style="text-align:center;padding:40px 32px">
        <?php if ($ok): ?>
        <div style="width:64px;height:64px;border-radius:50%;background:color-mix(in oklab,var(--success) 12%,transparent);display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-11"/></svg>
        </div>
        <h1 style="margin:0 0 10px;font-size:22px;font-weight:700;color:var(--ink)"><?= t('aff.verify.success_title') ?></h1>
        <?php else: ?>
        <div style="width:64px;height:64px;border-radius:50%;background:color-mix(in oklab,var(--danger) 10%,transparent);display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M6 18 18 6"/></svg>
        </div>
        <h1 style="margin:0 0 10px;font-size:22px;font-weight:700;color:var(--ink)"><?= t('aff.verify.invalid_title') ?></h1>
        <?php endif; ?>
        <p style="color:var(--ink-mute);margin:0 0 28px;font-size:14px;line-height:1.6"><?= htmlspecialchars($msg, ENT_QUOTES) ?></p>
        <a href="<?= BASE_PATH ?>/affiliate/login.php" class="rz-btn rz-btn-primary" style="display:inline-flex"><?= t('aff.verify.cta_login') ?></a>
    </div>
</div>
</div>
</body>
</html>
