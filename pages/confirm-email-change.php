<?php
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';

$pdo   = getDB();
$token = trim($_GET['token'] ?? '');
$error = '';
$ok    = false;

if (!$token) {
    $error = t('confirm_email.err_no_token');
} else {
    $stmt = $pdo->prepare("
        SELECT id, email_change_pending, email_change_expires
        FROM users
        WHERE email_change_token = ? AND email_change_pending IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = t('confirm_email.err_invalid');
    } elseif (strtotime($user['email_change_expires']) < time()) {
        $error = t('confirm_email.err_expired');
        $pdo->prepare("UPDATE users SET email_change_pending=NULL, email_change_token=NULL, email_change_expires=NULL WHERE id=?")
            ->execute([$user['id']]);
    } else {
        // Preveri da email ni medtem zasedel drug user
        $conflict = $pdo->prepare("SELECT id FROM users WHERE email=? AND id != ?");
        $conflict->execute([$user['email_change_pending'], $user['id']]);
        if ($conflict->fetchColumn()) {
            $error = t('confirm_email.err_conflict');
        } else {
            $pdo->prepare("
                UPDATE users SET email=?, email_change_pending=NULL, email_change_token=NULL, email_change_expires=NULL
                WHERE id=?
            ")->execute([$user['email_change_pending'], $user['id']]);
            $ok = true;
            // Posodobi session če je user prijavljen
            if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['id']) {
                $_SESSION['email'] = $user['email_change_pending'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_PATH ?>/assets/images/icon.svg">
    <title><?= $ok ? t('confirm_email.page_title_ok') : t('confirm_email.page_title_err') ?> – <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card" style="text-align:center;max-width:420px">
        <div class="login-logo">
            <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                <rect width="40" height="40" rx="10" fill="#F59E0B"/>
                <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <?php if ($ok): ?>
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="1.5" style="margin-bottom:12px"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
            <h1 style="font-size:1.3rem;margin-bottom:10px"><?= t('confirm_email.ok_title') ?></h1>
            <p style="color:#6B7280;font-size:.875rem;margin-bottom:28px"><?= t('confirm_email.ok_desc') ?></p>
            <a href="<?= BASE_PATH ?>/pages/main.php" style="display:inline-block;background:#F59E0B;color:#fff;font-weight:600;padding:10px 28px;border-radius:8px;text-decoration:none"><?= t('confirm_email.ok_btn') ?></a>
        <?php else: ?>
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="1.5" style="margin-bottom:12px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <h1 style="font-size:1.3rem;margin-bottom:10px"><?= t('confirm_email.err_title') ?></h1>
            <p style="color:#6B7280;font-size:.875rem;margin-bottom:28px"><?= h($error) ?></p>
            <a href="<?= BASE_PATH ?>/pages/profile.php" style="display:inline-block;background:#F59E0B;color:#fff;font-weight:600;padding:10px 28px;border-radius:8px;text-decoration:none"><?= t('confirm_email.err_btn') ?></a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
