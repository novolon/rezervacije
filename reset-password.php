<?php
require_once 'includes/auth_check.php';
require_once 'includes/lang.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (is_logged_in()) {
    redirect_to_main();
}

$token  = trim($_GET['token'] ?? '');
$errors = [];
$done   = false;
$user   = null;

if (!$token) {
    header('Location: ' . BASE_PATH . '/forgot-password.php');
    exit;
}

// Preveri token
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT id, full_name, email
        FROM users
        WHERE reset_token = ? AND reset_token_expires > NOW() AND is_active = 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Reset password lookup error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = t('auth.err_password_short');
    }
    if ($password !== $confirm) {
        $errors[] = t('auth.err_passwords_mismatch');
    }

    if (empty($errors)) {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("
                UPDATE users
                SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL,
                    remember_token = NULL, remember_expires = NULL
                WHERE id = ?
            ")->execute([$hash, $user['id']]);
            $done = true;
        } catch (PDOException $e) {
            error_log('Reset password update error: ' . $e->getMessage());
            $errors[] = t('auth.err_server');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= get_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('auth.reset_title') ?> – <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/register.css">
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
        <p class="subtitle"><?= t('auth.reset_title') ?></p>

        <?php if ($done): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <h2><?= t('auth.reset_success_title') ?></h2>
                <p><?= t('auth.reset_success_text') ?></p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login"><?= t('auth.reset_login_btn') ?></a>
            </div>

        <?php elseif (!$user): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                <h2><?= t('auth.reset_expired_title') ?></h2>
                <p><?= t('auth.reset_expired_text') ?></p>
                <a href="<?= BASE_PATH ?>/forgot-password.php" class="btn-register-login"><?= t('auth.forgot_request_new') ?></a>
            </div>

        <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="error-msg" style="display:block">
                <?= implode('<br>', array_map(fn($e) => h($e), $errors)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div class="form-group">
                <label for="password"><?= t('auth.new_password_label') ?></label>
                <input type="password" id="password" name="password" required autofocus autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password_confirm"><?= t('auth.confirm_new_password_label') ?></label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            <button type="submit"><?= t('auth.set_password_btn') ?></button>
        </form>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
