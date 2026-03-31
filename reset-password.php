<?php
require_once 'includes/auth_check.php';
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
        $errors[] = 'Geslo mora imeti vsaj 8 znakov.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Gesli se ne ujemata.';
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
            $errors[] = 'Napaka strežnika. Poskusite znova.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo geslo – <?= APP_NAME ?></title>
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
        <p class="subtitle">Novo geslo</p>

        <?php if ($done): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <h2>Geslo je nastavljeno!</h2>
                <p>Vaše geslo je bilo uspešno posodobljeno.</p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login">Prijava →</a>
            </div>

        <?php elseif (!$user): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                <h2>Link je potekel</h2>
                <p>Ta link za ponastavitev gesla je neveljaven ali je potekel (1 ura).</p>
                <a href="<?= BASE_PATH ?>/forgot-password.php" class="btn-register-login">Zahtevaj novega</a>
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
                <label for="password">Novo geslo (min. 8 znakov)</label>
                <input type="password" id="password" name="password" required autofocus autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password_confirm">Potrdi novo geslo</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            <button type="submit">Nastavi geslo</button>
        </form>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
