<?php
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/affiliate_session.php';
aff_session_start();

$token   = trim($_GET['token'] ?? '');
$errors  = [];
$success = false;

if (!$token) { header('Location: ' . BASE_PATH . '/affiliate/login.php'); exit; }

$pdo   = getDB();
$stmt  = $pdo->prepare("SELECT id FROM affiliates WHERE reset_token = ? AND reset_token_expires > NOW()");
$stmt->execute([$token]);
$aff   = $stmt->fetch();
if (!$aff) {
    $invalid = true;
}

if (!isset($invalid) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = $_POST['password']         ?? '';
    $pw2 = $_POST['password_confirm'] ?? '';
    if (strlen($pw) < 8)  $errors[] = 'Geslo mora imeti vsaj 8 znakov.';
    if ($pw !== $pw2)      $errors[] = 'Gesli se ne ujemata.';

    if (empty($errors)) {
        $pdo->prepare("UPDATE affiliates SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
            ->execute([password_hash($pw, PASSWORD_BCRYPT), $aff['id']]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ponastavitev gesla – Affiliate</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-auth">
<div class="aff-auth-card">
    <div class="aff-auth-logo"><div class="aff-auth-logo-icon">R</div> Affiliate</div>
    <h1 class="aff-auth-title">Novo geslo</h1>

    <?php if (isset($invalid)): ?>
    <div class="aff-error">Neveljaven ali porabljen token. Zahtevajte novo ponastavitev.</div>
    <?php elseif ($success): ?>
    <div class="aff-success">Geslo je bilo posodobljeno. Lahko se prijavite.</div>
    <div style="margin-top:16px"><a href="<?= BASE_PATH ?>/affiliate/login.php" class="aff-btn">Na prijavo</a></div>
    <?php else: ?>
    <?php if ($errors): ?><div class="aff-error"><?= htmlspecialchars($errors[0], ENT_QUOTES) ?></div><?php endif; ?>
    <form method="POST" action="?token=<?= urlencode($token) ?>">
        <div class="aff-form-group">
            <label for="password">Novo geslo</label>
            <input type="password" id="password" name="password" required autofocus autocomplete="new-password">
        </div>
        <div class="aff-form-group">
            <label for="password_confirm">Potrdi novo geslo</label>
            <input type="password" id="password_confirm" name="password_confirm" required>
        </div>
        <button type="submit" class="aff-btn">Nastavi geslo</button>
    </form>
    <?php endif; ?>
</div>
</div>
</body>
</html>
