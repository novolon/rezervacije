<?php
require_once '../config.php';
require_once '../includes/db.php';

$token = trim($_GET['token'] ?? '');
$msg   = '';
$ok    = false;

if ($token) {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE verification_token = ? AND email_verified_at IS NULL");
    $stmt->execute([$token]);
    $aff  = $stmt->fetch();

    if ($aff) {
        $pdo->prepare("UPDATE affiliates SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?")
            ->execute([$aff['id']]);
        $ok  = true;
        $msg = 'Email potrjen! Vaša prijava čaka na odobritev. Ko jo pregledamo, vas obvestimo.';
    } else {
        $msg = 'Neveljaven ali porabljen token.';
    }
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Potrditev emaila – <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/affiliate.css">
</head>
<body>
<div class="aff-auth">
<div class="aff-auth-card" style="text-align:center">
    <div style="font-size:2.5rem;margin-bottom:16px"><?= $ok ? '✅' : '❌' ?></div>
    <h1 class="aff-auth-title"><?= $ok ? 'Email potrjen' : 'Napaka' ?></h1>
    <p style="color:var(--aff-ink-mute);margin-bottom:24px"><?= htmlspecialchars($msg, ENT_QUOTES) ?></p>
    <a href="<?= BASE_PATH ?>/affiliate/login.php" class="aff-btn" style="display:inline-block;width:auto;padding:10px 24px">Na prijavo</a>
</div>
</div>
</body>
</html>
