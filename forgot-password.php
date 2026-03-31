<?php
require_once 'includes/auth_check.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

if (is_logged_in()) {
    redirect_to_main();
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vnesite veljaven email naslov.';
    } else {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Vedno pokažemo uspeh (ne razkrijemo ali email obstaja)
            if ($user) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
                    ->execute([$token, $expires, $user['id']]);
                send_password_reset_email($email, $user['full_name'], $token);
            }

            $success = true;

        } catch (PDOException $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            $error = 'Napaka strežnika. Poskusite znova.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pozabljeno geslo – <?= APP_NAME ?></title>
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
        <p class="subtitle">Ponastavitev gesla</p>

        <?php if ($success): ?>
            <div class="register-success">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="1.8"><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/></svg>
                <h2>Email poslan!</h2>
                <p>Če ta email obstaja v sistemu, smo poslali navodila za ponastavitev gesla.</p>
                <p style="font-size:.8rem;color:#9CA3AF">Link je veljaven 1 uro. Preverite tudi mapo Spam.</p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login">Nazaj na prijavo</a>
            </div>
        <?php else: ?>

        <?php if ($error): ?>
            <div class="error-msg" style="display:block"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="email">Vaš email naslov</label>
                <input type="email" id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>" required autofocus autocomplete="email">
            </div>
            <button type="submit">Pošlji navodila</button>
        </form>

        <p style="text-align:center;margin-top:20px;font-size:.875rem;color:#6B7280">
            <a href="<?= BASE_PATH ?>/login.php" style="color:#F59E0B;font-weight:600;text-decoration:none">← Nazaj na prijavo</a>
        </p>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
