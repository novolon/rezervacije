<?php
require_once 'includes/auth_check.php';
require_once 'includes/lang.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

$token = trim($_GET['token'] ?? '');
$state = 'invalid'; // invalid | expired | already | success

if ($token) {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT id, email_verified_at
            FROM users
            WHERE verification_token = ? AND is_active = 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $state = 'invalid';
        } elseif ($user['email_verified_at'] !== null) {
            $state = 'already';
        } else {
            $pdo->prepare("
                UPDATE users
                SET email_verified_at = NOW(), verification_token = NULL
                WHERE id = ?
            ")->execute([$user['id']]);
            $state = 'success';
        }
    } catch (PDOException $e) {
        error_log('Verify email error: ' . $e->getMessage());
        $state = 'invalid';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('auth.verify_title') ?> – <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/register.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card register-card" style="text-align:center">
        <div class="login-logo">
            <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                <rect width="40" height="40" rx="10" fill="#F59E0B"/>
                <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <h1><?= APP_NAME ?></h1>

        <?php if ($state === 'success'): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <h2><?= t('auth.verify_success_title') ?></h2>
                <p><?= t('auth.verify_success_text') ?></p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login"><?= t('auth.reset_login_btn') ?></a>
            </div>
        <?php elseif ($state === 'already'): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <h2><?= t('auth.verify_already_title') ?></h2>
                <p><?= t('auth.verify_already_text') ?></p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login"><?= t('auth.reset_login_btn') ?></a>
            </div>
        <?php else: ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                <h2><?= t('auth.verify_invalid_title') ?></h2>
                <p><?= nl2br(t('auth.verify_invalid_text')) ?></p>
                <a href="<?= BASE_PATH ?>/register.php" class="btn-register-login"><?= t('auth.register_title') ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
