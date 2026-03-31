<?php
require_once 'includes/auth_check.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (is_logged_in()) {
    redirect_to_main();
}

$errors  = [];
$success = false;
$post    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post['full_name'] = trim($_POST['full_name'] ?? '');
    $post['email']     = trim($_POST['email']     ?? '');
    $password          = $_POST['password']        ?? '';
    $confirm           = $_POST['password_confirm'] ?? '';

    if (!$post['full_name']) {
        $errors[] = 'Polno ime je obvezno.';
    }
    if (!$post['email'] || !filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vnesite veljaven email naslov.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Geslo mora imeti vsaj 8 znakov.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Gesli se ne ujemata.';
    }

    if (empty($errors)) {
        try {
            $pdo = getDB();

            $hash      = password_hash($password, PASSWORD_BCRYPT);
            $trialEnds = date('Y-m-d H:i:s', strtotime('+14 days'));

            $pdo->prepare("
                INSERT INTO users (email, password_hash, full_name, role, trial_ends_at, subscription_status, is_active)
                VALUES (?, ?, ?, 'admin', ?, 'trial', 1)
            ")->execute([$post['email'], $hash, $post['full_name'], $trialEnds]);

            $success = true;

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Ta email naslov je že registriran.';
            } else {
                error_log('Register error: ' . $e->getMessage());
                $errors[] = 'Napaka strežnika. Poskusite znova.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registracija – <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/login.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/register.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card register-card">
        <div class="login-logo">
            <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                <rect width="40" height="40" rx="10" fill="#F59E0B"/>
                <path d="M10 14h20M10 20h20M10 26h12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
        <h1><?= APP_NAME ?></h1>
        <p class="subtitle">Registracija – 14 dni brezplačno</p>

        <?php if ($success): ?>
            <div class="register-success">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <h2>Dobrodošel/a!</h2>
                <p>Tvoj račun je ustvarjen. 14-dnevni brezplačni preizkus se začne zdaj.</p>
                <a href="<?= BASE_PATH ?>/login.php" class="btn-register-login">Prijava →</a>
            </div>
        <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="error-msg" style="display:block">
                <?= implode('<br>', array_map(fn($e) => h($e), $errors)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="full_name">Ime in priimek</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= h($post['full_name'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="email">Email naslov</label>
                <input type="email" id="email" name="email"
                       value="<?= h($post['email'] ?? '') ?>" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Geslo (min. 8 znakov)</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password_confirm">Potrdi geslo</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            <button type="submit">Ustvari brezplačen račun</button>
        </form>

        <p style="text-align:center;margin-top:20px;font-size:.875rem;color:#6B7280">
            Že imaš račun?
            <a href="<?= BASE_PATH ?>/login.php" style="color:#F59E0B;font-weight:600;text-decoration:none">Prijava</a>
        </p>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
