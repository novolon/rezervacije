<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_session.php';

aff_session_start();
if (aff_is_logged_in()) { header('Location: ' . BASE_PATH . '/affiliate/dashboard.php'); exit; }

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $errors[] = 'Vnesite email in geslo.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $aff  = $stmt->fetch();

        if (!$aff || !password_verify($password, $aff['password_hash'])) {
            $errors[] = 'Napačen email ali geslo.';
        } elseif (!$aff['email_verified_at']) {
            $errors[] = 'Email naslov ni potrjen. Preverite poštni nabiralnik.';
        } elseif ($aff['status'] === 'rejected') {
            $errors[] = 'Vaša prijava je bila zavrnjena.';
        } else {
            session_regenerate_id(true);
            aff_session_set($aff);
            header('Location: ' . BASE_PATH . '/affiliate/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Affiliate Login – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-auth">
<div class="aff-auth-card">
    <div class="aff-auth-logo">
        <div class="aff-auth-logo-icon">R</div>
        <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?> Affiliati
    </div>
    <h1 class="aff-auth-title">Prijava</h1>
    <p class="aff-auth-sub">Dostop do affiliate dashboarda</p>

    <?php if ($_GET['err'] ?? '' === 'suspended'): ?>
    <div class="aff-error">Vaš račun je bil suspendiran. Kontaktirajte nas za pomoč.</div>
    <?php endif; ?>
    <?php if ($errors): ?>
    <div class="aff-error"><?= implode('<br>', array_map(fn($e) => htmlspecialchars($e, ENT_QUOTES), $errors)) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="on">
        <div class="aff-form-group">
            <label for="email">Email naslov</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required autofocus autocomplete="email">
        </div>
        <div class="aff-form-group">
            <label for="password">Geslo</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="aff-btn" style="margin-top:8px">Prijavi se</button>
    </form>

    <div class="aff-auth-foot">
        <a href="<?= BASE_PATH ?>/affiliate/forgot-password.php" style="color:var(--aff-primary)">Pozabljeno geslo?</a>
        &nbsp;·&nbsp;
        <a href="<?= BASE_PATH ?>/affiliate/register.php" style="color:var(--aff-primary)">Registracija</a>
    </div>
</div>
</div>
</body>
</html>
